<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 6 2018
 *
 */

return [
	'repModuleMtsPbx'       => 'Module template - %repesent%',
	'mo_ModuleModuleMtsPbx' => 'Module template',
    'BreadcrumbModuleMtsPbx'=> 'Template module',
    'SubHeaderModuleMtsPbx' => 'Example to create own modules',
    'module_template_AddNewRecord'  => 'Add new',
    'module_mts_pbx_authApiKey' => 'API key',
    'module_mts_pbx_inLogin' => 'REST login',
    'module_mts_inPassword' => 'REST password',
    'module_mts_pbx_gap' => 'Time offset, hours',
    'module_mts_pbx_reset_offset' => 'Start loading from the beginning',
    'module_mts_pbx_reset_offset_confirm' => 'Reset the synchronization position and reload call history for the last 30 days?',
    'module_mts_pbx_reset_offset_success' => 'The synchronization position was reset. Loading will restart.',
    'module_mts_pbx_reset_offset_error' => 'Failed to reset the synchronization position.',
    'module_mts_pbx_accounts_header' => 'MTS Autosecretary',
    'module_mts_pbx_account_name' => 'Name',
    'module_mts_pbx_account_enabled' => 'Synchronization enabled',
    'module_mts_pbx_account_downloadRecords' => 'Download call recordings',
    'module_mts_pbx_account_gap_short' => 'Offset, h',
    'module_mts_pbx_account_records_hint' => 'Download call recordings from MTS. When off, only call history is synchronized and recordings are not downloaded.',
    'module_mts_pbx_account_col_records' => 'Recordings',
    'module_mts_pbx_account_edit_key' => 'Edit API key',
    'module_mts_pbx_account_domain' => 'Domain',
    'module_mts_pbx_account_offset' => 'Synchronization position',
    'module_mts_pbx_account_id_format' => 'Call ID format',
    'module_mts_pbx_account_add' => 'Add',
    'module_mts_pbx_account_delete' => 'Delete',
    'module_mts_pbx_account_delete_confirm' => 'Delete? Synchronization stops after the settings are saved. Loaded call history is kept.',
    'module_mts_pbx_account_error_emptyKey' => '"%name%": API key is empty',
    'module_mts_pbx_account_error_invalidKey' => '"%name%": cannot get the domain from the API key',
    'module_mts_pbx_account_error_duplicateDomain' => '"%name%": this domain is already added',
    'module_mts_pbx_cdrAuthEnabled' => 'Require authorization',
    'module_mts_pbx_cdrAuthEnabled_hint' => 'MTS events are always accepted with "REST login" and "REST password". "Require authorization" also checks them for the call history export to 1C (/pbxcore/mts-pbx/cdr); in the 1C data processor set the PBX address as login:password@host.',
    'module_mts_pbxTextFieldLabel'        => 'Text field example',
    'module_mts_pbxTextAreaFieldLabel'    => 'TextArea field example',
    'module_mts_pbxPasswordFieldLabel'    => 'Password field example',
    'module_mts_pbxIntegerFieldLabel'     => 'Integer field example',
    'module_mts_pbxCheckBoxFieldLabel'    => 'CheckBox',
    'module_mts_pbxToggleFieldLabel'      => 'Toggle',
    'module_mts_pbxDropDownFieldLabel'    => 'Dropdown menu',
    'module_mts_pbxValidateValueIsEmpty'  => 'Check the field, it looks like empty',
    'module_mts_pbxConnected'             => 'Module connected',
    'module_mts_pbxDisconnected'          => 'Module disconnected',
    'module_mts_pbxUpdateStatus'          => 'Update module status',
];
