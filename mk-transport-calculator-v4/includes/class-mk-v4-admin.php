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
            echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>🚚 MK Transport Calculator V4 — v<?php echo esc_html(MK_V4_VERSION); ?></h1>
            <p style="color:#666;">Basé sur le cahier des charges "Facturation du transport" — MK Horse Solutions.</p>

            <h2 class="nav-tab-wrapper">
                <a href="?page=mk-transport-v4&tab=test" class="nav-tab <?php echo $tab === 'test' ? 'nav-tab-active' : ''; ?>">🧪 Tester</a>
                <a href="?page=mk-transport-v4&tab=bareme" class="nav-tab <?php echo $tab === 'bareme' ? 'nav-tab-active' : ''; ?>">📋 Barème / Règles</a>
                <a href="?page=mk-transport-v4&tab=zones" class="nav-tab <?php echo $tab === 'zones' ? 'nav-tab-active' : ''; ?>">🌍 Zones départements</a>
                <a href="?page=mk-transport-v4&tab=config" class="nav-tab <?php echo $tab === 'config' ? 'nav-tab-active' : ''; ?>">⚙️ Configuration</a>
            </h2>

            <?php
            switch ($tab) {
                case 'bareme':
                    $this->tab_bareme();
                    break;
                case 'zones':
                    $this->tab_zones();
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

        $result = null;

        if (isset($_POST['mk_v4_test_submit'])) {
            $reflect = new ReflectionClass($this->calculator);

            $dalles_method = $reflect->getMethod('calculate_dalles_cost');
            $dalles_method->setAccessible(true);
            $dalles_cost = $dalles_method->invoke($this->calculator, $dalles);

            $pct_method = $reflect->getMethod('calculate_percentage_cost');
            $pct_method->setAccessible(true);
            $autres_calc = $pct_method->invoke($this->calculator, strtoupper($country), $postcode, $autres);

            $result = [
                'dalles_cost' => $dalles_cost,
                'autres_calc' => $autres_calc,
                'fixe' => $fixe,
                'total' => $dalles_cost + $autres_calc['cost'] + $fixe,
            ];
        }
        ?>
        <h2>🧪 Simuler un calcul</h2>
        <p>Utilisez les 3 exemples du cahier des charges pour valider : ex. département 24, 1390€ → 181,40€ attendu.</p>

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
                    <?php if (!empty($result['autres_calc']['zone'])): ?>
                    <tr><td>Zone détectée</td><td><?php echo esc_html($result['autres_calc']['zone']); ?> (coefficient ×<?php echo esc_html($result['autres_calc']['coefficient']); ?>)</td></tr>
                    <?php endif; ?>
                    <?php if (!empty($result['autres_calc']['tranche'])): ?>
                    <tr><td>% appliqué</td><td>
                        <?php echo esc_html(round($result['autres_calc']['pct_final'] * 100, 2)); ?>%
                        <?php echo !empty($result['autres_calc']['plafonne']) ? ' <span style="color:#d63638;">(plafonné au max de la tranche)</span>' : ''; ?>
                    </td></tr>
                    <?php endif; ?>
                    <tr><td>Coût autres produits</td><td><strong><?php echo wc_price($result['autres_calc']['cost']); ?></strong></td></tr>
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
        <h2>📋 Barème dalles (fixe, inchangé)</h2>
        <table class="widefat striped" style="max-width:500px;">
            <thead><tr><th>Palettes</th><th>Tarif</th></tr></thead>
            <tbody>
                <tr><td>1 palette</td><td>110 €</td></tr>
                <tr><td>2 palettes</td><td>180 €</td></tr>
                <tr><td>3 palettes</td><td>240 €</td></tr>
                <tr><td>4 palettes</td><td>290 €</td></tr>
                <tr><td>5+ palettes</td><td>1 € (symbolique — incitatif volume, ne jamais remonter)</td></tr>
            </tbody>
        </table>

        <h2 style="margin-top:30px;">🇫🇷 France — tranches (avant coefficient de zone)</h2>
        <table class="widefat striped" style="max-width:600px;">
            <thead><tr><th>Valeur commande</th><th>% min</th><th>% max</th></tr></thead>
            <tbody>
                <?php foreach ($this->data->get_tranches_france() as $t): ?>
                <tr>
                    <td><?php echo esc_html($t['min']); ?>€ - <?php echo $t['max'] === null ? '∞' : esc_html($t['max']) . '€'; ?></td>
                    <td><?php echo esc_html($t['pct_min'] * 100); ?>%</td>
                    <td><?php echo esc_html($t['pct_max'] * 100); ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top:20px;">Coefficients géographiques (France)</h3>
        <table class="widefat striped" style="max-width:400px;">
            <thead><tr><th>Zone</th><th>Coefficient</th></tr></thead>
            <tbody>
                <?php foreach ($this->data->get_coefficients_geo() as $zone => $coeff): ?>
                <tr><td>Zone <?php echo esc_html($zone); ?></td><td>×<?php echo esc_html($coeff); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><code>% final = MIN( %min_tranche × coefficient_zone , %max_tranche )</code></p>

        <h2 style="margin-top:30px;">🇧🇪 Belgique — tranches (pas de coefficient, %min appliqué directement)</h2>
        <table class="widefat striped" style="max-width:600px;">
            <thead><tr><th>Valeur commande</th><th>% min (appliqué)</th><th>% max</th></tr></thead>
            <tbody>
                <?php foreach ($this->data->get_tranches_belgique() as $t): ?>
                <tr>
                    <td><?php echo esc_html($t['min']); ?>€ - <?php echo $t['max'] === null ? '∞' : esc_html($t['max']) . '€'; ?></td>
                    <td><?php echo esc_html($t['pct_min'] * 100); ?>%</td>
                    <td><?php echo esc_html($t['pct_max'] * 100); ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:20px; color:#666; font-style:italic;">
            Commande mixte : coût dalles + coût autres produits calculés séparément sur leurs sous-totaux respectifs, puis additionnés en une seule ligne "Frais de livraison".
        </p>

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

    private function tab_zones() {
        ?>
        <h2>🌍 Zones par département (France)</h2>
        <p style="color:#666;">Département 69 (Rhône) : les deux entrées 69D/69M de la grille transporteur appartiennent à la même zone (C) — fusionnées ici, non bloquant pour ce calcul. Départements absents de cette table (Corse 2A/2B, DOM-TOM 97/98) : zone D appliquée par défaut.</p>
        <table class="widefat striped" style="max-width:400px;">
            <thead><tr><th>Département</th><th>Zone</th></tr></thead>
            <tbody>
                <?php foreach ($this->data->get_zones_par_departement() as $dept => $zone): ?>
                <tr><td><?php echo esc_html($dept); ?></td><td>Zone <?php echo esc_html($zone); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function tab_config() {
        $debug = get_option('mk_v4_debug', 'yes');
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
                        Voir l'onglet « Barème / Règles » pour la logique.
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="mk_v4_save_settings" class="button button-primary">Enregistrer</button></p>
        </form>
        <?php
    }
}
