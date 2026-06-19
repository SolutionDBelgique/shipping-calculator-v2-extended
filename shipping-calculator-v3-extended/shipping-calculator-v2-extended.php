<?php
/**
 * Plugin Name: Shipping Calculator V3 Extended
 * Version: 3.1.2
 * Description: Interface complète + Produits dégressifs au m² + Produits dégressifs par Ballot + Internationalisation + Codes promo Prix m²
 * Author: SolutionD Belgique
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Charger Composer autoload
require_once __DIR__ . '/autoload.php';

// Charger la classe des produits dégressifs
require_once __DIR__ . '/class-degressive-products.php';

// Charger les champs produits dégressifs
require_once __DIR__ . '/degressive-product-fields.php';

// Charger l'onglet admin dégressif
require_once __DIR__ . '/admin-tab-degressive.php';

// ===== NOUVEAU v3.1.0 : Codes promo Prix m² =====
require_once __DIR__ . '/class-degressive-coupon.php';
// ===== FIN Codes promo Prix m² =====

// ===== NOUVEAU v2.7.0 : Système Ballot =====
// Charger la classe des produits ballot
require_once __DIR__ . '/class-ballot-products.php';

// Charger l'interface admin ballot
require_once __DIR__ . '/ballot-admin-interface.php';
// ===== FIN Système Ballot =====

// ===== NOUVEAU v2.8.0 : Système Raben =====
require_once __DIR__ . '/class-raben-tariffs.php';
require_once __DIR__ . '/admin-tab-raben.php';
// ===== FIN Système Raben =====

// ===== NOUVEAU v3.0.0 : Internationalisation =====
require_once __DIR__ . '/class-international-manager.php';
require_once __DIR__ . '/class-country-config.php';
require_once __DIR__ . '/class-country-shipping.php';
require_once __DIR__ . '/class-country-pricing.php';
require_once __DIR__ . '/class-country-promotions.php';
require_once __DIR__ . '/international-product-fields.php';
require_once __DIR__ . '/international-admin-tab.php';
require_once __DIR__ . '/international-migration.php';
// ===== FIN Internationalisation =====

// ===== Mise à jour automatique via GitHub Releases =====
require_once __DIR__ . '/class-plugin-updater.php';

add_action('plugins_loaded', function () {
    if (!is_admin()) {
        return;
    }
    $updater = new SHP_Plugin_Updater(__FILE__, 'SolutionDBelgique', 'shipping-calculator-v2-extended');
    $updater->init();
});
// ===== FIN Mise à jour automatique =====

// Vérifier WooCommerce
add_action('admin_init', function() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Ce plugin nécessite WooCommerce. <a href="' . admin_url('plugins.php') . '">Retour</a>');
    }
});

/**
 * ===========================================
 * CLASSE BASE DE DONNÉES (Tarifs France)
 * ===========================================
 */
class SHP_V2_Extended_Database {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'shipping_tarifs';
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            quantite int(11) NOT NULL,
            departement varchar(3) NOT NULL,
            prix decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_quantite_dept (type, quantite, departement)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function get_tarif($type, $quantite, $departement) {
        global $wpdb;
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE type = %s AND quantite = %d AND departement = %s 
             LIMIT 1",
            $type, $quantite, $departement
        ));
        
        return $result;
    }
    
    public function get_all_tarifs($limit = 100, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             ORDER BY type, departement, quantite 
             LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }
    
    public function count_tarifs() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    public function insert_tarif($type, $quantite, $departement, $prix) {
        global $wpdb;
        
        return $wpdb->insert(
            $this->table_name,
            [
                'type' => $type,
                'quantite' => $quantite,
                'departement' => $departement,
                'prix' => $prix
            ],
            ['%s', '%d', '%s', '%f']
        );
    }
    
    public function delete_all_tarifs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    public function get_stats() {
        global $wpdb;
        
        return [
            'total' => $this->count_tarifs(),
            'forfait' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE type = 'forfait'"),
            'metre_plancher' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE type = 'metre_plancher'"),
            'departements' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT departement) FROM {$this->table_name}")
        ];
    }
}

/**
 * ===========================================
 * CLASSE GESTION RÈGLES TARIFAIRES
 * ===========================================
 */
class SHP_V2_Extended_Rules {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'shipping_rules';
    }
    
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            country_code varchar(2) NOT NULL,
            rule_name varchar(100) NOT NULL,
            postcode_condition varchar(20) NOT NULL,
            postcode_value varchar(50),
            postcode_value_end varchar(50),
            price_per_pallet decimal(10,2) NOT NULL,
            non_pallet_price decimal(10,2) NOT NULL,
            priority int(11) NOT NULL DEFAULT 10,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY country_priority (country_code, priority, enabled)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function get_all_rules($country = null) {
        global $wpdb;
        
        if ($country) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE country_code = %s 
                 ORDER BY priority ASC, id ASC",
                $country
            ));
        }
        
        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
             ORDER BY country_code, priority ASC, id ASC"
        );
    }
    
    public function get_active_rules($country) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE country_code = %s AND enabled = 1 
             ORDER BY priority ASC, id ASC",
            $country
        ));
    }
    
    public function get_rule($id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }
    
    public function insert_rule($data) {
        global $wpdb;
        
        return $wpdb->insert(
            $this->table_name,
            [
                'country_code' => $data['country_code'],
                'rule_name' => $data['rule_name'],
                'postcode_condition' => $data['postcode_condition'],
                'postcode_value' => $data['postcode_value'] ?? null,
                'postcode_value_end' => $data['postcode_value_end'] ?? null,
                'price_per_pallet' => $data['price_per_pallet'],
                'non_pallet_price' => $data['non_pallet_price'],
                'priority' => $data['priority'] ?? 10,
                'enabled' => $data['enabled'] ?? 1
            ],
            ['%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d']
        );
    }
    
    public function update_rule($id, $data) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            [
                'country_code' => $data['country_code'],
                'rule_name' => $data['rule_name'],
                'postcode_condition' => $data['postcode_condition'],
                'postcode_value' => $data['postcode_value'] ?? null,
                'postcode_value_end' => $data['postcode_value_end'] ?? null,
                'price_per_pallet' => $data['price_per_pallet'],
                'non_pallet_price' => $data['non_pallet_price'],
                'priority' => $data['priority'] ?? 10,
                'enabled' => $data['enabled'] ?? 1
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d'],
            ['%d']
        );
    }
    
    public function delete_rule($id) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            ['id' => $id],
            ['%d']
        );
    }
    
    public function toggle_rule($id) {
        global $wpdb;
        
        $rule = $this->get_rule($id);
        if (!$rule) {
            return false;
        }
        
        $new_status = $rule->enabled ? 0 : 1;
        
        return $wpdb->update(
            $this->table_name,
            ['enabled' => $new_status],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }
    
    public function match_rule($country, $postcode) {
        $rules = $this->get_active_rules($country);
        
        foreach ($rules as $rule) {
            if ($this->postcode_matches($postcode, $rule)) {
                return $rule;
            }
        }
        
        return null;
    }
    
    private function postcode_matches($postcode, $rule) {
        $postcode_clean = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($postcode));
        
        switch ($rule->postcode_condition) {
            case 'all':
                return true;
                
            case 'starts_with':
                $value = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($rule->postcode_value));
                return strpos($postcode_clean, $value) === 0;
                
            case 'between':
                $start = (int) preg_replace('/[^0-9]/', '', $rule->postcode_value);
                $end = (int) preg_replace('/[^0-9]/', '', $rule->postcode_value_end);
                $cp_int = (int) preg_replace('/[^0-9]/', '', $postcode_clean);
                return $cp_int >= $start && $cp_int <= $end;
                
            case 'equals':
                $value = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($rule->postcode_value));
                return $postcode_clean === $value;
                
            default:
                return false;
        }
    }
    
    public function get_countries() {
        global $wpdb;
        
        return $wpdb->get_col(
            "SELECT DISTINCT country_code FROM {$this->table_name} 
             ORDER BY country_code"
        );
    }
    
    public function count_rules($country = null) {
        global $wpdb;
        
        if ($country) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE country_code = %s",
                $country
            ));
        }
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    public function migrate_default_rules() {
        // Vérifier si déjà migrées
        if ($this->count_rules() > 0) {
            return false;
        }
        
        // Règles Belgique
        $this->insert_rule([
            'country_code' => 'BE',
            'rule_name' => 'Belgique Standard',
            'postcode_condition' => 'all',
            'price_per_pallet' => 50.00,
            'non_pallet_price' => 100.00,
            'priority' => 10,
            'enabled' => 1
        ]);
        
        $this->insert_rule([
            'country_code' => 'BE',
            'rule_name' => 'Province Luxembourg + Région 6xxx',
            'postcode_condition' => 'starts_with',
            'postcode_value' => '6',
            'price_per_pallet' => 60.00,
            'non_pallet_price' => 100.00,
            'priority' => 5,
            'enabled' => 1
        ]);
        
        // Règle Luxembourg
        $this->insert_rule([
            'country_code' => 'LU',
            'rule_name' => 'Grand-Duché Luxembourg',
            'postcode_condition' => 'all',
            'price_per_pallet' => 60.00,
            'non_pallet_price' => 100.00,
            'priority' => 10,
            'enabled' => 1
        ]);
        
        return true;
    }
    
    public function export_rules() {
        $rules = $this->get_all_rules();
        return json_encode($rules, JSON_PRETTY_PRINT);
    }
    
    public function import_rules($json_data, $replace = false) {
        $data = json_decode($json_data, true);
        
        if (!$data || !is_array($data)) {
            return ['success' => false, 'message' => 'Format JSON invalide'];
        }
        
        if ($replace) {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        }
        
        $imported = 0;
        foreach ($data as $rule) {
            if (isset($rule['id'])) {
                unset($rule['id']);
            }
            if (isset($rule['created_at'])) {
                unset($rule['created_at']);
            }
            
            if ($this->insert_rule($rule)) {
                $imported++;
            }
        }
        
        return [
            'success' => true,
            'imported' => $imported,
            'message' => sprintf('%d règle(s) importée(s)', $imported)
        ];
    }
}

