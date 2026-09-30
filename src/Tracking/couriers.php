<?php
/**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * Table-based AWB finders for courier modules that do NOT write the tracking
 * number into PrestaShop's order_carrier (those are already covered by
 * NativeOrderCarrierFinder, which always runs first).
 *
 * In practice the Romanian modules checked so far DO write into order_carrier,
 * so these finders are a safety net: they still pay off when an AWB was created
 * by an older module version, or when a cancelled shipment cleared
 * order_carrier.tracking_number but left the courier's own row in place.
 *
 * Entries marked VERIFIED were read from the module's own source (version
 * noted); the others are CANDIDATES - module names, tables and columns are
 * educated guesses and must be confirmed on a real install with the courier
 * module active and an AWB generated (SHOW TABLES LIKE 'ps_%courier%';
 * DESCRIBE the table). A wrong guess is harmless: TableAwbFinder checks that
 * the table and all columns exist before running any query.
 *
 * Keys:
 *   code         short code (filter OVEBOTAI_TRACKING_FINDERS / URL override key)
 *   name         carrier name returned to Ovebot.ai
 *   modules      module technical names; at least one must be installed + enabled
 *   tables       candidate tables (without prefix); first existing one wins
 *   order_column column holding the PrestaShop order id
 *   awb_column   column holding the tracking number
 *   sort_column  column used to pick the most recent row (null = none)
 *   tracking_url public tracking URL, {code} = AWB (overridable via OVEBOTAI_TRACKING_URLS)
 */
return [
    [
        // VERIFIED against module samedaycourier 1.8.12 (github.com/sameday-courier/prestashop-plugin):
        // SamedayAwb::TABLE_NAME = "sameday_awb", columns id / id_order / awb_number.
        // The module also fills order_carrier.tracking_number, and clears it when
        // an AWB is cancelled. The tracking URL below is still a guess: the module
        // reads statuses through the API and never links to a public page.
        'code' => 'sameday',
        'name' => 'Sameday',
        'modules' => ['samedaycourier'],
        'tables' => ['sameday_awb'],
        'order_column' => 'id_order',
        'awb_column' => 'awb_number',
        'sort_column' => 'id',
        'tracking_url' => 'https://sameday.ro/#awb={code}',
    ],
    [
        // VERIFIED against module fancourier 2.5.2: table `fancourier_order`
        // (singular), columns id_fan_order / id_order / awb_number, one row per
        // order (UNIQUE KEY on id_order). The module writes the same AWB into
        // order_carrier.tracking_number and into `fancourier_order_info`.`fan_AWB`;
        // that second table is not listed because its column name differs and a
        // finder carries a single awb_column for all its candidate tables.
        // Tracking URL taken from the module's own order_actions.tpl.
        'code' => 'fancourier',
        'name' => 'FAN Courier',
        'modules' => ['fancourier', 'fancourierro', 'fancouriershipping'],
        'tables' => ['fancourier_order'],
        'order_column' => 'id_order',
        'awb_column' => 'awb_number',
        'sort_column' => 'id_fan_order',
        'tracking_url' => 'https://www.fancourier.ro/awb-tracking/?tracking={code}',
    ],
    [
        // CANDIDATE - not yet confirmed on a real install.
        'code' => 'cargus',
        'name' => 'Cargus',
        'modules' => ['urgentcargus', 'cargus', 'cargusshipping'],
        'tables' => ['awb_urgent_cargus', 'cargus_awb', 'urgentcargus_awb'],
        'order_column' => 'id_order',
        'awb_column' => 'barcode',
        'sort_column' => 'id',
        'tracking_url' => 'https://www.cargus.ro/personal/urmareste-coletul/?tracking_number={code}',
    ],
    [
        // VERIFIED against module dpdgeopost 3.0.8: table `dpdgeopost_shipment`,
        // primary key id_order, so a single row per order and nothing to sort by.
        // The tracking number IS the shipment id - Shipment::addTrackingNumber()
        // is called with it, and the same value goes into order_carrier.
        // Tracking URL from the module's own _DPDGEOPOST_TRACKING_URL_ constant.
        // The other module names are kept for older/renamed DPD integrations.
        'code' => 'dpd',
        'name' => 'DPD',
        'modules' => ['dpdgeopost', 'dpdro', 'dpdromania', 'dpd'],
        'tables' => ['dpdgeopost_shipment'],
        'order_column' => 'id_order',
        'awb_column' => 'id_shipment',
        'sort_column' => null,
        'tracking_url' => 'https://tracking.dpd.ro/?shipmentNumber={code}&language=ro',
    ],
    [
        // CANDIDATE - not yet confirmed on a real install.
        'code' => 'gls',
        'name' => 'GLS',
        'modules' => ['glsromania', 'gls', 'glsshipping'],
        'tables' => ['gls_label', 'gls_parcel', 'gls_awb'],
        'order_column' => 'id_order',
        'awb_column' => 'parcel_number',
        'sort_column' => 'id',
        'tracking_url' => 'https://gls-group.com/RO/ro/urmarire-colet/?match={code}',
    ],
    [
        // CANDIDATE - not yet confirmed on a real install.
        'code' => 'fedex',
        'name' => 'FedEx',
        'modules' => ['fedex', 'fedexshipping', 'fedexcarrier'],
        'tables' => ['fedex_shipment', 'fedex_label', 'fedex_awb'],
        'order_column' => 'id_order',
        'awb_column' => 'tracking_number',
        'sort_column' => 'id',
        'tracking_url' => 'https://www.fedex.com/fedextrack/?trknbr={code}',
    ],
];
