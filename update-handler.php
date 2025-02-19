<?php

if ( ! class_exists('Emu_Update_Core')){

class Emu_Update_Core { 
    private $api_url_core;
    private $plugin_slug_core;
    private $checked = false; // Flag para evitar múltiplas verificações na mesma requisição

    public function __construct($plugin_slug_core, $api_url_core = '') {
        $this->plugin_slug_core = $plugin_slug_core;
        $this->api_url_core    = $api_url_core 
            ? $api_url_core 
            : 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $this->plugin_slug_core . '/info.json';

        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
        
        // Inicia a sessão, se não estiver ativa
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function plugin_info($res, $action, $args) {
        if ('plugin_information' !== $action || $args->slug !== $this->plugin_slug_core) {
            return $res;
        }

        // Verifica se já existe a informação na sessão
        if (isset($_SESSION['emu_update_core_info_' . $this->plugin_slug_core])) {
            return $_SESSION['emu_update_core_info_' . $this->plugin_slug_core];
        }

        $remote = wp_remote_get($this->api_url_core);
        if (is_wp_error($remote)) {
            return $res;
        }

        $plugin_info_core = json_decode(wp_remote_retrieve_body($remote));
        if (!$plugin_info_core) {
            return $res;
        }

        // Sanitiza a URL de download
        $plugin_info_core->download_url = $this->sanitize_download_url($plugin_info_core->download_url);

        $res = new stdClass();
        $res->name          = $plugin_info_core->name;
        $res->slug          = $this->plugin_slug_core;
        $res->version       = $plugin_info_core->version;
        $res->author        = '<a href="' . esc_url($plugin_info_core->author_homepage) . '">' . $plugin_info_core->author . '</a>';
        $res->download_link = $plugin_info_core->download_url;
        $res->tested        = $plugin_info_core->tested;
        $res->requires      = $plugin_info_core->requires;
        $res->sections      = (array) $plugin_info_core->sections;

        // Armazena as informações na sessão por esta requisição
        $_SESSION['emu_update_core_info_' . $this->plugin_slug_core] = $res;

        return $res;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Tenta recuperar a informação de atualização da sessão
        if (isset($_SESSION['emu_update_core_update_' . $this->plugin_slug_core])) {
            $cached_update = $_SESSION['emu_update_core_update_' . $this->plugin_slug_core];
            if (!empty($cached_update)) {
                $plugin_file_path_core = $this->plugin_slug_core . '/' . $this->plugin_slug_core . '.php';
                $transient->response[$plugin_file_path_core] = $cached_update;
            }
            return $transient;
        }

        // Se já foi verificado nesta requisição, não refaz a requisição remota
        if ($this->checked) {
            return $transient;
        }
        $this->checked = true;

        $remote = wp_remote_get($this->api_url_core);
        if (is_wp_error($remote)) {
            return $transient;
        }

        $plugin_info_core = json_decode(wp_remote_retrieve_body($remote));
        if (!$plugin_info_core) {
            return $transient;
        }

        // Sanitiza a URL de download (se não tiver sido feita na requisição anterior)
        $plugin_info_core->download_url = $this->sanitize_download_url($plugin_info_core->download_url);

        $plugin_file_path_core      = $this->plugin_slug_core . '/' . $this->plugin_slug_core . '.php';
        $plugin_file_full_path_core = WP_PLUGIN_DIR . '/' . $plugin_file_path_core;

        if (!file_exists($plugin_file_full_path_core)) {
            return $transient;
        }

        // Obtém a versão atual do plugin
        $plugin_headers_core  = get_file_data($plugin_file_full_path_core, ['Version' => 'Version']);
        $current_version_core = $plugin_headers_core['Version'];

        // Se a versão remota for maior, prepara os dados de atualização
        if (version_compare($current_version_core, $plugin_info_core->version, '<')) {
            $update_data = (object) [
                'slug'        => $this->plugin_slug_core,
                'plugin'      => $plugin_file_path_core,
                'new_version' => $plugin_info_core->version,
                'package'     => $plugin_info_core->download_url,
                'tested'      => $plugin_info_core->tested,
                'requires'    => $plugin_info_core->requires
            ];

            $transient->response[$plugin_file_path_core] = $update_data;
            // Armazena os dados de atualização na sessão
            $_SESSION['emu_update_core_update_' . $this->plugin_slug_core] = $update_data;
        }

        return $transient;
    }

    private function sanitize_download_url($url) {
        return esc_url_raw($url);
    }
}
}

if ( ! class_exists('Emu_Updater')){
class Emu_Updater {
    private $api_url;
    private $plugin_slug;

    public function __construct($plugin_slug) {
        $this->plugin_slug = $plugin_slug;
        $this->api_url     = 'https://raw.githubusercontent.com/emuplugins/' . $this->plugin_slug . '/main/info.json';

        add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_details'], 10, 3);
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
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
    
        $transient_key = 'emu_updater_' . $this->plugin_slug;
        $update_info = get_transient($transient_key);
    
        if (!$update_info) {
            $response = wp_remote_get($this->api_url);
            if (is_wp_error($response)) {
                return $result;
            }
    
            $update_info = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($update_info) || empty($update_info['version'])) {
                return $result;
            }
    
            set_transient($transient_key, $update_info, HOUR_IN_SECONDS);
        }
    
        // Crie um novo objeto stdClass e adicione a propriedade "plugin"
        $plugin_details = new stdClass();
        $plugin_details->name = isset($update_info['name']) ? $update_info['name'] : '';
        $plugin_details->slug = isset($update_info['slug']) ? $update_info['slug'] : '';
        
        // Atribuir valor à propriedade "plugin", se ela não estiver definida no $update_info, use $this->plugin_slug
        $plugin_details->plugin = isset($update_info['plugin']) ? $update_info['plugin'] : $this->plugin_slug;
        
        $plugin_details->version = isset($update_info['version']) ? $update_info['version'] : '';
        $plugin_details->author = isset($update_info['author_homepage']) && isset($update_info['author']) 
            ? '<a href="' . esc_url($update_info['author_homepage']) . '">' . esc_html($update_info['author']) . '</a>' 
            : '';
        $plugin_details->download_link = isset($update_info['download_url']) ? $update_info['download_url'] : '';
        $plugin_details->last_updated = isset($update_info['last_updated']) ? $update_info['last_updated'] : '';
        $plugin_details->tested = isset($update_info['tested']) ? $update_info['tested'] : '';
        $plugin_details->requires = isset($update_info['requires']) ? $update_info['requires'] : '';
        $plugin_details->sections = isset($update_info['sections']) ? $update_info['sections'] : '';
        
        return $plugin_details;
    }
    
    
}
}

