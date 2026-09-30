<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Api;

use Ovebotai\Api\Exception\AuthException;
use Ovebotai\Api\Exception\ConnectionException;
use Ovebotai\Service\Random;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Thin transport for Ovebot.ai: pure HTTP, no knowledge of where tokens are
 * stored. Holds one access token in memory and speaks the account host (OAuth)
 * and the api host (/v1/*). Token lifecycle lives in Service\Integration.
 */
class Client
{
    const DEFAULT_ACCOUNT_HOST = 'account.ovebot.ai';
    const DEFAULT_API_HOST = 'api.ovebot.ai';

    /** Same scopes as the WordPress / OpenCart / Shopify integrations, so grants match across platforms. */
    const SCOPES = 'workspaces:read setup:widget:write setup:products:write setup:order-info:write kb:write';

    /** @var string */
    private $accountHost;

    /** @var string */
    private $apiHost;

    /** @var string */
    private $accessToken;

    /** @var string */
    private $userAgent;

    /** @var int total request timeout, seconds */
    private $timeout = 30;

    /**
     * @param string $accessToken
     * @param string $accountHost '' = default
     * @param string $apiHost '' = default
     * @param string $userAgent
     */
    public function __construct($accessToken, $accountHost, $apiHost, $userAgent)
    {
        $this->accessToken = (string) $accessToken;
        $this->accountHost = self::sanitizeHost($accountHost, self::DEFAULT_ACCOUNT_HOST);
        $this->apiHost = self::sanitizeHost($apiHost, self::DEFAULT_API_HOST);
        $this->userAgent = (string) $userAgent;
    }

    /**
     * @param string $token
     *
     * @return $this
     */
    public function setAccessToken($token)
    {
        $this->accessToken = (string) $token;

        return $this;
    }

    /**
     * @param int $seconds
     *
     * @return $this
     */
    public function setTimeout($seconds)
    {
        $this->timeout = max(1, (int) $seconds);

        return $this;
    }

    /**
     * @return string
     */
    public function getAccountHost()
    {
        return $this->accountHost;
    }

    // ── PKCE / authorization URL ─────────────────────────────────────────────

    /**
     * Random PKCE code_verifier (base64url of 48 random bytes = 64 chars).
     *
     * @return string
     */
    public static function generateVerifier()
    {
        return self::b64url(Random::bytes(48));
    }

    /**
     * One-time OAuth state (32 hex chars).
     *
     * @return string
     */
    public static function generateState()
    {
        return Random::hex(16);
    }

    /**
     * @param string $siteDomain
     * @param string $callbackUrl
     * @param string $verifier
     * @param string $state
     *
     * @return string
     */
    public function buildAuthUrl($siteDomain, $callbackUrl, $verifier, $state)
    {
        return 'https://' . $this->accountHost . '/oauth/authorize?' . http_build_query([
            'site_domain' => $siteDomain,
            'callback_url' => $callbackUrl,
            'scopes' => self::SCOPES,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'state' => $state,
        ]);
    }

    /**
     * "Start Free" target on the account host.
     *
     * @param string $plan
     * @param string $domain
     *
     * @return string
     */
    public function buildRegisterUrl($plan, $domain)
    {
        return 'https://' . $this->accountHost . '/register?' . http_build_query([
            'plan' => $plan,
            'domain' => $domain,
        ]);
    }

    // ── Token endpoints ──────────────────────────────────────────────────────

    /**
     * Returns the decoded token payload (access_token, refresh_token, workspace,
     * agent, ...). Throws AuthException on failure: nothing to return without tokens.
     *
     * @param string $code
     * @param string $verifier
     *
     * @return array
     *
     * @throws AuthException
     * @throws ConnectionException
     */
    public function exchangeCode($code, $verifier)
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => (string) $code,
            'code_verifier' => (string) $verifier,
        ]);
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     *
     * @throws AuthException
     * @throws ConnectionException
     */
    public function refreshToken($refreshToken)
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $refreshToken,
        ]);
    }

    /**
     * @param array $payload
     *
     * @return array
     *
     * @throws AuthException
     * @throws ConnectionException
     */
    private function tokenRequest(array $payload)
    {
        $result = $this->request('POST', 'https://' . $this->accountHost . '/oauth/token', [], json_encode($payload));

        if ($result['status'] !== 200 || empty($result['body']['access_token'])) {
            $body = $result['body'];
            // RFC 6749 error shape: {error, error_description}
            $message = isset($body['error_description']) && is_string($body['error_description']) ? $body['error_description'] : '';
            if ($message === '' && isset($body['error']) && is_string($body['error'])) {
                $message = $body['error'];
            }
            if ($message === '') {
                $message = 'Token request failed (HTTP ' . (int) $result['status'] . ').';
            }

            throw new AuthException($message, (int) $result['status']);
        }

        return $result['body'];
    }

    // ── Authenticated API ────────────────────────────────────────────────────

    /**
     * One round trip against the API host with the current token. No
     * refresh/retry here: that's the Integration's job (so it can persist
     * rotated tokens).
     *
     * @param string $method
     * @param string $path e.g. '/v1/me'
     * @param array|null $body JSON-encoded when not null
     *
     * @return array ['status' => int, 'body' => array]
     *
     * @throws ConnectionException
     */
    public function apiRequest($method, $path, $body = null)
    {
        return $this->request(
            $method,
            'https://' . $this->apiHost . $path,
            ['Authorization: Bearer ' . $this->accessToken],
            $body !== null ? json_encode($body) : null
        );
    }

    // ── Low-level HTTP ───────────────────────────────────────────────────────

    /**
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string|null $payload
     *
     * @return array ['status' => int, 'body' => array]
     *
     * @throws ConnectionException on transport failure
     */
    private function request($method, $url, array $headers, $payload = null)
    {
        $headers[] = 'Accept: application/json';
        if ($this->userAgent !== '') {
            $headers[] = 'User-Agent: ' . $this->userAgent;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            return $this->viaCurl($method, $url, $headers, $payload);
        }

        return $this->viaStream($method, $url, $headers, $payload);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string|null $payload
     *
     * @return array
     *
     * @throws ConnectionException
     */
    private function viaCurl($method, $url, array $headers, $payload)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new ConnectionException('Ovebot.ai connection error: ' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => self::decode($raw)];
    }

    /**
     * Fallback for hosts without ext-curl: stream context + ignore_errors so
     * 4xx/5xx bodies are still readable; the status is parsed from the
     * response headers.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string|null $payload
     *
     * @return array
     *
     * @throws ConnectionException
     */
    private function viaStream($method, $url, array $headers, $payload)
    {
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'follow_location' => 0,
            ],
        ];
        if ($payload !== null) {
            $options['http']['content'] = $payload;
        }

        $context = stream_context_create($options);
        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            $error = error_get_last();

            throw new ConnectionException('Ovebot.ai connection error: ' . (isset($error['message']) ? $error['message'] : 'request failed'));
        }

        $status = 0;
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => self::decode($raw)];
    }

    /**
     * @param string $raw
     *
     * @return array
     */
    private static function decode($raw)
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Host overrides come from configuration (dev/staging only); only a bare
     * hostname is accepted so nothing else can be injected into the URL.
     *
     * @param string $host
     * @param string $default
     *
     * @return string
     */
    private static function sanitizeHost($host, $default)
    {
        $host = trim((string) $host);
        if ($host !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{2,5})?$/i', $host)) {
            return $host;
        }

        return $default;
    }

    /**
     * @param string $bin
     *
     * @return string
     */
    private static function b64url($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
