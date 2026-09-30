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

use Ovebotai\Config\Settings;
use Ovebotai\Feed\ProductFeedBuilder;
use Ovebotai\Http\JsonResponder;

/**
 * Product feed read periodically by Ovebot.ai.
 *
 * URL: index.php?fc=module&module=ovebotai&controller=feed&id_lang=N&hash=XXX
 */
class OvebotaiFeedModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
    }

    /**
     * The feed must keep working while the shop is in maintenance (catalog
     * imports on Ovebot's side run on a schedule).
     */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function displayAjax()
    {
        $settings = new Settings((int) $this->context->shop->id);
        $expected = $settings->get(Settings::FEED_HASH);
        $given = (string) Tools::getValue('hash');

        // Chat is the module's master on/off; "own feed" and "recommend off"
        // both mean Ovebot must not read our catalog. Same 403 for all of them
        // (403, not 401, reads as "disabled" on Ovebot's side).
        if (!$settings->getFlag(Settings::CHAT_STATUS, false)
            || !$settings->getFlag(Settings::PRODUCTS_BUILTIN, true)
            || !$settings->getFlag(Settings::PRODUCTS_RECOMMEND, true)
            || $expected === ''
            || !hash_equals($expected, $given)) {
            JsonResponder::send(403, ['error' => 'Forbidden']);

            return;
        }

        @set_time_limit(300);

        JsonResponder::streamArray((new ProductFeedBuilder($this->context, (int) $this->context->shop->id))->iterate());
    }
}
