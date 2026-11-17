<?php

namespace Modules\ModuleMtsPbx\Lib;

/**
 * Формирование идентификаторов звонков MTS с учётом портала.
 *
 * Портал, перенесённый из однопортальной версии модуля (legacyIdFormat = 1), сохраняет
 * старый формат `fs-mts-<callId>`: иначе уже загруженная история задвоится в mts_cdr и 1С.
 * Остальные порталы получают `fs-mts-<accountId>-<callId>`.
 */
final class MtsCallId
{
    public const PREFIX = 'fs-mts-';

    public static function build(int $accountId, bool $legacy, string $callId): string
    {
        return self::PREFIX . self::suffix($accountId, $legacy, $callId);
    }

    /**
     * ID звонка для событий реального времени (в 1С уходит без префикса fs-mts-).
     */
    public static function eventCallId(int $accountId, bool $legacy, string $callId): string
    {
        return self::suffix($accountId, $legacy, $callId);
    }

    /**
     * Имя файла записи (без расширения) по linkedid: `<callId>` или `<accountId>-<callId>`.
     */
    public static function fileBase(string $linkedId): string
    {
        if (strpos($linkedId, self::PREFIX) !== 0) {
            return '';
        }
        return substr($linkedId, strlen(self::PREFIX));
    }

    /**
     * Исходный callId MTS из linkedid старого формата `fs-mts-<callId>`.
     * Используется для строк mts_cdr, загруженных до появления колонки mts_call_id.
     */
    public static function callIdFromLegacyLinkedId(string $linkedId): string
    {
        return self::fileBase($linkedId);
    }

    private static function suffix(int $accountId, bool $legacy, string $callId): string
    {
        return $legacy ? $callId : $accountId . '-' . $callId;
    }
}
