<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Parses what the visitor typed into something the lookup can match.
 */
class OrderIdentifier
{
    /**
     * What the customer has is the reference ("ZNZNIWJSD") - the numeric order
     * id is never shown to them - but an operator or another integration may
     * still send the id. So both are returned as CANDIDATES and the lookup
     * matches `reference = ? OR id_order = ?`, instead of guessing one format
     * and silently missing the order when the guess is wrong.
     *
     * A plain number yields both candidates: PrestaShop's own references are
     * letters only, but a merchant may run a module that generates numeric ones.
     *
     * @param mixed $raw
     *
     * @return array|null ['reference' => string|null, 'id' => int|null], null when neither applies
     */
    public static function parse($raw)
    {
        $raw = is_scalar($raw) ? trim((string) $raw) : '';
        if ($raw === '') {
            return null;
        }

        // "#ZNZNIWJSD" / "#123" - the hash is how people write order numbers.
        $clean = trim(ltrim($raw, '#'));
        $reference = null;
        $id = null;

        // `orders`.`reference` is VARCHAR(9), so anything longer cannot be one.
        if ($clean !== '' && strlen($clean) <= 9 && preg_match('/^[A-Za-z0-9_-]+$/', $clean)) {
            $reference = strtoupper($clean);
        }

        if (preg_match('/^[0-9]+$/', $clean)) {
            $id = (int) $clean;
        } elseif ($reference === null) {
            // "order 45", "comanda nr. 45" - only worth digging for digits when
            // the input cannot be a reference, otherwise "ORD12" would turn into 12.
            $id = (int) preg_replace('/\D/', '', $clean);
        }

        if ($id !== null && $id <= 0) {
            $id = null;
        }

        if ($reference === null && $id === null) {
            return null;
        }

        return ['reference' => $reference, 'id' => $id];
    }

    /**
     * Last 9 national digits: "+40 721-234.567" / "0721234567" / "0040721234567"
     * -> "721234567". Generalises the OpenCart RO-only normalisation and is
     * matched with LIKE '%core' against the cleaned address phones.
     *
     * @param mixed $phone
     *
     * @return string '' when fewer than 9 digits remain
     */
    public static function phoneCore($phone)
    {
        $digits = (string) preg_replace('/\D/', '', is_scalar($phone) ? (string) $phone : '');
        $digits = ltrim($digits, '0');

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }
}
