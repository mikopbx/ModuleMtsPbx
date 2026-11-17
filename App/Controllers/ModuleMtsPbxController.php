<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 11 2018
 */
namespace Modules\ModuleMtsPbx\App\Controllers;
use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleMtsPbx\App\Forms\ModuleMtsPbxForm;
use Modules\ModuleMtsPbx\Models\ModuleMtsPbx;
use Modules\ModuleMtsPbx\Lib\CacheManager;
use Modules\ModuleMtsPbx\Lib\ModuleSettings;
use Modules\ModuleMtsPbx\Lib\MtsAccountKey;
use Modules\ModuleMtsPbx\Lib\MtsAccountsForm;
use Modules\ModuleMtsPbx\Lib\RestAPI\GetController;
use Modules\ModuleMtsPbx\Models\MtsAccounts;
use MikoPBX\Common\Models\Providers;

class ModuleMtsPbxController extends BaseController
{
    private $moduleUniqueID = 'ModuleMtsPbx';
    private $moduleDir;

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = "{$this->url->get()}assets/img/cache/{$this->moduleUniqueID}/logo.svg";
        $this->view->submitMode = null;
        parent::initialize();
    }

    public function getTablesDescriptionAction(): void
    {
        $this->view->data = $this->getTablesDescription();
    }

    public function getNewRecordsAction(): void
    {
        $currentPage                 = $this->request->getPost('draw');
        $table                       = $this->request->get('table');
        $this->view->draw            = $currentPage;
        $this->view->recordsTotal    = 0;
        $this->view->recordsFiltered = 0;
        $this->view->data            = [];

        $descriptions = $this->getTablesDescription();
        if(!isset($descriptions[$table])){
            return;
        }
        $className = $this->getClassName($table);
        if(!empty($className)){
            $filter = [];
            if(isset($descriptions[$table]['cols']['priority'])){
                $filter = ['order' => 'priority'];
            }
            $allRecords = $className::find($filter)->toArray();
            $records    = [];
            $emptyRow   = [
                'rowIcon'  =>  $descriptions[$table]['cols']['rowIcon']['icon']??'',
                'DT_RowId' => 'TEMPLATE'
            ];
            foreach ($descriptions[$table]['cols'] as $key => $metadata) {
                if('rowIcon' !== $key){
                    $emptyRow[$key] = '';
                }
            }
            $records[] = $emptyRow;
            foreach ($allRecords as $rowData){
                $tmpData = [];
                $tmpData['DT_RowId'] =  $rowData['id'];
                foreach ($descriptions[$table]['cols'] as $key => $metadata){
                    if('rowIcon' === $key){
                        $tmpData[$key] = $metadata['icon']??'';
                    }elseif('delButton' === $key){
                        $tmpData[$key] = '';
                    }elseif(isset($rowData[$key])){
                        $tmpData[$key] =  $rowData[$key];
                    }
                }
                $records[] = $tmpData;
            }
            $this->view->data      = $records;
        }
    }

    /**
     * Index page controller
     */
    public function indexAction(): void
    {
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs('js/vendor/datatable/dataTables.semanticui.js', true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/module-mts-pbx-index.js", true);
        $footerCollection->addJs('js/vendor/jquery.tablednd.min.js', true);

        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS->addCss("css/cache/{$this->moduleUniqueID}/module-mts-pbx.css", true);
        $headerCollectionCSS->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);

        $settings = ModuleMtsPbx::findFirst();
        if ($settings === null) {
            $settings = new ModuleMtsPbx();
            ModuleSettings::initNewSettings($settings);
            $settings->save();
        }

        $accounts = [];
        foreach (MtsAccounts::find(['order' => 'id']) as $account) {
            $accounts[] = [
                'id'         => $account->id,
                'name'       => (string)$account->name,
                'authApiKey' => (string)$account->authApiKey,
                'authApiKeyShort' => MtsAccountKey::shorten((string)$account->authApiKey, 6, 4),
                'offsetView' => self::formatOffset((string)$account->offset),
                'domain'     => (string)$account->domain,
                'gap'        => (string)ModuleSettings::normalizeGap($account->gap),
                'offset'     => (string)$account->offset,
                'enabled'    => $account->isEnabled(),
                'downloadRecords' => MtsAccounts::isDownloadRecordsEnabled($account->downloadRecords),
                'legacy'     => $account->isLegacyIdFormat(),
            ];
        }
        $this->view->accounts = $accounts;
        $this->view->cdrAuthEnabled = ModuleSettings::isCdrAuthEnabled($settings->cdrAuthEnabled);

        $providers = Providers::find();
        $providersList = [];
        foreach ($providers as $provider){
            $providersList[ $provider->uniqid ] = $provider->getRepresent();
        }
        $options['providers']=$providersList;

        $this->view->form = new ModuleMtsPbxForm($settings, $options);
        $this->view->pick("{$this->moduleDir}/App/Views/index");
    }

    /**
     * Позиция синхронизации для таблицы порталов: `2026-09-16T09:44:05` → `16.09.2026 09:44`.
     */
    private static function formatOffset(string $offset): string
    {
        if ($offset === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($offset))->format('d.m.Y H:i');
        } catch (\Throwable $e) {
            return $offset;
        }
    }

    /**
     * Save settings AJAX action
     */
    public function saveAction() :void
    {
        $data   = $this->request->getPost();
        $result = MtsAccountsForm::normalize($data['accounts'] ?? []);
        if (!empty($result['errors'])) {
            $messages = [];
            foreach ($result['errors'] as $error) {
                $messages[] = $this->translation->_('module_mts_pbx_account_error_' . $error['code'], ['name' => $error['name']]);
            }
            $this->flash->error(implode('<br>', $messages));
            $this->view->success = false;
            return;
        }

        $record = ModuleMtsPbx::findFirst();
        if ($record === null) {
            $record = new ModuleMtsPbx();
            ModuleSettings::initNewSettings($record);
        }
        $record->inLogin        = trim((string)($data['inLogin'] ?? ''));
        $record->inPassword     = trim((string)($data['inPassword'] ?? ''));
        $record->cdrAuthEnabled = MtsAccountsForm::isChecked($data['cdrAuthEnabled'] ?? '') ? '1' : '0';

        // Модели модуля живут в собственной SQLite-базе, $this->db — основная БД MikoPBX.
        $connection = $record->getWriteConnection();
        $connection->begin();
        try {
            $accountIds = [];
            $errors = $this->saveAccounts($result['rows'], $accountIds);
            if (empty($errors) && $record->save() === false) {
                $errors = $record->getMessages();
            }
        } catch (\Throwable $e) {
            $errors = [$e->getMessage()];
        }
        if (!empty($errors)) {
            $connection->rollback();
            $this->flash->error(implode('<br>', $errors));
            $this->view->success = false;
            return;
        }
        $connection->commit();

        CacheManager::setCacheData('ModuleMtsPbx', $record->toArray(), 60);
        GetController::getAccounts(true);

        $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        $this->view->success    = true;
        // Соответствие ключей новых блоков формы (newN) созданным ID — JS обновляет форму без перезагрузки.
        $this->view->accountIds = $accountIds;
    }

    /**
     * Создаёт/обновляет порталы из формы, удаляет отсутствующие в ней.
     *
     * @param array $rows       результат MtsAccountsForm::normalize()['rows']
     * @param array $accountIds заполняется соответствием ключ формы => ID портала
     *
     * @return array ошибки сохранения
     */
    private function saveAccounts(array $rows, array &$accountIds): array
    {
        $existing = [];
        foreach (MtsAccounts::find() as $account) {
            $existing[(string)$account->id] = $account;
        }

        $errors = [];
        $keep   = [];
        foreach ($rows as $row) {
            $account = $existing[$row['key']] ?? null;
            if ($account === null) {
                $account = new MtsAccounts();
                $account->offset         = '';
                $account->legacyIdFormat = 0;
            } elseif (MtsAccountKey::normalizeDomain((string)$account->domain) !== MtsAccountKey::normalizeDomain($row['domain'])) {
                // Ключ другого портала — его история начинается с нуля.
                $account->offset = '';
            }
            $account->name       = $row['name'];
            $account->authApiKey = $row['authApiKey'];
            $account->domain     = $row['domain'];
            $account->gap        = (string)$row['gap'];
            $account->enabled    = $row['enabled'];
            $account->downloadRecords = $row['downloadRecords'];
            if (!$account->save()) {
                foreach ($account->getMessages() as $message) {
                    $errors[] = (string)$message;
                }
                continue;
            }
            $keep[(string)$account->id] = true;
            $accountIds[$row['key']]    = (string)$account->id;
        }
        if (!empty($errors)) {
            return $errors;
        }
        foreach ($existing as $id => $account) {
            if (!isset($keep[$id]) && !$account->delete()) {
                foreach ($account->getMessages() as $message) {
                    $errors[] = (string)$message;
                }
            }
        }
        return $errors;
    }

    /**
     * Clears the synchronization cursor of the account. An empty offset makes the worker
     * reload CDR data starting 30 days ago.
     */
    public function resetOffsetAction(): void
    {
        if (!$this->request->isPost()) {
            $this->view->success = false;
            return;
        }

        $account = MtsAccounts::findFirstById((int)$this->request->getPost('id'));
        if ($account === null || !ModuleSettings::resetOffset($account)) {
            $this->view->success = false;
            return;
        }

        $this->view->success = true;
    }

    /**
     */
    public function deleteAction(): void
    {
        $table     = $this->request->get('table');
        $className = $this->getClassName($table);
        if(empty($className)) {
            $this->view->success = false;
            return;
        }
        $id     = $this->request->get('id');
        $record = $className::findFirstById($id);
        if ($record !== null && ! $record->delete()) {
            $this->flash->error(implode('<br>', $record->getMessages()));
            $this->view->success = false;
            return;
        }
        $this->view->success = true;
    }

    /**
     * Возвращает метаданные таблицы.
     * @return array
     */
    private function getTablesDescription():array
    {
        return [];
    }

    /**
     * Обновление данных в таблице.
     */
    public function saveTableDataAction():void
    {
        $data       = $this->request->getPost();
        $tableName  = $data['pbx-table-id']??'';

        $className = $this->getClassName($tableName);
        if(empty($className)){
            return;
        }
        $rowId      = $data['pbx-row-id']??'';
        if(empty($rowId)){
            $this->view->success = false;
            return;
        }
        $this->db->begin();
        $rowData = $className::findFirst('id="'.$rowId.'"');
        if(!$rowData){
            $rowData = new $className();
        }
        foreach ($rowData as $key => $value) {
            if($key === 'id'){
                continue;
            }
            if (array_key_exists($key, $data)) {
                $rowData->writeAttribute($key, $data[$key]);
            }
        }
        // save action
        if ($rowData->save() === FALSE) {
            $errors = $rowData->getMessages();
            $this->flash->error(implode('<br>', $errors));
            $this->view->success = false;
            $this->db->rollback();
            return;
        }
        $this->view->data = ['pbx-row-id'=>$rowId, 'newId'=>$rowData->id, 'pbx-table-id' => $data['pbx-table-id']];
        $this->view->success = true;
        $this->db->commit();

    }

    /**
     * Получение имени класса по имени таблицы
     * @param $tableName
     * @return string
     */
    private function getClassName($tableName):string
    {
        if(empty($tableName)){
            return '';
        }
        $className = "Modules\ModuleMtsPbx\Models\\$tableName";
        if(!class_exists($className)){
            $className = '';
        }
        return $className;
    }

    /**
     * Changes rules priority
     *
     */
    public function changePriorityAction(): void
    {
        $this->view->disable();
        $result = true;

        if ( ! $this->request->isPost()) {
            return;
        }
        $priorityTable = $this->request->getPost();
        $tableName     = $this->request->get('table');
        $className = $this->getClassName($tableName);
        if(empty($className)){
            echo "table not found -- ы$tableName --";
            return;
        }
        $rules = $className::find();
        foreach ($rules as $rule){
            if (array_key_exists ( $rule->id, $priorityTable)){
                $rule->priority = $priorityTable[$rule->id];
                $result         .= $rule->update();
            }
        }
        echo json_encode($result);
    }
}
