<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * JSON output for the module's front controllers (feed / orders).
 * Status + headers + body; no exit: the controller returns normally.
 */
class JsonResponder
{
    const JSON_FLAGS = 320; // JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES

    /**
     * @param int $status
     * @param array $payload
     * @param string[] $headers extra headers, e.g. WWW-Authenticate
     */
    public static function send($status, array $payload, array $headers = [])
    {
        self::head($status, $headers);
        echo json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * Streams a JSON array item by item so a large catalog never sits in
     * memory as one big string.
     *
     * @param iterable $items
     */
    public static function streamArray($items)
    {
        self::head(200, []);

        echo '[';
        $first = true;
        $count = 0;
        foreach ($items as $item) {
            echo ($first ? '' : ',') . json_encode($item, self::JSON_FLAGS);
            $first = false;
            if (++$count % 200 === 0) {
                self::flush();
            }
        }
        echo ']';
        self::flush();
    }

    /**
     * @param int $status
     * @param string[] $headers
     */
    private static function head($status, array $headers)
    {
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow');
            foreach ($headers as $header) {
                header($header);
            }
        }
    }

    private static function flush()
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
