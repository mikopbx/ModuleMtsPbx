<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 6 2018
 *
 */

return [
	'repModuleMtsPbx'         => 'Интеграция с MTS Автосекретарь 2.0 - %repesent%',
	'mo_ModuleModuleMtsPbx'   => 'Интеграция с MTS Автосекретарь 2.0',
    'BreadcrumbModuleMtsPbx'  => 'Интеграция с MTS Автосекретарь 2.0',
    'SubHeaderModuleMtsPbx'   => 'Синхронизация истории звонков',
    'module_template_AddNewRecord'  => 'Добавить',
    'module_mts_pbx_authApiKey'  => 'API Ключ',
    'module_mts_pbx_inLogin'  => 'Логин REST',
    'module_mts_inPassword'  => 'Пароль REST',
    'module_mts_pbx_gap'     => 'Смещение времени, часов',
    'module_mts_pbx_reset_offset' => 'Начать загрузку с начала',
    'module_mts_pbx_reset_offset_confirm' => 'Сбросить позицию синхронизации и повторно загрузить историю за последние 30 дней?',
    'module_mts_pbx_reset_offset_success' => 'Позиция синхронизации сброшена. Загрузка начнётся заново.',
    'module_mts_pbx_reset_offset_error' => 'Не удалось сбросить позицию синхронизации.',
    'module_mts_pbx_accounts_header' => 'MTS Автосекретарь',
    'module_mts_pbx_account_name' => 'Название',
    'module_mts_pbx_account_enabled' => 'Синхронизация включена',
    'module_mts_pbx_account_downloadRecords' => 'Скачивать записи разговоров',
    'module_mts_pbx_account_gap_short' => 'Смещение, ч',
    'module_mts_pbx_account_records_hint' => 'Скачивать записи разговоров из MTS. Если выключено, синхронизируется только история звонков, записи не скачиваются.',
    'module_mts_pbx_account_col_records' => 'Записи',
    'module_mts_pbx_account_edit_key' => 'Изменить API ключ',
    'module_mts_pbx_account_domain' => 'Домен',
    'module_mts_pbx_account_offset' => 'Позиция синхронизации',
    'module_mts_pbx_account_id_format' => 'Формат ID звонка',
    'module_mts_pbx_account_add' => 'Добавить',
    'module_mts_pbx_account_delete' => 'Удалить',
    'module_mts_pbx_account_delete_confirm' => 'Удалить? Синхронизация прекратится после сохранения настроек. Загруженная история звонков сохранится.',
    'module_mts_pbx_account_error_emptyKey' => '«%name%»: не указан API ключ',
    'module_mts_pbx_account_error_invalidKey' => '«%name%»: не удалось определить домен из API ключа',
    'module_mts_pbx_account_error_duplicateDomain' => '«%name%»: такой домен уже добавлен',
    'module_mts_pbx_cdrAuthEnabled' => 'Требовать авторизацию',
    'module_mts_pbx_cdrAuthEnabled_hint' => 'События MTS всегда принимаются с «Логином REST» и «Паролем REST». «Требовать авторизацию» включает их проверку и для выдачи истории звонков в 1С (/pbxcore/mts-pbx/cdr) — в обработке 1С укажите адрес АТС в виде логин:пароль@адрес.',
    'module_mts_pbxTextFieldLabel'        => 'Пример текстового поля',
    'module_mts_pbxTextAreaFieldLabel'    => 'Пример многостраничного поля',
    'module_mts_pbxPasswordFieldLabel'    => 'Пример поля с паролем',
    'module_mts_pbxIntegerFieldLabel'     => 'Пример числового поля',
    'module_mts_pbxCheckBoxFieldLabel'    => 'Чекбокс',
    'module_mts_pbxToggleFieldLabel'      => 'Переключатель',
    'module_mts_pbxDropDownFieldLabel'    => 'Выпадающее меню',
    'module_mts_pbxValidateValueIsEmpty'  => 'Проверьте поле, оно не заполнено',
    'module_mts_pbxConnected'             => 'Модуль подключен',
    'module_mts_pbxDisconnected'          => 'Модуль отключен',
    'module_mts_pbxUpdateStatus'          => 'Обновление статуса',
];