/**
 * ===========================================
 * CLASSE CHAMPS PRODUIT
 * ===========================================
 */
class SHP_V2_Extended_Product_Fields {
    
    private static $instance = null;
    
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
        echo '<h4>🚚 Frais de Port V2 Extended</h4>';
        
        woocommerce_wp_checkbox([
            'id' => '_palette_complete_v2',
            'label' => 'Palette complète',
            'description' => '1 unité = 1 palette = 35 m². Frais calculés selon pays.',
            'value' => get_post_meta($post->ID, '_palette_complete_v2', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_palette_info_v2',
            'label' => 'Info palette',
            'placeholder' => 'Ex: 40 dalles × 0.96 m²',
            'value' => get_post_meta($post->ID, '_palette_info_v2', true)
        ]);
        
        echo '<p style="padding-left: 150px; color: #666; font-style: italic;">';
        echo '📦 <strong>Palette :</strong> Tarif selon règles pays<br>';
        echo '📦 <strong>Non-palette :</strong> Tarif selon règles pays';
        echo '</p>';
        
        echo '</div>';
    }
    
    public function save_fields($post_id) {
        $value = isset($_POST['_palette_complete_v2']) ? 'yes' : 'no';
        update_post_meta($post_id, '_palette_complete_v2', $value);
        
        if (isset($_POST['_palette_info_v2'])) {
            update_post_meta($post_id, '_palette_info_v2', sanitize_text_field($_POST['_palette_info_v2']));
        }
    }
    
    public function display_cart_info($item_data, $cart_item) {
        $product = $cart_item['data'];
        $is_palette = get_post_meta($product->get_id(), '_palette_complete_v2', true);
        
        if ($is_palette === 'yes') {
            $qty = $cart_item['quantity'];
            $surface = $qty * 35;
            
            $item_data[] = [
                'key' => sprintf(_n('%d palette', '%d palettes', $qty), $qty),
                'value' => sprintf('%d m² au total', $surface)
            ];
        }
        
        return $item_data;
    }
}

/**
 * ===========================================
 * CLASSE CALCUL V2 EXTENDED
 * ===========================================
 */
class SHP_V2_Extended_Calculator {
    
