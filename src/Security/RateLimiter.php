<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Security;

use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 10 failed Basic-auth attempts within an hour block the IP for an hour.
 *
 * IPs are stored hashed (no personal data at rest). A successful auth resets
 * the counter; regenerating the credentials clears the whole table so
 * Ovebot's own egress IP can't stay locked out by calls made with the old
 * credentials.
 */
class RateLimiter
{
    const TABLE = 'ovebotai_rate_limit';
    const MAX_FAILURES = 10;
    const WINDOW = 3600;
    const BLOCK = 3600;

    /**
     * @param string $ip
     *
     * @return bool
     */
    public function isBlocked($ip)
    {
        try {
            $blockedUntil = (int) Db::getInstance()->getValue(
                'SELECT `blocked_until` FROM `' . $this->table() . '` WHERE `ip_hash` = \'' . pSQL($this->hash($ip)) . '\''
            );
        } catch (\Exception $e) {
            return false;
        }

        return $blockedUntil > time();
    }

    /**
     * @param string $ip
     */
    public function recordFailure($ip)
    {
        $hash = pSQL($this->hash($ip));
        $now = time();

        try {
            $db = Db::getInstance();
            $row = $db->getRow('SELECT `failures`, `window_start` FROM `' . $this->table() . '` WHERE `ip_hash` = \'' . $hash . '\'');

            $failures = 1;
            $windowStart = $now;
            if ($row && (int) $row['window_start'] + self::WINDOW > $now) {
                $failures = (int) $row['failures'] + 1;
                $windowStart = (int) $row['window_start'];
            }
            $blockedUntil = $failures >= self::MAX_FAILURES ? $now + self::BLOCK : 0;

            $db->execute(
                'INSERT INTO `' . $this->table() . '` (`ip_hash`, `failures`, `window_start`, `blocked_until`)
                 VALUES (\'' . $hash . '\', ' . (int) $failures . ', ' . (int) $windowStart . ', ' . (int) $blockedUntil . ')
                 ON DUPLICATE KEY UPDATE `failures` = ' . (int) $failures . ', `window_start` = ' . (int) $windowStart . ', `blocked_until` = ' . (int) $blockedUntil
            );

            // Opportunistic cleanup of stale rows (cheap, bounded).
            if (mt_rand(1, 20) === 1) {
                $db->execute('DELETE FROM `' . $this->table() . '` WHERE `blocked_until` < ' . (int) $now . ' AND `window_start` < ' . (int) ($now - self::WINDOW));
            }
        } catch (\Exception $e) {
            // never turn a rate-limit bookkeeping error into an endpoint failure
        }
    }

    /**
     * @param string $ip
     */
    public function reset($ip)
    {
        try {
            Db::getInstance()->execute('DELETE FROM `' . $this->table() . '` WHERE `ip_hash` = \'' . pSQL($this->hash($ip)) . '\'');
        } catch (\Exception $e) {
            // ignore
        }
    }

    public function clearAll()
    {
        try {
            Db::getInstance()->execute('DELETE FROM `' . $this->table() . '`');
        } catch (\Exception $e) {
            // ignore
        }
    }

    /**
     * @return string CREATE TABLE statement (used by the installer)
     */
    public static function createTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `ip_hash` CHAR(40) NOT NULL,
            `failures` INT UNSIGNED NOT NULL DEFAULT 0,
            `window_start` INT UNSIGNED NOT NULL DEFAULT 0,
            `blocked_until` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ip_hash`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
    }

    /**
     * @return string
     */
    public static function dropTableSql()
    {
        return 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`';
    }

    /**
     * @return string
     */
    private function table()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /**
     * @param string $ip
     *
     * @return string
     */
    private function hash($ip)
    {
        return sha1('ovebotai|' . (string) $ip);
    }
}
