<?php
/**
 * MK Transport Calculator V4 - Moteur de calcul
 *
 * Implémente le cahier des charges "Facturation du transport" :
 * - Dalles de stabilisation : barème fixe par nombre de palettes (inchangé, non modifiable)
 * - Tous les autres produits : système au pourcentage (tranche valeur x coefficient zone),
 *   avec plafonnement au %max de la tranche
 * - Produit à tarif de transport fixe : bypass total du calcul ci-dessus pour ce
 *   produit — il est exclu des sous-totaux dalles/pourcentage et son montant fixe
 *   (× quantité) est simplement ajouté au total
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

        $analysis = $this->analyze_cart($package['contents']);

        if ($analysis['dalles_qty'] <= 0 && $analysis['autres_subtotal'] <= 0 && $analysis['fixed_cost'] <= 0) {
            return $rates;
        }

        $dalles_cost  = $this->calculate_dalles_cost($analysis['dalles_qty']);
        $autres_calc  = $this->calculate_percentage_cost($country, $postcode, $analysis['autres_subtotal']);
        $autres_cost  = $autres_calc['cost'];
        $fixed_cost   = round($analysis['fixed_cost'], 2);

        $total_cost = $dalles_cost + $autres_cost + $fixed_cost;

        WC()->session->set('mk_v4_calc', [
            'country'         => $country,
            'postcode'        => $postcode,
            'dalles_qty'      => $analysis['dalles_qty'],
            'dalles_cost'     => $dalles_cost,
            'autres_subtotal' => $analysis['autres_subtotal'],
            'autres_cost'     => $autres_cost,
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
    private function analyze_cart($cart_items) {
        $dalles_qty      = 0;
        $autres_subtotal = 0.0;
        $fixed_cost      = 0.0;
        $fixed_items     = [];

        foreach ($cart_items as $item) {
            $product    = $item['data'];
            $quantity   = $item['quantity'];
            $product_id = $product->get_id();
            $line_total = isset($item['line_total']) ? (float) $item['line_total'] : (float) $product->get_price() * $quantity;

            // Bypass : un tarif de transport fixe au niveau produit court-circuite
            // le barème dalles ET le système au pourcentage pour cette ligne.
            if (get_post_meta($product_id, self::FIXED_ENABLED_META_KEY, true) === 'yes') {
                $unit_amount = (float) get_post_meta($product_id, self::FIXED_AMOUNT_META_KEY, true);
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
        ];
    }

    /**
     * Barème fixe dalles (cahier des charges §2) : totaux forfaitaires, pas un prix/palette.
     * Ne jamais remonter le tarif symbolique 5+ automatiquement.
     */
    private function calculate_dalles_cost($nb_palettes) {
        if ($nb_palettes <= 0) {
            return 0.0;
        }

        $bareme = $this->data->get_bareme_dalles();

        if ($nb_palettes >= 5) {
            return (float) $bareme['5_plus'];
        }

        return (float) $bareme[$nb_palettes];
    }

    /**
     * Système au pourcentage pour tous les produits hors dalles (cahier des charges §3).
     */
    private function calculate_percentage_cost($country, $postcode, $subtotal) {
        if ($subtotal <= 0) {
            return [
                'cost' => 0.0,
                'country' => $country,
                'zone' => null,
                'pct_final' => 0,
                'tranche' => null,
                'coefficient' => null,
                'plafonne' => false,
            ];
        }

        if ($country === 'BE') {
            return $this->calculate_percentage_belgique($subtotal);
        }

        // France + tout autre pays non couvert par le cahier des charges :
        // on applique la grille France avec la zone la plus prudente (D) par défaut.
        return $this->calculate_percentage_france($country, $postcode, $subtotal);
    }

    private function calculate_percentage_france($country, $postcode, $subtotal) {
        $zone = $this->get_zone($country, $postcode);

        $tranche = $this->find_tranche($this->data->get_tranches_france(), $subtotal);

        if (!$tranche) {
            return [
                'cost' => 0.0,
                'country' => $country,
                'zone' => $zone,
                'pct_final' => 0,
                'tranche' => null,
                'coefficient' => null,
                'plafonne' => false,
            ];
        }

        $coefficients = $this->data->get_coefficients_geo();
        $coefficient  = $coefficients[$zone] ?? $coefficients['D'];

        $pct_brut  = $tranche['pct_min'] * $coefficient;
        $pct_final = min($pct_brut, $tranche['pct_max']);
        $plafonne  = $pct_brut > $tranche['pct_max'];

        return [
            'cost'        => round($subtotal * $pct_final, 2),
            'country'     => $country,
            'zone'        => $zone,
            'pct_final'   => $pct_final,
            'tranche'     => $tranche,
            'coefficient' => $coefficient,
            'plafonne'    => $plafonne,
        ];
    }

    private function calculate_percentage_belgique($subtotal) {
        $tranche = $this->find_tranche($this->data->get_tranches_belgique(), $subtotal);

        if (!$tranche) {
            return [
                'cost' => 0.0,
                'country' => 'BE',
                'zone' => null,
                'pct_final' => 0,
                'tranche' => null,
                'coefficient' => null,
                'plafonne' => false,
            ];
        }

        $pct_final = $tranche['pct_min'];

        return [
            'cost'        => round($subtotal * $pct_final, 2),
            'country'     => 'BE',
            'zone'        => null,
            'pct_final'   => $pct_final,
            'tranche'     => $tranche,
            'coefficient' => null,
            'plafonne'    => false,
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

    /**
     * Détermine la zone géographique (A-D) pour un pays/code postal donné.
     * - Pays != FR (y compris BE, qui n'utilise pas de zone) : zone D par défaut (le plus sûr financièrement).
     * - FR avec département hors table (Corse 2A/2B, DOM-TOM 97/98) : zone D par défaut.
     */
    public function get_zone($country, $postcode) {
        if ($country !== 'FR') {
            return 'D';
        }

        $postcode_clean = preg_replace('/[^0-9]/', '', (string) $postcode);

        if (strlen($postcode_clean) < 2) {
            return 'D';
        }

        $dept_key = substr($postcode_clean, 0, 2);

        $zone = $this->data->get_zone_departement($dept_key);

        return $zone ?: 'D';
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
                        <?php if (!empty($details['zone'])): ?>
                        <tr>
                            <td style="font-weight: 600;">Zone géo :</td>
                            <td><?php echo esc_html($details['zone']); ?> (coefficient ×<?php echo esc_html($details['coefficient']); ?>)</td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($details['tranche'])): ?>
                        <tr>
                            <td style="font-weight: 600;">% appliqué :</td>
                            <td>
                                <?php echo esc_html(round($details['pct_final'] * 100, 2)); ?>%
                                <?php echo !empty($details['plafonne']) ? ' (plafonné)' : ''; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="font-weight: 600;">Frais autres produits :</td>
                            <td><?php echo wc_price($calc['autres_cost']); ?></td>
                        </tr>
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
