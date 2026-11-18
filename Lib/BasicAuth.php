<?php

namespace Modules\ModuleMtsPbx\Lib;

/**
 * Проверка заголовка HTTP Basic Auth.
 */
final class BasicAuth
{
    public static function check($authHeader, string $validUsername, string $validPassword): bool
    {
        if ($validUsername === '' || $validPassword === '') {
            return false;
        }
        if (!is_string($authHeader) || !preg_match('/^Basic\s+(\S+)\s*$/i', $authHeader, $matches)) {
            return false;
        }
        $credentials = base64_decode($matches[1], true);
        if ($credentials === false || strpos($credentials, ':') === false) {
            return false;
        }
        [$username, $password] = explode(':', $credentials, 2);

        return hash_equals($validUsername, $username) && hash_equals($validPassword, $password);
    }
}
