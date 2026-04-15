<?php
/**
 * Admin Tab Raben - Interface d'administration pour les tarifs Raben
 *
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function shp_v2_ext_tab_raben() {
    $raben = SHP_V2_Extended_Raben_Tariffs::get_instance();
    $stats = $raben->get_stats();
    $has_phpspreadsheet = class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');

    // Recuperer les surcharges actuelles
    $fuel_surcharge = get_option('shp_v2_ext_raben_fuel_surcharge', 15.00);
    $road_surcharge = get_option('shp_v2_ext_raben_road_surcharge', 8.76);

    // Traitement du test lookup
    $test_result = null;
    if (isset($_POST['test_raben_lookup']) && wp_verify_nonce($_POST['shp_v2_ext_nonce'] ?? '', 'shp_v2_ext_raben_test')) {
        $test_country  = sanitize_text_field($_POST['test_raben_country'] ?? '');
        $test_postcode = sanitize_text_field($_POST['test_raben_postcode'] ?? '');
        $test_weight   = (float) ($_POST['test_raben_weight'] ?? 0);

        if ($test_country && $test_postcode && $test_weight > 0) {
            $test_result = $raben->calculate_shipping($test_country, $test_postcode, $test_weight);
            if (!$test_result) {
                $zone = $raben->get_zone($test_country, $test_postcode);
                $test_result = [
                    'error' => true,
                    'zone' => $zone,
                    'message' => $zone
                        ? sprintf('Zone %s trouvee mais aucun prix pour %skg', $zone, $test_weight)
                        : 'Aucune zone trouvee pour ce code postal'
                ];
            }
        }
    }
    ?>

    <h2>Tarifs Raben (Transport International)</h2>

    <!-- Statistiques -->
    <table class="widefat" style="width: auto; margin-bottom: 20px;">
        <tr>
            <th colspan="2" style="padding: 10px; background: #d63384; color: white;">
                Raben - Statistiques
            </th>
        </tr>
        <tr>
            <th style="padding: 10px;">Zones postales</th>
            <td style="padding: 10px;"><strong><?php echo number_format($stats['zones']); ?></strong></td>
        </tr>
        <tr>
            <th style="padding: 10px;">Grilles de prix</th>
            <td style="padding: 10px;"><strong><?php echo number_format($stats['prices']); ?></strong></td>
        </tr>
        <tr>
            <th style="padding: 10px;">Pays couverts</th>
            <td style="padding: 10px;">
                <?php
                if (!empty($stats['countries'])) {
                    echo '<strong>' . implode(', ', $stats['countries']) . '</strong>';
                    echo ' (' . count($stats['countries']) . ' pays)';
                } else {
                    echo '<em>Aucun - importez un fichier Raben</em>';
                }
                ?>
            </td>
        </tr>
        <tr>
            <th style="padding: 10px;">Dernier import</th>
            <td style="padding: 10px;">
                <?php
                if ($stats['last_import']) {
                    echo '<strong>' . esc_html($stats['last_import']) . '</strong>';
                } else {
                    echo '<em>Jamais</em>';
                }
                ?>
            </td>
        </tr>
        <tr>
            <th style="padding: 10px;">Surcharges</th>
            <td style="padding: 10px;">
                Carburant <strong><?php echo number_format($fuel_surcharge, 2); ?>%</strong>
                + Routiere <strong><?php echo number_format($road_surcharge, 2); ?>%</strong>
                = <strong><?php echo number_format($fuel_surcharge + $road_surcharge, 2); ?>%</strong>
            </td>
        </tr>
    </table>

    <!-- Import Excel Raben -->
    <h3>Import fichier Excel Raben</h3>

    <?php if (!$has_phpspreadsheet): ?>
        <div class="notice notice-error">
            <p><strong>PhpSpreadsheet non installe.</strong> Executez <code>composer install --no-dev</code> dans le dossier du plugin.</p>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="max-width: 600px; margin-bottom: 30px;">
        <?php wp_nonce_field('shp_v2_ext_import_raben', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="import_raben">

        <table class="form-table">
            <tr>
                <th><label for="raben_excel_file">Fichier Excel Raben</label></th>
                <td>
                    <input type="file" name="raben_excel_file" id="raben_excel_file" accept=".xlsx,.xls" required <?php echo !$has_phpspreadsheet ? 'disabled' : ''; ?>>
                    <p class="description">Fichier tarifs Raben avec onglets XX-kody (zones) et XX (prix)</p>
                </td>
            </tr>
            <tr>
                <th><label for="raben_replace_all">Remplacer tout</label></th>
                <td>
                    <input type="checkbox" name="raben_replace_all" id="raben_replace_all" value="1">
                    <span class="description">Supprime toutes les zones et prix existants avant import</span>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary" <?php echo !$has_phpspreadsheet ? 'disabled' : ''; ?>>
                Importer Raben
            </button>
        </p>
    </form>

    <!-- Configuration surcharges -->
    <h3>Configuration des surcharges</h3>

    <form method="post" style="max-width: 600px; margin-bottom: 30px;">
        <?php wp_nonce_field('shp_v2_ext_save_raben_config', 'shp_v2_ext_nonce'); ?>
        <input type="hidden" name="shp_v2_ext_action" value="save_raben_config">

        <table class="form-table">
            <tr>
                <th><label for="raben_fuel_surcharge">Surcharge carburant (%)</label></th>
                <td>
                    <input type="number" name="raben_fuel_surcharge" id="raben_fuel_surcharge"
                           value="<?php echo esc_attr($fuel_surcharge); ?>" step="0.01" min="0" max="100" style="width: 120px;">
                </td>
            </tr>
            <tr>
                <th><label for="raben_road_surcharge">Surcharge routiere (%)</label></th>
                <td>
                    <input type="number" name="raben_road_surcharge" id="raben_road_surcharge"
                           value="<?php echo esc_attr($road_surcharge); ?>" step="0.01" min="0" max="100" style="width: 120px;">
                </td>
            </tr>
            <tr>
                <th>Total surcharges</th>
                <td>
                    <strong><?php echo number_format($fuel_surcharge + $road_surcharge, 2); ?>%</strong>
                    <p class="description">Appliquees sur le prix netto Raben</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary">Enregistrer les surcharges</button>
        </p>
    </form>

    <!-- Test de lookup -->
    <h3>Test de lookup Raben</h3>

    <form method="post" style="max-width: 600px;">
        <?php wp_nonce_field('shp_v2_ext_raben_test', 'shp_v2_ext_nonce'); ?>

        <table class="form-table">
            <tr>
                <th><label for="test_raben_country">Pays</label></th>
                <td>
                    <input type="text" name="test_raben_country" id="test_raben_country"
                           value="<?php echo isset($_POST['test_raben_country']) ? esc_attr($_POST['test_raben_country']) : 'DE'; ?>"
                           maxlength="2" style="width: 80px;" placeholder="DE" required>
                    <p class="description">Code pays 2 lettres (DE, CZ, HU, ES, etc.)</p>
                </td>
            </tr>
            <tr>
                <th><label for="test_raben_postcode">Code postal</label></th>
                <td>
                    <input type="text" name="test_raben_postcode" id="test_raben_postcode"
                           value="<?php echo isset($_POST['test_raben_postcode']) ? esc_attr($_POST['test_raben_postcode']) : ''; ?>"
                           style="width: 150px;" placeholder="10115" required>
                </td>
            </tr>
            <tr>
                <th><label for="test_raben_weight">Poids (kg)</label></th>
                <td>
                    <input type="number" name="test_raben_weight" id="test_raben_weight"
                           value="<?php echo isset($_POST['test_raben_weight']) ? esc_attr($_POST['test_raben_weight']) : '1250'; ?>"
                           step="0.01" min="0.01" style="width: 150px;" required>
                    <p class="description">1 palette dalles = 1250kg, 1 matelas = 75kg</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" name="test_raben_lookup" class="button button-primary">Tester le lookup</button>
        </p>
    </form>

    <?php if ($test_result): ?>
        <?php if (!empty($test_result['error'])): ?>
            <div class="notice notice-error" style="padding: 15px; margin-top: 15px;">
                <h4>Aucun resultat</h4>
                <p><?php echo esc_html($test_result['message']); ?></p>
            </div>
        <?php else: ?>
            <div class="notice notice-success" style="padding: 15px; margin-top: 15px;">
                <h4>Resultat du lookup Raben</h4>
                <table style="width: auto;">
                    <tr>
                        <td style="padding: 5px;"><strong>Pays :</strong></td>
                        <td style="padding: 5px;"><?php echo esc_html($test_result['country']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Code postal :</strong></td>
                        <td style="padding: 5px;"><?php echo esc_html($test_result['postcode']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Zone :</strong></td>
                        <td style="padding: 5px;"><?php echo esc_html($test_result['zone']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Poids :</strong></td>
                        <td style="padding: 5px;"><?php echo esc_html($test_result['weight_kg']); ?> kg</td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Prix netto :</strong></td>
                        <td style="padding: 5px;"><?php echo number_format($test_result['price_netto'], 2); ?> EUR</td>
                    </tr>
                    <tr>
                        <td style="padding: 5px;"><strong>Surcharges :</strong></td>
                        <td style="padding: 5px;">
                            +<?php echo number_format($test_result['surcharge_pct'], 2); ?>%
                            = <?php echo number_format($test_result['surcharge_amount'], 2); ?> EUR
                            (carburant <?php echo number_format($test_result['fuel_surcharge'], 2); ?>%
                            + routiere <?php echo number_format($test_result['road_surcharge'], 2); ?>%)
                        </td>
                    </tr>
                    <tr style="border-top: 2px solid #d63384;">
                        <td style="padding: 5px;"><strong>Prix ALL IN :</strong></td>
                        <td style="padding: 5px;">
                            <strong style="font-size: 18px; color: #d63384;">
                                <?php echo number_format($test_result['price_all_in'], 2); ?> EUR
                            </strong>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="notice notice-info" style="margin-top: 20px;">
        <h4>Format du fichier Excel Raben</h4>
        <p>Le fichier doit contenir les onglets suivants :</p>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><strong>XX-kody</strong> (ex: DE-kody, CZ-kody) : zones postales avec colonnes code_postal_debut, code_postal_fin, zone</li>
            <li><strong>XX</strong> (ex: DE, CZ) : grille de prix avec la ligne "Zakres wagi" comme header des zones</li>
        </ul>
        <p>Les onglets KOREKTY et ceux contenant "." sont ignores.</p>
    </div>
    <?php
}
