<?php
/**
 * Plugin Name: MK Transport Calculator V4
 * Version: 4.1.0
 * Description: Calcul des frais de transport — barème fixe pour les dalles, système au pourcentage (tranche x zone) pour tous les autres produits, tarif de transport fixe optionnel par produit (bypass). Remplace Shipping Calculator V3 Extended.
 * Author: SolutionD Belgique
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MK_V4_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MK_V4_VERSION', '4.1.0');

require_once MK_V4_PLUGIN_DIR . 'includes/class-mk-v4-data.php';
require_once MK_V4_PLUGIN_DIR . 'includes/class-mk-v4-calculator.php';
require_once MK_V4_PLUGIN_DIR . 'includes/class-mk-v4-admin.php';
require_once MK_V4_PLUGIN_DIR . 'includes/class-mk-v4-degressive-pricing.php';
require_once MK_V4_PLUGIN_DIR . 'includes/class-mk-v4-product-fields.php';

// Vérifier WooCommerce
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Ce plugin nécessite WooCommerce. <a href="' . admin_url('plugins.php') . '">Retour</a>');
    }
});

/**
 * ===========================================
 * ENREGISTREMENT MÉTHODE WOOCOMMERCE
 * ===========================================
 */
add_action('woocommerce_shipping_init', function () {

    if (class_exists('MK_V4_Shipping_Method')) {
        return;
    }

    class MK_V4_Shipping_Method extends WC_Shipping_Method {

        public function __construct($instance_id = 0) {
            $this->id = 'mk_v4_transport';
            $this->instance_id = absint($instance_id);
            $this->method_title = 'MK Transport Calculator V4';
            $this->method_description = 'Calcul automatique : barème fixe dalles + système au pourcentage pour les autres produits.';
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
                    'default' => 'Frais de livraison',
                    'desc_tip' => true,
                ],
                'enabled' => [
                    'title' => 'Activer/Désactiver',
                    'type' => 'checkbox',
                    'label' => 'Activer cette méthode',
                    'default' => 'yes',
                ],
            ];
        }

        public function calculate_shipping($package = []) {
            // Géré par MK_V4_Calculator via woocommerce_package_rates
        }
    }
});

add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['mk_v4_transport'] = 'MK_V4_Shipping_Method';
    return $methods;
});

/**
 * ===========================================
 * INITIALISATION
 * ===========================================
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    MK_V4_Data::get_instance();
    MK_V4_Calculator::get_instance();
    MK_V4_Admin::get_instance();
    MK_V4_Degressive_Pricing::get_instance();
    MK_V4_Product_Fields::get_instance();
});

/**
 * ===========================================
 * ACTIVATION
 * ===========================================
 */
register_activation_hook(__FILE__, function () {
    add_option('mk_v4_debug', 'yes');
    set_transient('mk_v4_activated', true, 60);
});

add_action('admin_notices', function () {
    if (get_transient('mk_v4_activated')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <h3>✅ MK Transport Calculator V4 activé !</h3>
            <p>Pensez à créer/activer une méthode de livraison "MK Transport Calculator V4" dans vos zones d'expédition WooCommerce.</p>
            <p><a href="<?php echo admin_url('admin.php?page=mk-transport-v4'); ?>" class="button button-primary">Configurer →</a></p>
        </div>
        <?php
        delete_transient('mk_v4_activated');
    }
});
