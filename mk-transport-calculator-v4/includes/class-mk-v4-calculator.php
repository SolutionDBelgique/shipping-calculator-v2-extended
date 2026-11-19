<?php
/**
 * MK Transport Calculator V4 - Moteur de calcul
 *
 * Implémente le cahier des charges "Facturation du transport" :
 * - Dalles de stabilisation : prix par palette selon le nombre de palettes et le pays
 *   (grille France ou grille Belgique)
 * - Tous les autres produits : système au pourcentage (taux de la tranche de valeur,
 *   uniforme par pays), avec un minimum de MIN_AUTRES_COST
 * - Produit à tarif de transport fixe : bypass total du calcul ci-dessus pour ce
 *   produit — il est exclu des sous-totaux dalles/pourcentage et son montant fixe
 *   (× quantité) est simplement ajouté au total. Un montant spécifique France peut
 *   être saisi : il remplace le montant général pour les livraisons en France.
 * - Barrières métal (catégories configurables) : forfait unique de BARRIER_FORFAIT_DEFAULT €
 *   pour 1 à BARRIER_MAX_QTY_DEFAULT barrières au total (modèles mélangés), en Belgique et
 *   en France ; au-delà, elles repassent dans le calcul normal (système au pourcentage)
 * - Autres produits métal (catégories configurables) livrés en Belgique : forfait de
 *   METAL_FORFAIT_DEFAULT € par commande ; ailleurs, calcul normal
 * - Commande mixte : tous les montants sont additionnés et affichés en UNE seule ligne
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Calculator {

    private static $instance = null;
    private $data;

    const RATE_ID = 'mk_v4_transport';
    const DALLE_META_KEY = '_palette_complete_v2';
    const FIXED_ENABLED_META_KEY = '_mk_v4_fixed_shipping_enabled';
    const FIXED_AMOUNT_META_KEY = '_mk_v4_fixed_shipping_amount';
    // Montant fixe spécifique à un pays : _mk_v4_fixed_shipping_amount_FR
    const FIXED_AMOUNT_COUNTRY_META_PREFIX = '_mk_v4_fixed_shipping_amount_';
    const FIXED_COUNTRIES = ['FR' => 'France'];
    // Minimum de frais pour la partie "autres produits" (hors dalles et hors tarif fixe)
    const MIN_AUTRES_COST = 90.0;

    // Barrières métal : forfait pour 1 à N barrières (tous modèles confondus)
    const BARRIER_CATS_OPTION      = 'mk_v4_barrier_category_ids';
    const BARRIER_FORFAIT_OPTION   = 'mk_v4_barrier_forfait';
    const BARRIER_MAX_QTY_OPTION   = 'mk_v4_barrier_max_qty';
    const BARRIER_FORFAIT_DEFAULT  = 49.0;
    const BARRIER_MAX_QTY_DEFAULT  = 3;
    const BARRIER_COUNTRIES        = ['BE', 'FR'];

    // Autres produits métal : forfait par commande
    const METAL_CATS_OPTION        = 'mk_v4_metal_category_ids';
    const METAL_FORFAIT_OPTION     = 'mk_v4_metal_forfait';
    const METAL_FORFAIT_DEFAULT    = 79.0;
    const METAL_COUNTRIES          = ['BE'];

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->data = MK_V4_Data::get_instance();

        add_filter('woocommerce_package_rates', [$this, 'calculate'], 10, 2);
        add_action('woocommerce_cart_totals_after_shipping', [$this, 'render_debug_panel']);
        add_action('woocommerce_review_order_after_shipping', [$this, 'render_debug_panel']);
    }

    public function calculate($rates, $package) {
        $country  = strtoupper($package['destination']['country']);
        $postcode = $package['destination']['postcode'];

        $analysis = $this->analyze_cart($package['contents'], $country);

        if ($analysis['dalles_qty'] <= 0 && $analysis['autres_subtotal'] <= 0 && $analysis['fixed_cost'] <= 0
            && $analysis['barrier_qty'] <= 0 && $analysis['metal_qty'] <= 0) {
            return $rates;
        }

        $forfaits = $this->apply_forfaits(
            $country,
            $analysis['barrier_qty'],
            $analysis['barrier_subtotal'],
            $analysis['metal_qty'],
            $analysis['metal_subtotal']
        );

        $dalles_cost  = $this->calculate_dalles_cost($country, $analysis['dalles_qty']);
        $autres_subtotal = $analysis['autres_subtotal'] + $forfaits['autres_extra_subtotal'];
        $autres_calc  = $this->calculate_percentage_cost($country, $postcode, $autres_subtotal);
        $autres_cost  = $autres_calc['cost'];
        $fixed_cost   = round($analysis['fixed_cost'], 2);

        $total_cost = $dalles_cost + $autres_cost + $fixed_cost + $forfaits['barrier_cost'] + $forfaits['metal_cost'];

        WC()->session->set('mk_v4_calc', [
            'country'         => $country,
            'postcode'        => $postcode,
            'dalles_qty'      => $analysis['dalles_qty'],
            'dalles_cost'     => $dalles_cost,
            'autres_subtotal' => $autres_subtotal,
            'autres_cost'     => $autres_cost,
            'barrier_qty'     => $analysis['barrier_qty'],
            'barrier_forfait' => $forfaits['barrier_forfait'],
            'barrier_cost'    => $forfaits['barrier_cost'],
            'metal_qty'       => $analysis['metal_qty'],
            'metal_forfait'   => $forfaits['metal_forfait'],
            'metal_cost'      => $forfaits['metal_cost'],
            'autres_details'  => $autres_calc,
            'fixed_cost'      => $fixed_cost,
            'fixed_items'     => $analysis['fixed_items'],
            'total_cost'      => $total_cost,
        ]);

        $taxes = WC_Tax::calc_shipping_tax($total_cost, WC_Tax::get_shipping_tax_rates());

        // Une seule ligne "Frais de livraison" affichée au client, meme en cas de commande mixte
        $rates[self::RATE_ID] = new WC_Shipping_Rate(
            self::RATE_ID,
            'Frais de livraison',
            $total_cost,
            $taxes,
            self::RATE_ID
        );

        return $rates;
    }

    /**
     * Répartit le panier en trois sous-ensembles :
     * - quantité de dalles (palettes) → barème fixe
     * - sous-total (hors taxe) des autres produits → système au pourcentage
     * - produits à tarif de transport fixe → montant × quantité, hors des deux autres
     */
    /**
     * Montant fixe de transport par unité pour un produit et un pays de livraison.
     *
     * - Case « Forcer un tarif de transport fixe » décochée → null (calcul normal)
     * - Pays avec montant spécifique rempli (ex. France) → ce montant
     * - Sinon → montant général ; s'il est vide → null (calcul normal pour ce pays)
     *
     * @return float|null
     */
    public static function get_fixed_unit_amount($product_id, $country) {
        if (get_post_meta($product_id, self::FIXED_ENABLED_META_KEY, true) !== 'yes') {
            return null;
        }

        $country = strtoupper((string) $country);
        if (isset(self::FIXED_COUNTRIES[$country])) {
            $country_amount = get_post_meta($product_id, self::FIXED_AMOUNT_COUNTRY_META_PREFIX . $country, true);
            if ($country_amount !== '' && is_numeric($country_amount)) {
                return (float) $country_amount;
            }
        }

        $amount = get_post_meta($product_id, self::FIXED_AMOUNT_META_KEY, true);
        if ($amount !== '' && is_numeric($amount)) {
            return (float) $amount;
        }

        return null;
    }

    /**
     * IDs de catégories produit configurés dans une option (tableau d'entiers).
     */
    public static function get_category_ids($option) {
        return array_values(array_filter(array_map('absint', (array) get_option($option, []))));
    }

    /**
     * Le produit (ou son produit parent pour une variation) appartient-il à l'une des
     * catégories données, ou à une de leurs sous-catégories ?
     */
    public static function product_in_categories($product, $category_ids) {
        if (empty($category_ids) || !is_object($product)) {
            return false;
        }

        $product_id = (method_exists($product, 'get_parent_id') && $product->get_parent_id())
            ? $product->get_parent_id()
            : $product->get_id();

        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (is_wp_error($terms) || empty($terms)) {
            return false;
        }

        foreach ($terms as $term_id) {
            $lineage = array_merge([(int) $term_id], array_map('intval', get_ancestors($term_id, 'product_cat', 'taxonomy')));
            if (array_intersect($lineage, $category_ids)) {
                return true;
            }
        }

        return false;
    }

    public static function get_barrier_forfait() {
        return (float) get_option(self::BARRIER_FORFAIT_OPTION, self::BARRIER_FORFAIT_DEFAULT);
    }

    public static function get_barrier_max_qty() {
        return max(0, (int) get_option(self::BARRIER_MAX_QTY_OPTION, self::BARRIER_MAX_QTY_DEFAULT));
    }

    public static function get_metal_forfait() {
        return (float) get_option(self::METAL_FORFAIT_OPTION, self::METAL_FORFAIT_DEFAULT);
    }

    /**
     * Forfaits métal :
     * - barrières : 1 à N au total (tous modèles) en BE/FR → forfait unique ;
     *   sinon leur sous-total rejoint le calcul normal (pourcentage)
     * - autres produits métal en BE → forfait par commande ;
     *   sinon leur sous-total rejoint le calcul normal
     *
     * @return array barrier_cost, barrier_forfait (bool), metal_cost, metal_forfait (bool),
     *               autres_extra_subtotal (montant à ajouter au sous-total "autres produits")
     */
    public function apply_forfaits($country, $barrier_qty, $barrier_subtotal, $metal_qty, $metal_subtotal) {
        $country = strtoupper((string) $country);
        $result = [
            'barrier_cost'          => 0.0,
            'barrier_forfait'       => false,
            'metal_cost'            => 0.0,
            'metal_forfait'         => false,
            'autres_extra_subtotal' => 0.0,
        ];

        if ($barrier_qty > 0) {
            if (in_array($country, self::BARRIER_COUNTRIES, true) && $barrier_qty <= self::get_barrier_max_qty()) {
                $result['barrier_cost']    = round(self::get_barrier_forfait(), 2);
                $result['barrier_forfait'] = true;
            } else {
                $result['autres_extra_subtotal'] += (float) $barrier_subtotal;
            }
        }

        if ($metal_qty > 0) {
            if (in_array($country, self::METAL_COUNTRIES, true)) {
                $result['metal_cost']    = round(self::get_metal_forfait(), 2);
                $result['metal_forfait'] = true;
            } else {
                $result['autres_extra_subtotal'] += (float) $metal_subtotal;
            }
        }

        return $result;
    }

    private function analyze_cart($cart_items, $country = '') {
        $dalles_qty      = 0;
        $autres_subtotal = 0.0;
        $fixed_cost      = 0.0;
        $fixed_items     = [];
        $barrier_qty      = 0;
        $barrier_subtotal = 0.0;
        $metal_qty        = 0;
        $metal_subtotal   = 0.0;

        $barrier_cats = self::get_category_ids(self::BARRIER_CATS_OPTION);
        $metal_cats   = self::get_category_ids(self::METAL_CATS_OPTION);

        foreach ($cart_items as $item) {
            $product    = $item['data'];
            $quantity   = $item['quantity'];
            $product_id = $product->get_id();
            $line_total = isset($item['line_total']) ? (float) $item['line_total'] : (float) $product->get_price() * $quantity;

            // Bypass : un tarif de transport fixe au niveau produit court-circuite
            // le barème dalles ET le système au pourcentage pour cette ligne.
            // Le montant peut être spécifique au pays de livraison (ex. France).
            $unit_amount = self::get_fixed_unit_amount($product_id, $country);
            if ($unit_amount !== null) {
                $line_fixed  = $unit_amount * $quantity;

                $fixed_cost   += $line_fixed;
                $fixed_items[] = [
                    'name'     => $product->get_name(),
                    'unit'     => $unit_amount,
                    'quantity' => $quantity,
                    'subtotal' => $line_fixed,
                ];
                continue;
            }

            // Barrières métal (tous modèles confondus) : comptées ensemble pour le forfait
            if (self::product_in_categories($product, $barrier_cats)) {
                $barrier_qty      += $quantity;
                $barrier_subtotal += $line_total;
                continue;
            }

            // Autres produits métal : forfait par commande selon le pays
            if (self::product_in_categories($product, $metal_cats)) {
                $metal_qty      += $quantity;
                $metal_subtotal += $line_total;
                continue;
            }

            $is_dalle = get_post_meta($product_id, self::DALLE_META_KEY, true) === 'yes';

            if ($is_dalle) {
                $dalles_qty += $quantity;
            } else {
                $autres_subtotal += $line_total;
            }
        }

        return [
            'dalles_qty'      => $dalles_qty,
            'autres_subtotal' => $autres_subtotal,
            'fixed_cost'      => $fixed_cost,
            'fixed_items'     => $fixed_items,
            'barrier_qty'      => $barrier_qty,
            'barrier_subtotal' => $barrier_subtotal,
            'metal_qty'        => $metal_qty,
            'metal_subtotal'   => $metal_subtotal,
        ];
    }

    /**
     * Dalles : prix par palette selon le nombre total de palettes (grille du pays).
     * Ex. France 3 palettes = 3 × 115 € = 345 €.
     */
    private function calculate_dalles_cost($country, $nb_palettes) {
        if ($nb_palettes <= 0) {
            return 0.0;
        }

        $bareme = $this->data->get_bareme_dalles($country);
        $key = $nb_palettes >= 5 ? '5_plus' : (string) $nb_palettes;

        return round($nb_palettes * (float) ($bareme[$key] ?? 0), 2);
    }

    /**
     * Système au pourcentage pour tous les produits hors dalles :
     * taux de la tranche de valeur (grille Belgique pour BE, grille France sinon),
     * avec un minimum de MIN_AUTRES_COST.
     */
    private function calculate_percentage_cost($country, $postcode, $subtotal) {
        $tranches = ($country === 'BE') ? $this->data->get_tranches_belgique() : $this->data->get_tranches_france();
        $tranche  = $subtotal > 0 ? $this->find_tranche($tranches, $subtotal) : null;

        if (!$tranche) {
            return [
                'cost'      => 0.0,
                'country'   => $country,
                'pct_final' => 0,
                'tranche'   => null,
                'minimum'   => false,
            ];
        }

        $cost    = round($subtotal * $tranche['pct'], 2);
        $minimum = $cost < self::MIN_AUTRES_COST;

        return [
            'cost'      => $minimum ? self::MIN_AUTRES_COST : $cost,
            'country'   => $country,
            'pct_final' => $tranche['pct'],
            'tranche'   => $tranche,
            'minimum'   => $minimum,
        ];
    }

    private function find_tranche($tranches, $value) {
        foreach ($tranches as $tranche) {
            $min_ok = $value >= $tranche['min'];
            $max_ok = ($tranche['max'] === null) || ($value < $tranche['max']);
            if ($min_ok && $max_ok) {
                return $tranche;
            }
        }
        return null;
    }

    public function render_debug_panel() {
        $calc = WC()->session ? WC()->session->get('mk_v4_calc') : null;

        if (!$calc || get_option('mk_v4_debug', 'yes') !== 'yes') {
            return;
        }

        $details = $calc['autres_details'];
        ?>
        <tr>
            <th colspan="2">
                <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1;">
                    <h4 style="color: #2271b1; margin-top:0;">🔍 Debug MK Transport V4</h4>
                    <table style="width: 100%; font-size: 13px;">
                        <tr>
                            <td style="font-weight: 600; width: 45%;">Pays :</td>
                            <td><?php echo esc_html($calc['country']); ?> (<?php echo esc_html($calc['postcode']); ?>)</td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600;">Dalles (palettes) :</td>
                            <td><?php echo esc_html($calc['dalles_qty']); ?> → <?php echo wc_price($calc['dalles_cost']); ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight: 600;">Sous-total autres produits :</td>
                            <td><?php echo wc_price($calc['autres_subtotal']); ?></td>
                        </tr>
                        <?php if (!empty($details['tranche'])): ?>
                        <tr>
                            <td style="font-weight: 600;">% appliqué :</td>
                            <td>
                                <?php echo esc_html(round($details['pct_final'] * 100, 2)); ?>%
                                <?php echo !empty($details['minimum']) ? ' (minimum ' . wc_price(self::MIN_AUTRES_COST) . ' appliqué)' : ''; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="font-weight: 600;">Frais autres produits :</td>
                            <td><?php echo wc_price($calc['autres_cost']); ?></td>
                        </tr>
                        <?php if (!empty($calc['barrier_qty'])): ?>
                        <tr>
                            <td style="font-weight: 600;">Barrières métal :</td>
                            <td>
                                <?php echo esc_html($calc['barrier_qty']); ?> →
                                <?php echo !empty($calc['barrier_forfait'])
                                    ? 'forfait ' . wc_price($calc['barrier_cost'])
                                    : 'calcul normal (inclus dans les autres produits)'; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($calc['metal_qty'])): ?>
                        <tr>
                            <td style="font-weight: 600;">Autres produits métal :</td>
                            <td>
                                <?php echo esc_html($calc['metal_qty']); ?> →
                                <?php echo !empty($calc['metal_forfait'])
                                    ? 'forfait ' . wc_price($calc['metal_cost'])
                                    : 'calcul normal (inclus dans les autres produits)'; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($calc['fixed_cost'])): ?>
                        <tr>
                            <td style="font-weight: 600;">Produits à tarif fixe (bypass) :</td>
                            <td>
                                <?php foreach (($calc['fixed_items'] ?? []) as $fi): ?>
                                    <?php echo esc_html($fi['name']); ?> : <?php echo wc_price($fi['unit']); ?> × <?php echo esc_html($fi['quantity']); ?> = <?php echo wc_price($fi['subtotal']); ?><br>
                                <?php endforeach; ?>
                                <strong><?php echo wc_price($calc['fixed_cost']); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>
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
