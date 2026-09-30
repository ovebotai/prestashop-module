<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Config;

use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sanitisation + defaults for the OVEBOTAI_WIDGET JSON (Settings > Appearance).
 *
 * Semantics: '' means "chat-loader's own default" and empty keys are NOT
 * forwarded to the storefront loader. Everything here is local-only except
 * `language`, which also goes to /setup as widget.language.
 */
class WidgetSettings
{
    const THEMES = ['', 'light', 'dark'];
    const LANGUAGES = ['', 'auto', 'en', 'ro', 'de', 'fr', 'es'];
    const AUDIO = ['', 'play', 'none'];
    const SIDES = ['', 'right', 'left'];

    /** Keys forwarded to chat-loader as strings (when non-empty). */
    const STRING_KEYS = ['subtitle', 'accent_color', 'proactive_message', 'theme', 'language', 'audio_beep', 'side'];

    /** Keys forwarded to chat-loader as integers (when numeric). */
    const INT_KEYS = ['proactive_delay', 'offset_y', 'offset_x', 'z_index'];

    /**
     * @return array every widget key => ''
     */
    public static function defaults()
    {
        return [
            'accent_color' => '',
            'theme' => '',
            'language' => '',
            'audio_beep' => '',
            'side' => '',
            'offset_y' => '',
            'offset_x' => '',
            'z_index' => '',
            'subtitle' => '',
            'proactive_message' => '',
            'proactive_delay' => '',
        ];
    }

    /**
     * Placeholders shown in the form (the loader's own defaults).
     *
     * @return array
     */
    public static function placeholders()
    {
        return [
            'accent_color' => '#615ED6',
            'offset_y' => '20',
            'offset_x' => '20',
            'z_index' => '2147483644',
            'proactive_delay' => '4',
        ];
    }

    /**
     * Whitelists enums, validates the colour, clamps numbers and strips tags
     * from free text. Unknown keys are dropped; missing keys become ''.
     *
     * @param array $raw key => raw string
     *
     * @return array
     */
    public static function sanitize(array $raw)
    {
        $out = self::defaults();

        $out['accent_color'] = self::color(self::str($raw, 'accent_color'));
        $out['theme'] = self::enumValue(self::str($raw, 'theme'), self::THEMES);
        $out['language'] = self::enumValue(self::str($raw, 'language'), self::LANGUAGES);
        $out['audio_beep'] = self::audio(self::str($raw, 'audio_beep'));
        $out['side'] = self::enumValue(self::str($raw, 'side'), self::SIDES);
        $out['offset_y'] = self::intValue(self::str($raw, 'offset_y'), 0, 10000);
        $out['offset_x'] = self::intValue(self::str($raw, 'offset_x'), 0, 10000);
        $out['z_index'] = self::intValue(self::str($raw, 'z_index'), 0, 2147483647);
        $out['subtitle'] = self::text(self::str($raw, 'subtitle'));
        $out['proactive_message'] = self::text(self::str($raw, 'proactive_message'));
        $out['proactive_delay'] = self::intValue(self::str($raw, 'proactive_delay'), 0, 300);

        return $out;
    }

    /**
     * Stored widget config merged over the defaults, for the settings form.
     *
     * @param array $stored
     *
     * @return array
     */
    public static function withDefaults(array $stored)
    {
        $out = self::defaults();
        foreach ($out as $key => $unused) {
            if (isset($stored[$key]) && is_scalar($stored[$key])) {
                $out[$key] = (string) $stored[$key];
            }
        }

        return $out;
    }

    /**
     * @param array $raw
     * @param string $key
     *
     * @return string
     */
    private static function str(array $raw, $key)
    {
        return isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
    }

    /**
     * @param string $value
     * @param array $allowed
     *
     * @return string
     */
    private static function enumValue($value, array $allowed)
    {
        $value = Tools::strtolower($value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Shopify stored audio_beep as a boolean; normalise legacy '1'/'0' too.
     *
     * @param string $value
     *
     * @return string
     */
    private static function audio($value)
    {
        $value = Tools::strtolower($value);
        if ($value === '1' || $value === 'true') {
            return 'play';
        }
        if ($value === '0' || $value === 'false') {
            return 'none';
        }

        return self::enumValue($value, self::AUDIO);
    }

    /**
     * @param string $value
     *
     * @return string '' or '#RRGGBB'
     */
    private static function color($value)
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? Tools::strtoupper($value) : '';
    }

    /**
     * @param string $value
     * @param int $min
     * @param int $max
     *
     * @return string '' or the clamped integer as a string
     */
    private static function intValue($value, $min, $max)
    {
        if ($value === '' || !ctype_digit($value)) {
            return '';
        }
        $int = (int) $value;
        if ($int < $min) {
            $int = $min;
        }
        if ($int > $max) {
            $int = $max;
        }

        return (string) $int;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function text($value)
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);

        return Tools::substr($value, 0, 255);
    }
}
