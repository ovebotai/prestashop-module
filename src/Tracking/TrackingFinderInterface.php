<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Tracking;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One source of AWB / tracking numbers. Third-party modules can add their own
 * through the `actionOvebotaiTrackingFinders` hook (see TrackingResolver).
 */
interface TrackingFinderInterface
{
    /**
     * @return string short code used by OVEBOTAI_TRACKING_FINDERS / OVEBOTAI_TRACKING_URLS
     */
    public function code();

    /**
     * Cheap check: module installed + enabled, table exists (memoised).
     *
     * @return bool
     */
    public function isAvailable();

    /**
     * @param int $idOrder
     *
     * @return array|null ['carrier' => string, 'awb' => string, 'tracking_url' => string|null]
     */
    public function find($idOrder);
}
