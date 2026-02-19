<?php

require_once 'Globals.php';

echo "1. Testing with session init...\n";
$response = \Modules\ModuleMtsPbx\Lib\RestAPI\GetController::makeSoapRequest1C('user_list', []);
print_r($response);

$data = [
    'Subject' => 'provider.v1.calls',
    'Data' => json_encode( [
        // 'key' => '001c5cfd-6126-4508-ae0a-b3ece8814809',
        'user_id'  => '35f2a139-948f-403e-a57c-40bcdba008f8',
        'entire_id'=> '',
        'feature'  => '',
        'time'     => '2025-11-27T11:04:31.588445Z',
        'state'    => "Connected", // Connected/ Calling / Finished
        'from'     => ['number' => '79109136612', 'extension' => ''],
        'to'       => ['number' => '79198896688', 'extension' => ''],
        'call_id'  => 'fs-mts-88317125934'
    ]),
];
$response = \Modules\ModuleMtsPbx\Lib\RestAPI\GetController::makeSoapRequest1C('call_event', $data);
var_dump($response);
