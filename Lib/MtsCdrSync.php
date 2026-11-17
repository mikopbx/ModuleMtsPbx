<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace Modules\ModuleMtsPbx\Lib;

use DateTime;
use DateTimeImmutable;
use GuzzleHttp\Client;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Storage;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerCallEvents;
use Modules\ModuleMtsPbx\bin\ConnectorDb;
use Modules\ModuleMtsPbx\Models\MtsAccounts;

/**
 * Синхронизация CDR и записей разговоров одного портала MTS.
 * С базой модуля работает только через ConnectorDb (см. причину там).
 */
class MtsCdrSync
{
    public const RESULT_OK           = 0;
    public const RESULT_NO_DOMAIN    = 2;
    public const RESULT_CDR_ERROR    = 3;
    public const RESULT_TRUNKS_ERROR = 11;
    public const RESULT_DB_ERROR     = 12;

    // Лаг от текущего времени: MTS API не возвращает звонки, которые ещё в процессе.
    // Откатываем правую границу окна, чтобы дать звонкам успеть завершиться и попасть в индекс MTS.
    private const LAG_MINUTES = 5;

    // Перекрытие с предыдущим окном: пересинхронизируем последние N минут на каждом запуске.
    // CallHistory ищется по linkedid — повторная обработка идемпотентна.
    private const OVERLAP_MINUTES = 10;

    private const MAX_WINDOW_DAYS = 10;
    private const PAGE_LIMIT      = 1000;
    private const API_URL         = 'https://aa.mts.ru/api/ac20/trunks/';
    private const SAVE_BATCH      = 50;

    /** @var array строка m_ModuleMtsPbxAccounts */
    private array $account;
    private Logger $logger;
    private Client $client;
    private int $lookbackMinutes;
    private string $label;

    /**
     * @param int $lookbackMinutes > 0 — режим глубокой дозагрузки: окно последних N минут, offset не меняется.
     */
    public function __construct(array $account, Logger $logger, Client $client, int $lookbackMinutes = 0)
    {
        $this->account         = $account;
        $this->logger          = $logger;
        $this->client          = $client;
        $this->lookbackMinutes = $lookbackMinutes;
        $this->label           = 'account ' . ($account['id'] ?? '?') . '/' . (($account['domain'] ?? '') ?: '?');
    }

    public function run(): int
    {
        $domain = (string)($this->account['domain'] ?? '');
        if ($domain === '') {
            $domain = MtsAccountKey::parseDomain((string)($this->account['authApiKey'] ?? ''));
        }
        if ($domain === '') {
            $this->error('Error parse authApiKey: domain is empty');
            return self::RESULT_NO_DOMAIN;
        }

        $isDeep = $this->lookbackMinutes > 0;
        if ($isDeep) {
            $start = MtsApiTime::now()->modify('-' . $this->lookbackMinutes . ' minutes');
            $this->info('Deep lookback sync from ' . $start->format('Y-m-d\TH:i:s') . " ({$this->lookbackMinutes}m, offset untouched)...");
        } elseif (empty($this->account['offset'])) {
            $start = MtsApiTime::now()->modify('-30 day');
            $this->info('Offset is empty start sync -30 day...');
        } else {
            // Продолжаем с сохранённого offset, отступая на OVERLAP_MINUTES назад,
            // чтобы перехватить звонки, которые были в процессе на границе окна.
            $start = MtsApiTime::fromApiValue((string)$this->account['offset'])
                ->modify('-' . self::OVERLAP_MINUTES . ' minutes');
            $this->info('Start sync ' . $start->format('Y-m-d\TH:i:s') . ' (overlap ' . self::OVERLAP_MINUTES . 'm)...');
        }
        $now = MtsApiTime::now()->modify('-' . self::LAG_MINUTES . ' minutes');

        $trunks = $this->getTrunks($domain);
        if ($trunks === null) {
            return self::RESULT_TRUNKS_ERROR;
        }

        return $this->syncWindows($domain, $trunks, $start, $now, $isDeep);
    }

    private function getTrunks(string $domain): ?array
    {
        $url   = self::API_URL . 'all';
        $query = ['Domain' => $domain];
        $this->logHttpRequest('GET', $url, $query, 'trunks/all');
        try {
            $response = $this->client->request('GET', $url, [
                'query'   => $query,
                'headers' => $this->headers(),
                'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5,
            ]);
            $message = (string)$response->getBody();
            $status  = $response->getStatusCode();
            $data    = json_decode($message, true);
            $this->logHttpResponse($status, $message, 'trunks/all');
        } catch (\Exception $e) {
            $data    = null;
            $status  = 0;
            $message = $e->getMessage();
            $this->logHttpException($e, 'trunks/all');
        }
        if (!is_array($data)) {
            $this->error([$status, $message], 'Fail Get Trunks');
            return null;
        }
        return $data;
    }

