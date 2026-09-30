<?php
/**
 * Lightweight unit tests for the ovebotai module's pure classes, run with
 * stubs standing in for the PrestaShop core (no database, no PrestaShop).
 *
 *   php tests/run.php   (from the module folder; needs only PHP 7.1+, no PrestaShop install)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('_PS_VERSION_', '9.0.1');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');
define('_PS_USE_SQL_SLAVE_', 0);

// ── PrestaShop stubs ─────────────────────────────────────────────────────────

class Configuration
{
    public static $data = [];

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return array_key_exists($key, self::$data) ? self::$data[$key] : $default;
    }

    public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        return array_key_exists($key, self::$data);
    }

    public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null)
    {
        $value = is_array($values) ? reset($values) : $values;
        // Mimic pSQL(): strip tags + nl2br on the way in.
        self::$data[$key] = strip_tags(nl2br((string) $value));

        return true;
    }

    public static function set($key, $values, $idShopGroup = null, $idShop = null)
    {
        self::$data[$key] = is_array($values) ? reset($values) : $values;
    }

    public static function getGlobalValue($key, $idLang = null)
    {
        return self::get($key);
    }

    public static function deleteByName($key)
    {
        unset(self::$data[$key]);

        return true;
    }
}

class Shop
{
    const CONTEXT_SHOP = 1;
    public static $multistore = false;
    public $id = 1;
    public $name = 'Test shop';

    public static function isFeatureActive() { return self::$multistore; }
    public static function getGroupFromShop($id, $asId = true) { return 1; }
    public static function getContext() { return self::CONTEXT_SHOP; }
    public static function getShops($active = true, $group = null, $asList = false) { return $asList ? [1] : [['id_shop' => 1, 'name' => 'Test shop']]; }
}

class Link
{
    public function getBaseLink($idShop = null, $ssl = null, $rel = false)
    {
        return ($ssl ? 'https' : 'http') . '://www.shop-test.ro/';
    }

    public function getCMSLink($cms, $alias = null, $ssl = null, $idLang = null, $idShop = null)
    {
        return 'https://www.shop-test.ro/content/' . (int) $cms . '-' . $alias;
    }
}

class Translator
{
    public function trans($id, array $params = [], $domain = null, $locale = null)
    {
        return strtr($id, $params);
    }
}

class Context
{
    public static $instance;
    public $shop;
    public $link;
    public $controller;
    public $cookie;
    public $customer;
    public $employee;

    public static function getContext()
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->shop = new Shop();
            self::$instance->link = new Link();
        }

        return self::$instance;
    }

    public function getTranslator() { return new Translator(); }
}

class Currency
{
    public $iso_code = 'RON';
    public function __construct($id) {}
}

class Tools
{
    public static function strtolower($s) { return mb_strtolower((string) $s, 'UTF-8'); }
    public static function strtoupper($s) { return mb_strtoupper((string) $s, 'UTF-8'); }
    public static function substr($s, $a, $b = null) { return $b === null ? mb_substr($s, $a, null, 'UTF-8') : mb_substr($s, $a, $b, 'UTF-8'); }
    public static function getValue($k, $d = false) { return isset($_POST[$k]) ? $_POST[$k] : (isset($_GET[$k]) ? $_GET[$k] : $d); }
}

class Module
{
    public $version = '1.0.0';
    public static function getInstanceByName($n) { return new self(); }
    public static function isInstalled($n) { return in_array($n, Db::$installedModules, true); }
    public static function isEnabled($n) { return in_array($n, Db::$installedModules, true); }
}

class PrestaShopLogger
{
    public static $logs = [];
    public static function addLog($m, $sev = 1, $code = null, $type = null, $id = null, $dup = false, $emp = null) { self::$logs[] = $m; }
}

class Hook
{
    public static $callback;
    public static function exec($name, $args = [], $idModule = null, $arrayReturn = false)
    {
        if (self::$callback) {
            call_user_func_array(self::$callback, [$name, $args]);
        }
    }
}

class DbQuery
{
    public $parts = ['select' => [], 'from' => '', 'join' => [], 'where' => [], 'order' => ''];
    public function select($s) { $this->parts['select'][] = $s; return $this; }
    public function from($t, $a = null) { $this->parts['from'] = $t . ' ' . $a; return $this; }
    public function innerJoin($t, $a, $on) { $this->parts['join'][] = 'INNER ' . $t . ' ' . $a . ' ON ' . $on; return $this; }
    public function leftJoin($t, $a, $on) { $this->parts['join'][] = 'LEFT ' . $t . ' ' . $a . ' ON ' . $on; return $this; }
    public function where($w) { $this->parts['where'][] = $w; return $this; }
    public function orderBy($o) { $this->parts['order'] = $o; return $this; }
    public function __toString() { return 'SELECT ' . implode(', ', $this->parts['select']) . ' FROM ' . $this->parts['from'] . ' ' . implode(' ', $this->parts['join']) . ' WHERE (' . implode(') AND (', $this->parts['where']) . ') ORDER BY ' . $this->parts['order']; }
}

class Db
{
    public static $instance;
    public static $installedModules = [];
    /** @var array list of [regex, result] for getRow / getValue / executeS */
    public static $answers = [];
    public static $queries = [];

    public static function getInstance($slave = false) { return self::$instance ?: (self::$instance = new self()); }

    private function answer($sql, $default)
    {
        self::$queries[] = (string) $sql;
        foreach (self::$answers as $pair) {
            if (preg_match($pair[0], (string) $sql)) {
                return is_callable($pair[1]) ? call_user_func($pair[1], (string) $sql) : $pair[1];
            }
        }

        return $default;
    }

    public function getValue($sql) { return $this->answer($sql, false); }
    public function getRow($sql) { return $this->answer($sql, false); }
    public function executeS($sql) { return $this->answer($sql, []); }
    public function execute($sql) { self::$queries[] = (string) $sql; return true; }
}

function pSQL($s, $html = false) { return addslashes((string) $s); }
function bqSQL($s) { return str_replace('`', '\\`', (string) $s); }

require __DIR__ . '/../vendor/autoload.php';

use Ovebotai\Api\Client;
use Ovebotai\Api\Exception\ApiException;
use Ovebotai\Api\Exception\AuthException;
use Ovebotai\Api\Exception\ConnectionException;
use Ovebotai\Config\Settings;
use Ovebotai\Config\WidgetSettings;
use Ovebotai\KnowledgeBase\CmsPageProvider;
use Ovebotai\KnowledgeBase\KbSync;
use Ovebotai\Order\OrderIdentifier;
use Ovebotai\Order\OrderLookup;
use Ovebotai\Security\BasicAuth;
use Ovebotai\Service\Integration;
use Ovebotai\Service\SetupPayloadBuilder;
use Ovebotai\Service\Text;
use Ovebotai\Service\Urls;
use Ovebotai\Tracking\TrackingResolver;

