<?php
/**
 * Classe Raben Tariffs - Gestion des tarifs transporteur Raben
 *
 * Gere les zones postales et grilles de prix Raben pour les pays hors FR/BE/LU/NL.
 * Import depuis fichier Excel Raben, lookup zone par code postal, calcul prix avec surcharges.
 *
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHP_V2_Extended_Raben_Tariffs {

    private static $instance = null;
    private $table_zones;
    private $table_prices;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_zones  = $wpdb->prefix . 'shipping_raben_zones';
        $this->table_prices = $wpdb->prefix . 'shipping_raben_prices';
    }

    /**
     * Creation des tables BDD via dbDelta
     */
    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql_zones = "CREATE TABLE IF NOT EXISTS {$this->table_zones} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            country_code varchar(2) NOT NULL,
            postcode_from varchar(10) NOT NULL,
            postcode_to varchar(10) NOT NULL,
            zone_number varchar(10) NOT NULL,
            PRIMARY KEY (id),
            KEY country_postcode (country_code, postcode_from, postcode_to)
        ) $charset_collate;";

        $sql_prices = "CREATE TABLE IF NOT EXISTS {$this->table_prices} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            country_code varchar(2) NOT NULL,
            zone_number varchar(10) NOT NULL,
            weight_min decimal(10,2) NOT NULL,
            weight_max decimal(10,2) NOT NULL,
            price_netto decimal(10,2) NOT NULL,
            PRIMARY KEY (id),
            KEY country_zone_weight (country_code, zone_number, weight_min, weight_max)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_zones);
        dbDelta($sql_prices);
    }

    /**
     * Import depuis fichier Excel Raben
     *
     * Detecte les onglets :
     * - XX-kody ou XX -kody : zones (postcode_from, postcode_to, zone)
     * - XX (2 lettres exactement) : grille prix (Zakres wagi = header)
     * - Ignore : KOREKTY, onglets avec "."
     *
     * @param string $file_path Chemin vers le fichier Excel
     * @param bool   $replace_all Vider les tables avant import
     * @return array Resultat de l'import
     */
    public function import_from_excel($file_path, $replace_all = false) {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            return [
                'success' => false,
                'message' => 'PhpSpreadsheet n\'est pas installe.'
            ];
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);

            if ($replace_all) {
                $this->truncate_all();
            }

            $zones_imported  = 0;
            $prices_imported = 0;
            $errors          = [];
            $countries_found = [];

            $sheet_names = $spreadsheet->getSheetNames();

            foreach ($sheet_names as $sheet_name) {
                $name_trimmed = trim($sheet_name);

                // Ignorer les onglets KOREKTY et ceux contenant "."
                if (stripos($name_trimmed, 'KOREKTY') !== false || strpos($name_trimmed, '.') !== false) {
                    continue;
                }

                // Detecter onglet zones : XX-kody ou XX -kody
                if (preg_match('/^([A-Z]{2})\s*-\s*kody$/i', $name_trimmed, $matches)) {
                    $country_code = strtoupper($matches[1]);
                    $worksheet = $spreadsheet->getSheetByName($sheet_name);
                    $result = $this->import_zones_sheet($worksheet, $country_code);
                    $zones_imported += $result['imported'];
                    $errors = array_merge($errors, $result['errors']);
                    $countries_found[] = $country_code;
                    continue;
                }

                // Detecter onglet prix : exactement 2 lettres majuscules
                if (preg_match('/^[A-Z]{2}$/i', $name_trimmed)) {
                    $country_code = strtoupper($name_trimmed);
                    $worksheet = $spreadsheet->getSheetByName($sheet_name);
                    $result = $this->import_prices_sheet($worksheet, $country_code);
                    $prices_imported += $result['imported'];
                    $errors = array_merge($errors, $result['errors']);
                    if (!in_array($country_code, $countries_found)) {
                        $countries_found[] = $country_code;
                    }
                }
            }

            // Sauvegarder la date du dernier import
            update_option('shp_v2_ext_raben_last_import', current_time('mysql'));

            return [
                'success'  => true,
                'zones'    => $zones_imported,
                'prices'   => $prices_imported,
                'countries' => $countries_found,
                'errors'   => $errors,
                'message'  => sprintf(
                    '%d zone(s) et %d prix importes pour %d pays (%s)',
                    $zones_imported,
                    $prices_imported,
                    count($countries_found),
                    implode(', ', $countries_found)
                )
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ];
        }
    }

    /**
     * Importer les zones depuis un onglet kody
     * Header ligne 1, donnees lignes 2+ (3 colonnes : from, to, zone)
     */
    private function import_zones_sheet($worksheet, $country_code) {
        global $wpdb;

        $rows = $worksheet->toArray();
        $imported = 0;
        $errors   = [];

        // Ignorer le header (ligne 1)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            $from = isset($row[0]) ? trim((string) $row[0]) : '';
            $to   = isset($row[1]) ? trim((string) $row[1]) : '';
            $zone = isset($row[2]) ? trim((string) $row[2]) : '';

            if ($from === '' && $to === '' && $zone === '') {
                continue; // Ligne vide
            }

            if ($from === '' || $zone === '') {
                continue; // Donnees insuffisantes
            }

            // Si 'to' est vide, utiliser 'from' (zone = code postal unique)
            if ($to === '') {
                $to = $from;
            }

            $wpdb->insert(
                $this->table_zones,
                [
                    'country_code'  => $country_code,
                    'postcode_from' => $from,
                    'postcode_to'   => $to,
                    'zone_number'   => $zone,
                ],
                ['%s', '%s', '%s', '%s']
            );
            $imported++;
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Importer les prix depuis un onglet pays
     * Trouver la ligne contenant "Zakres wagi" = header des zones
     * Colonnes 3+ = numeros de zones
     * Lignes suivantes : col 1=index, col 2=poids_max, col 3+=prix par zone
     */
    private function import_prices_sheet($worksheet, $country_code) {
        global $wpdb;

        $rows = $worksheet->toArray();
        $imported = 0;
        $errors   = [];

        // Trouver la ligne header contenant "Zakres wagi"
        $header_row_index = null;
        $zone_columns     = [];

        foreach ($rows as $row_index => $row) {
            foreach ($row as $col_index => $cell) {
                if ($cell !== null && stripos(trim((string) $cell), 'Zakres wagi') !== false) {
                    $header_row_index = $row_index;
                    break 2;
                }
            }
        }

        if ($header_row_index === null) {
            $errors[] = sprintf('Onglet %s : ligne "Zakres wagi" non trouvee', $country_code);
            return ['imported' => 0, 'errors' => $errors];
        }

        // Extraire les numeros de zones depuis le header (colonnes 2+)
        $header_row = $rows[$header_row_index];
        for ($col = 2; $col < count($header_row); $col++) {
            $zone_val = isset($header_row[$col]) ? trim((string) $header_row[$col]) : '';
            if ($zone_val !== '') {
                $zone_columns[$col] = $zone_val;
            }
        }

        if (empty($zone_columns)) {
            $errors[] = sprintf('Onglet %s : aucune zone trouvee dans le header', $country_code);
            return ['imported' => 0, 'errors' => $errors];
        }

        // Traiter les lignes de donnees (apres le header)
        $prev_weight_max = 0;

        for ($i = $header_row_index + 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            // Colonne 1 (index 1) = poids_max de la tranche
            $weight_max_raw = isset($row[1]) ? trim((string) $row[1]) : '';

            if ($weight_max_raw === '' || !is_numeric(str_replace(',', '.', $weight_max_raw))) {
                // Fin des donnees ou ligne invalide
                if ($weight_max_raw === '') {
                    break;
                }
                continue;
            }

            $weight_max = (float) str_replace(',', '.', $weight_max_raw);

            // Determiner weight_min depuis la ligne precedente
            $weight_min = $prev_weight_max;

            // Inserer un prix pour chaque zone
            foreach ($zone_columns as $col => $zone_number) {
                $price_raw = isset($row[$col]) ? trim((string) $row[$col]) : '';

                if ($price_raw === '' || $price_raw === '-') {
                    continue;
                }

                $price = (float) str_replace(',', '.', str_replace(' ', '', $price_raw));

                if ($price <= 0) {
                    continue;
                }

                $wpdb->insert(
                    $this->table_prices,
                    [
                        'country_code' => $country_code,
                        'zone_number'  => $zone_number,
                        'weight_min'   => $weight_min,
                        'weight_max'   => $weight_max,
                        'price_netto'  => $price,
                    ],
                    ['%s', '%s', '%f', '%f', '%f']
                );
                $imported++;
            }

            $prev_weight_max = $weight_max;
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Lookup zone par code postal
     *
     * @param string $country Code pays 2 lettres
     * @param string $postcode Code postal
     * @return string|null Numero de zone ou null
     */
    public function get_zone($country, $postcode) {
        global $wpdb;

        $country = strtoupper($country);
        $postcode_clean = preg_replace('/[^0-9A-Za-z]/', '', $postcode);

        // Essayer d'abord une correspondance exacte numerique
        $postcode_num = preg_replace('/[^0-9]/', '', $postcode_clean);

        if ($postcode_num !== '') {
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT zone_number FROM {$this->table_zones}
                 WHERE country_code = %s
                 AND CAST(%s AS UNSIGNED) >= CAST(postcode_from AS UNSIGNED)
                 AND CAST(%s AS UNSIGNED) <= CAST(postcode_to AS UNSIGNED)
                 LIMIT 1",
                $country, $postcode_num, $postcode_num
            ));

            if ($result !== null) {
                return $result;
            }
        }

        // Fallback : correspondance textuelle
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT zone_number FROM {$this->table_zones}
             WHERE country_code = %s
             AND postcode_from <= %s
             AND postcode_to >= %s
             LIMIT 1",
            $country, $postcode_clean, $postcode_clean
        ));

        return $result;
    }

    /**
     * Lookup prix par zone + tranche de poids
     *
     * @param string $country Code pays
     * @param string $zone Numero de zone
     * @param float  $weight_kg Poids en kg
     * @return float|null Prix netto ou null
     */
    public function get_price($country, $zone, $weight_kg) {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT price_netto FROM {$this->table_prices}
             WHERE country_code = %s
             AND zone_number = %s
             AND weight_min < %f
             AND weight_max >= %f
             LIMIT 1",
            strtoupper($country), $zone, $weight_kg, $weight_kg
        ));

        return $result !== null ? (float) $result : null;
    }

    /**
     * Pipeline complet : zone -> prix -> surcharges
     *
     * @param string $country Code pays
     * @param string $postcode Code postal
     * @param float  $weight_kg Poids en kg
     * @return array|null Resultat avec prix netto et all-in, ou null si non trouve
     */
    public function calculate_shipping($country, $postcode, $weight_kg) {
        $zone = $this->get_zone($country, $postcode);

        if ($zone === null) {
            return null;
        }

        $price_netto = $this->get_price($country, $zone, $weight_kg);

        if ($price_netto === null) {
            return null;
        }

        $fuel_surcharge = (float) get_option('shp_v2_ext_raben_fuel_surcharge', 15.00);
        $road_surcharge = (float) get_option('shp_v2_ext_raben_road_surcharge', 8.76);
        $total_surcharge_pct = $fuel_surcharge + $road_surcharge;

        $surcharge_amount = $price_netto * ($total_surcharge_pct / 100);
        $price_all_in = $price_netto + $surcharge_amount;

        return [
            'country'          => strtoupper($country),
            'postcode'         => $postcode,
            'zone'             => $zone,
            'weight_kg'        => $weight_kg,
            'price_netto'      => round($price_netto, 2),
            'fuel_surcharge'   => $fuel_surcharge,
            'road_surcharge'   => $road_surcharge,
            'surcharge_pct'    => $total_surcharge_pct,
            'surcharge_amount' => round($surcharge_amount, 2),
            'price_all_in'     => round($price_all_in, 2),
        ];
    }

    /**
     * Statistiques generales
     */
    public function get_stats() {
        return [
            'zones'     => $this->count_zones(),
            'prices'    => $this->count_prices(),
            'countries' => $this->get_countries(),
            'last_import' => get_option('shp_v2_ext_raben_last_import', null),
        ];
    }

    /**
     * Liste des pays couverts
     */
    public function get_countries() {
        global $wpdb;

        $zone_countries = $wpdb->get_col(
            "SELECT DISTINCT country_code FROM {$this->table_zones} ORDER BY country_code"
        );

        return $zone_countries;
    }

    /**
     * Nombre total de zones
     */
    public function count_zones() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_zones}");
    }

    /**
     * Nombre total de prix
     */
    public function count_prices() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_prices}");
    }

    /**
     * Vider toutes les tables Raben
     */
    private function truncate_all() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_zones}");
        $wpdb->query("TRUNCATE TABLE {$this->table_prices}");
    }
}
