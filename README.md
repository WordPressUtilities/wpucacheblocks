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

## How to display a block content.


```php
echo wpucacheblocks_block('headersearch');
```