// ── Tiny assertion helpers ───────────────────────────────────────────────────

$passed = 0;
$failed = 0;
function check($label, $cond, $extra = '')
{
    global $passed, $failed;
    if ($cond) {
        ++$passed;
    } else {
        ++$failed;
        echo "FAIL: $label", $extra !== '' ? " -- $extra" : '', "\n";
    }
}
function same($label, $expected, $actual)
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
}
function resetState()
{
    Configuration::$data = [
        'PS_SHOP_DEFAULT' => '1',
        'PS_LANG_DEFAULT' => '2',
        'PS_CURRENCY_DEFAULT' => '1',
        'PS_SSL_ENABLED' => '1',
        'PS_SHOP_NAME' => 'Test shop',
    ];
    Db::$answers = [];
    Db::$queries = [];
    Db::$installedModules = [];
    PrestaShopLogger::$logs = [];
    Hook::$callback = null;
}

// Scripted client: records calls, returns canned responses.
class FakeClient extends Client
{
    public $calls = [];
    public $responses = [];     // "METHOD path" => ['status'=>..,'body'=>..] or list of them
    public $refreshResponse;    // array | Exception
    public $exchangeResponse;   // array | Exception
    public $token = '';
    public $throwConnection = false;

    public function __construct() { parent::__construct('', '', '', 'test'); }
    public function setAccessToken($t) { $this->token = (string) $t; return $this; }

    public function apiRequest($method, $path, $body = null)
    {
        $key = strtoupper($method) . ' ' . preg_replace('/\?.*/', '', $path);
        $this->calls[] = ['key' => $key, 'body' => $body, 'token' => $this->token];
        if ($this->throwConnection) {
            throw new ConnectionException('Ovebot.ai connection error: timeout');
        }
        if (isset($this->responses[$key])) {
            $r = $this->responses[$key];
            if (isset($r[0]) && is_array($r[0]) && isset($r[0]['status'])) { // queue
                $next = array_shift($this->responses[$key]);
                if (!$this->responses[$key]) { unset($this->responses[$key]); }
                return $next;
            }
            return $r;
        }
        return ['status' => 200, 'body' => []];
    }

