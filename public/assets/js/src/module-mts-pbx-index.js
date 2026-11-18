/*
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 11 2018
 *
 */
const idUrl     = 'module-mts-pbx';
const idForm    = 'module-mts-pbx-form';
const className = 'ModuleMtsPbx';

/* global globalRootUrl, globalTranslate, Form, Config, UserMessage */
const ModuleMtsPbx = {
	$formObj: $('#'+idForm),
	$accounts: $('#mts-accounts'),
	$moduleStatus: $('#status'),
	newAccountCounter: 0,
	validateRules: {},

	/**
	 * On page load we init some Semantic UI library
	 */
	initialize() {
		window[className].$formObj.find('.ui.checkbox').checkbox();
		window[className].initializeForm();
		$('#add-account-button').on('click', window[className].addAccount);
		window[className].$accounts.on('click', '.reset-offset-button', window[className].resetOffset);
		window[className].$accounts.on('click', '.delete-account-button', window[className].deleteAccount);
		window[className].$accounts.on('click', '.edit-key-button', window[className].editKey);
		window[className].initPopups($('#mts-accounts-table'));
	},

	/**
	 * Добавляет пустой блок портала из шаблона.
	 */
	addAccount() {
		window[className].newAccountCounter += 1;
		const template = document.getElementById('mts-account-template');
		// $.parseHTML: строка шаблона начинается с переноса строки, и $(html) приняла бы её за селектор.
		// Строка портала и строка поля API ключа под ней.
		const $rows = $($.parseHTML(template.innerHTML.trim())).filter('tr');
		const $account = $rows.filter('.mts-account');
		$account.attr('data-id', `new${window[className].newAccountCounter}`);
		window[className].$accounts.append($rows);
		$rows.find('.ui.checkbox').checkbox();
		window[className].initPopups($rows);
		$account.find('[data-field="name"]').focus();
	},

	/**
	 * Подсказки к кнопкам действий таблицы порталов.
	 */
	initPopups($container) {
		$container.find('.popuped').popup({
			position: 'top center',
			variation: 'mini',
		});
	},

	/**
	 * Показывает поле ввода API ключа вместо его сокращённого представления.
	 */
	editKey(e) {
		const $keyRow = $(e.target).closest('.mts-account').next('.mts-key-row');
		$keyRow.toggle();
		if ($keyRow.is(':visible')) {
			$keyRow.find('textarea').focus();
		}
	},

	/**
	 * Удаляет блок портала из формы. Портал удаляется из базы при сохранении формы.
	 */
	deleteAccount(e) {
		const $account = $(e.target).closest('.mts-account');
		const id = String($account.attr('data-id'));
		if (id.indexOf('new') !== 0 && !window.confirm(globalTranslate.module_mts_pbx_account_delete_confirm)) {
			return;
		}
		$account.find('.popuped').popup('destroy');
		$account.next('.mts-key-row').remove();
		$account.remove();
	},

	resetOffset(e) {
		const id = $(e.target).closest('.mts-account').attr('data-id');
		if (!window.confirm(globalTranslate.module_mts_pbx_reset_offset_confirm)) {
			return;
		}
		$.api({
			on: 'now',
			url: `${globalRootUrl}${idUrl}/resetOffset`,
			method: 'POST',
			data: { id },
			successTest(response) {
				return response !== undefined && response.success === true;
			},
			onSuccess() {
				UserMessage.showInformation(globalTranslate.module_mts_pbx_reset_offset_success);
				window[className].applyConfigurationChanges();
			},
			onFailure() {
				UserMessage.showError(globalTranslate.module_mts_pbx_reset_offset_error);
			},
		});
	},

	/**
	 * Собирает порталы в поля вида accounts[<id|newN>][<field>].
	 */
	collectAccounts() {
		const data = {};
		window[className].$accounts.find('.mts-account').each((index, obj) => {
			const $account = $(obj);
			const id = $account.attr('data-id');
			// Поле API ключа находится в отдельной строке таблицы сразу под строкой портала.
			$account.find('[data-field]').add($account.next('.mts-key-row').find('[data-field]')).each((i, field) => {
				const $field = $(field);
				const name = $field.attr('data-field');
				const value = $field.is(':checkbox') ? ($field.is(':checked') ? '1' : '0') : $field.val();
				data[`accounts[${id}][${name}]`] = value;
			});
		});
		return data;
	},

	/**
	 * Send command to restart module workers after data changes,
	 * Also we can do it on TemplateConf->modelsEventChangeData method
	 */
	applyConfigurationChanges() {
		window[className].changeStatus('Updating');
		$.api({
			url: `${Config.pbxUrl}/pbxcore/api/modules/`+className+`/reload`,
			on: 'now',
			successTest(response) {
				// test whether a JSON response is valid
				return Object.keys(response).length > 0 && response.result === true;
			},
			onSuccess() {
				window[className].changeStatus('Connected');
			},
			onFailure() {
				window[className].changeStatus('Disconnected');
			},
		});
	},
	/**
	 * We can modify some data before form send
	 * @param settings
	 * @returns {*}
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		const $form = window[className].$formObj;
		result.data = $.extend({
			id: $form.find('[name="id"]').val(),
			inLogin: $form.find('[name="inLogin"]').val(),
			inPassword: $form.find('[name="inPassword"]').val(),
			cdrAuthEnabled: $form.find('[name="cdrAuthEnabled"]').is(':checked') ? '1' : '0',
		}, window[className].collectAccounts());
		return result;
	},
	/**
	 * Some actions after forms send
	 */
	cbAfterSendForm(response) {
		// Новые порталы получают ID в базе: обновляем ключи блоков, чтобы повторное
		// сохранение изменило их, а не создало ещё раз.
		if (response !== undefined && response.accountIds !== undefined) {
			$.each(response.accountIds, (key, id) => {
				window[className].$accounts.find(`.mts-account[data-id="${key}"]`).attr('data-id', id);
			});
		}
		window[className].applyConfigurationChanges();
	},
	/**
	 * Initialize form parameters
	 */
	initializeForm() {
		Form.$formObj = window[className].$formObj;
		Form.url = `${globalRootUrl}${idUrl}/save`;
		Form.validateRules = window[className].validateRules;
		Form.cbBeforeSendForm = window[className].cbBeforeSendForm;
		Form.cbAfterSendForm = window[className].cbAfterSendForm;
		// Блоки порталов не входят в 'get values' формы, поэтому отслеживание изменений
		// не увидит добавление/удаление портала — кнопка сохранения всегда активна.
		Form.enableDirrity = false;
		Form.initialize();
	},
	/**
	 * Update the module state on form label
	 * @param status
	 */
	changeStatus(status) {
		switch (status) {
			case 'Connected':
				window[className].$moduleStatus
					.removeClass('grey')
					.removeClass('red')
					.addClass('green');
				window[className].$moduleStatus.html(globalTranslate.module_mts_pbxConnected);
				break;
			case 'Updating':
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(`<i class="spinner loading icon"></i>${globalTranslate.module_mts_pbxUpdateStatus}`);
				break;
			default:
				window[className].$moduleStatus
					.removeClass('green')
					.removeClass('red')
					.addClass('grey');
				window[className].$moduleStatus.html(globalTranslate.module_mts_pbxDisconnected);
				break;
		}
	},
};

$(document).ready(() => {
	window[className].initialize();
});
