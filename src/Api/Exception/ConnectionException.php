<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Api\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The HTTP request never completed: DNS / TLS / timeout / transport-level
 * failure. No response body was received, so unlike ApiException there is no
 * status code to key off; the connection simply couldn't be made.
 */
class ConnectionException extends OvebotaiException
{
}