    public function refreshToken($rt)
    {
        $this->calls[] = ['key' => 'REFRESH', 'body' => $rt, 'token' => $this->token];
        if ($this->refreshResponse instanceof \Exception) { throw $this->refreshResponse; }
        return $this->refreshResponse ?: ['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3600];
    }

    public function exchangeCode($code, $verifier)
    {
        $this->calls[] = ['key' => 'EXCHANGE', 'body' => [$code, $verifier], 'token' => $this->token];
        if ($this->exchangeResponse instanceof \Exception) { throw $this->exchangeResponse; }
        return $this->exchangeResponse;
    }
}

function makeIntegration(FakeClient $client)
{
    $settings = new Settings(1);
    $urls = new Urls(1);
    return new Integration($settings, $client, $urls, new SetupPayloadBuilder($settings, $urls));
}

function connectedState()
{
    $s = new Settings(1);
    $s->setMany([
        Settings::ACCESS_TOKEN => 'AT1',
        Settings::REFRESH_TOKEN => 'RT1',
        Settings::TOKEN_EXPIRES => (string) (time() + 3600),
        Settings::WORKSPACE => 'my-shop',
        Settings::AGENT => '',
        Settings::FEED_HASH => str_repeat('a', 32),
        Settings::ORDER_USER => 'shop_test_ro_deadbeef',
        Settings::ORDER_PASS => str_repeat('b', 32),
    ]);
    return $s;
}

// ═════════════════════════════════════════════════════════════════════════════
echo "WidgetSettings\n";
resetState();
$w = WidgetSettings::sanitize([
    'accent_color' => '#ff00aa', 'theme' => 'DARK', 'language' => 'es', 'audio_beep' => '1', 'side' => 'left',
    'offset_y' => '35', 'offset_x' => '-4', 'z_index' => '99999999999', 'subtitle' => " <b>Hi</b>\nthere ",
    'proactive_message' => str_repeat('x', 300), 'proactive_delay' => '900', 'bogus' => 'y',
]);
same('color upper-cased', '#FF00AA', $w['accent_color']);
same('theme enum lower-cased', 'dark', $w['theme']);
same('language es allowed', 'es', $w['language']);
same('audio legacy bool -> play', 'play', $w['audio_beep']);
same('side', 'left', $w['side']);
same('offset_y kept', '35', $w['offset_y']);
same('negative offset_x rejected', '', $w['offset_x']);
same('z_index clamped', '2147483647', $w['z_index']);
same('subtitle stripped + single line', 'Hi there', $w['subtitle']);
same('proactive_message truncated to 255', 255, mb_strlen($w['proactive_message']));
same('proactive_delay clamped to 300', '300', $w['proactive_delay']);
check('unknown key dropped', !array_key_exists('bogus', $w));
$w = WidgetSettings::sanitize(['accent_color' => 'red', 'theme' => 'blue', 'language' => 'xx', 'audio_beep' => 'loud', 'side' => 'top', 'offset_y' => 'abc']);
same('bad color -> empty', '', $w['accent_color']);
same('bad theme -> empty', '', $w['theme']);
same('bad language -> empty', '', $w['language']);
same('bad audio -> empty', '', $w['audio_beep']);
same('bad side -> empty', '', $w['side']);
same('bad offset -> empty', '', $w['offset_y']);
same('defaults have 11 keys', 11, count(WidgetSettings::defaults()));

// ═════════════════════════════════════════════════════════════════════════════
echo "OrderIdentifier\n";
same('reference 9 letters', ['reference' => 'XKBKNABJK', 'id' => null], OrderIdentifier::parse('xkbknabjk'));
same('reference with hash', ['reference' => 'XKBKNABJK', 'id' => null], OrderIdentifier::parse('#xkbknabjk'));
same('plain number -> both candidates', ['reference' => '123', 'id' => 123], OrderIdentifier::parse('#123'));
same('numeric id with text', ['reference' => null, 'id' => 45], OrderIdentifier::parse('order 45'));
same('empty -> null', null, OrderIdentifier::parse(''));
same('short reference kept', ['reference' => 'ABC', 'id' => null], OrderIdentifier::parse('ABC'));
same('reference with digits stays a reference', ['reference' => 'ORD12345', 'id' => null], OrderIdentifier::parse('ord12345'));
same('longer than the column -> null', null, OrderIdentifier::parse('TOOLONGREFERENCE'));
same('phone +40', '721234567', OrderIdentifier::phoneCore('+40 721-234.567'));
same('phone 07', '721234567', OrderIdentifier::phoneCore('0721234567'));
same('phone 0040', '721234567', OrderIdentifier::phoneCore('0040 721 234 567'));
same('phone too short', '', OrderIdentifier::phoneCore('12345'));
same('phone long international keeps last 9', '123456789', OrderIdentifier::phoneCore('+49 30 123456789'));

// ═════════════════════════════════════════════════════════════════════════════
echo "Text\n";
same('plain paragraphs', "Hello world\nSecond &amp; more\nItem", Text::plain('<p>Hello <b>world</b></p><p>Second &amp;amp; more</p><ul><li>Item</li></ul>'));
same('plain nbsp + entities', "A B – c", Text::plain('A&nbsp;B &ndash;&nbsp;c'));
same('plain strips script', 'Visible', Text::plain('<script>alert(1)</script>Visible<style>p{}</style>'));
same('line collapses newlines', 'a b c', Text::line("a<br>b\n\nc"));
same('length utf8', 5, Text::length('țărăn'));
same('kb body', "Title\n\nBody text", KbSync::buildBody('Title', '<p>Body text</p>'));
same('kb body no content', 'Title', KbSync::buildBody('Title', ''));
same('slug', 'cms-7', KbSync::slug('7'));

// ═════════════════════════════════════════════════════════════════════════════
echo "Settings (single shop)\n";
resetState();
$s = new Settings(1);
same('default when missing', 'dflt', $s->get('OVEBOTAI_NOPE', 'dflt'));
$s->set(Settings::CHAT_STATUS, '1');
same('set/get', '1', $s->get(Settings::CHAT_STATUS));
same('getFlag never-set -> default true', true, $s->getFlag(Settings::ORDER_ENABLED, true));
same('getFlag never-set -> default false', false, $s->getFlag(Settings::CHAT_STATUS . '_X', false));
$s->set(Settings::ORDER_ENABLED, '0');
same('getFlag 0 -> false', false, $s->getFlag(Settings::ORDER_ENABLED, true));
$s->delete(Settings::ORDER_ENABLED);
same('delete -> default again', true, $s->getFlag(Settings::ORDER_ENABLED, true));
$s->setArray(Settings::WIDGET, ['subtitle' => "a < b & c \"q\" 'x'", 'offset_y' => '20']);
same('json survives pSQL-like stripping', ['subtitle' => "a < b & c \"q\" 'x'", 'offset_y' => '20'], $s->getArray(Settings::WIDGET));
$s->setArray(Settings::OAUTH_PENDING, ['abc' => ['r' => 'https://x/admin/index.php?controller=A&token=1', 'x' => 1]]);
same('json with & and url survives', 'https://x/admin/index.php?controller=A&token=1', $s->getArray(Settings::OAUTH_PENDING)['abc']['r']);
$s->setMany(['OVEBOTAI_WORKSPACE' => 'ws', 'OVEBOTAI_KB_PAGE_IDS' => [1, 2]]);
same('setMany scalar', 'ws', $s->get('OVEBOTAI_WORKSPACE'));
same('setMany array', [1, 2], $s->getArray('OVEBOTAI_KB_PAGE_IDS'));
check('allKeys has 23 keys', count(Settings::allKeys()) === 23, (string) count(Settings::allKeys()));
check('allKeys only OVEBOTAI_', !array_filter(Settings::allKeys(), function ($k) { return strpos($k, 'OVEBOTAI_') !== 0; }));

// ═════════════════════════════════════════════════════════════════════════════
echo "Urls\n";
resetState();
$u = new Urls(1);
same('feed url', 'https://www.shop-test.ro/index.php?fc=module&module=ovebotai&controller=feed&id_lang=2&hash=abc', $u->feedUrl('abc'));
same('orders url', 'https://www.shop-test.ro/index.php?fc=module&module=ovebotai&controller=orders&id_lang=2', $u->ordersUrl());
same('oauth url', 'https://www.shop-test.ro/index.php?fc=module&module=ovebotai&controller=oauth&id_lang=2', $u->oauthCallbackUrl());
same('domain', 'www.shop-test.ro', $u->shopDomain());
same('domain slug', 'shop_test_ro', $u->domainSlug());
same('currency', 'RON', $u->defaultCurrencyIso());
same('lang', 2, $u->getIdLang());
Configuration::$data['PS_SSL_ENABLED'] = '0';
$u = new Urls(1);
same('http when ssl disabled', 'http://www.shop-test.ro/index.php?fc=module&module=ovebotai&controller=oauth&id_lang=2', $u->oauthCallbackUrl());

// ═════════════════════════════════════════════════════════════════════════════
echo "SetupPayloadBuilder\n";
resetState();
$s = connectedState();
$b = new SetupPayloadBuilder($s, new Urls(1));
$p = $b->build();
same('widget language default auto', 'auto', $p['widget']['language']);
same('order_info complete', ['enabled', 'api_url', 'api_user', 'api_password', 'lookup_method'], array_keys($p['order_info']));
same('lookup_method email', 'email', $p['order_info']['lookup_method']);
same('products enabled + feed + currency when recommend&&builtin (defaults)', ['enabled', 'feed_url', 'currency'], array_keys($p['products']));
check('feed url carries hash', strpos($p['products']['feed_url'], 'hash=' . str_repeat('a', 32)) !== false);
$s->set(Settings::PRODUCTS_RECOMMEND, '0');
$p = $b->build();
same('recommend off -> enabled:false only', ['enabled' => false], $p['products']);
$s->set(Settings::PRODUCTS_RECOMMEND, '1');
$s->set(Settings::PRODUCTS_BUILTIN, '0');
$p = $b->build();
same('own feed -> enabled:true only', ['enabled' => true], $p['products']);
$p = $b->build(['products_builtin' => true, 'feed_hash' => 'NEWHASH', 'order_user' => 'u', 'order_pass' => 'p', 'order_enabled' => false]);
check('override feed_hash used', strpos($p['products']['feed_url'], 'hash=NEWHASH') !== false);
same('override order creds', ['u', 'p', false], [$p['order_info']['api_user'], $p['order_info']['api_password'], $p['order_info']['enabled']]);
same('override does not persist', '0', $s->get(Settings::PRODUCTS_BUILTIN));
$s->setArray(Settings::WIDGET, ['language' => 'ro']);
same('widget language from settings', 'ro', $b->build()['widget']['language']);

// ═════════════════════════════════════════════════════════════════════════════
echo "Client\n";
$c = new Client('', 'evil.example/../x', 'api.staging.ovebot.ai:8443', 'UA');
same('bad account host -> default', 'account.ovebot.ai', $c->getAccountHost());
$auth = $c->buildAuthUrl('shop.ro', 'https://shop.ro/cb?a=1', 'verifier', 'state1');
check('auth url host', strpos($auth, 'https://account.ovebot.ai/oauth/authorize?') === 0);
parse_str(parse_url($auth, PHP_URL_QUERY), $q);
same('auth params', ['site_domain' => 'shop.ro', 'callback_url' => 'https://shop.ro/cb?a=1', 'scopes' => Client::SCOPES, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'state' => 'state1'], $q);
same('register url', 'https://account.ovebot.ai/register?plan=wp-freemium&domain=shop.ro', $c->buildRegisterUrl('wp-freemium', 'shop.ro'));
check('verifier length 64', strlen(Client::generateVerifier()) === 64);
check('state 32 hex', preg_match('/^[a-f0-9]{32}$/', Client::generateState()) === 1);

// ═════════════════════════════════════════════════════════════════════════════
echo "Integration: OAuth pending state\n";
resetState();
$client = new FakeClient();
$i = makeIntegration($client);
$url = $i->beginAuthorization('https://www.shop-test.ro/admin123/index.php?controller=AdminOvebotai&token=t', 7);
parse_str(parse_url($url, PHP_URL_QUERY), $q);
same('site_domain = callback host', 'www.shop-test.ro', $q['site_domain']);
same('callback is the front controller', 'https://www.shop-test.ro/index.php?fc=module&module=ovebotai&controller=oauth&id_lang=2', $q['callback_url']);
$state = $q['state'];
$i->beginAuthorization('https://x/2', 7);
$pending = $i->pullPendingState($state);
check('first state still valid after a second click', $pending !== null && $pending['r'] === 'https://www.shop-test.ro/admin123/index.php?controller=AdminOvebotai&token=t');
same('state is one-shot', null, $i->pullPendingState($state));
same('unknown state', null, $i->pullPendingState('zzz'));
for ($n = 0; $n < 7; ++$n) { $i->beginAuthorization('https://x/' . $n, 7); }
check('pending capped at 5', count((new Settings(1))->getArray(Settings::OAUTH_PENDING)) === 5);

echo "Integration: callback + agent change\n";
resetState();
$client = new FakeClient();
$client->exchangeResponse = ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'workspace' => ['slug' => 'my-shop'], 'agent' => null];
$client->responses['GET /v1/me'] = ['status' => 200, 'body' => ['agent' => ['public_id' => 'agent-2']]];
$s = new Settings(1);
$s->setMany([Settings::SETUP_COMPLETE => '1', Settings::KB_PAGE_IDS => [3, 4], Settings::PRODUCTS_RECOMMEND => '0', Settings::ORDER_ENABLED => '0', Settings::AGENT => '']);
$i = makeIntegration($client);
same('callback ok', ['success' => true], $i->handleCallback('code', 'ver'));
same('tokens stored', ['AT', 'RT', 'my-shop'], [$s->get(Settings::ACCESS_TOKEN), $s->get(Settings::REFRESH_TOKEN), $s->get(Settings::WORKSPACE)]);
same('agent from /me', 'agent-2', $s->get(Settings::AGENT));
same('agent change resets setup', '0', $s->get(Settings::SETUP_COMPLETE));
same('agent change clears kb ids', [], $s->getArray(Settings::KB_PAGE_IDS));
same('agent change clears mirrors', [true, true], [$i->getProductsRecommend(), $i->getOrderEnabled()]);
same('agent setup url non-default', 'https://my-shop.ovebot.ai/agents/agent-2/setup', $i->getAgentSetupUrl());
same('connection label', 'my-shop:agent-2', $i->getConnectionLabel());
check('client got the new token', $client->token === 'AT');
// same agent again -> no reset
$s->set(Settings::SETUP_COMPLETE, '1');
$s->setArray(Settings::KB_PAGE_IDS, [3]);
$i->handleCallback('code', 'ver');
same('same agent keeps setup', '1', $s->get(Settings::SETUP_COMPLETE));
same('same agent keeps kb ids', [3], $s->getArray(Settings::KB_PAGE_IDS));
// invalid workspace slug is not stored
resetState();
$client = new FakeClient();
$client->exchangeResponse = ['access_token' => 'AT', 'refresh_token' => 'RT', 'workspace' => ['slug' => 'bad.slug/x']];
$i = makeIntegration($client);
$i->handleCallback('c', 'v');
same('invalid workspace ignored', '', (new Settings(1))->get(Settings::WORKSPACE));
same('not connected without workspace', false, $i->isConnected());
// exchange failure
$client->exchangeResponse = new AuthException('invalid_grant: expired');
same('callback error surfaces message', ['error' => 'invalid_grant: expired'], $i->handleCallback('c', 'v'));
$i->flashOauthResult(['error' => 'boom']);
same('flash read once', 'boom', $i->pullOauthError());
same('flash gone', '', $i->pullOauthError());

