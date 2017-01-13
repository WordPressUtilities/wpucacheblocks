<?php

/*
Plugin Name: WPU Cache Blocks
Description: Cache blocks
Version: 0.5
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUCacheBlocks {
    private $blocks = array();
    private $cached_blocks = array();
    private $reload_hooks = array();
    private $cache_dir = '';
    private $cache_file = '';
    private $cache_type = 'file';
    private $cache_prefix = 'wpucacheblocks_';
    private $base_cache_prefix = 'wpucacheblocks_';
    private $cache_types = array('file', 'apc');
    private $options = array(
        'id' => 'wpucacheblocks',
        'plugin_name' => 'WPU Cache Blocks',
        'plugin_pageslug' => 'wpucacheblocks',
        'plugin_userlevel' => 'manage_options',
        'plugin_id' => 'wpucacheblocks'
    );

    public function __construct() {
        add_action('plugins_loaded', array(&$this, 'plugins_loaded'));
        add_action('wp_loaded', array(&$this, 'wp_loaded'));
    }

    public function plugins_loaded() {

        // Messages
        include 'inc/WPUBaseMessages/WPUBaseMessages.php';
        $this->messages = new \wpucacheblocks\WPUBaseMessages($this->options['plugin_id']);

        // Admin page
        $this->options['admin_url'] = admin_url('options-general.php?page=' . $this->options['plugin_id']);
        add_action('admin_menu', array(&$this,
            'admin_menu'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), array(&$this,
            'add_settings_link'
        ));
        add_action('admin_post_wpucacheblock_postaction', array(&$this,
            'postAction'
        ));

    }

    public function wp_loaded() {
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

        $this->cache_prefix = strtolower(apply_filters('wpucacheblocks_cacheprefix', $this->cache_prefix));
        $this->base_cache_prefix = $this->cache_prefix;
        $this->cache_prefix = $this->cache_prefix . strtolower(get_locale()) . '_';
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
                $this->get_block_content($id, true);
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
     * @param string $id      ID of the block.
     * @param string $content Content that will be cached.
     */
    public function save_block_in_cache($id = '', $content = '', $expires = false) {

        switch ($this->cache_type) {
        case 'apc':
            apc_store($this->cache_prefix . $id, $content, $expires);
            break;
        default:
            $cache_file = str_replace('#id#', $id, $this->cache_file);
            if (file_exists($cache_file)) {
                unlink($cache_file);
            }
            file_put_contents($cache_file, $content);
        }

    }

    /**
     * Get cached block content if available
     * @param  string $id   ID of the block.
     * @return mixed        false|string : false if invalid cache, string of cached content if valid.
     */
    public function get_cache_content($id = '') {

        switch ($this->cache_type) {
        case 'apc':
            return apc_fetch($this->cache_prefix . $id);
            break;
        default:
            $cache_file = str_replace('#id#', $id, $this->cache_file);

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

    }

    /**
     * Get content of the block, cached or regenerate
     * @param  string  $id      ID of the block.
     * @param  boolean $reload  Force regeneration of this block.
     * @return string           Content of the block.
     */
    public function get_block_content($id, $reload = false) {

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

        // Get original block content
        ob_start();
        include $this->blocks[$id]['fullpath'];
        $content = ob_get_clean();

        // Save cache
        $this->save_block_in_cache($id, $content, $this->blocks[$id]['expires']);

        // Keep cache content if needs to be reused
        $this->cached_blocks[$id] = $content;

        return $content;

    }

    /* ----------------------------------------------------------
      Admin page
    ---------------------------------------------------------- */

    public function admin_menu() {
        add_submenu_page('options-general.php', $this->options['plugin_name'] . ' - ' . __('Settings'), $this->options['plugin_name'], $this->options['plugin_userlevel'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ), '', 110);
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . $this->options['admin_url'] . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function admin_settings() {
        echo '<div class="wrap">';
        echo '<h2>' . $this->options['plugin_name'] . '</h2>';

        /* Blocks */
        echo '<h3>Blocks</h3>';
        foreach ($this->blocks as $id => $block) {
            echo '<p>';
            echo '<strong>' . $id . ' - ' . $block['path'] . '</strong><br />';
            $_expiration = (is_bool($block['expires']) ? __('never', 'wpucacheblocks') : $block['expires'] . 's');
            echo sprintf(__('Expiration: %s', 'wpucacheblocks'), $_expiration);
            echo '</p>';
        }

        /* Actions */
        echo '<hr />';
        echo '<h3>Actions</h3>';
        echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
        echo '<input type="hidden" name="action" value="wpucacheblock_postaction">';
        submit_button(__('Clear cache', 'wpucacheblocks'), 'primary', 'clear_cache', false);
        echo ' ';
        submit_button(__('Rebuild cache', 'wpucacheblocks'), 'primary', 'rebuild_cache', false);
        echo '</form>';

        echo '</div>';

    }

    public function postAction() {
        if (isset($_POST['clear_cache'])) {
            $this->clear_cache();
            $this->messages->set_message('cleared_cache', __('Cache has been cleared', 'wpucacheblocks'));
        }
        if (isset($_POST['rebuild_cache'])) {
            foreach ($this->blocks as $id => $block) {
                $this->get_block_content($id, true);
            }
            $this->messages->set_message('rebuilt_cache', __('Cache has been rebuilt', 'wpucacheblocks'));
        }
        wp_safe_redirect(wp_get_referer());
        die();
    }

}

$WPUCacheBlocks = new WPUCacheBlocks();

/**
 * Helper function to get the content of a block
 * @param  string $block_id  ID of the block.
 * @return string            Content of the block.
 */
function wpucacheblocks_block($block_id = '') {
    global $WPUCacheBlocks;
    return $WPUCacheBlocks->get_block_content($block_id);
}
