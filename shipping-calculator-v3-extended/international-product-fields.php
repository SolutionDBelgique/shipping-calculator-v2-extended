<?php
/**
 * Champs produit multi-pays (prix, promos, paliers PL)
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('woocommerce_product_options_pricing', 'shp_v2_int_add_product_fields', 30);

function shp_v2_int_add_product_fields() {
    global $post;

    $countries = [
        'DE' => ['name' => 'Allemagne', 'flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA", 'currency' => 'EUR', 'symbol' => "\xE2\x82\xAC", 'has_promo' => true],
        'NL' => ['name' => 'Pays-Bas', 'flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB1", 'currency' => 'EUR', 'symbol' => "\xE2\x82\xAC", 'has_promo' => true],
        'PL' => ['name' => 'Pologne', 'flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB1", 'currency' => 'PLN', 'symbol' => "z\xC5\x82", 'has_promo' => false],
    ];

    echo '<div style="border: 1px solid #0073aa; padding: 15px; margin-top: 15px; background: #e8f4fd;">';
    echo '<h4 style="margin-top: 0; color: #0073aa;">v3.0 - Prix Internationaux</h4>';
    echo '<p style="color: #666; font-size: 12px;">Les prix DE et NL utilisent les memes paliers que FR (EUR). Seul PL a des paliers separes (PLN).</p>';

    foreach ($countries as $code => $info) {
        $price = get_post_meta($post->ID, "_price_{$code}", true);
        $promo_enabled = get_post_meta($post->ID, "_promo_enabled_{$code}", true);
        $sale_price = get_post_meta($post->ID, "_sale_price_{$code}", true);
        $promo_start = get_post_meta($post->ID, "_promo_start_{$code}", true);
        $promo_end = get_post_meta($post->ID, "_promo_end_{$code}", true);

        echo '<div style="border: 1px solid #ccc; padding: 10px; margin: 10px 0; background: white; border-radius: 4px;">';
        echo '<h5 style="margin: 0 0 10px 0;">' . $info['flag'] . ' ' . esc_html($info['name']) . ' (' . esc_html($info['currency']) . ')</h5>';

        woocommerce_wp_text_input([
            'id' => "_price_{$code}",
            'label' => "Prix ({$info['symbol']})",
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            'value' => $price,
            'description' => "Laisser vide = utiliser le prix FR",
        ]);

        if ($info['has_promo']) {
            woocommerce_wp_checkbox([
                'id' => "_promo_enabled_{$code}",
                'label' => 'Promo active',
                'value' => $promo_enabled,
            ]);

            woocommerce_wp_text_input([
                'id' => "_sale_price_{$code}",
                'label' => "Prix promo ({$info['symbol']})",
                'type' => 'number',
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
                'value' => $sale_price,
            ]);

            echo '<p style="padding-left: 150px;">';
            echo '<label>Du : <input type="date" name="_promo_start_' . esc_attr($code) . '" value="' . esc_attr($promo_start) . '" style="width: 150px;"></label> ';
            echo '<label>Au : <input type="date" name="_promo_end_' . esc_attr($code) . '" value="' . esc_attr($promo_end) . '" style="width: 150px;"></label>';
            echo '</p>';
        }

        echo '</div>';
    }

    // Paliers PL pour produits dégressifs m²
    $is_degressive = get_post_meta($post->ID, '_degressive_enabled', true) === 'yes';
    if ($is_degressive) {
        echo '<div style="border: 1px solid #d63638; padding: 10px; margin: 10px 0; background: #fef0f0; border-radius: 4px;">';
        echo '<h5 style="margin: 0 0 10px 0; color: #d63638;">Paliers Degressifs PL (PLN)</h5>';
        echo '<p style="color: #666; font-size: 12px;">Saisir les paliers en PLN pour la Pologne. DE et NL utilisent les memes paliers que FR.</p>';

        $tiers_pl_json = get_post_meta($post->ID, '_degressive_tiers_PL', true);
        $tiers_pl = $tiers_pl_json ? json_decode($tiers_pl_json, true) : [];

        echo '<div id="degressive_tiers_pl_container">';

        if (!empty($tiers_pl)) {
            foreach ($tiers_pl as $tier) {
                echo '<div class="degressive-tier-pl" style="border: 1px solid #ccc; padding: 8px; margin-bottom: 5px; background: white;">';
                echo '<label>Pal. Min: </label>';
                echo '<input type="number" name="_degressive_tier_pl_min[]" value="' . esc_attr($tier['min_pallets']) . '" style="width: 70px;" min="1"> ';
                echo '<label>Max: </label>';
                echo '<input type="number" name="_degressive_tier_pl_max[]" value="' . esc_attr($tier['max_pallets']) . '" style="width: 70px;" min="1"> ';
                echo '<label>Prix/m2 (PLN): </label>';
                echo '<input type="number" name="_degressive_tier_pl_price[]" value="' . esc_attr($tier['price_per_m2']) . '" style="width: 100px;" min="0" step="0.01"> ';
                echo '<button type="button" class="button remove-tier-pl" style="font-size: 11px;">X</button>';
                echo '</div>';
            }
        }

        echo '</div>';
        echo '<p><button type="button" id="add_tier_pl" class="button" style="font-size: 11px;">+ Palier PL</button></p>';
        echo '</div>';
    }

    // Paliers Ballot PL
    $is_ballot = get_post_meta($post->ID, '_degressive_ballot_enabled', true) === 'yes';
    if ($is_ballot) {
        echo '<div style="border: 1px solid #d63638; padding: 10px; margin: 10px 0; background: #fef0f0; border-radius: 4px;">';
        echo '<h5 style="margin: 0 0 10px 0; color: #d63638;">Paliers Ballot PL (PLN)</h5>';

        $ballot_tiers_pl_json = get_post_meta($post->ID, '_ballot_tiers_PL', true);
        $ballot_tiers_pl = $ballot_tiers_pl_json ? json_decode($ballot_tiers_pl_json, true) : [];

        echo '<div id="ballot_tiers_pl_container">';

        if (!empty($ballot_tiers_pl)) {
            foreach ($ballot_tiers_pl as $tier) {
                echo '<div class="ballot-tier-pl" style="border: 1px solid #ccc; padding: 8px; margin-bottom: 5px; background: white;">';
                echo '<label>Pal. Min: </label>';
                echo '<input type="number" name="_ballot_tier_pl_min[]" value="' . esc_attr($tier['min_pallets']) . '" style="width: 70px;" min="1"> ';
                echo '<label>Max: </label>';
                echo '<input type="number" name="_ballot_tier_pl_max[]" value="' . esc_attr($tier['max_pallets']) . '" style="width: 70px;" min="1"> ';
                echo '<label>Prix/ballot (PLN): </label>';
                echo '<input type="number" name="_ballot_tier_pl_price[]" value="' . esc_attr($tier['price_per_ballot']) . '" style="width: 100px;" min="0" step="0.01"> ';
                echo '<button type="button" class="button remove-ballot-tier-pl" style="font-size: 11px;">X</button>';
                echo '</div>';
            }
        }

        echo '</div>';
        echo '<p><button type="button" id="add_ballot_tier_pl" class="button" style="font-size: 11px;">+ Palier Ballot PL</button></p>';
        echo '</div>';
    }

    echo '</div>';

    // JavaScript pour ajouter/supprimer les paliers PL
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Paliers dégressifs PL
        $('#add_tier_pl').on('click', function() {
            var html = '<div class="degressive-tier-pl" style="border: 1px solid #ccc; padding: 8px; margin-bottom: 5px; background: white;">';
            html += '<label>Pal. Min: </label>';
            html += '<input type="number" name="_degressive_tier_pl_min[]" value="1" style="width: 70px;" min="1"> ';
            html += '<label>Max: </label>';
            html += '<input type="number" name="_degressive_tier_pl_max[]" value="5" style="width: 70px;" min="1"> ';
            html += '<label>Prix/m2 (PLN): </label>';
            html += '<input type="number" name="_degressive_tier_pl_price[]" value="0" style="width: 100px;" min="0" step="0.01"> ';
            html += '<button type="button" class="button remove-tier-pl" style="font-size: 11px;">X</button>';
            html += '</div>';
            $('#degressive_tiers_pl_container').append(html);
        });

        $(document).on('click', '.remove-tier-pl', function() {
            $(this).closest('.degressive-tier-pl').remove();
        });

        // Paliers ballot PL
        $('#add_ballot_tier_pl').on('click', function() {
            var html = '<div class="ballot-tier-pl" style="border: 1px solid #ccc; padding: 8px; margin-bottom: 5px; background: white;">';
            html += '<label>Pal. Min: </label>';
            html += '<input type="number" name="_ballot_tier_pl_min[]" value="1" style="width: 70px;" min="1"> ';
            html += '<label>Max: </label>';
            html += '<input type="number" name="_ballot_tier_pl_max[]" value="5" style="width: 70px;" min="1"> ';
            html += '<label>Prix/ballot (PLN): </label>';
            html += '<input type="number" name="_ballot_tier_pl_price[]" value="0" style="width: 100px;" min="0" step="0.01"> ';
            html += '<button type="button" class="button remove-ballot-tier-pl" style="font-size: 11px;">X</button>';
            html += '</div>';
            $('#ballot_tiers_pl_container').append(html);
        });

        $(document).on('click', '.remove-ballot-tier-pl', function() {
            $(this).closest('.ballot-tier-pl').remove();
        });
    });
    </script>
    <?php
}

// Sauvegarde des champs internationaux
add_action('woocommerce_process_product_meta', 'shp_v2_int_save_product_fields', 30);

function shp_v2_int_save_product_fields($post_id) {
    $countries = ['DE', 'NL', 'PL'];

    foreach ($countries as $code) {
        // Prix
        if (isset($_POST["_price_{$code}"])) {
            $val = $_POST["_price_{$code}"];
            if ($val !== '') {
                update_post_meta($post_id, "_price_{$code}", sanitize_text_field($val));
            } else {
                delete_post_meta($post_id, "_price_{$code}");
            }
        }

        // Promo
        $promo = isset($_POST["_promo_enabled_{$code}"]) ? 'yes' : 'no';
        update_post_meta($post_id, "_promo_enabled_{$code}", $promo);

        if (isset($_POST["_sale_price_{$code}"])) {
            $val = $_POST["_sale_price_{$code}"];
            if ($val !== '') {
                update_post_meta($post_id, "_sale_price_{$code}", sanitize_text_field($val));
            } else {
                delete_post_meta($post_id, "_sale_price_{$code}");
            }
        }

        if (isset($_POST["_promo_start_{$code}"])) {
            update_post_meta($post_id, "_promo_start_{$code}", sanitize_text_field($_POST["_promo_start_{$code}"]));
        }
        if (isset($_POST["_promo_end_{$code}"])) {
            update_post_meta($post_id, "_promo_end_{$code}", sanitize_text_field($_POST["_promo_end_{$code}"]));
        }
    }

    // Paliers dégressifs PL
    if (isset($_POST['_degressive_tier_pl_min']) &&
        isset($_POST['_degressive_tier_pl_max']) &&
        isset($_POST['_degressive_tier_pl_price'])) {

        $tiers = [];
        $count = count($_POST['_degressive_tier_pl_min']);
        for ($i = 0; $i < $count; $i++) {
            $tiers[] = [
                'min_pallets' => (int) $_POST['_degressive_tier_pl_min'][$i],
                'max_pallets' => (int) $_POST['_degressive_tier_pl_max'][$i],
                'price_per_m2' => (float) $_POST['_degressive_tier_pl_price'][$i],
            ];
        }
        update_post_meta($post_id, '_degressive_tiers_PL', json_encode($tiers));
    }

    // Paliers ballot PL
    if (isset($_POST['_ballot_tier_pl_min']) &&
        isset($_POST['_ballot_tier_pl_max']) &&
        isset($_POST['_ballot_tier_pl_price'])) {

        $tiers = [];
        $count = count($_POST['_ballot_tier_pl_min']);
        for ($i = 0; $i < $count; $i++) {
            $tiers[] = [
                'min_pallets' => (int) $_POST['_ballot_tier_pl_min'][$i],
                'max_pallets' => (int) $_POST['_ballot_tier_pl_max'][$i],
                'price_per_ballot' => (float) $_POST['_ballot_tier_pl_price'][$i],
            ];
        }
        update_post_meta($post_id, '_ballot_tiers_PL', json_encode($tiers));
    }
}