echo "Integration: token refresh\n";
resetState();
connectedState();
$client = new FakeClient();
$client->token = 'AT1';
$client->responses['GET /v1/integration/status'] = [
    ['status' => 401, 'body' => []],
    ['status' => 200, 'body' => ['integration' => ['products' => false, 'order_info' => true, 'counts' => ['products' => 12]]]],
];
Db::$answers[] = ['/GET_LOCK/', '1'];
Db::$answers[] = ['/FROM `ps_configuration` WHERE `name` = \'([A-Z_]+)\'/', function ($sql) { preg_match('/`name` = \'([A-Z_]+)\'/', $sql, $m); return Configuration::get($m[1]); }];
$i = makeIntegration($client);
same('probe live after reactive refresh', Integration::PROBE_LIVE, $i->probeConnection());
$keys = array_map(function ($c) { return $c['key']; }, $client->calls);
same('401 -> refresh -> retry', ['GET /v1/integration/status', 'REFRESH', 'GET /v1/integration/status'], $keys);
same('rotated tokens stored', ['AT2', 'RT2'], [(new Settings(1))->get(Settings::ACCESS_TOKEN), (new Settings(1))->get(Settings::REFRESH_TOKEN)]);
same('retry used new token', 'AT2', $client->calls[2]['token']);
same('count from status', 12, $i->getIndexedProductCount());
$i->syncSettings();
same('mirrors synced from account', [false, true], [$i->getProductsRecommend(), $i->getOrderEnabled()]);
same('probe memoised', Integration::PROBE_LIVE, $i->probeConnection());
same('only 3 calls (memoised)', 3, count($client->calls));

