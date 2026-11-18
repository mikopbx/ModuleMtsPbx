
<form class="ui large grey segment form" id="module-mts-pbx-form">
    {{ form.render('id') }}

    <table class="ui very basic very compact table" id="mts-accounts-table">
        <thead>
            <tr>
                <th class="collapsing"></th>
                <th>{{ t._('module_mts_pbx_account_name') }}</th>
                <th class="collapsing">{{ t._('module_mts_pbx_account_gap_short') }}</th>
                <th class="collapsing">{{ t._('module_mts_pbx_authApiKey') }}</th>
                <th class="center aligned collapsing">
                    {{ t._('module_mts_pbx_account_col_records') }}
                    <i class="small grey info circle icon popuped" data-content="{{ t._('module_mts_pbx_account_records_hint') }}"></i>
                </th>
                <th class="collapsing"></th>
            </tr>
        </thead>
        <tbody id="mts-accounts">
        {% for account in accounts %}
            <tr class="mts-account" data-id="{{ account['id'] }}">
                <td class="center aligned">
                    <div class="ui fitted toggle checkbox popuped" data-position="top left" data-content="{{ t._('module_mts_pbx_account_enabled') }}">
                        <input type="checkbox" data-field="enabled" {% if account['enabled'] %}checked{% endif %}><label></label>
                    </div>
                </td>
                <td>
                    <div class="ui mini fluid input"><input type="text" data-field="name" value="{{ account['name']|e }}"></div>
                </td>
                <td>
                    <div class="ui mini fluid input"><input type="number" step="1" data-field="gap" value="{{ account['gap']|e }}"></div>
                </td>
                <td class="mts-key-cell">
                    <div class="mts-key-view">
                        <code class="mts-key-short">{{ account['authApiKeyShort']|e }}</code>
                        <button type="button" class="ui mini basic icon button edit-key-button popuped" data-content="{{ t._('module_mts_pbx_account_edit_key') }}">
                            <i class="pencil icon"></i>
                        </button>
                    </div>
                </td>
                <td class="center aligned">
                    <div class="ui fitted toggle checkbox" title="{{ t._('module_mts_pbx_account_downloadRecords') }}">
                        <input type="checkbox" data-field="downloadRecords" {% if account['downloadRecords'] %}checked{% endif %}><label></label>
                    </div>
                </td>
                <td class="right aligned">
                    <div class="ui mini basic icon buttons">
                        <button type="button" class="ui button reset-offset-button popuped" data-title="{{ t._('module_mts_pbx_reset_offset') }}" data-content="{{ t._('module_mts_pbx_account_offset') }}: {% if account['offsetView'] is empty %}—{% else %}{{ account['offsetView']|e }}{% endif %}"><i class="orange history icon"></i></button>
                        <button type="button" class="ui button delete-account-button popuped" data-content="{{ t._('module_mts_pbx_account_delete') }}"><i class="red trash icon"></i></button>
                    </div>
                </td>
            </tr>
            <tr class="mts-key-row" style="display: none;">
                <td colspan="6">
                    <label>{{ t._('module_mts_pbx_authApiKey') }}</label>
                    <textarea rows="2" data-field="authApiKey">{{ account['authApiKey']|e }}</textarea>
                </td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
    <template id="mts-account-template">
        <tr class="mts-account" data-id="">
            <td class="center aligned">
                <div class="ui fitted toggle checkbox popuped" data-position="top left" data-content="{{ t._('module_mts_pbx_account_enabled') }}">
                    <input type="checkbox" data-field="enabled" checked><label></label>
                </div>
            </td>
            <td>
                <div class="ui mini fluid input"><input type="text" data-field="name" value=""></div>
            </td>
            <td>
                <div class="ui mini fluid input"><input type="number" step="1" data-field="gap" value="0"></div>
            </td>
            <td class="mts-key-cell">—</td>
            <td class="center aligned">
                <div class="ui fitted toggle checkbox" title="{{ t._('module_mts_pbx_account_downloadRecords') }}">
                    <input type="checkbox" data-field="downloadRecords" checked><label></label>
                </div>
            </td>
            <td class="right aligned">
                <div class="ui mini basic icon buttons">
                    <button type="button" class="ui button delete-account-button popuped" data-content="{{ t._('module_mts_pbx_account_delete') }}"><i class="red trash icon"></i></button>
                </div>
            </td>
        </tr>
        <tr class="mts-key-row">
            <td colspan="6">
                <label>{{ t._('module_mts_pbx_authApiKey') }}</label>
                <textarea rows="2" data-field="authApiKey"></textarea>
            </td>
        </tr>
    </template>
    <div class="field">
        <button type="button" class="ui small basic button" id="add-account-button">
            <i class="plus icon"></i>
            {{ t._('module_mts_pbx_account_add') }}
        </button>
    </div>

    <div class="fields">
        <div class="six wide field disability">
            <label>{{ t._('module_mts_pbx_inLogin') }}</label>
            {{ form.render('inLogin') }}
        </div>
        <div class="six wide field disability">
            <label>{{ t._('module_mts_inPassword') }}</label>
            {{ form.render('inPassword') }}
        </div>
        <div class="four wide field mts-toggle-field mts-nowrap">
            <div class="mts-toggle-wrap">
                <div class="ui toggle checkbox" id="cdr-auth-enabled">
                    <input type="checkbox" name="cdrAuthEnabled" {% if cdrAuthEnabled %}checked{% endif %}>
                    <label>{{ t._('module_mts_pbx_cdrAuthEnabled') }}</label>
                </div>
            </div>
        </div>
    </div>
    <div class="ui small message">{{ t._('module_mts_pbx_cdrAuthEnabled_hint') }}</div>
    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>
