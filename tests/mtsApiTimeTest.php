<?php

declare(strict_types=1);

use Modules\ModuleMtsPbx\Lib\MtsApiTime;

require_once dirname(__DIR__) . '/Lib/MtsApiTime.php';

date_default_timezone_set('Europe/Samara');

$samaraNow = new DateTimeImmutable('2026-09-02 15:00:00');
$apiNow = MtsApiTime::now($samaraNow);

if ($apiNow->format('Y-m-d\TH:i:sP') !== '2026-09-02T14:00:00+03:00') {
    throw new RuntimeException('MTS API time must be normalized to Europe/Moscow.');
}

$cursor = MtsApiTime::fromApiValue('2026-09-02T13:50:00');
if ($cursor->modify('-10 minutes')->format('Y-m-d\TH:i:sP') !== '2026-09-02T13:40:00+03:00') {
    throw new RuntimeException('A timezone-less MTS cursor must be interpreted as Moscow time.');
}

fwrite(STDOUT, "OK (2 assertions)\n");
