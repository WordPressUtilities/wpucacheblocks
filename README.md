# WPU Cache Blocks

Cache Blocks

## How to setup blocks.

```php
add_filter('wpucacheblocks_blocks', 'demo_wpucacheblocks_blocks', 10, 1);
function demo_wpucacheblocks_blocks($blocks) {
    $blocks['headersearch'] = array(
        'path' => '/tpl/header/searchform.php'
    );
    return $blocks;
}
```

### Global settings :

#### filter : wpucacheblocks_cacheprefix (string: default wpucacheblocks_)

String used to prefix cache keys & cache filenames (only [a-z0-9_] chars). Default : *wpucacheblocks_*.

#### filter : wpucacheblocks_cachetype (string: default file)

Cache method. You can choose between 'file' and 'apc'

#### filter : wpucacheblocks_cachedir (string: default wp-content/uploads/wpucacheblocks/)

Absolute path to the file cache dir.

### Settings per block :

#### path (string)

Path to the file, relative to the Stylesheet Directory.
You can also use 'fullpath', which is an absolute path to the file that will be cached.

#### expires (int: default 3600)

Duration in seconds after which the block will be refreshed.
If 0, the block will never be refreshed in front, only via a hook.

#### reload_hooks (array)

An array of hooks that will trigger a refresh for the block.

## How to display a block content.

```php
echo wpucacheblocks_block('headersearch');
```

## Custom function to allow plugin deactivation :

```php
function custom_wpucacheblocks_block($blockid) {
    if (function_exists('wpucacheblocks_block')) {
        return wpucacheblocks_block($blockid);
    }
    $blocks = apply_filters('wpucacheblocks_blocks', array());
    if (!is_array($blocks) || !isset($blocks[$blockid], $blocks[$blockid]['path'])) {
        return '';
    }
    ob_start();
    include $blocks[$blockid]['path'];
    return ob_get_clean();
}
echo custom_wpucacheblocks_block('headersearch');
```
