<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Service;

use Db;
use Module;
use Ovebotai\Api\Client;
use Ovebotai\Api\Exception\AuthException;
use Ovebotai\Api\Exception\OvebotaiException;
use Ovebotai\Config\Settings;
use Ovebotai\Security\RateLimiter;
use PrestaShopLogger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Orchestrator for ONE shop's Ovebot.ai integration.
 *
 * Owns token storage/refresh and the local configuration, delegates raw
 * HTTP/OAuth to the dumb Client. One instance per request so the
 * /v1/integration/status probe is memoized (see probeConnection()).
 */
class Integration
{
    const WORKSPACE_PATTERN = '/^[a-z0-9-]+$/i';
    const REGISTER_PLAN = 'wp-freemium';

    const PENDING_TTL = 900;
    const PENDING_MAX = 5;
    const FLASH_TTL = 900;

    const PROBE_LIVE = 'live';
    const PROBE_REVOKED = 'revoked';
    const PROBE_UNREACHABLE = 'unreachable';
    const PROBE_DISCONNECTED = 'disconnected';

    /** @var Settings */
    private $settings;

    /** @var Client */
    private $client;

    /** @var Urls */
    private $urls;

    /** @var SetupPayloadBuilder */
    private $payloads;

    /** @var array|null GET /v1/integration/status body; null = not fetched, [] = failed */
    private $statusBody = null;

    /** @var string|null one of the PROBE_* constants once probed */
    private $probe = null;

    /**
     * @param int $idShop
     *
     * @return Integration
     */
    public static function forShop($idShop)
    {
        $settings = new Settings((int) $idShop);
        $module = Module::getInstanceByName('ovebotai');
        $version = $module ? (string) $module->version : '0';

        $client = new Client(
            $settings->get(Settings::ACCESS_TOKEN),
            $settings->get(Settings::ACCOUNT_HOST),
            $settings->get(Settings::API_HOST),
            sprintf('OvebotAI-PrestaShop/%s (PrestaShop %s; PHP %s)', $version, _PS_VERSION_, PHP_VERSION)
        );
        $urls = new Urls((int) $idShop);

        return new self($settings, $client, $urls, new SetupPayloadBuilder($settings, $urls));
    }

