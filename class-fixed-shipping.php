<?php
/**
 * Frais de port fixes par palette (produits confort / nutrition : foin, copeaux...)
 * Version: 3.2.0
 *
 * Deux champs sur la fiche produit (onglet Livraison) :
 *  - _fixed_shipping_pallet     : prix fixe par palette, tous pays (optionnel)
 *  - _fixed_shipping_pallet_FR  : prix fixe par palette pour la France (prioritaire en France)
 *
 * Si un prix s'applique au pays de livraison, le produit sort du calcul
 * standard (grille Excel / règles / Raben) et son montant fixe
 * (prix × nb palettes) s'ajoute au reste des frais de port du panier.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Fixed_Shipping {

    const META_DEFAULT = '_fixed_shipping_pallet';
    const META_COUNTRY_PREFIX = '_fixed_shipping_pallet_';

    /** Pays pour lesquels un prix spécifique peut être saisi */
    const COUNTRIES = [
        'FR' => 'France',
    ];

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_shipping', [$this, 'add_fields'], 5);
        add_action('woocommerce_process_product_meta', [$this, 'save_fields']);
    }

    /**
     * Prix fixe par palette applicable à un produit pour un pays.
     *
     * @return float|null null = pas de prix fixe, calcul standard
     */
    public function get_price_per_pallet($product_id, $country) {
        $country = strtoupper((string) $country);

        if (isset(self::COUNTRIES[$country])) {
            $value = get_post_meta($product_id, self::META_COUNTRY_PREFIX . $country, true);
            if ($value !== '' && $value !== false && is_numeric($value)) {
                return (float) $value;
            }
        }

        $value = get_post_meta($product_id, self::META_DEFAULT, true);
        if ($value !== '' && $value !== false && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Nombre de palettes d'une ligne panier (arrondi à la palette supérieure).
     */
    public function get_item_pallets($cart_item) {
        if (isset($cart_item['ballot_data']['palettes'])) {
            $pallets = (float) $cart_item['ballot_data']['palettes'];
        } elseif (isset($cart_item['degressive_data']['palettes'])) {
            $pallets = (float) $cart_item['degressive_data']['palettes'];
        } else {
            // Produit simple : 1 unité = 1 palette
            $pallets = (float) $cart_item['quantity'];
        }

        return (int) ceil($pallets - 0.0001);
    }

    public function add_fields() {
        global $post;

        echo '<div class="options_group" style="border: 2px solid #2e7d32; padding: 15px; margin-top: 15px; background: #f1f8e9;">';
        echo '<h4 style="margin-top: 0; color: #2e7d32;">🌾 Frais de port fixes par palette (confort / nutrition)</h4>';
        echo '<p style="color: #666; font-size: 12px; margin: 0 0 10px;">Laisser vide = calcul standard. Le montant (prix × nb de palettes) s\'additionne aux frais des autres produits du panier.</p>';

        woocommerce_wp_text_input([
            'id'                => self::META_DEFAULT,
            'label'             => 'Prix / palette – tous pays (€)',
            'type'              => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
            'value'             => get_post_meta($post->ID, self::META_DEFAULT, true),
            'description'       => 'Optionnel. Vide = calcul standard hors pays ci-dessous.',
        ]);

        foreach (self::COUNTRIES as $code => $name) {
            woocommerce_wp_text_input([
                'id'                => self::META_COUNTRY_PREFIX . $code,
                'label'             => sprintf('Prix / palette – %s (€)', $name),
                'type'              => 'number',
                'custom_attributes' => ['step' => '0.01', 'min' => '0'],
                'value'             => get_post_meta($post->ID, self::META_COUNTRY_PREFIX . $code, true),
                'description'       => sprintf('Prioritaire pour les livraisons en %s.', $name),
            ]);
        }

        echo '</div>';
    }

    public function save_fields($post_id) {
        $keys = [self::META_DEFAULT];
        foreach (array_keys(self::COUNTRIES) as $code) {
            $keys[] = self::META_COUNTRY_PREFIX . $code;
        }

        foreach ($keys as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = trim(wp_unslash($_POST[$key]));
            if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, wc_format_decimal(str_replace(',', '.', $value), 2));
            }
        }
    }
}

SHP_V2_Fixed_Shipping::get_instance();