    private static $instance = null;
    private $db;
    private $rules;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->db = SHP_V2_Extended_Database::get_instance();
        $this->rules = SHP_V2_Extended_Rules::get_instance();
        add_filter('woocommerce_package_rates', [$this, 'calculate'], 10, 2);
        add_action('woocommerce_cart_totals_after_shipping', [$this, 'debug']);
        add_action('woocommerce_review_order_after_shipping', [$this, 'debug']);
    }
    
    public function calculate($rates, $package) {
        $country  = strtoupper($package['destination']['country']);
        $postcode = $package['destination']['postcode'];

        $analysis = $this->analyze_cart($package['contents'], $country);

        $has_dalles  = ($analysis['dalles_palettes'] > 0 || $analysis['dalles_non_palettes'] > 0);
        $has_matelas = ($analysis['matelas_count'] > 0);

        // CAS LIVRAISON GRATUITE UNIQUEMENT : creer taux a 0EUR
        if (!$has_dalles && !$has_matelas) {
            if ($analysis['nb_free_palettes'] > 0) {
                $nb = $analysis['nb_free_palettes'];
                $label = sprintf('Livraison GRATUITE - %d palette(s)', $nb);

                $rates = [];
                $rates['shp_v2_ext'] = new WC_Shipping_Rate(
                    'shp_v2_ext',
                    $label,
                    0,
                    [],
                    'shp_v2_ext'
                );
            }
            return $rates;
        }

        // v3.0 : Vérifier si le pays a un transport fixe
        $country_config = SHP_V2_Country_Config::get_instance();
        $shipping_type = $country_config->get_shipping_type($country);

        // Determiner le groupe pays
        $group1_countries = ['FR', 'BE', 'LU', 'NL'];
        $is_group1 = in_array($country, $group1_countries);

        $total_cost = 0;
        $details    = [];

        // === FRAIS DALLES ===
        if ($analysis['dalles_palettes'] > 0) {
            if ($shipping_type === 'fixed') {
                // v3.0 : Transport fixe (DE, PL)
                $dalles_cost = SHP_V2_Country_Shipping::get_instance()
                    ->calculate_shipping($country, $postcode, $analysis['dalles_palettes']);
            } elseif ($is_group1) {
                $dalles_cost = $this->calculate_palette_cost($country, $postcode, $analysis['dalles_palettes']);
            } else {
                $dalles_cost = $this->calculate_raben_cost($country, $postcode, $analysis['dalles_palettes'], 'dalles');
            }
            if ($dalles_cost !== null) {
                $total_cost += $dalles_cost['cost'];
                $details[] = $dalles_cost;
            }
        }

        // Frais non-palettes dalles
        if ($analysis['dalles_non_palettes'] > 0) {
            if ($shipping_type === 'fixed') {
                // v3.0 : Pour les pays à transport fixe, non-palette = même coût fixe
                $non_palette_cost = [
                    'type' => 'non_palettes_fixed',
                    'label' => 'Produits non-palettes',
                    'cost' => $country_config->get_shipping_cost($country),
                    'details' => ['country' => $country, 'shipping_type' => 'fixed']
                ];
            } else {
                $non_palette_cost = $this->calculate_non_palette_cost($country, $postcode);
            }
            if ($non_palette_cost !== null) {
                $total_cost += $non_palette_cost['cost'];
                $details[] = $non_palette_cost;
            }
        }

        // === FRAIS MATELAS ===
        if ($has_matelas) {
            if ($shipping_type === 'fixed') {
                // v3.0 : Transport fixe pour matelas aussi
                $matelas_cost = SHP_V2_Country_Shipping::get_instance()
                    ->calculate_shipping($country, $postcode, $analysis['matelas_palettes']);
                if ($matelas_cost !== null) {
                    $matelas_cost['label'] = sprintf('Matelas (%d = %d palette(s))', $analysis['matelas_count'], $analysis['matelas_palettes']);
                    $matelas_cost['type'] = 'matelas_fixed';
                }
            } elseif ($is_group1) {
                // Matelas groupe 1 : ceil(nb/2) palettes, meme grille
                $matelas_cost = $this->calculate_palette_cost($country, $postcode, $analysis['matelas_palettes']);
                if ($matelas_cost !== null) {
                    $matelas_cost['label'] = sprintf('Matelas (%d = %d palette(s))', $analysis['matelas_count'], $analysis['matelas_palettes']);
                    $matelas_cost['type'] = 'matelas_palettes';
                }
            } else {
                // Matelas groupe 2 : Raben poids 75kg/matelas
                $matelas_cost = $this->calculate_raben_cost($country, $postcode, $analysis['matelas_count'], 'matelas');
            }
            if ($matelas_cost !== null) {
                $total_cost += $matelas_cost['cost'];
                $details[] = $matelas_cost;
            }
        }

        if ($total_cost === 0) {
            return $rates;
        }

        WC()->session->set('shp_v2_ext_calc', [
            'country'          => $country,
            'postcode'         => $postcode,
            'nb_palettes'      => $analysis['dalles_palettes'],
            'nb_non_palettes'  => $analysis['dalles_non_palettes'],
            'matelas_count'    => $analysis['matelas_count'],
            'matelas_palettes' => $analysis['matelas_palettes'],
            'total_cost'       => $total_cost,
            'details'          => $details,
        ]);

        $label = $this->build_label_v2($country, $analysis);

        $taxes = WC_Tax::calc_shipping_tax( $total_cost, WC_Tax::get_shipping_tax_rates() );

        $rates['shp_v2_ext'] = new WC_Shipping_Rate(
            'shp_v2_ext',
            $label,
            $total_cost,
            $taxes,
            'shp_v2_ext'
        );

        return $rates;
    }
    
    private function analyze_cart($cart_items, $country = 'FR') {
        $dalles_palettes = 0;
        $dalles_non_palettes = 0;
        $matelas_count = 0;
        $nb_free_palettes = 0;

        $matelas_category_id = (int) get_option('shp_v2_ext_matelas_category_id', 0);

        foreach ($cart_items as $item) {
            $product = $item['data'];
            $quantity = $item['quantity'];
            $product_id = $product->get_id();

            // VERIFIER LIVRAISON GRATUITE
            $is_free_shipping = get_post_meta($product_id, '_free_shipping_palette', true);

            if ($is_free_shipping === 'yes') {
                if (isset($item['ballot_data'])) {
                    $nb_free_palettes += $item['ballot_data']['palettes'];
                } elseif (isset($item['degressive_data'])) {
                    $nb_free_palettes += $item['degressive_data']['palettes'];
                } elseif (get_post_meta($product_id, '_palette_complete_v2', true) === 'yes') {
                    $nb_free_palettes += $quantity;
                } else {
                    $nb_free_palettes += 1;
                }
                continue;
            }

            // v2.8.0 : Detecter les matelas par categorie WooCommerce
            if ($matelas_category_id > 0 && has_term($matelas_category_id, 'product_cat', $product_id)) {
                $matelas_count += $quantity;
                continue;
            }

            // TYPE 1: Palette complete normale (existant - dalles)
            $is_palette = get_post_meta($product_id, '_palette_complete_v2', true);

            if ($is_palette === 'yes') {
                $dalles_palettes += $quantity;
                continue;
            }

            // TYPE 2: Produit degressif au m²
            if (isset($item['degressive_data'])) {
                $dalles_palettes += $item['degressive_data']['palettes'];
                continue;
            }

            // TYPE 3: Produit degressif par Ballot
            if (isset($item['ballot_data'])) {
                $dalles_palettes += $item['ballot_data']['palettes'];
                continue;
            }

            // TYPE 4: Non-palette (existant - dalles)
            $dalles_non_palettes += $quantity;
        }

        return [
            'dalles_palettes'     => $dalles_palettes,
            'dalles_non_palettes' => $dalles_non_palettes,
            'matelas_count'       => $matelas_count,
            'matelas_palettes'    => $matelas_count > 0 ? (int) ceil($matelas_count / 2) : 0,
            'nb_free_palettes'    => $nb_free_palettes,
            // Backward compat
            'nb_palettes'         => $dalles_palettes,
            'nb_non_palettes'     => $dalles_non_palettes,
        ];
    }
    
    private function calculate_palette_cost($country, $postcode, $nb_palettes) {
        // France : utilise toujours tarifs Excel
        if ($country === 'FR') {
            return $this->calculate_france_palettes($postcode, $nb_palettes);
        }

        // Belgique : grille par paliers + surcharge Luxembourg
        if ($country === 'BE') {
            return $this->calculate_belgium_palettes($postcode, $nb_palettes);
        }

        // Autres pays : utilise règles
        $rule = $this->rules->match_rule($country, $postcode);
        
        if (!$rule) {
            return null;
        }
        
        $cost = $nb_palettes * $rule->price_per_pallet;
        
        return [
            'type' => 'palettes_rules',
            'label' => sprintf('Palettes %s (%d)', $rule->rule_name, $nb_palettes),
            'cost' => $cost,
            'details' => [
                'nb_palettes' => $nb_palettes,
                'tarif_unitaire' => $rule->price_per_pallet,
                'rule_name' => $rule->rule_name,
                'country' => $country
            ]
        ];
    }
    
    private function calculate_france_palettes($postcode, $nb_palettes) {
        $postcode_clean = preg_replace('/[^0-9]/', '', $postcode);
        
        if (strlen($postcode_clean) !== 5) {
            return null;
        }
        
        $dept = substr($postcode_clean, 0, 2);
        
        if ($postcode_clean >= '20000' && $postcode_clean <= '20190') {
            $dept = '2A';
        } elseif ($postcode_clean >= '20200' && $postcode_clean <= '20620') {
            $dept = '2B';
        }
        
        if (in_array($dept, ['97', '98'])) {
            $dept = substr($postcode_clean, 0, 3);
        }
        
        if ($nb_palettes >= 1 && $nb_palettes <= 3) {
            $type = 'forfait';
            $qte = $nb_palettes;
        } else {
            $type = 'metre_plancher';
            $qte = (int) ceil($nb_palettes * 0.5);
        }
        
        $tarif = $this->db->get_tarif($type, $qte, $dept);
        
        if (!$tarif) {
            return null;
        }
        
        return [
            'type' => 'france_palettes',
            'label' => sprintf('Palettes France (%d)', $nb_palettes),
            'cost' => (float) $tarif->prix,
            'details' => [
                'nb_palettes' => $nb_palettes,
                'tarif_type' => $type,
                'quantite' => $qte,
                'departement' => $dept
            ]
        ];
    }
    
    private function calculate_belgium_palettes($postcode, $nb_palettes) {
        // Grille par paliers
        if ($nb_palettes === 1) {
            $cost = 80.00;
        } elseif ($nb_palettes === 2) {
            $cost = 140.00;
        } elseif ($nb_palettes === 3) {
            $cost = 180.00;
        } else {
            $cost = $nb_palettes * 50.00;
        }

        // Surcharge région Luxembourg (codes postaux 6700-6999)
        $surcharge = 0;
        $postcode_int = (int) preg_replace('/[^0-9]/', '', $postcode);
        if ($postcode_int >= 6700 && $postcode_int <= 6999) {
            $surcharge = 20.00;
            $cost += $surcharge;
        }

        return [
            'type' => 'belgium_palettes',
            'label' => sprintf('Palettes Belgique (%d)', $nb_palettes),
            'cost' => $cost,
            'details' => [
                'nb_palettes' => $nb_palettes,
                'surcharge_luxembourg' => $surcharge,
                'country' => 'BE'
            ]
        ];
    }

    private function calculate_non_palette_cost($country, $postcode) {
        // France : 100€ fixe
        if ($country === 'FR') {
            return [
                'type' => 'non_palettes',
                'label' => 'Produits non-palettes',
                'cost' => 100.00,
                'details' => ['country' => $country]
            ];
        }
        
        // Autres pays : utilise règles
        $rule = $this->rules->match_rule($country, $postcode);
        
        if (!$rule) {
            return null;
        }
        
        return [
            'type' => 'non_palettes_rules',
            'label' => 'Produits non-palettes',
            'cost' => $rule->non_pallet_price,
            'details' => [
                'rule_name' => $rule->rule_name,
                'country' => $country
            ]
        ];
    }
    
    /**
     * Calcul des frais via Raben (pays hors Groupe 1)
     *
     * @param string $country Code pays
     * @param string $postcode Code postal
     * @param int    $quantity Nombre de palettes (dalles) ou matelas
     * @param string $product_type 'dalles' ou 'matelas'
     * @return array|null
     */
    private function calculate_raben_cost($country, $postcode, $quantity, $product_type) {
        $weight_kg = ($product_type === 'matelas') ? $quantity * 75 : $quantity * 1250;

        $result = SHP_V2_Extended_Raben_Tariffs::get_instance()
            ->calculate_shipping($country, $postcode, $weight_kg);

        if (!$result) {
            return null;
        }

        return [
            'type'    => 'raben',
            'label'   => sprintf('%s via Raben (%dkg)', ucfirst($product_type), $weight_kg),
            'cost'    => $result['price_all_in'],
            'details' => [
                'product_type'    => $product_type,
                'quantity'        => $quantity,
                'weight_kg'       => $weight_kg,
                'zone'            => $result['zone'],
                'price_netto'     => $result['price_netto'],
                'surcharge_pct'   => $result['surcharge_pct'],
                'price_all_in'    => $result['price_all_in'],
                'country'         => $country,
            ],
        ];
    }

    private function build_label_v2($country, $analysis) {
        $parts = [];

        if ($analysis['dalles_palettes'] > 0) {
            $parts[] = sprintf('%d palette(s) dalles', $analysis['dalles_palettes']);
        }

        if ($analysis['matelas_count'] > 0) {
            $parts[] = sprintf('%d matelas', $analysis['matelas_count']);
        }

        if ($analysis['dalles_non_palettes'] > 0) {
            $parts[] = 'produits standard';
        }

        return 'Livraison - ' . implode(' + ', $parts);
    }

    /**
     * @deprecated Use build_label_v2() instead
     */
    private function build_label($country, $analysis) {
        return $this->build_label_v2($country, $analysis);
    }
    
    public function debug() {
        $calc = WC()->session->get('shp_v2_ext_calc');
        
        if (!$calc || get_option('shp_v2_ext_debug') !== 'yes') {
            return;
        }
        
        ?>
        <tr>
            <th colspan="2">
                <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1;">
                    <h4 style="color: #2271b1;">🔍 Debug V2 Extended</h4>
                    <table style="width: 100%; font-size: 13px;">
                        <tr>
                            <td style="font-weight: 600; width: 40%;">Pays :</td>
                            <td><?php echo esc_html($calc['country']); ?> (<?php echo esc_html($calc['postcode']); ?>)</td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600;">Palettes dalles :</td>
                            <td><?php echo esc_html($calc['nb_palettes']); ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600;">Non-palettes dalles :</td>
                            <td><?php echo esc_html($calc['nb_non_palettes']); ?></td>
                        </tr>
                        <?php if (!empty($calc['matelas_count'])): ?>
                        <tr>
                            <td style="font-weight: 600;">Matelas :</td>
                            <td><?php echo esc_html($calc['matelas_count']); ?> (<?php echo esc_html($calc['matelas_palettes']); ?> palette(s))</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($calc['details'] as $detail): ?>
                        <tr>
                            <td style="font-weight: 600;"><?php echo esc_html($detail['label']); ?> :</td>
                            <td><?php echo wc_price($detail['cost']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 2px solid #2271b1;">
                            <td style="font-weight: 600; font-size: 16px;">TOTAL :</td>
                            <td style="font-weight: 700; font-size: 16px; color: #2271b1;">
                                <?php echo wc_price($calc['total_cost']); ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </th>
        </tr>
        <?php
    }
}

