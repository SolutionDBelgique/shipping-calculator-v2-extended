<?php
/**
 * Promotions temporaires par pays (DE, NL)
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Country_Promotions {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_get_price_html', [$this, 'modify_price_html'], 10, 2);
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'display_promo_badge'], 10);
    }

    /**
     * Affiche prix barré + prix promo si promo active
     */
    public function modify_price_html($price_html, $product) {
        // Ne pas modifier dans l'admin
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price_html;
        }

        $product_id = $product->get_id();

        // Ne pas modifier les produits dégressifs/ballot
        if (get_post_meta($product_id, '_degressive_enabled', true) === 'yes') {
            return $price_html;
        }
        if (get_post_meta($product_id, '_degressive_ballot_enabled', true) === 'yes') {
            return $price_html;
        }

        $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        $pricing = SHP_V2_Country_Pricing::get_instance();

        if (!$pricing->is_promo_active($product_id, $country)) {
            return $price_html;
        }

        $regular_price = $pricing->get_regular_price($product_id, $country);
        $sale_price = $pricing->get_sale_price($product_id, $country);

        if ($regular_price === null || $sale_price === null) {
            return $price_html;
        }

        $config = SHP_V2_Country_Config::get_instance();
        $symbol = $config->get_currency_symbol($country);

        $formatted_regular = number_format($regular_price, 2, ',', ' ') . ' ' . $symbol;
        $formatted_sale = number_format($sale_price, 2, ',', ' ') . ' ' . $symbol;

        return '<del style="color: #999;">' . esc_html($formatted_regular) . '</del> '
             . '<ins style="font-weight: bold; color: #d63638;">' . esc_html($formatted_sale) . '</ins>';
    }

    /**
     * Badge "PROMO" sur les produits en promo
     */
    public function display_promo_badge() {
        global $product;
        if (!$product) {
            return;
        }

        $product_id = $product->get_id();
        $country = SHP_V2_International_Manager::get_instance()->get_current_country();

        if (!SHP_V2_Country_Pricing::get_instance()->is_promo_active($product_id, $country)) {
            return;
        }

        echo '<span style="position: absolute; top: 10px; left: 10px; background: #d63638; color: white; '
           . 'padding: 4px 10px; font-size: 12px; font-weight: bold; border-radius: 3px; z-index: 10;">'
           . 'PROMO</span>';
    }
}
