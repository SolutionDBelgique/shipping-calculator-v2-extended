<?php
/**
 * Interface Admin - Produits Ballot
 * Version: 1.0.0
 * Ajoute le Type 3 dans l'édition produit WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// Hook pour ajouter les champs
add_action('woocommerce_product_options_shipping', 'shp_v2_ballot_product_fields');
add_action('woocommerce_process_product_meta', 'shp_v2_ballot_save_fields');

function shp_v2_ballot_product_fields() {
    global $post;
    
    echo '<div class="options_group" style="border: 2px solid #c71585; padding: 15px; margin-top: 15px; background: #fff0f8;">';
    echo '<h4 style="margin-top: 0; color: #c71585;">🎯 Type 3 : Produit Dégressif par Ballot</h4>';
    
    $is_ballot = get_post_meta($post->ID, '_degressive_ballot_enabled', true) === 'yes';
    
    woocommerce_wp_checkbox([
        'id' => '_degressive_ballot_enabled',
        'label' => 'Activer tarification par ballot',
        'description' => 'Prix par ballot dégressif selon quantité. Commande par multiples de ballots/palette.',
        'value' => get_post_meta($post->ID, '_degressive_ballot_enabled', true)
    ]);
    
    echo '<div id="ballot_config" style="' . ($is_ballot ? '' : 'display:none;') . '">';
    
    woocommerce_wp_text_input([
        'id' => '_ballots_per_pallet',
        'label' => 'Ballots par palette',
        'type' => 'number',
        'custom_attributes' => ['step' => '1', 'min' => '1'],
        'value' => get_post_meta($post->ID, '_ballots_per_pallet', true) ?: '30',
        'description' => 'Ex : 30 pour copeaux de bois (30 ballots/palette)'
    ]);
    
    echo '<p style="margin-left: 150px;"><strong>Paliers de Prix par Ballot :</strong></p>';
    echo '<div id="ballot_tiers_container" style="margin-left: 150px;">';
    
    $tiers_json = get_post_meta($post->ID, '_ballot_tiers', true);
    $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
    
    if (empty($tiers)) {
        // Paliers par défaut (copeaux de bois - 30 ballots/palette)
        $tiers = [
            ['min_pallets' => 1, 'max_pallets' => 1, 'price_per_ballot' => 10.50],
            ['min_pallets' => 2, 'max_pallets' => 3, 'price_per_ballot' => 10.20],
            ['min_pallets' => 4, 'max_pallets' => 4, 'price_per_ballot' => 9.75],
            ['min_pallets' => 5, 'max_pallets' => 32, 'price_per_ballot' => 9.55],
            ['min_pallets' => 33, 'max_pallets' => 999, 'price_per_ballot' => 8.20]
        ];
    }
    
    foreach ($tiers as $index => $tier) {
        echo '<div class="ballot-tier" style="border: 1px solid #c71585; padding: 10px; margin-bottom: 10px; background: white;">';
        echo '<p style="margin: 5px 0;">';
        echo '<label style="display: inline-block; width: 100px;">Palettes Min :</label>';
        echo '<input type="number" name="_ballot_tier_min[]" value="' . esc_attr($tier['min_pallets']) . '" style="width: 80px;" min="1" step="1"> ';
        echo '<label style="display: inline-block; width: 50px; margin-left: 10px;">Max :</label>';
        echo '<input type="number" name="_ballot_tier_max[]" value="' . esc_attr($tier['max_pallets']) . '" style="width: 80px;" min="1" step="1"> ';
        echo '<label style="display: inline-block; width: 100px; margin-left: 10px;">Prix/ballot :</label>';
        echo '<input type="number" name="_ballot_tier_price[]" value="' . esc_attr($tier['price_per_ballot']) . '" style="width: 100px;" min="0" step="0.01"> € ';
        echo '<button type="button" class="button remove-ballot-tier" style="margin-left: 10px;">Supprimer</button>';
        echo '</p>';
        echo '</div>';
    }
    
    echo '</div>';
    
    echo '<p style="margin-left: 150px;"><button type="button" id="add_ballot_tier" class="button">+ Ajouter un palier</button></p>';
    
    // Aperçu des prix
    echo '<div class="ballot-preview" style="background: #f0f0f0; padding: 15px; margin: 15px 0 0 150px; border-left: 3px solid #c71585;">';
    echo '<strong>📊 Aperçu calcul (pour 30 ballots/palette) :</strong><br>';
    echo '<small style="line-height: 1.8;">';
    echo '• 1 palette (30 ballots) = 315,00€ (10,50€/ballot)<br>';
    echo '• 4 palettes (120 ballots) = 1 170,00€ (9,75€/ballot)<br>';
    echo '• 33 palettes (990 ballots - camion) = 8 118,00€ (8,20€/ballot)';
    echo '</small>';
    echo '</div>';
    
    echo '<script>
    jQuery(document).ready(function($) {
        $("#_degressive_ballot_enabled").change(function() {
            if ($(this).is(":checked")) {
                $("#ballot_config").show();
            } else {
                $("#ballot_config").hide();
            }
        });
        
        $("#add_ballot_tier").click(function() {
            var html = \'<div class="ballot-tier" style="border: 1px solid #c71585; padding: 10px; margin-bottom: 10px; background: white;">\';
            html += \'<p style="margin: 5px 0;">\';
            html += \'<label style="display: inline-block; width: 100px;">Palettes Min :</label>\';
            html += \'<input type="number" name="_ballot_tier_min[]" value="1" style="width: 80px;" min="1" step="1"> \';
            html += \'<label style="display: inline-block; width: 50px; margin-left: 10px;">Max :</label>\';
            html += \'<input type="number" name="_ballot_tier_max[]" value="5" style="width: 80px;" min="1" step="1"> \';
            html += \'<label style="display: inline-block; width: 100px; margin-left: 10px;">Prix/ballot :</label>\';
            html += \'<input type="number" name="_ballot_tier_price[]" value="10.00" style="width: 100px;" min="0" step="0.01"> € \';
            html += \'<button type="button" class="button remove-ballot-tier" style="margin-left: 10px;">Supprimer</button>\';
            html += \'</p>\';
            html += \'</div>\';
            $("#ballot_tiers_container").append(html);
        });
        
        $(document).on("click", ".remove-ballot-tier", function() {
            $(this).closest(".ballot-tier").remove();
        });
    });
    </script>';
    
    echo '</div>'; // fin ballot_config
    echo '</div>';
}

function shp_v2_ballot_save_fields($post_id) {
    // Sauvegarder activation
    $ballot_enabled = isset($_POST['_degressive_ballot_enabled']) ? 'yes' : 'no';
    update_post_meta($post_id, '_degressive_ballot_enabled', $ballot_enabled);
    
    if ($ballot_enabled === 'yes') {
        // Sauvegarder ballots par palette
        if (isset($_POST['_ballots_per_pallet'])) {
            update_post_meta($post_id, '_ballots_per_pallet', (int) $_POST['_ballots_per_pallet']);
        }
        
        // Sauvegarder paliers
        if (isset($_POST['_ballot_tier_min']) && 
            isset($_POST['_ballot_tier_max']) && 
            isset($_POST['_ballot_tier_price'])) {
            
            $tiers = [];
            $count = count($_POST['_ballot_tier_min']);
            
            for ($i = 0; $i < $count; $i++) {
                $tiers[] = [
                    'min_pallets' => (int) $_POST['_ballot_tier_min'][$i],
                    'max_pallets' => (int) $_POST['_ballot_tier_max'][$i],
                    'price_per_ballot' => (float) $_POST['_ballot_tier_price'][$i]
                ];
            }
            
            update_post_meta($post_id, '_ballot_tiers', json_encode($tiers));
        }
    }
}
