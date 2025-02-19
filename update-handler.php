<?php
if (!defined('ABSPATH')) exit;

class Emu_Update_Core {
    // Classe vazia, se necessário
}

class Emu_Updater {
    private $api_url;
    private $plugin_slug;

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        $this->api_url     = 'https://raw.githubusercontent.com/emuplugins/' . $this->plugin_slug . '/main/info.json';

        add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_details'], 10, 3);

        // Verificar e corrigir o slug do plugin após ativação
        add_action('activated_plugin', [$this, 'correct_plugin_slug']);
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;

        $transient_key = 'emu_updater_' . $this->plugin_slug;
        $update_info = get_transient($transient_key);

        if (!$update_info) {
            $response = wp_remote_get($this->api_url);
            if (is_wp_error($response)) return $transient;

            $update_info = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($update_info) || empty($update_info['version'])) return $transient;

            set_transient($transient_key, $update_info, HOUR_IN_SECONDS);
        }

        // Use o caminho correto do plugin para recuperar a versão atual
        $plugin_file = $this->plugin_slug . '/' . $this->plugin_slug . '.php';
        $current_version = isset($transient->checked[$plugin_file]) ? $transient->checked[$plugin_file] : '0.0.0';

        if (version_compare($update_info['version'], $current_version, '>')) {
            $transient->response[$plugin_file] = (object) [
                'slug'        => $update_info['slug'],
                'new_version' => $update_info['version'],
                'package'     => $update_info['download_url'],
                'url'         => $update_info['author_homepage']
            ];
        }

        return $transient;
    }

    public function plugin_details($result, $action, $args) {
        if ($args->slug !== $this->plugin_slug) return $result;

        $transient_key = 'emu_updater_' . $this->plugin_slug;
        $update_info = get_transient($transient_key);

        if (!$update_info) {
            $response = wp_remote_get($this->api_url);
            if (is_wp_error($response)) return $result;

            $update_info = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($update_info) || empty($update_info['version'])) return $result;

            set_transient($transient_key, $update_info, HOUR_IN_SECONDS);
        }

        return (object) [
            'name'          => $update_info['name'],
            'slug'          => $update_info['slug'],
            'version'       => $update_info['version'],
            'author'        => '<a href="' . esc_url($update_info['author_homepage']) . '">' . esc_html($update_info['author']) . '</a>',
            'download_link' => $update_info['download_url'],
            'last_updated'  => $update_info['last_updated'],
            'tested'        => $update_info['tested'],
            'requires'      => $update_info['requires'],
            'sections'      => $update_info['sections']
        ];
    }

    public function correct_plugin_slug() {
        // Verifica se o plugin foi ativado e tem o sufixo "-main"
        $plugin_dir_unsanitized = basename(__DIR__);
        if (substr($plugin_dir_unsanitized, -5) === '-main') {
            $plugin_slug = substr($plugin_dir_unsanitized, 0, -5);

            // Renomeia o diretório do plugin (cuidado com permissões de arquivo)
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_dir_unsanitized;
            $new_plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
            rename($plugin_path, $new_plugin_path);

            // Atualiza a base do plugin no WordPress
            activate_plugin($new_plugin_path . '/' . $plugin_slug . '.php');
        }
    }
}

$plugin_dir_unsanitized = basename(__DIR__);
$plugin_slug = $plugin_dir_unsanitized;
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}

new Emu_Updater($plugin_slug);

// Exibe o link de "Verificar Atualizações" na listagem de plugins
add_filter('plugin_action_links_' . $plugin_slug . '/' . $plugin_slug . '.php', function($actions) use ($plugin_slug) {
    $url = wp_nonce_url(admin_url("plugins.php?force-check-update=$plugin_slug"), "force_check_update_$plugin_slug");
    $actions['check_update'] = '<a href="' . esc_url($url) . '">Verificar Atualizações</a>';
    return $actions;
});
