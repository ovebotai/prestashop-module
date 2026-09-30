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

use Ovebotai\Api\Exception\OvebotaiException;
use Ovebotai\Config\WidgetSettings;
use Ovebotai\Feed\ProductFeedBuilder;
use Ovebotai\KnowledgeBase\CmsPageProvider;
use Ovebotai\KnowledgeBase\KbSync;
use Ovebotai\Service\Integration;

/**
 * Single back-office page whose content depends on state:
 *   multistore "all shops" / "group" context -> pick a shop
 *   not connected / not finished             -> setup wizard
 *   finished + ?view=settings                -> settings
 *   finished                                 -> dashboard
 *
 * AJAX actions (POST index.php?controller=AdminOvebotai&token=..&ajax=1&action=X):
 *   SyncPages, Finish, SaveSettings, RegenFeedHash, RegenOrderCreds
 */
class AdminOvebotaiController extends ModuleAdminController
{
    const DOMAIN = 'Modules.Ovebotai.Admin';

    const VIEW_SHOP_CONTEXT = 'shop_context';
    const VIEW_SETUP = 'setup';
    const VIEW_DASHBOARD = 'dashboard';
    const VIEW_SETTINGS = 'settings';

    /** @var Integration|null shared per request so the status probe is memoised */
    private $integration;

    /** @var array|null JSON payload of the current AJAX action */
    private $ajaxResponse;

