<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */


namespace Modules\ModuleMtsPbx\Lib;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleMtsPbx\Lib\RestAPI\GetController;

class MtsPbxConf extends ConfigClass
{

    /**
     * Добавление задач в crond.
     *
     * @param $tasks
     */
    public function createCronTasks(&$tasks): void
    {
        if ( !is_array($tasks)) {
            return;
        }
        $binDir          = $this->moduleDir.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR;
        $syncCdrPath     = $binDir.'synchCdr.php';
        $downloadRecPath = $binDir.'downloadRecords.php';
        $phpPath         = Util::which('php');
        $tasks[] = "*/1 * * * * $phpPath -f $syncCdrPath > /dev/null 2> /dev/null\n";
        $tasks[] = "*/5 * * * * $phpPath -f $downloadRecPath > /dev/null 2> /dev/null\n";
    }

    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [GetController::class, 'getDataAction', '/pbxcore/mts-pbx/cdr', 'get', '/', true],
            [GetController::class, 'processEvent','/pbxcore/api/mts-pbx/v1/event', 'post', '/', true],
        ];
    }

    /**
     * Generates additional fail2ban jail conf rules
     *
     * @return string
     */
    public function generateFail2BanFilters():string
    {
        return "[INCLUDES]\n" .
            "before = common.conf\n" .
            "[Definition]\n" .
            "_daemon = (authpriv.warn |auth.warn )?ModuleMtsPbx\n" .
            'failregex = ^%(__prefix_line)sFrom\s+<HOST>.\s+UserAgent:\s+[a-zA-Z0-9 \s\.,/:;\+\-_\)\(\[\]]*.\s+Fail\s+auth\s+http.$' . "\n" .
            '            ^%(__prefix_line)sFrom\s+<HOST>.\s+UserAgent:\s+[a-zA-Z0-9 \s\.,/:;\+\-_\)\(\[\]]*.\s+File\s+not\s+found.$' . "\n" .
            "ignoreregex =\n";
    }
}