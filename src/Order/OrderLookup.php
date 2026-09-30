<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Order;

use Db;
use DbQuery;
use Ovebotai\Config\Settings;
use Ovebotai\Tracking\TrackingResolver;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Finds ONE order of the shop by id/reference + email or phone and maps it to
 * the response shape Ovebot.ai expects.
 */
class OrderLookup
{
    const DEFAULT_MAX_AGE_DAYS = 60;

    /** @var int */
    private $idShop;

    /** @var Settings */
    private $settings;

    /** @var TrackingResolver */
    private $tracking;

    /**
     * @param int $idShop
     * @param Settings $settings
     * @param TrackingResolver|null $tracking
     */
    public function __construct($idShop, Settings $settings, ?TrackingResolver $tracking = null)
    {
        $this->idShop = (int) $idShop;
        $this->settings = $settings;
        $this->tracking = $tracking ?: new TrackingResolver($settings);
    }

    /**
     * @param array $identifier OrderIdentifier::parse() result: ['reference' => ?string, 'id' => ?int]
     * @param string $type 'email' | 'phone'
     * @param string $value validated email, or the 9-digit phone core
     *
     * @return array|null
     */
    public function find(array $identifier, $type, $value)
    {
        $maxAge = (int) $this->settings->get(Settings::ORDER_MAX_AGE_DAYS, '');
        if ($maxAge <= 0) {
            $maxAge = self::DEFAULT_MAX_AGE_DAYS;
        }

        $q = new DbQuery();
        $q->select('o.id_order, o.reference, o.date_add, o.total_paid_tax_incl, cu.iso_code, osl.name AS status');
        $q->from('orders', 'o');
        $q->innerJoin('customer', 'c', 'c.id_customer = o.id_customer');
        $q->leftJoin('currency', 'cu', 'cu.id_currency = o.id_currency');
        // Status in the language the order was placed in, not the shop's
        // default: it is read back to the customer who placed it.
        $q->leftJoin('order_state_lang', 'osl', 'osl.id_order_state = o.current_state AND osl.id_lang = o.id_lang');
        $q->where('o.id_shop = ' . (int) $this->idShop);
        $q->where('o.current_state > 0');
        $q->where('o.date_add >= DATE_SUB(NOW(), INTERVAL ' . (int) $maxAge . ' DAY)');

        // The customer only ever sees the reference, an operator may quote the
        // numeric id: match whichever candidate the input produced.
        $match = [];
        if (!empty($identifier['reference'])) {
            $match[] = 'o.reference = \'' . pSQL($identifier['reference']) . '\'';
        }
        if (!empty($identifier['id'])) {
            $match[] = 'o.id_order = ' . (int) $identifier['id'];
        }
        if (!$match) {
            return null;
        }
        $q->where('(' . implode(' OR ', $match) . ')');

        if ($type === 'email') {
            $q->where('c.email = \'' . pSQL($value) . '\'');
        } else {
            $core = preg_replace('/\D/', '', (string) $value);
            if ($core === '') {
                return null;
            }
            $q->where('EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'address` a
                WHERE a.id_address IN (o.id_address_delivery, o.id_address_invoice)
                  AND (' . $this->cleanPhone('a.phone') . ' LIKE \'%' . pSQL($core) . '\'
                    OR ' . $this->cleanPhone('a.phone_mobile') . ' LIKE \'%' . pSQL($core) . '\'))');
        }

        // Split carts share one reference: the most recent order wins.
        $q->orderBy('o.id_order DESC');

        $row = Db::getInstance()->getRow($q);
        if (!$row) {
            return null;
        }

        $tracking = $this->tracking->resolve((int) $row['id_order']);

        return [
            'id' => (int) $row['id_order'],
            'reference' => (string) $row['reference'],
            'date' => (string) $row['date_add'],
            'status' => (string) $row['status'],
            'total' => round((float) $row['total_paid_tax_incl'], 2),
            'currency' => (string) $row['iso_code'],
            'carrier' => $tracking ? $tracking['carrier'] : null,
            'awb' => $tracking ? $tracking['awb'] : null,
            'awb_tracking_url' => $tracking ? $tracking['tracking_url'] : null,
        ];
    }

    /**
     * Strips the usual separators so "+40 (721) 234-567" matches "721234567".
     *
     * @param string $column
     *
     * @return string SQL expression
     */
    private function cleanPhone($column)
    {
        $expr = 'COALESCE(' . $column . ', \'\')';
        foreach ([' ', '.', '-', '/', '(', ')', '+'] as $char) {
            $expr = 'REPLACE(' . $expr . ', \'' . $char . '\', \'\')';
        }

        return $expr;
    }
}
