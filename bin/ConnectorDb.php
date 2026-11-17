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

namespace Modules\ModuleMtsPbx\bin;
require_once 'Globals.php';

use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleMtsPbx\Lib\Logger;
use Modules\ModuleMtsPbx\Lib\MikoPBXVersion;
use Modules\ModuleMtsPbx\Models\CallHistory;
use Modules\ModuleMtsPbx\Models\MtsAccounts;

/**
 * Единственный процесс, который пишет в SQLite-базу модуля из фоновых задач.
 *
 * Синхронизация порталов (по процессу на портал) и дозагрузка записей работают параллельно.
 * При прямой записи из нескольких процессов SQLite (rollback journal) отвечает
 * "database is locked": процесс с открытым курсором держит SHARED-блокировку, и две
 * встречные попытки записи образуют взаимоблокировку, на которой busy_timeout не ждёт.
 * Поэтому фоновые задачи обращаются к базе через этот воркер (Beanstalk request/reply).
 */
class ConnectorDb extends WorkerBase
{
    public const MODULE_NAME = 'ModuleMtsPbx';

    // Запросы больше этого размера передаются через временный файл (лимит размера задания Beanstalk).
    private const MAX_INLINE_PAYLOAD_BYTES = 4096;

    // Вызовы дольше этого времени пишутся в лог: воркер обрабатывает запросы по очереди,
    // и долгий вызов задерживает все остальные.
    private const SLOW_CALL_SECONDS = 1.0;

    // Поля mts_cdr, которые можно менять через updateCdrRecord().
    private const RECORD_FIELDS = ['recordingfile', 'mts_rec_status', 'mts_account_id', 'mts_call_id'];

