
<form class="ui large grey segment form" id="module-mts-pbx-form">
    {{ form.render('id') }}
    <div class="ten wide field disability">
        <label >{{ t._('module_mts_pbx_authApiKey') }}</label>
        {{ form.render('authApiKey') }}
    </div>

    <div class="ten wide field disability">
        <label >{{ t._('module_mts_pbx_inLogin') }}</label>
        {{ form.render('inLogin') }}
    </div>
    <div class="ten wide field disability">
        <label >{{ t._('module_mts_inPassword') }}</label>
        {{ form.render('inPassword') }}
    </div>
    <div class="ten wide field disability">
        <label>{{ t._('module_mts_pbx_gap') }}</label>
        {{ form.render('gap') }}
    </div>
    <div class="field">
        <button type="button" class="ui orange button" id="reset-offset-button">
            <i class="history icon"></i>
            {{ t._('module_mts_pbx_reset_offset') }}
        </button>
    </div>
    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>
