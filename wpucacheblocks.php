<?php

/*
Plugin Name: WPU Cache Blocks
Description: Cache blocks
Version: 1.1.0
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUCacheBlocks {
    private $version = '1.1.0';
    private $blocks = array();
    private $cached_blocks = array();
    private $reload_hooks = array();
    private $cache_dir = '';
    private $cache_file = '';
    private $cache_type = 'file';
    private $cache_prefix = 'wpucacheblocks_';
    private $base_cache_prefix = 'wpucacheblocks_';
    private $cache_types = array('file', 'apc', 'wp');
    private $options = array();
    private $languages = array();
    private $current_block = false;
    private $current_lang = false;

    public function __construct() {
        add_action('plugins_loaded', array(&$this, 'plugins_loaded__main'));
        add_action('plugins_loaded', array(&$this, 'plugins_loaded'));
        add_action('plugins_loaded', array(&$this, 'set_reload_front'));
        add_action('template_include', array(&$this, 'generate_reload_front'));
        add_action('wpucacheblocks_clearcache', array(&$this, 'clear_cache'));
    }

    public function plugins_loaded__main() {

        load_plugin_textdomain('wpucacheblocks', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        // Options
        $this->options = array(
            'basename' => plugin_basename(__FILE__),
            'id' => 'wpucacheblocks',
            'plugin_id' => 'wpucacheblocks',
            'name' => 'WPU Cache Blocks',
            'level' => 'manage_options'
        );

        ## INIT ##
        $this->settings_details = array(
            # Default
            'plugin_id' => 'wpucacheblocks',
            'option_id' => 'wpucacheblocks_options',
            'user_cap' => 'manage_options',
            'sections' => array(
                'settings' => array(
                    'name' => __('Settings', 'wpucacheblocks')
                )
            )
        );
        $this->settings = array(
            'disable' => array(
                'label' => __('Disable', 'wpucacheblocks'),
                'help' => __('Disable cache blocks for everyone.', 'wpucacheblocks'),
                'type' => 'select'
            ),
            'disable_admins' => array(
                'label' => __('Disable for admins', 'wpucacheblocks'),
                'help' => __('Disable cache blocks for users with an administrator level.', 'wpucacheblocks'),
                'type' => 'select'
            )
        );

        // Settings
        $this->cache_type = apply_filters('wpucacheblocks_cachetype', $this->cache_type);
        $this->languages = get_available_languages();
        $this->check_cache_conf();
        $this->blocks = $this->load_blocks_list();
        foreach ($this->reload_hooks as $hook) {
            add_action($hook, array(&$this, 'reload_hooks'));
        }

        $config = get_option($this->settings_details['option_id']);
        if (!is_array($config)) {
            $config = array();
        }
        $this->config = apply_filters('wpucacheblocks_config', $config);
        $this->config__disable = (isset($this->config['disable']) && $this->config['disable'] == '1');
        $this->config__disable_admins = (isset($this->config['disable_admins']) && $this->config['disable_admins'] == '1');

    }

    public function plugins_loaded() {

        // Messages
        include 'inc/WPUBaseMessages/WPUBaseMessages.php';
        $this->messages = new \wpucacheblocks\WPUBaseMessages($this->options['id']);

        if (!is_admin()) {
            return;
        }

        include 'inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $admin_pages = array(
            'main' => array(
                'icon_url' => 'dashicons-tagcloud',
                'menu_name' => __('Cache blocks', 'wpucacheblocks'),
                'page_title' => sprintf(__('%s - Block list', 'wpucacheblocks'), $this->options['name']),
                'name' => __('Block list', 'wpucacheblocks'),
                'function_content' => array(&$this,
                    'page_content__main'
                ),
                'function_action' => array(&$this,
                    'page_action__main'
                ),
                'actions' => array(
                    array('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'))
                )
            ),
            'settings' => array(
                'parent' => 'main',
                'name' => __('Settings', 'wpucacheblocks'),
                'page_title' => sprintf(__('%s - Settings', 'wpucacheblocks'), $this->options['name']),
                'has_form' => false,
                'settings_link' => true,
                'settings_name' => __('Settings', 'wpucacheblocks'),
                'function_content' => array(&$this,
                    'page_content__settings'
                )
            ),
            'actions' => array(
                'parent' => 'main',
                'name' => __('Actions', 'wpucacheblocks'),
                'page_title' => sprintf(__('%s - Actions', 'wpucacheblocks'), $this->options['name']),
                'settings_link' => true,
                'settings_name' => __('Actions', 'wpucacheblocks'),
                'function_content' => array(&$this,
                    'page_content__actions'
                ),
                'function_action' => array(&$this,
                    'page_action__actions'
                )
            )
        );

        include 'inc/WPUBaseSettings/WPUBaseSettings.php';
        $settings_obj = new \wpucacheblocks\WPUBaseSettings($this->settings_details, $this->settings);

        ## if no auto create_page and medias ##
        if (isset($_GET['page']) && $_GET['page'] == 'wpucacheblocks-main') {
            add_action('admin_init', array(&$settings_obj, 'load_assets'));
        }

        // Init admin page
        $this->adminpages = new \wpucacheblocks\WPUBaseAdminPage();
        $this->adminpages->init($this->options, $admin_pages);

    }

    /**
     * Check cache config
     * @return void
     */
    public function check_cache_conf() {

        $this->cache_prefix = 'site' . get_current_blog_id() . '_' . strtolower(apply_filters('wpucacheblocks_cacheprefix', $this->cache_prefix));
        $this->base_cache_prefix = $this->cache_prefix;
        // $this->cache_prefix = strtolower(get_locale()) . '_' . $this->cache_prefix;
        $this->cache_prefix = preg_replace("/[^a-z0-9_]/", '', $this->cache_prefix);

        /* Config cache type */
        if (!in_array($this->cache_type, $this->cache_types)) {
            $this->cache_type = $this->cache_types[0];
        }

        /* If WP, check if a plugin is installed */
        if ($this->cache_type == 'wp' && !wp_using_ext_object_cache()) {
            error_log('[WPUCACHEBLOCKS] You need to install a WP Cache extension to use this cache method.');
            $this->cache_type = $this->cache_types[0];
        }

        /* If APC, check if enabled */
        if ($this->cache_type == 'apc' && (!extension_loaded('apc') || !ini_get('apc.enabled'))) {
            $this->cache_type = $this->cache_types[0];
            error_log('[WPUCACHEBLOCKS] You need to install APC to use this cache method.');
        }

        /* Check if cache dir exists */
        if ($this->cache_type == 'file') {
            $upload_dir = wp_upload_dir();
            $this->cache_dir = apply_filters('wpucacheblocks_cachedir', $upload_dir['basedir'] . '/wpucacheblocks/');
            $this->cache_file = $this->cache_dir . $this->cache_prefix . '#id#.txt';
            if (!is_dir($this->cache_dir)) {
                @mkdir($this->cache_dir, 0755);
                @chmod($this->cache_dir, 0755);
                @file_put_contents($this->cache_dir . '.htaccess', 'deny from all');
            }
        }
    }

    public function reload_hooks() {
        $current_filter = current_filter();
        foreach ($this->blocks as $id => $block) {
            /* If block should be reloaded at current filter */
            if (!in_array($current_filter, $block['reload_hooks'])) {
                continue;
            }
            /* A Callback is available : just delete this block */
            if (isset($this->blocks[$id]['callback_prefix'])) {
                $this->clear_block_from_cache($id);
            } else {
                $this->save_block_in_cache($id);
            }
        }
    }

    public function clear_block_from_cache($id) {
        if (!isset($this->blocks[$id])) {
            return;
        }

        $prefixes = $this->get_block_prefixes($id);

        foreach ($prefixes as $prefix) {
            $cache_key = $this->get_cache_block_id($prefix['prefix'], $id);
            switch ($this->cache_type) {
            case 'apc':
                apc_delete($cache_key);
                break;
            case 'wp':
                wp_cache_delete($cache_key);
                break;
            default:
                if (file_exists($cache_key)) {
                    unlink($cache_key);
                }
            }
        }
    }

    public function clear_cache() {
        switch ($this->cache_type) {
        case 'apc':
            /* Get list of cached blocks */
            $cache_info = apc_cache_info();
            if (!is_array($cache_info) || !isset($cache_info['cache_list'])) {
                return;
            }
            $prefix_len = strlen($this->base_cache_prefix);
            foreach ($cache_info['cache_list'] as $item) {
                /* Delete block cache */
                if (substr($item['info'], 0, $prefix_len) == $this->base_cache_prefix) {
                    apc_delete($item['info']);
                }
            }
            break;

        case 'wp':
            foreach ($this->blocks as $id => $block) {
                $this->clear_block_from_cache($id);
            }
            global $wp_object_cache;
            foreach ($wp_object_cache->cache as $key => $group) {
                if (strpos($key, 'wpucacheblocks') !== false) {
                    $cache_key = end(explode(':', $key));
                    wp_cache_delete($cache_key);
                }
            }
            break;
        default:
            /* Get list of all files in cache */
            $excluded_files = array('.', '..', '.htaccess');
            $cache_files = scandir($this->cache_dir);
            foreach ($cache_files as $cache_file) {
                if (in_array($cache_file, $excluded_files)) {
                    continue;
                }
                /* Delete cache file */
                unlink($this->cache_dir . $cache_file);
            }
        }
    }

    /**
     * Load block lists from a WordPress hook.
     * @return array  blocks list.
     */
    public function load_blocks_list() {
        $tmp_blocks = apply_filters('wpucacheblocks_blocks', array());
        $blocks = array();
        foreach ($tmp_blocks as $id => $block) {
            /* Path ex : /tpl/header/block.php */
            if (!isset($block['fullpath']) && !isset($block['path'])) {
                /* A path should always be defined */
                continue;
            }
            /* Full path to the file */
            if (!isset($block['fullpath'])) {
                $block['fullpath'] = get_stylesheet_directory() . $block['path'];
            }

            /* Reload hooks */
            if (!isset($block['reload_hooks']) || !is_array($block['reload_hooks'])) {
                $block['reload_hooks'] = array();
            } else {
                /* Keep a list of all hooks used */
                foreach ($block['reload_hooks'] as $hook) {
                    $this->reload_hooks[$hook] = $hook;
                }
            }

            if (!isset($block['minify'])) {
                $block['minify'] = true;
            }

            /* Expiration time */
            if (!isset($block['expires'])) {
                $block['expires'] = 3600;
            } else {
                /* Allow infinite lifespan for a cached block ( no front reload ) */
                if ($block['expires'] == 0) {
                    $block['expires'] = false;
                }
            }
            $blocks[$id] = $block;
        }
        return $blocks;
    }

    /**
     * Save block in cache
     * @param string $id     ID of the block.
     * @param mixed  $lang   false|string : Current language.
     */
    public function save_block_in_cache($id = '', $lang = false) {

        if (!isset($this->blocks[$id])) {
            return '';
        }

        if ($lang === false) {
            $lang = get_locale();
        }

        $prefix = $this->get_current_block_prefix($id, $lang);

        $expires = $this->blocks[$id]['expires'];

        // Get original block content
        $content = wpucacheblocks_load_html_block_content($this->blocks[$id]['fullpath']);

        if (apply_filters('wpucacheblocks_bypass_cache', false, $id)) {
            return $content;
        }

        // Keep cache content if needs to be reused
        $this->cached_blocks[$id] = $content;
        if ($this->blocks[$id]['minify']) {
            // Remove multiple spaces
            $content = preg_replace('! {2,}!', ' ', $content);
            // Trim each line
            $content = implode("\n", array_map('trim', explode("\n", $content)));
        }

        $cache_id = $this->get_cache_block_id($prefix, $id);
        switch ($this->cache_type) {
        case 'apc':
            apc_store($cache_id, $content, $expires);
            break;
        case 'wp':
            wp_cache_add($cache_id, $content, '', $expires);
            break;
        default:
            if (file_exists($cache_id)) {
                unlink($cache_id);
            }
            file_put_contents($cache_id, $content);
        }

        return $content;
    }

    /**
     * Get the ID for a cache block
     * @param  string $prefix   Prefix for this block
     * @param  string $id       ID of this block.
     * @return string           APC ID or cache file.
     */
    public function get_cache_block_id($prefix, $id) {
        switch ($this->cache_type) {
        case 'wp':
        case 'apc':
            return $this->cache_prefix . $prefix . $id;
            break;
        }
        return str_replace('#id#', $prefix . $id, $this->cache_file);
    }

    /**
     * Get cached block content if available
     * @param  string $id    ID of the block.
     * @param  mixed  $lang  false|string : Current language.
     * @return mixed         false|string : false if invalid cache, string of cached content if valid.
     */
    public function get_cache_content($id = '', $lang = false, $manual_callback = false) {

        if (!isset($this->blocks[$id])) {
            return '';
        }

        $prefix = $this->get_current_block_prefix($id, $lang, $manual_callback);

        switch ($this->cache_type) {
        case 'apc':
            return apc_fetch($this->get_cache_block_id($prefix, $id));
            break;
        case 'wp':
            return wp_cache_get($this->get_cache_block_id($prefix, $id));
            break;
        default:
            $cache_file = $this->get_cache_block_id($prefix, $id);
            /* Cache does not exists */
            if (!file_exists($cache_file)) {
                return false;
            }

            /* Cache is expired */
            if ($this->blocks[$id]['expires'] !== false && filemtime($cache_file) + $this->blocks[$id]['expires'] < time()) {
                return false;
            }

            /* Return cache content */
            return file_get_contents($cache_file);
        }

        return false;
    }

    /**
     * Get cache expiration date
     * @param  string $id   ID of the block.
     * @param  mixed  $lang  false|string : Current language.
     * @return mixed        false|int : false if invalid cache, int: expiration date if valid.
     */
    public function get_cache_expiration($id = '', $lang = false, $manual_callback = false) {

        if ($this->blocks[$id]['expires'] === false) {
            return false;
        }

        $prefix = $this->get_current_block_prefix($id, $lang, $manual_callback);
        $cache_id = $this->get_cache_block_id($prefix, $id);

        switch ($this->cache_type) {
        case 'apc':

            $cache_info = apc_cache_info();
            if (!is_array($cache_info) || !isset($cache_info['cache_list'])) {
                return;
            }

            foreach ($cache_info['cache_list'] as $cache_item) {
                if ($cache_item['info'] == $cache_id) {
                    return $this->blocks[$id]['expires'] - time() + $cache_item['mtime'];
                }
            }

            break;
        default:

            /* Cache does not exists */
            if (!file_exists($cache_id)) {
                return false;
            }

            return $this->blocks[$id]['expires'] - time() + filemtime($cache_id);

        }

        return false;
    }

    /**
     * Get content of the block, cached or regenerate
     * @param  string  $id      ID of the block.
     * @param  mixed   $lang    false|string : Current language.
     * @param  boolean $reload  Force regeneration of this block.
     * @return string           Content of the block.
     */
    public function get_block_content($id, $lang = false, $reload = false) {
        if (!isset($this->blocks[$id])) {
            return '';
        }

        if ($lang === false) {
            $lang = get_locale();
        }

        $prefix = $this->get_current_block_prefix($id, $lang);

        $bypass_cache = false;

        // Disable if setting
        if ($this->config__disable) {
            $bypass_cache = true;
        }

        // Disable if admin & setting
        if ($this->config__disable_admins && current_user_can('administrator')) {
            $bypass_cache = true;
        }

        if (apply_filters('wpucacheblocks_bypass_cache', false, $id)) {
            $bypass_cache = true;
        }

        if ($bypass_cache) {
            return wpucacheblocks_load_html_block_content($this->blocks[$id]['fullpath']);
        }

        if (!$reload) {

            // Cache has already been called on this page
            if (isset($this->cached_blocks[$prefix . $id])) {
                return $this->cached_blocks[$prefix . $id];
            }

            // Get cached version if exists
            $content = $this->get_cache_content($id, $lang);
            if ($content !== false) {
                $this->cached_blocks[$prefix . $id] = $content;
                return $content;
            }
        }

        // Save cache
        $content = $this->save_block_in_cache($id, $lang);

        return $content;
    }

    /* ----------------------------------------------------------
      Prefix
    ---------------------------------------------------------- */

    /**
     * Get block prefix
     * @param  string $id     ID of the block.
     * @param  mixed  $lang   false|string : Current language.
     * @return string         Prefix
     */
    public function get_current_block_prefix($id = '', $lang = false, $manual_callback = false) {

        if (!isset($this->blocks[$id])) {
            return false;
        }

        $prefix = '';
        if ($lang !== false) {
            $prefix = $lang . '__';
        }

        /* Allow a callback emulation for cache deletion */
        if ($manual_callback === false) {
            if (!is_admin() && isset($this->blocks[$id]['callback_prefix']) && is_callable($this->blocks[$id]['callback_prefix'])) {
                $prefix = call_user_func($this->blocks[$id]['callback_prefix'], $prefix);
            }
        } else {
            $prefix = $manual_callback;
        }

        return $prefix;
    }

    public function get_block_prefixes($id) {
        if (!isset($this->blocks[$id])) {
            return array();
        }

        /* For every callback */
        $callbacks = array(false);

        if (isset($this->blocks[$id]['callback_values'])) {
            $callbacks = $this->blocks[$id]['callback_values'];
        }
        $prefixes = array();
        foreach ($callbacks as $manual_callback) {
            /* For every lang */
            foreach ($this->languages as $lang) {

                $prefix = $this->get_current_block_prefix($id, $lang, $manual_callback);
                $pref_lang = $lang;
                /* Prefix already exists : lang is deleted by the callback so we remove it */
                if (array_key_exists($prefix, $prefixes)) {
                    $pref_lang = '';
                }
                $prefixes[$prefix] = array('prefix' => $prefix, 'lang' => $pref_lang, 'manual_callback' => $manual_callback);
            }
        }

        return $prefixes;
    }

    /* ----------------------------------------------------------
      Reload front
    ---------------------------------------------------------- */

    public function set_reload_front() {
        if (!is_user_logged_in() || !current_user_can($this->options['level'])) {
            return false;
        }
        if (!isset($_GET['wpucache_block']) || !array_key_exists($_GET['wpucache_block'], $this->blocks)) {
            return false;
        }
        $this->current_block = $_GET['wpucache_block'];
        if (!isset($_GET['block_lang']) || !in_array($_GET['block_lang'], $this->languages)) {
            return false;
        }
        $this->current_lang = $_GET['block_lang'];
        add_filter('locale', array(&$this, 'set_current_lang'));
        if (!defined('WPLANG')) {
            define('WPLANG', $this->current_lang);
        }
    }

    public function set_current_lang($locale) {
        if ($this->current_lang !== false) {
            $locale = $this->current_lang;
        }
        return $locale;
    }

    public function generate_reload_front($tpl) {
        if ($this->current_block === false) {
            return $tpl;
        }

        echo $this->save_block_in_cache($this->current_block, $this->current_lang);

        die;
    }

    /* ----------------------------------------------------------
      Admin page
    ---------------------------------------------------------- */

    /* Main
    -------------------------- */

    public function page_content__main() {

        echo '<h3>' . __('Blocks', 'wpucacheblocks') . '</h3>';
        foreach ($this->blocks as $id => $block) {
            echo '<p>';
            echo '<strong>' . $id . ' - ' . $block['path'] . '</strong><br />';
            $_expiration = (is_bool($block['expires']) ? __('never', 'wpucacheblocks') : $block['expires'] . 's');
            echo sprintf(__('Expiration: %s', 'wpucacheblocks'), $_expiration);

            $prefixes = $this->get_block_prefixes($id);

            foreach ($prefixes as $prefix) {
                echo $this->display_block_cache_status($id, $prefix);
            }

            echo '<br />';
            if (!apply_filters('wpucacheblocks_bypass_cache', false, $id)) {
                if (isset($block['callback_prefix'])) {
                    submit_button(__('Clear', 'wpucacheblocks'), 'secondary', 'clear__' . $id, false);
                } else {
                    submit_button(__('Reload', 'wpucacheblocks'), 'secondary', 'reload__' . $id, false);
                }
            } else {
                echo __('A bypass exists for this block. Regeneration is only available in front mode.', 'wpucacheblocks');
            }
            echo '</p>';
            echo '<hr />';
        }
    }

    public function display_block_cache_status($id, $prefix = false) {
        $html_content = '';
        $cache_status = $this->get_cache_content($id, $prefix['lang'], $prefix['manual_callback']);
        $html_content .= $prefix['lang'] . ' ' . $prefix['manual_callback'] . ' : ';
        if ($cache_status !== false) {
            $html_content .= __('Block is cached.', 'wpucacheblocks');

            if ($this->cache_type != 'wp') {
                $cache_expiration = $this->get_cache_expiration($id, $prefix['lang'], $prefix['manual_callback']);
                if ($cache_expiration !== false) {
                    $html_content .= ' <span data-wpucacheblockscounterempty="' . __('Cache has expired', 'wpucacheblocks') . '">' . sprintf(__('Cache expires in %ss.', 'wpucacheblocks'), '<span data-wpucacheblockscounter="' . $cache_expiration . '">' . $cache_expiration . '</span>') . '</span>';
                }
            }
        } else {
            $html_content .= __('Block is not cached.', 'wpucacheblocks');
        }
        return '<span style="display: block;">' . $html_content . '</span>';
    }

    public function page_action__main() {
        foreach ($this->blocks as $id => $block) {
            if (isset($_POST['reload__' . $id])) {
                $this->save_block_in_cache($id);
                $this->messages->set_message('rebuilt_cache__' . $id, sprintf(__('Cache for the block "%s" has been rebuilt.', 'wpucacheblocks'), $id));
            }
            if (isset($_POST['clear__' . $id])) {
                $this->clear_block_from_cache($id);
                $this->messages->set_message('rebuilt_cache__' . $id, sprintf(__('Cache for the block "%s" has been cleared.', 'wpucacheblocks'), $id));
            }
        }
    }

    public function admin_enqueue_scripts() {
        wp_enqueue_script('wpucacheblocks-main', plugins_url('/assets/main.js', __FILE__), array(
            'jquery'
        ), $this->version, true);
    }

    /* Settings
    -------------------------- */

    public function page_content__settings() {
        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpucacheblocks'));
        echo '</form>';
    }

    /* Actions
    -------------------------- */

    public function page_content__actions() {
        submit_button(__('Clear cache', 'wpucacheblocks'), 'primary', 'clear_cache', false);
        echo ' ';
        submit_button(__('Rebuild cache', 'wpucacheblocks'), 'primary', 'rebuild_cache', false);
    }

    public function page_action__actions() {
        if (isset($_POST['clear_cache'])) {
            $this->clear_cache();
            $this->messages->set_message('cleared_cache', __('Cache has been cleared.', 'wpucacheblocks'));
        }
        if (isset($_POST['rebuild_cache'])) {
            foreach ($this->blocks as $id => $block) {
                $this->save_block_in_cache($id);
            }
            $this->messages->set_message('rebuilt_cache', __('Cache has been rebuilt.', 'wpucacheblocks'));
        }
    }

    /* ----------------------------------------------------------
      Activation / Deactivation
    ---------------------------------------------------------- */

    public function activate() {

    }

    public function deactivate() {
        $this->clear_cache();
    }

}

$WPUCacheBlocks = new WPUCacheBlocks();

/* Set activation/deactivation hook */
register_activation_hook(__FILE__, array(&$WPUCacheBlocks,
    'activate'
));
register_deactivation_hook(__FILE__, array(&$WPUCacheBlocks,
    'deactivate'
));

function wpucacheblocks_load_html_block_content($path) {
    ob_start();
    include $path;
    return ob_get_clean();
}

/**
 * Helper function to get the content of a block
 * @param  string $block_id  ID of the block.
 * @return string            Content of the block.
 */
function wpucacheblocks_block($block_id = '', $lang = false) {
    global $WPUCacheBlocks;
    return $WPUCacheBlocks->get_block_content($block_id, $lang);
}
