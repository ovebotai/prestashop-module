<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Config;

use Configuration;
use Context;
use Db;
use Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Per-shop view over Configuration.
 *
 * Every read/write passes an explicit shop + group id, so a multistore install
 * keeps one Ovebot.ai connection per shop while a single-shop install behaves
 * exactly like standard PrestaShop configuration.
 *
 * Multistore rule (no inheritance): when the multistore feature is active a
 * key is read from the SHOP level only. A shop that never connected must not
 * look connected with another shop's tokens (Configuration::get would
 * otherwise fall back to the group / global value).
 */
class Settings
{
    // OAuth / account
    const ACCESS_TOKEN = 'OVEBOTAI_ACCESS_TOKEN';
    const REFRESH_TOKEN = 'OVEBOTAI_REFRESH_TOKEN';
    const TOKEN_EXPIRES = 'OVEBOTAI_TOKEN_EXPIRES';
    const WORKSPACE = 'OVEBOTAI_WORKSPACE';
    const AGENT = 'OVEBOTAI_AGENT';
    const SETUP_COMPLETE = 'OVEBOTAI_SETUP_COMPLETE';
    const KB_PAGE_IDS = 'OVEBOTAI_KB_PAGE_IDS';
    const OAUTH_PENDING = 'OVEBOTAI_OAUTH_PENDING';
    const OAUTH_RESULT = 'OVEBOTAI_OAUTH_RESULT';
    const LAST_PUSH = 'OVEBOTAI_LAST_PUSH';
    const ACCOUNT_HOST = 'OVEBOTAI_ACCOUNT_HOST';
    const API_HOST = 'OVEBOTAI_API_HOST';

    // Storefront credentials
    const FEED_HASH = 'OVEBOTAI_FEED_HASH';
    const ORDER_USER = 'OVEBOTAI_ORDER_USER';
    const ORDER_PASS = 'OVEBOTAI_ORDER_PASS';

    // Switches
    const CHAT_STATUS = 'OVEBOTAI_CHAT_STATUS';
    const PRODUCTS_BUILTIN = 'OVEBOTAI_PRODUCTS_BUILTIN';
    const PRODUCTS_RECOMMEND = 'OVEBOTAI_PRODUCTS_RECOMMEND';
    const ORDER_ENABLED = 'OVEBOTAI_ORDER_ENABLED';

    // Widget appearance (JSON)
    const WIDGET = 'OVEBOTAI_WIDGET';

    // Tracking / order lookup tuning (no UI)
    const TRACKING_FINDERS = 'OVEBOTAI_TRACKING_FINDERS';
    const TRACKING_URLS = 'OVEBOTAI_TRACKING_URLS';
    const ORDER_MAX_AGE_DAYS = 'OVEBOTAI_ORDER_MAX_AGE_DAYS';

    /**
     * Configuration::updateValue() runs non-HTML values through pSQL(), which
     * strip_tags()/nl2br()s them. Hex-escaping <, >, &, ' and " keeps stored
     * JSON free of any character those transforms could touch.
     * = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
     */
    const JSON_FLAGS = 15;

    /** @var int */
    private $idShop;

    /** @var int */
    private $idShopGroup;

    /** @var bool one lazy migration attempt per request */
    private static $migrationChecked = false;

    /**
     * @param int|null $idShop null = context shop
     */
    public function __construct($idShop = null)
    {
        $this->idShop = $idShop !== null ? (int) $idShop : (int) Context::getContext()->shop->id;
        $this->idShopGroup = (int) Shop::getGroupFromShop($this->idShop, true);

        // Configuration::hasKey() only looks at the in-memory cache; make sure
        // it is populated before any hasKey()-based decision below.
        Configuration::get('PS_SHOP_DEFAULT');

        $this->migrateGlobalValuesIfNeeded();
    }

    /**
     * Every OVEBOTAI_* key this module owns (used by uninstall / migration).
     *
     * @return string[]
     */
    public static function allKeys()
    {
        $reflection = new \ReflectionClass(__CLASS__);
        $keys = [];
        foreach ($reflection->getConstants() as $value) {
            if (is_string($value) && strpos($value, 'OVEBOTAI_') === 0) {
                $keys[] = $value;
            }
        }

        return $keys;
    }

