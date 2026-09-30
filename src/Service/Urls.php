<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Service;

use Configuration;
use Context;
use Currency;
use Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every public URL the integration hands to Ovebot.ai or shows in the back
 * office, for ONE shop: feed, order lookup, OAuth callback, CMS pages.
 */
class Urls
{
    /** @var int */
    private $idShop;

    /** @var int */
    private $idShopGroup;

    /** @var int */
    private $idLang;

    /**
     * @param int $idShop
     */
    public function __construct($idShop)
    {
        $this->idShop = (int) $idShop;
        $this->idShopGroup = (int) Shop::getGroupFromShop($this->idShop, true);
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, $this->idShopGroup, $this->idShop);
    }

    /**
     * @return int
     */
    public function getIdShop()
    {
        return $this->idShop;
    }

    /**
     * The shop's default language: the feed and the KB use it (D11). Order
     * statuses are the exception: the lookup returns them in the language the
     * order was placed in (orders.id_lang), i.e. the customer's own language.
     *
     * @return int
     */
    public function getIdLang()
    {
        return $this->idLang;
    }

    /**
     * Non-friendly form on purpose: it keeps working when the merchant toggles
     * friendly URLs, so the URL already registered with Ovebot.ai never goes dead.
     *
     * @param string $hash
     *
     * @return string
     */
    public function feedUrl($hash)
    {
        return $this->moduleUrl('feed', ['hash' => (string) $hash]);
    }

    /**
     * @return string
     */
    public function ordersUrl()
    {
        return $this->moduleUrl('orders');
    }

    /**
     * OAuth return target, on the SHOP's own domain (never the admin folder).
     *
     * @return string
     */
    public function oauthCallbackUrl()
    {
        return $this->moduleUrl('oauth');
    }

    /**
     * Host of the shop's main URL = OAuth site_domain = host of callback_url.
     *
     * @return string
     */
    public function shopDomain()
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * "{domain_slug}" part of the generated order-lookup user name.
     *
     * @return string
     */
    public function domainSlug()
    {
        $host = preg_replace('/^www\./i', '', $this->shopDomain());
        $slug = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $host)), '_');

        return $slug !== '' ? $slug : 'shop';
    }

    /**
     * ISO code of the shop's default currency (products.currency in /setup).
     *
     * @return string
     */
    public function defaultCurrencyIso()
    {
        $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, $this->idShopGroup, $this->idShop);
        $currency = new Currency($idCurrency);

        return (string) $currency->iso_code;
    }

    /**
     * Public URL of a CMS page (KB source_url + "View page" in the wizard).
     *
     * @param int $idCms
     * @param string|null $linkRewrite
     *
     * @return string
     */
    public function cmsUrl($idCms, $linkRewrite = null)
    {
        return Context::getContext()->link->getCMSLink((int) $idCms, $linkRewrite, $this->useSsl(), $this->idLang, $this->idShop);
    }

    /**
     * Shop base URL with trailing slash: scheme://domain/physical_uri/virtual_uri/
     *
     * @return string
     */
    public function baseUrl()
    {
        return (string) Context::getContext()->link->getBaseLink($this->idShop, $this->useSsl());
    }

    /**
     * @return bool
     */
    public function useSsl()
    {
        return (bool) Configuration::get('PS_SSL_ENABLED', null, $this->idShopGroup, $this->idShop);
    }

    /**
     * @param string $controller
     * @param array $params
     *
     * @return string
     */
    private function moduleUrl($controller, array $params = [])
    {
        return $this->baseUrl() . 'index.php?' . http_build_query(array_merge([
            'fc' => 'module',
            'module' => 'ovebotai',
            'controller' => $controller,
            'id_lang' => $this->idLang,
        ], $params));
    }
}
