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

$logger = new Logger('SyncCdr', 'ModuleMtsPbx');
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
    $logger->writeError('Error parce authApiKey...'.$e->getMessage());
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
    // Создаём объект DateTime из строки
    $dt = new DateTime($settings->offset);
    $dt->setTime(0, 0, 0);
    $startTime = $dt->format('Y-m-d\TH:i:s');
    $logger->writeInfo('Start sync '.$startTime.'...');
}
$endTime   = date("Y-m-d\TH:i:s");
try {
    $client = new Client();
    $response = $client->request('GET', 'https://aa.mts.ru/api/ac20/trunks/all', [
        'query' => [
            'Domain'  => $domain,
        ],
        'headers' => [
            'Authorization' => 'Bearer '.$settings->authApiKey,
            'accept' => 'application/json',
        ],
        'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5
    ]);
    $trunksData = json_decode($response->getBody(), true);
    $status  = $response->getStatusCode();
    $message = $response->getBody();
}catch (\Exception $e){
    $trunksData = null;
    $status = 0;
    $message = $e->getMessage();
}
if(!is_array($trunksData)){
    $logger->writeError([$status, $message],"Fail Get Trunks");
    exit(11);
}

$results = [];
$limit = 1000;
foreach ($trunksData as $trunkData) {
    $offset = 0;
    $count  = $limit;
    while ($count>=$limit){
        $logger->writeInfo("Get CDR for $trunkData[trunkId], offset: $offset");
        usleep(200000);
        try {
            $response = $client->request('GET', 'https://aa.mts.ru/api/ac20/trunks/statistics', [
                'query' => [
                    'Domain'  => $domain,
                    'TrunkId' => $trunkData['trunkId'],
                    'Begin'   => $startTime,
                    'End'     => $endTime,
                    'Limit'   => $limit,
                    'Offset'  => $offset,
                ],
                'headers' => [
                    'Authorization' => 'Bearer '.$settings->authApiKey,
                    'accept' => 'application/json',
                ],
                'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5
            ]);
            $res = json_decode($response->getBody(), true);
            $status = $response->getStatusCode();
            $message = $response->getBody();
        }catch (\Exception $e){
            $res = null;
            $status = 0;
            $message = $e->getMessage();
        }
        if(is_array($res)){
            $count  = count($res);
            $offset = $offset + $count - ($offset===0?1:0);
            $results[] = $res;
        }else{
            $count = 0;
            $haveError = true;
            $logger->writeError(["status: $status", $message],"Fail Get CDR for $trunkData[trunkId] offset: $offset");
        }
    }
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

    $filename = '';
    if(intval($cdr['recDur']) > 0){
        $filename = Storage::getMonitorDir().$startDate->format("/Y/m/d/H/").$cdr['callId'].'.mp3';
        $filenameFail = $filename.'.fail';
        if(!file_exists($filename)){
            Util::mwMkdir(dirname($filename));
            try {
                $response = $client->request('GET', 'https://aa.mts.ru/api/ac20/trunks/record', [
                    'query' => [
                        'Domain'  => $domain,
                        'TrunkId' => $cdr['trunkId'],
                        'CallId'   => $cdr['callId'],
                        'Type'   => 'mp3'
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer '.$settings->authApiKey,
                        'accept' => 'application/json',
                    ],
                    'timeout' => 5, 'connect_timeout' => 5, 'read_timeout' => 5
                ]);
                $code = $response->getStatusCode();
                if($code === 200){
                    file_put_contents($filename, $response->getBody()->getContents());
                    if(file_exists($filenameFail)){
                        unlink($filenameFail);
                        $logger->writeInfo($cdr,"Get recording OK, was new attempt...");
                    }
                }else{
                    $logger->writeError($cdr,"Fail download file code:".$code.', message: '.$response->getBody()->getContents());
                    file_put_contents($filenameFail, $response->getBody()->getContents());
                    $filename = '';
                }
                usleep(100000);
            }catch (Exception $e){
                $logger->writeError($cdr,"Fail download file ".$e->getMessage());
                $haveError = true;
            }
        }
    }else{
        $filenameFail = $filename.'.fail';
        $logger->writeError($cdr,"File not exists - recDur: 0");
        file_put_contents($filenameFail, $response->getBody()->getContents());
    }

    $tmpCdr = [
        'UNIQUEID'  => 'fs-mts-'.$cdr['callId'],
        'linkedid'  => 'fs-mts-'.$cdr['callId'],
        'start'     => $startDate->format("Y-m-d H:i:s.u"),
        'answer'    => $startDate->modify('+'.intval($cdr['wait']).' seconds')->format("Y-m-d H:i:s.u"),
        'endtime'   => $startDate->modify('+'.$duration.' seconds')->format("Y-m-d H:i:s.u"),
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
    ];

    $ch = 0;
    while ($ch < 15){
        $ch++;
        try {
            $dbCDR = CallHistory::findFirst(['linkedid=:linkedid:', 'bind' => [ 'linkedid' => $tmpCdr['linkedid']]  ]);
            if(!$dbCDR){
                $dbCDR = new CallHistory();
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
if($haveError === false){
    $settings->offset = $endTime;
    $settings->save();
    $logger->writeInfo("Update offset  $endTime...");
}