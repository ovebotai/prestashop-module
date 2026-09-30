<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\KnowledgeBase;

use Context;
use Ovebotai\Api\Exception\ApiException;
use Ovebotai\Api\Exception\OvebotaiException;
use Ovebotai\Service\Integration;
use Ovebotai\Service\Text;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reconciles CMS pages with the agent's knowledge base by slug.
 *
 * The API does NOT upsert on create, so the deterministic slug `cms-{id}` is
 * matched client-side against the agent's KB list: slug match -> PUT/update,
 * no match -> POST/insert (only when activating).
 */
class KbSync
{
    const SLUG_PREFIX = 'cms-';
    const MIN_BODY_LENGTH = 10;
    const TRANSLATION_DOMAIN = 'Modules.Ovebotai.Admin';

    /** @var Integration */
    private $integration;

    /** @var CmsPageProvider */
    private $pages;

    public function __construct(Integration $integration, CmsPageProvider $pages)
    {
        $this->integration = $integration;
        $this->pages = $pages;
    }

    /**
     * Bulk sync (wizard step 2 and the CMS hooks). Fetches the agent's KB list
     * once, then syncs each page independently.
     *
     * Does NOT stop at the first quota hit: a page that already exists (slug
     * match -> update) doesn't consume quota and must still go through.
     *
     * @param int[] $idsCms
     * @param bool $active
     *
     * @return array ['failed' => [id => message], 'kb_limit' => string, 'kb_limit_ids' => int[]]
     *
     * @throws OvebotaiException when the KB list itself can't be fetched
     */
    public function sync(array $idsCms, $active = true)
    {
        $failed = [];
        $kbLimit = '';
        $kbLimitIds = [];

        $idsCms = array_values(array_unique(array_filter(array_map('intval', $idsCms))));
        if (!$idsCms) {
            return ['failed' => $failed, 'kb_limit' => $kbLimit, 'kb_limit_ids' => $kbLimitIds];
        }

        $remoteBySlug = $this->fetchRemoteKbSlugs();

        foreach ($idsCms as $idCms) {
            $slug = self::slug($idCms);
            $kbId = isset($remoteBySlug[$slug]) ? (int) $remoteBySlug[$slug] : 0;

            try {
                if ($kbId) {
                    $this->updateEntry($kbId, $idCms, (bool) $active, $failed);
                } elseif ($active) {
                    $this->insertEntry($idCms, $failed);
                }
                // No slug match + deactivating -> nothing to do.
            } catch (QuotaReachedException $e) {
                if ($kbLimit === '') {
                    $kbLimit = $e->getMessage();
                }
                $kbLimitIds[] = $idCms;
            } catch (OvebotaiException $e) {
                $failed[$idCms] = $e->getMessage();
            }
        }

        return ['failed' => $failed, 'kb_limit' => $kbLimit, 'kb_limit_ids' => $kbLimitIds];
    }

    /**
     * A CMS page was deleted in PrestaShop: deactivate its entry (if any) using
     * the content we still have from the deleted object. Best-effort.
     *
     * @param int $idCms
     * @param string $title
     * @param string $htmlContent
     *
     * @return bool true when an entry was found and updated
     *
     * @throws OvebotaiException
     */
    public function deactivateDeleted($idCms, $title, $htmlContent)
    {
        $remoteBySlug = $this->fetchRemoteKbSlugs();
        $slug = self::slug($idCms);
        if (!isset($remoteBySlug[$slug])) {
            return false;
        }

        $title = Text::line($title);
        $body = self::buildBody($title, $htmlContent);
        if (Text::length($body) < self::MIN_BODY_LENGTH) {
            // Pad with the slug so the API's minimum still holds for a
            // deactivation of a page that never had much text.
            $body = trim($body . "\n\n" . $slug);
        }

        $payload = [
            'title' => $title !== '' ? $title : $slug,
            'body' => $body,
            'is_active' => false,
            'slug' => $slug,
            'source_url' => $this->integration->getUrls()->baseUrl(),
        ];

        $result = $this->integration->apiRequest('PUT', $this->integration->kbApiPath() . '/' . (int) $remoteBySlug[$slug], $payload);
        if ($result['status'] === 404) {
            return false;
        }
        $this->assertResult($result, $payload['title']);

        return true;
    }

    /**
     * @param int $idCms
     *
     * @return string
     */
    public static function slug($idCms)
    {
        return self::SLUG_PREFIX . (int) $idCms;
    }

    /**
     * Update an existing KB entry (matched by slug) in place.
     *
     * @param int $kbId
     * @param int $idCms
     * @param bool $active
     * @param array $failed
     *
     * @throws OvebotaiException
     */
    private function updateEntry($kbId, $idCms, $active, array &$failed)
    {
        $payload = $this->buildPayload($idCms, $active, $failed);
        if ($payload === null) {
            return;
        }

        $result = $this->integration->apiRequest('PUT', $this->integration->kbApiPath() . '/' . (int) $kbId, $payload);

        // Entry deleted on Ovebot's side since the list was fetched: recreate it
        // when activating, otherwise there's simply nothing left to deactivate.
        if ($result['status'] === 404) {
            if ($active) {
                $this->insertEntry($idCms, $failed);
            }

            return;
        }

        $this->assertResult($result, $payload['title']);
    }

