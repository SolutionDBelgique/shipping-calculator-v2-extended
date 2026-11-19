<?php
/**
 * MK Transport Calculator V4 - Page admin (test + référence)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Admin {

    private static $instance = null;
    private $data;
    private $calculator;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->data = MK_V4_Data::get_instance();
        $this->calculator = MK_V4_Calculator::get_instance();

        add_action('admin_menu', [$this, 'add_menu'], 99);
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'MK Transport V4',
            '🚚 MK Transport V4',
            'manage_woocommerce',
            'mk-transport-v4',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'test';

        if (isset($_POST['mk_v4_save_settings']) && check_admin_referer('mk_v4_settings')) {
            update_option('mk_v4_debug', isset($_POST['mk_v4_debug']) ? 'yes' : 'no');

            $barrier_cats = array_values(array_filter(array_map('absint', (array) ($_POST['mk_v4_barrier_cats'] ?? []))));
            $metal_cats   = array_values(array_filter(array_map('absint', (array) ($_POST['mk_v4_metal_cats'] ?? []))));
            update_option(MK_V4_Calculator::BARRIER_CATS_OPTION, $barrier_cats);
            update_option(MK_V4_Calculator::METAL_CATS_OPTION, $metal_cats);

            if (isset($_POST['mk_v4_barrier_forfait']) && is_numeric(str_replace(',', '.', $_POST['mk_v4_barrier_forfait']))) {
                update_option(MK_V4_Calculator::BARRIER_FORFAIT_OPTION, (float) str_replace(',', '.', $_POST['mk_v4_barrier_forfait']));
            }
            if (isset($_POST['mk_v4_barrier_max_qty'])) {
                update_option(MK_V4_Calculator::BARRIER_MAX_QTY_OPTION, max(0, (int) $_POST['mk_v4_barrier_max_qty']));
            }
            if (isset($_POST['mk_v4_metal_forfait']) && is_numeric(str_replace(',', '.', $_POST['mk_v4_metal_forfait']))) {
                update_option(MK_V4_Calculator::METAL_FORFAIT_OPTION, (float) str_replace(',', '.', $_POST['mk_v4_metal_forfait']));
            }
            echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>🚚 MK Transport Calculator V4 — v<?php echo esc_html(MK_V4_VERSION); ?></h1>
            <p style="color:#666;">Basé sur le cahier des charges "Facturation du transport" — MK Horse Solutions.</p>

            <h2 class="nav-tab-wrapper">
                <a href="?page=mk-transport-v4&tab=test" class="nav-tab <?php echo $tab === 'test' ? 'nav-tab-active' : ''; ?>">🧪 Tester</a>
                <a href="?page=mk-transport-v4&tab=bareme" class="nav-tab <?php echo $tab === 'bareme' ? 'nav-tab-active' : ''; ?>">📋 Barème / Règles</a>
                <a href="?page=mk-transport-v4&tab=config" class="nav-tab <?php echo $tab === 'config' ? 'nav-tab-active' : ''; ?>">⚙️ Configuration</a>
            </h2>

            <?php
            switch ($tab) {
                case 'bareme':
                    $this->tab_bareme();
                    break;
                case 'config':
                    $this->tab_config();
                    break;
                default:
                    $this->tab_test();
            }
            ?>
        </div>
        <?php
    }

    private function tab_test() {
        $country  = isset($_POST['mk_test_country']) ? sanitize_text_field($_POST['mk_test_country']) : 'FR';
        $postcode = isset($_POST['mk_test_postcode']) ? sanitize_text_field($_POST['mk_test_postcode']) : '';
        $dalles   = isset($_POST['mk_test_dalles']) ? (int) $_POST['mk_test_dalles'] : 0;
        $autres   = isset($_POST['mk_test_autres']) ? (float) str_replace(',', '.', $_POST['mk_test_autres']) : 0;
        $fixe     = isset($_POST['mk_test_fixe']) ? (float) str_replace(',', '.', $_POST['mk_test_fixe']) : 0;
        $barrier_qty      = isset($_POST['mk_test_barrier_qty']) ? max(0, (int) $_POST['mk_test_barrier_qty']) : 0;
        $barrier_subtotal = isset($_POST['mk_test_barrier_subtotal']) ? (float) str_replace(',', '.', $_POST['mk_test_barrier_subtotal']) : 0;
        $metal_subtotal   = isset($_POST['mk_test_metal_subtotal']) ? (float) str_replace(',', '.', $_POST['mk_test_metal_subtotal']) : 0;

        $result = null;

        if (isset($_POST['mk_v4_test_submit'])) {
            $reflect = new ReflectionClass($this->calculator);

            $dalles_method = $reflect->getMethod('calculate_dalles_cost');
            $dalles_method->setAccessible(true);
            $dalles_cost = $dalles_method->invoke($this->calculator, strtoupper($country), $dalles);

            $forfaits = $this->calculator->apply_forfaits(
                strtoupper($country),
                $barrier_qty,
                $barrier_subtotal,
                $metal_subtotal > 0 ? 1 : 0,
                $metal_subtotal
            );

            $pct_method = $reflect->getMethod('calculate_percentage_cost');
            $pct_method->setAccessible(true);
            $autres_calc = $pct_method->invoke($this->calculator, strtoupper($country), $postcode, $autres + $forfaits['autres_extra_subtotal']);

            $result = [
                'dalles_cost' => $dalles_cost,
                'autres_calc' => $autres_calc,
                'fixe' => $fixe,
                'forfaits' => $forfaits,
                'total' => $dalles_cost + $autres_calc['cost'] + $fixe + $forfaits['barrier_cost'] + $forfaits['metal_cost'],
            ];
        }
        ?>
        <h2>🧪 Simuler un calcul</h2>
        <p>Ex. : France, 1390€ d'autres produits → 14% = 194,60€ ; France, 3 palettes → 3 × 115€ = 345€.</p>

        <form method="post" style="max-width:500px;">
            <table class="form-table">
                <tr>
                    <th><label>Pays</label></th>
                    <td>
                        <select name="mk_test_country">
                            <option value="FR" <?php selected($country, 'FR'); ?>>France</option>
                            <option value="BE" <?php selected($country, 'BE'); ?>>Belgique</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Code postal</label></th>
                    <td><input type="text" name="mk_test_postcode" value="<?php echo esc_attr($postcode); ?>" placeholder="Ex: 24000" /></td>
                </tr>
                <tr>
                    <th><label>Nombre de palettes (dalles)</label></th>
                    <td><input type="number" name="mk_test_dalles" value="<?php echo esc_attr($dalles); ?>" min="0" /></td>
                </tr>
                <tr>
                    <th><label>Sous-total autres produits (€)</label></th>
                    <td><input type="text" name="mk_test_autres" value="<?php echo esc_attr($autres); ?>" placeholder="Ex: 1390" /></td>
                </tr>
                <tr>
                    <th><label>Nombre de barrières métal</label></th>
                    <td>
                        <input type="number" name="mk_test_barrier_qty" value="<?php echo esc_attr($barrier_qty); ?>" min="0" />
                        <p class="description">Total tous modèles confondus.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Sous-total barrières (€)</label></th>
                    <td>
                        <input type="text" name="mk_test_barrier_subtotal" value="<?php echo esc_attr($barrier_subtotal); ?>" placeholder="Ex: 640" />
                        <p class="description">Utilisé seulement si le forfait ne s'applique pas (calcul normal).</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Sous-total autres produits métal (€)</label></th>
                    <td><input type="text" name="mk_test_metal_subtotal" value="<?php echo esc_attr($metal_subtotal); ?>" placeholder="Ex: 300" /></td>
                </tr>
                <tr>
                    <th><label>Frais fixes produits (bypass) (€)</label></th>
                    <td>
                        <input type="text" name="mk_test_fixe" value="<?php echo esc_attr($fixe); ?>" placeholder="Ex: 135" />
                        <p class="description">Somme des tarifs de transport fixes (montant × quantité) des produits concernés. Ajouté tel quel au total.</p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="mk_v4_test_submit" class="button button-primary">Calculer</button></p>
        </form>

        <?php if ($result): ?>
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; max-width:600px;">
                <h3>Résultat</h3>
                <table class="widefat striped">
                    <tr><td>Coût dalles</td><td><strong><?php echo wc_price($result['dalles_cost']); ?></strong></td></tr>
                    <?php if (!empty($result['autres_calc']['tranche'])): ?>
                    <tr><td>% appliqué</td><td>
                        <?php echo esc_html(round($result['autres_calc']['pct_final'] * 100, 2)); ?>%
                        <?php echo !empty($result['autres_calc']['minimum']) ? ' <span style="color:#d63638;">(minimum ' . wc_price(MK_V4_Calculator::MIN_AUTRES_COST) . ' appliqué)</span>' : ''; ?>
                    </td></tr>
                    <?php endif; ?>
                    <tr><td>Coût autres produits</td><td><strong><?php echo wc_price($result['autres_calc']['cost']); ?></strong></td></tr>
                    <?php if ($barrier_qty > 0): ?>
                    <tr><td>Barrières métal (<?php echo esc_html($barrier_qty); ?>)</td><td><strong>
                        <?php echo $result['forfaits']['barrier_forfait'] ? 'Forfait ' . wc_price($result['forfaits']['barrier_cost']) : 'Calcul normal (inclus dans les autres produits)'; ?>
                    </strong></td></tr>
                    <?php endif; ?>
                    <?php if ($metal_subtotal > 0): ?>
                    <tr><td>Autres produits métal</td><td><strong>
                        <?php echo $result['forfaits']['metal_forfait'] ? 'Forfait ' . wc_price($result['forfaits']['metal_cost']) : 'Calcul normal (inclus dans les autres produits)'; ?>
                    </strong></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($result['fixe'])): ?>
                    <tr><td>Frais fixes produits (bypass)</td><td><strong><?php echo wc_price($result['fixe']); ?></strong></td></tr>
                    <?php endif; ?>
                    <tr style="border-top:2px solid #2271b1;">
                        <td style="font-size:16px;">TOTAL "Frais de livraison"</td>
                        <td style="font-size:16px; color:#2271b1;"><strong><?php echo wc_price($result['total']); ?></strong></td>
                    </tr>
                </table>
            </div>
        <?php endif; ?>
        <?php
    }

    private function tab_bareme() {
        ?>
        <h2>📋 Barème dalles (prix par palette)</h2>
        <table class="widefat striped" style="max-width:600px;">
            <thead><tr><th>Palettes</th><th>🇫🇷 France (et autres pays)</th><th>🇧🇪 Belgique</th></tr></thead>
            <tbody>
                <?php
                $bareme_fr = $this->data->get_bareme_dalles('FR');
                $bareme_be = $this->data->get_bareme_dalles('BE');
                foreach (['1', '2', '3', '4', '5_plus'] as $key):
                ?>
                <tr>
                    <td><?php echo $key === '5_plus' ? '5 palettes et +' : esc_html($key) . ' palette' . ($key === '1' ? '' : 's'); ?></td>
                    <td><?php echo esc_html($bareme_fr[$key] ?? '-'); ?> € / palette</td>
                    <td><?php echo esc_html($bareme_be[$key] ?? '-'); ?> € / palette</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><code>coût dalles = nombre de palettes × prix par palette</code> (ex. France 3 palettes = 3 × 115 € = 345 €)</p>

        <h2 style="margin-top:30px;">📦 Autres produits — % de la valeur commande</h2>
        <table class="widefat striped" style="max-width:600px;">
            <thead><tr><th>Valeur commande</th><th>🇫🇷 France (et autres pays)</th><th>🇧🇪 Belgique</th></tr></thead>
            <tbody>
                <?php
                $tranches_be = $this->data->get_tranches_belgique();
                foreach ($this->data->get_tranches_france() as $i => $t):
                ?>
                <tr>
                    <td><?php echo esc_html($t['min']); ?>€ - <?php echo $t['max'] === null ? '∞' : esc_html($t['max']) . '€'; ?></td>
                    <td><?php echo esc_html($t['pct'] * 100); ?>%</td>
                    <td><?php echo isset($tranches_be[$i]) ? esc_html($tranches_be[$i]['pct'] * 100) . '%' : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>Taux unique par pays (pas de coefficient de zone). <strong>Minimum : <?php echo wc_price(MK_V4_Calculator::MIN_AUTRES_COST); ?></strong> de frais sur la partie « autres produits » dès qu'il y en a dans le panier.</p>

        <p style="margin-top:20px; color:#666; font-style:italic;">
            Commande mixte : coût dalles + coût autres produits calculés séparément sur leurs sous-totaux respectifs, puis additionnés en une seule ligne "Frais de livraison".
        </p>

        <h2 style="margin-top:30px;">🔩 Produits métal</h2>
        <ul style="list-style:disc; margin-left:20px;">
            <li><strong>Barrières métal</strong> (Texas, Eco, avec porte, extensibles…) livrées en 🇧🇪 Belgique ou 🇫🇷 France :
                de 1 à <?php echo esc_html(MK_V4_Calculator::get_barrier_max_qty()); ?> barrières au total, tous modèles mélangés →
                <strong>forfait unique de <?php echo wc_price(MK_V4_Calculator::get_barrier_forfait()); ?></strong>.
                Au-delà, ou vers un autre pays : calcul normal (système au pourcentage avec les autres produits).</li>
            <li><strong>Autres produits métal</strong> livrés en 🇧🇪 Belgique : <strong>forfait de <?php echo wc_price(MK_V4_Calculator::get_metal_forfait()); ?> par commande</strong>.
                Ailleurs : calcul normal.</li>
            <li>Catégories concernées : onglet « Configuration ». Un produit à tarif fixe (bypass) garde son tarif fixe.</li>
            <li>Panier mixte : les forfaits s'additionnent aux autres frais (dalles, autres produits, tarifs fixes).</li>
        </ul>

        <h2 style="margin-top:30px;">🔒 Tarif de transport fixe par produit (bypass)</h2>
        <p>
            Activable produit par produit dans l'onglet <strong>Expédition</strong> de la fiche produit
            (case « Forcer un tarif de transport fixe » + montant par unité).
        </p>
        <ul style="list-style:disc; margin-left:20px;">
            <li>Le produit est <strong>totalement exclu</strong> du barème dalles et du système au pourcentage.</li>
            <li>Sa contribution aux frais de livraison = <code>montant fixe × quantité</code>.</li>
            <li>Les autres produits du panier restent calculés normalement ; les montants sont ensuite additionnés dans la ligne unique "Frais de livraison".</li>
            <li>Si la case « Palette complète » est aussi cochée sur ce produit, le tarif fixe l'emporte (le produit ne compte pas comme dalle).</li>
        </ul>
        <?php
    }

    private function tab_config() {
        $debug = get_option('mk_v4_debug', 'yes');
        $barrier_cats = MK_V4_Calculator::get_category_ids(MK_V4_Calculator::BARRIER_CATS_OPTION);
        $metal_cats   = MK_V4_Calculator::get_category_ids(MK_V4_Calculator::METAL_CATS_OPTION);
        $categories   = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        $categories   = is_wp_error($categories) ? [] : $categories;
        ?>
        <h2>⚙️ Configuration</h2>
        <form method="post">
            <?php wp_nonce_field('mk_v4_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Panneau debug (cart/checkout)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mk_v4_debug" <?php checked($debug, 'yes'); ?> />
                            Afficher le détail du calcul dans le panier et la commande (utile pour vérifier un tarif)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Catégories « barrières métal »</th>
                    <td>
                        <?php $this->render_category_select('mk_v4_barrier_cats', $categories, $barrier_cats); ?>
                        <p class="description">Texas, Eco, modèles avec porte, extensibles… Les sous-catégories sont incluses. Ctrl/Cmd + clic pour en choisir plusieurs.</p>
                    </td>
                </tr>
                <tr>
                    <th>Forfait barrières</th>
                    <td>
                        <input type="text" name="mk_v4_barrier_forfait" value="<?php echo esc_attr(MK_V4_Calculator::get_barrier_forfait()); ?>" style="width:80px;" /> €
                        pour 1 à
                        <input type="number" name="mk_v4_barrier_max_qty" value="<?php echo esc_attr(MK_V4_Calculator::get_barrier_max_qty()); ?>" min="0" step="1" style="width:60px;" />
                        barrières au total (Belgique et France). Au-delà : calcul normal.
                    </td>
                </tr>
                <tr>
                    <th>Catégories « autres produits métal »</th>
                    <td>
                        <?php $this->render_category_select('mk_v4_metal_cats', $categories, $metal_cats); ?>
                        <p class="description">Un produit présent dans les deux listes est traité comme une barrière.</p>
                    </td>
                </tr>
                <tr>
                    <th>Forfait autres produits métal</th>
                    <td>
                        <input type="text" name="mk_v4_metal_forfait" value="<?php echo esc_attr(MK_V4_Calculator::get_metal_forfait()); ?>" style="width:80px;" /> €
                        par commande, livraison en Belgique. Ailleurs : calcul normal.
                    </td>
                </tr>
                <tr>
                    <th>Détection "dalle"</th>
                    <td>
                        Champ produit réutilisé : <code>_palette_complete_v2</code> (case "Palette complète" de l'onglet Expédition du produit).
                    </td>
                </tr>
                <tr>
                    <th>Tarif de transport fixe (bypass)</th>
                    <td>
                        Champs produit : <code>_mk_v4_fixed_shipping_enabled</code> (case "Forcer un tarif de transport fixe") +
                        <code>_mk_v4_fixed_shipping_amount</code> (montant par unité), onglet Expédition du produit.
                        Montant spécifique France : <code>_mk_v4_fixed_shipping_amount_FR</code> (prioritaire pour les livraisons en France).
                        Voir l'onglet « Barème / Règles » pour la logique.
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="mk_v4_save_settings" class="button button-primary">Enregistrer</button></p>
        </form>
        <?php
    }

    /**
     * Liste à choix multiple des catégories produit, indentée selon la hiérarchie.
     */
    private function render_category_select($name, $categories, $selected) {
        $by_parent = [];
        foreach ($categories as $cat) {
            $by_parent[(int) $cat->parent][] = $cat;
        }

        echo '<select name="' . esc_attr($name) . '[]" multiple size="8" style="min-width:320px;">';
        $this->render_category_options($by_parent, 0, 0, $selected);
        echo '</select>';
    }

    private function render_category_options($by_parent, $parent_id, $depth, $selected) {
        foreach ($by_parent[$parent_id] ?? [] as $cat) {
            printf(
                '<option value="%d" %s>%s%s (%d)</option>',
                (int) $cat->term_id,
                in_array((int) $cat->term_id, $selected, true) ? 'selected' : '',
                str_repeat('— ', $depth),
                esc_html($cat->name),
                (int) $cat->count
            );
            $this->render_category_options($by_parent, (int) $cat->term_id, $depth + 1, $selected);
        }
    }
}
