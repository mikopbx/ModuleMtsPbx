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
use MikoPBX\Core\System\Storage;
use MikoPBX\Core\System\Util;
use Modules\ModuleMtsPbx\Lib\Logger;
use Modules\ModuleMtsPbx\Models\CallHistory;
use Modules\ModuleMtsPbx\Models\ModuleMtsPbx;

require_once 'Globals.php';

const RECORDS_LOOKBACK_DAYS = 7;
const RECORDS_BATCH_LIMIT   = 200;
const RECORDS_PID_FILE      = '/var/run/mts-records.pid';

$logger = new Logger('DownloadRecords', 'ModuleMtsPbx');

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

$settings = ModuleMtsPbx::findFirst();
if (!$settings || empty($settings->authApiKey)) {
    exit(1);
}

$domain = '';
try {
    $data = json_decode(base64_decode(explode('.', $settings->authApiKey)[1]), true);
    $host = explode('@', $data['id'])[1] ?? '';
    if (!empty($host)) {
        $domain = $host;
    }
} catch (\Exception $e) {
    $logger->writeError('Error parse authApiKey...'.$e->getMessage());
}
if (empty($domain)) {
    exit(2);
}

$since = (new DateTimeImmutable('-' . RECORDS_LOOKBACK_DAYS . ' days'))->format('Y-m-d H:i:s');
$pendingCdrs = CallHistory::find([
    "from_account = 'fs-mts' AND mts_rec_status = 'pending' AND mts_rec_dur > 0 AND start >= :since:",
    'bind'  => ['since' => $since],
    'order' => 'start ASC',
    'limit' => RECORDS_BATCH_LIMIT,
]);

if ($pendingCdrs->count() === 0) {
    exit(0);
}

$logger->writeInfo("Start downloading records, pending count: ".$pendingCdrs->count());

$client    = new Client();
$recordUrl = 'https://aa.mts.ru/api/ac20/trunks/record';
$ok        = 0;
$gone      = 0;
$stillPending = 0;

foreach ($pendingCdrs as $dbCDR) {
    $callId  = substr($dbCDR->linkedid, strlen('fs-mts-'));
    $trunkId = extractTrunkId($dbCDR->dst_chan);
    if ($trunkId === '') {
        $trunkId = extractTrunkId($dbCDR->src_chan);
    }
    if (empty($callId) || empty($trunkId)) {
        // Не можем восстановить параметры запроса — отметим как недоступно, иначе будем биться вечно.
        $dbCDR->mts_rec_status = 'gone';
        $dbCDR->save();
        $gone++;
        continue;
    }

    try {
        $startDate = new DateTime($dbCDR->start);
    } catch (\Exception $e) {
        $startDate = new DateTime();
    }
    $filename = Storage::getMonitorDir().$startDate->format("/Y/m/d/H/").$callId.'.mp3';
    if (file_exists($filename)) {
        $dbCDR->recordingfile = $filename;
        $dbCDR->mts_rec_status = 'ok';
        $dbCDR->save();
        $ok++;
        continue;
    }
    Util::mwMkdir(dirname($filename));

    try {
        $response = $client->request('GET', $recordUrl, [
            'query' => [
                'Domain'  => $domain,
                'TrunkId' => $trunkId,
                'CallId'  => $callId,
                'Type'    => 'mp3',
            ],
            'headers' => [
                'Authorization' => 'Bearer '.$settings->authApiKey,
                'accept'        => 'application/json',
            ],
            'timeout' => 30, 'connect_timeout' => 5, 'read_timeout' => 30,
            'http_errors' => false,
        ]);
        $code = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($code === 200) {
            file_put_contents($filename, $body);
            $dbCDR->recordingfile  = $filename;
            $dbCDR->mts_rec_status = 'ok';
            $ok++;
        } elseif ($code === 404 || $code === 410) {
            $dbCDR->mts_rec_status = 'gone';
            $gone++;
            $logger->writeInfo([
                'callId' => $callId, 'trunkId' => $trunkId, 'status' => $code,
            ], 'Record gone');
        } else {
            // 204 / 5xx — ещё не готово или временная ошибка, оставляем pending.
            $stillPending++;
        }
        $dbCDR->save();
        usleep(150000);
    } catch (\Exception $e) {
        $logger->writeError([
            'callId' => $callId, 'trunkId' => $trunkId, 'exception' => $e->getMessage(),
        ], 'Fail download record');
        $stillPending++;
    }
}

$logger->writeInfo("Finished: ok={$ok}, gone={$gone}, stillPending={$stillPending}");
exit(0);
