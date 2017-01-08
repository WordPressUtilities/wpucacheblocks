<?php

/*
Plugin Name: WPU Cache Blocks
Description: Cache blocks
Version: 0.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUCacheBlocks {
    private $blocks = array();
    private $cached_blocks = array();
    private $reload_hooks = array();
    private $cache_dir = array();

    public function __construct() {
        add_action('wp_loaded', array(&$this, 'wp_loaded'));
    }

    public function wp_loaded() {
        $this->check_cache_dir();
        $this->blocks = $this->load_blocks_list();
        foreach ($this->reload_hooks as $hook) {
            add_action($hook, array(&$this, 'reload_hooks'));
        }
    }

    /**
     * Check if cache dir exists
     * @return void
     */
    public function check_cache_dir() {
        $upload_dir = wp_upload_dir();
        $this->cache_dir = apply_filters('wpucacheblocks_cachedir', $upload_dir['basedir'] . '/wpucacheblocks/');
        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755);
            @chmod($this->cache_dir, 0755);
            @file_put_contents($this->cache_dir . '.htaccess', 'deny from all');
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
    public function save_block_in_cache($id = '', $content = '') {
        $cache_file = $this->cache_dir . 'cache-' . $id . '.txt';
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }
        file_put_contents($cache_file, $content);
    }

    /**
     * Get cached block content if available
     * @param  string $id   ID of the block.
     * @return mixed        false|string : false if invalid cache, string of cached content if valid.
     */
    public function get_cache_content($id = '') {
        $cache_file = $this->cache_dir . 'cache-' . $id . '.txt';

        /* Cache does not exists */
        if (!file_exists($cache_file)) {
            return false;
        }

        /* Cache is expired */
        if ($this->blocks[$id]['expires'] !== false && filemtime($cache_file) + $this->blocks[$id]['expires'] < time()) {
            return false;
        }

        /* Return cache content */
        ob_start();
        include $cache_file;
        return ob_get_clean();
    }

    /**
     * Get content of the block, cached or regenerate
     * @param  string  $id      ID of the block.
     * @param  boolean $reload  Force regeneration of this block.
     * @return string           Content of the block.
     */
    public function get_block_content($id, $reload = false) {

        if (!$reload) {

            if (isset($this->cached_blocks[$id])) {
                return $this->cached_blocks[$id];
            }

            $content = $this->get_cache_content($id);
            if ($content !== false) {
                $this->cached_blocks[$id] = $content;
                return $content;
            }
        }

        // Get block content
        ob_start();
        include $this->blocks[$id]['fullpath'];
        $content = ob_get_clean();

        // Save cache
        $this->save_block_in_cache($id, $content);
        $this->cached_blocks[$id] = $content;

        return $content;

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
