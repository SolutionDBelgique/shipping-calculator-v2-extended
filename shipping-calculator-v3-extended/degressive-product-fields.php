<?php
/**
 * Ajout des champs pour produits dégressifs
 */

if (!defined('ABSPATH')) {
    exit;
}

// Hook pour ajouter les champs dégressifs dans l'édition produit
add_action('woocommerce_product_options_shipping', 'shp_v2_ext_add_degressive_fields', 20);

function shp_v2_ext_add_degressive_fields() {
    global $post;
    
    echo '<div style="border: 1px solid #2271b1; padding: 15px; margin-top: 15px; background: #f0f6fc;">';
    echo '<h4 style="margin-top: 0; color: #2271b1;">Type 2 : Produit Dégressif au m² 🆕</h4>';
    
    $is_degressive = get_post_meta($post->ID, '_degressive_enabled', true) === 'yes';
    
    woocommerce_wp_checkbox([
        'id' => '_degressive_enabled',
        'label' => 'Activer tarification dégressive',
        'description' => 'Prix au m² dégressif selon quantité. Commande par multiples de surface/palette.',
        'value' => get_post_meta($post->ID, '_degressive_enabled', true)
    ]);
    
    echo '<div id="degressive_config" style="' . ($is_degressive ? '' : 'display:none;') . '">';
    
    woocommerce_wp_text_input([
        'id' => '_degressive_surface_per_pallet',
        'label' => 'Surface par palette (m²)',
        'type' => 'number',
        'custom_attributes' => ['step' => '1', 'min' => '1'],
        'value' => get_post_meta($post->ID, '_degressive_surface_per_pallet', true) ?: '35',
        'description' => 'Ex: 35 pour dalle 35 m²/palette, 40 pour dalle 40 m²/palette'
    ]);
    
    echo '<p><strong>Paliers de Prix :</strong></p>';
    echo '<div id="degressive_tiers_container">';
    
    $tiers_json = get_post_meta($post->ID, '_degressive_tiers', true);
    $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
    
    if (empty($tiers)) {
        // Paliers par défaut (dalle 35 m²)
        $tiers = [
            ['min_pallets' => 1, 'max_pallets' => 2, 'price_per_m2' => 20.58],
            ['min_pallets' => 3, 'max_pallets' => 5, 'price_per_m2' => 20.15],
            ['min_pallets' => 6, 'max_pallets' => 8, 'price_per_m2' => 19.75],
            ['min_pallets' => 9, 'max_pallets' => 11, 'price_per_m2' => 19.35],
            ['min_pallets' => 12, 'max_pallets' => 19, 'price_per_m2' => 18.93],
            ['min_pallets' => 20, 'max_pallets' => 999, 'price_per_m2' => 18.10]
        ];
    }
    
    foreach ($tiers as $index => $tier) {
        echo '<div class="degressive-tier" style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; background: white;">';
        echo '<p>';
        echo '<label>Palettes Min: </label>';
        echo '<input type="number" name="_degressive_tier_min[]" value="' . esc_attr($tier['min_pallets']) . '" style="width: 80px;" min="1" step="1"> ';
        echo '<label>Max: </label>';
        echo '<input type="number" name="_degressive_tier_max[]" value="' . esc_attr($tier['max_pallets']) . '" style="width: 80px;" min="1" step="1"> ';
        echo '<label>Prix/m²: </label>';
        $currency_sym = class_exists('SHP_V2_Country_Config') ? SHP_V2_Country_Config::get_instance()->get_currency_symbol('FR') : '€';
        echo '<input type="number" name="_degressive_tier_price[]" value="' . esc_attr($tier['price_per_m2']) . '" style="width: 100px;" min="0" step="0.01"> ' . esc_html($currency_sym) . ' ';
        echo '<button type="button" class="button remove-tier">Supprimer</button>';
        echo '</p>';
        echo '</div>';
    }
    
    echo '</div>';
    
    echo '<p><button type="button" id="add_tier" class="button">+ Ajouter un palier</button></p>';
    
    echo '<script>
    jQuery(document).ready(function($) {
        $("#_degressive_enabled").change(function() {
            if ($(this).is(":checked")) {
                $("#degressive_config").show();
            } else {
                $("#degressive_config").hide();
            }
        });
        
        $("#add_tier").click(function() {
            var html = \'<div class="degressive-tier" style="border: 1px solid #ccc; padding: 10px; margin-bottom: 10px; background: white;">\';
            html += \'<p>\';
            html += \'<label>Palettes Min: </label>\';
            html += \'<input type="number" name="_degressive_tier_min[]" value="1" style="width: 80px;" min="1" step="1"> \';
            html += \'<label>Max: </label>\';
            html += \'<input type="number" name="_degressive_tier_max[]" value="5" style="width: 80px;" min="1" step="1"> \';
            html += \'<label>Prix/m²: </label>\';
            html += \'<input type="number" name="_degressive_tier_price[]" value="20.00" style="width: 100px;" min="0" step="0.01"> \' + \'€\' + \' \';
            html += \'<button type="button" class="button remove-tier">Supprimer</button>\';
            html += \'</p>\';
            html += \'</div>\';
            $("#degressive_tiers_container").append(html);
        });
        
        $(document).on("click", ".remove-tier", function() {
            $(this).closest(".degressive-tier").remove();
        });
    });
    </script>';
    
    echo '<div style="margin-top: 10px; padding: 10px 12px; background: #fff8e1; border-left: 4px solid #f0a500; border-radius: 3px; font-size: 13px;">';
    echo '📌 <strong>Affichage du tableau des tarifs :</strong> le tableau ne s\'affiche pas automatiquement sur la fiche produit. ';
    echo 'Ajoutez le shortcode <code>[tableau_degressif]</code> dans le contenu du produit à l\'endroit souhaité.';
    echo '</div>';

    echo '</div>'; // fin degressive_config
    echo '</div>';

    echo '<p style="padding-left: 150px; color: #d63638; font-weight: bold;">';
    echo '⚠️ Ne cochez QU\'UNE SEULE option par produit !';
    echo '</p>';
}