// proactive refresh when near expiry
resetState();
$s = connectedState();
$s->set(Settings::TOKEN_EXPIRES, (string) (time() + 60));
$client = new FakeClient();
Db::$answers[] = ['/GET_LOCK/', '1'];
Db::$answers[] = ['/FROM `ps_configuration`/', function ($sql) { preg_match('/`name` = \'([A-Z_]+)\'/', $sql, $m); return Configuration::get($m[1]); }];
$i = makeIntegration($client);
$i->apiRequest('GET', '/v1/me');
same('proactive refresh first', 'REFRESH', $client->calls[0]['key']);

// revoked: refresh rejected -> tokens cleared, workspace kept
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/integration/status'] = ['status' => 401, 'body' => []];
$client->refreshResponse = new AuthException('invalid_grant');
Db::$answers[] = ['/GET_LOCK/', '1'];
Db::$answers[] = ['/FROM `ps_configuration`/', function ($sql) { preg_match('/`name` = \'([A-Z_]+)\'/', $sql, $m); return Configuration::get($m[1]); }];
$i = makeIntegration($client);
same('probe revoked', Integration::PROBE_REVOKED, $i->probeConnection());
$s = new Settings(1);
same('tokens cleared', ['', ''], [$s->get(Settings::ACCESS_TOKEN), $s->get(Settings::REFRESH_TOKEN)]);
same('workspace kept for the widget', 'my-shop', $s->get(Settings::WORKSPACE));
same('disconnected now', false, $i->isConnected());

// network blip on refresh: tokens kept, probe unreachable
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/integration/status'] = ['status' => 401, 'body' => []];
$client->refreshResponse = new ConnectionException('timeout');
Db::$answers[] = ['/GET_LOCK/', '1'];
Db::$answers[] = ['/FROM `ps_configuration`/', function ($sql) { preg_match('/`name` = \'([A-Z_]+)\'/', $sql, $m); return Configuration::get($m[1]); }];
$i = makeIntegration($client);
same('probe unreachable on transport error', Integration::PROBE_UNREACHABLE, $i->probeConnection());
same('still connected', true, $i->isConnected());

// 5xx -> unreachable, nothing cleared
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/integration/status'] = ['status' => 502, 'body' => []];
$i = makeIntegration($client);
same('probe unreachable on 5xx', Integration::PROBE_UNREACHABLE, $i->probeConnection());
same('5xx keeps tokens', 'RT1', (new Settings(1))->get(Settings::REFRESH_TOKEN));
$i->syncSettings();
same('no sync when unreachable', true, $i->getProductsRecommend());

// connection exception on status
resetState();
connectedState();
$client = new FakeClient();
$client->throwConnection = true;
$i = makeIntegration($client);
same('probe unreachable on exception', Integration::PROBE_UNREACHABLE, $i->probeConnection());

// another process refreshed while we waited for the lock
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/me'] = [['status' => 401, 'body' => []], ['status' => 200, 'body' => []]];
Db::$answers[] = ['/GET_LOCK/', '1'];
Db::$answers[] = ['/FROM `ps_configuration` WHERE `name` = \'OVEBOTAI_REFRESH_TOKEN\'/', 'RT-OTHER'];
Db::$answers[] = ['/FROM `ps_configuration` WHERE `name` = \'OVEBOTAI_ACCESS_TOKEN\'/', 'AT-OTHER'];
Db::$answers[] = ['/FROM `ps_configuration`/', ''];
$i = makeIntegration($client);
$r = $i->apiRequest('GET', '/v1/me');
$keys = array_map(function ($c) { return $c['key']; }, $client->calls);
same('adopted the other process tokens without refreshing', ['GET /v1/me', 'GET /v1/me'], $keys);
same('retry with adopted token', 'AT-OTHER', $client->calls[1]['token']);

echo "Integration: /setup, finish, settings, regen\n";
resetState();
connectedState();
$client = new FakeClient();
$i = makeIntegration($client);
$r = $i->finish(false);
same('finish ok', true, $r['success']);
$s = new Settings(1);
same('finish flags', ['1', '1', '0', '1', '1'], [$s->get(Settings::SETUP_COMPLETE), $s->get(Settings::CHAT_STATUS), $s->get(Settings::PRODUCTS_BUILTIN), $s->get(Settings::ORDER_ENABLED), $s->get(Settings::PRODUCTS_RECOMMEND)]);
same('setup path', 'PUT /v1/workspaces/my-shop/agents/default/setup', $client->calls[0]['key']);
same('own feed payload', ['enabled' => true], $client->calls[0]['body']['products']);
same('last push recorded', ['feed_url' => '', 'api_url' => 'https://www.shop-test.ro/index.php?fc=module&module=ovebotai&controller=orders&id_lang=2'], $s->getArray(Settings::LAST_PUSH));
same('setup complete', true, $i->isSetupComplete());

// finish failure: nothing marked complete
resetState();
connectedState();
$client = new FakeClient();
$client->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = ['status' => 422, 'body' => ['error' => ['message' => 'Validation failed.', 'fields' => ['order_info.api_url' => ['must be https']]]]];
$i = makeIntegration($client);
$r = $i->finish(true);
same('finish failed', false, $r['success']);
same('error flattened', 'Validation failed. must be https', $r['error']);
same('not complete', '', (new Settings(1))->get(Settings::SETUP_COMPLETE));

// saveSettings: push fails -> synced flags NOT persisted, local ones are
resetState();
$s = connectedState();
$s->setMany([Settings::PRODUCTS_BUILTIN => '1', Settings::PRODUCTS_RECOMMEND => '1', Settings::ORDER_ENABLED => '1', Settings::CHAT_STATUS => '1']);
$client = new FakeClient();
$client->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = ['status' => 500, 'body' => ['message' => 'Server error']];
$i = makeIntegration($client);
$r = $i->saveSettings(false, ['language' => 'de', 'subtitle' => 'Hi'], false, false, false);
same('sync_failed status', 'sync_failed', $r['status']);
same('sync_failed error', 'Server error', $r['error']);
same('local fields persisted', ['0', 'de'], [$s->get(Settings::CHAT_STATUS), $s->getArray(Settings::WIDGET)['language']]);
same('synced flags untouched', ['1', '1', '1'], [$s->get(Settings::PRODUCTS_BUILTIN), $s->get(Settings::PRODUCTS_RECOMMEND), $s->get(Settings::ORDER_ENABLED)]);
same('effective reports persisted values', ['products_builtin' => true, 'products_recommend' => true, 'order_enabled' => true], $r['effective']);
same('payload used the NEW values', [false, false], [$client->calls[0]['body']['products']['enabled'], $client->calls[0]['body']['order_info']['enabled']]);
same('payload language from new widget', 'de', $client->calls[0]['body']['widget']['language']);
// success path
$client->responses = [];
$r = $i->saveSettings(true, ['language' => ''], false, true, false);
same('ok status', 'ok', $r['status']);
same('synced flags persisted', ['0', '1', '0'], [$s->get(Settings::PRODUCTS_BUILTIN), $s->get(Settings::PRODUCTS_RECOMMEND), $s->get(Settings::ORDER_ENABLED)]);
// disconnected path
resetState();
$s = new Settings(1);
$client = new FakeClient();
$i = makeIntegration($client);
$r = $i->saveSettings(true, [], false, false, true);
same('needs_reconnect', 'needs_reconnect', $r['status']);
same('flags persisted locally when disconnected', ['0', '0', '1'], [$s->get(Settings::PRODUCTS_BUILTIN), $s->get(Settings::PRODUCTS_RECOMMEND), $s->get(Settings::ORDER_ENABLED)]);
same('no api call when disconnected', 0, count($client->calls));