/**
 * ===========================================
 * CLASSE IMPORT EXCEL (France uniquement)
 * ===========================================
 */
class SHP_V2_Extended_Excel_Importer {
    
    private static $instance = null;
    private $db;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->db = SHP_V2_Extended_Database::get_instance();
    }
    
    public function import_from_excel($file_path, $replace_all = false) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            return [
                'success' => false,
                'message' => 'PhpSpreadsheet n\'est pas installé.'
            ];
        }
        
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            array_shift($rows);
            
            if ($replace_all) {
                $this->db->delete_all_tarifs();
            }
            
            $imported = 0;
            $errors = [];
            
            foreach ($rows as $index => $row) {
                if (empty($row[0]) || empty($row[1]) || empty($row[2]) || empty($row[3])) {
                    continue;
                }
                
                $type = trim($row[0]);
                $quantite = (int) $row[1];
                $departement = trim($row[2]);
                $prix = (float) $row[3];
                
                if (!in_array($type, ['forfait', 'metre_plancher'])) {
                    $errors[] = "Ligne " . ($index + 2) . ": Type invalide '$type'";
                    continue;
                }
                
                if ($quantite <= 0 || empty($departement) || $prix <= 0) {
                    $errors[] = "Ligne " . ($index + 2) . ": Données invalides";
                    continue;
                }
                
                if ($this->db->insert_tarif($type, $quantite, $departement, $prix)) {
                    $imported++;
                }
            }
            
            return [
                'success' => true,
                'imported' => $imported,
                'errors' => $errors,
                'message' => sprintf('%d tarif(s) importé(s)', $imported)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ];
        }
    }
    
    public function export_to_excel() {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return false;
        }
        
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            $sheet->setCellValue('A1', 'Type');
            $sheet->setCellValue('B1', 'Quantité');
            $sheet->setCellValue('C1', 'Département');
            $sheet->setCellValue('D1', 'Prix');
            
            $sheet->getStyle('A1:D1')->getFont()->setBold(true);
            
            $tarifs = $this->db->get_all_tarifs(10000, 0);
            $row = 2;
            
            foreach ($tarifs as $tarif) {
                $sheet->setCellValue('A' . $row, $tarif->type);
                $sheet->setCellValue('B' . $row, $tarif->quantite);
                $sheet->setCellValue('C' . $row, $tarif->departement);
                $sheet->setCellValue('D' . $row, $tarif->prix);
                $row++;
            }
            
            foreach (range('A', 'D') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            
            $filename = 'tarifs-france-export-' . date('Y-m-d-His') . '.xlsx';
            $filepath = wp_upload_dir()['path'] . '/' . $filename;
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);
            
            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'url' => wp_upload_dir()['url'] . '/' . $filename
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

/**
 * ===========================================
 * ENREGISTREMENT MÉTHODE WOOCOMMERCE
 * ===========================================
 */
add_action('woocommerce_shipping_init', function() {
    
    class WC_Shipping_Extended extends WC_Shipping_Method {
        
        public function __construct($instance_id = 0) {
            $this->id = 'shp_v2_ext';
            $this->instance_id = absint($instance_id);
            $this->method_title = 'Shipping Calculator V2 Extended';
            $this->method_description = 'Calcul automatique multi-pays avec règles tarifaires';
            $this->supports = ['shipping-zones', 'instance-settings'];
            $this->enabled = 'yes';
            
            $this->init();
        }
        
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->get_option('title', $this->method_title);
            $this->enabled = $this->get_option('enabled', 'yes');
            
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }
        
        public function init_form_fields() {
            $this->instance_form_fields = [
                'title' => [
                    'title' => 'Titre',
                    'type' => 'text',
                    'description' => 'Titre affiché au client',
                    'default' => 'Livraison',
                    'desc_tip' => true
                ],
                'enabled' => [
                    'title' => 'Activer/Désactiver',
                    'type' => 'checkbox',
                    'label' => 'Activer cette méthode',
                    'default' => 'yes'
                ]
            ];
        }
        
        public function calculate_shipping($package = []) {
            // Géré par SHP_V2_Extended_Calculator
        }
    }
});

add_filter('woocommerce_shipping_methods', function($methods) {
    $methods['shp_v2_ext'] = 'WC_Shipping_Extended';
    return $methods;
});

/**
 * ===========================================
 * ACTIVATION
 * ===========================================
 */
register_activation_hook(__FILE__, function() {
    SHP_V2_Extended_Database::get_instance()->create_tables();
    SHP_V2_Extended_Rules::get_instance()->create_table();
    SHP_V2_Extended_Rules::get_instance()->migrate_default_rules();
    SHP_V2_Extended_Raben_Tariffs::get_instance()->create_tables();
    add_option('shp_v2_ext_debug', 'yes');
    add_option('shp_v2_ext_matelas_category_id', 0);
    add_option('shp_v2_ext_raben_fuel_surcharge', 15.00);
    add_option('shp_v2_ext_raben_road_surcharge', 8.76);
    // v3.0 : Initialiser config internationale
    SHP_V2_Country_Config::get_instance()->init_default_config();
    set_transient('shp_v2_ext_activated', true, 60);
});

