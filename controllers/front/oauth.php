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

use Ovebotai\Service\Integration;

/**
 * OAuth return target, served on the SHOP's own domain so `site_domain` and the
 * callback host always match, and the secret admin folder never leaves the
 * server. Only ever redirects to the admin URL we stored ourselves (or the
 * shop's home page when the state is unknown / expired).
 *
 * URL: index.php?fc=module&module=ovebotai&controller=oauth&code=..&state=..
 */
class OvebotaiOauthModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function __construct()
    {
        parent::__construct();
        // No theme rendering: run() ends in displayAjax().
        $this->ajax = true;
    }

    /**
     * Must answer even while the shop is in maintenance: the merchant connects
     * before opening the shop. FrontController declares these protected on
     * every supported version.
     */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function displayAjax()
    {
        $integration = Integration::forShop((int) $this->context->shop->id);

        // One-shot state: unknown or expired -> home, never a URL from the query.
        $pending = $integration->pullPendingState((string) Tools::getValue('state'));
        if ($pending === null) {
            Tools::redirect($this->context->link->getPageLink('index', true));

            return;
        }

        $error = (string) Tools::getValue('error');
        $code = (string) Tools::getValue('code');

        if ($error !== '' || $code === '') {
            $description = (string) Tools::getValue('error_description');
            if ($description === '') {
                $description = $error !== ''
                    ? $error
                    : $this->trans('Authorization was cancelled.', [], 'Modules.Ovebotai.Admin');
            }
            $integration->flashOauthResult(['error' => $description]);
        } else {
            $integration->flashOauthResult($integration->handleCallback($code, (string) $pending['v']));
        }

        // Absolute admin URL stored by beginAuthorization(); sanity-checked to
        // be http(s) before it is used as a redirect target.
        $returnUrl = (string) $pending['r'];
        if (!preg_match('#^https?://#i', $returnUrl)) {
            $returnUrl = $this->context->link->getPageLink('index', true);
        }

        Tools::redirect($returnUrl);
    }
}
