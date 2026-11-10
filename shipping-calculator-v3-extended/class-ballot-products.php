<?php
/**
 * Classe des Produits Dégressifs par Ballot
 * Version: 1.0.0
 * Compatible: Shipping Calculator V2 Extended v2.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Extended_Ballot_Products {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Scripts frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX endpoints
        add_action('wp_ajax_calculate_ballot_price', [$this, 'ajax_calculate_price']);
        add_action('wp_ajax_nopriv_calculate_ballot_price', [$this, 'ajax_calculate_price']);
        add_action('wp_ajax_get_ballot_options', [$this, 'ajax_get_options']);
        add_action('wp_ajax_nopriv_get_ballot_options', [$this, 'ajax_get_options']);
        
        // Panier
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'get_cart_item_from_session'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_info'], 10, 2);
        
        // Prix panier - ✅ FIX v1.1 : Priority 99 pour s'exécuter APRÈS la session
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_prices'], 99, 1);
        add_filter('woocommerce_cart_item_price', [$this, 'cart_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'cart_item_subtotal'], 10, 3);
        
        // Validation
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 3);
    }
    
    /**
     * Charger les scripts frontend
     */
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }
        
        global $post;
        if (!$post) {
            return;
        }
        
        $is_ballot = get_post_meta($post->ID, '_degressive_ballot_enabled', true);
        
        if ($is_ballot !== 'yes') {
            return;
        }
        
        $js_url = plugins_url('shipping-calculator-v2-extended/assets/ballot-products.js');
        
        wp_enqueue_script(
            'ballot-products',
            $js_url,
            ['jquery', 'wc-add-to-cart-variation'],
            '1.0.0',
            true
        );
        
        $ballots_per_pallet = (int) get_post_meta($post->ID, '_ballots_per_pallet', true);
        $tiers_json = get_post_meta($post->ID, '_ballot_tiers', true);
        $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
        
        // v3.0 : Passer le symbole devise et les tiers du pays
        $country = class_exists('SHP_V2_International_Manager')
            ? SHP_V2_International_Manager::get_instance()->get_current_country()
            : 'FR';
        $currency_symbol = class_exists('SHP_V2_Country_Config')
            ? SHP_V2_Country_Config::get_instance()->get_currency_symbol($country)
            : "\xE2\x82\xAC";

        // v3.0 : Utiliser les tiers du pays si disponibles
        if (class_exists('SHP_V2_Country_Pricing')) {
            $tiers = SHP_V2_Country_Pricing::get_instance()->get_ballot_tiers($post->ID, $country);
        }

        wp_localize_script('ballot-products', 'shp_ballot_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ballot_calculation'),
            'product_id' => $post->ID,
            'ballots_per_pallet' => $ballots_per_pallet,
            'tiers' => $tiers,
            'currency_symbol' => $currency_symbol,
            'country' => $country,
        ]);
    }
    
    /**
     * AJAX: Calculer le prix
     */
    public function ajax_calculate_price() {
        check_ajax_referer('ballot_calculation', 'nonce');
        
        $product_id = (int) $_POST['product_id'];
        $quantity_ballots = (int) $_POST['quantity'];
        
        $result = $this->calculate_price($product_id, $quantity_ballots);
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Obtenir les options de quantité
     */
    public function ajax_get_options() {
        check_ajax_referer('ballot_calculation', 'nonce');
        
        $product_id = (int) $_POST['product_id'];
        $options = $this->get_quantity_options($product_id);
        
        wp_send_json_success(['options' => $options]);
    }
    
    /**
     * Détecte si un produit est configuré en mode ballot
     */
    public function is_ballot($product_id) {
        return get_post_meta($product_id, '_degressive_ballot_enabled', true) === 'yes';
    }

    /**
     * Retourne le prix/ballot le plus bas de la table (palier au volume le plus élevé).
     * Point d'entrée unique pour le widget "Prix le plus bas".
     */
    public function get_lowest_price_per_ballot($product_id, $country = null) {
        if (!$this->is_ballot($product_id)) {
            return null;
        }

        if ($country === null && class_exists('SHP_V2_International_Manager')) {
            $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        }

        if ($country && class_exists('SHP_V2_Country_Pricing')) {
            $tiers = SHP_V2_Country_Pricing::get_instance()->get_ballot_tiers($product_id, $country);
        } else {
            $tiers_json = get_post_meta($product_id, '_ballot_tiers', true);
            $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
        }

        if (empty($tiers)) {
            return null;
        }

        $prices = array_map(function ($tier) {
            return (float) $tier['price_per_ballot'];
        }, $tiers);

        return min($prices);
    }

    /**
     * Calculer le prix pour une quantité de ballots
     */
    public function calculate_price($product_id, $quantity_ballots, $country = null) {
        $ballots_per_pallet = (int) get_post_meta($product_id, '_ballots_per_pallet', true);

        if ($ballots_per_pallet <= 0) {
            return ['error' => 'Configuration invalide'];
        }

        $nb_palettes = $quantity_ballots / $ballots_per_pallet;

        // v3.0 : Détection pays automatique
        if ($country === null && class_exists('SHP_V2_International_Manager')) {
            $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        }

        // v3.0 : Paliers par pays
        if ($country && class_exists('SHP_V2_Country_Pricing')) {
            $tiers = SHP_V2_Country_Pricing::get_instance()->get_ballot_tiers($product_id, $country);
        } else {
            $tiers_json = get_post_meta($product_id, '_ballot_tiers', true);
            $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
        }

        if (empty($tiers)) {
            return ['error' => 'Aucun palier configuré'];
        }

        $price_per_ballot = null;

        foreach ($tiers as $tier) {
            if ($nb_palettes >= $tier['min_pallets'] && $nb_palettes <= $tier['max_pallets']) {
                $price_per_ballot = (float) $tier['price_per_ballot'];
                break;
            }
        }

        if ($price_per_ballot === null) {
            return ['error' => 'Quantité hors paliers'];
        }

        $total = $quantity_ballots * $price_per_ballot;

        return [
            'quantity_ballots' => $quantity_ballots,
            'nb_palettes' => $nb_palettes,
            'price_per_ballot' => $price_per_ballot,
            'total' => $total,
            'formatted_price' => wc_price($price_per_ballot),
            'formatted_total' => wc_price($total)
        ];
    }
    
    /**
     * Générer les options de quantité
     */
    public function get_quantity_options($product_id) {
        $ballots_per_pallet = (int) get_post_meta($product_id, '_ballots_per_pallet', true);
        
        if ($ballots_per_pallet <= 0) {
            return [];
        }
        
        $tiers_json = get_post_meta($product_id, '_ballot_tiers', true);
        $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
        
        if (empty($tiers)) {
            return [];
        }
        
        $options = [];
        
        foreach ($tiers as $tier) {
            for ($pal = $tier['min_pallets']; $pal <= $tier['max_pallets']; $pal++) {
                $qty = $pal * $ballots_per_pallet;
                $options[] = [
                    'value' => $qty,
                    'label' => sprintf('%d ballots (%d palette%s)', $qty, $pal, $pal > 1 ? 's' : ''),
                    'palettes' => $pal
                ];
                
                if ($pal >= 33) {
                    break;
                }
            }
        }
        
        return $options;
    }
    
    /**
     * Ajouter les données au panier
     */
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (get_post_meta($product_id, '_degressive_ballot_enabled', true) !== 'yes') {
            return $cart_item_data;
        }
        
        if (!isset($_POST['ballot_quantity'])) {
            return $cart_item_data;
        }
        
        $quantity_ballots = (int) $_POST['ballot_quantity'];
        $ballots_per_pallet = (int) get_post_meta($product_id, '_ballots_per_pallet', true);
        $nb_palettes = $quantity_ballots / $ballots_per_pallet;
        
        $calc = $this->calculate_price($product_id, $quantity_ballots);
        
        $cart_item_data['ballot_data'] = [
            'quantity_ballots' => $quantity_ballots,
            'palettes' => $nb_palettes,
            'price_per_ballot' => $calc['price_per_ballot'],
            'total' => $calc['total']
        ];
        
        $cart_item_data['unique_key'] = md5(json_encode($cart_item_data['ballot_data']));
        
        return $cart_item_data;
    }
    
    /**
     * Restaurer depuis session
     * ✅ FIX v1.1 : Restaurer AUSSI le prix
     */
    public function get_cart_item_from_session($cart_item, $values) {
        if (isset($values['ballot_data'])) {
            $cart_item['ballot_data'] = $values['ballot_data'];
            
            // ✅ FIX : Restaurer le prix immédiatement
            $total_price = (float) $values['ballot_data']['total'];
            $cart_item['data']->set_price($total_price);
            
            // ✅ DEBUG
            error_log(sprintf(
                'Ballot price restored from session: %f',
                $total_price
            ));
        }
        return $cart_item;
    }
    
    /**
     * Afficher dans le panier
     */
    public function display_cart_info($item_data, $cart_item) {
        if (!isset($cart_item['ballot_data'])) {
            return $item_data;
        }
        
        $data = $cart_item['ballot_data'];
        
        $item_data[] = [
            'key' => sprintf('%d ballots', $data['quantity_ballots']),
            'value' => sprintf('%.1f palette%s', $data['palettes'], $data['palettes'] > 1 ? 's' : '')
        ];
        
        $item_data[] = [
            'key' => 'Prix unitaire',
            'value' => wc_price($data['price_per_ballot']) . ' / ballot'
        ];
        
        return $item_data;
    }
    
    /**
     * Mettre à jour le prix réel du produit dans le panier
     * CRITIQUE : C'est ce qui permet le calcul correct du sous-total
     * FIX v1.1 : Anti-boucle + Priority + Force price
     */
    public function update_cart_item_prices($cart) {
        // Éviter les appels admin sauf AJAX
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // Éviter la boucle infinie
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['ballot_data'])) {
                // ✅ FIX : Utiliser le prix TOTAL ballot
                $total_price = (float) $cart_item['ballot_data']['total'];
                
                // ✅ IMPORTANT : set_price() avec le prix total
                // Comme quantity = 1, WooCommerce calcule : 1 × total_price
                $cart_item['data']->set_price($total_price);
                
                // ✅ DEBUG : Vérifier que le prix est bien défini
                error_log(sprintf(
                    'Ballot price set for product %d: %f',
                    $cart_item['product_id'],
                    $total_price
                ));
            }
        }
    }
    
    /**
     * Prix ligne panier
     */
    public function cart_item_price($price, $cart_item, $cart_item_key) {
        if (isset($cart_item['ballot_data'])) {
            return wc_price($cart_item['ballot_data']['price_per_ballot']) . ' / ballot';
        }
        return $price;
    }
    
    /**
     * Sous-total ligne panier
     */
    public function cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        if (isset($cart_item['ballot_data'])) {
            $qty = $cart_item['quantity'];
            $total = $cart_item['ballot_data']['total'] * $qty;
            return wc_price($total);
        }
        return $subtotal;
    }
    
    /**
     * Validation ajout panier
     */
    public function validate_add_to_cart($passed, $product_id, $quantity) {
        if (get_post_meta($product_id, '_degressive_ballot_enabled', true) !== 'yes') {
            return $passed;
        }
        
        if (!isset($_POST['ballot_quantity'])) {
            wc_add_notice('Veuillez sélectionner une quantité de ballots.', 'error');
            return false;
        }
        
        $ballot_qty = (int) $_POST['ballot_quantity'];
        $ballots_per_pallet = (int) get_post_meta($product_id, '_ballots_per_pallet', true);
        
        if ($ballot_qty % $ballots_per_pallet !== 0) {
            wc_add_notice('La quantité doit être un multiple de ' . $ballots_per_pallet . ' ballots.', 'error');
            return false;
        }
        
        return $passed;
    }
    
    /**
     * Compter les produits ballot configurés
     */
    public function count_ballot_products() {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_degressive_ballot_enabled',
                    'value' => 'yes'
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
}