/**
 * ===========================================
 * INITIALISATION
 * ===========================================
 */
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    SHP_V2_Extended_Database::get_instance();
    SHP_V2_Extended_Rules::get_instance();
    SHP_V2_Extended_Degressive_Products::get_instance();
    SHP_V2_Extended_Product_Fields::get_instance();
    SHP_V2_Extended_Calculator::get_instance();
    SHP_V2_Extended_Excel_Importer::get_instance();
    SHP_V2_Extended_Ballot_Products::get_instance();
    SHP_V2_Extended_Raben_Tariffs::get_instance();
    // v3.0 : Internationalisation
    SHP_V2_International_Manager::get_instance();
    SHP_V2_Country_Config::get_instance();
    SHP_V2_Country_Shipping::get_instance();
    SHP_V2_Country_Pricing::get_instance();
    SHP_V2_Country_Promotions::get_instance();
});

/**
 * ===========================================
 * NOTICE ACTIVATION
 * ===========================================
 */
add_action('admin_notices', function() {
    if (get_transient('shp_v2_ext_activated')) {
        $rules_count = SHP_V2_Extended_Rules::get_instance()->count_rules();
        $degressive_count = SHP_V2_Extended_Degressive_Products::get_instance()->count_degressive_products();
        ?>
        <div class="notice notice-success is-dismissible">
            <h3>✅ Shipping Calculator V2 Extended - Version 2.6.0 Activé !</h3>
            <p><strong>🎉 Nouvelle Fonctionnalité : Produits Dégressifs au m² !</strong></p>
            <p><?php echo $rules_count; ?> règle(s) | <?php echo $degressive_count; ?> produit(s) dégressif(s)</p>
            <p><a href="<?php echo admin_url('admin.php?page=shipping-calculator-v2-ext'); ?>" class="button button-primary">Configurer →</a></p>
        </div>
        <?php
        delete_transient('shp_v2_ext_activated');
    }
});

// Suite dans le prochain message (fichier trop long)...

