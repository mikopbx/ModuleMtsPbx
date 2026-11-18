<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 5 2019
 */

namespace Modules\ModuleMtsPbx\Setup;

use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleMtsPbx\Lib\ModuleSettings;
use Modules\ModuleMtsPbx\Models\ModuleMtsPbx;
use Modules\ModuleMtsPbx\Models\MtsAccounts;


/**
 * Class PbxExtensionSetup
 * Module installer and uninstaller
 *
 * @package Modules\ModuleMtsPbx\Setup
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{

    /**
     * PbxExtensionSetup constructor.
     *
     * @param string $moduleUniqueID - the unique module identifier
     */
    public function __construct(string $moduleUniqueID)
    {
        parent::__construct($moduleUniqueID);

    }

    /**
     * Creates database structure according to models annotations
     *
     * If it necessary, it fills some default settings, and change sidebar menu item representation for this module
     *
     * After installation it registers module on PbxExtensionModules model
     *
     *
     * @return bool result of installation
     */
    public function installDB(): bool
    {
        $result = $this->createSettingsTableByModelsAnnotations();

        if ($result) {
            $result = $this->registerNewModule();
        }

        if ($result) {
            $result = $this->addToSidebar();
        }

        if ($result) {
            $this->migrateSettings();
        }

        return $result;
    }

    /**
     * Приводит настройки к многопортальной схеме:
     *  - новая установка: создаёт строку настроек, авторизация CDR включена;
     *  - обновление: авторизация CDR выключена (если не задана), API ключ/смещение/offset
     *    однопортальной версии переносятся в MtsAccounts как портал со старым форматом ID.
     */
    private function migrateSettings(): void
    {
        try {
            $settings = ModuleMtsPbx::findFirst();
            if ($settings === null) {
                $settings = new ModuleMtsPbx();
                ModuleSettings::initNewSettings($settings);
                $settings->save();
                return;
            }

            $changed = ModuleSettings::applyUpgradeDefaults($settings);
            if (empty($settings->accountsMigrated)) {
                $data = ModuleSettings::legacyAccountData($settings);
                if ($data !== null && MtsAccounts::count() === 0) {
                    $account = new MtsAccounts();
                    foreach ($data as $key => $value) {
                        $account->$key = $value;
                    }
                    if (!$account->save()) {
                        // Не отмечаем перенос выполненным — повторим при следующей установке.
                        if ($changed) {
                            $settings->save();
                        }
                        return;
                    }
                }
                $settings->accountsMigrated = '1';
                $changed = true;
            }
            if ($changed) {
                $settings->save();
            }
        } catch (\Throwable $e) {
            // Ошибка миграции не должна ломать установку модуля.
        }
    }

    /**
     * Create folders on PBX system and apply rights
     *
     * @return bool result of installation
     */
    public function installFiles(): bool
    {
        return parent::installFiles();
    }

    /**
     * Unregister module on PbxExtensionModules,
     * Makes data backup if $keepSettings is true
     *
     * Before delete module we can do some soft delete changes, f.e. change forwarding rules i.e.
     *
     * @param  $keepSettings bool creates backup folder with module settings
     *
     * @return bool uninstall result
     */
    public function unInstallDB($keepSettings = false): bool
    {
        return parent::unInstallDB($keepSettings);
    }

}