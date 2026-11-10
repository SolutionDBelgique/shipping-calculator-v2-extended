<?php
/**
 * MK Transport Calculator V4 - Champ produit "Palette complète"
 *
 * Réintroduit l'écran produit qui avait disparu entre v3-extended et v4 :
 * sans cette case à cocher, personne ne peut (dés)activer _palette_complete_v2
 * sur un produit, alors que MK_V4_Calculator::DALLE_META_KEY en dépend pour
 * détecter les dalles/palettes dans le calcul du barème fixe.
 *
 * Logique "palette complète" (distincte du système ballot/dégressif de v3) :
 * 1 unité achetée = 1 palette = 35 m², au prix normal du produit WooCommerce.
 * Aucun calcul dynamique de prix ici — uniquement le flag consommé par le
 * calculateur de frais de transport.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Product_Fields {

    private static $instance = null;

    const META_KEY = '_palette_complete_v2';
    const INFO_META_KEY = '_palette_info_v2';
    const FIXED_ENABLED_META_KEY = '_mk_v4_fixed_shipping_enabled';
    const FIXED_AMOUNT_META_KEY = '_mk_v4_fixed_shipping_amount';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_shipping', [$this, 'add_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_fields']);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_info'], 10, 2);
    }

    public function add_fields() {
        global $post;

        echo '<div class="options_group">';
        echo '<h4>🚚 MK Transport Calculator V4</h4>';

        woocommerce_wp_checkbox([
            'id' => self::META_KEY,
            'label' => 'Palette complète',
            'description' => '1 unité achetée = 1 palette (35 m²). Utilisé par le barème fixe dalles du calcul de transport.',
            'value' => get_post_meta($post->ID, self::META_KEY, true),
        ]);

        woocommerce_wp_text_input([
            'id' => self::INFO_META_KEY,
            'label' => 'Info palette',
            'placeholder' => 'Ex: 40 dalles × 0.96 m²',
            'value' => get_post_meta($post->ID, self::INFO_META_KEY, true),
        ]);

        echo '</div>';

        $this->add_fixed_shipping_fields();
        $this->add_degressive_fields();
    }

    /**
     * Tarif de transport fixe au niveau produit (bypass).
     * Quand il est actif, ce produit est retiré du calcul dalles / pourcentage
     * (voir MK_V4_Calculator::analyze_cart) et son montant fixe × quantité est
     * simplement ajouté aux frais de livraison.
     */
    private function add_fixed_shipping_fields() {
        global $post;

        $is_fixed = get_post_meta($post->ID, self::FIXED_ENABLED_META_KEY, true) === 'yes';

        echo '<div class="options_group" style="border: 1px solid #d63638; padding: 15px; margin-top: 15px; background: #fcf0f1;">';
        echo '<h4 style="margin-top: 0; color: #d63638;">🔒 Frais de transport fixes (bypass du calcul)</h4>';

        woocommerce_wp_checkbox([
            'id' => self::FIXED_ENABLED_META_KEY,
            'label' => 'Forcer un tarif de transport fixe',
            'description' => 'Ce produit est exclu du barème dalles et du système au pourcentage. Seul son montant fixe (× quantité) est ajouté aux frais de livraison.',
            'value' => get_post_meta($post->ID, self::FIXED_ENABLED_META_KEY, true),
        ]);

        echo '<div id="mk_v4_fixed_shipping_config" style="' . ($is_fixed ? '' : 'display:none;') . '">';

        woocommerce_wp_text_input([
            'id' => self::FIXED_AMOUNT_META_KEY,
            'label' => 'Montant fixe par unité (€)',
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            'description' => 'Multiplié par la quantité commandée. Ex : 45 € × 3 palettes = 135 €.',
            'desc_tip' => true,
            'value' => get_post_meta($post->ID, self::FIXED_AMOUNT_META_KEY, true),
        ]);

        echo '</div>';

        echo '<script>
        jQuery(function($) {
            $("#' . self::FIXED_ENABLED_META_KEY . '").change(function() {
                $("#mk_v4_fixed_shipping_config").toggle($(this).is(":checked"));
            });
        });
        </script>';

        echo '</div>';
    }

    /**
     * Prix dégressif au m² selon le nombre de palettes commandées (portage de v3).
     * S'applique en plus de "Palette complète" : la quantité panier (= nb de palettes)
     * sert à la fois au barème de port fixe et au palier de prix ici.
     */
    private function add_degressive_fields() {
        global $post;

        $is_degressive = get_post_meta($post->ID, MK_V4_Degressive_Pricing::ENABLED_META_KEY, true) === 'yes';

        echo '<div class="options_group" style="border: 1px solid #2271b1; padding: 15px; margin-top: 15px; background: #f0f6fc;">';
        echo '<h4 style="margin-top: 0; color: #2271b1;">📊 Prix dégressif au m² (par palier de palettes)</h4>';

        woocommerce_wp_checkbox([
            'id' => MK_V4_Degressive_Pricing::ENABLED_META_KEY,
            'label' => 'Activer le prix dégressif',
            'description' => 'Le prix au m² diminue selon le nombre de palettes commandées (voir paliers ci-dessous).',
            'value' => get_post_meta($post->ID, MK_V4_Degressive_Pricing::ENABLED_META_KEY, true),
        ]);

        echo '<div id="mk_v4_degressive_config" style="' . ($is_degressive ? '' : 'display:none;') . '">';

        woocommerce_wp_text_input([
            'id' => MK_V4_Degressive_Pricing::SURFACE_META_KEY,
            'label' => 'Surface par palette (m²)',
            'type' => 'number',
            'custom_attributes' => ['step' => '1', 'min' => '1'],
            'value' => get_post_meta($post->ID, MK_V4_Degressive_Pricing::SURFACE_META_KEY, true) ?: '35',
        ]);

        echo '<p><strong>Paliers de prix :</strong></p>';
        echo '<div id="mk_v4_degressive_tiers_container">';

        $tiers_json = get_post_meta($post->ID, MK_V4_Degressive_Pricing::TIERS_META_KEY, true);
        $tiers = $tiers_json ? json_decode($tiers_json, true) : [];

        foreach ((array) $tiers as $tier) {
            echo '<p class="mk-v4-degressive-tier">';
            echo '<label>Palettes min : </label>';
            echo '<input type="number" name="_mk_v4_degressive_tier_min[]" value="' . esc_attr($tier['min_pallets']) . '" style="width: 80px;" min="1" step="1"> ';
            echo '<label>max : </label>';
            echo '<input type="number" name="_mk_v4_degressive_tier_max[]" value="' . esc_attr($tier['max_pallets']) . '" style="width: 80px;" min="1" step="1"> ';
            echo '<label>Prix/m² : </label>';
            echo '<input type="number" name="_mk_v4_degressive_tier_price[]" value="' . esc_attr($tier['price_per_m2']) . '" style="width: 100px;" min="0" step="0.01"> € ';
            echo '<button type="button" class="button mk-v4-remove-tier">Supprimer</button>';
            echo '</p>';
        }

        echo '</div>';
        echo '<p><button type="button" id="mk_v4_add_tier" class="button">+ Ajouter un palier</button></p>';

        echo '</div>'; // fin mk_v4_degressive_config

        echo '<script>
        jQuery(document).ready(function($) {
            $("#' . MK_V4_Degressive_Pricing::ENABLED_META_KEY . '").change(function() {
                $("#mk_v4_degressive_config").toggle($(this).is(":checked"));
            });
            $("#mk_v4_add_tier").click(function() {
                var html = \'<p class="mk-v4-degressive-tier">\'
                    + \'<label>Palettes min : </label>\'
                    + \'<input type="number" name="_mk_v4_degressive_tier_min[]" value="1" style="width: 80px;" min="1" step="1"> \'
                    + \'<label>max : </label>\'
                    + \'<input type="number" name="_mk_v4_degressive_tier_max[]" value="1" style="width: 80px;" min="1" step="1"> \'
                    + \'<label>Prix/m² : </label>\'
                    + \'<input type="number" name="_mk_v4_degressive_tier_price[]" value="0.00" style="width: 100px;" min="0" step="0.01"> € \'
                    + \'<button type="button" class="button mk-v4-remove-tier">Supprimer</button>\'
                    + \'</p>\';
                $("#mk_v4_degressive_tiers_container").append(html);
            });
            $(document).on("click", ".mk-v4-remove-tier", function() {
                $(this).closest(".mk-v4-degressive-tier").remove();
            });
        });
        </script>';

        echo '</div>';
    }

    public function save_fields($post_id) {
        $value = isset($_POST[self::META_KEY]) ? 'yes' : 'no';
        update_post_meta($post_id, self::META_KEY, $value);

        if (isset($_POST[self::INFO_META_KEY])) {
            update_post_meta($post_id, self::INFO_META_KEY, sanitize_text_field($_POST[self::INFO_META_KEY]));
        }

        $fixed_enabled = isset($_POST[self::FIXED_ENABLED_META_KEY]) ? 'yes' : 'no';
        update_post_meta($post_id, self::FIXED_ENABLED_META_KEY, $fixed_enabled);

        if (isset($_POST[self::FIXED_AMOUNT_META_KEY])) {
            update_post_meta($post_id, self::FIXED_AMOUNT_META_KEY, wc_format_decimal($_POST[self::FIXED_AMOUNT_META_KEY]));
        }

        $this->save_degressive_fields($post_id);
    }

    private function save_degressive_fields($post_id) {
        $enabled = isset($_POST[MK_V4_Degressive_Pricing::ENABLED_META_KEY]) ? 'yes' : 'no';
        update_post_meta($post_id, MK_V4_Degressive_Pricing::ENABLED_META_KEY, $enabled);

        if (isset($_POST[MK_V4_Degressive_Pricing::SURFACE_META_KEY])) {
            update_post_meta($post_id, MK_V4_Degressive_Pricing::SURFACE_META_KEY, (int) $_POST[MK_V4_Degressive_Pricing::SURFACE_META_KEY]);
        }

        if (isset($_POST['_mk_v4_degressive_tier_min'], $_POST['_mk_v4_degressive_tier_max'], $_POST['_mk_v4_degressive_tier_price'])) {
            $tiers = [];
            $count = count($_POST['_mk_v4_degressive_tier_min']);

            for ($i = 0; $i < $count; $i++) {
                $tiers[] = [
                    'min_pallets' => (int) $_POST['_mk_v4_degressive_tier_min'][$i],
                    'max_pallets' => (int) $_POST['_mk_v4_degressive_tier_max'][$i],
                    'price_per_m2' => (float) $_POST['_mk_v4_degressive_tier_price'][$i],
                ];
            }

            update_post_meta($post_id, MK_V4_Degressive_Pricing::TIERS_META_KEY, wp_json_encode($tiers));
        }
    }

    public function display_cart_info($item_data, $cart_item) {
        $product_id = $cart_item['data']->get_id();
        $is_palette = get_post_meta($product_id, self::META_KEY, true);

        // Si le prix dégressif est actif sur ce produit, MK_V4_Degressive_Pricing
        // affiche déjà une ligne "Surface" plus détaillée (m², palettes, prix/m²) —
        // pas besoin de doublonner l'info ici.
        $is_degressive = get_post_meta($product_id, MK_V4_Degressive_Pricing::ENABLED_META_KEY, true) === 'yes';

        if ($is_palette === 'yes' && !$is_degressive) {
            $qty = $cart_item['quantity'];
            $surface = $qty * 35;

            $item_data[] = [
                'key' => sprintf(_n('%d palette', '%d palettes', $qty), $qty),
                'value' => sprintf('%d m² au total', $surface),
            ];
        }

        if (get_post_meta($product_id, self::FIXED_ENABLED_META_KEY, true) === 'yes') {
            $amount = (float) get_post_meta($product_id, self::FIXED_AMOUNT_META_KEY, true);
            $qty = $cart_item['quantity'];

            $item_data[] = [
                'key' => 'Transport (tarif fixe)',
                'value' => sprintf('%s × %d = %s', wc_price($amount), $qty, wc_price($amount * $qty)),
            ];
        }

        return $item_data;
    }
}