/**
 * ===========================================
 * PAGE ADMIN
 * ===========================================
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Tarifs de Port V2 Extended',
        'Tarifs Port Extended',
        'manage_woocommerce',
        'shipping-calculator-v2-ext',
        'shp_v2_ext_admin_page'
    );
}, 99);

function shp_v2_ext_admin_page() {
    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
    
    if (isset($_POST['shp_v2_ext_action'])) {
        shp_v2_ext_handle_action();
    }
    
    ?>
    <div class="wrap">
        <h1>🚚 Shipping Calculator V2 Extended - v3.0.0</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=shipping-calculator-v2-ext&tab=overview" class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                📊 Vue d'ensemble
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=rules" class="nav-tab <?php echo $tab === 'rules' ? 'nav-tab-active' : ''; ?>">
                🌍 Règles Tarifaires
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=degressive" class="nav-tab <?php echo $tab === 'degressive' ? 'nav-tab-active' : ''; ?>">
                🎯 Produits Dégressifs
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=france" class="nav-tab <?php echo $tab === 'france' ? 'nav-tab-active' : ''; ?>">
                🇫🇷 Tarifs France
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=raben" class="nav-tab <?php echo $tab === 'raben' ? 'nav-tab-active' : ''; ?>">
                🚛 Raben
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=international" class="nav-tab <?php echo $tab === 'international' ? 'nav-tab-active' : ''; ?>">
                🌐 International
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=test" class="nav-tab <?php echo $tab === 'test' ? 'nav-tab-active' : ''; ?>">
                🧪 Tester
            </a>
            <a href="?page=shipping-calculator-v2-ext&tab=config" class="nav-tab <?php echo $tab === 'config' ? 'nav-tab-active' : ''; ?>">
                ⚙️ Configuration
            </a>
        </h2>
        
        <?php
        switch ($tab) {
            case 'rules':
                shp_v2_ext_tab_rules();
                break;
            case 'degressive':
                shp_v2_ext_tab_degressive();
                break;
            case 'france':
                shp_v2_ext_tab_france();
                break;
            case 'raben':
                shp_v2_ext_tab_raben();
                break;
            case 'international':
                shp_v2_ext_tab_international();
                break;
            case 'test':
                shp_v2_ext_tab_test();
                break;
            case 'config':
                shp_v2_ext_tab_config();
                break;
            default:
                shp_v2_ext_tab_overview();
        }
        ?>
    </div>
    <?php
}

function shp_v2_ext_tab_overview() {
    $db = SHP_V2_Extended_Database::get_instance();
    $rules = SHP_V2_Extended_Rules::get_instance();
    $stats_france = $db->get_stats();
    $countries = $rules->get_countries();
    ?>
    <div class="notice notice-info">
        <h3>🎉 Nouvelle Version 2.5.0 - Interface de Gestion des Règles !</h3>
        <p><strong>Nouveauté :</strong> Gérez tous vos tarifs par pays via l'onglet "Règles Tarifaires"</p>
        <p><strong>France :</strong> Reste sur tarifs Excel (onglet "Tarifs France")</p>
        <p><strong>Autres pays :</strong> Gérez via les règles tarifaires (facile à configurer !)</p>
    </div>
    
    <h2>📊 Statistiques</h2>
    
    <table class="widefat" style="width: auto; margin-bottom: 20px;">
        <tr>
            <th colspan="2" style="padding: 10px; background: #2271b1; color: white;">
                🇫🇷 Tarifs France (Excel)
            </th>
        </tr>
        <tr>
            <th style="padding: 10px;">Tarifs totaux</th>
            <td style="padding: 10px;"><strong><?php echo $stats_france['total']; ?></strong></td>
        </tr>
        <tr>
            <th style="padding: 10px;">Forfaitaires (1-3 pal.)</th>
            <td style="padding: 10px;"><?php echo $stats_france['forfait']; ?></td>
        </tr>
        <tr>
            <th style="padding: 10px;">Mètre plancher (>3 pal.)</th>
            <td style="padding: 10px;"><?php echo $stats_france['metre_plancher']; ?></td>
        </tr>
        <tr>
            <th style="padding: 10px;">Départements</th>
            <td style="padding: 10px;"><?php echo $stats_france['departements']; ?></td>
        </tr>
    </table>
    
    <table class="widefat" style="width: auto;">
        <tr>
            <th colspan="2" style="padding: 10px; background: #2271b1; color: white;">
                🌍 Règles Tarifaires (Autres Pays)
            </th>
        </tr>
        <tr>
            <th style="padding: 10px;">Règles actives</th>
            <td style="padding: 10px;"><strong><?php echo $rules->count_rules(); ?></strong></td>
        </tr>
        <tr>
            <th style="padding: 10px;">Pays couverts</th>
            <td style="padding: 10px;">
                <?php 
                if (!empty($countries)) {
                    echo implode(', ', array_map(function($c) { 
                        return '<strong>' . $c . '</strong>'; 
                    }, $countries));
                } else {
                    echo '<em>Aucun</em>';
                }
                ?>
            </td>
        </tr>
    </table>
    
    <h2 style="margin-top: 30px;">🚀 Démarrage Rapide</h2>
    <ol>
        <li><strong>France :</strong> Importez vos tarifs Excel dans l'onglet "Tarifs France"</li>
        <li><strong>Autres pays :</strong> Créez des règles dans l'onglet "Règles Tarifaires"</li>
        <li><strong>Zones WooCommerce :</strong> Ajoutez "Shipping Calculator V2 Extended" à chaque zone pays</li>
        <li><strong>Produits :</strong> Cochez "Palette complète" pour les produits palettes</li>
        <li><strong>Test :</strong> Utilisez l'onglet "Tester" pour vérifier vos configurations</li>
    </ol>
    
    <p><a href="?page=shipping-calculator-v2-ext&tab=rules" class="button button-primary">Gérer les Règles Tarifaires →</a></p>
    <?php
}

function shp_v2_ext_tab_rules() {
    $rules_db = SHP_V2_Extended_Rules::get_instance();
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    $rule_id = isset($_GET['rule_id']) ? (int) $_GET['rule_id'] : 0;
    
    if ($action === 'edit' && $rule_id) {
        shp_v2_ext_rule_form($rule_id);
    } elseif ($action === 'add') {
        shp_v2_ext_rule_form(0);
    } else {
        shp_v2_ext_rules_list();
    }
}

function shp_v2_ext_rules_list() {
    $rules_db = SHP_V2_Extended_Rules::get_instance();
    $rules = $rules_db->get_all_rules();
    $countries = [
        'BE' => '🇧🇪 Belgique',
        'LU' => '🇱🇺 Luxembourg',
        'NL' => '🇳🇱 Pays-Bas',
        'DE' => '🇩🇪 Allemagne',
        'ES' => '🇪🇸 Espagne',
        'IT' => '🇮🇹 Italie',
        'GB' => '🇬🇧 Royaume-Uni',
        'CH' => '🇨🇭 Suisse',
        'AT' => '🇦🇹 Autriche',
        'PT' => '🇵🇹 Portugal'
    ];
    ?>
    <h2>🌍 Règles Tarifaires</h2>
    
    <p>
        <a href="?page=shipping-calculator-v2-ext&tab=rules&action=add" class="button button-primary">
            ➕ Ajouter une règle
        </a>
        <a href="#" onclick="return confirm('Export disponible bientôt');" class="button">
            📤 Exporter toutes les règles
        </a>
    </p>
    
    <?php if (empty($rules)): ?>
        <div class="notice notice-warning">
            <p><strong>Aucune règle tarifaire créée.</strong></p>
            <p>Cliquez sur "Ajouter une règle" pour créer votre première règle tarifaire.</p>
        </div>
    <?php else: ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th style="width: 60px;">Pays</th>
                    <th>Nom de la règle</th>
                    <th>Condition</th>
                    <th style="text-align: right;">€/Palette</th>
                    <th style="text-align: right;">€/Non-Pal.</th>
                    <th style="text-align: center; width: 80px;">Priorité</th>
                    <th style="text-align: center; width: 80px;">Statut</th>
                    <th style="text-align: center; width: 200px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule): ?>
                <tr <?php echo $rule->enabled ? '' : 'style="opacity: 0.5;"'; ?>>
                    <td>
                        <strong>
                            <?php echo isset($countries[$rule->country_code]) ? $countries[$rule->country_code] : $rule->country_code; ?>
                        </strong>
                    </td>
                    <td><?php echo esc_html($rule->rule_name); ?></td>
                    <td>
                        <?php
                        switch ($rule->postcode_condition) {
                            case 'all':
                                echo '<em>Tous les codes postaux</em>';
                                break;
                            case 'starts_with':
                                echo 'CP commence par <strong>' . esc_html($rule->postcode_value) . '</strong>';
                                break;
                            case 'between':
                                echo 'CP entre <strong>' . esc_html($rule->postcode_value) . '</strong> et <strong>' . esc_html($rule->postcode_value_end) . '</strong>';
                                break;
                            case 'equals':
                                echo 'CP égal à <strong>' . esc_html($rule->postcode_value) . '</strong>';
                                break;
                        }
                        ?>
                    </td>
                    <td style="text-align: right;"><strong><?php echo number_format($rule->price_per_pallet, 2); ?>€</strong></td>
                    <td style="text-align: right;"><?php echo number_format($rule->non_pallet_price, 2); ?>€</td>
                    <td style="text-align: center;"><?php echo $rule->priority; ?></td>
                    <td style="text-align: center;">
                        <?php if ($rule->enabled): ?>
                            <span style="color: green;">✅ Actif</span>
                        <?php else: ?>
                            <span style="color: red;">❌ Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <a href="?page=shipping-calculator-v2-ext&tab=rules&action=edit&rule_id=<?php echo $rule->id; ?>" class="button button-small">
                            ✏️ Modifier
                        </a>
                        <form method="post" style="display: inline;" onsubmit="return confirm('Supprimer cette règle ?');">
                            <?php wp_nonce_field('shp_v2_ext_delete_rule', 'shp_v2_ext_nonce'); ?>
                            <input type="hidden" name="shp_v2_ext_action" value="delete_rule">
                            <input type="hidden" name="rule_id" value="<?php echo $rule->id; ?>">
                            <button type="submit" class="button button-small button-link-delete">
                                🗑️
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <div class="notice notice-info" style="margin-top: 20px;">
        <h4>💡 Comment fonctionnent les règles ?</h4>
        <p><strong>Priorité :</strong> Si plusieurs règles matchent, celle avec la priorité la plus basse (1) s'applique en premier.</p>
        <p><strong>Condition :</strong> Définit quels codes postaux sont concernés par la règle.</p>
        <p><strong>Exemple :</strong></p>
        <ul>
            <li>Règle 1 (Priorité 5) : Belgique codes 6xxx → 60€/palette</li>
            <li>Règle 2 (Priorité 10) : Belgique tous codes → 50€/palette</li>
            <li>→ Un client à Charleroi (6000) paiera 60€ (règle 1 prioritaire)</li>
            <li>→ Un client à Bruxelles (1000) paiera 50€ (règle 2)</li>
        </ul>
    </div>
    <?php
}

function shp_v2_ext_rule_form($rule_id = 0) {
    $rules_db = SHP_V2_Extended_Rules::get_instance();
    $rule = $rule_id ? $rules_db->get_rule($rule_id) : null;
    
    $countries = [
        'BE' => '🇧🇪 Belgique',
        'LU' => '🇱🇺 Luxembourg',
        'NL' => '🇳🇱 Pays-Bas',
        'DE' => '🇩🇪 Allemagne',
        'ES' => '🇪🇸 Espagne',
        'IT' => '🇮🇹 Italie',
        'GB' => '🇬🇧 Royaume-Uni',
        'CH' => '🇨🇭 Suisse',
        'AT' => '🇦🇹 Autriche',
        'PT' => '🇵🇹 Portugal'
    ];
    ?>
    <h2><?php echo $rule_id ? '✏️ Modifier' : '➕ Ajouter'; ?> une Règle Tarifaire</h2>
    
    <p>
        <a href="?page=shipping-calculator-v2-ext&tab=rules" class="button">
            ← Retour à la liste
        </a>
    </p>
    
    <form method="post" style="max-width: 800px;">
        <?php wp_nonce_field('shp_v2_ext_save_rule', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="save_rule">
        <input type="hidden" name="rule_id" value="<?php echo $rule_id; ?>">
        
        <table class="form-table">
            <tr>
                <th scope="row"><label for="country_code">Pays *</label></th>
                <td>
                    <select name="country_code" id="country_code" required style="width: 300px;">
                        <option value="">-- Sélectionner un pays --</option>
                        <?php foreach ($countries as $code => $name): ?>
                            <option value="<?php echo $code; ?>" <?php echo ($rule && $rule->country_code === $code) ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><label for="rule_name">Nom de la règle *</label></th>
                <td>
                    <input type="text" name="rule_name" id="rule_name" value="<?php echo $rule ? esc_attr($rule->rule_name) : ''; ?>" required style="width: 300px;" placeholder="Ex: Belgique Standard">
                    <p class="description">Nom descriptif pour identifier la règle</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><label>Condition code postal *</label></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="radio" name="postcode_condition" value="all" <?php echo (!$rule || $rule->postcode_condition === 'all') ? 'checked' : ''; ?>>
                            Tous les codes postaux
                        </label><br>
                        
                        <label style="margin-top: 10px; display: inline-block;">
                            <input type="radio" name="postcode_condition" value="starts_with" <?php echo ($rule && $rule->postcode_condition === 'starts_with') ? 'checked' : ''; ?>>
                            Code postal commence par :
                        </label>
                        <input type="text" name="postcode_value_starts" value="<?php echo ($rule && $rule->postcode_condition === 'starts_with') ? esc_attr($rule->postcode_value) : ''; ?>" style="width: 100px;" placeholder="Ex: 6">
                        <br>
                        
                        <label style="margin-top: 10px; display: inline-block;">
                            <input type="radio" name="postcode_condition" value="between" <?php echo ($rule && $rule->postcode_condition === 'between') ? 'checked' : ''; ?>>
                            Code postal entre :
                        </label>
                        <input type="text" name="postcode_value_between_start" value="<?php echo ($rule && $rule->postcode_condition === 'between') ? esc_attr($rule->postcode_value) : ''; ?>" style="width: 100px;" placeholder="6000">
                        et
                        <input type="text" name="postcode_value_between_end" value="<?php echo ($rule && $rule->postcode_condition === 'between') ? esc_attr($rule->postcode_value_end) : ''; ?>" style="width: 100px;" placeholder="6999">
                        <br>
                        
                        <label style="margin-top: 10px; display: inline-block;">
                            <input type="radio" name="postcode_condition" value="equals" <?php echo ($rule && $rule->postcode_condition === 'equals') ? 'checked' : ''; ?>>
                            Code postal égal à :
                        </label>
                        <input type="text" name="postcode_value_equals" value="<?php echo ($rule && $rule->postcode_condition === 'equals') ? esc_attr($rule->postcode_value) : ''; ?>" style="width: 100px;" placeholder="1000">
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><label for="price_per_pallet">Tarif par palette * (€)</label></th>
                <td>
                    <input type="number" name="price_per_pallet" id="price_per_pallet" value="<?php echo $rule ? $rule->price_per_pallet : '50.00'; ?>" required step="0.01" min="0" style="width: 150px;">
                    <p class="description">Prix en euros pour 1 palette</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><label for="non_pallet_price">Prix non-palettes * (€)</label></th>
                <td>
                    <input type="number" name="non_pallet_price" id="non_pallet_price" value="<?php echo $rule ? $rule->non_pallet_price : '100.00'; ?>" required step="0.01" min="0" style="width: 150px;">
                    <p class="description">Prix forfaitaire pour les produits non-palettes</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><label for="priority">Priorité *</label></th>
                <td>
                    <input type="number" name="priority" id="priority" value="<?php echo $rule ? $rule->priority : '10'; ?>" required min="1" max="99" style="width: 100px;">
                    <p class="description">1 = prioritaire, 99 = dernier. En cas de conflit, la règle avec la priorité la plus basse s'applique.</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><label for="enabled">Statut</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" id="enabled" value="1" <?php echo (!$rule || $rule->enabled) ? 'checked' : ''; ?>>
                        Règle activée
                    </label>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="submit" class="button button-primary">
                💾 Enregistrer la règle
            </button>
            <a href="?page=shipping-calculator-v2-ext&tab=rules" class="button">Annuler</a>
        </p>
    </form>
    <?php
}

function shp_v2_ext_tab_france() {
    $db = SHP_V2_Extended_Database::get_instance();
    $importer = SHP_V2_Extended_Excel_Importer::get_instance();
    $has_phpspreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
    $tarifs = $db->get_all_tarifs(20, 0);
    ?>
    <h2>🇫🇷 Tarifs France (Excel)</h2>
    
    <div class="notice notice-info">
        <p><strong>Info :</strong> Les tarifs France restent gérés via fichiers Excel (logique complexe forfait/mètre plancher).</p>
        <p>Pour les autres pays, utilisez l'onglet "Règles Tarifaires".</p>
    </div>
    
    <?php if (!$has_phpspreadsheet): ?>
        <div class="notice notice-error">
            <h3>⚠️ PhpSpreadsheet Non Installé</h3>
            <p>Pour l'import Excel : <code>composer install --no-dev</code> dans le dossier du plugin.</p>
        </div>
    <?php endif; ?>
    
    <h3>📥 Importer des Tarifs</h3>
    <form method="post" enctype="multipart/form-data" style="max-width: 600px;">
        <?php wp_nonce_field('shp_v2_ext_import', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="import">
        
        <table class="form-table">
            <tr>
                <th><label for="excel_file">Fichier Excel</label></th>
                <td>
                    <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" required <?php echo !$has_phpspreadsheet ? 'disabled' : ''; ?>>
                </td>
            </tr>
            <tr>
                <th><label for="replace_all">Remplacer tous</label></th>
                <td>
                    <input type="checkbox" name="replace_all" id="replace_all" value="1">
                    <span class="description">⚠️ Supprime les tarifs existants</span>
                </td>
            </tr>
        </table>
        
        <p>
            <button type="submit" class="button button-primary" <?php echo !$has_phpspreadsheet ? 'disabled' : ''; ?>>
                📥 Importer
            </button>
        </p>
    </form>
    
    <h3>📤 Exporter les Tarifs</h3>
    <form method="post">
        <?php wp_nonce_field('shp_v2_ext_export', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="export">
        <p>
            <button type="submit" class="button" <?php echo !$has_phpspreadsheet ? 'disabled' : ''; ?>>
                📤 Exporter
            </button>
        </p>
    </form>
    
    <h3>📋 Aperçu (20 premiers tarifs)</h3>
    <table class="widefat">
        <thead>
            <tr>
                <th>Type</th>
                <th>Quantité</th>
                <th>Département</th>
                <th>Prix</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tarifs)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 20px;">
                        Aucun tarif. Importez un fichier Excel.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tarifs as $tarif): ?>
                <tr>
                    <td><?php echo esc_html($tarif->type); ?></td>
                    <td><?php echo esc_html($tarif->quantite); ?></td>
                    <td><?php echo esc_html($tarif->departement); ?></td>
                    <td><?php echo number_format($tarif->prix, 2); ?>€</td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

function shp_v2_ext_tab_test() {
    $rules_db = SHP_V2_Extended_Rules::get_instance();
    $countries = [
        'FR' => '🇫🇷 France',
        'BE' => '🇧🇪 Belgique',
        'LU' => '🇱🇺 Luxembourg',
        'NL' => '🇳🇱 Pays-Bas',
        'DE' => '🇩🇪 Allemagne'
    ];
    
    $test_result = null;
    if (isset($_POST['test_shipping'])) {
        $country = $_POST['test_country'];
        $postcode = $_POST['test_postcode'];
        $palettes = (int) $_POST['test_palettes'];
        
        $rule = $rules_db->match_rule($country, $postcode);
        
        if ($rule) {
            $cost = $palettes * $rule->price_per_pallet;
            $test_result = [
                'success' => true,
                'rule' => $rule,
                'palettes' => $palettes,
                'cost' => $cost
            ];
        } else {
            $test_result = ['success' => false];
        }
    }
    ?>
    <h2>🧪 Tester une Configuration</h2>
    
    <form method="post" style="max-width: 600px;">
        <table class="form-table">
            <tr>
                <th><label for="test_country">Pays</label></th>
                <td>
                    <select name="test_country" id="test_country" required>
                        <?php foreach ($countries as $code => $name): ?>
                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="test_postcode">Code Postal</label></th>
                <td>
                    <input type="text" name="test_postcode" id="test_postcode" required placeholder="Ex: 1000, 6700">
                </td>
            </tr>
            <tr>
                <th><label for="test_palettes">Nombre de Palettes</label></th>
                <td>
                    <input type="number" name="test_palettes" id="test_palettes" required min="1" value="3">
                </td>
            </tr>
        </table>
        
        <p>
            <button type="submit" name="test_shipping" class="button button-primary">
                🧪 Tester
            </button>
        </p>
    </form>
    
    <?php if ($test_result): ?>
        <?php if ($test_result['success']): ?>
            <div class="notice notice-success" style="padding: 15px;">
                <h3>✅ Résultat du Test</h3>
                <table style="width: auto;">
                    <tr>
                        <td style="padding: 5px;"><strong>Règle appliquée :</strong></td>
                        <td style="padding: 5px;"><?php echo esc_html($test_result['rule']->rule_name); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Pays :</strong></td>
                        <td style="padding: 5px;"><?php echo esc_html($test_result['rule']->country_code); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Palettes :</strong></td>
                        <td style="padding: 5px;"><?php echo $test_result['palettes']; ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Prix unitaire :</strong></td>
                        <td style="padding: 5px;"><?php echo number_format($test_result['rule']->price_per_pallet, 2); ?>€</td>
                    </tr>
                    <tr style="border-top: 2px solid #2271b1;">
                        <td style="padding: 5px;"><strong>TOTAL :</strong></td>
                        <td style="padding: 5px;"><strong style="font-size: 18px; color: #2271b1;"><?php echo number_format($test_result['cost'], 2); ?>€</strong></td>
                    </tr>
                </table>
            </div>
        <?php else: ?>
            <div class="notice notice-error">
                <p><strong>❌ Aucune règle trouvée pour cette configuration.</strong></p>
                <p>Créez une règle dans l'onglet "Règles Tarifaires".</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}

function shp_v2_ext_tab_config() {
    $matelas_category_id = (int) get_option('shp_v2_ext_matelas_category_id', 0);
    ?>
    <h2>⚙️ Configuration</h2>

    <!-- Categorie Matelas -->
    <h3>Categorie Matelas (v2.8.0)</h3>
    <form method="post" style="max-width: 600px; margin-bottom: 30px;">
        <?php wp_nonce_field('shp_v2_ext_save_matelas_category', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="save_matelas_category">

        <table class="form-table">
            <tr>
                <th><label for="matelas_category_id">Categorie WooCommerce</label></th>
                <td>
                    <?php
                    $categories = get_terms([
                        'taxonomy'   => 'product_cat',
                        'hide_empty' => false,
                        'orderby'    => 'name',
                    ]);
                    ?>
                    <select name="matelas_category_id" id="matelas_category_id" style="width: 300px;">
                        <option value="0">-- Aucune (desactive) --</option>
                        <?php if (!is_wp_error($categories)): ?>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($matelas_category_id, $cat->term_id); ?>>
                                    <?php echo esc_html($cat->name); ?> (<?php echo $cat->count; ?> produits)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="description">Les produits de cette categorie seront traites comme des matelas (75kg, 2 par palette)</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary">Enregistrer la categorie</button>
        </p>
    </form>

    <!-- Debug -->
    <h3>Mode Debug</h3>
    <form method="post">
        <?php wp_nonce_field('shp_v2_ext_config', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="toggle_debug">

        <table class="form-table">
            <tr>
                <th>Mode Debug</th>
                <td>
                    <?php if (get_option('shp_v2_ext_debug') === 'yes'): ?>
                        <p><span style="color: green; font-weight: bold;">Actif</span></p>
                        <button type="submit" class="button">Desactiver</button>
                    <?php else: ?>
                        <p><span style="color: red; font-weight: bold;">Inactif</span></p>
                        <button type="submit" class="button">Activer</button>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </form>
    <?php
}

function shp_v2_ext_handle_action() {
    if (!isset($_POST['shp_v2_ext_nonce'])) {
        return;
    }
    
    $action = $_POST['shp_v2_ext_action'];
    
    switch ($action) {
        case 'save_rule':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_save_rule')) {
                break;
            }
            
            $rules_db = SHP_V2_Extended_Rules::get_instance();
            $rule_id = (int) $_POST['rule_id'];
            
            // Déterminer postcode_value selon condition
            $condition = $_POST['postcode_condition'];
            $postcode_value = null;
            $postcode_value_end = null;
            
            switch ($condition) {
                case 'starts_with':
                    $postcode_value = $_POST['postcode_value_starts'];
                    break;
                case 'between':
                    $postcode_value = $_POST['postcode_value_between_start'];
                    $postcode_value_end = $_POST['postcode_value_between_end'];
                    break;
                case 'equals':
                    $postcode_value = $_POST['postcode_value_equals'];
                    break;
            }
            
            $data = [
                'country_code' => $_POST['country_code'],
                'rule_name' => sanitize_text_field($_POST['rule_name']),
                'postcode_condition' => $condition,
                'postcode_value' => $postcode_value,
                'postcode_value_end' => $postcode_value_end,
                'price_per_pallet' => (float) $_POST['price_per_pallet'],
                'non_pallet_price' => (float) $_POST['non_pallet_price'],
                'priority' => (int) $_POST['priority'],
                'enabled' => isset($_POST['enabled']) ? 1 : 0
            ];
            
            if ($rule_id) {
                $rules_db->update_rule($rule_id, $data);
                add_settings_error('shp_v2_ext', 'rule_updated', 'Règle modifiée avec succès', 'success');
            } else {
                $rules_db->insert_rule($data);
                add_settings_error('shp_v2_ext', 'rule_created', 'Règle créée avec succès', 'success');
            }
            
            settings_errors('shp_v2_ext');
            break;
            
        case 'delete_rule':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_delete_rule')) {
                break;
            }
            
            $rules_db = SHP_V2_Extended_Rules::get_instance();
            $rule_id = (int) $_POST['rule_id'];
            
            $rules_db->delete_rule($rule_id);
            add_settings_error('shp_v2_ext', 'rule_deleted', 'Règle supprimée', 'success');
            settings_errors('shp_v2_ext');
            break;
            
        case 'import':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_import')) {
                break;
            }
            
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                add_settings_error('shp_v2_ext', 'upload_error', 'Erreur fichier', 'error');
                break;
            }
            
            $replace_all = isset($_POST['replace_all']);
            $importer = SHP_V2_Extended_Excel_Importer::get_instance();
            $result = $importer->import_from_excel($_FILES['excel_file']['tmp_name'], $replace_all);
            
            if ($result['success']) {
                add_settings_error('shp_v2_ext', 'import_success', $result['message'], 'success');
            } else {
                add_settings_error('shp_v2_ext', 'import_error', $result['message'], 'error');
            }
            
            settings_errors('shp_v2_ext');
            break;
            
        case 'export':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_export')) {
                break;
            }
            
            $importer = SHP_V2_Extended_Excel_Importer::get_instance();
            $result = $importer->export_to_excel();
            
            if ($result['success']) {
                add_settings_error('shp_v2_ext', 'export_success', 
                    'Export réussi ! <a href="' . $result['url'] . '">Télécharger</a>', 
                    'success');
            } else {
                add_settings_error('shp_v2_ext', 'export_error', $result['message'], 'error');
            }
            
            settings_errors('shp_v2_ext');
            break;
            
        case 'toggle_debug':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_config')) {
                break;
            }

            $current = get_option('shp_v2_ext_debug', 'no');
            $new = $current === 'yes' ? 'no' : 'yes';
            update_option('shp_v2_ext_debug', $new);

            add_settings_error('shp_v2_ext', 'debug_toggle',
                'Mode debug ' . ($new === 'yes' ? 'active' : 'desactive'),
                'success');

            settings_errors('shp_v2_ext');
            break;

        case 'import_raben':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_import_raben')) {
                break;
            }

            if (!isset($_FILES['raben_excel_file']) || $_FILES['raben_excel_file']['error'] !== UPLOAD_ERR_OK) {
                add_settings_error('shp_v2_ext', 'raben_upload_error', 'Erreur de fichier', 'error');
                settings_errors('shp_v2_ext');
                break;
            }

            $replace_all = isset($_POST['raben_replace_all']);
            $raben = SHP_V2_Extended_Raben_Tariffs::get_instance();
            $result = $raben->import_from_excel($_FILES['raben_excel_file']['tmp_name'], $replace_all);

            if ($result['success']) {
                add_settings_error('shp_v2_ext', 'raben_import_success', $result['message'], 'success');
            } else {
                add_settings_error('shp_v2_ext', 'raben_import_error', $result['message'], 'error');
            }

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    add_settings_error('shp_v2_ext', 'raben_import_warning', $error, 'warning');
                }
            }

            settings_errors('shp_v2_ext');
            break;

        case 'save_raben_config':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_save_raben_config')) {
                break;
            }

            $fuel = (float) ($_POST['raben_fuel_surcharge'] ?? 15.00);
            $road = (float) ($_POST['raben_road_surcharge'] ?? 8.76);

            update_option('shp_v2_ext_raben_fuel_surcharge', $fuel);
            update_option('shp_v2_ext_raben_road_surcharge', $road);

            add_settings_error('shp_v2_ext', 'raben_config_saved',
                sprintf('Surcharges enregistrees : carburant %.2f%% + routiere %.2f%% = %.2f%%', $fuel, $road, $fuel + $road),
                'success');
            settings_errors('shp_v2_ext');
            break;

        case 'save_matelas_category':
            if (!wp_verify_nonce($_POST['shp_v2_ext_nonce'], 'shp_v2_ext_save_matelas_category')) {
                break;
            }

            $cat_id = (int) ($_POST['matelas_category_id'] ?? 0);
            update_option('shp_v2_ext_matelas_category_id', $cat_id);

            if ($cat_id > 0) {
                $term = get_term($cat_id, 'product_cat');
                $cat_name = $term && !is_wp_error($term) ? $term->name : "ID $cat_id";
                add_settings_error('shp_v2_ext', 'matelas_cat_saved',
                    sprintf('Categorie matelas definie : %s', $cat_name),
                    'success');
            } else {
                add_settings_error('shp_v2_ext', 'matelas_cat_saved',
                    'Categorie matelas desactivee',
                    'success');
            }
            settings_errors('shp_v2_ext');
            break;
    }
}
