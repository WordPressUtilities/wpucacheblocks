<?php

/*
Plugin Name: WPU Cache Blocks
Description: Cache blocks
Version: 0.9.0
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUCacheBlocks {
    private $version = '0.9.0';
    private $blocks = array();
    private $cached_blocks = array();
    private $reload_hooks = array();
    private $cache_dir = '';
    private $cache_file = '';
    private $cache_type = 'file';
    private $cache_prefix = 'wpucacheblocks_';
    private $base_cache_prefix = 'wpucacheblocks_';
    private $cache_types = array('file', 'apc');
    private $options = array();
    private $languages = array();
    private $current_block = false;
    private $current_lang = false;

    public function __construct() {
        add_action('plugins_loaded', array(&$this, 'plugins_loaded'));
        add_action('plugins_loaded', array(&$this, 'set_reload_front'));
        add_action('template_include', array(&$this, 'generate_reload_front'));
    }

    public function plugins_loaded() {
        load_plugin_textdomain('wpucacheblocks', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        // Options
        $this->options = array(
            'basename' => plugin_basename(__FILE__),
            'id' => 'wpucacheblocks',
            'name' => 'WPU Cache Blocks',
            'level' => 'manage_options'
        );

        // Messages
        include 'inc/WPUBaseMessages/WPUBaseMessages.php';
        $this->messages = new \wpucacheblocks\WPUBaseMessages($this->options['id']);

        include 'inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $admin_pages = array(
            'main' => array(
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
            'actions' => array(
                'parent' => 'main',
                'name' => __('Actions', 'wpucacheblocks'),
                'page_title' => sprintf(__('%s - Actions', 'wpucacheblocks'), $this->options['name']),
                'settings_link' => true,
                'settings_name' => 'Settings',
                'function_content' => array(&$this,
                    'page_content__actions'
                ),
                'function_action' => array(&$this,
                    'page_action__actions'
                )
            )
        );

        // Init admin page
        $this->adminpages = new \wpucacheblocks\WPUBaseAdminPage();
        $this->adminpages->init($this->options, $admin_pages);

        // Settings
        $this->languages = get_available_languages();
        if (!in_array('en_US', $this->languages)) {
            $this->languages[] = 'en_US';
        }
        $this->check_cache_conf();
        $this->blocks = $this->load_blocks_list();
        foreach ($this->reload_hooks as $hook) {
            add_action($hook, array(&$this, 'reload_hooks'));
        }
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
        $this->cache_type = apply_filters('wpucacheblocks_cachetype', $this->cache_type);
        if (!in_array($this->cache_type, $this->cache_types)) {
            $this->cache_type = $this->cache_types[0];
        }

        /* If APC, check if enabled */
        if ($this->cache_type == 'apc' && (!extension_loaded('apc') || !ini_get('apc.enabled'))) {
            $this->cache_type = $this->cache_types[0];
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
            if (in_array($current_filter, $block['reload_hooks'])) {
                $this->save_block_in_cache($id);
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

        $prefix = '';
        if ($lang !== false) {
            $prefix = $lang . '__';
        }

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

        switch ($this->cache_type) {
        case 'apc':
            apc_store($this->cache_prefix . $prefix . $id, $content, $expires);
            break;
        default:
            $cache_file = str_replace('#id#', $prefix . $id, $this->cache_file);
            if (file_exists($cache_file)) {
                unlink($cache_file);
            }
            file_put_contents($cache_file, $content);
        }

        return $content;
    }

    /**
     * Get cached block content if available
     * @param  string $id    ID of the block.
     * @param  mixed  $lang  false|string : Current language.
     * @return mixed         false|string : false if invalid cache, string of cached content if valid.
     */
    public function get_cache_content($id = '', $lang = false) {

        $prefix = '';
        if ($lang !== false) {
            $prefix = $lang . '__';
        }

        switch ($this->cache_type) {
        case 'apc':
            return apc_fetch($this->cache_prefix . $prefix . $id);
            break;
        default:
            $cache_file = str_replace('#id#', $prefix . $id, $this->cache_file);

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
     * @return mixed        false|int : false if invalid cache, int: expiration date if valid.
     */
    public function get_cache_expiration($id = '') {

        if ($this->blocks[$id]['expires'] === false) {
            return false;
        }

        switch ($this->cache_type) {
        case 'apc':

            $cache_info = apc_cache_info();
            if (!is_array($cache_info) || !isset($cache_info['cache_list'])) {
                return;
            }

            foreach ($cache_info['cache_list'] as $cache_item) {
                if ($cache_item['info'] == $this->cache_prefix . $id) {
                    return $this->blocks[$id]['expires'] - time() + $cache_item['mtime'];
                }
            }

            break;
        default:
            $cache_file = str_replace('#id#', $id, $this->cache_file);

            /* Cache does not exists */
            if (!file_exists($cache_file)) {
                return false;
            }

            return $this->blocks[$id]['expires'] - time() + filemtime($cache_file);

        }

        return false;
    }

    /**
     * Get content of the block, cached or regenerate
     * @param  string  $id      ID of the block.
     * @param  boolean $reload  Force regeneration of this block.
     * @return string           Content of the block.
     */
    public function get_block_content($id, $reload = false) {
        if (!isset($this->blocks[$id])) {
            return '';
        }

        if (apply_filters('wpucacheblocks_bypass_cache', false, $id)) {
            return wpucacheblocks_load_html_block_content($this->blocks[$id]['fullpath']);
        }

        if (!$reload) {

            // Cache has already been called on this page
            if (isset($this->cached_blocks[$id])) {
                return $this->cached_blocks[$id];
            }

            // Get cached version if exists
            $content = $this->get_cache_content($id);
            if ($content !== false) {
                $this->cached_blocks[$id] = $content;
                return $content;
            }
        }

        // Save cache
        $content = $this->save_block_in_cache($id);

        return $content;
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

        echo '<h3>Blocks</h3>';
        foreach ($this->blocks as $id => $block) {
            echo '<p>';
            echo '<strong>' . $id . ' - ' . $block['path'] . '</strong><br />';
            $_expiration = (is_bool($block['expires']) ? __('never', 'wpucacheblocks') : $block['expires'] . 's');
            echo sprintf(__('Expiration: %s', 'wpucacheblocks'), $_expiration);
            $cache_status = $this->get_cache_content($id);
            if ($cache_status !== false) {
                echo '<br />' . __('Block is cached.', 'wpucacheblocks');
                $cache_expiration = $this->get_cache_expiration($id);
                if ($cache_expiration !== false) {
                    echo '<span style="display:block;" data-wpucacheblockscounterempty="' . __('Cache has expired', 'wpucacheblocks') . '">' . sprintf(__('Cache expires in %ss.', 'wpucacheblocks'), '<span data-wpucacheblockscounter="' . $cache_expiration . '">' . $cache_expiration . '</span>') . '</span>';
                }
            } else {
                echo '<br />' . __('Block is not cached.', 'wpucacheblocks');
            }
            echo '<br /><br />';
            if (!apply_filters('wpucacheblocks_bypass_cache', false, $id)) {
                submit_button(__('Reload', 'wpucacheblocks'), 'secondary', 'reload__' . $id, false);
            } else {
                echo __('A bypass exists for this block. Regeneration is only available in front mode.', 'wpucacheblocks');
            }
            echo '</p>';
            echo '<hr />';
        }
    }

    public function page_action__main() {
        foreach ($this->blocks as $id => $block) {
            if (isset($_POST['reload__' . $id])) {
                $this->save_block_in_cache($id);
                $this->messages->set_message('rebuilt_cache__' . $id, sprintf(__('Cache for the block "%s" has been rebuilt.', 'wpucacheblocks'), $id));
            }
        }
    }

    public function admin_enqueue_scripts() {
        wp_enqueue_script('wpucacheblocks-main', plugins_url('/assets/main.js', __FILE__), array(
            'jquery'
        ), $this->version, true);
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
function wpucacheblocks_block($block_id = '') {
    global $WPUCacheBlocks;
    return $WPUCacheBlocks->get_block_content($block_id);
}
