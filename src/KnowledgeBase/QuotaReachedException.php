<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\KnowledgeBase;

use Ovebotai\Api\Exception\ApiException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The plan's knowledge-base quota blocked a CREATE. Carries the API's own
 * message (shown once in the wizard banner).
 */
class QuotaReachedException extends ApiException
{
}
