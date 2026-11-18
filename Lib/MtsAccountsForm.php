<?php

namespace Modules\ModuleMtsPbx\Lib;

/**
 * Нормализация и проверка списка порталов, присланного формой настроек.
 */
final class MtsAccountsForm
{
    public const ERROR_EMPTY_KEY        = 'emptyKey';
    public const ERROR_INVALID_KEY      = 'invalidKey';
    public const ERROR_DUPLICATE_DOMAIN = 'duplicateDomain';

    /**
     * @param mixed $posted массив вида [<id|newN> => ['name'=>..., 'authApiKey'=>..., 'gap'=>..., 'enabled'=>..., 'downloadRecords'=>...]]
     *
     * @return array{rows: array<int, array{key:string, name:string, authApiKey:string, domain:string, gap:int, enabled:int, downloadRecords:int}>,
     *               errors: array<int, array{code:string, name:string}>}
     */
    public static function normalize($posted): array
    {
        $rows    = [];
        $errors  = [];
        $domains = [];
        if (!is_array($posted)) {
            return ['rows' => $rows, 'errors' => $errors];
        }

        $index = 0;
        foreach ($posted as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $index++;
            $key        = (string)$key;
            $name       = trim((string)($row['name'] ?? ''));
            $authApiKey = trim((string)($row['authApiKey'] ?? ''));
            $isNew      = !ctype_digit($key);

            if ($isNew && $name === '' && $authApiKey === '') {
                // Пустой добавленный и не заполненный блок — просто игнорируем.
                continue;
            }
            $title = $name !== '' ? $name : '#' . $index;
            if ($authApiKey === '') {
                $errors[] = ['code' => self::ERROR_EMPTY_KEY, 'name' => $title];
                continue;
            }
            $domain = MtsAccountKey::parseDomain($authApiKey);
            if ($domain === '') {
                $errors[] = ['code' => self::ERROR_INVALID_KEY, 'name' => $title];
                continue;
            }
            $normalized = MtsAccountKey::normalizeDomain($domain);
            if (isset($domains[$normalized])) {
                $errors[] = ['code' => self::ERROR_DUPLICATE_DOMAIN, 'name' => $title];
                continue;
            }
            $domains[$normalized] = true;

            $rows[] = [
                'key'        => $key,
                'name'       => $name !== '' ? $name : $domain,
                'authApiKey' => $authApiKey,
                'domain'     => $domain,
                'gap'        => ModuleSettings::normalizeGap($row['gap'] ?? ''),
                'enabled'    => self::isChecked($row['enabled'] ?? '') ? 1 : 0,
                // Поле не прислано (старый JS в кеше браузера) — скачивание остаётся включённым.
                'downloadRecords' => array_key_exists('downloadRecords', $row) && !self::isChecked($row['downloadRecords']) ? 0 : 1,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    public static function isChecked($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'on', 'true', 'yes'], true);
    }
}
