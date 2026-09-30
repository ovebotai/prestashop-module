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
 * HTML -> plain text for the product feed and the knowledge base body.
 */
class Text
{
    /**
     * Block-level closings and <br> become newlines BEFORE strip_tags, so
     * paragraphs / list items don't run into each other; entities are decoded
     * and whitespace collapsed while single newlines are kept.
     *
     * @param string|null $html
     *
     * @return string
     */
    public static function plain($html)
    {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|section|article|ul|ol|table|pre)>#i', "\n", $html);
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\r"], [' ', ''], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/ *\n */', "\n", $text);
        $text = preg_replace("/\n{2,}/", "\n", $text);

        return trim((string) $text);
    }

    /**
     * Single-line variant (product / feature names).
     *
     * @param string|null $html
     *
     * @return string
     */
    public static function line($html)
    {
        return trim((string) preg_replace('/\s+/', ' ', self::plain($html)));
    }

    /**
     * @param string $text
     *
     * @return int
     */
    public static function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
