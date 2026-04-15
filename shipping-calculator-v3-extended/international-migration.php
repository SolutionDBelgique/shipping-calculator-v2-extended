<?php
/**
 * Migration v2.8 → v3.0
 * Copie les prix WooCommerce existants vers _price_FR, _price_DE, _price_NL
 * PL laissé vide (saisie manuelle en PLN)
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exécute la migration
 * @param bool $force Forcer même si déjà faite
 * @return string Message résultat
 */
function shp_v2_int_run_migration($force = false) {
    if (!$force && get_option('shp_v2_int_migration_done', false)) {
        return 'Migration deja effectuee.';
    }

    global $wpdb;

    // Récupérer tous les produits WooCommerce
    $product_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_status = 'publish'"
    );

    $migrated = 0;
    $skipped = 0;

    foreach ($product_ids as $product_id) {
        // Prix régulier WooCommerce
        $wc_price = get_post_meta($product_id, '_regular_price', true);
        if ($wc_price === '' || $wc_price === false) {
            $wc_price = get_post_meta($product_id, '_price', true);
        }

        if ($wc_price === '' || $wc_price === false) {
            $skipped++;
            continue;
        }

        // Copier vers _price_FR si pas déjà défini
        $existing_fr = get_post_meta($product_id, '_price_FR', true);
        if ($existing_fr === '' || $existing_fr === false) {
            update_post_meta($product_id, '_price_FR', $wc_price);
        }

        // Copier vers _price_DE si pas déjà défini (même prix EUR)
        $existing_de = get_post_meta($product_id, '_price_DE', true);
        if ($existing_de === '' || $existing_de === false) {
            update_post_meta($product_id, '_price_DE', $wc_price);
        }

        // Copier vers _price_NL si pas déjà défini (même prix EUR)
        $existing_nl = get_post_meta($product_id, '_price_NL', true);
        if ($existing_nl === '' || $existing_nl === false) {
            update_post_meta($product_id, '_price_NL', $wc_price);
        }

        // PL : ne rien faire, saisie manuelle en PLN

        $migrated++;
    }

    update_option('shp_v2_int_migration_done', true);

    return sprintf('Migration terminee : %d produit(s) migre(s), %d ignore(s).', $migrated, $skipped);
}
