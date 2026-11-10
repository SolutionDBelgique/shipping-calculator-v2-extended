<?php
/**
 * Onglet Admin "International" - Configuration pays
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

function shp_v2_ext_tab_international() {
    $config = SHP_V2_Country_Config::get_instance();
    $defaults = $config->get_defaults();
    $countries_info = [
        'FR' => ['name' => 'France', 'flag' => "\xF0\x9F\x87\xAB\xF0\x9F\x87\xB7"],
        'DE' => ['name' => 'Allemagne', 'flag' => "\xF0\x9F\x87\xA9\xF0\x9F\x87\xAA"],
        'PL' => ['name' => 'Pologne', 'flag' => "\xF0\x9F\x87\xB5\xF0\x9F\x87\xB1"],
        'NL' => ['name' => 'Pays-Bas', 'flag' => "\xF0\x9F\x87\xB3\xF0\x9F\x87\xB1"],
    ];

    // Sauvegarde
    if (isset($_POST['shp_v2_int_save_config']) && wp_verify_nonce($_POST['shp_v2_int_nonce'], 'shp_v2_int_save')) {
        foreach (array_keys($defaults) as $country) {
            $conf = [
                'pallet_size'     => (int) ($_POST["pallet_size_{$country}"] ?? $defaults[$country]['pallet_size']),
                'shipping_type'   => sanitize_text_field($_POST["shipping_type_{$country}"] ?? $defaults[$country]['shipping_type']),
                'shipping_cost'   => (float) ($_POST["shipping_cost_{$country}"] ?? $defaults[$country]['shipping_cost']),
                'shipping_notice' => sanitize_text_field($_POST["shipping_notice_{$country}"] ?? ''),
                'currency'        => sanitize_text_field($_POST["currency_{$country}"] ?? $defaults[$country]['currency']),
                'currency_symbol' => sanitize_text_field($_POST["currency_symbol_{$country}"] ?? $defaults[$country]['currency_symbol']),
            ];
            $config->save_config($country, $conf);
        }
        echo '<div class="notice notice-success"><p>Configuration internationale sauvegardee.</p></div>';
    }

    // Migration manuelle
    if (isset($_POST['shp_v2_int_run_migration']) && wp_verify_nonce($_POST['shp_v2_int_nonce'], 'shp_v2_int_save')) {
        if (file_exists(__DIR__ . '/international-migration.php')) {
            require_once __DIR__ . '/international-migration.php';
            $result = shp_v2_int_run_migration(true);
            echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
        }
    }
    ?>
    <h2>Configuration Internationale (v3.0)</h2>

    <div class="notice notice-info">
        <p><strong>Detection pays :</strong> Via la langue Weglot (fr=FR, de=DE, pl=PL, nl=NL)</p>
        <p><strong>Transport :</strong> "rules" = regles tarifaires existantes, "fixed" = cout fixe par palette</p>
    </div>

    <form method="post">
        <?php wp_nonce_field('shp_v2_int_save', 'shp_v2_int_nonce'); ?>

        <table class="widefat" style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th>Pays</th>
                    <th>Taille palette (m2)</th>
                    <th>Type transport</th>
                    <th>Cout fixe/palette</th>
                    <th>Mention livraison</th>
                    <th>Devise</th>
                    <th>Symbole</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_keys($defaults) as $country): ?>
                <?php
                    $conf = $config->get_config($country);
                    $info = $countries_info[$country] ?? ['name' => $country, 'flag' => ''];
                ?>
                <tr>
                    <td>
                        <strong><?php echo $info['flag'] . ' ' . esc_html($info['name']); ?></strong>
                        <br><small><?php echo esc_html($country); ?></small>
                    </td>
                    <td>
                        <input type="number" name="pallet_size_<?php echo esc_attr($country); ?>"
                               value="<?php echo esc_attr($conf['pallet_size']); ?>"
                               style="width: 80px;" min="1">
                    </td>
                    <td>
                        <select name="shipping_type_<?php echo esc_attr($country); ?>" style="width: 100px;">
                            <option value="rules" <?php selected($conf['shipping_type'], 'rules'); ?>>Rules</option>
                            <option value="fixed" <?php selected($conf['shipping_type'], 'fixed'); ?>>Fixed</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="shipping_cost_<?php echo esc_attr($country); ?>"
                               value="<?php echo esc_attr($conf['shipping_cost']); ?>"
                               style="width: 80px;" min="0" step="0.01">
                    </td>
                    <td>
                        <input type="text" name="shipping_notice_<?php echo esc_attr($country); ?>"
                               value="<?php echo esc_attr($conf['shipping_notice']); ?>"
                               style="width: 200px;"
                               placeholder="Ex: Livraison sans chariot">
                    </td>
                    <td>
                        <select name="currency_<?php echo esc_attr($country); ?>" style="width: 80px;">
                            <option value="EUR" <?php selected($conf['currency'], 'EUR'); ?>>EUR</option>
                            <option value="PLN" <?php selected($conf['currency'], 'PLN'); ?>>PLN</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="currency_symbol_<?php echo esc_attr($country); ?>"
                               value="<?php echo esc_attr($conf['currency_symbol']); ?>"
                               style="width: 50px;">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="submit" name="shp_v2_int_save_config" class="button button-primary">
                Enregistrer la configuration
            </button>
        </p>
    </form>

    <hr style="margin: 30px 0;">

    <h3>Migration v2.8 vers v3.0</h3>
    <p>Copie les prix WooCommerce existants vers _price_FR, _price_DE, _price_NL. PL reste vide (saisie manuelle en PLN).</p>
    <?php
    $migration_done = get_option('shp_v2_int_migration_done', false);
    if ($migration_done) {
        echo '<p style="color: green;"><strong>Migration deja effectuee.</strong></p>';
    }
    ?>
    <form method="post">
        <?php wp_nonce_field('shp_v2_int_save', 'shp_v2_int_nonce'); ?>
        <button type="submit" name="shp_v2_int_run_migration" class="button"
                onclick="return confirm('Executer la migration ? Les prix existants seront copies vers _price_FR/_price_DE/_price_NL.');">
            Executer la migration
        </button>
    </form>
    <?php
}
