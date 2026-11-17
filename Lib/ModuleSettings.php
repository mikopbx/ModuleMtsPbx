<?php

namespace Modules\ModuleMtsPbx\Lib;

final class ModuleSettings
{
    public static function normalizeGap($value): int
    {
        try {
            if ($value === null || $value === '' || !is_numeric($value)) {
                return 0;
            }

            return (int)$value;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function resetOffset($settings): bool
    {
        try {
            $settings->offset = '';
            return $settings->save() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Значения по умолчанию для новой установки модуля.
     * Авторизация на выдаче CDR (/pbxcore/mts-pbx/cdr) для новых установок включена.
     */
    public static function initNewSettings($settings): void
    {
        if (empty($settings->inLogin)) {
            $settings->inLogin = bin2hex(random_bytes(16));
        }
        if (empty($settings->inPassword)) {
            $settings->inPassword = bin2hex(random_bytes(16));
        }
        $settings->cdrAuthEnabled   = '1';
        $settings->accountsMigrated = '1';
    }

    /**
     * Значения по умолчанию для установки, обновлённой с предыдущей версии.
     * Колонка cdrAuthEnabled добавляется без default, поэтому у существующей строки она NULL —
     * для таких установок авторизацию CDR выключаем, чтобы не сломать работающую обработку 1С.
     *
     * @return bool были ли изменения
     */
    public static function applyUpgradeDefaults($settings): bool
    {
        if ($settings->cdrAuthEnabled === null || $settings->cdrAuthEnabled === '') {
            $settings->cdrAuthEnabled = '0';
            return true;
        }
        return false;
    }

    /**
     * Данные портала, переносимого из однопортальных настроек, или null, если переносить нечего.
     */
    public static function legacyAccountData($settings): ?array
    {
        if (!empty($settings->accountsMigrated)) {
            return null;
        }
        $authApiKey = trim((string)($settings->authApiKey ?? ''));
        if ($authApiKey === '') {
            return null;
        }
        $domain = MtsAccountKey::parseDomain($authApiKey);

        return [
            'name'           => $domain !== '' ? $domain : 'MTS',
            'authApiKey'     => $authApiKey,
            'domain'         => $domain,
            'gap'            => (string)self::normalizeGap($settings->gap ?? ''),
            'offset'         => (string)($settings->offset ?? ''),
            'enabled'        => '1',
            'legacyIdFormat' => '1',
        ];
    }

    public static function isCdrAuthEnabled($value): bool
    {
        return (string)$value === '1';
    }
}
