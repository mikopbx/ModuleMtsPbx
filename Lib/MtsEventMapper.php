<?php

namespace Modules\ModuleMtsPbx\Lib;

/**
 * Разбор событий MTS, приходящих на POST /pbxcore/api/mts-pbx/v1/event.
 */
final class MtsEventMapper
{
    private const STATES = [
        'CallAccepted' => 'Calling',
        'CallAnswered' => 'Connected',
        'EndCall'      => 'Finished',
    ];

    /**
     * Декодирует тело события. null — тело не JSON-объект или в нём нет EventType.
     */
    public static function decode(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !is_string($data['EventType'] ?? null)) {
            return null;
        }
        return $data;
    }

    public static function state(string $eventType): ?string
    {
        return self::STATES[$eventType] ?? null;
    }

    /**
     * Ищет портал по полю Domain события (без учёта регистра).
     *
     * @param array $accounts список порталов: [['id' => int, 'domain' => string, 'legacyIdFormat' => int|string], ...]
     */
    public static function findAccount(array $accounts, string $domain): ?array
    {
        $needle = MtsAccountKey::normalizeDomain($domain);
        if ($needle === '') {
            return null;
        }
        foreach ($accounts as $account) {
            if (MtsAccountKey::normalizeDomain((string)($account['domain'] ?? '')) === $needle) {
                return $account;
            }
        }
        return null;
    }

    /**
     * call_id для 1С. Для неизвестного портала и портала со старым форматом — исходный CallID.
     */
    public static function callId(array $data, ?array $account): string
    {
        $callId = (string)($data['CallID'] ?? '');
        if ($account === null || $callId === '') {
            return $callId;
        }
        return MtsCallId::eventCallId(
            (int)$account['id'],
            (string)($account['legacyIdFormat'] ?? '0') === '1',
            $callId
        );
    }
}
