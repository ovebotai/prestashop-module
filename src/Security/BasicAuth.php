<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Security;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * HTTP Basic credentials check for the order-lookup endpoint.
 *
 * $_SERVER is the only place the Authorization header exists; there is no
 * PrestaShop helper for it. Reads PHP_AUTH_* first, then falls back to the raw
 * header (CGI/FastCGI hosts don't populate PHP_AUTH_*; on Apache the header
 * may need `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
 */
class BasicAuth
{
    /**
     * @param string $expectedUser
     * @param string $expectedPass
     *
     * @return bool
     */
    public static function matches($expectedUser, $expectedPass)
    {
        $expectedUser = trim((string) $expectedUser);
        $expectedPass = trim((string) $expectedPass);
        if ($expectedUser === '' || $expectedPass === '') {
            return false;
        }

        $given = self::credentials();
        if ($given === null) {
            return false;
        }

        return hash_equals($expectedUser, $given[0]) && hash_equals($expectedPass, $given[1]);
    }

    /**
     * @return array|null [user, pass]
     */
    public static function credentials()
    {
        $user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
        $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

        if ($user !== '') {
            return [$user, $pass];
        }

        $header = '';
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
                $header = $_SERVER[$key];
                break;
            }
        }
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower((string) $name) === 'authorization' && is_string($value)) {
                        $header = $value;
                        break;
                    }
                }
            }
        }

        if (stripos($header, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return null;
        }

        list($user, $pass) = explode(':', $decoded, 2);

        return $user !== '' ? [$user, $pass] : null;
    }
}
