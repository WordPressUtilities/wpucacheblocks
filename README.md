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