    /**
     * Загружает CDR скользящими окнами по MAX_WINDOW_DAYS. CDR окна сохраняются в базу до сдвига offset:
     * если сохранение не удалось, окно будет загружено повторно при следующем запуске.
     */
    private function syncWindows(string $domain, array $trunks, DateTimeImmutable $start, DateTimeImmutable $now, bool $isDeep): int
    {
        $url         = self::API_URL . 'statistics';
        $windowStart = $start;

        while ($windowStart < $now) {
            $windowEnd = $windowStart->modify('+' . self::MAX_WINDOW_DAYS . ' days');
            if ($windowEnd > $now) {
                $windowEnd = $now;
            }
            $windowStartTime = $windowStart->format('Y-m-d\TH:i:s');
            $windowEndTime   = $windowEnd->format('Y-m-d\TH:i:s');
            $this->info("Start sync window {$windowStartTime} - {$windowEndTime}...");
            $results   = [];
            $haveError = false;

            foreach ($trunks as $trunk) {
                $trunkId = $trunk['trunkId'] ?? '';
                $offset  = 0;
                $count   = self::PAGE_LIMIT;
                while ($count >= self::PAGE_LIMIT) {
                    $this->info("Get CDR for {$trunkId}, offset: {$offset}");
                    usleep(200000);
                    $query = [
                        'Domain'  => $domain,
                        'TrunkId' => $trunkId,
                        'Begin'   => $windowStartTime,
                        'End'     => $windowEndTime,
                        'Limit'   => self::PAGE_LIMIT,
                        'Offset'  => $offset,
                    ];
                    $this->logHttpRequest('GET', $url, $query, 'trunks/statistics');
                    try {
                        $response = $this->client->request('GET', $url, [
                            'query'   => $query,
                            'headers' => $this->headers(),
                            'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5,
                        ]);
                        $message = (string)$response->getBody();
                        $status  = $response->getStatusCode();
                        $res     = json_decode($message, true);
                        $this->logHttpResponse($status, $message, 'trunks/statistics');
                    } catch (\Exception $e) {
                        $res     = null;
                        $status  = 0;
                        $message = $e->getMessage();
                        $this->logHttpException($e, 'trunks/statistics');
                    }

                    if (is_array($res)) {
                        $count     = count($res);
                        $offset   += $count;
                        $results[] = $res;
                        continue;
                    }
                    if ($status === 204) {
                        // Нет CDR за период/страницу — не ошибка.
                        $count = 0;
                        $this->info([
                            'trunkId' => $trunkId,
                            'offset'  => $offset,
                            'status'  => $status,
                            'window'  => [$windowStartTime, $windowEndTime],
                        ], 'CDR page is empty');
                        continue;
                    }

                    $haveError = true;
                    $this->error(["status: $status", $message, 'query' => $query], "Fail Get CDR for {$trunkId} offset: {$offset}");
                    break 2; // любая ошибка останавливает синхронизацию окна
                }
            }

            if ($haveError) {
                return self::RESULT_CDR_ERROR;
            }
            $cdrs = empty($results) ? [] : array_merge(...$results);
            if (!empty($cdrs) && !$this->saveCdrs($domain, $cdrs, $isDeep)) {
                return self::RESULT_DB_ERROR;
            }

            // Окно загружено и сохранено. В режиме глубокой дозагрузки offset не трогаем — им управляет основной проход.
            if (!$isDeep && !$this->saveOffset($windowEndTime)) {
                return self::RESULT_DB_ERROR;
            }
            if ($windowEnd >= $now) {
                break;
            }
            $windowStart = $windowEnd;
        }

        return self::RESULT_OK;
    }

    private function saveOffset(string $offset): bool
    {
        $result = ConnectorDb::call('saveAccountOffset', [(int)$this->account['id'], $offset]);
        if ($result !== true) {
            $this->error(['offset' => $offset, 'result' => $result], 'Fail update offset (ConnectorDb)');
            return false;
        }
        $this->account['offset'] = $offset;
        $this->info("Update offset  {$offset}...");
        return true;
    }