// regenerate feed hash: failure keeps old hash
resetState();
$s = connectedState();
$client = new FakeClient();
$client->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = ['status' => 503, 'body' => []];
$i = makeIntegration($client);
$r = $i->regenerateFeedHash();
same('regen hash failed', false, $r['success']);
same('regen hash error fallback', 'HTTP 503', $r['error']);
same('old hash kept', str_repeat('a', 32), $s->get(Settings::FEED_HASH));
$client->responses = [];
$r = $i->regenerateFeedHash();
check('regen hash ok + synced', $r['success'] && $r['synced'] && preg_match('/^[a-f0-9]{32}$/', $r['hash']));
same('new hash stored', $r['hash'], $s->get(Settings::FEED_HASH));
check('pushed feed_url has new hash', strpos($client->calls[1]['body']['products']['feed_url'], 'hash=' . $r['hash']) !== false);
// own feed: local only, no push
$s->set(Settings::PRODUCTS_BUILTIN, '0');
$calls = count($client->calls);
$r = $i->regenerateFeedHash();
same('own feed regen is local', [true, false, $calls], [$r['success'], $r['synced'], count($client->calls)]);

// regenerate creds: failure keeps old, success clears rate limit table
resetState();
$s = connectedState();
$client = new FakeClient();
$client->responses['PUT /v1/workspaces/my-shop/agents/default/setup'] = ['status' => 422, 'body' => ['error' => ['code' => 'x', 'message' => 'bad']]];
$i = makeIntegration($client);
$r = $i->regenerateOrderCreds();
same('regen creds failed', [false, 'bad'], [$r['success'], $r['error']]);
same('old creds kept', 'shop_test_ro_deadbeef', $s->get(Settings::ORDER_USER));
$client->responses = [];
$r = $i->regenerateOrderCreds();
check('regen creds ok', $r['success'] && preg_match('/^shop_test_ro_[a-f0-9]{8}$/', $r['user']) && preg_match('/^[a-f0-9]{32}$/', $r['pass']));
same('creds stored', [$r['user'], $r['pass']], [$s->get(Settings::ORDER_USER), $s->get(Settings::ORDER_PASS)]);
check('rate limit table cleared', (bool) array_filter(Db::$queries, function ($q) { return strpos($q, 'DELETE FROM `ps_ovebotai_rate_limit`') === 0; }));
same('pushed creds before persisting', [$r['user'], $r['pass']], [$client->calls[1]['body']['order_info']['api_user'], $client->calls[1]['body']['order_info']['api_password']]);

// disconnect keeps setup/agent/creds
resetState();
$s = connectedState();
$s->setMany([Settings::SETUP_COMPLETE => '1', Settings::AGENT => 'ag', Settings::KB_PAGE_IDS => [1]]);
$client = new FakeClient();
$i = makeIntegration($client);
$i->disconnect();
same('disconnect called remote', 'POST /v1/disconnect', $client->calls[0]['key']);
same('disconnect cleared tokens+workspace', ['', '', '', ''], [$s->get(Settings::ACCESS_TOKEN), $s->get(Settings::REFRESH_TOKEN), $s->get(Settings::TOKEN_EXPIRES), $s->get(Settings::WORKSPACE)]);
same('disconnect kept the rest', ['1', 'ag', [1], str_repeat('a', 32)], [$s->get(Settings::SETUP_COMPLETE), $s->get(Settings::AGENT), $s->getArray(Settings::KB_PAGE_IDS), $s->get(Settings::FEED_HASH)]);
same('setup not complete while disconnected', false, $i->isSetupComplete());

// ensureCredentials seeds once
resetState();
$client = new FakeClient();
$i = makeIntegration($client);
$i->ensureCredentials();
$s = new Settings(1);
check('seeded hash', preg_match('/^[a-f0-9]{32}$/', $s->get(Settings::FEED_HASH)) === 1);
check('seeded user', preg_match('/^shop_test_ro_[a-f0-9]{8}$/', $s->get(Settings::ORDER_USER)) === 1);
$hash = $s->get(Settings::FEED_HASH);
$i->ensureCredentials();
same('ensureCredentials idempotent', $hash, $s->get(Settings::FEED_HASH));

// selfHeal: pushes when the URL changed
resetState();
$s = connectedState();
$s->set(Settings::SETUP_COMPLETE, '1');
$s->setArray(Settings::LAST_PUSH, ['feed_url' => 'https://old.example/feed', 'api_url' => 'https://old.example/orders']);
$client = new FakeClient();
$client->responses['GET /v1/integration/status'] = ['status' => 200, 'body' => ['integration' => []]];
$i = makeIntegration($client);
$i->probeConnection();
$i->selfHealEndpoints();
$keys = array_map(function ($c) { return $c['key']; }, $client->calls);
same('self-heal pushed setup', ['GET /v1/integration/status', 'PUT /v1/workspaces/my-shop/agents/default/setup'], $keys);
$i->selfHealEndpoints();
same('self-heal idempotent', 2, count($client->calls));

// apiErrorMessage shapes
$i = makeIntegration(new FakeClient());
same('err shape 1', 'msg a b', $i->apiErrorMessage(['status' => 422, 'body' => ['error' => ['message' => 'msg', 'fields' => ['x' => ['a', 'b']]]]]));
same('err shape 2', 'desc', $i->apiErrorMessage(['status' => 400, 'body' => ['error' => 'invalid', 'error_description' => 'desc']]));
same('err shape 3', 'm', $i->apiErrorMessage(['status' => 400, 'body' => ['message' => 'm']]));
same('err shape 4', 'f1 f2', $i->apiErrorMessage(['status' => 400, 'body' => ['errors' => ['a' => ['f1'], 'b' => 'f2']]]));
same('err fallback', 'HTTP 500', $i->apiErrorMessage(['status' => 500, 'body' => []]));
same('quota by code', true, $i->isQuotaError(['status' => 400, 'body' => ['error' => ['code' => 'kb_limit_reached']]]));
same('quota by status', true, $i->isQuotaError(['status' => 402, 'body' => []]));
same('quota by message', true, $i->isQuotaError(['status' => 400, 'body' => ['message' => 'Plan limit reached']]));
same('not quota', false, $i->isQuotaError(['status' => 400, 'body' => ['message' => 'Bad slug']]));

