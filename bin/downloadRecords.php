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

use GuzzleHttp\Client;
use MikoPBX\Core\System\Util;
use Modules\ModuleMtsPbx\bin\ConnectorDb;
use Modules\ModuleMtsPbx\Lib\Logger;
use Modules\ModuleMtsPbx\Lib\MtsAccountKey;
use Modules\ModuleMtsPbx\Lib\MtsCallId;
use Modules\ModuleMtsPbx\Lib\MtsCdrSync;
use Modules\ModuleMtsPbx\Models\MtsAccounts;

require_once 'Globals.php';

// MTS хранит записи 30 дней. +1 день буфер на часовые пояса / поздний фикс статуса.
const RECORDS_LOOKBACK_DAYS = 31;
const RECORDS_BATCH_LIMIT   = 200;
const RECORDS_PID_FILE      = '/var/run/mts-records.pid';

$logger = new Logger('DownloadRecords', 'ModuleMtsPbx');

// Перехватываем все необработанные исключения и фатальные ошибки — иначе
// MikoPBX может автоматически отключить модуль из-за ошибки в воркере крона.
set_exception_handler(static function (\Throwable $e) use ($logger) {
    $logger->writeError([
        'exception' => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'trace'     => $e->getTraceAsString(),
    ], 'Uncaught exception in downloadRecords.php');
    exit(1);
});

function recordsProcessExists(): bool
{
    $pid = posix_getpid();
    if (file_exists(RECORDS_PID_FILE)) {
        $psPath      = Util::which('ps');
        $busyboxPath = Util::which('busybox');
        $oldPid      = trim(file_get_contents(RECORDS_PID_FILE));
        $output      = trim(shell_exec("$psPath -A -o 'pid' | $busyboxPath awk '{print $1}' | $busyboxPath grep '^$oldPid\$'") ?? '');
        if (!empty($output)) {
            return true;
        }
    }
    file_put_contents(RECORDS_PID_FILE, $pid);
    return false;
}

function extractTrunkId(?string $chan): string
{
    if (empty($chan) || strpos($chan, 'PJSIP/mts_') !== 0) {
        return '';
    }
    $rest = substr($chan, strlen('PJSIP/mts_'));
    $lastDash = strrpos($rest, '-');
    return $lastDash === false ? $rest : substr($rest, 0, $lastDash);
}

if (recordsProcessExists()) {
    echo "Process exists...".PHP_EOL;
    exit(12);
}

// Скрипт не обращается к SQLite модуля напрямую — только через воркер ConnectorDb.
// Порталы: включённые — источник ключа/домена; строки mts_cdr без mts_account_id
// (загружены однопортальной версией) относятся к порталу со старым форматом ID.
$rows = ConnectorDb::call('getAccounts', [true]);
if (!is_array($rows)) {
    $logger->writeError('ConnectorDb did not respond', 'Fail get accounts');
    exit(1);
}
$accounts      = [];
$legacyAccount = null;
foreach ($rows as $account) {
    if (empty($account['domain'])) {
        $account['domain'] = MtsAccountKey::parseDomain((string)$account['authApiKey']);
    }
    if (empty($account['authApiKey']) || empty($account['domain'])) {
        continue;
    }
    if (!MtsAccounts::isDownloadRecordsEnabled($account['downloadRecords'] ?? null)) {
        continue; // скачивание записей у портала выключено
    }
    $accounts[(int)$account['id']] = $account;
    if ($legacyAccount === null && (string)$account['legacyIdFormat'] === '1') {
        $legacyAccount = $account;
    }
}
if (empty($accounts)) {
    exit(1);
}

$since = (new DateTimeImmutable('-' . RECORDS_LOOKBACK_DAYS . ' days'))->format('Y-m-d H:i:s');

// Устаревшие pending (запись в MTS уже удалена по истечении хранения) — помечаем gone,
// чтобы они не висели вечно и не попадали в выборку при каждом запуске.
$expiredCount = ConnectorDb::call('markExpiredPendingRecords', [$since], 120);
if ($expiredCount === null) {
    $logger->writeError('ConnectorDb did not respond', 'Fail to mark expired pending records');
} elseif ($expiredCount > 0) {
    $logger->writeInfo("Marked {$expiredCount} expired pending records as gone (older than " . RECORDS_LOOKBACK_DAYS . " days)");
}

