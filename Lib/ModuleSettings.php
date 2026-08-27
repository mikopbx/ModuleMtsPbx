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
}
