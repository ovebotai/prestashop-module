<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Service;

use Ovebotai\Config\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the body of PUT /v1/workspaces/{ws}/agents/{agent}/setup.
 *
 * $overrides swaps values in WITHOUT persisting them first, so a rejected write
 * keeps the old (still working) config: a regenerated feed hash / credentials
 * or a flipped switch are only stored once Ovebot.ai accepted them.
 *
 * Supported override keys: products_recommend, products_builtin, order_enabled
 * (bool), feed_hash, order_user, order_pass (string).
 */
class SetupPayloadBuilder
{
    /** @var Settings */
    private $settings;

    /** @var Urls */
    private $urls;

    public function __construct(Settings $settings, Urls $urls)
    {
        $this->settings = $settings;
        $this->urls = $urls;
    }

    /**
     * @param array $o overrides
     *
     * @return array
     */
    public function build(array $o = [])
    {
        $widget = $this->settings->getArray(Settings::WIDGET);
        // /setup's widget section only takes `language`, and a widget object
        // without it is a 422. The rest of the widget config is storefront-only.
        $language = isset($widget['language']) && $widget['language'] !== '' ? (string) $widget['language'] : 'auto';

        $recommend = $this->pick($o, 'products_recommend', Settings::PRODUCTS_RECOMMEND);
        $builtin = $this->pick($o, 'products_builtin', Settings::PRODUCTS_BUILTIN);
        $orders = $this->pick($o, 'order_enabled', Settings::ORDER_ENABLED);

        // products.enabled mirrors the account's recommendation master and is
        // always sent. A non-empty feed_url makes the account re-import the
        // feed and flip products.enabled back ON, so feed_url + currency only
        // go out while recommendations are on AND our own feed is the source.
        // With "own feed" we send `enabled` alone and the API keeps the
        // merchant's feed_url.
        $products = ['enabled' => $recommend];
        if ($recommend && $builtin) {
            $hash = array_key_exists('feed_hash', $o) ? (string) $o['feed_hash'] : $this->settings->get(Settings::FEED_HASH);
            $products['feed_url'] = $this->urls->feedUrl($hash);
            $products['currency'] = $this->urls->defaultCurrencyIso();
        }

        return [
            'widget' => ['language' => $language],
            // The API validates the whole order_info section: always send it
            // complete (api_url + lookup_method are mandatory). lookup_method is
            // always `email`, even though the endpoint also accepts a phone.
            'order_info' => [
                'enabled' => $orders,
                'api_url' => $this->urls->ordersUrl(),
                'api_user' => array_key_exists('order_user', $o) ? (string) $o['order_user'] : $this->settings->get(Settings::ORDER_USER),
                'api_password' => array_key_exists('order_pass', $o) ? (string) $o['order_pass'] : $this->settings->get(Settings::ORDER_PASS),
                'lookup_method' => 'email',
            ],
            'products' => $products,
        ];
    }

    /**
     * @param array $o
     * @param string $name
     * @param string $key
     *
     * @return bool
     */
    private function pick(array $o, $name, $key)
    {
        return array_key_exists($name, $o) ? (bool) $o[$name] : $this->settings->getFlag($key, true);
    }
}
