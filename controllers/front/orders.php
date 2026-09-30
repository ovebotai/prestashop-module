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
use Ovebotai\Http\JsonResponder;
use Ovebotai\Order\OrderIdentifier;
use Ovebotai\Order\OrderLookup;
use Ovebotai\Security\BasicAuth;
use Ovebotai\Security\RateLimiter;

/**
 * Order lookup for the agent ("Where is my order?").
 *
 * POST index.php?fc=module&module=ovebotai&controller=orders&id_lang=N
 * HTTP Basic (api_user:api_password), body JSON or form:
 *   { "id": "123" | "XKBKNABJK", "email": "..." }  or  { "id": ..., "phone": "..." }
 * Exactly one of email / phone.
 *
 * Responses: {"success":true,"data":{...}} / {"success":false,"error":"..."}
 */
class OvebotaiOrdersModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
    }

    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function displayAjax()
    {
        $settings = new Settings((int) $this->context->shop->id);

        // Feature gates come BEFORE auth and answer 403, not 401, so Ovebot reads
        // "off" rather than "bad credentials" and just says "order not found".
        if (!$settings->getFlag(Settings::CHAT_STATUS, false) || !$settings->getFlag(Settings::ORDER_ENABLED, true)) {
            JsonResponder::send(403, ['success' => false, 'error' => 'Forbidden']);

            return;
        }

        $limiter = new RateLimiter();
        $ip = (string) Tools::getRemoteAddr();
        if ($limiter->isBlocked($ip)) {
            JsonResponder::send(429, ['success' => false, 'error' => 'Too many failed attempts.'], ['Retry-After: 3600']);

            return;
        }

        if (!BasicAuth::matches($settings->get(Settings::ORDER_USER), $settings->get(Settings::ORDER_PASS))) {
            $limiter->recordFailure($ip);
            JsonResponder::send(401, ['success' => false, 'error' => 'Unauthorized'], ['WWW-Authenticate: Basic realm="Ovebot.ai"']);

            return;
        }
        $limiter->reset($ip);

        $input = $this->input();
        $identifier = OrderIdentifier::parse(isset($input['id']) ? $input['id'] : '');
        $email = isset($input['email']) && is_scalar($input['email']) ? trim((string) $input['email']) : '';
        $phone = isset($input['phone']) && is_scalar($input['phone']) ? trim((string) $input['phone']) : '';

        // Exactly one of email/phone - never both, never neither.
        if ($identifier === null || (($email === '') === ($phone === ''))) {
            JsonResponder::send(400, ['success' => false, 'error' => 'Invalid request.']);

            return;
        }
        if ($email !== '' && !Validate::isEmail($email)) {
            JsonResponder::send(400, ['success' => false, 'error' => 'Invalid email.']);

            return;
        }
        $phoneCore = $phone !== '' ? OrderIdentifier::phoneCore($phone) : '';
        if ($phone !== '' && $phoneCore === '') {
            JsonResponder::send(400, ['success' => false, 'error' => 'Invalid phone.']);

            return;
        }

        $lookup = new OrderLookup((int) $this->context->shop->id, $settings);
        $data = $email !== ''
            ? $lookup->find($identifier, 'email', $email)
            : $lookup->find($identifier, 'phone', $phoneCore);

        JsonResponder::send(200, $data ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => 'Order not found.']);
    }

    /**
     * JSON body first, form fields as fallback.
     *
     * @return array
     */
    private function input()
    {
        $raw = Tools::file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'id' => Tools::getValue('id'),
            'email' => Tools::getValue('email'),
            'phone' => Tools::getValue('phone'),
        ];
    }
}
