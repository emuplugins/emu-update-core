<?php

if ( ! defined('ABSPATH')) exit;

if ( ! class_exists('Emu_Updater')) {
    class Emu_Updater {
        private $api_url;
        private $plugin_slug;

        public function __construct($plugin_slug) {
            $this->plugin_slug = $plugin_slug;
            $this->api_url = 'https://raw.githubusercontent.com/emuplugins/' . $this->plugin_slug . '/main/info.json';

            add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
            add_filter('plugins_api', [$this, 'plugin_details'], 10, 3);
        }

        // Check for plugin updates
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

            // Use the correct plugin path to retrieve the current version
            $plugin_file = $this->plugin_slug . '/' . $this->plugin_slug . '.php';
            $current_version = isset($transient->checked[$plugin_file]) ? $transient->checked[$plugin_file] : '0.0.0';

            if (version_compare($update_info['version'], $current_version, '>')) {
                $transient->response[$plugin_file] = (object) [
                    'slug' => $update_info['slug'],
                    'new_version' => $update_info['version'],
                    'package' => $update_info['download_url'],
                    'url' => $update_info['author_homepage']
                ];
            }

            return $transient;
        }

        // Retrieve plugin details
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

            // Create a new stdClass object and add the "plugin" property
            $plugin_details = new stdClass();
            $plugin_details->name = isset($update_info['name']) ? $update_info['name'] : '';
            $plugin_details->slug = isset($update_info['slug']) ? $update_info['slug'] : '';

            // Assign value to the "plugin" property, if not defined in $update_info, use $this->plugin_slug
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

// Get the plugin directory and slug
$plugin_dir_unsanitized = basename(__DIR__);
$plugin_slug = $plugin_dir_unsanitized;
if (substr($plugin_slug, -5) === '-main') {
    $plugin_slug = substr($plugin_slug, 0, -5);
}

$desired_plugin_dir = $plugin_slug;
$self_plugin_dir = $plugin_dir_unsanitized;

// Instance of Emu_Updater
new Emu_Updater($plugin_slug);

// Manage custom plugin updates
if (!function_exists('custom_plugin_update_management')) {
    function custom_plugin_update_management($self_plugin_dir, $plugin_slug, $desired_plugin_dir) {
        // Displays the "Check for Updates" link in the plugin listing
        add_filter('plugin_action_links_' . $self_plugin_dir . '/' . $plugin_slug . '.php', function($actions) use ($self_plugin_dir) {
            $url = wp_nonce_url(admin_url("plugins.php?force-check-update=$self_plugin_dir"), "force_check_update_$self_plugin_dir");
            $actions['check_update'] = '<a href="' . esc_url($url) . '">Check for Updates</a>';
            return $actions;
        });
        // Check if the current plugin is being installed/updated, to prevent interference from other plugins
        add_filter('upgrader_post_install', function($response, $hook_extra, $result) use ($desired_plugin_dir, $self_plugin_dir) {
            global $wp_filesystem;

            // Ensure the current plugin is the one being updated/installed
            if (isset($hook_extra['plugin']) && basename($hook_extra['plugin']) === $self_plugin_dir . '.php') {
                $proper_destination = WP_PLUGIN_DIR . '/' . $desired_plugin_dir;
                $current_destination = $result['destination'];

                // Only move the plugin if it is the one being updated/installed
                if ($current_destination !== $proper_destination) {
                    $wp_filesystem->move($current_destination, $proper_destination);
                    $result['destination'] = $proper_destination;
                }
            }

            return $response;
        }, 10, 3);

        // Handle plugin reactivation after update
        add_action('upgrader_process_complete', function($upgrader_object, $options) use ($self_plugin_dir, $desired_plugin_dir, $plugin_slug) {
            $current_plugin_file = $self_plugin_dir . '/' . $plugin_slug . '.php';

            // Ensure the plugin being processed is the correct one
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
                        error_log('Error renaming the plugin folder.');
                    }
                }

                // Reactivate the plugin only if it is the one being processed
                if (!is_plugin_active($plugin_file)) {
                    $result = activate_plugin($plugin_file);
                    if (is_wp_error($result)) {
                        error_log('Error reactivating the plugin: ' . $result->get_error_message());
                    }
                }
            }
        }, 10, 2);
    }
}

// Function call
custom_plugin_update_management($self_plugin_dir, $plugin_slug, $desired_plugin_dir);