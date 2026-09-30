<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cryptographically strong random bytes, with a graceful fallback for hosts
 * where random_bytes() throws (no usable entropy source).
 */
class Random
{
    /**
     * @param int $length number of raw bytes
     *
     * @return string binary string of exactly $length bytes
     */
    public static function bytes($length)
    {
        $length = max(1, (int) $length);

        try {
            return random_bytes($length);
        } catch (\Exception $e) {
            // fall through to the weaker fallback below
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $result = openssl_random_pseudo_bytes($length, $strong);
            if ($result !== false && strlen($result) === $length) {
                return $result;
            }
        }

        $out = '';
        while (strlen($out) < $length) {
            $out .= hash('sha256', uniqid('ovebotai', true) . microtime(true) . mt_rand(), true);
        }

        return substr($out, 0, $length);
    }

    /**
     * Lower-case hex token of 2 * $bytes characters (16 bytes -> 32 hex chars).
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function hex($bytes)
    {
        return bin2hex(self::bytes($bytes));
    }
}
