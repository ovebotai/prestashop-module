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
 * The API responded, but with a 4xx/5xx status. getCode() carries the HTTP
 * status so callers can special-case it (e.g. treat 404 on a KB entry as
 * "already gone, nothing to do" rather than a hard failure).
 */
class ApiException extends OvebotaiException
{
}