    /** @var string|null resolved view (memoised) */
    private $view;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->meta_title = 'Ovebot AI';
    }

    // ── Media ────────────────────────────────────────────────────────────────

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        if ($this->ajax) {
            return;
        }

        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin/common.js');
    }

    // ── Non-AJAX actions ─────────────────────────────────────────────────────

    public function postProcess()
    {
        parent::postProcess(); // dispatches ajaxProcess* when ajax=1

        if ($this->ajax) {
            return;
        }

        $action = (string) Tools::getValue('ovebotai_action');
        if ($action === 'connect') {
            $this->processConnect();
        } elseif ($action === 'disconnect') {
            $this->processDisconnect();
        }
    }

    private function processConnect()
    {
        if (!$this->inShopContext()) {
            return;
        }
        if (!$this->access('edit')) {
            $this->errors[] = $this->permissionError();

            return;
        }

        $returnUrl = $this->absoluteAdminUrl($this->adminUrl());
        $authorizeUrl = $this->integration()->beginAuthorization($returnUrl, (int) $this->context->employee->id);

        Tools::redirectAdmin($authorizeUrl);
    }

    private function processDisconnect()
    {
        if ($this->inShopContext()) {
            if ($this->access('edit')) {
                $this->integration()->disconnect();
            } else {
                $this->errors[] = $this->permissionError();

                return;
            }
        }

        Tools::redirectAdmin($this->adminUrl());
    }

    // ── Page ─────────────────────────────────────────────────────────────────

    public function initContent()
    {
        parent::initContent();

        if ($this->ajax || !$this->viewAccess()) {
            return;
        }

        switch ($this->resolveView()) {
            case self::VIEW_SHOP_CONTEXT:
                $html = $this->renderShopContext();
                break;
            case self::VIEW_SETUP:
                $this->addJS($this->module->getPathUri() . 'views/js/admin/setup.js');
                $html = $this->renderSetup();
                break;
            case self::VIEW_SETTINGS:
                $this->addJS($this->module->getPathUri() . 'views/js/admin/settings.js');
                $html = $this->renderSettings();
                break;
            default:
                $html = $this->renderDashboard();
        }

        $this->content .= $html;
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Routing is derived from state alone, never from the query string (a
     * stale ?step= after an old OAuth return used to strand the OpenCart module).
     *
     * @return string
     */
    private function resolveView()
    {
        if ($this->view !== null) {
            return $this->view;
        }

        if (!$this->inShopContext()) {
            return $this->view = self::VIEW_SHOP_CONTEXT;
        }

        $integration = $this->integration();
        $integration->ensureCredentials();
        // Probe first: a revoked token is cleared right here, so THIS load
        // already lands on the reconnect step instead of a stale dashboard.
        $integration->probeConnection();
        $integration->syncSettings();
        $integration->selfHealEndpoints();

        if (!$integration->isSetupComplete()) {
            return $this->view = self::VIEW_SETUP;
        }
        if ((string) Tools::getValue('view') === 'settings') {
            return $this->view = self::VIEW_SETTINGS;
        }

        return $this->view = self::VIEW_DASHBOARD;
    }

    // ── Views ────────────────────────────────────────────────────────────────

    /**
     * @return string
     */
    private function renderSetup()
    {
        $integration = $this->integration();
        $connected = $integration->isConnected();

        // The opening step comes from state alone: not connected -> 1, connected
        // but not finished -> 2 (a finished setup never reaches this view).
        $initialStep = $connected ? 2 : 1;

        $pages = (new CmsPageProvider($integration->getUrls()))->listForWizard($integration->getKbPageIds());
        $setupUrl = $integration->getAgentSetupUrl();
        $kbUrl = $integration->getKbUrl();

        Media::addJsDef(['ovebotaiSetup' => [
            'ajaxUrl' => $this->ajaxUrl(),
            'initialStep' => $initialStep,
            'stepsSequence' => [1, 2, 3, 4],
            'isConnected' => $connected ? 1 : 0,
            'productCounts' => (new ProductFeedBuilder($this->context, (int) $this->context->shop->id))->counts(),
            'i18n' => [
                'next' => $this->trans('Next', [], self::DOMAIN),
                'finish' => $this->trans('Finish setup', [], self::DOMAIN),
                'retry' => $this->trans('Retry', [], self::DOMAIN),
                'error' => $this->trans('An error occurred. Please try again.', [], self::DOMAIN),
                'noProducts' => $this->trans('No enabled products found - your AI agent won\'t have any products to recommend yet.', [], self::DOMAIN),
                'productsWillBeIndexed' => $this->trans('products will be sent to your AI agent so it can recommend them to customers.', [], self::DOMAIN),
                'syncingPages' => $this->trans('Syncing pages…', [], self::DOMAIN),
                'kbLimitPageSkipped' => $this->trans('Skipped - knowledge base limit reached.', [], self::DOMAIN),
                'pageUpdated' => $this->trans('Saved', [], self::DOMAIN),
            ],
        ]]);

        $this->assignCommon();
        $this->context->smarty->assign([
            'ove_step' => $initialStep,
            'ove_connected' => $connected,
            'ove_oauth_error' => $integration->pullOauthError(),
            'ove_register_url' => $integration->getRegisterUrl(),
            'ove_connect_url' => $this->adminUrl(['ovebotai_action' => 'connect']),
            'ove_pages' => $pages,
            'ove_products_builtin' => $integration->getProductsBuiltin(),
            'ove_setup_url' => $setupUrl,
            'ove_settings_url' => $this->adminUrl(['view' => 'settings']),
            'ove_chat_url' => $this->previewUrl(),
            'ove_step_labels' => [
                1 => $this->trans('Connect account', [], self::DOMAIN),
                2 => $this->trans('Website pages', [], self::DOMAIN),
                3 => $this->trans('Products', [], self::DOMAIN),
                4 => $this->trans('Go live 🎉', [], self::DOMAIN),
            ],
            'ove_no_pages_html' => $this->trans(
                'No enabled CMS pages found. You can still add knowledge base entries in your %link%.',
                ['%link%' => $this->link($kbUrl, $this->trans('Ovebot.ai account', [], self::DOMAIN))],
                self::DOMAIN
            ),
            'ove_products_external_html' => $this->trans(
                'Set up a compatible feed URL (e.g. Google Merchant) directly in your %link%.',
                ['%link%' => $this->link($setupUrl, $this->trans('Ovebot.ai account', [], self::DOMAIN))],
                self::DOMAIN
            ),
            'ove_done_recommend_html' => $setupUrl !== '' ? $this->trans(
                'Setup finished successfully - we still recommend checking the settings in your Ovebot.ai account for the selected agent, available %link%. You\'ll also find more settings and customizations there that might be useful.',
                ['%link%' => $this->link($setupUrl, $this->trans('here', [], self::DOMAIN))],
                self::DOMAIN
            ) : '',
        ]);

        return $this->fetch('setup.tpl');
    }

    /**
     * @return string
     */
    private function renderDashboard()
    {
        $integration = $this->integration();
        $probe = $integration->probeConnection();
        $kb = $integration->getKbEntries();

        $activeCount = 0;
        foreach ($kb['entries'] as $entry) {
            if ($entry['is_active']) {
                ++$activeCount;
            }
        }

        $this->assignCommon();
        $this->context->smarty->assign([
            'ove_page_title' => $this->trans('Ovebot.ai', [], self::DOMAIN),
            'ove_account_url' => $integration->getAccountUrl(),
            'ove_settings_url' => $this->adminUrl(['view' => 'settings']),
            'ove_chat_status' => $integration->getChatStatus(),
            'ove_chat_url' => $this->previewUrl(),
            'ove_chat_highlight_url' => $this->adminUrl(['view' => 'settings', 'highlight' => 'oveChatStatus']),
            'ove_setup_url' => $integration->getAgentSetupUrl(),
            'ove_unreachable' => $probe === Integration::PROBE_UNREACHABLE,
            'ove_products_recommend' => $integration->getProductsRecommend(),
            'ove_products_count' => $integration->getIndexedProductCount(),
            'ove_products_count_label' => number_format($integration->getIndexedProductCount()),
            'ove_products_url' => $integration->getProductsUrl(),
            'ove_products_off_html' => $this->trans(
                'Your AI agent isn\'t recommending products right now. You can change its status %link%.',
                ['%link%' => $this->link($this->adminUrl(['view' => 'settings', 'highlight' => 'oveProductsRecommend']), $this->trans('here', [], self::DOMAIN), false)],
                self::DOMAIN
            ),
            'ove_order_enabled' => $integration->getOrderEnabled(),
            'ove_order_off_html' => $this->trans(
                'Your AI agent can\'t answer order-status questions while order tracking is off. You can change its status %link%.',
                ['%link%' => $this->link($this->adminUrl(['view' => 'settings', 'highlight' => 'oveOrderEnabled']), $this->trans('here', [], self::DOMAIN), false)],
                self::DOMAIN
            ),
            'ove_kb_error' => $kb['error'],
            'ove_kb_entries' => $kb['entries'],
            'ove_kb_summary' => $this->trans('%active% of %total% entries active', ['%active%' => $activeCount, '%total%' => count($kb['entries'])], self::DOMAIN),
            'ove_kb_none_html' => $this->trans(
                'No knowledge base entries yet - %link%.',
                ['%link%' => $this->link($integration->getKbCreateUrl(), $this->trans('add one here', [], self::DOMAIN))],
                self::DOMAIN
            ),
        ]);

        return $this->fetch('dashboard.tpl');
    }

    /**
     * @return string
     */
    private function renderSettings()
    {
        $integration = $this->integration();

        Media::addJsDef(['ovebotaiSettings' => [
            'ajaxUrl' => $this->ajaxUrl(),
            'dashboardUrl' => $this->adminUrl(),
            'isConnected' => $integration->isConnected() ? 1 : 0,
            'i18n' => [
                'saved' => $this->trans('Settings saved.', [], self::DOMAIN),
                'saving' => $this->trans('Saving…', [], self::DOMAIN),
                'error' => $this->trans('An error occurred. Please try again.', [], self::DOMAIN),
                'enabled' => $this->trans('Enabled', [], self::DOMAIN),
                'disabled' => $this->trans('Disabled', [], self::DOMAIN),
                'confirmRegenHash' => $this->trans('Regenerate feed hash? The current feed URL will stop working.', [], self::DOMAIN),
                'confirmRegenCreds' => $this->trans('Regenerate API credentials? The current credentials will stop working immediately.', [], self::DOMAIN),
                'copied' => $this->trans('Copied!', [], self::DOMAIN),
            ],
        ]]);

        $this->assignCommon();
        $this->context->smarty->assign([
            'ove_page_title' => $this->trans('Settings', [], self::DOMAIN),
            'ove_dashboard_url' => $this->adminUrl(),
            'ove_chat_status' => $integration->getChatStatus(),
            'ove_products_recommend' => $integration->getProductsRecommend(),
            'ove_products_builtin' => $integration->getProductsBuiltin(),
            'ove_order_enabled' => $integration->getOrderEnabled(),
            'ove_setup_url' => $integration->getAgentSetupUrl(),
            'ove_feed_url' => $integration->getFeedUrl(),
            'ove_order_url' => $integration->getOrdersUrl(),
            'ove_order_user' => $integration->getOrderUser(),
            'ove_order_pass' => $integration->getOrderPass(),
            'ove_widget' => WidgetSettings::withDefaults($integration->getWidget()),
            'ove_placeholders' => WidgetSettings::placeholders(),
            'ove_languages' => [
                'en' => 'English',
                'ro' => 'Română',
                'de' => 'Deutsch',
                'fr' => 'Français',
                'es' => 'Español',
            ],
        ]);

        return $this->fetch('settings.tpl');
    }

    /**
     * Multistore, "All shops" / group context: pick the shop to configure.
     *
     * @return string
     */
    private function renderShopContext()
    {
        $shops = [];
        foreach (Shop::getShops(true) as $shop) {
            $idShop = (int) $shop['id_shop'];
            $integration = Integration::forShop($idShop);
            $shops[] = [
                'id' => $idShop,
                'name' => (string) $shop['name'],
                'connected' => $integration->isConnected(),
                'label' => $integration->getConnectionLabel(),
                'url' => $this->adminUrl(['setShopContext' => 's-' . $idShop]),
            ];
        }

        $this->assignCommon(false);
        $this->context->smarty->assign(['ove_shops' => $shops]);

        return $this->fetch('shop_context.tpl');
    }

    /**
     * Variables shared by every template (header, badge, logo).
     *
     * @param bool $withIntegration
     */
    private function assignCommon($withIntegration = true)
    {
        $vars = [
            'ove_tpl_dir' => $this->templateDir(),
            'ove_logo_url' => $this->module->getPathUri() . 'views/img/logo.png',
            'ove_version' => (string) $this->module->version,
            'ove_page_title' => '',
            'ove_is_connected' => false,
            'ove_connection_label' => '',
            'ove_disconnect_url' => '',
            'ove_shop_name' => Shop::isFeatureActive() ? (string) $this->context->shop->name : '',
        ];

        if ($withIntegration) {
            $integration = $this->integration();
            $vars['ove_is_connected'] = $integration->isConnected();
            $vars['ove_connection_label'] = $integration->getConnectionLabel();
            $vars['ove_disconnect_url'] = $this->adminUrl(['ovebotai_action' => 'disconnect']);
        }

        $this->context->smarty->assign($vars);
    }

    // ── AJAX: wizard step 2 ──────────────────────────────────────────────────

    /**
     * Fired on every "Next" click from step 2. Whatever couldn't be sent (real
     * API error, or an intentional skip like "not enough text") comes back
     * keyed by id_cms in `failed`; the JS unchecks those boxes, shows the
     * message under each and keeps the merchant on step 2 for another go.
     * Re-sending everything checked on every attempt is intentional.
     */
    public function ajaxProcessSyncPages()
    {
        if (!$this->guardAjax()) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) Tools::getValue('page_ids', [])))));
        $integration = $this->integration();
        $integration->saveKbPageIds($ids);

        $result = ['failed' => [], 'kb_limit' => '', 'kb_limit_ids' => []];
        try {
            $result = (new KbSync($integration, new CmsPageProvider($integration->getUrls())))->sync($ids, true);
        } catch (OvebotaiException $e) {
            // Couldn't even reach the API: every requested page is equally
            // "failed", same message on each.
            foreach ($ids as $id) {
                $result['failed'][$id] = $e->getMessage();
            }
        }

        $this->json([
            'success' => true,
            'failed' => (object) $result['failed'],
            'kb_limit' => $result['kb_limit'],
            'kb_limit_ids' => array_values($result['kb_limit_ids']),
            'clean' => !$result['failed'] && !$result['kb_limit_ids'],
        ]);
    }

    // ── AJAX: wizard finish ──────────────────────────────────────────────────

    /**
     * Page sync already happened per step; all that's left is the products
     * choice from step 3 (persisted only now) and pushing the whole /setup
     * payload. Success flips setup_complete + chat on.
     */
    public function ajaxProcessFinish()
    {
        if (!$this->guardAjax()) {
            return;
        }

        $result = $this->integration()->finish((bool) Tools::getValue('products_builtin'));

        if ($result['success']) {
            $this->json(['success' => true, 'message' => $this->trans('Setup complete!', [], self::DOMAIN)]);

            return;
        }

        $message = trim($this->trans('Could not sync feed and order settings with Ovebot.ai.', [], self::DOMAIN) . ' ' . $result['error']);
        $this->json(['success' => false, 'message' => $message, 'errors' => [$message]]);
    }

    // ── AJAX: settings ───────────────────────────────────────────────────────

    public function ajaxProcessSaveSettings()
    {
        if (!$this->guardAjax()) {
            return;
        }

        $raw = [];
        foreach (array_keys(WidgetSettings::defaults()) as $key) {
            $raw[$key] = Tools::getValue('widget_' . $key, '');
        }
        $widget = WidgetSettings::sanitize($raw);

        $result = $this->integration()->saveSettings(
            (bool) Tools::getValue('chat_status'),
            $widget,
            (bool) Tools::getValue('products_builtin'),
            (bool) Tools::getValue('products_recommend'),
            (bool) Tools::getValue('order_enabled')
        );

        if ($result['status'] === 'needs_reconnect') {
            $this->json([
                'success' => true,
                'needs_reconnect' => true,
                'message' => $this->trans('Chat settings saved. Reconnect to Ovebot.ai to sync feed and order settings.', [], self::DOMAIN),
            ]);

            return;
        }

        if ($result['status'] === 'sync_failed') {
            $this->json([
                'success' => true,
                'message' => $this->trans('Settings saved.', [], self::DOMAIN),
                'warnings' => [trim($this->trans('Settings saved locally but could not sync with Ovebot.ai.', [], self::DOMAIN) . ' ' . $result['error'])],
                'effective' => $result['effective'],
            ]);

            return;
        }

        $this->json(['success' => true, 'message' => $this->trans('Settings saved.', [], self::DOMAIN)]);
    }

    public function ajaxProcessRegenFeedHash()
    {
        if (!$this->guardAjax()) {
            return;
        }

        $result = $this->integration()->regenerateFeedHash();

        if (!$result['success']) {
            $this->json([
                'success' => false,
                'message' => trim($this->trans('Could not sync with Ovebot.ai - feed hash left unchanged.', [], self::DOMAIN) . ' ' . $result['error']),
            ]);

            return;
        }

        $this->json([
            'success' => true,
            'hash' => $result['hash'],
            'url' => $result['url'],
            'message' => $result['synced']
                ? $this->trans('Feed URL regenerated and synced with Ovebot.ai.', [], self::DOMAIN)
                : $this->trans('Feed URL regenerated.', [], self::DOMAIN),
        ]);
    }

    public function ajaxProcessRegenOrderCreds()
    {
        if (!$this->guardAjax()) {
            return;
        }

        $result = $this->integration()->regenerateOrderCreds();

        if (!$result['success']) {
            $this->json([
                'success' => false,
                'message' => trim($this->trans('Could not sync with Ovebot.ai - credentials left unchanged.', [], self::DOMAIN) . ' ' . $result['error']),
            ]);

            return;
        }

        $this->json([
            'success' => true,
            'user' => $result['user'],
            'pass' => $result['pass'],
            'message' => $result['synced']
                ? $this->trans('Credentials regenerated and synced with Ovebot.ai.', [], self::DOMAIN)
                : $this->trans('Credentials regenerated.', [], self::DOMAIN),
        ]);
    }

    /**
     * JSON output for every AJAX action (ajaxRender, never die/exit).
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        $payload = $this->ajaxResponse !== null ? $this->ajaxResponse : ['success' => false, 'error' => $this->trans('An error occurred. Please try again.', [], self::DOMAIN)];
        $this->ajaxRender(json_encode($payload));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Permission + shop-context guard shared by the AJAX actions.
     *
     * @return bool
     */
    private function guardAjax()
    {
        if (!$this->access('edit')) {
            $this->json(['success' => false, 'error' => $this->permissionError()]);

            return false;
        }
        if (!$this->inShopContext()) {
            $this->json(['success' => false, 'error' => $this->trans('Select a shop', [], self::DOMAIN)]);

            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private function inShopContext()
    {
        return !Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP;
    }

    /**
     * @return Integration
     */
    private function integration()
    {
        if ($this->integration === null) {
            $this->integration = Integration::forShop((int) $this->context->shop->id);
        }

        return $this->integration;
    }

    /**
     * @param array $payload
     */
    private function json(array $payload)
    {
        $this->ajaxResponse = $payload;
    }

    /**
     * @return string
     */
    private function permissionError()
    {
        return $this->trans('You do not have permission to modify the Ovebot.ai module.', [], self::DOMAIN);
    }

    /**
     * @param array $params
     *
     * @return string
     */
    private function adminUrl(array $params = [])
    {
        return $this->context->link->getAdminLink(Ovebotai::ADMIN_CONTROLLER, true, [], $params);
    }

    /**
     * @return string
     */
    private function ajaxUrl()
    {
        return $this->adminUrl(['ajax' => 1]);
    }

    /**
     * getAdminLink() may return a relative URL depending on the version; the
     * OAuth return target must be absolute (the front controller redirects to it).
     *
     * @param string $url
     *
     * @return string
     */
    private function absoluteAdminUrl($url)
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $host = Tools::getShopDomainSsl(true);
        if (strpos($url, '/') === 0) {
            return $host . $url;
        }

        $adminDir = basename(_PS_ADMIN_DIR_);
        if (strpos($url, $adminDir . '/') === 0) {
            return $host . __PS_BASE_URI__ . $url;
        }

        return $host . __PS_BASE_URI__ . $adminDir . '/' . ltrim($url, '/');
    }

    /**
     * Storefront home with the widget auto-opened; validated on the front side
     * exactly like PrestaShop's own product preview links.
     *
     * @return string
     */
    private function previewUrl()
    {
        $idLang = $this->integration()->getUrls()->getIdLang();

        return $this->context->link->getPageLink('index', true, $idLang, [
            'ovebotai_preview' => 1,
            'id_employee' => (int) $this->context->employee->id,
            'adtoken' => Tools::getAdminTokenLite(Ovebotai::ADMIN_CONTROLLER),
        ], false, (int) $this->context->shop->id);
    }

    /**
     * Anchor markup for the "%link%" placeholders (URL escaped, label escaped).
     *
     * @param string $url
     * @param string $label
     * @param bool $external
     *
     * @return string
     */
    private function link($url, $label, $external = true)
    {
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return $label;
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $label . '</a>';
    }

    /**
     * @return string
     */
    private function templateDir()
    {
        return $this->module->getLocalPath() . 'views/templates/admin/';
    }

    /**
     * @param string $template
     *
     * @return string
     */
    private function fetch($template)
    {
        return $this->context->smarty->fetch($this->templateDir() . $template);
    }
}
