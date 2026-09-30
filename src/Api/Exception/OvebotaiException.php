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
 * Base class for every exception thrown by the Ovebot.ai integration.
 *
 * Catching this one type is enough to trap any integration-originated
 * failure; the subclasses only matter when a caller wants to react
 * differently to a transport failure vs. an API rejection vs. an auth problem.
 */
class OvebotaiException extends \Exception
{
}
