<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace Ovebotai\Feed;

use Configuration;
use Context;
use Currency;
use Db;
use ImageType;
use Ovebotai\Service\Text;
use Product;
use Shop;
use StockAvailable;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Enabled, orderable, priced products of ONE shop in its default language.
 *
 * iterate() is a generator consumed in batches of 200 so the feed streams with
 * constant memory. counts() uses the SAME base filter (in SQL), so what the
 * wizard promises is what ships.
 */
class ProductFeedBuilder
{
    const BATCH = 200;

    /** @var Context */
    private $context;

    /** @var int */
    private $idShop;

    /** @var int */
    private $idShopGroup;

    /** @var int */
    private $idLang;

    /** @var string */
    private $currencyIso;

    /** @var bool */
    private $stockManagement;

    /** @var bool PS_ORDER_OUT_OF_STOCK: global default for out_of_stock = 2 */
    private $orderOutOfStock;

    /** @var string */
    private $imageType;

    /** @var string PS_ATTRIBUTE_ANCHOR_SEPARATOR, used to rebuild combination anchors */
    private $anchorSeparator;

    /** @var array id_category => "A > B > C" */
    private $categoryPaths = [];

    /** @var Product|null reusable, never-loaded object handed to Link::getProductLink() */
    private $linkProduct;

