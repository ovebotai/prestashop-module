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
 * An OAuth step failed: the token endpoint rejected the code/verifier, a
 * refresh token was revoked, or a required credential was missing. Distinct
 * from ApiException because the caller's remedy is different: reconnect the
 * account rather than retry the call.
 */
class AuthException extends OvebotaiException
{
}
