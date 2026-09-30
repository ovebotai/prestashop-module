<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Tracking;

use Db;
use Module;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Generic, declaratively configured finder for courier modules that keep
 * their AWBs in their own table (see couriers.php).
 *
 * Defensive by design: the module must be installed and enabled, one of the
 * candidate tables must exist AND expose the configured columns, otherwise
 * the finder reports itself unavailable and never runs a query.
 */
class TableAwbFinder implements TrackingFinderInterface
{
    /** @var array one entry of couriers.php */
    private $def;

    /** @var string|false|null resolved table (without prefix); null = not checked yet */
    private $table = null;

    /** @var string|null */
    private $urlOverride;

    /**
     * @param array $def
     * @param string|null $urlOverride URL format override with {code}
     */
    public function __construct(array $def, $urlOverride = null)
    {
        $this->def = array_merge([
            'code' => '',
            'name' => '',
            'modules' => [],
            'tables' => [],
            'order_column' => 'id_order',
            'awb_column' => 'awb',
            'sort_column' => null,
            'tracking_url' => null,
        ], $def);
        $this->urlOverride = $urlOverride !== null && $urlOverride !== '' ? (string) $urlOverride : null;
    }

    public function code()
    {
        return (string) $this->def['code'];
    }

    public function isAvailable()
    {
        $installed = false;
        foreach ((array) $this->def['modules'] as $name) {
            if (Module::isInstalled($name) && Module::isEnabled($name)) {
                $installed = true;
                break;
            }
        }

        return $installed && $this->resolveTable() !== false;
    }

    public function find($idOrder)
    {
        $table = $this->resolveTable();
        if ($table === false) {
            return null;
        }

        $sql = 'SELECT `' . bqSQL($this->def['awb_column']) . '`
                FROM `' . _DB_PREFIX_ . bqSQL($table) . '`
                WHERE `' . bqSQL($this->def['order_column']) . '` = ' . (int) $idOrder . '
                  AND `' . bqSQL($this->def['awb_column']) . '` <> \'\'
                  AND `' . bqSQL($this->def['awb_column']) . '` IS NOT NULL';
        if (!empty($this->def['sort_column'])) {
            $sql .= ' ORDER BY `' . bqSQL($this->def['sort_column']) . '` DESC';
        }

        $awb = Db::getInstance()->getValue($sql);
        if ($awb === false || $awb === null || trim((string) $awb) === '') {
            return null;
        }
        $awb = trim((string) $awb);

        $format = $this->urlOverride !== null ? $this->urlOverride : $this->def['tracking_url'];

        return [
            'carrier' => (string) $this->def['name'],
            'awb' => $awb,
            'tracking_url' => $format ? str_replace('{code}', rawurlencode($awb), (string) $format) : null,
        ];
    }

    /**
     * First candidate table that exists and has the required columns.
     *
     * @return string|false
     */
    private function resolveTable()
    {
        if ($this->table !== null) {
            return $this->table;
        }
        $this->table = false;

        $db = Db::getInstance();
        $required = array_filter([$this->def['order_column'], $this->def['awb_column'], $this->def['sort_column']]);

        foreach ((array) $this->def['tables'] as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate === '' || !preg_match('/^[a-z0-9_]+$/i', $candidate)) {
                continue;
            }
            $full = _DB_PREFIX_ . $candidate;
            try {
                if (!$db->executeS('SHOW TABLES LIKE \'' . pSQL($full) . '\'')) {
                    continue;
                }
                $columns = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($full) . '`');
            } catch (\Exception $e) {
                continue;
            }

            $names = [];
            foreach (is_array($columns) ? $columns : [] as $column) {
                if (isset($column['Field'])) {
                    $names[] = strtolower((string) $column['Field']);
                }
            }

            $ok = true;
            foreach ($required as $column) {
                if (!in_array(strtolower((string) $column), $names, true)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $this->table = $candidate;
            }
        }

        return $this->table;
    }
}
