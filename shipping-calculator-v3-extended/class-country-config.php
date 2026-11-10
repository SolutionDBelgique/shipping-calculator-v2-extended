<?php
/**
 * Configuration par pays - Options WP
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Country_Config {

    private static $instance = null;

    private $defaults = [
        'FR' => [
            'pallet_size'     => 35,
            'shipping_type'   => 'rules',
            'shipping_cost'   => 0,
            'shipping_notice' => '',
            'currency'        => 'EUR',
            'currency_symbol' => '€',
        ],
        'DE' => [
            'pallet_size'     => 25,
            'shipping_type'   => 'fixed',
            'shipping_cost'   => 95,
            'shipping_notice' => 'Livraison sans chariot élévateur',
            'currency'        => 'EUR',
            'currency_symbol' => '€',
        ],
        'PL' => [
            'pallet_size'     => 25,
            'shipping_type'   => 'fixed',
            'shipping_cost'   => 150,
            'shipping_notice' => '',
            'currency'        => 'PLN',
            'currency_symbol' => 'zł',
        ],
        'NL' => [
            'pallet_size'     => 35,
            'shipping_type'   => 'rules',
            'shipping_cost'   => 0,
            'shipping_notice' => '',
            'currency'        => 'EUR',
            'currency_symbol' => '€',
        ],
    ];

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Récupère la config complète d'un pays
     */
    public function get_config($country) {
        $default = $this->defaults[$country] ?? $this->defaults['FR'];
        $config = [];

        foreach ($default as $key => $default_value) {
            $option_name = "shp_v2_int_{$key}_{$country}";
            $config[$key] = get_option($option_name, $default_value);
        }

        return $config;
    }

    /**
     * Taille palette pour un pays
     */
    public function get_pallet_size($country) {
        $option = get_option("shp_v2_int_pallet_size_{$country}");
        if ($option !== false && $option > 0) {
            return (int) $option;
        }
        return (int) ($this->defaults[$country]['pallet_size'] ?? 35);
    }

    /**
     * Type de transport pour un pays
     */
    public function get_shipping_type($country) {
        $option = get_option("shp_v2_int_shipping_type_{$country}");
        if ($option !== false) {
            return $option;
        }
        return $this->defaults[$country]['shipping_type'] ?? 'rules';
    }

    /**
     * Coût fixe de transport
     */
    public function get_shipping_cost($country) {
        $option = get_option("shp_v2_int_shipping_cost_{$country}");
        if ($option !== false) {
            return (float) $option;
        }
        return (float) ($this->defaults[$country]['shipping_cost'] ?? 0);
    }

    /**
     * Mention transport
     */
    public function get_shipping_notice($country) {
        $option = get_option("shp_v2_int_shipping_notice_{$country}");
        if ($option !== false) {
            return $option;
        }
        return $this->defaults[$country]['shipping_notice'] ?? '';
    }

    /**
     * Devise pour un pays
     */
    public function get_currency($country) {
        $option = get_option("shp_v2_int_currency_{$country}");
        if ($option !== false) {
            return $option;
        }
        return $this->defaults[$country]['currency'] ?? 'EUR';
    }

    /**
     * Symbole devise
     */
    public function get_currency_symbol($country) {
        $option = get_option("shp_v2_int_currency_symbol_{$country}");
        if ($option !== false) {
            return $option;
        }
        return $this->defaults[$country]['currency_symbol'] ?? '€';
    }

    /**
     * Initialise les options par défaut (activation plugin)
     */
    public function init_default_config() {
        foreach ($this->defaults as $country => $config) {
            foreach ($config as $key => $value) {
                $option_name = "shp_v2_int_{$key}_{$country}";
                add_option($option_name, $value);
            }
        }
    }

    /**
     * Sauvegarde la config d'un pays
     */
    public function save_config($country, $config) {
        foreach ($config as $key => $value) {
            $option_name = "shp_v2_int_{$key}_{$country}";
            update_option($option_name, $value);
        }
    }

    /**
     * Retourne les défauts
     */
    public function get_defaults() {
        return $this->defaults;
    }
}