$plugin_dir_unsanitized = basename(__DIR__);
$plugin_slug = $plugin_dir_unsanitized;
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}

$desired_plugin_dir = $plugin_slug;
$self_plugin_dir = $plugin_dir_unsanitized;

// Instância do Emu_Updater
new Emu_Updater($plugin_slug);

// Chamada da função
custom_plugin_update_management($self_plugin_dir, $plugin_slug, $desired_plugin_dir);

function custom_plugin_update_management($self_plugin_dir, $plugin_slug, $desired_plugin_dir) {
    // Exibe o link de "Verificar Atualizações" na listagem de plugins
    add_filter('plugin_action_links_' . $self_plugin_dir . '/' . $plugin_slug . '.php', function($actions) use ($self_plugin_dir) {
        $url = wp_nonce_url(admin_url("plugins.php?force-check-update=$self_plugin_dir"), "force_check_update_$self_plugin_dir");
        $actions['check_update'] = '<a href="' . esc_url($url) . '">Verificar Atualizações</a>';
        return $actions;
    });

    // Após instalação/atualização, move o plugin para o diretório desejado
    add_filter('upgrader_post_install', function($response, $hook_extra, $result) use ($desired_plugin_dir) {
        global $wp_filesystem;
        
        $proper_destination = WP_PLUGIN_DIR . '/' . $desired_plugin_dir;
        $current_destination = $result['destination'];
        
        if ($current_destination !== $proper_destination) {
            $wp_filesystem->move($current_destination, $proper_destination);
            $result['destination'] = $proper_destination;
        }
        
        return $response;
    }, 10, 3);

    add_action('upgrader_process_complete', function($upgrader_object, $options) use ($self_plugin_dir, $desired_plugin_dir, $plugin_slug) {
        $current_plugin_file = $self_plugin_dir . '/' . $plugin_slug . '.php';
        
        if (isset($options['action'], $options['type'], $options['plugins']) && 
            $options['action'] === 'update' && 
            $options['type'] === 'plugin' && 
            is_array($options['plugins']) && 
            in_array($current_plugin_file, $options['plugins'])) {
            
            $plugin_file = $current_plugin_file;
            
            if ($self_plugin_dir !== $desired_plugin_dir) {
                $old_path = WP_PLUGIN_DIR . '/' . $self_plugin_dir;
                $new_path = WP_PLUGIN_DIR . '/' . $desired_plugin_dir;
                
                if (rename($old_path, $new_path)) {
                    $plugin_file = $desired_plugin_dir . '/' . $plugin_slug . '.php';
                } else {
                    error_log('Erro ao renomear a pasta do plugin.');
                }
            }
            
            if (!is_plugin_active($plugin_file)) {
                $result = activate_plugin($plugin_file);
                if (is_wp_error($result)) {
                    error_log('Erro ao reativar o plugin: ' . $result->get_error_message());
                }
            }
        }
    }, 10, 2);
}
