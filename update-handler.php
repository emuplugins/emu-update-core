<?php
if (!defined('ABSPATH')) exit;

if (!is_admin()) {
    exit;
}

class Emu_Update_Core {
    private $api_url;
    private $plugin_slug;
    private $plugin_dir;
    private $plugin_file;

    public function __construct($plugin_slug, $plugin_dir, $plugin_file, $api_url = '') {
        $this->plugin_slug = $plugin_slug;
        $this->plugin_dir  = $plugin_dir;
        $this->plugin_file = $plugin_file;
        $this->api_url     = $api_url ? $api_url : 'https://raw.githubusercontent.com/emuplugins/emu-update-list/main/' . $this->plugin_slug . '/info.json';
    
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('site_transient_update_plugins', array($this, 'check_for_update'));
        add_action('upgrader_process_complete', array($this, 'auto_reactivate_plugin_after_update'), 10, 2);
        add_filter('upgrader_source_selection', array($this, 'fix_plugin_directory'), 10, 4);
        add_action('upgrader_install_package_result', array($this, 'verify_installation'), 10, 2);
    }

    private function sanitize_download_url($url) {
        $parts = parse_url($url);
        if (!isset($parts['path'])) {
            return $url;
        }
    
        $path_parts = pathinfo($parts['path']);
        $new_path = rtrim($path_parts['dirname'], '/') . '/' . $this->plugin_slug . '.zip';
        
        $parts['path'] = $new_path;
        return $this->build_url($parts);
    }

    private function build_url($parts) {
        $url = '';
        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $url .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $parts['path'];
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }

    public function fix_plugin_directory($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;
    
        $plugin_basename = $this->plugin_dir . '/' . $this->plugin_file;
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $plugin_basename) {
            return $source;
        }
    
        $temp_dir = basename($source);
        if ($temp_dir === $this->plugin_slug) {
            return $source;
        }
    
        $new_source = trailingslashit(dirname($source)) . $this->plugin_slug;
        
        if (!$wp_filesystem->move($source, $new_source)) {
            error_log("Falha ao renomear diretório de {$source} para {$new_source}");
            return new WP_Error('rename_failed', 'Falha ao ajustar estrutura do plugin');
        }
    
        return $new_source;
    }

    public function verify_installation($result, $hook_extra) {
        $plugin_basename = $this->plugin_dir . '/' . $this->plugin_file;
        
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $plugin_basename) {
            return $result;
        }
    
        if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_basename)) {
            error_log("Arquivo do plugin não encontrado após instalação: " . WP_PLUGIN_DIR . '/' . $plugin_basename);
            return new WP_Error('install_failed', 'Arquivo principal do plugin não encontrado');
        }
    
        return $result;
    }

    public function plugin_info($res, $action, $args) {
        if ('plugin_information' !== $action || $args->slug !== $this->plugin_slug) {
            return $res;
        }
    
        $plugin_info = $this->get_plugin_info();
        if (!$plugin_info) {
            return $res;
        }
    
        $plugin_info->download_url = $this->sanitize_download_url($plugin_info->download_url);
    
        $res = new stdClass();
        $res->name          = $plugin_info->name;
        $res->slug          = $this->plugin_slug;
        $res->version       = $plugin_info->version;
        $res->author        = '<a href="' . esc_url($plugin_info->author_homepage) . '">' . $plugin_info->author . '</a>';
        $res->download_link = $plugin_info->download_url;
        $res->tested        = $plugin_info->tested;
        $res->requires      = $plugin_info->requires;
        $res->sections      = (array) $plugin_info->sections;
    
        return $res;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $plugin_info = $this->get_plugin_info();
        if (!$plugin_info) {
            return $transient;
        }

        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_dir . '/' . $this->plugin_file);
        $current_version = $plugin_data['Version'];

        if (version_compare($current_version, $plugin_info->version, '<')) {
            $transient->response[$this->plugin_dir . '/' . $this->plugin_file] = (object) array(
                'slug'        => $this->plugin_slug,
                'new_version' => $plugin_info->version,
                'package'     => $this->sanitize_download_url($plugin_info->download_url),
                'url'         => $plugin_info->homepage,
            );
        }

        return $transient;
    }
    
    public function auto_reactivate_plugin_after_update($upgrader_object, $options) {
        if ('update' === $options['action'] && 'plugin' === $options['type']) {
            if (isset($options['plugins']) && is_array($options['plugins'])) {
                $plugin_basename = $this->plugin_dir . '/' . $this->plugin_file;
                if (in_array($plugin_basename, $options['plugins']) && !is_plugin_active($plugin_basename)) {
                    activate_plugin($plugin_basename);
                }
            }
        }
    }

    private function get_plugin_info() {
        $cache_key = 'emu_plugin_info_' . $this->plugin_slug;
        $plugin_info = get_transient($cache_key);
        if ($plugin_info !== false) {
            return $plugin_info;
        }
    
        $remote = wp_remote_get($this->api_url);
        if (is_wp_error($remote)) {
            return false;
        }
    
        $plugin_info = json_decode(wp_remote_retrieve_body($remote));
        if (!$plugin_info) {
            return false;
        }
    
        set_transient($cache_key, $plugin_info, HOUR_IN_SECONDS);
    
        return $plugin_info;
    }
}
// Self Update

