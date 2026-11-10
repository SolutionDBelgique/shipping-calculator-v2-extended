<?php
/**
 * MK Transport Calculator V4 - Données de référence
 *
 * Charge specification_transport_dev.json (tranches de pourcentage, barème palettes)
 * et expose les données prêtes à l'emploi pour le calculateur.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Data {

    private static $instance = null;
    private $data = null;

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
    }

    public function get_tranches_france() {
        return $this->data['france']['tranches_valeur_commande'] ?? [];
    }

    public function get_tranches_belgique() {
        return $this->data['belgique']['tranches_valeur_commande'] ?? [];
    }

    /**
     * Prix PAR palette de dalles selon le nombre de palettes, pour un pays.
     * France et pays hors France/Belgique : grille France.
     */
    public function get_bareme_dalles($country) {
        $grilles = $this->data['dalles_regle_a_part']['prix_par_palette'] ?? [];
        $key = ($country === 'BE') ? 'belgique' : 'france';

        return $grilles[$key] ?? [];
    }
}
