<?php
/**
 * Gestionnaire International - Détection pays via Weglot
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_International_Manager {

    private static $instance = null;
    private $current_country = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'detect_and_store_country'], 5);
    }

    /**
     * Détecte le pays via Weglot et stocke en session
     */
    public function detect_and_store_country() {
        $country = $this->detect_from_weglot();

        if ($country) {
            $this->current_country = $country;
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('shp_v2_int_country', $country);
            }
            return;
        }

        // Fallback : session WooCommerce
        if (function_exists('WC') && WC()->session) {
            $session_country = WC()->session->get('shp_v2_int_country');
            if ($session_country && in_array($session_country, $this->get_supported_countries(), true)) {
                $this->current_country = $session_country;
                return;
            }
        }

        // Défaut : FR
        $this->current_country = 'FR';
    }

    /**
     * Détecte la langue Weglot et mappe vers un pays
     */
    private function detect_from_weglot() {
        if (!function_exists('weglot_get_current_language')) {
            return null;
        }

        $language = weglot_get_current_language();
        return $this->map_language_to_country($language);
    }

    /**
     * Mapping langue Weglot → pays
     */
    public function map_language_to_country($language) {
        $mapping = [
            'fr' => 'FR',
            'de' => 'DE',
            'pl' => 'PL',
            'nl' => 'NL',
        ];

        return $mapping[$language] ?? null;
    }

    /**
     * Retourne le pays courant détecté
     */
    public function get_current_country() {
        if ($this->current_country === null) {
            $this->detect_and_store_country();
        }
        return $this->current_country;
    }

    /**
     * Liste des pays supportés
     */
    public function get_supported_countries() {
        return ['FR', 'DE', 'PL', 'NL'];
    }
}
