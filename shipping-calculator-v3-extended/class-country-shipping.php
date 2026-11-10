<?php
/**
 * Transport par pays - Fixe ou Règles
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Country_Shipping {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_cart_totals_after_shipping', [$this, 'display_shipping_notice']);
        add_action('woocommerce_review_order_after_shipping', [$this, 'display_shipping_notice']);
    }

    /**
     * Calcule le transport selon le pays
     *
     * @param string $country Code pays (FR, DE, PL, NL)
     * @param string $postcode Code postal
     * @param int $nb_palettes Nombre de palettes
     * @return array|null ['cost' => float, 'label' => string, 'type' => string, 'details' => array]
     */
    public function calculate_shipping($country, $postcode, $nb_palettes) {
        $config = SHP_V2_Country_Config::get_instance();
        $shipping_type = $config->get_shipping_type($country);

        if ($shipping_type === 'fixed') {
            return $this->calculate_fixed($country, $nb_palettes);
        }

        // Type 'rules' : déléguer au système existant
        return null; // Le calculateur principal gère les rules
    }

    /**
     * Transport fixe (DE, PL)
     */
    private function calculate_fixed($country, $nb_palettes) {
        $config = SHP_V2_Country_Config::get_instance();
        $cost_per_pallet = $config->get_shipping_cost($country);
        $total_cost = $nb_palettes * $cost_per_pallet;
        $currency_symbol = $config->get_currency_symbol($country);

        $country_names = [
            'DE' => 'Allemagne',
            'PL' => 'Pologne',
            'NL' => 'Pays-Bas',
            'FR' => 'France',
        ];

        $country_name = $country_names[$country] ?? $country;

        return [
            'type'  => 'fixed_country',
            'label' => sprintf('Livraison %s (%d palette(s))', $country_name, $nb_palettes),
            'cost'  => $total_cost,
            'details' => [
                'nb_palettes'   => $nb_palettes,
                'cost_per_pallet' => $cost_per_pallet,
                'country'       => $country,
                'shipping_type' => 'fixed',
            ],
        ];
    }

    /**
     * Affiche la mention transport si configurée
     */
    public function display_shipping_notice() {
        $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        $notice = SHP_V2_Country_Config::get_instance()->get_shipping_notice($country);

        if (!empty($notice)) {
            echo '<tr><td colspan="2"><p style="color: #d63638; font-style: italic; margin: 5px 0;">';
            echo esc_html($notice);
            echo '</p></td></tr>';
        }
    }
}
