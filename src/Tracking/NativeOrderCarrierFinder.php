<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Tracking;

use Configuration;
use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's own tracking number: order_carrier.tracking_number + the
 * carrier's tracking URL template (`@` placeholder). Most courier modules
 * write here too, so this finder covers them without any specific code.
 */
class NativeOrderCarrierFinder implements TrackingFinderInterface
{
    const CODE = 'native';

    /** @var string|null URL override with {code} placeholder */
    private $urlOverride;

    /**
     * @param string|null $urlOverride
     */
    public function __construct($urlOverride = null)
    {
        $this->urlOverride = $urlOverride !== null && $urlOverride !== '' ? (string) $urlOverride : null;
    }

    public function code()
    {
        return self::CODE;
    }

    public function isAvailable()
    {
        return true;
    }

    public function find($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT oc.tracking_number, oc.id_carrier, c.name, c.url
             FROM `' . _DB_PREFIX_ . 'order_carrier` oc
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier` c ON (c.id_carrier = oc.id_carrier)
             WHERE oc.id_order = ' . (int) $idOrder . ' AND oc.tracking_number <> \'\'
             ORDER BY oc.id_order_carrier DESC'
        );
        if (!$row) {
            return null;
        }

        $awb = trim((string) $row['tracking_number']);
        if ($awb === '') {
            return null;
        }

        // PrestaShop convention: a carrier named "0" stands for the shop itself.
        $carrier = (string) $row['name'];
        if ($carrier === '0' || $carrier === '') {
            $carrier = (string) Configuration::get('PS_SHOP_NAME');
        }

        $url = null;
        if ($this->urlOverride !== null) {
            $url = str_replace('{code}', rawurlencode($awb), $this->urlOverride);
        } elseif (!empty($row['url'])) {
            $url = str_replace('@', rawurlencode($awb), (string) $row['url']);
        }

        return ['carrier' => $carrier, 'awb' => $awb, 'tracking_url' => $url];
    }
}
