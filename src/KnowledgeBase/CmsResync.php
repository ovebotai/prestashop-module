<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\KnowledgeBase;

use CMS;
use Db;
use Ovebotai\Service\Integration;
use PrestaShopLogger;
use Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Keeps the agent's knowledge base in step with CMS edits made in PrestaShop
 * (hooks actionObjectCmsUpdateAfter / actionObjectCmsDeleteAfter).
 *
 * Runs for every shop the page is selected in (KB_PAGE_IDS) that is connected
 * with a finished setup. Errors are logged and never propagate: a CMS save
 * must never fail because Ovebot.ai is unreachable.
 */
class CmsResync
{
    /**
     * @param CMS $cms
     */
    public function onSaved(CMS $cms)
    {
        $idCms = (int) $cms->id;
        if (!$idCms) {
            return;
        }

        foreach ($this->shopsFor($idCms, true) as $idShop) {
            try {
                $integration = Integration::forShop($idShop);
                if (!$integration->isSetupComplete() || !in_array($idCms, $integration->getKbPageIds(), true)) {
                    continue;
                }
                $pages = new CmsPageProvider($integration->getUrls());
                $result = (new KbSync($integration, $pages))->sync([$idCms], (bool) $cms->active);
                foreach ($result['failed'] as $message) {
                    $this->log($idShop, 'CMS #' . $idCms . ' resync skipped: ' . $message);
                }
                if ($result['kb_limit'] !== '') {
                    $this->log($idShop, 'CMS #' . $idCms . ' resync blocked by the knowledge base quota: ' . $result['kb_limit']);
                }
            } catch (\Throwable $e) {
                $this->log($idShop, 'CMS #' . $idCms . ' resync failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param CMS $cms the deleted object (associations may already be gone)
     */
    public function onDeleted(CMS $cms)
    {
        $idCms = (int) $cms->id;
        if (!$idCms) {
            return;
        }

        foreach ($this->shopsFor($idCms, false) as $idShop) {
            try {
                $integration = Integration::forShop($idShop);
                $selected = $integration->getKbPageIds();
                if (!in_array($idCms, $selected, true)) {
                    continue;
                }

                // Forget the page locally in every case.
                $integration->saveKbPageIds(array_diff($selected, [$idCms]));

                if (!$integration->isSetupComplete()) {
                    continue;
                }

                $idLang = $integration->getUrls()->getIdLang();
                $pages = new CmsPageProvider($integration->getUrls());
                (new KbSync($integration, $pages))->deactivateDeleted(
                    $idCms,
                    self::langValue($cms->meta_title, $idLang),
                    self::langValue($cms->content, $idLang)
                );
            } catch (\Throwable $e) {
                $this->log($idShop, 'CMS #' . $idCms . ' deactivation after delete failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Shops to consider: the page's associated shops when they can still be
     * read, otherwise every shop (the delete hook fires after associations
     * are gone; the KB_PAGE_IDS check filters the rest).
     *
     * @param int $idCms
     * @param bool $associatedOnly
     *
     * @return int[]
     */
    private function shopsFor($idCms, $associatedOnly)
    {
        $all = array_map('intval', (array) Shop::getShops(false, null, true));

        if (!$associatedOnly) {
            return $all;
        }

        $rows = Db::getInstance()->executeS('SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'cms_shop` WHERE `id_cms` = ' . (int) $idCms);
        $associated = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $associated[] = (int) $row['id_shop'];
        }

        return $associated ? array_values(array_intersect($all, $associated)) : [];
    }

    /**
     * ObjectModel lang fields are arrays (id_lang => value) when the object was
     * loaded for all languages, plain strings otherwise.
     *
     * @param mixed $value
     * @param int $idLang
     *
     * @return string
     */
    private static function langValue($value, $idLang)
    {
        if (is_array($value)) {
            if (isset($value[$idLang]) && is_scalar($value[$idLang])) {
                return (string) $value[$idLang];
            }
            foreach ($value as $candidate) {
                if (is_scalar($candidate) && (string) $candidate !== '') {
                    return (string) $candidate;
                }
            }

            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param int $idShop
     * @param string $message
     */
    private function log($idShop, $message)
    {
        try {
            PrestaShopLogger::addLog('Ovebotai: ' . $message, 2, null, 'Ovebotai', (int) $idShop, true);
        } catch (\Exception $e) {
            // never break the CMS save
        }
    }
}