// Hook pour sauvegarder les champs
add_action('woocommerce_process_product_meta', 'shp_v2_ext_save_degressive_fields', 20);

function shp_v2_ext_save_degressive_fields($post_id) {
    // Produit dégressif
    $degressive_enabled = isset($_POST['_degressive_enabled']) ? 'yes' : 'no';
    update_post_meta($post_id, '_degressive_enabled', $degressive_enabled);
    
    if ($degressive_enabled === 'yes') {
        // Surface par palette
        if (isset($_POST['_degressive_surface_per_pallet'])) {
            update_post_meta($post_id, '_degressive_surface_per_pallet', (int) $_POST['_degressive_surface_per_pallet']);
        }
        
        // Paliers
        if (isset($_POST['_degressive_tier_min']) && 
            isset($_POST['_degressive_tier_max']) && 
            isset($_POST['_degressive_tier_price'])) {
            
            $tiers = [];
            $count = count($_POST['_degressive_tier_min']);
            
            for ($i = 0; $i < $count; $i++) {
                $tiers[] = [
                    'min_pallets' => (int) $_POST['_degressive_tier_min'][$i],
                    'max_pallets' => (int) $_POST['_degressive_tier_max'][$i],
                    'price_per_m2' => (float) $_POST['_degressive_tier_price'][$i]
                ];
            }
            
            update_post_meta($post_id, '_degressive_tiers', json_encode($tiers));
        }
    }
}

// Hook pour afficher les infos dans le panier
add_filter('woocommerce_get_item_data', 'shp_v2_ext_display_degressive_cart_info', 20, 2);

function shp_v2_ext_display_degressive_cart_info($item_data, $cart_item) {
    // Info produit dégressif
    if (isset($cart_item['degressive_data'])) {
        $data = $cart_item['degressive_data'];
        $item_data[] = [
            'key' => 'Surface',
            'value' => sprintf('%d m² (%d palette%s)', 
                $data['m2'], 
                $data['palettes'],
                $data['palettes'] > 1 ? 's' : '')
        ];
    }
    
    return $item_data;
}