    private Logger $logger;

    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_' . cli_get_process_title());
    }

    public function start($argv): void
    {
        $this->logger = new Logger('ConnectorDb', self::MODULE_NAME);
        $this->logger->writeInfo($argv, 'Starting');
        $beanstalk = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false) {
            $beanstalk->wait();
        }
    }

    /**
     * @param BeanstalkClient $tube
     */
    public function onEvents($tube): void
    {
        try {
            $data = json_decode(self::readPayload((string)$tube->getBody()), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->writeError($e->getMessage(), 'Bad request');
            $tube->reply('');
            return;
        }
        $result   = '';
        $function = (string)($data['function'] ?? '');
        if (($data['action'] ?? '') === 'invoke' && in_array($function, self::publicFunctions(), true)) {
            $started = microtime(true);
            try {
                $result = self::saveInTmpFile(['result' => $this->$function(...array_values($data['args'] ?? []))]);
                $duration = microtime(true) - $started;
                if ($duration >= self::SLOW_CALL_SECONDS) {
                    $this->logger->writeInfo(sprintf('%s: %.2fs', $function, $duration), 'Slow call');
                }
            } catch (\Throwable $e) {
                $this->logger->writeError([
                    'function'  => $function,
                    'exception' => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ], 'Invoke failed');
            }
        } else {
            $this->logger->writeError($function, 'Unknown function');
        }
        $tube->reply($result);
    }

    /**
     * Вызов функции воркера из другого процесса.
     *
     * @return mixed|null результат функции; null — воркер не ответил или функция завершилась ошибкой
     */
    public static function call(string $function, array $args = [], int $timeout = 60)
    {
        try {
            $request = json_encode(['action' => 'invoke', 'function' => $function, 'args' => $args], JSON_THROW_ON_ERROR);
            if (strlen($request) > self::MAX_INLINE_PAYLOAD_BYTES) {
                $request = self::saveInTmpFile(json_decode($request, true));
                if ($request === '') {
                    return null;
                }
            }
            $client = new BeanstalkClient(self::class);
            $file   = $client->request($request, $timeout);
            if (!is_string($file) || $file === '' || !self::isOwnTmpFile($file)) {
                return null;
            }
            $response = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            @unlink($file);
            return is_array($response) && array_key_exists('result', $response) ? $response['result'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Функции, доступные через call()
    // ---------------------------------------------------------------------------------------------

    private static function publicFunctions(): array
    {
        return [
            'getAccounts',
            'saveAccountOffset',
            'filterMissingLinkedIds',
            'saveCdrs',
            'markExpiredPendingRecords',
            'getPendingRecords',
            'updateCdrRecord',
        ];
    }

    public function getAccounts(bool $enabledOnly = true): array
    {
        $params = ['order' => 'id'];
        if ($enabledOnly) {
            $params[0] = 'enabled = 1';
        }
        return MtsAccounts::find($params)->toArray();
    }

    public function saveAccountOffset(int $accountId, string $offset): bool
    {
        $account = MtsAccounts::findFirstById($accountId);
        if ($account === null) {
            return false;
        }
        $account->offset = $offset;
        if ($account->save() === false) {
            $this->logger->writeError(implode('; ', $account->getMessages()), "Fail save offset of account {$accountId}");
            return false;
        }
        return true;
    }

    /**
     * Возвращает linkedid из списка, которых ещё нет в mts_cdr.
     */
    public function filterMissingLinkedIds(array $linkedIds): array
    {
        $linkedIds = array_values(array_unique(array_map('strval', $linkedIds)));
        $missing   = array_fill_keys($linkedIds, true);
        foreach (array_chunk($linkedIds, 500) as $chunk) {
            $rows = CallHistory::find([
                'linkedid IN ({ids:array})',
                'bind'    => ['ids' => $chunk],
                'columns' => 'linkedid',
            ])->toArray();
            foreach ($rows as $row) {
                unset($missing[$row['linkedid']]);
            }
        }
        return array_keys($missing);
    }

    /**
     * Создаёт/обновляет строки mts_cdr по linkedid.
     *
     * @return array{saved: int, failed: string[]}
     */
    public function saveCdrs(array $rows): array
    {
        $saved  = 0;
        $failed = [];
        // Одна транзакция на пачку: в rollback journal каждый автокоммит — отдельный fsync.
        $connection = (new CallHistory())->getWriteConnection();
        $connection->begin();
        foreach ($rows as $row) {
            $linkedId = (string)($row['linkedid'] ?? '');
            if ($linkedId === '') {
                continue;
            }
            try {
                $dbCdr = CallHistory::findFirst(['linkedid=:linkedid:', 'bind' => ['linkedid' => $linkedId]]);
                if (!$dbCdr) {
                    $dbCdr = new CallHistory();
                } elseif ($dbCdr->mts_rec_status === 'ok' && !empty($dbCdr->recordingfile) && file_exists($dbCdr->recordingfile)) {
                    // Не затираем уже скачанную запись при пересинхронизации с перекрытием.
                    unset($row['recordingfile'], $row['mts_rec_status']);
                }
                foreach ($row as $key => $value) {
                    $dbCdr->{$key} = $value === null ? null : (string)$value;
                }
                if ($dbCdr->save()) {
                    $saved++;
                    continue;
                }
                $this->logger->writeError([$row, implode('; ', $dbCdr->getMessages())], 'Fail save CDR');
            } catch (\Throwable $e) {
                $this->logger->writeError([$row, $e->getMessage()], 'Exception save CDR');
            }
            $failed[] = $linkedId;
        }
        try {
            $committed = $connection->commit();
        } catch (\Throwable $e) {
            $committed = false;
            try {
                $connection->rollback();
            } catch (\Throwable $ignored) {
            }
            $this->logger->writeError($e->getMessage(), 'Commit exception');
        }
        if (!$committed) {
            $this->logger->writeError('Commit failed, rows: ' . count($rows), 'Fail save CDR');
            $failed = array_values(array_filter(array_map(static fn($row) => (string)($row['linkedid'] ?? ''), $rows)));
            $saved  = 0;
        }
        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * Помечает gone записи, ожидающие дозагрузки дольше срока хранения MTS.
     */
    public function markExpiredPendingRecords(string $since): int
    {
        $rows = [];
        // Сначала вычитываем выборку целиком: запись при открытом курсоре держит блокировку дольше нужного.
        foreach (CallHistory::find([
            "from_account = 'fs-mts' AND mts_rec_status = 'pending' AND start < :since:",
            'bind' => ['since' => $since],
        ]) as $row) {
            $rows[] = $row;
        }
        $count = 0;
        foreach ($rows as $row) {
            $row->mts_rec_status = 'gone';
            if ($row->save()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Записи, ожидающие дозагрузки, для указанных порталов.
     *
     * @param bool $includeLegacy включать строки без mts_account_id (однопортальная версия модуля)
     */
    public function getPendingRecords(string $since, array $accountIds, bool $includeLegacy, int $limit): array
    {
        $accountIds = array_values(array_map('intval', $accountIds));
        if (empty($accountIds)) {
            return [];
        }
        // Только записи включённых порталов: иначе pending-строки выключенного портала
        // могут занять весь батч и записи остальных порталов перестанут дозагружаться.
        $accountCondition = 'mts_account_id IN ({accountIds:array})';
        if ($includeLegacy) {
            $accountCondition = "($accountCondition OR mts_account_id IS NULL)";
        }
        return CallHistory::find([
            "from_account = 'fs-mts' AND mts_rec_status = 'pending' AND mts_rec_dur > 0 AND start >= :since: AND $accountCondition",
            'bind'    => ['since' => $since, 'accountIds' => $accountIds],
            'order'   => 'start ASC',
            'limit'   => $limit,
            'columns' => 'id, linkedid, start, src_chan, dst_chan, mts_account_id, mts_call_id',
        ])->toArray();
    }

    public function updateCdrRecord(int $id, array $fields): bool
    {
        $dbCdr = CallHistory::findFirstById($id);
        if (!$dbCdr) {
            return false;
        }
        foreach (array_intersect_key($fields, array_flip(self::RECORD_FIELDS)) as $key => $value) {
            $dbCdr->{$key} = $value === null ? null : (string)$value;
        }
        return $dbCdr->save() !== false;
    }

    // ---------------------------------------------------------------------------------------------
    // Передача данных через временные файлы
    // ---------------------------------------------------------------------------------------------

    private static function readPayload(string $body): string
    {
        if ($body !== '' && $body[0] !== '{' && self::isOwnTmpFile($body)) {
            $content = (string)file_get_contents($body);
            @unlink($body);
            return $content;
        }
        return $body;
    }

    private static function tmpDir(): string
    {
        $tmpDir = '/tmp';
        $di     = MikoPBXVersion::getDefaultDi();
        if ($di !== null) {
            $dir = $di->getShared('config')->path('core.tempDir') . '/' . self::MODULE_NAME;
            Util::mwMkdir($dir);
            if (is_dir($dir)) {
                $tmpDir = $dir;
            }
        }
        return $tmpDir;
    }

    private static function isOwnTmpFile(string $path): bool
    {
        $real = realpath($path);
        $dir  = realpath(self::tmpDir());
        return $real !== false && $dir !== false && strpos($real, $dir . '/temp-') === 0 && is_file($real);
    }

    private static function saveInTmpFile(array $data): string
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            return '';
        }
        $tmpDir = self::tmpDir();
        // Файлы, которые никто не прочитал (вызывающий процесс не дождался ответа), удаляем через 10 минут.
        foreach (glob($tmpDir . '/temp-*') ?: [] as $old) {
            if (@filemtime($old) < time() - 600) {
                @unlink($old);
            }
        }
        $filename = tempnam($tmpDir, 'temp-');
        if ($filename === false || file_put_contents($filename, $json, LOCK_EX) === false) {
            return '';
        }
        @chmod($filename, 0600);
        return $filename;
    }
}

if (isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDb::class) === $argv[0]) {
    ConnectorDb::startWorker($argv ?? []);
}