$pendingCdrs = ConnectorDb::call('getPendingRecords', [$since, array_keys($accounts), $legacyAccount !== null, RECORDS_BATCH_LIMIT]);
if (!is_array($pendingCdrs)) {
    $logger->writeError('ConnectorDb did not respond', 'Fail get pending records');
    exit(1);
}
if (count($pendingCdrs) === 0) {
    exit(0);
}

$logger->writeInfo("Start downloading records, pending count: ".count($pendingCdrs));

$client    = new Client();
$recordUrl = 'https://aa.mts.ru/api/ac20/trunks/record';
$ok        = 0;
$gone      = 0;
$stillPending = 0;

$saveRecord = static function (array $row, array $fields) use ($logger): void {
    if (ConnectorDb::call('updateCdrRecord', [(int)$row['id'], $fields]) !== true) {
        $logger->writeError(['id' => $row['id'], 'fields' => $fields], 'Fail update record status (ConnectorDb)');
    }
};

foreach ($pendingCdrs as $dbCDR) {
    $fields = [];
    if ($dbCDR['mts_account_id'] === null || $dbCDR['mts_account_id'] === '') {
        $account = $legacyAccount;
        if ($account === null) {
            continue;
        }
        $callId = MtsCallId::callIdFromLegacyLinkedId((string)$dbCDR['linkedid']);
        // Заполняем новые колонки у строк однопортальной версии.
        $fields['mts_account_id'] = (string)$account['id'];
        $fields['mts_call_id']    = $callId;
    } else {
        $account = $accounts[(int)$dbCDR['mts_account_id']] ?? null;
        if ($account === null) {
            continue;
        }
        $callId = (string)$dbCDR['mts_call_id'];
    }
    $trunkId = extractTrunkId($dbCDR['dst_chan']);
    if ($trunkId === '') {
        $trunkId = extractTrunkId($dbCDR['src_chan']);
    }
    if (empty($callId) || empty($trunkId)) {
        // Не можем восстановить параметры запроса — отметим как недоступно, иначе будем биться вечно.
        $saveRecord($dbCDR, $fields + ['mts_rec_status' => 'gone']);
        $gone++;
        continue;
    }

    try {
        $startDate = new DateTime($dbCDR['start']);
    } catch (\Exception $e) {
        $startDate = new DateTime();
    }
    $filename = MtsCdrSync::recordPath((string)$dbCDR['linkedid'], $startDate);
    if (file_exists($filename)) {
        $saveRecord($dbCDR, $fields + ['recordingfile' => $filename, 'mts_rec_status' => 'ok']);
        $ok++;
        continue;
    }
    Util::mwMkdir(dirname($filename));

    try {
        $response = $client->request('GET', $recordUrl, [
            'query' => [
                'Domain'  => $account['domain'],
                'TrunkId' => $trunkId,
                'CallId'  => $callId,
                'Type'    => 'mp3',
            ],
            'headers' => [
                'Authorization' => 'Bearer '.$account['authApiKey'],
                'accept'        => 'application/json',
            ],
            'timeout' => 30, 'connect_timeout' => 5, 'read_timeout' => 30,
            'http_errors' => false,
        ]);
        $code = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($code === 200) {
            file_put_contents($filename, $body);
            $fields += ['recordingfile' => $filename, 'mts_rec_status' => 'ok'];
            $ok++;
        } elseif ($code === 404 || $code === 410) {
            $fields += ['mts_rec_status' => 'gone'];
            $gone++;
            $logger->writeInfo([
                'account' => $account['id'], 'callId' => $callId, 'trunkId' => $trunkId, 'status' => $code,
            ], 'Record gone');
        } else {
            // 204 / 5xx — ещё не готово или временная ошибка, оставляем pending.
            $stillPending++;
        }
        if (!empty($fields)) {
            $saveRecord($dbCDR, $fields);
        }
        usleep(150000);
    } catch (\Exception $e) {
        $logger->writeError([
            'account' => $account['id'], 'callId' => $callId, 'trunkId' => $trunkId, 'exception' => $e->getMessage(),
        ], 'Fail download record');
        $stillPending++;
    }
}

$logger->writeInfo("Finished: ok={$ok}, gone={$gone}, stillPending={$stillPending}");
exit(0);