// Rendre la quantité non-modifiable dans le panier pour les produits dégressifs
add_filter('woocommerce_cart_item_quantity', 'shp_v2_ext_degressive_cart_quantity', 10, 3);

function shp_v2_ext_degressive_cart_quantity($product_quantity, $cart_item_key, $cart_item) {
    if (isset($cart_item['degressive_data'])) {
        // Afficher la quantité comme texte non-modifiable
        return '<span style="font-weight: bold;">1</span>';
    }
    
    return $product_quantity;
}

// Shortcode [tableau_degressif] — à placer manuellement dans la fiche produit
add_shortcode('tableau_degressif', 'shp_v2_ext_display_degressive_table');

function shp_v2_ext_display_degressive_table() {
    global $product;

    if (!$product || !is_a($product, 'WC_Product')) {
        $product = wc_get_product(get_the_ID());
    }

    if (!$product) {
        return '';
    }

    $degressive = SHP_V2_Extended_Degressive_Products::get_instance();

    if (!$degressive->is_degressive($product->get_id())) {
        return '';
    }

    $config = $degressive->get_config($product->get_id());

    if (!$config || empty($config['tiers'])) {
        return '';
    }

    $tiers = array_reverse($config['tiers']);
    $surface_per_pallet = $config['surface_per_pallet'];

    $display_sym = class_exists('SHP_V2_International_Manager') && class_exists('SHP_V2_Country_Config')
        ? SHP_V2_Country_Config::get_instance()->get_currency_symbol(SHP_V2_International_Manager::get_instance()->get_current_country())
        : '€';

    ob_start();
    ?>
    <div style="margin-top: 20px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">
        <details open>
            <summary style="cursor: pointer; font-weight: bold; color: #2271b1; font-size: 14px; margin-bottom: 10px;">📊 Tarifs Dégressifs (cliquer pour masquer)</summary>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: #2271b1; color: white;">
                        <th style="padding: 6px 8px; text-align: left; font-size: 12px;">Quantité</th>
                        <th style="padding: 6px 8px; text-align: right; font-size: 12px;">Prix / m²</th>
                        <th style="padding: 6px 8px; text-align: right; font-size: 12px;">Ex. Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tiers as $index => $tier) :
                    $min_m2 = $tier['min_pallets'] * $surface_per_pallet;
                    $max_m2 = $tier['max_pallets'] * $surface_per_pallet;
                    $bg_color = $index % 2 === 0 ? '#fff' : '#f5f5f5';
                    $example_total = $min_m2 * $tier['price_per_m2'];
                ?>
                    <tr style="background: <?php echo $bg_color; ?>;">
                        <td style="padding: 5px 8px;">
                            <?php if ($tier['max_pallets'] >= 999) : ?>
                                <strong style="font-size: 13px;"><?php echo $min_m2; ?> m²+</strong><br>
                                <small style="font-size: 11px; color: #666;">(<?php echo $tier['min_pallets']; ?> pal.+)</small>
                            <?php else : ?>
                                <strong style="font-size: 13px;"><?php echo $min_m2; ?>-<?php echo $max_m2; ?> m²</strong><br>
                                <small style="font-size: 11px; color: #666;">(<?php echo $tier['min_pallets']; ?>-<?php echo $tier['max_pallets']; ?> pal.)</small>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 5px 8px; text-align: right; font-size: 15px; font-weight: bold; color: #2271b1;">
                            <?php echo number_format($tier['price_per_m2'], 2, ',', ' ') . ' ' . esc_html($display_sym); ?>
                        </td>
                        <td style="padding: 5px 8px; text-align: right; font-size: 12px;">
                            <span style="color: #666;">Ex: <?php echo $min_m2; ?> m²</span><br>
                            <strong><?php echo number_format($example_total, 2, ',', ' ') . ' ' . esc_html($display_sym); ?></strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin: 8px 0 0 0; font-size: 11px; color: #666; font-style: italic;">
                ℹ️ Plus vous commandez, plus le prix diminue !
            </p>
        </details>
    </div>
    <?php
    return ob_get_clean();
}
