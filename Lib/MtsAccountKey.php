<?php

namespace Modules\ModuleMtsPbx\Lib;

/**
 * Разбор API-ключа (JWT) MTS Автосекретарь.
 */
final class MtsAccountKey
{
    /**
     * Возвращает домен портала из payload JWT (поле `id` вида `user@domain`) или '' при ошибке.
     */
    public static function parseDomain(string $authApiKey): string
    {
        try {
            $parts = explode('.', trim($authApiKey));
            if (count($parts) < 2) {
                return '';
            }
            $payload = strtr($parts[1], '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $data = json_decode((string)base64_decode($payload), true);
            if (!is_array($data) || !is_string($data['id'] ?? null)) {
                return '';
            }
            $at = strrpos($data['id'], '@');
            if ($at === false) {
                return '';
            }
            return trim(substr($data['id'], $at + 1));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Сокращённое представление ключа для интерфейса: `eyJhbGciOi…gk1Y`.
     */
    public static function shorten(string $authApiKey, int $head = 10, int $tail = 6): string
    {
        $authApiKey = trim($authApiKey);
        if (mb_strlen($authApiKey) <= $head + $tail + 1) {
            return $authApiKey;
        }
        return mb_substr($authApiKey, 0, $head) . '…' . mb_substr($authApiKey, -$tail);
    }

    /**
     * Нормализованный домен для сравнения (событие MTS присылает `Domain` в своём регистре).
     */
    public static function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
