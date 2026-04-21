<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */
use GuzzleHttp\Client;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\Workers\WorkerCallEvents;
use Modules\ModuleMtsPbx\Models\ModuleMtsPbx;
use Modules\ModuleMtsPbx\Lib\Logger;
use MikoPBX\Core\System\Storage;
use Modules\ModuleMtsPbx\Models\CallHistory;

require_once 'Globals.php';

// Лаг от текущего времени: MTS API не возвращает звонки, которые ещё в процессе.
// Откатываем правую границу окна, чтобы дать звонкам успеть завершиться и попасть в индекс MTS.
const MTS_SYNC_LAG_MINUTES = 5;

// Перекрытие с предыдущим окном: пересинхронизируем последние N минут на каждом запуске.
// CallHistory ищется по linkedid (см. ниже) — повторная обработка идемпотентна.
const MTS_SYNC_OVERLAP_MINUTES = 10;

$logger = new Logger('SyncCdr', 'ModuleMtsPbx');

// Перехватываем все необработанные исключения — иначе MikoPBX может автоматически
// отключить модуль из-за ошибки в воркере крона.
set_exception_handler(static function (\Throwable $e) use ($logger) {
    $logger->writeError([
        'exception' => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'trace'     => $e->getTraceAsString(),
    ], 'Uncaught exception in synchCdr.php');
    exit(1);
});

$haveError = false;
function processExists():bool
{
    $pid = posix_getpid();
    $pidFile = "/var/run/mts-sync.pid";
    $result = false;
    if(file_exists($pidFile)){
        $psPath      = Util::which('ps');
        $busyboxPath = Util::which('busybox');
        $oldPid      = trim(file_get_contents($pidFile));
        $output      = trim(shell_exec("$psPath -A -o 'pid' | $busyboxPath awk '{print $1}' | $busyboxPath grep  '^$oldPid\$'")??'');
        if(!empty($output)){
            echo "Old PID: $oldPid, Find processes: $output...".PHP_EOL;
            $result = true;
        }
    }
    if(!$result){
        file_put_contents($pidFile, $pid);
    }
    return $result;
}

function truncateForLog(string $value, int $maxLen = 3000): string
{
    if (strlen($value) <= $maxLen) {
        return $value;
    }
    $tailLen = strlen($value) - $maxLen;
    return substr($value, 0, $maxLen) . "... [truncated {$tailLen} bytes]";
}

function logHttpRequest(Logger $logger, string $method, string $url, array $query, string $label): void
{
    $logger->writeInfo([
        'label' => $label,
        'method' => $method,
        'url' => $url,
        'query' => $query,
    ], 'HTTP request');
}

function logHttpResponse(Logger $logger, int $status, string $body, string $label, bool $error = false): void
{
    $payload = [
        'label' => $label,
        'status' => $status,
        'body' => truncateForLog($body),
    ];
    if ($error) {
        $logger->writeError($payload, 'HTTP response');
        return;
    }
    $logger->writeInfo($payload, 'HTTP response');
}

function logHttpException(Logger $logger, \Exception $e, string $label): void
{
    $logger->writeError([
        'label' => $label,
        'exception' => $e->getMessage(),
    ], 'HTTP exception');
}

if(processExists()){
    echo "Process exists...".PHP_EOL;
    exit(12);
}

$settings = ModuleMtsPbx::findFirst();
if(!$settings || empty($settings->authApiKey)){
    echo "empty settings";
    exit(1);
}

$settings->gap = intval($settings->gap);
$domain = '';
try {
    $data = json_decode(base64_decode(explode('.', $settings->authApiKey)[1]), true);
    $host = explode('@', $data['id'])[1]??'';
    if(!empty($host)){
        $domain = $host;
    }
}catch (\Exception $e){
    $logger->writeError('Error parse authApiKey...'.$e->getMessage());
}

if(empty($domain)){
    exit(2);
}

