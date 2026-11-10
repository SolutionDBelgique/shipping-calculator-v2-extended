<?php
/**
 * MK Transport Calculator V4 - Mise à jour automatique via GitHub Releases
 *
 * Interroge l'API GitHub pour détecter une nouvelle release. Aucune
 * configuration manuelle : il suffit de pousser sur la branche main avec un
 * numéro de version mis à jour dans l'en-tête du plugin — la GitHub Action
 * crée le tag v4.x.x + le zip, et les sites WordPress voient la mise à jour
 * apparaître dans leur tableau de bord sous ~12 h.
 *
 * Porté depuis SHP_Plugin_Updater (Shipping Calculator V3 Extended). Le filtre
 * ne remonte une mise à jour que si le tag suit la ligne 4.x (voir
 * is_compatible_release) : un site encore sous V4 ne se verra jamais proposer
 * par erreur une release d'une autre ligne.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MK_V4_Updater {

    private string $plugin_slug;
    private string $plugin_file;
    private string $github_owner;
    private string $github_repo;
    private string $version_prefix;
    private int    $cache_ttl;
    private string $transient_key;

    public function __construct(string $plugin_file, string $github_owner, string $github_repo, string $version_prefix = '4.', int $cache_ttl = 43200) {
        $this->plugin_file    = $plugin_file;
        $this->plugin_slug    = plugin_basename($plugin_file);
        $this->github_owner   = $github_owner;
        $this->github_repo    = $github_repo;
        $this->version_prefix = $version_prefix;
        $this->cache_ttl      = $cache_ttl;
        $this->transient_key  = 'mk_v4_updater_' . md5($this->plugin_slug);
    }

    public function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
        // Vider le cache quand WordPress force une vérification manuelle
        add_action('delete_site_transient_update_plugins', [$this, 'clear_cache_simple']);
    }

    public function clear_cache_simple(): void {
        delete_transient($this->transient_key);
    }

    /**
     * Ne considère que les releases de la ligne courante (par défaut « 4. ») :
     * évite qu'un site sous V4 se voie proposer une release d'une autre ligne
     * majeure encore taguée sur le même dépôt.
     */
    private function is_compatible_release(string $version): bool {
        return strpos($version, $this->version_prefix) === 0;
    }

    public function check_for_update(object $transient): object {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        $current_version = $transient->checked[$this->plugin_slug] ?? '0.0.0';
        $latest_version  = ltrim($release->tag_name, 'v');

        if (!$this->is_compatible_release($latest_version)) {
            return $transient;
        }

        if (version_compare($latest_version, $current_version, '>')) {
            $transient->response[$this->plugin_slug] = (object) [
                'id'           => $this->plugin_slug,
                'slug'         => dirname($this->plugin_slug),
                'plugin'       => $this->plugin_slug,
                'new_version'  => $latest_version,
                'url'          => $release->html_url,
                'package'      => $this->get_zip_url($release),
                'requires_php' => '7.4',
                'tested'       => '',
                'icons'        => [],
                'banners'      => [],
                'compatibility' => new stdClass(),
            ];
        } else {
            $transient->no_update[$this->plugin_slug] = (object) [
                'id'          => $this->plugin_slug,
                'slug'        => dirname($this->plugin_slug),
                'plugin'      => $this->plugin_slug,
                'new_version' => $latest_version,
                'url'         => $release->html_url,
                'package'     => '',
            ];
        }

        return $transient;
    }

    public function plugin_info(false|object $result, string $action, object $args): false|object {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release || !$this->is_compatible_release(ltrim($release->tag_name, 'v'))) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->plugin_file);

        return (object) [
            'name'          => $plugin_data['Name'] ?? 'MK Transport Calculator V4',
            'slug'          => dirname($this->plugin_slug),
            'version'       => ltrim($release->tag_name, 'v'),
            'author'        => $plugin_data['Author'] ?? 'SolutionD Belgique',
            'homepage'      => $release->html_url,
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => '',
            'download_link' => $this->get_zip_url($release),
            'last_updated'  => $release->published_at ?? '',
            'sections'      => [
                'description' => $plugin_data['Description'] ?? '',
                'changelog'   => $release->body ? nl2br(esc_html($release->body)) : '<p>Voir les releases sur GitHub.</p>',
            ],
        ];
    }

    public function clear_cache(\WP_Upgrader $upgrader, array $options): void {
        if (
            isset($options['action'], $options['type'], $options['plugins']) &&
            $options['action'] === 'update' &&
            $options['type'] === 'plugin' &&
            in_array($this->plugin_slug, (array) $options['plugins'], true)
        ) {
            delete_transient($this->transient_key);
        }
    }

    /**
     * Récupère la dernière release GitHub (avec cache transient).
     */
    private function get_latest_release(): ?object {
        $cached = get_transient($this->transient_key);

        if ($cached !== false) {
            return $cached ?: null;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_owner,
            $this->github_repo
        );

        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            'headers'    => ['Accept' => 'application/vnd.github+json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($this->transient_key, '', HOUR_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response));

        if (!$data || empty($data->tag_name)) {
            set_transient($this->transient_key, '', HOUR_IN_SECONDS);
            return null;
        }

        set_transient($this->transient_key, $data, $this->cache_ttl);

        return $data;
    }

    /**
     * Extrait l'URL du zip depuis les assets de la release.
     * Retourne l'URL du zipball GitHub en dernier recours.
     */
    private function get_zip_url(object $release): string {
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (str_ends_with($asset->name, '.zip')) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback : archive automatique GitHub
        return $release->zipball_url ?? '';
    }
}