    /**
     * Create a new KB entry (no slug match). Only ever called when activating.
     *
     * @param int $idCms
     * @param array $failed
     *
     * @throws OvebotaiException
     */
    private function insertEntry($idCms, array &$failed)
    {
        $payload = $this->buildPayload($idCms, true, $failed);
        if ($payload === null) {
            return;
        }

        $result = $this->integration->apiRequest('POST', $this->integration->kbApiPath(), $payload);

        $this->assertResult($result, $payload['title'], true);
    }

    /**
     * Loads the CMS page and builds the API payload, or returns null (and
     * records a skip reason in $failed) when the page can't / shouldn't be
     * synced: missing, disabled, or too little text for the API's minimum.
     *
     * @param int $idCms
     * @param bool $active
     * @param array $failed
     *
     * @return array|null
     */
    private function buildPayload($idCms, $active, array &$failed)
    {
        $page = $this->pages->load($idCms);
        if ($page === null) {
            return null;
        }

        if (!$page['active'] && $active) {
            $failed[$idCms] = $this->trans('Skipped "%title%" - page is not enabled.', ['%title%' => $page['title']]);

            return null;
        }

        $body = self::buildBody($page['title'], $page['content']);
        if ($active && Text::length($body) < self::MIN_BODY_LENGTH) {
            $failed[$idCms] = $this->trans('Skipped "%title%" - not enough text content to sync (minimum 10 characters).', ['%title%' => $page['title']]);

            return null;
        }

        return [
            'title' => $page['title'],
            'body' => $body,
            'is_active' => (bool) $active,
            'slug' => self::slug($idCms),
            'source_url' => $page['url'],
        ];
    }

    /**
     * @param string $title
     * @param string $html
     *
     * @return string
     */
    public static function buildBody($title, $html)
    {
        $content = Text::plain($html);
        $title = trim((string) $title);

        return trim($title . ($content !== '' ? "\n\n" . $content : ''));
    }

    /**
     * Throws on a non-2xx KB write. A quota hit on CREATE becomes a
     * QuotaReachedException so sync() can keep going with the updates.
     *
     * @param array $result
     * @param string $title
     * @param bool $isCreate
     *
     * @throws OvebotaiException
     */
    private function assertResult(array $result, $title, $isCreate = false)
    {
        if ($result['status'] >= 200 && $result['status'] < 300) {
            return;
        }

        $code = $this->integration->apiErrorCode($result);
        if ($isCreate && ($code === 'kb_limit_reached' || $result['status'] === 409 || $this->integration->isQuotaError($result))) {
            $message = isset($result['body']['error']['message']) && is_scalar($result['body']['error']['message'])
                ? (string) $result['body']['error']['message']
                : $this->integration->apiErrorMessage($result);

            throw new QuotaReachedException($message, (int) $result['status']);
        }

        throw new ApiException(
            $this->trans('Could not sync knowledge base entry for "%title%". %error%', ['%title%' => $title, '%error%' => $this->integration->apiErrorMessage($result)]),
            (int) $result['status']
        );
    }

    /**
     * The agent's full KB list (paginated) as a slug => id map.
     *
     * @return array
     *
     * @throws OvebotaiException when a page of the list can't be fetched (a
     *                           partial map would cause duplicate entries)
     */
    private function fetchRemoteKbSlugs()
    {
        $bySlug = [];
        $page = 1;
        $fetched = 0;

        do {
            $result = $this->integration->apiRequest('GET', $this->integration->kbApiPath() . '?' . http_build_query([
                'page' => $page,
                'per_page' => 100,
            ]));

            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new ApiException(
                    $this->trans('Could not load knowledge base entries from Ovebot.ai.', []) . ' ' . $this->integration->apiErrorMessage($result),
                    (int) $result['status']
                );
            }

            $entries = isset($result['body']['entries']) && is_array($result['body']['entries']) ? $result['body']['entries'] : [];
            foreach ($entries as $entry) {
                if (!isset($entry['id'], $entry['slug'])) {
                    continue;
                }
                $bySlug[(string) $entry['slug']] = (int) $entry['id'];
            }

            $total = isset($result['body']['total']) ? (int) $result['body']['total'] : 0;
            $fetched += count($entries);
            ++$page;
        } while ($entries && $fetched < $total && $page <= 50);

        return $bySlug;
    }

    /**
     * @param string $id
     * @param array $parameters
     *
     * @return string
     */
    private function trans($id, array $parameters)
    {
        return Context::getContext()->getTranslator()->trans($id, $parameters, self::TRANSLATION_DOMAIN);
    }
}
