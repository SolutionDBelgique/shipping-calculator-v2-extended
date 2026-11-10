<?php
/**
 * Gestion des prix par pays et devise PLN
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Country_Pricing {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_product_get_price', [$this, 'filter_price'], 5, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'filter_price'], 5, 2);
    }

    /**
     * Filtre le prix WooCommerce selon le pays détecté
     */
    public function filter_price($price, $product) {
        // Ne pas filtrer dans l'admin
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price;
        }

        // Ne pas filtrer les produits dégressifs/ballot (ils ont leur propre logique)
        $product_id = $product->get_id();
        if (get_post_meta($product_id, '_degressive_enabled', true) === 'yes') {
            return $price;
        }
        if (get_post_meta($product_id, '_degressive_ballot_enabled', true) === 'yes') {
            return $price;
        }

        $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        $country_price = $this->get_price($product_id, $country);

        if ($country_price !== null) {
            return $country_price;
        }

        return $price;
    }

    /**
     * Récupère le prix d'un produit pour un pays
     *
     * Ordre :
     * 1. Promo active → _sale_price_{XX}
     * 2. Prix pays → _price_{XX}
     * 3. Fallback → _price_FR
     * 4. Fallback ultime → null (prix WC standard)
     */
    public function get_price($product_id, $country) {
        // 1. Vérifier promo active
        if ($this->is_promo_active($product_id, $country)) {
            $sale_price = get_post_meta($product_id, "_sale_price_{$country}", true);
            if ($sale_price !== '' && $sale_price !== false) {
                return (float) $sale_price;
            }
        }

        // 2. Prix pays spécifique
        $country_price = get_post_meta($product_id, "_price_{$country}", true);
        if ($country_price !== '' && $country_price !== false) {
            return (float) $country_price;
        }

        // 3. Fallback FR
        if ($country !== 'FR') {
            $fr_price = get_post_meta($product_id, '_price_FR', true);
            if ($fr_price !== '' && $fr_price !== false) {
                return (float) $fr_price;
            }
        }

        // 4. Fallback ultime : prix WC standard
        return null;
    }

    /**
     * Récupère les paliers dégressifs m² pour un pays
     */
    public function get_degressive_tiers($product_id, $country) {
        // 1. Paliers pays spécifiques
        $tiers_json = get_post_meta($product_id, "_degressive_tiers_{$country}", true);
        if (!empty($tiers_json)) {
            $tiers = json_decode($tiers_json, true);
            if (!empty($tiers)) {
                return $tiers;
            }
        }

        // 2. Fallback : paliers FR (existants)
        $tiers_json = get_post_meta($product_id, '_degressive_tiers', true);
        if (!empty($tiers_json)) {
            return json_decode($tiers_json, true) ?: [];
        }

        return [];
    }

    /**
     * Récupère les paliers ballot pour un pays
     */
    public function get_ballot_tiers($product_id, $country) {
        // 1. Paliers pays spécifiques
        $tiers_json = get_post_meta($product_id, "_ballot_tiers_{$country}", true);
        if (!empty($tiers_json)) {
            $tiers = json_decode($tiers_json, true);
            if (!empty($tiers)) {
                return $tiers;
            }
        }

        // 2. Fallback : paliers FR (existants)
        $tiers_json = get_post_meta($product_id, '_ballot_tiers', true);
        if (!empty($tiers_json)) {
            return json_decode($tiers_json, true) ?: [];
        }

        return [];
    }

    /**
     * Vérifie si une promo est active pour un produit/pays
     */
    public function is_promo_active($product_id, $country) {
        $promo_enabled = get_post_meta($product_id, "_promo_enabled_{$country}", true);
        if ($promo_enabled !== 'yes') {
            return false;
        }

        $start = get_post_meta($product_id, "_promo_start_{$country}", true);
        $end = get_post_meta($product_id, "_promo_end_{$country}", true);

        if (empty($start) || empty($end)) {
            return true; // Pas de dates = toujours actif si enabled
        }

        $now = current_time('timestamp');
        $start_ts = strtotime($start);
        $end_ts = strtotime($end . ' 23:59:59');

        return ($now >= $start_ts && $now <= $end_ts);
    }

    /**
     * Prix régulier (non-promo) pour un produit/pays
     */
    public function get_regular_price($product_id, $country) {
        $country_price = get_post_meta($product_id, "_price_{$country}", true);
        if ($country_price !== '' && $country_price !== false) {
            return (float) $country_price;
        }

        if ($country !== 'FR') {
            $fr_price = get_post_meta($product_id, '_price_FR', true);
            if ($fr_price !== '' && $fr_price !== false) {
                return (float) $fr_price;
            }
        }

        return null;
    }

    /**
     * Prix promo pour un produit/pays
     */
    public function get_sale_price($product_id, $country) {
        $sale_price = get_post_meta($product_id, "_sale_price_{$country}", true);
        if ($sale_price !== '' && $sale_price !== false) {
            return (float) $sale_price;
        }
        return null;
    }

    /**
     * Formate un prix avec la devise du pays
     */
    public function format_price($price, $country) {
        $config = SHP_V2_Country_Config::get_instance();
        $symbol = $config->get_currency_symbol($country);
        $currency = $config->get_currency($country);

        if ($currency === 'PLN') {
            return number_format($price, 2, ',', ' ') . ' ' . $symbol;
        }

        return number_format($price, 2, ',', ' ') . ' ' . $symbol;
    }
}
