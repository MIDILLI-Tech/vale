<?php

declare(strict_types=1);

/**
 * Canonical admin sidebar navigation definition.
 *
 * Structure:
 *   id          – unique slug, used as sort key
 *   label       – i18n key passed through __()
 *   type        – 'group' | 'link' | 'collapsible'
 *   href        – URL (links only)
 *   icon        – SVG <path d="…"> string (links + collapsible button icon)
 *   children    – array of link items (collapsible only)
 *
 * Items at the top level are ordered sections / lone links.
 * The saved order JSON stores only the top-level IDs.
 * Children of a collapsible stay together. Core and plugin children can be
 * reordered inside their own group, but cannot move to another group.
 */

return [
    // ── Ungrouped top items ──────────────────────────────────────────
    [
        'id'         => 'dashboard',
        'type'       => 'link',
        'label'      => 'Dashboard',
        'href'       => '/admin',
        'icon'       => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4',
        'permission' => null,
    ],
    [
        'id'         => 'users',
        'type'       => 'link',
        'label'      => 'Users',
        'href'       => '/admin/users',
        'icon'       => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        'permission' => 'users.list',
    ],

    // ── Analytics ────────────────────────────────────────────────────
    [
        'id'         => 'analytics',
        'type'       => 'link',
        'label'      => 'Analytics',
        'href'       => '/admin/analytics',
        'icon'       => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'permission' => 'settings.view',
    ],

    // ── Content ──────────────────────────────────────────────────────
    [
        'id'         => 'group_content',
        'type'       => 'group',
        'label'      => 'Content',
        'permission' => 'posts.list',
        'children'   => [
            [
                'id'         => 'pages',
                'type'       => 'link',
                'label'      => 'Pages',
                'href'       => '/admin/pages',
                'icon'       => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                'permission' => 'pages.list',
            ],
            [
                'id'         => 'posts',
                'type'       => 'link',
                'label'      => 'Posts',
                'href'       => '/admin/posts',
                'icon'       => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z',
                'permission' => 'posts.list',
            ],
            [
                'id'         => 'categories',
                'type'       => 'link',
                'label'      => 'Categories',
                'href'       => '/admin/categories',
                'icon'       => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
                'permission' => 'posts.list',
            ],
            [
                'id'         => 'tags',
                'type'       => 'link',
                'label'      => 'Tags',
                'href'       => '/admin/tags',
                'icon'       => 'M7 20l4-16m2 16l4-16M6 9h14M4 15h14',
                'permission' => 'posts.list',
            ],
            [
                'id'         => 'media',
                'type'       => 'link',
                'label'      => 'Media',
                'href'       => '/admin/media',
                'icon'       => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
                'permission' => 'media.list',
            ],
        ],
    ],

    // ── System ───────────────────────────────────────────────────────
    [
        'id'         => 'group_system',
        'type'       => 'group',
        'label'      => 'System',
        'permission' => 'settings.view',
        'children'   => [
            [
                'id'         => 'settings',
                'type'       => 'link',
                'label'      => 'Settings',
                'href'       => '/admin/settings',
                'icon'       => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
                'permission' => 'settings.view',
            ],
            [
                'id'         => 'menus',
                'type'       => 'link',
                'label'      => 'Menus',
                'href'       => '/admin/menus',
                'icon'       => 'M4 6h16M4 12h16M4 18h16',
                'permission' => 'settings.view',
            ],
            [
                'id'         => 'plugins',
                'type'       => 'link',
                'label'      => 'Plugins',
                'href'       => '/admin/plugins',
                'icon'       => 'M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z',
                'permission' => 'system.plugins',
            ],
            [
                'id'         => 'themes',
                'type'       => 'link',
                'label'      => 'Themes',
                'href'       => '/admin/themes',
                'icon'       => 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01',
                'permission' => 'system.themes',
            ],
            [
                'id'         => 'fonts',
                'type'       => 'link',
                'label'      => 'Fonts',
                'href'       => '/admin/appearance/fonts',
                'icon'       => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                'permission' => 'system.themes',
            ],
        ],
    ],

    // ── Comments ─────────────────────────────────────────────────────
    [
        'id'         => 'group_comments',
        'type'       => 'group',
        'label'      => 'Comments',
        'permission' => 'posts.list',
        'children'   => [
            [
                'id'         => 'comments',
                'type'       => 'link',
                'label'      => 'Comments',
                'href'       => '/admin/comments?entity_type=post',
                'icon'       => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3v-3z',
                'permission' => 'posts.list',
            ],
            [
                'id'         => 'reviews',
                'type'       => 'link',
                'label'      => 'Reviews',
                'href'       => '/admin/comments?entity_type=product',
                'icon'       => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
                'permission' => 'posts.list',
            ],
        ],
    ],

    // ── Shop ─────────────────────────────────────────────────────────
    [
        'id'         => 'group_shop',
        'type'       => 'group',
        'label'      => 'Shop',
        'permission' => 'products.list',
        'children'   => [
            [
                'id'         => 'products',
                'type'       => 'link',
                'label'      => 'Products',
                'href'       => '/admin/products',
                'icon'       => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
                'permission' => 'products.list',
            ],
            [
                'id'         => 'subscriptions',
                'type'       => 'link',
                'label'      => 'Membership Plans',
                'href'       => '/admin/membership/plans',
                'icon'       => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                'permission' => 'products.list',
            ],
            [
                'id'         => 'product_categories',
                'type'       => 'link',
                'label'      => 'Categories',
                'href'       => '/admin/product-categories',
                'icon'       => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
                'permission' => 'products.list',
            ],
            [
                'id'         => 'orders',
                'type'       => 'link',
                'label'      => 'Orders',
                'href'       => '/admin/orders',
                'icon'       => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
                'permission' => 'orders.list',
            ],
            [
                'id'         => 'orders_reports',
                'type'       => 'link',
                'label'      => 'Reports',
                'href'       => '/admin/orders/reports',
                'icon'       => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                'permission' => 'orders.list',
            ],
            [
                'id'         => 'coupons',
                'type'       => 'link',
                'label'      => 'Coupons',
                'href'       => '/admin/coupons',
                'icon'       => 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
                'permission' => 'products.list',
            ],
        ],
    ],

    // ── Forms ────────────────────────────────────────────────────────
    [
        'id'         => 'group_forms',
        'type'       => 'group',
        'label'      => 'Forms',
        'permission' => 'forms.manage',
        'children'   => [
            [
                'id'         => 'forms',
                'type'       => 'link',
                'label'      => 'Forms',
                'href'       => '/admin/forms',
                'icon'       => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                'permission' => 'forms.manage',
            ],
        ],
    ],

    // ── Newsletter ───────────────────────────────────────────────────
    [
        'id'         => 'group_newsletter',
        'type'       => 'group',
        'label'      => 'Newsletter',
        'permission' => 'newsletter.manage',
        'children'   => [
            [
                'id'         => 'subscribers',
                'type'       => 'link',
                'label'      => 'Subscribers',
                'href'       => '/admin/newsletter/subscribers',
                'icon'       => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
                'permission' => 'newsletter.manage',
            ],
            [
                'id'         => 'campaigns',
                'type'       => 'link',
                'label'      => 'Campaigns',
                'href'       => '/admin/newsletter/campaigns',
                'icon'       => 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z',
                'permission' => 'newsletter.manage',
            ],
            [
                'id'         => 'automations',
                'type'       => 'link',
                'label'      => 'Automations',
                'href'       => '/admin/newsletter/automations',
                'icon'       => 'M13 10V3L4 14h7v7l9-11h-7z',
                'permission' => 'newsletter.manage',
            ],
        ],
    ],
];
