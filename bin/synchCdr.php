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
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use Modules\ModuleMtsPbx\bin\ConnectorDb;
use Modules\ModuleMtsPbx\Lib\Logger;
use Modules\ModuleMtsPbx\Lib\MtsCdrSync;
use Modules\ModuleMtsPbx\Lib\ProcessLock;

require_once 'Globals.php';

// Синхронизация CDR включённых порталов MTS (см. Lib/MtsCdrSync).
//
// Без --account скрипт работает как диспетчер: для каждого портала, который сейчас не
// синхронизируется, запускает в фоне отдельный процесс `--account=<id>` и сразу завершается.
// BusyBox crond не запускает строку crontab повторно, пока жив её предыдущий процесс
// ("process already running"), поэтому долгая загрузка истории одного портала,
// выполняемая прямо в процессе cron, останавливала бы синхронизацию всех порталов.
//
// Режим глубокой дозагрузки (--lookback=N): запрашиваем CDR за последние N минут,
// НЕ трогая offset. Нужен из-за длинных/отложенных звонков: CDR появляется в индексе
// MTS только после завершения звонка, когда основное скользящее окно уже ушло вперёд.
// Запускается отдельной cron-задачей реже, чем основной проход (см. MtsPbxConf::createCronTasks).
$deepLookbackMinutes = 0;
$accountId           = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--lookback=(\d+)$/', $arg, $m)) {
        $deepLookbackMinutes = (int)$m[1];
    } elseif (preg_match('/^--account=(\d+)$/', $arg, $m)) {
        $accountId = (int)$m[1];
    }
}

$logger = new Logger($deepLookbackMinutes > 0 ? 'SyncCdrDeep' : 'SyncCdr', 'ModuleMtsPbx');

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

/**
 * Lock на портал: основной и глубокий проходы одного портала НЕ должны работать одновременно,
 * иначе при пересекающихся окнах возникают гонки (дубли строк mts_cdr, порча mp3).
 * Разные порталы друг друга не блокируют.
 */
function accountLockFile(int $accountId): string
{
    return "/var/run/mts-sync-{$accountId}.lock";
}

// Скрипт не обращается к SQLite модуля напрямую — только через воркер ConnectorDb.
$accounts = ConnectorDb::call('getAccounts', [true]);
if (!is_array($accounts)) {
    $logger->writeError('ConnectorDb did not respond', 'Fail get accounts');
    exit(1);
}
$accounts = array_values(array_filter($accounts, static fn($account) => !empty($account['authApiKey'])));

if ($accountId === 0) {
    // Режим диспетчера.
    $phpPath = Util::which('php');
    foreach ($accounts as $account) {
        $id = (int)$account['id'];
        if (ProcessLock::isLocked(accountLockFile($id))) {
            continue;
        }
        $command = "$phpPath -f " . __FILE__ . " -- --account={$id}"
            . ($deepLookbackMinutes > 0 ? " --lookback={$deepLookbackMinutes}" : '');
        Processes::mwExecBg($command);
    }
    exit(0);
}

$account = null;
foreach ($accounts as $row) {
    if ((int)$row['id'] === $accountId) {
        $account = $row;
        break;
    }
}
if ($account === null) {
    exit(0); // портал удалён или выключен
}

$lock = new ProcessLock(accountLockFile($accountId));
if (!$lock->acquire()) {
    exit(0); // портал уже синхронизируется другим процессом
}
try {
    $result = (new MtsCdrSync($account, $logger, new Client(), $deepLookbackMinutes))->run();
} catch (\Throwable $e) {
    $result = 1;
    $logger->writeError([
        'exception' => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ], "[account {$accountId}] Exception in sync");
} finally {
    $lock->release();
}
exit($result);