echo "Integration: KB list\n";
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/workspaces/my-shop/agents/default/knowledge-base'] = [
    ['status' => 200, 'body' => ['entries' => [['id' => 1, 'slug' => 'cms-1', 'title' => 'A', 'is_active' => true]], 'total' => 2]],
    ['status' => 200, 'body' => ['entries' => [['id' => 2, 'slug' => 'cms-2', 'title' => 'B', 'is_active' => false]], 'total' => 2]],
];
$i = makeIntegration($client);
$kb = $i->getKbEntries();
same('kb paginated', 2, count($kb['entries']));
same('kb edit url', 'https://my-shop.ovebot.ai/knowledge-base/2/edit', $kb['entries'][1]['edit_url']);
same('kb error false', false, $kb['error']);

// ═════════════════════════════════════════════════════════════════════════════
echo "KbSync\n";
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/workspaces/my-shop/agents/default/knowledge-base'] = ['status' => 200, 'body' => ['entries' => [['id' => 10, 'slug' => 'cms-1']], 'total' => 1]];
$client->responses['POST /v1/workspaces/my-shop/agents/default/knowledge-base'] = [
    ['status' => 201, 'body' => ['id' => 11]],
    ['status' => 409, 'body' => ['error' => ['code' => 'kb_limit_reached', 'message' => 'Your plan allows 2 entries.']]],
];
$client->responses['PUT /v1/workspaces/my-shop/agents/default/knowledge-base/10'] = ['status' => 200, 'body' => []];
$cmsRows = [
    1 => ['id_cms' => 1, 'active' => 1, 'meta_title' => 'About', 'content' => '<p>Long enough content here</p>', 'link_rewrite' => 'about'],
    2 => ['id_cms' => 2, 'active' => 1, 'meta_title' => 'Delivery', 'content' => '<p>Delivery info that is long</p>', 'link_rewrite' => 'delivery'],
    3 => ['id_cms' => 3, 'active' => 0, 'meta_title' => 'Hidden', 'content' => '<p>Hidden page content</p>', 'link_rewrite' => 'hidden'],
    4 => ['id_cms' => 4, 'active' => 1, 'meta_title' => 'Tiny', 'content' => '<p></p>', 'link_rewrite' => 'tiny'],
    5 => ['id_cms' => 5, 'active' => 1, 'meta_title' => 'Terms', 'content' => '<p>Terms and conditions text</p>', 'link_rewrite' => 'terms'],
];
Db::$answers[] = ['/c\.id_cms = (\d+)/', function ($sql) use ($cmsRows) { preg_match('/c\.id_cms = (\d+)/', $sql, $m); return isset($cmsRows[(int) $m[1]]) ? $cmsRows[(int) $m[1]] : false; }];
$i = makeIntegration($client);
$sync = new KbSync($i, new CmsPageProvider($i->getUrls()));
$r = $sync->sync([1, 2, 3, 4, 5, 99], true);
same('quota message once', 'Your plan allows 2 entries.', $r['kb_limit']);
same('quota ids', [5], $r['kb_limit_ids']);
same('failed reasons', [3 => 'Skipped "Hidden" - page is not enabled.', 4 => 'Skipped "Tiny" - not enough text content to sync (minimum 10 characters).'], $r['failed']);
$keys = array_map(function ($c) { return $c['key']; }, $client->calls);
same('kb calls', ['GET /v1/workspaces/my-shop/agents/default/knowledge-base', 'PUT /v1/workspaces/my-shop/agents/default/knowledge-base/10', 'POST /v1/workspaces/my-shop/agents/default/knowledge-base', 'POST /v1/workspaces/my-shop/agents/default/knowledge-base'], $keys);
same('update payload', ['title' => 'About', 'body' => "About\n\nLong enough content here", 'is_active' => true, 'slug' => 'cms-1', 'source_url' => 'https://www.shop-test.ro/content/1-about'], $client->calls[1]['body']);
// 404 on update -> recreate
resetState();
connectedState();
$client = new FakeClient();
$client->responses['GET /v1/workspaces/my-shop/agents/default/knowledge-base'] = ['status' => 200, 'body' => ['entries' => [['id' => 10, 'slug' => 'cms-1']], 'total' => 1]];
$client->responses['PUT /v1/workspaces/my-shop/agents/default/knowledge-base/10'] = ['status' => 404, 'body' => []];
$client->responses['POST /v1/workspaces/my-shop/agents/default/knowledge-base'] = ['status' => 201, 'body' => ['id' => 12]];
Db::$answers[] = ['/c\.id_cms = (\d+)/', function ($sql) use ($cmsRows) { preg_match('/c\.id_cms = (\d+)/', $sql, $m); return isset($cmsRows[(int) $m[1]]) ? $cmsRows[(int) $m[1]] : false; }];
$i = makeIntegration($client);
$sync = new KbSync($i, new CmsPageProvider($i->getUrls()));
$r = $sync->sync([1], true);
same('404 -> recreate: clean', [[], '', []], [$r['failed'], $r['kb_limit'], $r['kb_limit_ids']]);
same('404 -> POST issued', 'POST /v1/workspaces/my-shop/agents/default/knowledge-base', $client->calls[2]['key']);
// deactivate: 404 -> nothing
$client->calls = [];
$r = $sync->sync([1], false);
same('deactivate 404 -> no create', 2, count($client->calls));
// list fetch failure -> exception
$client->responses['GET /v1/workspaces/my-shop/agents/default/knowledge-base'] = ['status' => 500, 'body' => []];
try { $sync->sync([1], true); check('list failure throws', false); } catch (ApiException $e) { check('list failure throws ApiException', true); }
// deactivateDeleted
$client->responses['GET /v1/workspaces/my-shop/agents/default/knowledge-base'] = ['status' => 200, 'body' => ['entries' => [['id' => 10, 'slug' => 'cms-1']], 'total' => 1]];
$client->responses['PUT /v1/workspaces/my-shop/agents/default/knowledge-base/10'] = ['status' => 200, 'body' => []];
$client->calls = [];
same('deactivateDeleted found', true, $sync->deactivateDeleted(1, 'About', '<p>x</p>'));
same('deactivateDeleted payload inactive', false, $client->calls[1]['body']['is_active']);
check('deactivateDeleted body padded to min length', Text::length($client->calls[1]['body']['body']) >= 10);
same('deactivateDeleted unknown slug', false, $sync->deactivateDeleted(77, 'X', ''));