    /**
     * @return int
     */
    public function getIdShop()
    {
        return $this->idShop;
    }

    /**
     * @return int
     */
    public function getIdShopGroup()
    {
        return $this->idShopGroup;
    }

    /**
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public function get($key, $default = '')
    {
        if (Shop::isFeatureActive()) {
            if (!Configuration::hasKey($key, null, null, $this->idShop)) {
                return $default;
            }
            $value = Configuration::get($key, null, $this->idShopGroup, $this->idShop);
        } else {
            $value = Configuration::get($key);
        }

        return ($value === false || $value === null) ? $default : (string) $value;
    }

    /**
     * @param string $key
     * @param string|int|bool $value
     */
    public function set($key, $value)
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (Shop::isFeatureActive()) {
            Configuration::updateValue($key, (string) $value, false, $this->idShopGroup, $this->idShop);
        } else {
            Configuration::updateValue($key, (string) $value);
        }
    }

    /**
     * @param array $partial key => scalar|array (arrays are stored as JSON)
     */
    public function setMany(array $partial)
    {
        foreach ($partial as $key => $value) {
            if (is_array($value)) {
                $this->setArray($key, $value);
            } else {
                $this->set($key, $value);
            }
        }
    }

    /**
     * Never-set / emptied -> $default: the "absent = on" rule for the mirrored
     * switches (PRODUCTS_RECOMMEND / ORDER_ENABLED / PRODUCTS_BUILTIN).
     *
     * @param string $key
     * @param bool $default
     *
     * @return bool
     */
    public function getFlag($key, $default)
    {
        $raw = $this->get($key, '');

        return $raw === '' ? (bool) $default : $raw === '1';
    }

    /**
     * @param string $key
     *
     * @return array
     */
    public function getArray($key)
    {
        $decoded = json_decode($this->get($key, ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $key
     * @param array $value
     */
    public function setArray($key, array $value)
    {
        $this->set($key, json_encode($value, self::JSON_FLAGS));
    }

    /**
     * Emptying (instead of Configuration::deleteByName) keeps the other
     * shops' rows intact.
     *
     * @param string $key
     */
    public function delete($key)
    {
        $this->set($key, '');
    }

    /**
     * Re-reads one key straight from the database, bypassing the request
     * cache. Used under the refresh lock: another process may have rotated the
     * tokens while we were waiting, and a second refresh with the old token
     * would revoke the whole family.
     *
     * @param string $key
     *
     * @return string
     */
    public function reloadFromDb($key)
    {
        $sql = 'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'';
        if (Shop::isFeatureActive()) {
            $sql .= ' AND `id_shop` = ' . (int) $this->idShop . ' AND `id_shop_group` = ' . (int) $this->idShopGroup;
        } else {
            $sql .= ' AND (`id_shop` IS NULL OR `id_shop` = 0) AND (`id_shop_group` IS NULL OR `id_shop_group` = 0)';
        }
        $sql .= ' ORDER BY `id_configuration` DESC';

        $value = Db::getInstance()->getValue($sql);
        $value = ($value === false || $value === null) ? '' : (string) $value;

        // Keep the request cache consistent with what we just read.
        if (Shop::isFeatureActive()) {
            Configuration::set($key, $value, $this->idShopGroup, $this->idShop);
        } else {
            Configuration::set($key, $value);
        }

        return $value;
    }

    /**
     * Values written while multistore was OFF live at global level and belong
     * to the default shop. Once multistore is switched on (and reads become
     * shop-only), copy them down to the default shop exactly once, so the shop
     * that was connected stays connected and new shops start disconnected.
     */
    private function migrateGlobalValuesIfNeeded()
    {
        if (self::$migrationChecked || !Shop::isFeatureActive()) {
            return;
        }
        self::$migrationChecked = true;

        if ($this->idShop !== (int) Configuration::get('PS_SHOP_DEFAULT')
            || Configuration::hasKey(self::FEED_HASH, null, null, $this->idShop)
            || !Configuration::hasKey(self::FEED_HASH)) {
            return;
        }

        foreach (self::allKeys() as $key) {
            if (Configuration::hasKey($key)) {
                $global = Configuration::getGlobalValue($key);
                Configuration::updateValue($key, (string) $global, false, $this->idShopGroup, $this->idShop);
            }
        }
    }
}
