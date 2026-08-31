<?php
/**
 * MK Transport Calculator V4 - Prix dégressif au m² (portage de v3-extended)
 *
 * Complète la logique "palette" (_palette_complete_v2, 1 unité achetée = 1 palette)
 * en rendant le PRIX de ce produit dégressif selon le nombre de palettes commandées :
 * plus le client commande de palettes, plus le prix au m² diminue, par paliers.
 *
 * Ne touche jamais aux frais de port (barème fixe géré par MK_V4_Calculator) :
 * la quantité panier reste "nombre de palettes" pour les deux systèmes, mais chacun
 * l'utilise indépendamment — prix produit ici, frais de transport côté calculateur.
 *
 * Réutilise volontairement les clés meta de v3 (_degressive_enabled,
 * _degressive_surface_per_pallet, _degressive_tiers) : les produits déjà configurés
 * du temps de v3-extended reprennent leurs paliers existants sans ressaisie.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Degressive_Pricing {

    private static $instance = null;

    const ENABLED_META_KEY = '_degressive_enabled';
    const SURFACE_META_KEY = '_degressive_surface_per_pallet';
    const TIERS_META_KEY = '_degressive_tiers';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_degressive_price'], 20, 1);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_info'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 3);
    }

    public function is_degressive($product_id) {
        return get_post_meta($product_id, self::ENABLED_META_KEY, true) === 'yes';
    }

    public function get_config($product_id) {
        if (!$this->is_degressive($product_id)) {
            return null;
        }

        $surface = (int) get_post_meta($product_id, self::SURFACE_META_KEY, true);
        $tiers_json = get_post_meta($product_id, self::TIERS_META_KEY, true);
        $tiers = $tiers_json ? json_decode($tiers_json, true) : [];

        return [
            'surface_per_pallet' => $surface ?: 35,
            'tiers' => is_array($tiers) ? $tiers : [],
        ];
    }

    public function find_tier($tiers, $nb_palettes) {
        foreach ($tiers as $tier) {
            $min = (int) $tier['min_pallets'];
            $max = (int) $tier['max_pallets'];
            if ($nb_palettes >= $min && $nb_palettes <= $max) {
                return $tier;
            }
        }
        return null;
    }

    /**
     * Prix unitaire (par palette) à appliquer sur la ligne panier : surface d'une
     * palette x prix/m² du palier correspondant au nombre de palettes commandées.
     * WooCommerce multiplie ensuite ce prix par la quantité (= nb de palettes)
     * pour obtenir le sous-total de la ligne.
     */
    public function calculate_unit_price($product_id, $nb_palettes) {
        if ($nb_palettes <= 0) {
            return null;
        }

        $config = $this->get_config($product_id);

        if (!$config || empty($config['tiers'])) {
            return null;
        }

        $tier = $this->find_tier($config['tiers'], $nb_palettes);

        if (!$tier) {
            return null;
        }

        return $config['surface_per_pallet'] * (float) $tier['price_per_m2'];
    }

    public function apply_degressive_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['data']->get_id();
            $nb_palettes = (int) $cart_item['quantity'];

            $unit_price = $this->calculate_unit_price($product_id, $nb_palettes);

            if ($unit_price !== null) {
                $cart_item['data']->set_price($unit_price);
            }
        }
    }

    public function display_cart_info($item_data, $cart_item) {
        $product_id = $cart_item['data']->get_id();

        if (!$this->is_degressive($product_id)) {
            return $item_data;
        }

        $nb_palettes = (int) $cart_item['quantity'];
        $config = $this->get_config($product_id);

        if (!$config) {
            return $item_data;
        }

        $tier = $this->find_tier($config['tiers'], $nb_palettes);

        if ($tier) {
            $m2 = $nb_palettes * $config['surface_per_pallet'];
            $item_data[] = [
                'key' => 'Surface',
                'value' => sprintf(
                    '%d m² (%d palette%s) — %s/m²',
                    $m2,
                    $nb_palettes,
                    $nb_palettes > 1 ? 's' : '',
                    wc_price($tier['price_per_m2'])
                ),
            ];
        }

        return $item_data;
    }

    /**
     * Bloque l'ajout au panier si la quantité (nb de palettes) ne correspond à
     * aucun palier configuré — cohérent avec "vendu par palette complète,
     * pas de vente au détail".
     */
    public function validate_add_to_cart($passed, $product_id, $quantity) {
        if (!$this->is_degressive($product_id)) {
            return $passed;
        }

        $config = $this->get_config($product_id);

        if (!$config || empty($config['tiers'])) {
            return $passed;
        }

        if (!$this->find_tier($config['tiers'], (int) $quantity)) {
            wc_add_notice('Quantité invalide : ce produit se commande par palette complète, merci de choisir un nombre de palettes valide.', 'error');
            return false;
        }

        return $passed;
    }
}
