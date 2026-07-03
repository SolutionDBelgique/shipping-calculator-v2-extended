<?php
/**
 * Classe de gestion des produits dégressifs au m²
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Extended_Degressive_Products {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook pour enregistrer les scripts frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX pour calcul prix dynamique
        add_action('wp_ajax_calculate_degressive_price', [$this, 'ajax_calculate_price']);
        add_action('wp_ajax_nopriv_calculate_degressive_price', [$this, 'ajax_calculate_price']);
        
        // AJAX pour obtenir les options de quantité
        add_action('wp_ajax_get_degressive_options', [$this, 'ajax_get_options']);
        add_action('wp_ajax_nopriv_get_degressive_options', [$this, 'ajax_get_options']);
        
        // Modifier le prix affiché
        add_filter('woocommerce_product_get_price', [$this, 'get_dynamic_price'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'get_dynamic_price'], 10, 2);
        
        // Modifier affichage panier
        add_filter('woocommerce_cart_item_name', [$this, 'cart_item_name'], 10, 3);
        add_filter('woocommerce_cart_item_price', [$this, 'cart_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'cart_item_subtotal'], 10, 3);
        add_filter('woocommerce_cart_item_product_price', [$this, 'cart_item_product_price'], 10, 3);
        
        // Stocker données lors ajout panier
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'get_cart_item_from_session'], 10, 2);
        
        // IMPORTANT : Hooks multiples pour forcer le prix (priorités différentes)
        add_action('woocommerce_before_calculate_totals', [$this, 'set_degressive_price'], 10, 1);
        add_action('woocommerce_before_calculate_totals', [$this, 'fix_line_totals'], 99, 1);
        add_action('woocommerce_cart_loaded_from_session', [$this, 'cart_loaded_from_session'], 99, 1);
        add_action('woocommerce_calculate_totals', [$this, 'after_calculate_totals'], 99, 1);
        
        // Filtre pour forcer le total final
        add_filter('woocommerce_calculated_total', [$this, 'force_calculated_total'], 99, 2);
    }
    
    public function enqueue_scripts() {
        if (is_product()) {
            global $post;
            
            if ($this->is_degressive($post->ID)) {
                wp_enqueue_script(
                    'shp-v2-degressive',
                    plugins_url('assets/degressive-products.js', dirname(__FILE__) . '/shipping-calculator-v2-extended.php'),
                    ['jquery'],
                    '2.6.0',
                    true
                );
                
                // v3.0 : Passer le symbole devise
                $country = class_exists('SHP_V2_International_Manager')
                    ? SHP_V2_International_Manager::get_instance()->get_current_country()
                    : 'FR';
                $currency_symbol = class_exists('SHP_V2_Country_Config')
                    ? SHP_V2_Country_Config::get_instance()->get_currency_symbol($country)
                    : "\xE2\x82\xAC";

                wp_localize_script('shp-v2-degressive', 'shp_degressive_params', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'product_id' => $post->ID,
                    'nonce' => wp_create_nonce('degressive_calculation'),
                    'currency_symbol' => $currency_symbol,
                    'country' => $country,
                ]);
            }
        }
    }
    
    public function is_degressive($product_id) {
        return get_post_meta($product_id, '_degressive_enabled', true) === 'yes';
    }
    
    public function get_config($product_id, $country = null) {
        if (!$this->is_degressive($product_id)) {
            return null;
        }

        // v3.0 : Surface par palette = meta produit, sinon config pays comme défaut
        $surface_per_pallet = (int) get_post_meta($product_id, '_degressive_surface_per_pallet', true);
        if (!$surface_per_pallet && $country && class_exists('SHP_V2_Country_Config')) {
            $surface_per_pallet = SHP_V2_Country_Config::get_instance()->get_pallet_size($country);
        }

        // v3.0 : Paliers par pays
        if ($country && class_exists('SHP_V2_Country_Pricing')) {
            $tiers = SHP_V2_Country_Pricing::get_instance()->get_degressive_tiers($product_id, $country);
        } else {
            $tiers_json = get_post_meta($product_id, '_degressive_tiers', true);
            $tiers = $tiers_json ? json_decode($tiers_json, true) : [];
        }

        return [
            'surface_per_pallet' => $surface_per_pallet ?: 35,
            'tiers' => $tiers
        ];
    }
    
    public function get_active_tier($product_id, $nb_palettes, $country = null) {
        $config = $this->get_config($product_id, $country);

        if (!$config || empty($config['tiers'])) {
            return null;
        }

        foreach ($config['tiers'] as $tier) {
            $min = (int) $tier['min_pallets'];
            $max = (int) $tier['max_pallets'];

            if ($nb_palettes >= $min && $nb_palettes <= $max) {
                return $tier;
            }
        }

        return null;
    }
    
    public function calculate_price($product_id, $m2, $country = null) {
        // v3.0 : Détection pays automatique si non fourni
        if ($country === null && class_exists('SHP_V2_International_Manager')) {
            $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        }

        $config = $this->get_config($product_id, $country);

        if (!$config) {
            return null;
        }

        $surface_per_pallet = $config['surface_per_pallet'];
        $nb_palettes = ceil($m2 / $surface_per_pallet);

        $tier = $this->get_active_tier($product_id, $nb_palettes, $country);

        if (!$tier) {
            return null;
        }

        $price_per_m2 = (float) $tier['price_per_m2'];
        $total = $m2 * $price_per_m2;

        return [
            'nb_palettes' => $nb_palettes,
            'price_per_m2' => $price_per_m2,
            'total' => $total,
            'tier' => $tier
        ];
    }
    
    public function ajax_calculate_price() {
        check_ajax_referer('degressive_calculation', 'nonce');
        
        $product_id = (int) $_POST['product_id'];
        $m2 = (int) $_POST['quantity_m2'];
        
        $result = $this->calculate_price($product_id, $m2);
        
        if (!$result) {
            wp_send_json_error(['message' => 'Configuration invalide']);
            return;
        }
        
        wp_send_json_success([
            'nb_palettes' => $result['nb_palettes'],
            'price_per_m2' => number_format($result['price_per_m2'], 2, ',', ' '),
            'price_per_m2_raw' => $result['price_per_m2'],
            'total' => wc_price($result['total']),
            'total_raw' => $result['total']
        ]);
    }
    
    public function ajax_get_options() {
        check_ajax_referer('degressive_calculation', 'nonce');
        
        $product_id = (int) $_POST['product_id'];
        $options = $this->get_quantity_options($product_id, 50);
        
        wp_send_json_success([
            'options' => $options
        ]);
    }
    
    public function get_quantity_options($product_id, $max_pallets = 50, $country = null) {
        if ($country === null && class_exists('SHP_V2_International_Manager')) {
            $country = SHP_V2_International_Manager::get_instance()->get_current_country();
        }

        $config = $this->get_config($product_id, $country);

        if (!$config) {
            return [];
        }

        $currency_symbol = class_exists('SHP_V2_Country_Config')
            ? SHP_V2_Country_Config::get_instance()->get_currency_symbol($country)
            : "\xE2\x82\xAC";

        $surface_per_pallet = $config['surface_per_pallet'];
        $options = [];

        for ($i = 1; $i <= $max_pallets; $i++) {
            $m2 = $i * $surface_per_pallet;
            $tier = $this->get_active_tier($product_id, $i, $country);

            if ($tier) {
                $total = $m2 * (float) $tier['price_per_m2'];
                $options[$m2] = sprintf(
                    '%d m² (%d palette%s) — %s %s',
                    $m2,
                    $i,
                    $i > 1 ? 's' : '',
                    number_format($total, 2, ',', ' '),
                    $currency_symbol
                );
            } else {
                $options[$m2] = sprintf('%d m² (%d palette%s)', $m2, $i, $i > 1 ? 's' : '');
            }
        }

        return $options;
    }
    
    public function get_dynamic_price($price, $product) {
        // IMPORTANT : Dans le contexte du panier, retourner le prix TOTAL
        // Si on est en train de calculer le panier, WC va multiplier par quantité (1)
        // donc on doit retourner le prix TOTAL ici !
        
        // Vérifier si on a des données dégressives stockées pour ce produit
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product->get_id() && isset($cart_item['degressive_data'])) {
                    // Retourner le prix TOTAL (pas le prix/m²)
                    return floatval($cart_item['degressive_data']['total']);
                }
            }
        }
        
        // Ne pas écraser si le produit a une promotion WooCommerce native active
        // (évite <del>X€</del><ins>X€</ins> avec deux montants identiques)
        $raw_sale = get_post_meta($product->get_id(), '_sale_price', true);
        if ($raw_sale !== '' && $raw_sale !== false && (float) $raw_sale > 0) {
            $date_from = get_post_meta($product->get_id(), '_sale_price_dates_from', true);
            $date_to   = get_post_meta($product->get_id(), '_sale_price_dates_to', true);
            $now = time();
            $on_sale = (empty($date_from) || $now >= (int) $date_from)
                    && (empty($date_to)   || $now <= (int) $date_to);
            if ($on_sale) {
                return $price; // Laisser WooCommerce gérer le sale_price natif
            }
        }

        // Si pas dans le panier ou pas de données dégressives,
        // retourner le prix de base pour l'affichage boutique
        if ($this->is_degressive($product->get_id())) {
            $config = $this->get_config($product->get_id());
            if ($config && !empty($config['tiers'])) {
                // Retourner le prix du premier palier (prix le plus haut) pour affichage
                return $config['tiers'][0]['price_per_m2'];
            }
        }
        
        return $price;
    }
    
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if ($this->is_degressive($product_id)) {
            $m2 = isset($_POST['quantity_m2']) ? (int) $_POST['quantity_m2'] : 0;
            
            if ($m2 > 0) {
                $result = $this->calculate_price($product_id, $m2);
                
                if ($result) {
                    $cart_item_data['degressive_data'] = [
                        'm2' => $m2,
                        'palettes' => $result['nb_palettes'],
                        'price_per_m2' => $result['price_per_m2'],
                        'total' => $result['total']
                    ];
                }
            }
        }
        
        return $cart_item_data;
    }
    
    public function get_cart_item_from_session($cart_item, $values) {
        if (isset($values['degressive_data'])) {
            $cart_item['degressive_data'] = $values['degressive_data'];
            
            // IMPORTANT : Forcer le prix IMMÉDIATEMENT
            $total_price = floatval($values['degressive_data']['total']);
            
            // Forcer UNIQUEMENT le prix - WooCommerce calculera le reste
            $cart_item['data']->set_price($total_price);
            $cart_item['data']->set_regular_price($total_price);
            $cart_item['data']->set_sale_price('');
            
            // IMPORTANT : S'assurer que le produit est taxable
            // Ne pas changer le statut de taxe - laisser celui du produit
            
            // NE PAS forcer line_subtotal et line_total
            // Laisser WooCommerce les calculer avec les taxes
        }
        
        return $cart_item;
    }
    
    public function cart_item_name($name, $cart_item, $cart_item_key) {
        if (isset($cart_item['degressive_data'])) {
            $data = $cart_item['degressive_data'];
            $name .= sprintf(
                '<br><small style="font-weight: normal;">%s/m² × %d m² (%d palette%s)</small>',
                wc_price($data['price_per_m2']),
                $data['m2'],
                $data['palettes'],
                $data['palettes'] > 1 ? 's' : ''
            );
        }
        
        return $name;
    }
    
    public function cart_item_price($price, $cart_item, $cart_item_key) {
        if (isset($cart_item['degressive_data'])) {
            $data = $cart_item['degressive_data'];
            return sprintf(
                '%s/m² (%d m²)',
                wc_price($data['price_per_m2']),
                $data['m2']
            );
        }
        
        return $price;
    }
    
    public function cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        if (isset($cart_item['degressive_data'])) {
            $data = $cart_item['degressive_data'];
            // FORCER l'affichage du total correct
            return wc_price($data['total']);
        }
        
        return $subtotal;
    }
    
    public function cart_item_product_price($price, $cart_item, $cart_item_key) {
        if (isset($cart_item['degressive_data'])) {
            $data = $cart_item['degressive_data'];
            // Retourner le prix total, pas le prix unitaire
            return $data['total'];
        }
        
        return $price;
    }
    
    public function set_degressive_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // ÉTAPE 1 : Forcer le prix ET line_subtotal
        // IMPORTANT : Accéder DIRECTEMENT au cart_contents
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['degressive_data'])) {
                $total_price = floatval($cart_item['degressive_data']['total']);
                
                // Forcer le prix DIRECTEMENT sur l'objet dans le panier
                $cart->cart_contents[$cart_item_key]['data']->set_price($total_price);
                $cart->cart_contents[$cart_item_key]['data']->set_regular_price($total_price);
                $cart->cart_contents[$cart_item_key]['data']->set_sale_price('');
                
                // AUSSI forcer line_subtotal ici pour être SÛR
                $cart->cart_contents[$cart_item_key]['line_subtotal'] = $total_price;
                $cart->cart_contents[$cart_item_key]['line_total'] = $total_price;
            }
        }
    }
    
    public function fix_line_totals($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // ÉTAPE 2 : Forcer line_subtotal et line_total
        // APRÈS que WooCommerce ait calculé les taxes
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['degressive_data'])) {
                $total_price = floatval($cart_item['degressive_data']['total']);
                
                // Forcer les totaux dans cart_contents
                $cart->cart_contents[$cart_item_key]['line_subtotal'] = $total_price;
                $cart->cart_contents[$cart_item_key]['line_total'] = $total_price;
            }
        }
    }
    
    public function cart_loaded_from_session($cart) {
        // CRITIQUE : Modifier le prix après chargement session
        // IMPORTANT : Accéder DIRECTEMENT au cart_contents
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['degressive_data'])) {
                $total_price = floatval($cart_item['degressive_data']['total']);
                
                // Forcer UNIQUEMENT le prix DIRECTEMENT sur l'objet dans le panier
                $cart->cart_contents[$cart_item_key]['data']->set_price($total_price);
                $cart->cart_contents[$cart_item_key]['data']->set_regular_price($total_price);
            }
        }
    }
    
    public function after_calculate_totals($cart) {
        // Plus besoin de forcer quoi que ce soit ici
        // WooCommerce a déjà calculé tous les totaux et taxes correctement
        // à partir du prix qu'on a forcé dans before_calculate_totals
    }
    
    public function force_calculated_total($total, $cart) {
        // Plus besoin de forcer le total
        // WooCommerce a calculé correctement avec les taxes
        // à partir du prix qu'on a forcé
        return $total;
    }
    
    public function count_degressive_products() {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_degressive_enabled' 
             AND meta_value = 'yes'"
        );
    }
    
    public function get_all_degressive_products() {
        global $wpdb;
        
        $product_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_degressive_enabled' 
             AND meta_value = 'yes'"
        );
        
        $products = [];
        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if ($product) {
                $config = $this->get_config($id);
                $products[] = [
                    'id' => $id,
                    'name' => $product->get_name(),
                    'config' => $config
                ];
            }
        }
        
        return $products;
    }
}