    /**
     * @return bool все CDR сохранены
     */
    private function saveCdrs(string $domain, array $cdrs, bool $isDeep): bool
    {
        $accountId = (int)$this->account['id'];
        $legacy    = (string)($this->account['legacyIdFormat'] ?? '0') === '1';
        $gap       = ModuleSettings::normalizeGap($this->account['gap'] ?? '');
        $beanstalk = new BeanstalkClient(WorkerCallEvents::class);
        $this->info('Parse CDRs: ' . count($cdrs));

        // Глубокая дозагрузка добирает только ОТСУТСТВУЮЩИЕ звонки. Уже синхронизированный
        // звонок пропускаем целиком: без перекачки записи, UPDATE и повторной публикации в Beanstalk.
        // Дозагрузкой записей к существующим CDR занимается downloadRecords.php.
        $missing = null;
        if ($isDeep) {
            $linkedIds = [];
            foreach ($cdrs as $cdr) {
                $linkedIds[] = MtsCallId::build($accountId, $legacy, (string)($cdr['callId'] ?? ''));
            }
            $missing = ConnectorDb::call('filterMissingLinkedIds', [$linkedIds]);
            if (!is_array($missing)) {
                $this->error('ConnectorDb did not respond', 'Fail check existing CDR');
                return false;
            }
            $missing = array_fill_keys($missing, true);
        }

        $batch = [];
        $ok    = true;
        foreach ($cdrs as $cdr) {
            $callId = (string)($cdr['callId'] ?? '');
            if ($callId === '') {
                continue;
            }
            $linkedId = MtsCallId::build($accountId, $legacy, $callId);
            if ($missing !== null && !isset($missing[$linkedId])) {
                continue;
            }
            $batch[] = $this->buildCdr($domain, $cdr, $accountId, $callId, $linkedId, $gap);
            if (count($batch) >= self::SAVE_BATCH) {
                $ok = $this->flush($batch, $beanstalk) && $ok;
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $ok = $this->flush($batch, $beanstalk) && $ok;
        }
        return $ok;
    }

    /**
     * Сохраняет пачку CDR через ConnectorDb и публикует сохранённые в WorkerCallEvents.
     */
    private function flush(array $rows, BeanstalkClient $beanstalk): bool
    {
        $result = ConnectorDb::call('saveCdrs', [$rows]);
        if (!is_array($result)) {
            $this->error('ConnectorDb did not respond, rows: ' . count($rows), 'Fail save CDR');
            return false;
        }
        $failed = array_fill_keys($result['failed'] ?? [], true);
        if (!empty($failed)) {
            $this->error(array_keys($failed), 'Fail save CDR');
        }
        $published = [];
        foreach ($rows as $row) {
            if (isset($failed[$row['linkedid']])) {
                continue;
            }
            unset($row['mts_account_id'], $row['mts_call_id']);
            $published[] = $row;
        }
        foreach (array_chunk($published, 10) as $chunk) {
            $beanstalk->publish(json_encode(['action' => 'insert_cdr', 'rows' => $chunk]), WorkerCallEvents::class);
            usleep(100000);
        }
        return empty($failed);
    }

    private function buildCdr(string $domain, array $cdr, int $accountId, string $callId, string $linkedId, int $gap): array
    {
        foreach (['via', 'an', 'dn'] as $key) {
            $cdr[$key] = (string)($cdr[$key] ?? '');
            $cdr[$key] = strlen($cdr[$key]) === 10 ? '7' . $cdr[$key] : $cdr[$key];
        }
        $trunkId = (string)($cdr['trunkId'] ?? '');
        $src     = $cdr['an'];
        $dst     = $cdr['dn'];
        if ((int)($cdr['rel'] ?? 0) === 1) {
            // Внутренний вызов между сотрудниками (без внешнего транка).
            $srcChan    = 'PJSIP/mts-' . $callId;
            $dstChan    = 'PJSIP/mts_' . $trunkId . '-' . $callId;
            $cdr['via'] = '';
        } elseif ($cdr['via'] === $cdr['an']) {
            // Исходящий с номера сотрудника.
            $srcChan    = 'PJSIP/mts_' . $trunkId . '-' . $callId;
            $dstChan    = 'PJSIP/mts-' . $callId;
            $cdr['via'] = '';
        } else {
            $srcChan = 'PJSIP/mts-' . $callId;
            $dstChan = 'PJSIP/mts_' . $trunkId . '-' . $callId;
        }

        $duration  = (int)($cdr['duration'] ?? 0);
        $waitSec   = (int)($cdr['wait'] ?? 0);
        $startDate = (new DateTime((string)($cdr['startTime'] ?? 'now')))->modify($gap . ' hour');
        $recDur    = (int)($cdr['recDur'] ?? 0);
        if ($recDur <= 0) {
            [$filename, $recStatus] = ['', 'none'];
        } elseif (!MtsAccounts::isDownloadRecordsEnabled($this->account['downloadRecords'] ?? null)) {
            // Портал настроен без скачивания записей: синхронизируем только CDR.
            [$filename, $recStatus] = ['', 'skipped'];
        } else {
            [$filename, $recStatus] = $this->downloadRecord($domain, $trunkId, $callId, $linkedId, $startDate);
        }

        // DateTime::modify мутирует объект, поэтому answer/endtime считаем от клонов.
        $answerAt = (clone $startDate)->modify('+' . $waitSec . ' seconds');
        $endAt    = (clone $startDate)->modify('+' . $duration . ' seconds');

        return [
            'UNIQUEID'       => $linkedId,
            'linkedid'       => $linkedId,
            'start'          => $startDate->format('Y-m-d H:i:s.u'),
            'answer'         => $answerAt->format('Y-m-d H:i:s.u'),
            'endtime'        => $endAt->format('Y-m-d H:i:s.u'),
            'did'            => $cdr['via'],
            'src_num'        => $src,
            'src_chan'       => $srcChan,
            'dst_num'        => $dst,
            'dst_chan'       => $dstChan,
            'duration'       => $duration,
            'billsec'        => $duration - $waitSec,
            'disposition'    => ($duration - $waitSec > 1) ? 'ANSWERED' : 'NOANSWER',
            'recordingfile'  => $filename,
            'from_account'   => 'fs-mts',
            'work_completed' => '1',
            'is_app'         => '0',
            'transfer'       => '0',
            'mts_rec_dur'    => $recDur,
            'mts_rec_status' => $recStatus,
            'mts_account_id' => $accountId,
            'mts_call_id'    => $callId,
        ];
    }

    /**
     * @return array{0: string, 1: string} [путь к файлу, mts_rec_status]
     */
    private function downloadRecord(string $domain, string $trunkId, string $callId, string $linkedId, DateTime $startDate): array
    {
        $candidate = self::recordPath($linkedId, $startDate);
        if (file_exists($candidate)) {
            // Уже скачана при предыдущем запуске.
            return [$candidate, 'ok'];
        }
        Util::mwMkdir(dirname($candidate));
        $url   = self::API_URL . 'record';
        $query = ['Domain' => $domain, 'TrunkId' => $trunkId, 'CallId' => $callId, 'Type' => 'mp3'];
        $this->logHttpRequest('GET', $url, $query, 'trunks/record');
        try {
            $response = $this->client->request('GET', $url, [
                'query'       => $query,
                'headers'     => $this->headers(),
                'timeout'     => 30, 'connect_timeout' => 5, 'read_timeout' => 30,
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $this->logHttpResponse($code, $code === 200 ? 'binary bytes: ' . strlen($body) : $body, 'trunks/record', $code >= 400);
            usleep(100000);
            if ($code === 200) {
                file_put_contents($candidate, $body);
                return [$candidate, 'ok'];
            }
            if ($code === 404 || $code === 410) {
                // Запись недоступна навсегда (истекло хранение и т.п.) — больше не пытаемся.
                return ['', 'gone'];
            }
        } catch (\Exception $e) {
            $this->logHttpException($e, 'trunks/record');
        }
        // 204 / 5xx / сетевая ошибка — дозагрузит downloadRecords.php.
        return ['', 'pending'];
    }

    /**
     * Путь к mp3: `<monitor>/Y/m/d/H/<callId>.mp3` (старый формат) или `<accountId>-<callId>.mp3`.
     */
    public static function recordPath(string $linkedId, \DateTimeInterface $startDate): string
    {
        return Storage::getMonitorDir() . $startDate->format('/Y/m/d/H/') . MtsCallId::fileBase($linkedId) . '.mp3';
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . ($this->account['authApiKey'] ?? ''),
            'accept'        => 'application/json',
        ];
    }

    private function info($data, string $header = ''): void
    {
        $this->logger->writeInfo($data, $this->header($header));
    }

    private function error($data, string $header = ''): void
    {
        $this->logger->writeError($data, $this->header($header));
    }

    private function header(string $header): string
    {
        return '[' . $this->label . ']' . ($header === '' ? '' : ' ' . $header);
    }

    private function logHttpRequest(string $method, string $url, array $query, string $label): void
    {
        $this->info(['label' => $label, 'method' => $method, 'url' => $url, 'query' => $query], 'HTTP request');
    }

    private function logHttpResponse(int $status, string $body, string $label, bool $error = false): void
    {
        $payload = ['label' => $label, 'status' => $status, 'body' => self::truncateForLog($body)];
        if ($error) {
            $this->error($payload, 'HTTP response');
            return;
        }
        $this->info($payload, 'HTTP response');
    }

    private function logHttpException(\Exception $e, string $label): void
    {
        $this->error(['label' => $label, 'exception' => $e->getMessage()], 'HTTP exception');
    }

    private static function truncateForLog(string $value, int $maxLen = 3000): string
    {
        if (strlen($value) <= $maxLen) {
            return $value;
        }
        return substr($value, 0, $maxLen) . '... [truncated ' . (strlen($value) - $maxLen) . ' bytes]';
    }
}