// ═════════════════════════════════════════════════════════════════════════════
echo "BasicAuth\n";
$_SERVER['PHP_AUTH_USER'] = 'u1';
$_SERVER['PHP_AUTH_PW'] = 'p1';
same('php_auth ok', true, BasicAuth::matches('u1', 'p1'));
same('php_auth wrong pass', false, BasicAuth::matches('u1', 'p2'));
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('u2:p:with:colons');
same('header fallback', ['u2', 'p:with:colons'], BasicAuth::credentials());
same('header match', true, BasicAuth::matches('u2', 'p:with:colons'));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xyz';
same('non-basic header', null, BasicAuth::credentials());
unset($_SERVER['HTTP_AUTHORIZATION']);
same('empty expected never matches', false, BasicAuth::matches('', ''));

// ═════════════════════════════════════════════════════════════════════════════
echo "OrderLookup SQL\n";
resetState();
$s = new Settings(1);
$captured = null;
Db::$answers[] = ['/FROM orders o/', function ($sql) use (&$captured) { $captured = $sql; return false; }];
$lookup = new OrderLookup(1, $s, new TrackingResolver($s));
same('no order -> null', null, $lookup->find(['reference' => 'XKBKNABJK', 'id' => null], 'email', "o'brien@example.com"));
check('reference where', strpos($captured, "o.reference = 'XKBKNABJK'") !== false, $captured);
check('email escaped', strpos($captured, "c.email = 'o\\'brien@example.com'") !== false, $captured);
check('max age 60', strpos($captured, 'INTERVAL 60 DAY') !== false);
check('shop filter', strpos($captured, 'o.id_shop = 1') !== false);
check('state > 0', strpos($captured, 'o.current_state > 0') !== false);
check('most recent first', strpos($captured, 'ORDER BY o.id_order DESC') !== false);
$s->set(Settings::ORDER_MAX_AGE_DAYS, '90');
$lookup->find(['reference' => null, 'id' => 12], 'phone', '721234567');
check('id where', strpos($captured, 'o.id_order = 12') !== false);
check('phone like on both columns', substr_count($captured, "LIKE '%721234567'") === 2, $captured);
check('phone cleaned of separators', strpos($captured, "REPLACE(") !== false);
check('max age override', strpos($captured, 'INTERVAL 90 DAY') !== false);
$lookup->find(OrderIdentifier::parse('123'), 'email', 'a@b.ro');
check('both candidates OR-ed', strpos($captured, "(o.reference = '123' OR o.id_order = 123)") !== false, $captured);
// found order -> mapping + native tracking
Db::$answers = [];
Db::$answers[] = ['/FROM orders o/', ['id_order' => 12, 'reference' => 'ABCDEFGHI', 'date_add' => '2026-09-01 10:00:00', 'total_paid_tax_incl' => '349.9900', 'iso_code' => 'RON', 'status' => 'Expediată']];
Db::$answers[] = ['/ps_order_carrier/', ['tracking_number' => '1ONB123', 'id_carrier' => 3, 'name' => 'Sameday', 'url' => 'https://sameday.ro/#awb=@']];
$r = $lookup->find(['reference' => null, 'id' => 12], 'email', 'a@b.ro');
same('order mapped', ['id' => 12, 'reference' => 'ABCDEFGHI', 'date' => '2026-09-01 10:00:00', 'status' => 'Expediată', 'total' => 349.99, 'currency' => 'RON', 'carrier' => 'Sameday', 'awb' => '1ONB123', 'awb_tracking_url' => 'https://sameday.ro/#awb=1ONB123'], $r);
// carrier "0" -> shop name, no url
Db::$answers[1] = ['/ps_order_carrier/', ['tracking_number' => 'X1', 'id_carrier' => 1, 'name' => '0', 'url' => '']];
$r = $lookup->find(['reference' => null, 'id' => 12], 'email', 'a@b.ro');
same('carrier 0 -> shop name', ['Test shop', 'X1', null], [$r['carrier'], $r['awb'], $r['awb_tracking_url']]);
// no tracking at all
Db::$answers[1] = ['/ps_order_carrier/', false];
$r = $lookup->find(['reference' => null, 'id' => 12], 'email', 'a@b.ro');
same('no tracking -> nulls', [null, null, null], [$r['carrier'], $r['awb'], $r['awb_tracking_url']]);

echo "TrackingResolver\n";
resetState();
$s = new Settings(1);
$s->setArray(Settings::TRACKING_FINDERS, ['SAMEDAY']);
$resolver = new TrackingResolver($s);
same('filter by code (case-insensitive)', ['sameday'], array_map(function ($f) { return $f->code(); }, $resolver->finders()));
$s->setArray(Settings::TRACKING_FINDERS, []);
$resolver = new TrackingResolver($s);
same('all finders', ['native', 'sameday', 'fancourier', 'cargus', 'dpd', 'gls', 'fedex'], array_map(function ($f) { return $f->code(); }, $resolver->finders()));
// table finder: module installed, table exists with columns
Db::$installedModules = ['samedaycourier'];
Db::$answers[] = ['/ps_order_carrier/', false];
Db::$answers[] = ['/SHOW TABLES LIKE \'ps_sameday_awb\'/', [['x']]];
Db::$answers[] = ['/SHOW COLUMNS FROM `ps_sameday_awb`/', [['Field' => 'id'], ['Field' => 'id_order'], ['Field' => 'awb_number']]];
Db::$answers[] = ['/FROM `ps_sameday_awb`/', '2ONB999'];
$s->setArray(Settings::TRACKING_URLS, ['sameday' => 'https://track.example/{code}']);
$resolver = new TrackingResolver($s);
same('table finder result + url override', ['carrier' => 'Sameday', 'awb' => '2ONB999', 'tracking_url' => 'https://track.example/2ONB999'], $resolver->resolve(5));
// hook can prepend a finder
Hook::$callback = function ($name, $args) {
    if ($name === 'actionOvebotaiTrackingFinders') {
        $custom = new class() implements \Ovebotai\Tracking\TrackingFinderInterface {
            public function code() { return 'custom'; }
            public function isAvailable() { return true; }
            public function find($id) { return ['carrier' => 'Custom', 'awb' => 'C1', 'tracking_url' => null]; }
        };
        array_unshift($args['finders'], $custom);
    }
};
$resolver = new TrackingResolver($s);
same('hook finder wins', 'Custom', $resolver->resolve(5)['carrier']);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n", $passed, " passed, ", $failed, " failed\n";
exit($failed ? 1 : 0);
