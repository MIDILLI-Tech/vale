<?php

declare(strict_types=1);

/**
 * Shop / e-commerce configuration.
 */

return [
    'currency'        => 'EUR',
    'currency_symbol' => '€',
    'decimals'        => 2,

    'stock' => [
        'manage'         => true,
        'low_threshold'  => 5,   // Notify admin when stock drops below
    ],

    // How many products the storefront pager fetches per server round-trip
    'buffer_size' => max(1, (int) env('SHOP_BUFFER_SIZE', 50)),

    'checkout' => [
        'guest_enabled' => true,
    ],

    'order_statuses' => [
        'pending'    => 'Pending Payment',
        'processing' => 'Processing',
        'shipped'    => 'Shipped',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
    ],
];
