<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Install;

use Configuration;
use Db;
use Language;
use Module;
use Ovebotai\Config\Settings;
use Ovebotai\Security\RateLimiter;
use Ovebotai\Service\Integration;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use Shop;
use Tab;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Install / uninstall / reset steps: hooks, the rate-limit table, per-shop
 * seed of the storefront credentials, and the admin tab fallback.
 */
class Installer
{
    const MODULE_NAME = 'ovebotai';
    const ADMIN_CONTROLLER = 'AdminOvebotai';
    const TAB_PARENT = 'AdminParentCustomerThreads';
    const TAB_PARENT_FALLBACK = 'AdminParentModulesSf';

    const HOOKS = [
        'actionFrontControllerSetMedia',
        'actionObjectCmsUpdateAfter',
        'actionObjectCmsDeleteAfter',
        // Fired by the module manager (8.0+) right before a reset's uninstall.
        'actionBeforeResetModule',
    ];

    /** @var bool set once a reset of THIS module is known to be in progress */
    private static $resetting = false;

    /** @var Module */
    private $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * @return bool
     */
    public function install()
    {
        return $this->module->registerHook(self::HOOKS)
            && $this->createTables()
            && $this->seedShops()
            && $this->ensureTab();
    }

    /**
     * Module::reset() when the module manager keeps the data: refresh hooks,
     * table and tab, touch nothing the merchant configured.
     *
     * @return bool
     */
    public function reset()
    {
        self::markReset();

        return $this->install();
    }

    /**
     * The module manager's default reset is uninstall() + install() in one
     * request. Flag it so uninstall() keeps the connection and the storefront
     * credentials (the feed URL / order login registered with Ovebot.ai must
     * not silently break on a reset).
     */
    public static function markReset()
    {
        self::$resetting = true;
    }

    /**
     * True during a reset: either the actionBeforeResetModule hook fired
     * (8.0+), or the module manager's own action route says so (1.7.8, which
     * has no such hook).
     *
     * @return bool
     */
    public static function isResetting()
    {
        if (self::$resetting) {
            return true;
        }

        try {
            $container = SymfonyContainer::getInstance();
            $request = $container && $container->has('request_stack') ? $container->get('request_stack')->getCurrentRequest() : null;
            if ($request
                && (string) $request->attributes->get('action') === 'reset'
                && (string) $request->attributes->get('module_name') === self::MODULE_NAME) {
                return self::$resetting = true;
            }
        } catch (\Throwable $e) {
            // no Symfony request (CLI / legacy) -> not a reset we can detect
        }

        return false;
    }

    /**
     * Best-effort remote disconnect + local cleanup. Runs BEFORE
     * parent::uninstall() so the settings still tell which shops were connected.
     *
     * During a reset only the hooks / tab are refreshed by the parent; the
     * remote connection, every setting and the rate-limit table stay in place.
     *
     * @return bool
     */
    public function uninstall()
    {
        if (self::isResetting()) {
            return true;
        }

        foreach ($this->shopIds() as $idShop) {
            try {
                $integration = Integration::forShop($idShop);
                if ($integration->isConnected()) {
                    $integration->disconnectQuietly(5);
                }
            } catch (\Throwable $e) {
                // never block the uninstall
            }
        }

        foreach (Settings::allKeys() as $key) {
            Configuration::deleteByName($key);
        }

        Db::getInstance()->execute(RateLimiter::dropTableSql());

        $this->removeTab();

        return true;
    }

    /**
     * @return bool
     */
    private function createTables()
    {
        return (bool) Db::getInstance()->execute(RateLimiter::createTableSql());
    }

    /**
     * Storefront credentials are seeded once per shop and never overwritten
     * on a reset (a live feed URL / order login already registered with
     * Ovebot.ai must not silently break; see isResetting()). A real uninstall
     * deletes them, so a later reinstall starts from scratch. Chat is never
     * auto-enabled here: only a successful wizard Finish does that.
     *
     * @return bool
     */
    private function seedShops()
    {
        foreach ($this->shopIds() as $idShop) {
            Integration::forShop($idShop)->ensureCredentials();
            $settings = new Settings($idShop);
            if ($settings->get(Settings::CHAT_STATUS) === '') {
                $settings->set(Settings::CHAT_STATUS, '0');
            }
        }

        return true;
    }

    /**
     * PrestaShop's module manager creates the tabs declared in $tabs itself;
     * this fallback covers installs that go through Module::install() alone.
     *
     * @return bool
     */
    private function ensureTab()
    {
        if ((int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER) > 0) {
            return true;
        }

        $parentId = (int) Tab::getIdFromClassName(self::TAB_PARENT);
        if ($parentId <= 0) {
            $parentId = (int) Tab::getIdFromClassName(self::TAB_PARENT_FALLBACK);
        }

        $tab = new Tab();
        $tab->class_name = self::ADMIN_CONTROLLER;
        $tab->module = $this->module->name;
        $tab->id_parent = $parentId > 0 ? $parentId : 0;
        $tab->active = true;
        if (property_exists($tab, 'icon')) {
            $tab->icon = 'chat';
        }
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Ovebot AI';
        }

        try {
            return (bool) $tab->add();
        } catch (\Exception $e) {
            return true; // the module manager will create it; don't fail the install
        }
    }

    private function removeTab()
    {
        $idTab = (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER);
        if ($idTab > 0) {
            try {
                $tab = new Tab($idTab);
                if ($tab->id) {
                    $tab->delete();
                }
            } catch (\Exception $e) {
                // ignore
            }
        }
    }

    /**
     * @return int[]
     */
    private function shopIds()
    {
        return array_map('intval', (array) Shop::getShops(false, null, true));
    }
}