if (!class_exists('Emu_Updater')) {
    class Emu_Updater {
        private $api_url;
        private $plugin_slug;
        private $plugin_dir;
        private $self_plugin_dir;

        public function __construct($plugin_slug, $self_plugin_dir) {
            $this->plugin_slug   = $plugin_slug;
            $this->plugin_dir    = $self_plugin_dir;
            $this->api_url       = 'https://raw.githubusercontent.com/emuplugins/' . $this->plugin_slug . '/main/info.json';

            add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
            add_action('upgrader_process_complete', [$this, 'auto_reactivate_plugin_after_update'], 10, 2);
            add_action('wp_loaded', [$this, 'clear_plugin_transients']); // Limpa os transientes sempre que a página for recarregada
        }

        public function plugin_info($res, $action, $args) {
            if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
                return $res;
            }

            $plugin_info = $this->get_plugin_info();
            if (!$plugin_info) {
                return $res;
            }

            $res = new stdClass();
            $res->name          = $plugin_info->name;
            $res->slug          = $plugin_info->slug;
            $res->version       = $plugin_info->version;
            $res->author        = '<a href="' . $plugin_info->author_homepage . '">' . $plugin_info->author . '</a>';
            $res->download_link = $plugin_info->download_url;
            $res->tested        = $plugin_info->tested;
            $res->requires      = $plugin_info->requires;
            $res->sections      = (array) $plugin_info->sections;

            return $res;
        }

        public function check_for_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }
            
            // Cria uma flag única para cada plugin
            $flag_key = 'emu_updater_checked_' . $this->plugin_slug;
            if (isset($transient->$flag_key) && $transient->$flag_key) {
                return $transient;
            }
            $transient->$flag_key = true;
            
            // Obtém informações sobre o plugin
            $plugin_info = $this->get_plugin_info();
            if (!$plugin_info) {
                return $transient;
            }
            
            // Define o caminho do plugin
            $plugin_basename  = $this->plugin_dir . '/' . $this->plugin_slug . '.php';
            $plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_basename;
            
            // Obtém a versão atual a partir dos cabeçalhos do plugin
            $plugin_headers = get_file_data($plugin_file_path, ['Version' => 'Version']);
            $current_version = $plugin_headers['Version'];
            
            // Se houver nova versão, adiciona as informações ao transient
            if (version_compare($current_version, $plugin_info->version, '<')) {
                $transient->response[$plugin_basename] = (object) [
                    'slug'        => $this->plugin_slug,
                    'plugin'      => $plugin_basename,
                    'new_version' => $plugin_info->version,
                    'package'     => $plugin_info->download_url,
                    'tested'      => $plugin_info->tested,
                    'requires'    => $plugin_info->requires,
                ];
            }
            
            return $transient;
        }

        private function get_plugin_info() {
            $cache_key = 'emu_plugin_info_' . $this->plugin_slug;
            // Tenta recuperar as informações via transient (único para cada plugin)
            $plugin_info = get_transient($cache_key);
            if ($plugin_info !== false) {
                return $plugin_info;
            }

            $remote = wp_remote_get($this->api_url);
            if (is_wp_error($remote)) {
                return false;
            }

            $plugin_info = json_decode(wp_remote_retrieve_body($remote));
            if (!$plugin_info) {
                return false;
            }

            // Armazena as informações por 1 hora
            set_transient($cache_key, $plugin_info, HOUR_IN_SECONDS);

            return $plugin_info;
        }

        public function auto_reactivate_plugin_after_update($upgrader_object, $options) {
            $plugin_file = $this->plugin_dir . '/' . $this->plugin_slug . '.php';

            if ($options['action'] === 'update' &&
                $options['type'] === 'plugin' &&
                in_array($plugin_file, $options['plugins'])
            ) {
                if ($this->plugin_dir !== $this->plugin_slug) {
                    $old_path = WP_PLUGIN_DIR . '/' . $this->plugin_dir;
                    $new_path = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

                    if (rename($old_path, $new_path)) {
                        $plugin_file = $this->plugin_slug . '/' . $this->plugin_slug . '.php';
                    }
                }

                if (!is_plugin_active($plugin_file)) {
                    activate_plugin($plugin_file);
                }
            }
        }

        // Limpa o transient ao recarregar a página
        public function clear_plugin_transients() {
            $cache_key = 'emu_plugin_info_' . $this->plugin_slug;
            delete_transient($cache_key);
        }
    }
}

// Define o slug e os diretórios do plugin
$plugin_dir_unsanitized = basename(__DIR__);
$plugin_slug = $plugin_dir_unsanitized;
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}
$desired_plugin_dir = $plugin_slug;
$self_plugin_dir = $plugin_dir_unsanitized;

// Após instalação/atualização, move o plugin para o diretório desejado
add_filter('upgrader_post_install', function($response, $hook_extra, $result) use ($desired_plugin_dir, $self_plugin_dir, $plugin_slug) {
    // Verifica se o plugin sendo instalado/atualizado é o correto
    $plugin_file = $self_plugin_dir . '/' . $plugin_slug . '.php';
    
    // Se o plugin sendo instalado/atualizado for o desejado
    if (isset($result['plugins']) && in_array($plugin_file, $result['plugins'])) {
        global $wp_filesystem;
        
        $proper_destination = WP_PLUGIN_DIR . '/' . $desired_plugin_dir;
        $current_destination = $result['destination'];
        
        if ($current_destination !== $proper_destination) {
            $wp_filesystem->move($current_destination, $proper_destination);
            $result['destination'] = $proper_destination;
        }
    }
    
    return $response;
}, 10, 3);

// Após atualização, renomeia o diretório (se necessário) e reativa o plugin
add_action('upgrader_process_complete', function($upgrader_object, $options) use ($self_plugin_dir, $desired_plugin_dir, $plugin_slug) {
    $plugin_file = $self_plugin_dir . '/' . $plugin_slug . '.php';
    
    if (isset($options['action'], $options['type']) && 
        $options['action'] === 'update' && 
        $options['type'] === 'plugin' && 
        in_array($plugin_file, $options['plugins'])) {
        
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