$date = new DateTime();
$date->modify('-30 day');
if(empty($settings->offset)){
    $logger->writeInfo('Offset is empty start sync -30 day...');
    $startTime = $date->format('Y-m-d\TH:i:s');
}else{
    // Continue from last saved offset, отступая на MTS_SYNC_OVERLAP_MINUTES назад,
    // чтобы перехватить звонки, которые ранее были в процессе на границе окна.
    $dt = new DateTime($settings->offset);
    $dt->modify('-' . MTS_SYNC_OVERLAP_MINUTES . ' minutes');
    $startTime = $dt->format('Y-m-d\TH:i:s');
    $logger->writeInfo('Start sync '.$startTime.' (overlap '.MTS_SYNC_OVERLAP_MINUTES.'m)...');
}
$maxWindowDays = 10;
// Откатываем правую границу окна на MTS_SYNC_LAG_MINUTES — даём активным звонкам завершиться.
$now = (new DateTimeImmutable('now'))->modify('-' . MTS_SYNC_LAG_MINUTES . ' minutes');
try {
    $trunksUrl = 'https://aa.mts.ru/api/ac20/trunks/all';
    $trunksQuery = [
        'Domain'  => $domain,
    ];
    logHttpRequest($logger, 'GET', $trunksUrl, $trunksQuery, 'trunks/all');
    $client = new Client();
    $response = $client->request('GET', $trunksUrl, [
        'query' => $trunksQuery,
        'headers' => [
            'Authorization' => 'Bearer '.$settings->authApiKey,
            'accept' => 'application/json',
        ],
        'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5
    ]);
    $message = (string)$response->getBody();
    $trunksData = json_decode($message, true);
    $status  = $response->getStatusCode();
    logHttpResponse($logger, $status, $message, 'trunks/all');
}catch (\Exception $e){
    $trunksData = null;
    $status = 0;
    $message = $e->getMessage();
    logHttpException($logger, $e, 'trunks/all');
}
if(!is_array($trunksData)){
    $logger->writeError([$status, $message],"Fail Get Trunks");
    exit(11);
}

$results = [];
$limit = 1000;
$statsUrl = 'https://aa.mts.ru/api/ac20/trunks/statistics';

$windowStart = new DateTimeImmutable($startTime);
while ($windowStart < $now) {
    $windowEnd = $windowStart->modify("+{$maxWindowDays} days");
    if ($windowEnd > $now) {
        $windowEnd = $now;
    }
    $windowStartTime = $windowStart->format('Y-m-d\TH:i:s');
    $windowEndTime = $windowEnd->format('Y-m-d\TH:i:s');

    $logger->writeInfo("Start sync window {$windowStartTime} - {$windowEndTime}...");

    foreach ($trunksData as $trunkData) {
        $offset = 0;
        $count  = $limit;
        while ($count >= $limit) {
            $trunkId = $trunkData['trunkId'] ?? '';
            $logger->writeInfo("Get CDR for {$trunkId}, offset: {$offset}");
            usleep(200000);

            $statsQuery = [
                'Domain'  => $domain,
                'TrunkId' => $trunkId,
                'Begin'   => $windowStartTime,
                'End'     => $windowEndTime,
                'Limit'   => $limit,
                'Offset'  => $offset,
            ];
            $logger->writeInfo($statsQuery, 'Statistics request params');
            logHttpRequest($logger, 'GET', $statsUrl, $statsQuery, 'trunks/statistics');
            try {
                $response = $client->request('GET', $statsUrl, [
                    'query' => $statsQuery,
                    'headers' => [
                        'Authorization' => 'Bearer '.$settings->authApiKey,
                        'accept' => 'application/json',
                    ],
                    'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5
                ]);
                $message = (string)$response->getBody();
                $res = json_decode($message, true);
                $status = $response->getStatusCode();
                logHttpResponse($logger, $status, $message, 'trunks/statistics');
            } catch (\Exception $e) {
                $res = null;
                $status = 0;
                $message = $e->getMessage();
                logHttpException($logger, $e, 'trunks/statistics');
            }

            if (is_array($res)) {
                $count  = count($res);
                $offset = $offset + $count;
                $results[] = $res;
                continue;
            }

            if ($status === 204) {
                // No CDR data for the selected period/page, not an error.
                $count = 0;
                $logger->writeInfo([
                    'trunkId' => $trunkData['trunkId'],
                    'offset' => $offset,
                    'status' => $status,
                    'window' => [$windowStartTime, $windowEndTime],
                ], 'CDR page is empty');
                continue;
            }

            $count = 0;
            $haveError = true;
            $logger->writeError([
                "status: $status",
                $message,
                'query' => $statsQuery,
            ], "Fail Get CDR for {$trunkId} offset: {$offset}");
            break 2; // stop syncing this window on any error
        }
    }

    if ($haveError) {
        break;
    }

    // Window synced successfully. Persist progress and move to next window.
    $settings->offset = $windowEndTime;
    $settings->save();
    $logger->writeInfo("Update offset  {$windowEndTime}...");

    if ($windowEnd >= $now) {
        break;
    }
    $windowStart = $windowEnd;
}
if (empty($results)) {
    exit(0);
}

