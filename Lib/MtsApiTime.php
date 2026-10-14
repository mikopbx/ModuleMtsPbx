<?php

namespace Modules\ModuleMtsPbx\Lib;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class MtsApiTime
{
    private const TIMEZONE = 'Europe/Moscow';

    public static function now(?DateTimeInterface $instant = null): DateTimeImmutable
    {
        $date = $instant === null
            ? new DateTimeImmutable('now', self::timezone())
            : DateTimeImmutable::createFromInterface($instant);

        return $date->setTimezone(self::timezone());
    }

    public static function fromApiValue(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::timezone());
    }

    private static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }
}
