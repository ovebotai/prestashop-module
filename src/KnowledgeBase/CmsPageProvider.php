<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\KnowledgeBase;

use Db;
use DbQuery;
use Ovebotai\Service\Text;
use Ovebotai\Service\Urls;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * CMS pages of ONE shop, in the shop's default language: the list for wizard
 * step 2 and the loader used by the KB sync.
 */
class CmsPageProvider
{
    /** @var Urls */
    private $urls;

    /** @var int */
    private $idShop;

    /** @var int */
    private $idLang;

    public function __construct(Urls $urls)
    {
        $this->urls = $urls;
        $this->idShop = $urls->getIdShop();
        $this->idLang = $urls->getIdLang();
    }

    /**
     * Enabled CMS pages associated with the shop, ordered by title. When the
     * shop has never saved a selection every page defaults to checked;
     * afterwards the saved selection is honoured.
     *
     * @param int[] $savedIds
     *
     * @return array [['id' => int, 'title' => string, 'url' => string, 'checked' => bool], ...]
     */
    public function listForWizard(array $savedIds)
    {
        $savedIds = array_map('intval', $savedIds);

        $q = new DbQuery();
        $q->select('c.id_cms, cl.meta_title, cl.link_rewrite');
        $q->from('cms', 'c');
        $q->innerJoin('cms_shop', 'cs', 'cs.id_cms = c.id_cms AND cs.id_shop = ' . (int) $this->idShop);
        $q->innerJoin('cms_lang', 'cl', 'cl.id_cms = c.id_cms AND cl.id_lang = ' . (int) $this->idLang . ' AND cl.id_shop = ' . (int) $this->idShop);
        $q->where('c.active = 1');
        $q->orderBy('cl.meta_title ASC');

        $rows = Db::getInstance()->executeS($q);
        $pages = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) $row['id_cms'];
            $pages[] = [
                'id' => $id,
                'title' => Text::line($row['meta_title']),
                'url' => $this->urls->cmsUrl($id, (string) $row['link_rewrite']),
                'checked' => $savedIds ? in_array($id, $savedIds, true) : true,
            ];
        }

        return $pages;
    }

    /**
     * @param int $idCms
     *
     * @return array|null ['id', 'title', 'content' (html), 'active', 'url'] or null when the page
     *                    doesn't exist or isn't associated with this shop
     */
    public function load($idCms)
    {
        $q = new DbQuery();
        $q->select('c.id_cms, c.active, cl.meta_title, cl.content, cl.link_rewrite');
        $q->from('cms', 'c');
        $q->innerJoin('cms_shop', 'cs', 'cs.id_cms = c.id_cms AND cs.id_shop = ' . (int) $this->idShop);
        $q->leftJoin('cms_lang', 'cl', 'cl.id_cms = c.id_cms AND cl.id_lang = ' . (int) $this->idLang . ' AND cl.id_shop = ' . (int) $this->idShop);
        $q->where('c.id_cms = ' . (int) $idCms);

        $row = Db::getInstance()->getRow($q);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id_cms'],
            'title' => Text::line($row['meta_title']),
            'content' => (string) $row['content'],
            'active' => (int) $row['active'] === 1,
            'url' => $this->urls->cmsUrl((int) $row['id_cms'], (string) $row['link_rewrite']),
        ];
    }

    /**
     * @param int $idCms
     *
     * @return bool
     */
    public function isAssociated($idCms)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cms_shop` WHERE `id_cms` = ' . (int) $idCms . ' AND `id_shop` = ' . (int) $this->idShop
        );
    }

    /**
     * @return int
     */
    public function getIdLang()
    {
        return $this->idLang;
    }
}