    /**
     * @param Context $context
     * @param int|null $idShop null = context shop
     */
    public function __construct(Context $context, $idShop = null)
    {
        $this->context = $context;
        $this->idShop = $idShop !== null ? (int) $idShop : (int) $context->shop->id;
        $this->idShopGroup = (int) Shop::getGroupFromShop($this->idShop, true);
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, $this->idShopGroup, $this->idShop);

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, $this->idShopGroup, $this->idShop));
        $this->currencyIso = (string) $currency->iso_code;

        $this->stockManagement = (bool) Configuration::get('PS_STOCK_MANAGEMENT', null, $this->idShopGroup, $this->idShop);
        $this->orderOutOfStock = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK', null, $this->idShopGroup, $this->idShop);
        $this->imageType = ImageType::getFormattedName('large');

        $separator = (string) Configuration::get('PS_ATTRIBUTE_ANCHOR_SEPARATOR');
        $this->anchorSeparator = $separator !== '' ? $separator : '-';
    }

    /**
     * Generator over feed items.
     *
     * @return \Generator
     */
    public function iterate()
    {
        $lastId = 0;
        do {
            $rows = $this->fetchBatch($lastId);
            if (!$rows) {
                break;
            }

            $ids = array_map('intval', array_column($rows, 'id_product'));
            $features = $this->features($ids);
            $covers = $this->covers($ids);
            $categories = $this->deepestCategories($ids);

            // One item per combination: "tricou roșu, M" has to be answerable
            // with a real price, a real stock figure and its own link, which a
            // single row listing "Roșu | Negru" as an attribute cannot give.
            $combinations = $this->combinations($ids);
            $ipas = [];
            foreach ($combinations as $perProduct) {
                $ipas = array_merge($ipas, array_keys($perProduct));
            }
            $values = $ipas ? $this->combinationValues($ipas) : [];
            $images = $ipas ? $this->combinationImages($ipas) : [];

            foreach ($rows as $row) {
                $id = $lastId = (int) $row['id_product'];

                if (empty($combinations[$id])) {
                    $item = $this->map($row, $features, $covers, $categories);
                    if ($item !== null) {
                        yield $item;
                    }
                    continue;
                }

                foreach ($combinations[$id] as $ipa => $combination) {
                    $combination['ipa'] = (int) $ipa;
                    $combination['values'] = isset($values[$ipa]) ? $values[$ipa] : [];
                    $combination['id_image'] = isset($images[$ipa]) ? (int) $images[$ipa] : null;

                    $item = $this->map($row, $features, $covers, $categories, $combination);
                    if ($item !== null) {
                        yield $item;
                    }
                }
            }
        } while (count($rows) === self::BATCH);
    }

    /**
     * ['total' => active products in the shop, 'feed_count' => products passing the feed filter].
     *
     * @return array
     */
    public function counts()
    {
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);

        $total = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` ps WHERE ps.id_shop = ' . (int) $this->idShop . ' AND ps.active = 1'
        );

        // Products without combinations ship as one item each...
        $feedCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p ' . $this->baseJoins() . '
             WHERE ' . $this->baseWhere() . ' AND ps.price > 0 AND ' . $this->stockWhere() . '
               AND NOT ' . $this->hasCombinationsSql()
        );

        // ...the others ship one item per orderable combination. Same rules
        // map() applies per variant: its own stock row and its own base price
        // (product price + combination impact), never the product-level row.
        $feedCount += (int) $db->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas2
                 ON (pas2.id_product_attribute = pa.id_product_attribute AND pas2.id_shop = ' . (int) $this->idShop . ')
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON (p.id_product = pa.id_product) ' . $this->baseJoins() . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sac
                 ON (sac.id_product = pa.id_product AND sac.id_product_attribute = pa.id_product_attribute'
                     . StockAvailable::addSqlShopRestriction(null, $this->idShop, 'sac') . ')
             WHERE ' . $this->baseWhere() . ' AND (ps.price + pas2.price) > 0 AND ' . $this->stockWhere('sac')
        );

        return ['total' => $total, 'feed_count' => $feedCount];
    }

    /**
     * @param int $afterId
     *
     * @return array
     */
    private function fetchBatch($afterId)
    {
        // Products with combinations are not filtered on their aggregate stock
        // row here: each variant decides for itself in map(), exactly as
        // counts() counts them. Otherwise a product whose variants are
        // orderable but whose product-level row says "0 / deny" would be
        // counted by the wizard and then silently dropped from the feed.
        // product_lang.meta_keywords (a product_rule keyword on 1.7) was dropped in 8.0.
        $metaKeywords = self::hasMetaKeywords() ? 'pl.meta_keywords, ' : '';

        $sql = 'SELECT p.id_product, p.ean13, p.reference, p.id_manufacturer, p.id_supplier,
                       pl.name, pl.description, pl.description_short, pl.link_rewrite, ' . $metaKeywords . '
                       m.name AS manufacturer, sa.quantity, sa.out_of_stock, ps.id_category_default, ps.price,
                       (SELECT cl.link_rewrite FROM `' . _DB_PREFIX_ . 'category_lang` cl
                         WHERE cl.id_category = ps.id_category_default AND cl.id_lang = ' . (int) $this->idLang . ' AND cl.id_shop = ' . (int) $this->idShop . ' LIMIT 1) AS category_rewrite
                FROM `' . _DB_PREFIX_ . 'product` p ' . $this->baseJoins() . '
                LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON (m.id_manufacturer = p.id_manufacturer)
                WHERE ' . $this->baseWhere() . '
                  AND (' . $this->stockWhere() . ' OR ' . $this->hasCombinationsSql() . ')
                  AND p.id_product > ' . (int) $afterId . '
                ORDER BY p.id_product ASC
                LIMIT ' . (int) self::BATCH;

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return string
     */
    private function baseJoins()
    {
        return ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . (int) $this->idShop . ')
                 INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.id_product = p.id_product AND pl.id_lang = ' . (int) $this->idLang . ' AND pl.id_shop = ' . (int) $this->idShop . ')
                 LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON (sa.id_product = p.id_product AND sa.id_product_attribute = 0'
                    . StockAvailable::addSqlShopRestriction(null, $this->idShop, 'sa') . ')';
    }

    /**
     * @return string
     */
    private function baseWhere()
    {
        return 'ps.active = 1 AND ps.available_for_order = 1 AND ps.visibility IN (\'both\', \'catalog\', \'search\')';
    }

    /**
     * Never recommend what can't be bought: in stock, or orderable while out
     * of stock (per product, or the shop default when the product says
     * "default"). No stock management at all = everything is in stock.
     *
     * @return string
     */
    private function stockWhere($alias = 'sa')
    {
        if (!$this->stockManagement) {
            return '1';
        }

        $alias = bqSQL($alias);

        return '(COALESCE(' . $alias . '.quantity, 0) > 0 OR ' . $alias . '.out_of_stock = 1'
            . ($this->orderOutOfStock ? ' OR ' . $alias . '.out_of_stock = 2' : '') . ')';
    }

    /**
     * Shops that sell variants have one feed item per combination, so the
     * product-level row must not be counted for them.
     *
     * @return string
     */
    private function hasCombinationsSql()
    {
        return 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa_x
                INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas_x
                    ON (pas_x.id_product_attribute = pa_x.id_product_attribute AND pas_x.id_shop = ' . (int) $this->idShop . ')
                WHERE pa_x.id_product = p.id_product)';
    }

    /**
     * @param array $row
     * @param array $attributes
     * @param array $covers
     * @param array $categories
     *
     * @return array|null null = excluded (no price / not orderable)
     */
    private function map(array $row, array $attributes, array $covers, array $categories, ?array $variant = null)
    {
        $id = (int) $row['id_product'];
        $ipa = $variant !== null ? (int) $variant['ipa'] : null;

        $specificPrice = null;
        $price = (float) Product::getPriceStatic($id, true, $ipa, 2, null, false, false, 1, false, null, null, null, $specificPrice, true, true, $this->context);
        if ($price <= 0) {
            return null;
        }
        $specificPrice = null;
        $final = (float) Product::getPriceStatic($id, true, $ipa, 2, null, false, true, 1, false, null, null, null, $specificPrice, true, true, $this->context);

        $qty = (int) ($variant !== null ? $variant['quantity'] : $row['quantity']);
        if (!$this->stockManagement) {
            $availability = 'in_stock';
            $quantity = null;
        } elseif ($qty > 0) {
            $availability = 'in_stock';
            $quantity = $qty;
        } elseif (Product::isAvailableWhenOutOfStock((int) ($variant !== null ? $variant['out_of_stock'] : $row['out_of_stock']))) {
            $availability = 'preorder';
            $quantity = null;
        } else {
            return null;
        }

        $description = Text::plain($row['description']);
        if ($description === '') {
            $description = Text::plain($row['description_short']);
        }

        $manufacturer = isset($row['manufacturer']) ? Text::line($row['manufacturer']) : '';

        $name = Text::line($row['name']);
        $map = isset($attributes[$id]) ? $attributes[$id] : [];
        $idImage = isset($covers[$id]) ? (int) $covers[$id] : null;

        if ($variant !== null) {
            // "Tricou bumbac - Roșu, M": PrestaShop's own naming for a combination,
            // so the agent quotes the product the way the storefront shows it.
            $labels = [];
            foreach ($variant['values'] as $value) {
                $map[$value['group']] = $value['value'];
                $labels[] = $value['value'];
            }
            if ($labels) {
                $name .= ' - ' . implode(', ', $labels);
            }
            if (!empty($variant['id_image'])) {
                $idImage = (int) $variant['id_image'];
            }
        }

        return [
            'ref' => $variant !== null ? $id . '-' . $ipa : (string) $id,
            'name' => $name,
            'description' => $description,
            'category' => isset($categories[$id]) ? $categories[$id] : null,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'availability' => $availability,
            'quantity' => $quantity,
            'price' => $price,
            'special' => ($final > 0 && $final < $price) ? $final : null,
            'currency' => $this->currencyIso,
            'image' => $idImage ? $this->imageUrl((string) $row['link_rewrite'], $idImage) : null,
            'url' => $variant !== null ? $this->variantUrl($row, $ipa, $variant['values']) : $this->productUrl($row),
            'attributes' => $map ? (object) $map : (object) [],
        ];
    }

    /**
     * Link::getProductLink() only avoids `new Product($id)` (a full load per
     * row) when every keyword its route needs is already available on the
     * object it receives. Passing the id as an int is not enough: an empty
     * ean13 alone makes it load the product (1.7.8 through 9). So the batch
     * row is copied onto ONE reusable, never-loaded Product whose properties
     * cover every product_rule keyword; only `tags` / `categories` routes still
     * query, and only for what they need.
     *
     * @param array $row
     *
     * @return string
     */
    private function productUrl(array $row)
    {
        $category = !empty($row['category_rewrite']) ? (string) $row['category_rewrite'] : null;

        return $this->context->link->getProductLink(
            $this->linkProduct($row, $category),
            (string) $row['link_rewrite'],
            $category,
            (string) $row['ean13'],
            $this->idLang,
            $this->idShop
        );
    }

    /**
     * @param array $row
     * @param string|null $category
     *
     * @return Product
     */
    private function linkProduct(array $row, $category)
    {
        if ($this->linkProduct === null) {
            // No id: ObjectModel does not touch the database.
            $this->linkProduct = new Product();
        }
        $product = $this->linkProduct;

        $product->id = (int) $row['id_product'];
        $product->link_rewrite = (string) $row['link_rewrite'];
        $product->ean13 = (string) $row['ean13'];
        $product->reference = isset($row['reference']) ? (string) $row['reference'] : '';
        if (self::hasMetaKeywords()) {
            $product->meta_keywords = isset($row['meta_keywords']) ? (string) $row['meta_keywords'] : '';
        }
        $product->id_manufacturer = isset($row['id_manufacturer']) ? (int) $row['id_manufacturer'] : 0;
        $product->id_supplier = isset($row['id_supplier']) ? (int) $row['id_supplier'] : 0;
        $product->id_category_default = isset($row['id_category_default']) ? (int) $row['id_category_default'] : 0;
        $product->category = $category !== null ? $category : '';
        $product->price = isset($row['price']) ? (float) $row['price'] : 0.0;
        $product->isFullyLoaded = false;

        return $product;
    }

    /**
     * Product meta keywords exist on 1.7 only (column and Product property
     * both removed in 8.0; setting the property there would be a dynamic
     * property, deprecated on PHP 8.2+).
     *
     * @return bool
     */
    private static function hasMetaKeywords()
    {
        return version_compare(_PS_VERSION_, '8.0.0', '<') && property_exists('Product', 'meta_keywords');
    }

    /**
     * Product URL pointing at ONE combination, in the storefront's own form:
     * the combination id goes through Link::getProductLink() so it lands where
     * the product route puts it ("/2-11-sweater.html" with friendly URLs,
     * "&id_product_attribute=11" without), and the anchor is the one the theme
     * JS reads to preselect the combination, WITH the attribute ids
     * ("#/3-size-l"), i.e. what Product::getAnchor($ipa, true) produces.
     *
     * The anchor is rebuilt here from the values already loaded for the batch
     * instead of letting Link call getAnchor(): that would cost one
     * getAttributesParams() query per variant.
     *
     * @param array $row
     * @param int $ipa
     * @param array $values [['id' => .., 'group' => .., 'value' => ..], ..]
     *
     * @return string
     */
    private function variantUrl(array $row, $ipa, array $values)
    {
        $category = !empty($row['category_rewrite']) ? (string) $row['category_rewrite'] : null;

        $url = $this->context->link->getProductLink(
            $this->linkProduct($row, $category),
            (string) $row['link_rewrite'],
            $category,
            (string) $row['ean13'],
            $this->idLang,
            $this->idShop,
            (int) $ipa,
            false,
            false,
            true,
            [],
            false // no core anchor: built below without an extra query
        );

        $replace = $this->anchorSeparator === '_' ? '-' : '_';
        $anchor = '';
        foreach ($values as $value) {
            $group = str_replace($this->anchorSeparator, $replace, (string) Tools::str2url($value['group']));
            $name = str_replace($this->anchorSeparator, $replace, (string) Tools::str2url($value['value']));
            if ($group === '' || $name === '') {
                continue;
            }
            $idAttribute = isset($value['id']) ? (int) $value['id'] : 0;
            $anchor .= '/' . ($idAttribute > 0 ? $idAttribute . $this->anchorSeparator : '') . $group . $this->anchorSeparator . $name;
        }

        // A stale anchor from an older core (if it ever added one) must not be doubled.
        $url = (string) preg_replace('/#.*$/', '', $url);

        return $anchor !== '' ? $url . '#' . $anchor : $url;
    }

    /**
     * Orderable combinations of the given products, most representative first
     * (the default one). Stock is read per combination, not from the product's
     * aggregate row.
     *
     * @param int[] $ids
     *
     * @return array id_product => [id_product_attribute => ['quantity', 'out_of_stock']]
     */
    private function combinations(array $ids)
    {
        $sql = 'SELECT pa.id_product, pa.id_product_attribute, sa.quantity, sa.out_of_stock
                FROM `' . _DB_PREFIX_ . 'product_attribute` pa
                INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                    ON (pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $this->idShop . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                    ON (sa.id_product = pa.id_product AND sa.id_product_attribute = pa.id_product_attribute'
                        . StockAvailable::addSqlShopRestriction(null, $this->idShop, 'sa') . ')
                WHERE pa.id_product IN (' . implode(',', array_map('intval', $ids)) . ')
                ORDER BY pa.id_product, pas.default_on DESC, pa.id_product_attribute';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['id_product']][(int) $row['id_product_attribute']] = [
                'quantity' => (int) $row['quantity'],
                'out_of_stock' => (int) $row['out_of_stock'],
            ];
        }

        return $out;
    }

    /**
     * The attribute values of each combination, in the shop's group order:
     * [["id" => 3, "group" => "Culoare", "value" => "Roșu"], ["id" => 7, "group" => "Mărime", ..]].
     * `id` is the id_attribute the storefront anchor carries.
     *
     * @param int[] $ipas
     *
     * @return array id_product_attribute => array
     */
    private function combinationValues(array $ipas)
    {
        $sql = 'SELECT pac.id_product_attribute, a.id_attribute, agl.name AS group_name, al.name AS value_name
                FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON (a.id_attribute = pac.id_attribute)
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON (al.id_attribute = a.id_attribute AND al.id_lang = ' . (int) $this->idLang . ')
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON (ag.id_attribute_group = a.id_attribute_group)
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON (agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . (int) $this->idLang . ')
                WHERE pac.id_product_attribute IN (' . implode(',', array_map('intval', $ipas)) . ')
                ORDER BY pac.id_product_attribute, ag.position, agl.name, a.position';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $group = Text::line($row['group_name']);
            $value = Text::line($row['value_name']);
            if ($group === '' || $value === '') {
                continue;
            }
            $out[(int) $row['id_product_attribute']][] = ['id' => (int) $row['id_attribute'], 'group' => $group, 'value' => $value];
        }

        return $out;
    }

    /**
     * Image assigned to each combination (its cover first), so the red shirt
     * does not ship with the picture of the black one.
     *
     * @param int[] $ipas
     *
     * @return array id_product_attribute => id_image
     */
    private function combinationImages(array $ipas)
    {
        $sql = 'SELECT pai.id_product_attribute, pai.id_image
                FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
                INNER JOIN `' . _DB_PREFIX_ . 'image` i ON (i.id_image = pai.id_image)
                INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish ON (ish.id_image = i.id_image AND ish.id_shop = ' . (int) $this->idShop . ')
                WHERE pai.id_product_attribute IN (' . implode(',', array_map('intval', $ipas)) . ')
                ORDER BY pai.id_product_attribute, ish.cover DESC, i.position ASC';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $ipa = (int) $row['id_product_attribute'];
            if (!isset($out[$ipa])) {
                $out[$ipa] = (int) $row['id_image'];
            }
        }

        return $out;
    }

    /**
     * @param string $linkRewrite
     * @param int $idImage
     *
     * @return string
     */
    private function imageUrl($linkRewrite, $idImage)
    {
        $url = $this->context->link->getImageLink($linkRewrite !== '' ? $linkRewrite : 'product', (string) (int) $idImage, $this->imageType);
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        return $url;
    }

    /**
     * Product features: {"Feature": "value1\nvalue2"}.
     *
     * @param int[] $ids
     *
     * @return array id_product => [name => values]
     */
    private function features(array $ids)
    {
        $sql = 'SELECT fp.id_product, fl.name, f.position,
                       GROUP_CONCAT(fvl.value ORDER BY fv.id_feature_value SEPARATOR \'\n\') AS `value`
                FROM `' . _DB_PREFIX_ . 'feature_product` fp
                INNER JOIN `' . _DB_PREFIX_ . 'feature` f ON (f.id_feature = fp.id_feature)
                INNER JOIN `' . _DB_PREFIX_ . 'feature_shop` fs ON (fs.id_feature = f.id_feature AND fs.id_shop = ' . (int) $this->idShop . ')
                INNER JOIN `' . _DB_PREFIX_ . 'feature_lang` fl ON (fl.id_feature = f.id_feature AND fl.id_lang = ' . (int) $this->idLang . ')
                INNER JOIN `' . _DB_PREFIX_ . 'feature_value` fv ON (fv.id_feature_value = fp.id_feature_value)
                INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON (fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = ' . (int) $this->idLang . ')
                WHERE fp.id_product IN (' . implode(',', array_map('intval', $ids)) . ')
                GROUP BY fp.id_product, fp.id_feature, fl.name, f.position
                ORDER BY fp.id_product, f.position, fl.name';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = Text::line($row['name']);
            $value = trim(html_entity_decode((string) $row['value'], ENT_QUOTES, 'UTF-8'));
            if ($name === '' || $value === '') {
                continue;
            }
            $out[(int) $row['id_product']][$name] = $value;
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array id_product => id_image (cover)
     */
    private function covers(array $ids)
    {
        $sql = 'SELECT i.id_product, i.id_image
                FROM `' . _DB_PREFIX_ . 'image` i
                INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish ON (ish.id_image = i.id_image AND ish.id_shop = ' . (int) $this->idShop . ' AND ish.cover = 1)
                WHERE i.id_product IN (' . implode(',', array_map('intval', $ids)) . ')';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['id_product']] = (int) $row['id_image'];
        }

        return $out;
    }

    /**
     * Deepest active category of each product as a " > " path (Root and Home
     * excluded). Ties go to id_category_default.
     *
     * @param int[] $ids
     *
     * @return array id_product => path
     */
    private function deepestCategories(array $ids)
    {
        $sql = 'SELECT cp.id_product, c.id_category, c.level_depth, c.nleft, c.nright,
                       (cp.id_category = ps.id_category_default) AS is_default
                FROM `' . _DB_PREFIX_ . 'category_product` cp
                INNER JOIN `' . _DB_PREFIX_ . 'category` c ON (c.id_category = cp.id_category AND c.active = 1)
                INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category = c.id_category AND cs.id_shop = ' . (int) $this->idShop . ')
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = cp.id_product AND ps.id_shop = ' . (int) $this->idShop . ')
                WHERE cp.id_product IN (' . implode(',', array_map('intval', $ids)) . ') AND c.level_depth >= 2
                ORDER BY cp.id_product, c.level_depth DESC, is_default DESC';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $idProduct = (int) $row['id_product'];
            if (isset($out[$idProduct])) {
                continue; // first row per product = deepest (default wins ties)
            }
            $path = $this->categoryPath((int) $row['id_category'], (int) $row['nleft'], (int) $row['nright']);
            if ($path !== '') {
                $out[$idProduct] = $path;
            }
        }

        return $out;
    }

    /**
     * @param int $idCategory
     * @param int $nleft
     * @param int $nright
     *
     * @return string
     */
    private function categoryPath($idCategory, $nleft, $nright)
    {
        if (isset($this->categoryPaths[$idCategory])) {
            return $this->categoryPaths[$idCategory];
        }

        $sql = 'SELECT cl.name
                FROM `' . _DB_PREFIX_ . 'category` c
                INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.id_category = c.id_category AND cl.id_lang = ' . (int) $this->idLang . ' AND cl.id_shop = ' . (int) $this->idShop . ')
                WHERE c.nleft <= ' . (int) $nleft . ' AND c.nright >= ' . (int) $nright . ' AND c.level_depth >= 2
                ORDER BY c.level_depth ASC';

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = Text::line($row['name']);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $this->categoryPaths[$idCategory] = implode(' > ', $names);
    }
}
