<?php

declare(strict_types=1);

use Modules\ModuleMtsPbx\Lib\ModuleSettings;

require_once dirname(__DIR__) . '/Lib/ModuleSettings.php';

final class ModuleSettingsTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $cases = [
            ['3', 3],
            ['-4', -4],
            ['0', 0],
            ['', 0],
            ['not-a-number', 0],
            [null, 0],
            [new stdClass(), 0],
        ];

        foreach ($cases as [$value, $expected]) {
            $this->assertSame($expected, ModuleSettings::normalizeGap($value));
        }

        $settings = new class {
            public string $offset = '2026-08-27T12:00:00';
            public bool $saved = false;

            public function save(): bool
            {
                $this->saved = true;
                return true;
            }
        };

        $this->assertSame(true, ModuleSettings::resetOffset($settings));
        $this->assertSame('', $settings->offset);
        $this->assertSame(true, $settings->saved);

        fwrite(STDOUT, "OK ({$this->assertions} assertions)\n");
    }

    private function assertSame($expected, $actual): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
            );
        }
    }
}

(new ModuleSettingsTest())->run();