$fsData = array_merge(...$results);
if(empty($fsData)){
    exit(0);
}

$cdrData = [
    'action' => 'insert_cdr',
    'rows' => [],
];

$clientBeanstalk  = new BeanstalkClient(WorkerCallEvents::class);
$logger->writeInfo("Parse CDRs...");

foreach ($fsData as $index => $cdr){
    foreach (['via', 'an', 'dn'] as $key){
        $cdr[$key] = $cdr[$key]??'';
        try {
            $cdr[$key] = strlen($cdr[$key])===10?'7'.$cdr[$key]:$cdr[$key];
        }catch (\Exception $e){
            $logger->writeError($cdr,"Parse CDRs...".$e->getMessage());
        }
    }
    if(intval($cdr['rel']) === 1){
        // внутренний вызов
        $src = $cdr['an'];
        $dst = $cdr['dn'];
        $cdr['via'] = '';
    }if($cdr['via'] === $cdr['an']){
        // Исходящий с номера сотрудника.
        $src = $cdr['an'];
        $dst = $cdr['dn'];
        $src_chan = 'PJSIP/mts_'.$cdr['trunkId'].'-'.$cdr['callId'];
        $dst_chan = 'PJSIP/mts-'.$cdr['callId'];
        $cdr['via'] = '';
    }else{
        $src = $cdr['an'];
        $dst = $cdr['dn'];
        $src_chan = 'PJSIP/mts-'.$cdr['callId'];
        $dst_chan = 'PJSIP/mts_'.$cdr['trunkId'].'-'.$cdr['callId'];
    }
    $duration  = intval($cdr['duration']);
    $startDate = (new DateTime($cdr['startTime']))->modify($settings->gap.' hour');

    $recDur = intval($cdr['recDur']);
    $filename = '';
    $recStatus = $recDur > 0 ? 'pending' : 'none';
    if($recDur > 0){
        $candidate = Storage::getMonitorDir().$startDate->format("/Y/m/d/H/").$cdr['callId'].'.mp3';
        if(file_exists($candidate)){
            // Уже скачана при предыдущем запуске.
            $filename = $candidate;
            $recStatus = 'ok';
        } else {
            Util::mwMkdir(dirname($candidate));
            $recordUrl = 'https://aa.mts.ru/api/ac20/trunks/record';
            $recordQuery = [
                'Domain'  => $domain,
                'TrunkId' => $cdr['trunkId'],
                'CallId'   => $cdr['callId'],
                'Type'   => 'mp3'
            ];
            logHttpRequest($logger, 'GET', $recordUrl, $recordQuery, 'trunks/record');
            try {
                $response = $client->request('GET', $recordUrl, [
                    'query' => $recordQuery,
                    'headers' => [
                        'Authorization' => 'Bearer '.$settings->authApiKey,
                        'accept' => 'application/json',
                    ],
                    'timeout' => 30, 'connect_timeout' => 5, 'read_timeout' => 30,
                    'http_errors' => false,
                ]);
                $code = $response->getStatusCode();
                $recordBody = $response->getBody()->getContents();
                $isError = ($code >= 400);
                logHttpResponse(
                    $logger,
                    $code,
                    $code === 200 ? "binary bytes: ".strlen($recordBody) : $recordBody,
                    'trunks/record',
                    $isError
                );
                if($code === 200){
                    file_put_contents($candidate, $recordBody);
                    $filename = $candidate;
                    $recStatus = 'ok';
                } elseif($code === 404 || $code === 410){
                    // Запись недоступна навсегда (истекло хранение и т.п.) — больше не пытаемся.
                    $recStatus = 'gone';
                } else {
                    // 204 / 5xx / прочее — оставляем pending, дозагрузит downloadRecords.php.
                    $recStatus = 'pending';
                }
                usleep(100000);
            }catch (Exception $e){
                logHttpException($logger, $e, 'trunks/record');
                // Сетевая ошибка — пусть дозагрузчик повторит позже.
                $recStatus = 'pending';
            }
        }
    }

    // DateTime::modify мутирует объект, поэтому считаем answer/endtime от клонов,
    // иначе endtime получит лишние +wait секунд.
    $waitSec  = intval($cdr['wait']);
    $answerAt = (clone $startDate)->modify('+'.$waitSec.' seconds');
    $endAt    = (clone $startDate)->modify('+'.$duration.' seconds');

    $tmpCdr = [
        'UNIQUEID'  => 'fs-mts-'.$cdr['callId'],
        'linkedid'  => 'fs-mts-'.$cdr['callId'],
        'start'     => $startDate->format("Y-m-d H:i:s.u"),
        'answer'    => $answerAt->format("Y-m-d H:i:s.u"),
        'endtime'   => $endAt->format("Y-m-d H:i:s.u"),
        "did"       => $cdr['via'],
        "src_num"   => $src,
        "src_chan"  => $src_chan,
        "dst_num"   => $dst,
        "dst_chan"  => $dst_chan,
        'duration'  => $duration,
        'billsec'   => $duration - intval($cdr['wait']),
        'disposition'   => (intval($cdr['duration']) - intval($cdr['wait'])>1)?'ANSWERED':'NOANSWER',
        'recordingfile'   => $filename,
        'from_account'   => 'fs-mts',
        'work_completed'   => '1',
        'is_app'   => '0',
        'transfer'   => '0',
        'mts_rec_dur'    => $recDur,
        'mts_rec_status' => $recStatus,
    ];

    $ch = 0;
    while ($ch < 15){
        $ch++;
        try {
            $dbCDR = CallHistory::findFirst(['linkedid=:linkedid:', 'bind' => [ 'linkedid' => $tmpCdr['linkedid']]  ]);
            if(!$dbCDR){
                $dbCDR = new CallHistory();
            } else {
                // Не затираем уже скачанную запись при пересинхронизации с перекрытием.
                if($dbCDR->mts_rec_status === 'ok' && !empty($dbCDR->recordingfile) && file_exists($dbCDR->recordingfile)){
                    unset($tmpCdr['recordingfile'], $tmpCdr['mts_rec_status']);
                }
            }
            foreach ($tmpCdr as $key => $value){
                $dbCDR->{$key} = $value;
            }
            if($dbCDR->save()){
                break;
            }
            $logger->writeError($tmpCdr,"Fail save CDR...");
        }catch (Exception $e){
            $logger->writeError($tmpCdr,"Exception save CDR...");
            sleep(1);
        }
    }

    $cdrData['rows'][] = $tmpCdr;
    if(count($cdrData['rows'])>10){
        $clientBeanstalk->publish(json_encode($cdrData),WorkerCallEvents::class);
        $cdrData['rows'] = [];
        usleep(100000);
    }
}
if(!empty($cdrData['rows'])){
    $clientBeanstalk->publish(json_encode($cdrData),WorkerCallEvents::class);
}
// Offset is updated per-window above. Do not update it here on partial failures.