<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Ovebotai\Install\Installer;
use Ovebotai\KnowledgeBase\CmsResync;
use Ovebotai\Storefront\WidgetRenderer;

/**
 * Ovebot.ai integration: OAuth connection, knowledge base sync from CMS pages,
 * product feed, order-lookup endpoint, storefront chat widget + purchase event.
 *
 * The whole back office lives in the AdminOvebotai legacy controller
 * (controllers/admin/AdminOvebotaiController.php): wizard, dashboard, settings.
 */
class Ovebotai extends Module
{
    const ADMIN_CONTROLLER = 'AdminOvebotai';
    const TRANSLATION_DOMAIN = 'Modules.Ovebotai.Admin';

    public function __construct()
    {
        $this->name = 'ovebotai';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'AWeb Design SRL';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => '9.99.99'];
        $this->module_key = '';

        parent::__construct();

        $this->displayName = $this->trans('Ovebot - AI Chatbot, Live Chat & AI Sales Agent', [], self::TRANSLATION_DOMAIN);
        $this->description = $this->trans('AI chatbot and live chat that recommends your products, answers from your pages and tracks orders 24/7.', [], self::TRANSLATION_DOMAIN);
        $this->confirmUninstall = $this->trans('Uninstalling disconnects every shop from Ovebot.ai and deletes the module settings. Continue?', [], self::TRANSLATION_DOMAIN);

        // Menu entry under Customer Service (the closest native section for a
        // chat product). Created by PrestaShop from this declaration; the
        // Installer adds a fallback for installs that bypass the module manager.
        $this->tabs = [
            [
                'class_name' => self::ADMIN_CONTROLLER,
                'parent_class_name' => 'AdminParentCustomerThreads',
                'name' => 'Ovebot AI',
                'visible' => true,
                'icon' => 'chat',
            ],
        ];
    }

    /**
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function install()
    {
        return parent::install() && (new Installer($this))->install();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        // Remote disconnect + local cleanup first, while the settings still
        // exist to know which shops were connected. Skipped during a reset
        // (the Installer detects it) so the connection survives.
        (new Installer($this))->uninstall();

        return parent::uninstall();
    }

    /**
     * Used by the module manager when it resets with "keep data"; its default
     * reset (uninstall + install) is covered by Installer::isResetting().
     *
     * @return bool
     */
    public function reset()
    {
        return (new Installer($this))->reset();
    }

    /**
     * "Configure" -> the module's own admin page.
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink(self::ADMIN_CONTROLLER));
    }

    // ── Hooks ────────────────────────────────────────────────────────────────

    /**
     * Module manager (8.0+): a reset of this module is about to uninstall it.
     * Flag it so the uninstall step keeps the Ovebot.ai connection and the
     * storefront credentials.
     *
     * @param array $params ['moduleName' => string]
     */
    public function hookActionBeforeResetModule($params)
    {
        if (isset($params['moduleName']) && (string) $params['moduleName'] === $this->name) {
            Installer::markReset();
        }
    }

    /**
     * Storefront: chat widget on every page, purchase event on order-confirmation.
     *
     * @param array $params
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        try {
            (new WidgetRenderer($this->context))->register();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('Ovebotai: storefront widget skipped: ' . $e->getMessage(), 2, null, 'Ovebotai', (int) $this->context->shop->id, true);
        }
    }

    /**
     * A CMS page selected for the knowledge base was edited: resync its entry.
     *
     * @param array $params ['object' => CMS]
     */
    public function hookActionObjectCmsUpdateAfter($params)
    {
        if (isset($params['object']) && $params['object'] instanceof CMS) {
            (new CmsResync())->onSaved($params['object']);
        }
    }

    /**
     * A CMS page was deleted: deactivate its knowledge base entry.
     *
     * @param array $params ['object' => CMS]
     */
    public function hookActionObjectCmsDeleteAfter($params)
    {
        if (isset($params['object']) && $params['object'] instanceof CMS) {
            (new CmsResync())->onDeleted($params['object']);
        }
    }
}
