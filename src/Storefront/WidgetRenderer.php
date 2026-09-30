<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Storefront;

use Context;
use Currency;
use Db;
use FrontController;
use Media;
use Ovebotai\Config\Settings;
use Ovebotai\Config\WidgetSettings;
use Tab;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Storefront side: the chat widget on every page and the purchase event on
 * the order confirmation page (hook actionFrontControllerSetMedia).
 *
 * Output goes through Media::addJsDef + registerJavascript (theme-independent,
 * Addons-compliant): `var ovebot_ai = [["chat",{...}],["purchase",{...}]]` is
 * the same queue chat-loader.js / event.js consume on the other platforms.
 */
class WidgetRenderer
{
    const ADMIN_CONTROLLER = 'AdminOvebotai';
    const COOKIE_TRACKED = 'ovebotai_tracked';
    const COOKIE_MAX_IDS = 10;

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function register()
    {
        $controller = $this->context->controller;
        // Only real pages: the module's own JSON endpoints (and any other ajax
        // controller) never render a layout, so there is nothing to inject.
        if (!$controller instanceof FrontController || !empty($controller->ajax)) {
            return;
        }

        $settings = new Settings((int) $this->context->shop->id);
        $workspace = $settings->get(Settings::WORKSPACE);

        // Same master switch for widget and purchase event: no chat, no
        // Ovebot scripts at all. The workspace goes into a script-src host, so
        // it is re-validated here too.
        if (!$settings->getFlag(Settings::CHAT_STATUS, false)
            || $settings->get(Settings::SETUP_COMPLETE) !== '1'
            || !preg_match('/^[a-z0-9-]+$/i', $workspace)) {
            return;
        }

        $queue = [['chat', (object) $this->chatOptions($settings)]];
        $purchases = $this->purchases($controller);
        foreach ($purchases as $purchase) {
            $queue[] = ['purchase', (object) $purchase];
        }

        Media::addJsDef(['ovebot_ai' => $queue]);

        $base = 'https://' . $workspace . '.ovebot.ai/widget/';
        $controller->registerJavascript('ovebotai-chat-loader', $base . 'chat-loader.js', [
            'server' => 'remote',
            'position' => 'bottom',
            'priority' => 200,
            'attributes' => 'async',
        ]);
        if ($purchases) {
            $controller->registerJavascript('ovebotai-event', $base . 'event.js', [
                'server' => 'remote',
                'position' => 'bottom',
                'priority' => 201,
            ]);
        }
    }

    /**
     * Only the parameters the settings form collects are forwarded; empty
     * values are left to chat-loader's own defaults.
     *
     * @param Settings $settings
     *
     * @return array
     */
    private function chatOptions(Settings $settings)
    {
        $widget = $settings->getArray(Settings::WIDGET);
        $options = [];

        foreach (WidgetSettings::STRING_KEYS as $key) {
            if (isset($widget[$key]) && is_scalar($widget[$key]) && (string) $widget[$key] !== '') {
                $options[$key] = (string) $widget[$key];
            }
        }
        foreach (WidgetSettings::INT_KEYS as $key) {
            if (isset($widget[$key]) && is_scalar($widget[$key]) && (string) $widget[$key] !== '' && is_numeric($widget[$key])) {
                $options[$key] = (int) $widget[$key];
            }
        }

        // Only the workspace's default agent has no public_id: nothing to send
        // there, chat-loader falls back to the default agent on its own.
        $agent = $settings->get(Settings::AGENT);
        if ($agent !== '' && $agent !== 'default') {
            $options['agent'] = $agent;
        }

        if ($this->isAdminPreview()) {
            $options['auto_open'] = 'true';
        }

        return $options;
    }

    /**
     * "Chat with the AI agent" from the back office opens the storefront with
     * ?ovebotai_preview=1&id_employee=..&adtoken=... The token is validated the
     * same way PrestaShop validates the preview of an inactive product, so a
     * guessed query string can't force-open the widget for public visitors.
     *
     * @return bool
     */
    private function isAdminPreview()
    {
        if ((string) Tools::getValue('ovebotai_preview') !== '1') {
            return false;
        }
        $idEmployee = (int) Tools::getValue('id_employee');
        $adtoken = (string) Tools::getValue('adtoken');
        if ($idEmployee <= 0 || $adtoken === '') {
            return false;
        }

        // PrestaShop 9: admin tokens are Symfony CSRF tokens, validated by a
        // dedicated service that also understands the legacy hash.
        $validatorClass = 'PrestaShopBundle\\Security\\Admin\\LegacyAdminTokenValidator';
        if (class_exists($validatorClass)) {
            try {
                $controller = $this->context->controller;
                $container = method_exists($controller, 'getContainer') ? $controller->getContainer() : null;
                if ($container && $container->has($validatorClass)) {
                    return (bool) $container->get($validatorClass)->isTokenValid($idEmployee, $adtoken);
                }
            } catch (\Throwable $e) {
                // fall through to the legacy comparison
            }
        }

        $expected = Tools::getAdminToken(self::ADMIN_CONTROLLER . (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER) . $idEmployee);

        return is_string($expected) && $expected !== '' && hash_equals($expected, $adtoken);
    }

    /**
     * Purchase events for the order-confirmation page: every order created
     * from the cart (multi-carrier carts produce several), validated against
     * the cart's secure key and the logged-in customer, and sent once per
     * browser (a refresh must not double-count).
     *
     * @param FrontController $controller
     *
     * @return array
     */
    private function purchases(FrontController $controller)
    {
        if (!isset($controller->php_self) || $controller->php_self !== 'order-confirmation') {
            return [];
        }

        $idCart = (int) Tools::getValue('id_cart');
        $key = (string) Tools::getValue('key');
        $idCustomer = isset($this->context->customer->id) ? (int) $this->context->customer->id : 0;
        if ($idCart <= 0 || $key === '' || $idCustomer <= 0) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT o.id_order, o.total_paid_tax_incl, o.id_currency, o.secure_key, o.id_customer
             FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE o.id_cart = ' . (int) $idCart . ' AND o.id_shop = ' . (int) $this->context->shop->id . '
             ORDER BY o.id_order ASC'
        );
        if (!$rows) {
            return [];
        }

        $tracked = $this->trackedIds();
        $purchases = [];
        $newIds = [];

        foreach ($rows as $row) {
            if ((int) $row['id_customer'] !== $idCustomer || !hash_equals((string) $row['secure_key'], $key)) {
                continue;
            }
            $idOrder = (int) $row['id_order'];
            if (in_array($idOrder, $tracked, true)) {
                continue;
            }
            $currency = new Currency((int) $row['id_currency']);
            $purchases[] = [
                'transaction_id' => $idOrder,
                'total' => round((float) $row['total_paid_tax_incl'], 2),
                'currency' => (string) $currency->iso_code,
            ];
            $newIds[] = $idOrder;
        }

        if ($newIds) {
            $this->rememberTracked(array_merge($tracked, $newIds));
        }

        return $purchases;
    }

    /**
     * @return int[]
     */
    private function trackedIds()
    {
        $cookie = $this->context->cookie;
        $raw = isset($cookie->{self::COOKIE_TRACKED}) ? (string) $cookie->{self::COOKIE_TRACKED} : '';

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /**
     * @param int[] $ids
     */
    private function rememberTracked(array $ids)
    {
        $ids = array_slice(array_values(array_unique($ids)), -self::COOKIE_MAX_IDS);
        try {
            $this->context->cookie->{self::COOKIE_TRACKED} = implode(',', $ids);
        } catch (\Exception $e) {
            // cookie write failures must not affect the page
        }
    }
}
