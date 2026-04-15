<?php
/**
 * Codes promo "Prix forcé au m²" pour produits dégressifs
 *
 * Fonctionnement :
 * - Nouveau type de coupon WooCommerce : "Prix au m² personnalisé (dégressif)"
 * - Le coupon force un prix au m² libre, quelle que soit la quantité commandée
 * - Sélection manuelle des produits éligibles
 * - Option : prix différents par produit sur un même coupon
 * - Affichage WooCommerce standard : ligne "Remise : -X€" dans le panier
 *
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Degressive_Coupon {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Enregistrer le type de coupon dans la liste WooCommerce
        add_filter('woocommerce_coupon_discount_types', [$this, 'register_type']);

        // Admin : champs sur la page d'édition du coupon
        add_action('woocommerce_coupon_options', [$this, 'add_fields'], 10, 2);
        add_action('woocommerce_coupon_options_save', [$this, 'save_fields'], 10, 2);

        // Frontend : autoriser le coupon pour les produits dégressifs éligibles
        add_filter('woocommerce_coupon_is_valid_for_product', [$this, 'is_valid_for_product'], 10, 4);

        // Frontend : calculer le montant de la remise (différence original - forcé)
        add_filter('woocommerce_coupon_get_discount_amount', [$this, 'get_discount_amount'], 10, 5);

        // Frontend : valider que des produits éligibles sont dans le panier
        add_filter('woocommerce_coupon_is_valid', [$this, 'validate_coupon'], 10, 2);
    }

    // =========================================================================
    // ENREGISTREMENT DU TYPE
    // =========================================================================

    /**
     * Ajoute le type "Prix au m² personnalisé (dégressif)" dans la liste des coupons WC
     */
    public function register_type($types) {
        $types['degressive_price_m2'] = 'Prix au m² personnalisé (dégressif)';
        return $types;
    }

    // =========================================================================
    // ADMINISTRATION
    // =========================================================================

    /**
     * Affiche les champs personnalisés dans la page d'édition du coupon WooCommerce
     */
    public function add_fields($coupon_id, $coupon) {
        $is_our_type    = ($coupon->get_discount_type() === 'degressive_price_m2');
        $global_price   = $coupon->get_meta('_degressive_price_per_m2');
        $product_ids    = array_filter(array_map('intval', (array) $coupon->get_meta('_degressive_product_ids')));
        $product_prices = json_decode($coupon->get_meta('_degressive_product_prices') ?: '{}', true) ?: [];
        $per_product_on = !empty($product_prices);
        ?>

        <div class="options_group shp-degressive-coupon-fields"
             style="<?php echo $is_our_type ? '' : 'display:none;'; ?> border-top: 2px solid #2271b1; padding-top: 5px;">

            <p style="padding: 12px 24px 4px; font-weight: bold; color: #2271b1; font-size: 14px;">
                ⚙️ Configuration — Prix au m² Dégressif
            </p>

            <?php
            /* --- Prix global au m² ---------------------------------------- */
            woocommerce_wp_text_input([
                'id'                => '_degressive_price_per_m2',
                'label'             => 'Prix au m² (€)',
                'type'              => 'number',
                'custom_attributes' => ['step' => '0.01', 'min' => '0.01'],
                'value'             => $global_price,
                'description'       => 'Prix appliqué à tous les produits éligibles, quelle que soit la quantité commandée.',
                'desc_tip'          => true,
            ]);
            ?>

            <?php /* --- Produits éligibles ---------------------------------- */ ?>
            <p class="form-field">
                <label for="_degressive_product_ids">Produits éligibles</label>
                <select id="_degressive_product_ids"
                        name="_degressive_product_ids[]"
                        class="wc-product-search"
                        multiple="multiple"
                        style="width: 50%;"
                        data-placeholder="Rechercher des produits dégressifs…"
                        data-action="woocommerce_json_search_products_and_variations"
                        data-allow_clear="true">
                    <?php foreach ($product_ids as $pid) :
                        $p = wc_get_product($pid);
                        if ($p) : ?>
                            <option value="<?php echo esc_attr($pid); ?>" selected="selected">
                                <?php echo esc_html($p->get_name()); ?>
                            </option>
                        <?php endif;
                    endforeach; ?>
                </select>
                <span class="description" style="display:block; margin-top:4px;">
                    Sélectionner les produits auxquels ce prix s'applique.
                    Laissez vide pour appliquer à tous les produits dégressifs du panier.
                </span>
            </p>

            <?php /* --- Prix différents par produit (optionnel) ------------- */ ?>
            <?php if (!empty($product_ids)) : ?>

            <p class="form-field" style="padding-left: 24px;">
                <label>
                    <input type="checkbox"
                           id="shp_per_product_prices"
                           name="shp_per_product_prices"
                           value="1"
                           <?php checked($per_product_on); ?>>
                    Prix différents par produit <em style="font-weight:normal;">(optionnel)</em>
                </label>
                <span class="description" style="display:block; margin-top:4px;">
                    Permet d'appliquer un prix spécifique pour certains produits.
                    Un produit sans prix spécifique utilisera le prix global.
                </span>
            </p>

            <div id="shp_product_prices_fields"
                 style="<?php echo $per_product_on ? '' : 'display:none;'; ?>
                        padding: 10px 24px 14px;
                        background: #f9f9f9;
                        border: 1px solid #ddd;
                        margin: 0 24px 10px;
                        border-radius: 4px;">
                <p style="font-size: 12px; color: #888; margin: 0 0 10px;">
                    Laisser vide pour utiliser le prix global.
                </p>
                <?php foreach ($product_ids as $pid) :
                    $p = wc_get_product($pid);
                    if (!$p) continue;
                    $specific = $product_prices[$pid] ?? '';
                    ?>
                    <p class="form-field" style="margin-bottom: 8px;">
                        <label style="width: 180px; display:inline-block;">
                            <?php echo esc_html($p->get_name()); ?>
                        </label>
                        <input type="number"
                               name="_degressive_product_price[<?php echo esc_attr($pid); ?>]"
                               value="<?php echo esc_attr($specific); ?>"
                               step="0.01" min="0"
                               placeholder="Prix global"
                               style="width: 110px;">
                        <span style="margin-left:4px;">€/m²</span>
                    </p>
                <?php endforeach; ?>
            </div>

            <?php endif; // !empty($product_ids) ?>

            <p style="padding: 4px 24px 12px; font-size: 12px; color: #666; font-style: italic;">
                ℹ️ Après avoir ajouté des produits, enregistrez le coupon pour voir apparaître les champs de prix spécifiques.
            </p>

        </div><!-- .shp-degressive-coupon-fields -->

        <script>
        jQuery(document).ready(function ($) {
            /* Afficher/masquer la section selon le type de coupon */
            function toggleDegressiveSection() {
                if ($('#discount_type').val() === 'degressive_price_m2') {
                    $('.shp-degressive-coupon-fields').show();
                } else {
                    $('.shp-degressive-coupon-fields').hide();
                }
            }
            toggleDegressiveSection();
            $('#discount_type').on('change', toggleDegressiveSection);

            /* Afficher/masquer les prix par produit */
            $('#shp_per_product_prices').on('change', function () {
                $('#shp_product_prices_fields').toggle($(this).is(':checked'));
            });
        });
        </script>

        <?php
    }

    /**
     * Sauvegarde les champs personnalisés lors de l'enregistrement du coupon
     */
    public function save_fields($coupon_id, $coupon) {
        // Prix global au m²
        $global_price = isset($_POST['_degressive_price_per_m2'])
            ? wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_degressive_price_per_m2'])))
            : '';
        update_post_meta($coupon_id, '_degressive_price_per_m2', $global_price);

        // Produits éligibles
        $product_ids = [];
        if (!empty($_POST['_degressive_product_ids'])) {
            $product_ids = array_values(array_filter(array_map('intval', (array) wp_unslash($_POST['_degressive_product_ids']))));
        }
        update_post_meta($coupon_id, '_degressive_product_ids', $product_ids);

        // Prix spécifiques par produit
        $product_prices = [];
        if (!empty($_POST['shp_per_product_prices']) && !empty($_POST['_degressive_product_price'])) {
            foreach ((array) wp_unslash($_POST['_degressive_product_price']) as $pid => $price_val) {
                $price_val = trim($price_val);
                if ($price_val !== '') {
                    $product_prices[(int) $pid] = (float) $price_val;
                }
            }
        }
        update_post_meta($coupon_id, '_degressive_product_prices', wp_json_encode($product_prices));
    }

    // =========================================================================
    // FRONTEND — APPLICATION DE LA REMISE
    // =========================================================================

    /**
     * Autorise le coupon pour les produits dégressifs éligibles.
     *
     * WooCommerce appelle is_valid_for_product() pour chaque article du panier.
     * On retourne true uniquement pour les articles dégressifs dans la liste.
     */
    public function is_valid_for_product($valid, $product, $coupon, $values) {
        if ($coupon->get_discount_type() !== 'degressive_price_m2') {
            return $valid;
        }

        $product_id = (int) $product->get_id();

        // Chercher l'article dégressif correspondant dans le panier
        if (!$this->get_cart_degressive_data($product_id)) {
            return false;
        }

        // Vérifier la restriction produit du coupon
        $product_ids = array_filter(array_map('intval', (array) $coupon->get_meta('_degressive_product_ids')));
        if (!empty($product_ids) && !in_array($product_id, $product_ids, true)) {
            return false;
        }

        return true;
    }

    /**
     * Calcule le montant de la remise = total_original - total_forcé
     *
     * WooCommerce affiche cette différence comme "Remise : -X€" dans le panier.
     * La réduction est soustraite du total, ce qui donne bien le prix forcé au final.
     */
    public function get_discount_amount($discount, $discounting_amount, $cart_item, $single, $coupon) {
        if ($coupon->get_discount_type() !== 'degressive_price_m2') {
            return $discount;
        }

        // Récupérer product_id et degressive_data selon le type passé par WC
        [$product_id, $degressive_data] = $this->resolve_cart_item($cart_item);

        if (!$degressive_data) {
            return 0;
        }

        // Vérifier la restriction produit
        $product_ids = array_filter(array_map('intval', (array) $coupon->get_meta('_degressive_product_ids')));
        if (!empty($product_ids) && !in_array($product_id, $product_ids, true)) {
            return 0;
        }

        // Déterminer le prix forcé : spécifique au produit ou global
        $product_prices = json_decode($coupon->get_meta('_degressive_product_prices') ?: '{}', true) ?: [];
        $global_price   = (float) $coupon->get_meta('_degressive_price_per_m2');
        $forced_price   = isset($product_prices[$product_id])
            ? (float) $product_prices[$product_id]
            : $global_price;

        if ($forced_price <= 0) {
            return 0;
        }

        $m2             = (float) $degressive_data['m2'];
        $original_total = (float) $degressive_data['total'];
        $forced_total   = $m2 * $forced_price;

        // La remise = différence entre le total original et le total au prix forcé
        return max(0, round($original_total - $forced_total, 2));
    }

    /**
     * Valide que le coupon est applicable :
     * au moins un produit dégressif éligible doit être dans le panier.
     */
    public function validate_coupon($valid, $coupon) {
        if (!$valid) {
            return $valid;
        }
        if ($coupon->get_discount_type() !== 'degressive_price_m2') {
            return $valid;
        }
        if (!WC()->cart) {
            return $valid;
        }

        $product_ids = array_filter(array_map('intval', (array) $coupon->get_meta('_degressive_product_ids')));

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!isset($cart_item['degressive_data'])) {
                continue;
            }
            // Pas de restriction, ou produit dans la liste
            if (empty($product_ids) || in_array((int) $cart_item['product_id'], $product_ids, true)) {
                return true;
            }
        }

        // Aucun produit éligible trouvé
        throw new Exception(
            esc_html__('Ce code promo ne s\'applique qu\'aux produits dégressifs au m² sélectionnés.', 'shp-v2-extended')
        );
    }

    // =========================================================================
    // UTILITAIRES PRIVÉS
    // =========================================================================

    /**
     * Retrouve les données dégressives d'un produit dans le panier courant.
     *
     * @param int $product_id
     * @return array|null  degressive_data ou null si non trouvé
     */
    private function get_cart_degressive_data($product_id) {
        if (!WC()->cart) {
            return null;
        }
        foreach (WC()->cart->get_cart() as $ci) {
            if ((int) $ci['product_id'] === $product_id && isset($ci['degressive_data'])) {
                return $ci['degressive_data'];
            }
        }
        return null;
    }

    /**
     * Résout le product_id et degressive_data depuis l'argument $cart_item
     * passé par WooCommerce à get_discount_amount().
     *
     * Selon la version WC et le contexte, $cart_item peut être :
     *   - un tableau (article brut du panier)
     *   - un objet WC_Product
     *
     * @param mixed $cart_item
     * @return array  [product_id (int), degressive_data (array|null)]
     */
    private function resolve_cart_item($cart_item) {
        if (is_array($cart_item)) {
            // Article brut du panier : accès direct
            $product_id      = (int) ($cart_item['product_id'] ?? 0);
            $degressive_data = $cart_item['degressive_data'] ?? null;
            return [$product_id, $degressive_data];
        }

        if (is_object($cart_item) && method_exists($cart_item, 'get_id')) {
            // Objet WC_Product : chercher dans le panier
            $product_id      = (int) $cart_item->get_id();
            $degressive_data = $this->get_cart_degressive_data($product_id);
            return [$product_id, $degressive_data];
        }

        return [0, null];
    }
}

// Initialisation après le chargement de WooCommerce
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        SHP_V2_Degressive_Coupon::get_instance();
    }
}, 15);
