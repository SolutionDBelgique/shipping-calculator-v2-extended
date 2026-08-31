<?php
/**
 * MK Transport Calculator V4 - Données de référence
 *
 * Charge specification_transport_dev.json (tranches, coefficients, zones par département)
 * et expose les données prêtes à l'emploi pour le calculateur.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Data {

    private static $instance = null;
    private $data = null;
    private $zones_par_departement = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load();
    }

    private function load() {
        $json_path = MK_V4_PLUGIN_DIR . 'data/specification_transport_dev.json';

        if (!file_exists($json_path)) {
            $this->data = [];
            return;
        }

        $raw = file_get_contents($json_path);
        $decoded = json_decode($raw, true);

        $this->data = is_array($decoded) ? $decoded : [];

        $this->build_zones_par_departement();
    }

    /**
     * Normalise la table departements_par_zone : les clés spéciales 69D/69M
     * (Rhône hors métropole / Métropole de Lyon) sont fusionnées en une seule
     * entrée "69" car elles appartiennent à la même zone tarifaire (C).
     * Voir cahier des charges §4 : le detail metropole/hors-metropole n'affecte
     * pas le pourcentage applique (qui ne depend que de la zone), donc ce point
     * n'est pas bloquant pour la logique simplifiee v4.
     */
    private function build_zones_par_departement() {
        $source = $this->data['france']['departements_par_zone'] ?? [];
        $zones = [];

        foreach ($source as $dept => $info) {
            $dept_key = ($dept === '69D' || $dept === '69M') ? '69' : $dept;
            if (!isset($zones[$dept_key]) && isset($info['zone'])) {
                $zones[$dept_key] = $info['zone'];
            }
        }

        $this->zones_par_departement = $zones;
    }

    public function get_tranches_france() {
        return $this->data['france']['tranches_valeur_commande'] ?? [];
    }

    public function get_coefficients_geo() {
        return $this->data['france']['coefficients_geo'] ?? ['A' => 1.0, 'B' => 1.15, 'C' => 1.3, 'D' => 1.45];
    }

    public function get_tranches_belgique() {
        return $this->data['belgique']['tranches_valeur_commande'] ?? [];
    }

    public function get_zone_departement($dept_key) {
        return $this->zones_par_departement[$dept_key] ?? null;
    }

    public function get_zones_par_departement() {
        return $this->zones_par_departement ?: [];
    }

    public function get_bareme_dalles() {
        return [
            1 => 110.00,
            2 => 180.00,
            3 => 240.00,
            4 => 290.00,
            // 5 palettes et plus : tarif symbolique volontaire (incitatif volume), a ne jamais remonter
            '5_plus' => 1.00,
        ];
    }
}