    public function __construct(Settings $settings, Client $client, Urls $urls, SetupPayloadBuilder $payloads)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->urls = $urls;
        $this->payloads = $payloads;
    }

    /**
     * @return Settings
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * @return Urls
     */
    public function getUrls()
    {
        return $this->urls;
    }

    // ── OAuth: start ─────────────────────────────────────────────────────────

    /**
     * Builds the authorize URL and stashes the PKCE verifier under the one-time
     * state, so a second Connect click doesn't kill an in-flight authorization.
     *
     * @param string $returnUrl absolute admin URL to come back to
     * @param int $idEmployee
     *
     * @return string authorize URL
     */
    public function beginAuthorization($returnUrl, $idEmployee)
    {
        $verifier = Client::generateVerifier();
        $state = Client::generateState();

        $pending = $this->prunePending($this->settings->getArray(Settings::OAUTH_PENDING));
        $pending[$state] = [
            'v' => $verifier,
            'r' => (string) $returnUrl,
            'e' => (int) $idEmployee,
            'x' => time() + self::PENDING_TTL,
        ];
        // Several in-flight authorizations are allowed, capped so the config
        // row stays small.
        if (count($pending) > self::PENDING_MAX) {
            $pending = array_slice($pending, -self::PENDING_MAX, self::PENDING_MAX, true);
        }
        $this->settings->setArray(Settings::OAUTH_PENDING, $pending);

        return $this->client->buildAuthUrl($this->urls->shopDomain(), $this->urls->oauthCallbackUrl(), $verifier, $state);
    }

    /**
     * One-shot: the entry is removed whether or not it's still valid.
     *
     * @param string $state
     *
     * @return array|null ['v' => verifier, 'r' => return url, 'e' => id_employee, 'x' => expires]
     */
    public function pullPendingState($state)
    {
        $state = (string) $state;
        if (!preg_match('/^[a-f0-9]{32}$/', $state)) {
            return null;
        }

        $all = $this->settings->getArray(Settings::OAUTH_PENDING);
        $entry = isset($all[$state]) && is_array($all[$state]) ? $all[$state] : null;
        unset($all[$state]);
        $this->settings->setArray(Settings::OAUTH_PENDING, $this->prunePending($all));

        if ($entry === null || !isset($entry['v'], $entry['r'], $entry['x']) || (int) $entry['x'] < time()) {
            return null;
        }

        return $entry;
    }

    /**
     * @param array $pending
     *
     * @return array
     */
    private function prunePending(array $pending)
    {
        $now = time();
        foreach ($pending as $state => $entry) {
            if (!is_array($entry) || !isset($entry['x']) || (int) $entry['x'] < $now) {
                unset($pending[$state]);
            }
        }

        return $pending;
    }

    // ── OAuth: return ────────────────────────────────────────────────────────

    /**
     * Exchanges the auth code for tokens and persists them. Never throws:
     * returns ['success' => true] or ['error' => '...'] for inline display.
     *
     * @param string $code
     * @param string $verifier
     *
     * @return array
     */
    public function handleCallback($code, $verifier)
    {
        try {
            $response = $this->client->exchangeCode($code, $verifier);
        } catch (OvebotaiException $e) {
            $this->log('OAuth code exchange failed: ' . $e->getMessage());

            return ['error' => $e->getMessage()];
        }

        // Read the old agent BEFORE storeTokens(): it may persist a new agent
        // right away, which would otherwise mask a real agent change.
        $previousAgent = $this->settings->get(Settings::AGENT);

        $this->storeTokens($response);
        $this->syncAgentFromMe($previousAgent);

        return ['success' => true];
    }

    /**
     * Flash for the admin page after the front-controller callback.
     *
     * @param array $result handleCallback() result
     */
    public function flashOauthResult(array $result)
    {
        $this->settings->setArray(Settings::OAUTH_RESULT, [
            'error' => isset($result['error']) ? (string) $result['error'] : '',
            'x' => time() + self::FLASH_TTL,
        ]);
    }

    /**
     * One-shot read of the OAuth flash error ('' when none).
     *
     * @return string
     */
    public function pullOauthError()
    {
        $flash = $this->settings->getArray(Settings::OAUTH_RESULT);
        if (!$flash) {
            return '';
        }
        $this->settings->delete(Settings::OAUTH_RESULT);

        if (!isset($flash['x']) || (int) $flash['x'] < time()) {
            return '';
        }

        return isset($flash['error']) ? (string) $flash['error'] : '';
    }

    /**
     * After connecting, GET /v1/me to persist the token's real bound agent
     * (the token response's `agent` is null for the default agent). Best-effort.
     *
     * @param string $previousAgent
     */
    private function syncAgentFromMe($previousAgent)
    {
        try {
            $result = $this->apiRequest('GET', '/v1/me');
        } catch (OvebotaiException $e) {
            return;
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            return;
        }

        // '' is a legit value for the default agent, so treat a MISSING `agent`
        // key (not an empty one) as "nothing usable" and bail.
        if (!array_key_exists('agent', $result['body'])) {
            return;
        }

        $slug = self::agentSlug($result['body']['agent']);

        $partial = [Settings::AGENT => $slug];

        // Landed on a different agent than the wizard last finished for: the
        // synced pages belong to the OLD agent, so force the wizard to re-run,
        // clear the page selection (empty = all checked) and drop the mirrored
        // switches so they re-sync from the new agent. The KB entries
        // themselves are reconciled by slug against the new agent's list.
        if ((string) $previousAgent !== $slug) {
            $partial[Settings::SETUP_COMPLETE] = '0';
            $partial[Settings::KB_PAGE_IDS] = [];
            $partial[Settings::PRODUCTS_RECOMMEND] = '';
            $partial[Settings::ORDER_ENABLED] = '';
        }

        $this->settings->setMany($partial);
    }

    /**
     * `agent` may be null (default agent), a string id or {public_id}.
     *
     * @param mixed $agent
     *
     * @return string '' for the default agent
     */
    private static function agentSlug($agent)
    {
        if (is_array($agent)) {
            return isset($agent['public_id']) && is_scalar($agent['public_id']) ? (string) $agent['public_id'] : '';
        }

        $slug = is_scalar($agent) ? (string) $agent : '';

        return $slug === 'default' ? '' : $slug;
    }

    // ── Authenticated API with auto-refresh ──────────────────────────────────

    /**
     * All API calls funnel here for uniform token refresh: proactive refresh if
     * near expiry, then one reactive refresh + retry on a 401.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     *
     * @return array ['status' => int, 'body' => array]
     *
     * @throws \Ovebotai\Api\Exception\ConnectionException
     */
    public function apiRequest($method, $path, $body = null)
    {
        $expires = (int) $this->settings->get(Settings::TOKEN_EXPIRES, '0');
        if ($expires && $expires <= time() + 300) {
            $this->refresh();
        }

        $result = $this->client->apiRequest($method, $path, $body);

        if ($result['status'] === 401 && $this->refresh()) {
            $result = $this->client->apiRequest($method, $path, $body);
        }

        if ($result['status'] >= 400) {
            $this->log(sprintf('%s %s -> HTTP %d %s', strtoupper($method), $path, $result['status'], $this->apiErrorMessage($result)));
        }

        return $result;
    }

    /**
     * Rotating refresh token: serialised per shop with a DB lock, and the
     * stored token is re-read once the lock is held because another process
     * may already have refreshed (a second refresh with the OLD token would
     * revoke the whole family).
     *
     * @return bool true when a usable access token is in place afterwards
     */
    private function refresh()
    {
        $refreshToken = $this->settings->get(Settings::REFRESH_TOKEN);
        if ($refreshToken === '') {
            return false;
        }

        $lock = 'ovebotai_refresh_' . $this->settings->getIdShop();
        $locked = $this->acquireLock($lock);

        try {
            if ($locked) {
                $current = $this->settings->reloadFromDb(Settings::REFRESH_TOKEN);
                if ($current !== '' && $current !== $refreshToken) {
                    // Someone else rotated the tokens while we waited: adopt theirs.
                    $this->client->setAccessToken($this->settings->reloadFromDb(Settings::ACCESS_TOKEN));
                    $this->settings->reloadFromDb(Settings::TOKEN_EXPIRES);

                    return true;
                }
                if ($current === '') {
                    return false;
                }
            }

            try {
                $response = $this->client->refreshToken($refreshToken);
            } catch (AuthException $e) {
                // The token endpoint rejected the refresh token (revoked / family
                // reuse): clear only the OAuth creds, keep workspace/agent/chat so
                // the storefront widget keeps working until the merchant reconnects.
                $this->log('Token refresh rejected: ' . $e->getMessage());
                $this->expireTokens();

                return false;
            } catch (OvebotaiException $e) {
                // Network blip / 5xx on the token endpoint: NOT a revocation. Keep
                // the tokens and let the caller see the failure.
                $this->log('Token refresh failed: ' . $e->getMessage());

                return false;
            }

            $this->storeTokens($response);

            return true;
        } finally {
            if ($locked) {
                $this->releaseLock($lock);
            }
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private function acquireLock($name)
    {
        try {
            return (string) Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($name) . '\', 10)') === '1';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $name
     */
    private function releaseLock($name)
    {
        try {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($name) . '\')');
        } catch (\Exception $e) {
            // nothing to do - the lock dies with the connection anyway
        }
    }

    /**
     * Flattens Ovebot's error body into one readable string; falls back to
     * "HTTP <status>" when the body isn't the expected shape.
     * Shapes handled: {error:{code,message,fields}}, {message}, {error:"..",
     * error_description}, {errors:{field:[msg]}}.
     *
     * @param array $result
     *
     * @return string
     */
    public function apiErrorMessage(array $result)
    {
        $body = isset($result['body']) && is_array($result['body']) ? $result['body'] : [];
        $parts = [];

        if (isset($body['error']) && is_array($body['error'])) {
            if (isset($body['error']['message']) && is_scalar($body['error']['message'])) {
                $parts[] = (string) $body['error']['message'];
            }
            if (isset($body['error']['fields']) && is_array($body['error']['fields'])) {
                foreach ($body['error']['fields'] as $messages) {
                    foreach ((array) $messages as $fieldMessage) {
                        if (is_scalar($fieldMessage)) {
                            $parts[] = (string) $fieldMessage;
                        }
                    }
                }
            }
        } elseif (isset($body['error']) && is_scalar($body['error'])) {
            $parts[] = isset($body['error_description']) && is_scalar($body['error_description'])
                ? (string) $body['error_description']
                : (string) $body['error'];
        }

        if (!$parts && isset($body['message']) && is_scalar($body['message'])) {
            $parts[] = (string) $body['message'];
        }

        if (isset($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $messages) {
                foreach ((array) $messages as $fieldMessage) {
                    if (is_scalar($fieldMessage)) {
                        $parts[] = (string) $fieldMessage;
                    }
                }
            }
        }

        $text = trim(implode(' ', array_unique($parts)));

        return $text !== '' ? $text : 'HTTP ' . (isset($result['status']) ? (int) $result['status'] : 0);
    }

    /**
     * @param array $result
     *
     * @return string error.code when present, '' otherwise
     */
    public function apiErrorCode(array $result)
    {
        return isset($result['body']['error']['code']) && is_scalar($result['body']['error']['code'])
            ? (string) $result['body']['error']['code']
            : '';
    }

    /**
     * Plan quota hit (KB entries, etc.).
     *
     * @param array $result
     *
     * @return bool
     */
    public function isQuotaError(array $result)
    {
        $status = isset($result['status']) ? (int) $result['status'] : 0;
        if (in_array($status, [402, 409, 429], true)) {
            return true;
        }
        $code = strtolower($this->apiErrorCode($result));
        if ($code !== '' && (strpos($code, 'limit') !== false || strpos($code, 'quota') !== false)) {
            return true;
        }

        return (bool) preg_match('/limit reached|quota|exceed|too many/i', $this->apiErrorMessage($result));
    }

    // ── Connection state ─────────────────────────────────────────────────────

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->settings->get(Settings::REFRESH_TOKEN) !== '' && $this->getWorkspace() !== '';
    }

    /**
     * @return bool
     */
    public function isSetupComplete()
    {
        return $this->settings->get(Settings::SETUP_COMPLETE) === '1' && $this->isConnected();
    }

    /**
     * One memoized GET /v1/integration/status per request. Only a rejected
     * refresh (revoked) drops the connection: a 5xx / network blip must NOT
     * disconnect the shop (the OpenCart module did; fixed here).
     *
     * @return string live | revoked | unreachable | disconnected
     */
    public function probeConnection()
    {
        if ($this->probe !== null) {
            return $this->probe;
        }
        if (!$this->isConnected()) {
            return $this->probe = self::PROBE_DISCONNECTED;
        }

        $this->statusBody = [];
        try {
            $result = $this->apiRequest('GET', '/v1/integration/status');
        } catch (OvebotaiException $e) {
            return $this->probe = $this->isConnected() ? self::PROBE_UNREACHABLE : self::PROBE_REVOKED;
        }

        if ($result['status'] >= 200 && $result['status'] < 300) {
            $this->statusBody = $result['body'];

            return $this->probe = self::PROBE_LIVE;
        }

        // A 401 whose refresh failed has already cleared the tokens.
        return $this->probe = $this->isConnected() ? self::PROBE_UNREACHABLE : self::PROBE_REVOKED;
    }

    /**
     * The `integration` object from the memoized status body ({products,
     * order_info, counts, ...}); [] when disconnected / fetch failed.
     *
     * @return array
     */
    public function getIntegration()
    {
        $this->probeConnection();

        return isset($this->statusBody['integration']) && is_array($this->statusBody['integration'])
            ? $this->statusBody['integration']
            : [];
    }

    /**
     * Product count on Ovebot's side (0 when unknown).
     *
     * @return int
     */
    public function getIndexedProductCount()
    {
        $integration = $this->getIntegration();

        return isset($integration['counts']['products']) ? (int) $integration['counts']['products'] : 0;
    }

    /**
     * The account is the source of truth for the two mirrored switches:
     * a change made in the Ovebot.ai account is reflected locally on the next
     * back-office load.
     */
    public function syncSettings()
    {
        if ($this->probeConnection() !== self::PROBE_LIVE) {
            return;
        }
        $integration = $this->getIntegration();

        if (array_key_exists('order_info', $integration)) {
            $remote = !empty($integration['order_info']);
            if ($remote !== $this->getOrderEnabled()) {
                $this->settings->set(Settings::ORDER_ENABLED, $remote ? '1' : '0');
            }
        }
        if (array_key_exists('products', $integration)) {
            $remote = !empty($integration['products']);
            if ($remote !== $this->getProductsRecommend()) {
                $this->settings->set(Settings::PRODUCTS_RECOMMEND, $remote ? '1' : '0');
            }
        }
    }

    /**
     * Domain / SSL / default-language changes alter the feed and order URLs
     * already registered with Ovebot.ai: re-push them when they differ from
     * the last accepted push. Best-effort.
     */
    public function selfHealEndpoints()
    {
        if (!$this->isSetupComplete() || $this->probeConnection() !== self::PROBE_LIVE) {
            return;
        }

        $last = $this->settings->getArray(Settings::LAST_PUSH);
        $payload = $this->payloads->build();
        $feedUrl = isset($payload['products']['feed_url']) ? $payload['products']['feed_url'] : '';
        $apiUrl = $payload['order_info']['api_url'];

        $lastFeed = isset($last['feed_url']) ? (string) $last['feed_url'] : '';
        $lastApi = isset($last['api_url']) ? (string) $last['api_url'] : '';

        if ($lastFeed === $feedUrl && $lastApi === $apiUrl) {
            return;
        }

        $this->resyncSetup();
    }

    /**
     * Seeds the storefront credentials for shops that don't have them yet
     * (created after install). Never overwrites existing values.
     */
    public function ensureCredentials()
    {
        if ($this->settings->get(Settings::FEED_HASH) === '') {
            $this->settings->set(Settings::FEED_HASH, Random::hex(16));
        }
        if ($this->settings->get(Settings::ORDER_USER) === '') {
            $this->settings->set(Settings::ORDER_USER, $this->generateOrderUser());
        }
        if ($this->settings->get(Settings::ORDER_PASS) === '') {
            $this->settings->set(Settings::ORDER_PASS, Random::hex(16));
        }
    }

    // ── Workspace / agent / account URLs ─────────────────────────────────────

    /**
     * Strict slug only: it is concatenated into storefront script-src hosts.
     *
     * @return string '' when missing or invalid
     */
    public function getWorkspace()
    {
        $workspace = $this->settings->get(Settings::WORKSPACE);

        return preg_match(self::WORKSPACE_PATTERN, $workspace) ? $workspace : '';
    }

    /**
     * @return string stored agent public_id, '' for the default agent
     */
    public function getAgent()
    {
        $agent = $this->settings->get(Settings::AGENT);

        return $agent === 'default' ? '' : $agent;
    }

    /**
     * @return string agent segment for API paths ('default' for the default agent)
     */
    public function getAgentForApi()
    {
        $agent = $this->getAgent();

        return $agent !== '' ? $agent : 'default';
    }

    /**
     * @return string "{workspace}:{agent|default}" for the connection badge
     */
    public function getConnectionLabel()
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? $workspace . ':' . $this->getAgentForApi() : '';
    }

    /**
     * @return string
     */
    public function getAccountUrl()
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai' : '';
    }

    /**
     * @return string
     */
    public function getProductsUrl()
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai/products' : '';
    }

    /**
     * @return string
     */
    public function getKbUrl()
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai/knowledge-base' : '';
    }

    /**
     * @return string
     */
    public function getKbCreateUrl()
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai/knowledge-base/create' : '';
    }

    /**
     * @param int $idEntry
     *
     * @return string
     */
    public function getKbEditUrl($idEntry)
    {
        $workspace = $this->getWorkspace();

        return $workspace !== '' ? 'https://' . $workspace . '.ovebot.ai/knowledge-base/' . (int) $idEntry . '/edit' : '';
    }

    /**
     * Agent settings in the account: "own feed", advanced settings, the
     * recommendation at the end of the wizard.
     *
     * @return string
     */
    public function getAgentSetupUrl()
    {
        $workspace = $this->getWorkspace();
        if ($workspace === '') {
            return '';
        }
        $agent = $this->getAgent();

        return $agent !== ''
            ? 'https://' . $workspace . '.ovebot.ai/agents/' . rawurlencode($agent) . '/setup'
            : 'https://' . $workspace . '.ovebot.ai/setup';
    }

    /**
     * "Start Free": the account register page with the PrestaShop freemium
     * plan slug and the shop's domain pre-filled.
     *
     * @return string
     */
    public function getRegisterUrl()
    {
        return $this->client->buildRegisterUrl(self::REGISTER_PLAN, $this->urls->shopDomain());
    }

    /**
     * @return string
     */
    private function setupApiPath()
    {
        return '/v1/workspaces/' . rawurlencode($this->getWorkspace()) . '/agents/' . rawurlencode($this->getAgentForApi()) . '/setup';
    }

    /**
     * @return string
     */
    public function kbApiPath()
    {
        return '/v1/workspaces/' . rawurlencode($this->getWorkspace()) . '/agents/' . rawurlencode($this->getAgentForApi()) . '/knowledge-base';
    }

    // ── /setup ───────────────────────────────────────────────────────────────

    /**
     * Pushes local config to /setup (partial update by section: widget,
     * order_info and products are always sent). Never throws.
     *
     * @param array $overrides see SetupPayloadBuilder::build()
     *
     * @return array ['success' => bool, 'error' => string, 'payload' => array]
     */
    public function resyncSetup(array $overrides = [])
    {
        $payload = $this->payloads->build($overrides);

        try {
            $result = $this->apiRequest('PUT', $this->setupApiPath(), $payload);
        } catch (OvebotaiException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'payload' => $payload];
        }

        $success = $result['status'] >= 200 && $result['status'] < 300;
        if ($success) {
            $this->settings->setArray(Settings::LAST_PUSH, [
                'feed_url' => isset($payload['products']['feed_url']) ? $payload['products']['feed_url'] : '',
                'api_url' => $payload['order_info']['api_url'],
            ]);
        }

        return ['success' => $success, 'error' => $success ? '' : $this->apiErrorMessage($result), 'payload' => $payload];
    }

    /**
     * Wizard "Finish": persists the step-3 choice, pushes the whole /setup
     * payload and only then marks the setup complete + chat on.
     *
     * @param bool $productsBuiltin
     *
     * @return array ['success' => bool, 'error' => string]
     */
    public function finish($productsBuiltin)
    {
        $this->settings->set(Settings::PRODUCTS_BUILTIN, $productsBuiltin ? '1' : '0');

        $resync = $this->resyncSetup();
        if (!$resync['success']) {
            return ['success' => false, 'error' => $resync['error']];
        }

        $payload = $resync['payload'];
        $this->settings->setMany([
            Settings::SETUP_COMPLETE => '1',
            Settings::CHAT_STATUS => '1',
            // The initial push enabled these in the account: mirror what was sent.
            Settings::ORDER_ENABLED => !empty($payload['order_info']['enabled']) ? '1' : '0',
            Settings::PRODUCTS_RECOMMEND => !empty($payload['products']['enabled']) ? '1' : '0',
        ]);

        return ['success' => true, 'error' => ''];
    }

    /**
     * Settings > Save. Local fields are persisted first; the three mirrored
     * switches are pushed to /setup and persisted ONLY when the push succeeded,
     * so a rejected write can't leave the module and the account disagreeing.
     *
     * @param bool $chatStatus
     * @param array $widget already sanitised
     * @param bool $productsBuiltin
     * @param bool $productsRecommend
     * @param bool $orderEnabled
     *
     * @return array ['status' => ok|needs_reconnect|sync_failed, 'error' => string, 'effective' => array]
     */
    public function saveSettings($chatStatus, array $widget, $productsBuiltin, $productsRecommend, $orderEnabled)
    {
        $this->settings->set(Settings::CHAT_STATUS, $chatStatus ? '1' : '0');
        $this->settings->setArray(Settings::WIDGET, $widget);

        $synced = [
            Settings::PRODUCTS_BUILTIN => $productsBuiltin ? '1' : '0',
            Settings::PRODUCTS_RECOMMEND => $productsRecommend ? '1' : '0',
            Settings::ORDER_ENABLED => $orderEnabled ? '1' : '0',
        ];

        if (!$this->isConnected()) {
            $this->settings->setMany($synced);

            return ['status' => 'needs_reconnect', 'error' => '', 'effective' => $this->effectiveSwitches()];
        }

        $resync = $this->resyncSetup([
            'products_builtin' => (bool) $productsBuiltin,
            'products_recommend' => (bool) $productsRecommend,
            'order_enabled' => (bool) $orderEnabled,
        ]);

        if ($resync['success']) {
            $this->settings->setMany($synced);

            return ['status' => 'ok', 'error' => '', 'effective' => $this->effectiveSwitches()];
        }

        return ['status' => 'sync_failed', 'error' => $resync['error'], 'effective' => $this->effectiveSwitches()];
    }

    /**
     * @return array current (persisted) values of the mirrored switches
     */
    public function effectiveSwitches()
    {
        return [
            'products_builtin' => $this->getProductsBuiltin(),
            'products_recommend' => $this->getProductsRecommend(),
            'order_enabled' => $this->getOrderEnabled(),
        ];
    }

    /**
     * New feed hash. Confirmed with Ovebot.ai FIRST when it actually reads our
     * feed (recommend && built-in); persisted locally only on success, so a
     * failed sync leaves the old (working) hash in place. When Ovebot doesn't
     * read the feed anyway it's a purely local change.
     *
     * @return array ['success' => bool, 'error' => string, 'hash' => string, 'url' => string, 'synced' => bool]
     */
    public function regenerateFeedHash()
    {
        $hash = Random::hex(16);
        $synced = false;

        if ($this->isConnected() && $this->getProductsRecommend() && $this->getProductsBuiltin()) {
            $resync = $this->resyncSetup(['feed_hash' => $hash]);
            if (!$resync['success']) {
                return ['success' => false, 'error' => $resync['error'], 'hash' => '', 'url' => '', 'synced' => false];
            }
            $synced = true;
        }

        $this->settings->set(Settings::FEED_HASH, $hash);

        return ['success' => true, 'error' => '', 'hash' => $hash, 'url' => $this->urls->feedUrl($hash), 'synced' => $synced];
    }

    /**
     * Same confirm-before-persist ordering as regenerateFeedHash(). On success
     * the rate-limit table is emptied: Ovebot's own egress IP must not stay
     * locked out because of calls made with the old credentials.
     *
     * @return array ['success' => bool, 'error' => string, 'user' => string, 'pass' => string, 'synced' => bool]
     */
    public function regenerateOrderCreds()
    {
        $user = $this->generateOrderUser();
        $pass = Random::hex(16);
        $synced = false;

        if ($this->isConnected()) {
            $resync = $this->resyncSetup(['order_user' => $user, 'order_pass' => $pass]);
            if (!$resync['success']) {
                return ['success' => false, 'error' => $resync['error'], 'user' => '', 'pass' => '', 'synced' => false];
            }
            $synced = true;
        }

        $this->settings->setMany([
            Settings::ORDER_USER => $user,
            Settings::ORDER_PASS => $pass,
        ]);
        (new RateLimiter())->clearAll();

        return ['success' => true, 'error' => '', 'user' => $user, 'pass' => $pass, 'synced' => $synced];
    }

    /**
     * @return string "{domain_slug}_{8 hex}"
     */
    private function generateOrderUser()
    {
        return $this->urls->domainSlug() . '_' . Random::hex(4);
    }

    // ── Disconnect ───────────────────────────────────────────────────────────

    /**
     * Best-effort remote revoke, then clear tokens + workspace locally. Keeps
     * setup_complete / KB selection / feed hash / order creds / agent so a
     * reconnect on the same agent goes straight back to the dashboard (and
     * syncAgentFromMe() can spot an agent change).
     */
    public function disconnect()
    {
        if ($this->settings->get(Settings::ACCESS_TOKEN) !== '' || $this->settings->get(Settings::REFRESH_TOKEN) !== '') {
            try {
                $this->apiRequest('POST', '/v1/disconnect');
            } catch (\Exception $e) {
                // Ignore: local cleanup below must happen regardless.
            }
        }

        $this->settings->setMany([
            Settings::ACCESS_TOKEN => '',
            Settings::REFRESH_TOKEN => '',
            Settings::TOKEN_EXPIRES => '',
            Settings::WORKSPACE => '',
        ]);
        $this->probe = null;
        $this->statusBody = null;
    }

    /**
     * Uninstall variant: short timeout, never throws.
     *
     * @param int $timeoutSeconds
     */
    public function disconnectQuietly($timeoutSeconds = 5)
    {
        $this->client->setTimeout($timeoutSeconds);
        try {
            $this->disconnect();
        } catch (\Throwable $e) {
            // swallow: uninstall must never be blocked by the remote side
        }
    }

    // ── Token persistence ────────────────────────────────────────────────────

    /**
     * @param array $response token endpoint body
     */
    private function storeTokens(array $response)
    {
        $partial = [
            Settings::ACCESS_TOKEN => (string) $response['access_token'],
            Settings::TOKEN_EXPIRES => (string) (time() + (isset($response['expires_in']) && (int) $response['expires_in'] > 0 ? (int) $response['expires_in'] : 3600)),
        ];

        if (!empty($response['refresh_token']) && is_string($response['refresh_token'])) {
            $partial[Settings::REFRESH_TOKEN] = $response['refresh_token'];
        }

        if (!empty($response['workspace']['slug']) && is_string($response['workspace']['slug'])
            && preg_match(self::WORKSPACE_PATTERN, $response['workspace']['slug'])) {
            $partial[Settings::WORKSPACE] = $response['workspace']['slug'];
        }

        // '' (never 'default') for the default agent; syncAgentFromMe() resolves
        // the real value right after. Only matters if the response carries an agent.
        if (array_key_exists('agent', $response)) {
            $partial[Settings::AGENT] = self::agentSlug($response['agent']);
        }

        $this->settings->setMany($partial);
        $this->client->setAccessToken($partial[Settings::ACCESS_TOKEN]);
    }

    private function expireTokens()
    {
        $this->settings->setMany([
            Settings::ACCESS_TOKEN => '',
            Settings::REFRESH_TOKEN => '',
            Settings::TOKEN_EXPIRES => '',
        ]);
        $this->client->setAccessToken('');
    }

    // ── Knowledge base (dashboard) ───────────────────────────────────────────

    /**
     * The agent's KB as Ovebot currently holds it.
     *
     * @return array ['entries' => [['id','title','is_active','edit_url'], ...], 'error' => bool]
     */
    public function getKbEntries()
    {
        if (!$this->isConnected()) {
            return ['entries' => [], 'error' => false];
        }

        $entries = [];
        $page = 1;
        $fetched = 0;

        do {
            try {
                $result = $this->apiRequest('GET', $this->kbApiPath() . '?' . http_build_query(['page' => $page, 'per_page' => 100]));
            } catch (OvebotaiException $e) {
                return ['entries' => [], 'error' => true];
            }
            if ($result['status'] < 200 || $result['status'] >= 300) {
                return ['entries' => [], 'error' => true];
            }

            $raw = isset($result['body']['entries']) && is_array($result['body']['entries']) ? $result['body']['entries'] : [];
            foreach ($raw as $entry) {
                if (empty($entry['id'])) {
                    continue;
                }
                $entries[] = [
                    'id' => (int) $entry['id'],
                    'title' => isset($entry['title']) && is_scalar($entry['title']) ? (string) $entry['title'] : '',
                    'is_active' => !empty($entry['is_active']),
                    'edit_url' => $this->getKbEditUrl((int) $entry['id']),
                ];
            }

            $total = isset($result['body']['total']) ? (int) $result['body']['total'] : 0;
            $fetched += count($raw);
            ++$page;
        } while ($raw && $fetched < $total && $page <= 50);

        return ['entries' => $entries, 'error' => false];
    }

    /**
     * @return int[]
     */
    public function getKbPageIds()
    {
        return array_values(array_filter(array_map('intval', $this->settings->getArray(Settings::KB_PAGE_IDS))));
    }

    /**
     * Persists the wizard's page selection so a re-run shows the same boxes ticked.
     *
     * @param int[] $ids
     */
    public function saveKbPageIds(array $ids)
    {
        $this->settings->setArray(Settings::KB_PAGE_IDS, array_values(array_unique(array_filter(array_map('intval', $ids)))));
    }

    // ── Local switches / credentials ─────────────────────────────────────────

    /**
     * @return bool
     */
    public function getChatStatus()
    {
        return $this->settings->getFlag(Settings::CHAT_STATUS, false);
    }

    /**
     * Whether OUR built-in feed is the source Ovebot reads. Never-set = true.
     *
     * @return bool
     */
    public function getProductsBuiltin()
    {
        return $this->settings->getFlag(Settings::PRODUCTS_BUILTIN, true);
    }

    /**
     * Local mirror of the account's products.enabled. Never-set = true.
     *
     * @return bool
     */
    public function getProductsRecommend()
    {
        return $this->settings->getFlag(Settings::PRODUCTS_RECOMMEND, true);
    }

    /**
     * Local mirror of the account's order_info.enabled. Never-set = true.
     *
     * @return bool
     */
    public function getOrderEnabled()
    {
        return $this->settings->getFlag(Settings::ORDER_ENABLED, true);
    }

    /**
     * @return string
     */
    public function getFeedUrl()
    {
        return $this->urls->feedUrl($this->settings->get(Settings::FEED_HASH));
    }

    /**
     * @return string
     */
    public function getOrdersUrl()
    {
        return $this->urls->ordersUrl();
    }

    /**
     * @return string
     */
    public function getOrderUser()
    {
        return $this->settings->get(Settings::ORDER_USER);
    }

    /**
     * @return string
     */
    public function getOrderPass()
    {
        return $this->settings->get(Settings::ORDER_PASS);
    }

    /**
     * @return array
     */
    public function getWidget()
    {
        return $this->settings->getArray(Settings::WIDGET);
    }

    // ── Logging ──────────────────────────────────────────────────────────────

    /**
     * Warning-level entry in Advanced Parameters > Logs. Callers must never
     * pass tokens, passwords or personal data.
     *
     * @param string $message
     */
    private function log($message)
    {
        try {
            PrestaShopLogger::addLog('Ovebotai: ' . $message, 2, null, 'Ovebotai', $this->settings->getIdShop(), true);
        } catch (\Exception $e) {
            // logging must never break the request
        }
    }
}
