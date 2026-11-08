<?php

declare(strict_types=1);

/**
 * Vale Translation Preserve List
 *
 * Keys listed here are never removed by `scripts/extract-translations.php --prune`.
 * Missing keys on this list are also added to en.json by the extractor.
 *
 * Add a key ONLY when it is referenced dynamically at runtime, e.g.
 *
 *   {{ __(order.status) }}   {{ __(action.label) }}   {{ __(nt.label) }}
 *
 * so a static scan cannot find it as a `__('literal')` call. Literal usages
 * (`__('Dashboard')`) are detected automatically and do NOT belong here.
 */

return [
    // ── Status values (post, page, product, comment, order, subscriber, campaign, automation) ──
    'active',
    'approved',
    'archived',
    'cancelled',
    'completed',
    'draft',
    'failed',
    'on-hold',
    'pending',
    'processing',
    'published',
    'refund_rejected',
    'refund_requested',
    'refunded',
    'scheduled',
    'sending',
    'sent',
    'shipped',
    'spam',
    'trash',
    'unlisted',
    'unsubscribed',

    // ── Admin sidebar navigation labels (config/admin-nav.php, rendered via __(item.label)) ──
    'Dashboard',
    'Users',
    'Analytics',
    'Content',
    'Pages',
    'Posts',
    'Categories',
    'Tags',
    'Media',
    'System',
    'Settings',
    'Menus',
    'Themes',
    'Fonts',
    'Comments',
    'Reviews',
    'Shop',
    'Products',
    'Membership Plans',
    'Orders',
    'Reports',
    'Coupons',
    'Forms',
    'Newsletter',
    'Subscribers',
    'Campaigns',
    'Automations',

    // ── Dashboard quick actions (admin/dashboard.twig, rendered via __(action.label)) ──
    'New Post',
    'New Page',
    'New Product',
    'New Order',
    'New Campaign',
    'Optimize',
    'Warm Cache',

    // ── Mail notification type labels (admin/settings/_mail_notifications.twig, rendered via __(nt.label)) ──
    'Order Confirmation',
    'Payment Received',
    'Order Status Update',
    'Tracking Update',
    'Order Note',
    'Invoice',
    'Payment Link',
    'Refund Request (Customer)',
    'Refund Request (Admin)',
    'Refund Reviewed',
    'Low Stock Warning',
    'Subscription Welcome',
    'Subscription Cancelled',
    'Password Reset',
    'Email Verification',
    'Welcome Email',
    'Newsletter Confirmation',
    'New Comment',
    'New Product Review',

    // ── Miscellaneous dynamic labels ──
    'Content Types',

    // ── Theme descriptions (theme.json) ──
    'Vale default theme',
    'Dark theme for Vale - demonstrates how to build a child theme.',
    'Premium \'Elegant Tech\' theme for marketing agencies and high-end portfolios. Features glassmorphism, neon accents, and high-impact typography.',
    'Bold magazine-style theme - emerald accents, full-width grid blog, immersive post hero. Drop-in demo of a complete custom theme.',

    // ── Coupon error messages (returned from CouponValidator / admin validateAmount,
    //    translated dynamically via translator->get($result['error'])) ──
    'Percentage discount cannot exceed 100.',
    'Coupon amount cannot be negative.',
    'This coupon is only valid for memberships.',
    'This coupon requires you to be logged in.',
    'You have already reached the maximum usage limit for this coupon.',
    'This coupon is not valid for your email address.',
    'This coupon is restricted to specific email addresses. Please fill in your billing email first.',
    'This coupon is not valid for the products in your cart.',
    'This coupon cannot be used with one or more products in your cart.',
    'This coupon is not valid for the product categories in your cart.',
    'This coupon cannot be used with one or more product categories in your cart.',
];
