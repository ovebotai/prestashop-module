<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Tracking;

use Hook;
use Ovebotai\Config\Settings;
use PrestaShopLogger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Runs the AWB finders in order and returns the first hit.
 *
 * Order: native order_carrier first, then the table-based courier finders from
 * couriers.php. OVEBOTAI_TRACKING_FINDERS (JSON list of codes, case-insensitive)
 * restricts the set; OVEBOTAI_TRACKING_URLS ({code: url with {code}}) overrides
 * the public tracking URL. Other modules can add / replace finders through the
 * `actionOvebotaiTrackingFinders` hook (params: 'finders' => &array, 'id_shop').
 *
 * Never throws: a broken finder is logged and skipped.
 */
class TrackingResolver
{
    const HOOK = 'actionOvebotaiTrackingFinders';

    /** @var Settings */
    private $settings;

    /** @var TrackingFinderInterface[]|null */
    private $finders = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param int $idOrder
     *
     * @return array|null ['carrier', 'awb', 'tracking_url']
     */
    public function resolve($idOrder)
    {
        $hit = null;

        foreach ($this->finders() as $finder) {
            try {
                if (!$finder->isAvailable()) {
                    continue;
                }
                $result = $finder->find((int) $idOrder);
                if (!is_array($result) || empty($result['awb'])) {
                    continue;
                }

                $found = [
                    'carrier' => isset($result['carrier']) ? (string) $result['carrier'] : '',
                    'awb' => (string) $result['awb'],
                    'tracking_url' => !empty($result['tracking_url']) ? (string) $result['tracking_url'] : null,
                ];

                if ($hit === null) {
                    if ($found['tracking_url'] !== null) {
                        return $found;
                    }
                    // The native finder builds its URL from carrier.url ("@" placeholder),
                    // which many shops leave empty. Keep the AWB and let the remaining
                    // finders supply the link instead of returning a bare number.
                    $hit = $found;
                    continue;
                }

                // Borrow a URL only from a finder that reports the SAME AWB: anything
                // else would be another courier's link on this order's number.
                if ($found['tracking_url'] !== null && strcasecmp($found['awb'], $hit['awb']) === 0) {
                    $hit['tracking_url'] = $found['tracking_url'];

                    return $hit;
                }
            } catch (\Throwable $e) {
                $this->log('tracking finder "' . $finder->code() . '" failed: ' . $e->getMessage());
            }
        }

        return $hit;
    }

    /**
     * @return TrackingFinderInterface[]
     */
    public function finders()
    {
        if ($this->finders !== null) {
            return $this->finders;
        }

        $urls = $this->settings->getArray(Settings::TRACKING_URLS);
        $filter = array_map('strtolower', array_filter(array_map('strval', $this->settings->getArray(Settings::TRACKING_FINDERS))));

        $finders = [new NativeOrderCarrierFinder(isset($urls['native']) ? (string) $urls['native'] : null)];

        $definitions = include __DIR__ . '/couriers.php';
        foreach (is_array($definitions) ? $definitions : [] as $def) {
            if (empty($def['code'])) {
                continue;
            }
            $finders[] = new TableAwbFinder($def, isset($urls[$def['code']]) ? (string) $urls[$def['code']] : null);
        }

        if ($filter) {
            $finders = array_values(array_filter($finders, function ($finder) use ($filter) {
                return in_array(strtolower($finder->code()), $filter, true);
            }));
        }

        try {
            Hook::exec(self::HOOK, ['finders' => &$finders, 'id_shop' => $this->settings->getIdShop()]);
        } catch (\Throwable $e) {
            $this->log('tracking finders hook failed: ' . $e->getMessage());
        }

        $this->finders = array_values(array_filter($finders, function ($finder) {
            return $finder instanceof TrackingFinderInterface;
        }));

        return $this->finders;
    }

    /**
     * @param string $message
     */
    private function log($message)
    {
        try {
            PrestaShopLogger::addLog('Ovebotai: ' . $message, 2, null, 'Ovebotai', $this->settings->getIdShop(), true);
        } catch (\Exception $e) {
            // ignore
        }
    }
}
