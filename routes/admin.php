<?php

/**
 * Admin panel routes — protected by Auth + Admin middleware.
 */

declare(strict_types=1);
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;
use ValeApp\App\Controllers\Admin\AnalyticsController;
use ValeApp\App\Controllers\Admin\ApiTokenController;
use ValeApp\App\Controllers\Admin\AppearanceController;
use ValeApp\App\Controllers\Admin\AutomationController;
use ValeApp\App\Controllers\Admin\CategoryController;
use ValeApp\App\Controllers\Admin\CommentController;
use ValeApp\App\Controllers\Admin\ContentTypeController;
use ValeApp\App\Controllers\Admin\CouponController;
use ValeApp\App\Controllers\Admin\DashboardController;
use ValeApp\App\Controllers\Admin\ExchangeRateController;
use ValeApp\App\Controllers\Admin\FormController;
use ValeApp\App\Controllers\Admin\MailController;
use ValeApp\App\Controllers\Admin\MediaController;
use ValeApp\App\Controllers\Admin\MembershipController;
use ValeApp\App\Controllers\Admin\MenuController;
use ValeApp\App\Controllers\Admin\NavOrderController;
use ValeApp\App\Controllers\Admin\NewsletterController;
use ValeApp\App\Controllers\Admin\OrderController;
use ValeApp\App\Controllers\Admin\PageController;
use ValeApp\App\Controllers\Admin\PaymentController;
use ValeApp\App\Controllers\Admin\PluginController;
use ValeApp\App\Controllers\Admin\PostController;
use ValeApp\App\Controllers\Admin\ProductCategoryController;
use ValeApp\App\Controllers\Admin\ProductController;
use ValeApp\App\Controllers\Admin\RoleController;
use ValeApp\App\Controllers\Admin\SettingsController;
use ValeApp\App\Controllers\Admin\TagController;
use ValeApp\App\Controllers\Admin\ThemeController;
use ValeApp\App\Controllers\Admin\UpdateController;
use ValeApp\App\Controllers\Admin\UserController;
use ValeApp\App\Controllers\Admin\WebhookAdminController;
use ValeApp\App\Controllers\Api\FeedController;
use ValeApp\App\Controllers\CronController;
use ValeApp\App\Middleware\AdminCacheBustMiddleware;
use ValeApp\App\Middleware\AdminMiddleware;
use ValeApp\App\Middleware\AuthMiddleware;
use ValeApp\App\Middleware\PermissionMiddleware;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;

return function (App $app): void {
    $app->group('/admin', function (RouteCollectorProxy $group) use ($app): void {
        /** @var ContainerInterface $container */
        $container       = $app->getContainer();
        $session         = $container->get(SessionManager::class);
        $contentTypeRepo = $container->get(ContentTypeRepositoryInterface::class);
        $cptListPerms    = array_merge(
            ['posts.list'],
            array_map(fn ($ct) => "cpt.{$ct->getSlug()}.list", $contentTypeRepo->findAll()),
        );

        // ── Dashboard (accessible to any panel role) ──────────────────
        $group->get('', [DashboardController::class, 'index'])->setName('admin.dashboard');
        $group->get('/dashboard/news', [DashboardController::class, 'news'])->setName('admin.dashboard.news');

        // ── Users (granular CRUD permissions) ─────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/users', [UserController::class, 'index'])->setName('admin.users');
            $g->get('/users/{id:[0-9]+}/roles', function ($request, $response, array $args) {
                return (new Response(302))->withHeader('Location', admin_url('/users/' . ($args['id'] ?? '') . '/edit'));
            })->setName('admin.users.roles');
        })->add(new PermissionMiddleware($session, ['users.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/users', [UserController::class, 'store']);
        })->add(new PermissionMiddleware($session, ['users.create']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->put('/users/{id:[0-9]+}', [UserController::class, 'update']);
        })->add(new PermissionMiddleware($session, ['users.edit']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->delete('/users/{id:[0-9]+}', [UserController::class, 'destroy']);
            $g->post('/users/{id:[0-9]+}/anonymize', [UserController::class, 'anonymize']);
        })->add(new PermissionMiddleware($session, ['users.delete']));

        // ── Roles (dedicated permission) ──────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/roles', [RoleController::class, 'index'])->setName('admin.roles');
            $g->get('/roles/create', [RoleController::class, 'create'])->setName('admin.roles.create');
            $g->post('/roles', [RoleController::class, 'store']);
            $g->get('/roles/{id:[0-9]+}/edit', [RoleController::class, 'edit'])->setName('admin.roles.edit');
            $g->put('/roles/{id:[0-9]+}', [RoleController::class, 'update']);
            $g->delete('/roles/{id:[0-9]+}', [RoleController::class, 'destroy']);
        })->add(new PermissionMiddleware($session, ['roles.manage']));

        // ── Bulk actions ─────────────────────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/bulk/posts', [PostController::class, 'bulkAction']);
            $g->post('/bulk/comments', [CommentController::class, 'bulkAction']);
        })->add(new PermissionMiddleware($session, $cptListPerms));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/bulk/forms', [FormController::class, 'bulkAction']);
            $g->post('/bulk/submissions', [FormController::class, 'bulkSubmissionsAction']);
        })->add(new PermissionMiddleware($session, ['forms.manage']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/bulk/subscribers', [NewsletterController::class, 'bulkAction']);
        })->add(new PermissionMiddleware($session, ['newsletter.manage']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/bulk/users', [UserController::class, 'bulkAction']);
        })->add(new PermissionMiddleware($session, ['users.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/bulk/products', [ProductController::class, 'bulkAction']);
        })->add(new PermissionMiddleware($session, ['products.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/bulk/media', [MediaController::class, 'bulkAction']);
        })->add(new PermissionMiddleware($session, ['media.delete']));

        // ── Content: Posts (posts.list required) ──────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/posts', [PostController::class, 'index'])->setName('admin.posts');
            $g->get('/posts/create', [PostController::class, 'create'])->setName('admin.posts.create');
            $g->post('/posts', [PostController::class, 'store']);
            $g->get('/posts/{id}/edit', [PostController::class, 'edit'])->setName('admin.posts.edit');
            $g->put('/posts/{id}', [PostController::class, 'update']);
            $g->delete('/posts/{id}', [PostController::class, 'destroy']);
            $g->post('/posts/{id}/clone', [PostController::class, 'clone']);
            $g->post('/posts/preview', [PostController::class, 'preview'])->setName('admin.posts.preview');
            $g->get('/posts/list-json', [PostController::class, 'listJson']);
            $g->get('/posts/{id:[0-9]+}/json', [PostController::class, 'getPostJson']);

            $g->get('/content-types', [ContentTypeController::class, 'index'])
                ->setName('admin.content-types');
            $g->get('/content-types/new', [ContentTypeController::class, 'create'])
                ->setName('admin.content-types.new');
            $g->post('/content-types', [ContentTypeController::class, 'store']);
            $g->get('/content-types/{id}/edit', [ContentTypeController::class, 'edit'])
                ->setName('admin.content-types.edit');
            $g->put('/content-types/{id}', [ContentTypeController::class, 'update']);
            $g->delete('/content-types/{id}', [ContentTypeController::class, 'destroy']);
            $g->post('/content-types/{id}/clone', [ContentTypeController::class, 'clone']);
            $g->post('/content-types/{id}/toggle-active', [ContentTypeController::class, 'toggleActive']);

            $g->get('/posts/{id}/revisions/json', [PostController::class, 'revisionsJson']);
            $g->get('/posts/{id}/revisions/{revId}', [PostController::class, 'revisionDetail']);
            $g->get('/posts/{id}/revisions/{revId}/diff/{type:current|previous}', [
                PostController::class, 'revisionDiff',
            ]);
            $g->post('/posts/{id}/revisions/{revId}/restore', [PostController::class, 'restoreRevision']);
            $g->post('/posts/{id}/revisions/{revId}/delete', [PostController::class, 'deleteRevision']);

            $g->get('/categories', [CategoryController::class, 'index'])->setName('admin.categories');
            $g->get('/categories/list-json', [CategoryController::class, 'listJson']);
            $g->get('/categories/create', [CategoryController::class, 'create'])->setName('admin.categories.create');
            $g->post('/categories', [CategoryController::class, 'store']);
            $g->get('/categories/{id}/edit', [CategoryController::class, 'edit'])->setName('admin.categories.edit');
            $g->get('/categories/{id:[0-9]+}/json', [CategoryController::class, 'getCategoryJson']);
            $g->put('/categories/{id}', [CategoryController::class, 'update']);
            $g->delete('/categories/{id}', [CategoryController::class, 'destroy']);
            $g->get('/categories/{id}/posts', [CategoryController::class, 'postsJson']);

            $g->get('/tags', [TagController::class, 'index'])->setName('admin.tags');
            $g->get('/tags/list-json', [TagController::class, 'listJson']);
            $g->get('/tags/create', [TagController::class, 'create'])->setName('admin.tags.create');
            $g->post('/tags', [TagController::class, 'store']);
            $g->get('/tags/{id}/edit', [TagController::class, 'edit'])->setName('admin.tags.edit');
            $g->get('/tags/{id:[0-9]+}/json', [TagController::class, 'getTagJson']);
            $g->put('/tags/{id}', [TagController::class, 'update']);
            $g->delete('/tags/{id}', [TagController::class, 'destroy']);
            $g->get('/tags/{id}/posts', [TagController::class, 'postsJson']);

            $g->get('/comments', [CommentController::class, 'index'])->setName('admin.comments');
            $g->get('/comments/{id}/edit', [CommentController::class, 'edit']);
            $g->post('/comments/{id}', [CommentController::class, 'update']);
            $g->post('/comments/{id}/moderate', [CommentController::class, 'moderate']);
            $g->delete('/comments/{id}', [CommentController::class, 'destroy']);

            // Read-only form lookup for the block editor (embed picker)
            $g->get('/forms/list-json', [FormController::class, 'formsJson']);
            $g->get('/forms/{id:[0-9]+}/json', [FormController::class, 'formsJsonSingle'])->setName('admin.forms.json_single');
            // Read-only subscriber tag lookup for the block editor (tag pills)
            $g->get('/newsletter/subscriber-tags/list-json', [NewsletterController::class, 'subscriberTagsJson']);
        })->add(new PermissionMiddleware($session, $cptListPerms));

        // ── Newsletter (newsletter.manage required) ─────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/newsletter/subscribers', [NewsletterController::class, 'subscribers'])
                ->setName('admin.newsletter.subscribers');
            $g->post('/newsletter/subscribers', [NewsletterController::class, 'storeSubscriber']);
            $g->post('/newsletter/subscribers/export', [NewsletterController::class, 'exportSubscribers']);
            $g->get('/newsletter/subscribers/export', [NewsletterController::class, 'exportSubscribers']);
            $g->post('/newsletter/subscribers/import', [NewsletterController::class, 'importSubscribers']);
            $g->put('/newsletter/subscribers/{id}', [NewsletterController::class, 'updateSubscriber']);
            $g->delete('/newsletter/subscribers/{id}', [NewsletterController::class, 'deleteSubscriber']);
            $g->post('/newsletter/subscribers/{id}/terminate', [NewsletterController::class, 'terminateSubscription']);
            $g->post('/newsletter/subscribers/{id}/cancel', [NewsletterController::class, 'cancelSubscription']);
            $g->get('/newsletter/campaigns', [NewsletterController::class, 'campaigns'])
                ->setName('admin.newsletter.campaigns');
            $g->get('/newsletter/campaigns/create', [NewsletterController::class, 'createCampaign'])
                ->setName('admin.newsletter.campaigns.create');
            $g->post('/newsletter/campaigns', [NewsletterController::class, 'storeCampaign']);
            $g->get('/newsletter/campaigns/{id}/recipients', [NewsletterController::class, 'campaignRecipients'])
                ->setName('admin.newsletter.campaigns.recipients');
            $g->get('/newsletter/campaigns/{id}/edit', [NewsletterController::class, 'editCampaign'])
                ->setName('admin.newsletter.campaigns.edit');
            $g->get('/newsletter/campaigns/{id}', [NewsletterController::class, 'viewCampaign'])
                ->setName('admin.newsletter.campaigns.view');
            $g->post('/newsletter/campaigns/{id}/clone', [NewsletterController::class, 'cloneCampaign']);
            $g->put('/newsletter/campaigns/{id}', [NewsletterController::class, 'updateCampaign']);
            $g->post('/newsletter/campaigns/{id}/send', [NewsletterController::class, 'sendCampaign']);
            $g->post('/newsletter/campaigns/{id}/resend', [NewsletterController::class, 'resendCampaign']);
            $g->delete('/newsletter/campaigns/{id}', [NewsletterController::class, 'deleteCampaign']);

            $g->get('/newsletter/automations', [AutomationController::class, 'index'])
                ->setName('admin.newsletter.automations');
            $g->get('/newsletter/automations/create', [AutomationController::class, 'create'])
                ->setName('admin.newsletter.automations.create');
            $g->post('/newsletter/automations', [AutomationController::class, 'store']);
            $g->get('/newsletter/automations/{id}/edit', [AutomationController::class, 'edit'])
                ->setName('admin.newsletter.automations.edit');
            $g->put('/newsletter/automations/{id}', [AutomationController::class, 'update']);
            $g->delete('/newsletter/automations/{id}', [AutomationController::class, 'delete']);
            $g->post('/newsletter/automations/{id}/toggle', [AutomationController::class, 'toggleStatus']);
            $g->post('/newsletter/automations/{id}/clone', [AutomationController::class, 'clone']);
            $g->post('/newsletter/automations/process', [AutomationController::class, 'process'])
                ->setName('admin.newsletter.automations.process');
        })->add(new PermissionMiddleware($session, ['newsletter.manage']));

        // ── Forms (forms.manage required) ───────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/forms', [FormController::class, 'index'])->setName('admin.forms');
            $g->get('/forms/create', [FormController::class, 'create'])->setName('admin.forms.create');
            $g->post('/forms', [FormController::class, 'store']);
            $g->get('/forms/{id}/edit', [FormController::class, 'edit'])->setName('admin.forms.edit');
            $g->put('/forms/{id}', [FormController::class, 'update']);
            $g->post('/forms/{id}/clone', [FormController::class, 'cloneForm']);
            $g->delete('/forms/{id}', [FormController::class, 'destroy']);
            $g->get('/forms/{id}/submissions', [FormController::class, 'submissions'])
                ->setName('admin.forms.submissions');
            $g->delete('/forms/{id}/submissions/{subId}', [FormController::class, 'destroySubmission']);
        })->add(new PermissionMiddleware($session, ['forms.manage']));

        // ── Content: Pages (granular permissions) ─────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/pages', [PageController::class, 'index'])->setName('admin.pages');
            $g->get('/pages/{id:[0-9]+}/json', [PageController::class, 'getPageJson']);
        })->add(new PermissionMiddleware($session, ['pages.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/pages/create', [PageController::class, 'create'])->setName('admin.pages.create');
            $g->post('/pages', [PageController::class, 'store']);
            $g->post('/pages/preview', [PageController::class, 'preview'])->setName('admin.pages.preview');
            $g->post('/pages/{id}/clone', [PostController::class, 'clone']);
        })->add(new PermissionMiddleware($session, ['pages.create']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/pages/{id}/edit', [PageController::class, 'edit'])->setName('admin.pages.edit');
            $g->put('/pages/{id}', [PageController::class, 'update']);
            $g->get('/pages/{id}/revisions/json', [PageController::class, 'revisionsJson']);
            $g->get('/pages/{id}/revisions/{revId}', [PageController::class, 'revisionDetail']);
            $g->get('/pages/{id}/revisions/{revId}/diff/{type:current|previous}', [
                PageController::class, 'revisionDiff',
            ]);
            $g->post('/pages/{id}/revisions/{revId}/restore', [PageController::class, 'restoreRevision']);
        })->add(new PermissionMiddleware($session, ['pages.edit']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->delete('/pages/{id}', [PageController::class, 'destroy']);
            $g->post('/pages/{id}/revisions/{revId}/delete', [PageController::class, 'deleteRevision']);
        })->add(new PermissionMiddleware($session, ['pages.delete']));

        // ── Media (list) ──────────────────────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/media', [MediaController::class, 'index'])->setName('admin.media');
            $g->get('/media/browse-json', [MediaController::class, 'browseJson']);
        })->add(new PermissionMiddleware($session, ['media.list']));

        // ── Media (upload) ────────────────────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/media/create', [MediaController::class, 'create'])->setName('admin.media.create');
            $g->post('/media', [MediaController::class, 'store']);
            $g->post('/media/upload-json', [MediaController::class, 'uploadJson']);
            $g->post('/media/upload-json-multi', [MediaController::class, 'uploadJsonMulti']);
            $g->get('/media/{id}/edit', [MediaController::class, 'edit'])->setName('admin.media.edit');
            $g->put('/media/{id}', [MediaController::class, 'update']);
        })->add(new PermissionMiddleware($session, ['media.upload']));

        // ── Media (delete) ────────────────────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->delete('/media/{id}', [MediaController::class, 'destroy']);
        })->add(new PermissionMiddleware($session, ['media.delete']));

        // ── Shop: Products & Categories (granular permissions) ────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/products', [ProductController::class, 'index'])->setName('admin.products');
            $g->get('/products/list-json', [ProductController::class, 'listJson']);
            $g->get('/products/{id:[0-9]+}/json', [ProductController::class, 'getProductJson']);
            $g->get('/products/{id}/export-csv', [ProductController::class, 'exportCsv']);
            $g->get('/products/export-template', [ProductController::class, 'exportTemplate']);
            $g->post('/products/bulk-export', [ProductController::class, 'bulkExport']);

            $g->get('/product-categories', [ProductCategoryController::class, 'index'])
                ->setName('admin.product-categories');
            $g->get('/product-categories/{id:[0-9]+}/json', [ProductCategoryController::class, 'getCategoryJson']);
        })->add(new PermissionMiddleware($session, ['products.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/products/create', [ProductController::class, 'create'])->setName('admin.products.create');
            $g->post('/products', [ProductController::class, 'store']);
            $g->post('/products/{id}/preview-draft', [ProductController::class, 'previewDraft']);
            $g->get('/product-categories/create', [ProductCategoryController::class, 'create'])
                ->setName('admin.product-categories.create');
            $g->post('/product-categories', [ProductCategoryController::class, 'store']);
        })->add(new PermissionMiddleware($session, ['products.create']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/products/{id}/edit', [ProductController::class, 'edit'])->setName('admin.products.edit');
            $g->put('/products/{id}', [ProductController::class, 'update']);
            $g->get('/product-categories/{id}/edit', [ProductCategoryController::class, 'edit'])
                ->setName('admin.product-categories.edit');
            $g->put('/product-categories/{id}', [ProductCategoryController::class, 'update']);
        })->add(new PermissionMiddleware($session, ['products.edit']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->delete('/products/{id}', [ProductController::class, 'destroy']);
            $g->delete('/product-categories/{id}', [ProductCategoryController::class, 'destroy']);
        })->add(new PermissionMiddleware($session, ['products.delete']));

        // ── Shop: Orders (granular permissions) ───────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/orders', [OrderController::class, 'index'])->setName('admin.orders');
            $g->get('/orders/reports', [OrderController::class, 'reports'])->setName('admin.orders.reports');
            $g->get('/orders/reports/pdf', [OrderController::class, 'reportsPdf'])
                ->setName('admin.orders.reports.pdf');
            $g->get('/orders/{id:[0-9]+}', [OrderController::class, 'show'])->setName('admin.orders.show');
            $g->get('/orders/{id}/invoice', [OrderController::class, 'viewInvoice']);
            $g->get('/orders/{id}/invoice/pdf', [OrderController::class, 'downloadPdf']);
        })->add(new PermissionMiddleware($session, ['orders.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/orders/create', [OrderController::class, 'create'])->setName('admin.orders.create');
            $g->post('/orders', [OrderController::class, 'store']);
            $g->post('/orders/coupon-preview', [OrderController::class, 'couponPreview']);
            $g->post('/orders/{id}/billing', [OrderController::class, 'updateBilling']);
            $g->post('/orders/{id}/shipping', [OrderController::class, 'updateShipping']);
            $g->post('/orders/{id}/status', [OrderController::class, 'updateStatus']);
            $g->post('/orders/{id}/payment-method', [OrderController::class, 'updatePaymentMethod']);
            $g->post('/orders/{id}/note', [OrderController::class, 'addNote']);
            $g->post('/orders/{id}/email', [OrderController::class, 'sendCustomerEmail']);
            $g->post('/orders/{id}/send-email', [OrderController::class, 'sendEmail']);
            $g->post('/orders/{id}/payment-link', [OrderController::class, 'generatePaymentLink']);
            $g->post('/orders/{id}/invoice/issue', [OrderController::class, 'issueInvoice']);
            $g->post('/orders/{id}/invoice/send', [OrderController::class, 'sendInvoiceToCustomer']);
            $g->post('/orders/{id}/refund/approve', [OrderController::class, 'approveRefundRequest']);
            $g->post('/orders/{id}/refund/reject', [OrderController::class, 'rejectRefundRequest']);
            $g->post('/orders/{id}/tracking', [OrderController::class, 'updateTracking']);
            $g->post('/orders/{id}/logs/{logId}/delete', [OrderController::class, 'deleteLog']);
            $g->post('/orders/{id}/downloads/{dlId}/revoke', [OrderController::class, 'revokeDownload']);
            $g->post('/orders/{id}/downloads/{dlId}/regenerate', [OrderController::class, 'regenerateDownloadToken']);
            $g->post('/orders/{id}/downloads/{dlId}/update-meta', [OrderController::class, 'updateDownloadMeta']);
        })->add(new PermissionMiddleware($session, ['orders.edit']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/orders/{id}/delete', [OrderController::class, 'destroy']);
        })->add(new PermissionMiddleware($session, ['orders.delete']));

        // ── Shop: Coupons ─────────────────────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/coupons', [CouponController::class, 'index'])->setName('admin.coupons');
            $g->get('/coupons/list-json', [CouponController::class, 'listJson']);
            $g->get('/coupons.json', [CouponController::class, 'listJson']);
        })->add(new PermissionMiddleware($session, ['products.list']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/coupons', [CouponController::class, 'store']);
            $g->put('/coupons/{id}', [CouponController::class, 'update']);
            $g->post('/bulk/coupons', [CouponController::class, 'bulkAction']);
        })->add(new PermissionMiddleware($session, ['products.edit']));

        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/coupons/{id}/delete', [CouponController::class, 'destroy']);
            $g->post('/bulk/coupons/delete', [CouponController::class, 'bulkDelete']);
        })->add(new PermissionMiddleware($session, ['products.delete']));

        // ── Membership Plans ──────────────────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/membership/plans', [MembershipController::class, 'index'])->setName('admin.membership.plans');
            $g->get('/membership/plans/list-json', [MembershipController::class, 'listJson']);
            $g->get('/membership/plans/create', [MembershipController::class, 'create'])->setName('admin.membership.plans.create');
            $g->post('/membership/plans', [MembershipController::class, 'store']);
            $g->get('/membership/plans/{id}/edit', [MembershipController::class, 'edit'])->setName('admin.membership.plans.edit');
            $g->put('/membership/plans/{id}', [MembershipController::class, 'update']);
            $g->delete('/membership/plans/{id}', [MembershipController::class, 'destroy']);
        })->add(new PermissionMiddleware($session, ['products.list']));

        // ── GMC: Taxonomy browse (products.list required) ─────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/gmc/taxonomy', [FeedController::class, 'browseTaxonomy'])->setName('admin.gmc.taxonomy');
        })->add(new PermissionMiddleware($session, ['products.list']));

        // ── Settings & System: read-only views (settings.view required) ──
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/settings', [SettingsController::class, 'index'])->setName('admin.settings');
            $g->post('/settings/export-data', [SettingsController::class, 'exportData']);
            $g->post('/settings/export-site-data', [SettingsController::class, 'exportSiteData']);

            $g->get('/mail', function () {
                return (new Response(302))->withHeader('Location', admin_url('/settings?tab=mail'));
            })->setName('admin.mail');
            $g->get('/mail/template/{template}', [MailController::class, 'getTemplate'])
                ->setName('admin.mail.template.get');
            $g->get('/mail/logs', [MailController::class, 'logs'])->setName('admin.mail.logs');
            $g->get('/mail/preview/{template}', [MailController::class, 'preview'])->setName('admin.mail.preview');

            $g->get('/payment', [PaymentController::class, 'wizard'])->setName('admin.payment');

            $g->get('/analytics', [AnalyticsController::class, 'index'])->setName('admin.analytics');

            // AJAX "show more" rows for the analytics dashboard lists
            $g->get('/analytics/list-rows', [AnalyticsController::class, 'listRows'])
                ->setName('admin.analytics.list-rows');

            // AJAX re-render of the UTM card for the active facet/sort state
            $g->get('/analytics/utm-panel', [AnalyticsController::class, 'utmPanel'])
                ->setName('admin.analytics.utm-panel');

            // Heavy maintenance counters are read-only and loaded lazily (AJAX)
            // so the settings page opens instantly.
            $g->get('/maintenance/stats', [SettingsController::class, 'maintenanceStats'])->setName('admin.maintenance.stats');

            // Payment methods available on the configured Stripe account — powers
            // the Custom chips and is fetched lazily when that mode is selected.
            $g->get('/payment/stripe/methods', [PaymentController::class, 'stripeMethods'])
                ->setName('admin.payment.stripe-methods');
        })->add(new PermissionMiddleware($session, ['settings.view']));

        // ── Settings & System: mutations (settings.edit required) ─────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->put('/settings', [SettingsController::class, 'update']);
            $g->post('/settings/verify-captcha', [SettingsController::class, 'verifyCaptcha']);
            $g->post('/settings/import-site-data', [SettingsController::class, 'importSiteData']);
            $g->post('/settings/import-products', [SettingsController::class, 'importProducts']);

            $g->put('/mail', [MailController::class, 'save']);
            $g->post('/mail/test', [MailController::class, 'test'])->setName('admin.mail.test');
            $g->post('/mail/template/save', [MailController::class, 'saveTemplate'])
                ->setName('admin.mail.template.save');
            $g->post('/mail/template/reset', [MailController::class, 'resetTemplate'])
                ->setName('admin.mail.template.reset');
            $g->post('/mail/logs/clear', [MailController::class, 'clearLogs'])->setName('admin.mail.logs.clear');
            $g->post('/mail/logs/{id:[0-9]+}/delete', [MailController::class, 'deleteLog'])->setName('admin.mail.logs.delete');

            $g->post('/api-tokens', [ApiTokenController::class, 'store']);
            $g->delete('/api-tokens/{id}', [ApiTokenController::class, 'destroy']);

            $g->post('/webhooks', [WebhookAdminController::class, 'store']);
            $g->post('/webhooks/test-preview', [WebhookAdminController::class, 'testPreview']);
            $g->put('/webhooks/{id:[0-9]+}', [WebhookAdminController::class, 'update']);
            $g->post('/webhooks/{id:[0-9]+}', [WebhookAdminController::class, 'update']);
            $g->post('/webhooks/{id:[0-9]+}/delete', [WebhookAdminController::class, 'destroy']);
            $g->post('/webhooks/{id:[0-9]+}/test', [WebhookAdminController::class, 'test']);

            $g->post('/payment/save', [PaymentController::class, 'save']);
            $g->post('/payment/test', [PaymentController::class, 'test']);
            $g->post('/payment/toggle', [PaymentController::class, 'toggle']);
            $g->post('/payment/default', [PaymentController::class, 'setDefault']);

            $g->map(['POST', 'PUT'], '/exchange-rates/refresh', [ExchangeRateController::class, 'refresh'])
                ->setName('admin.exchange-rates.refresh');

            $g->post('/analytics/download-geo-db', [SettingsController::class, 'downloadGeoDb'])
                ->setName('admin.analytics.download-geo-db');
            $g->get('/analytics/check-cloudflare', [SettingsController::class, 'checkCloudflare'])
                ->setName('admin.analytics.check-cloudflare');

            $g->get('/update', [UpdateController::class, 'index'])->setName('admin.update');
            $g->post('/update/check', [UpdateController::class, 'checkNow'])->setName('admin.update.check');
            $g->post('/update/apply', [UpdateController::class, 'apply'])->setName('admin.update.apply');
            $g->post('/update/backup', [UpdateController::class, 'createBackupNow'])
                ->setName('admin.update.backup');
            $g->post('/update/rollback', [UpdateController::class, 'rollback'])->setName('admin.update.rollback');
            $g->get('/update/backup/download', [UpdateController::class, 'downloadBackup'])
                ->setName('admin.update.backup.download');
            $g->post('/update/backup/delete', [UpdateController::class, 'deleteBackup'])
                ->setName('admin.update.backup.delete');
            $g->get('/update/backups', [UpdateController::class, 'backups'])->setName('admin.update.backups');
        })->add(new PermissionMiddleware($session, ['settings.edit']));

        // ── System maintenance (system.cache required) ────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->post('/cache/clear', [SettingsController::class, 'clearAllCache'])->setName('admin.cache.clear');
            $g->post('/cache/warm', [SettingsController::class, 'warmCache'])->setName('admin.cache.warm');
            $g->post('/cache/rebuild-container', [SettingsController::class, 'rebuildContainerCache'])->setName('admin.cache.rebuild-container');
            $g->post('/maintenance/clear-page-cache', [SettingsController::class, 'clearPageCache'])->setName('admin.maintenance.clear-page-cache');
            $g->post('/maintenance/clear-data-cache', [SettingsController::class, 'clearDataCache'])->setName('admin.maintenance.clear-data-cache');
            $g->post('/maintenance/rescan-media', [SettingsController::class, 'rescanMedia'])->setName('admin.maintenance.rescan-media');
            $g->post('/maintenance/prune-revisions', [SettingsController::class, 'pruneRevisions'])->setName('admin.maintenance.prune-revisions');
            $g->post('/maintenance/db-optimize', [SettingsController::class, 'dbOptimize'])->setName('admin.maintenance.db-optimize');
            $g->post('/maintenance/clear-redirects', [SettingsController::class, 'clearRedirects'])->setName('admin.maintenance.clear-redirects');

            $g->post('/optimize', [SettingsController::class, 'optimizeAll'])->setName('admin.optimize');

            $g->post('/cron/run-now', [CronController::class, 'runAdmin'])->setName('admin.cron.run-now');
        })->add(new PermissionMiddleware($session, ['system.cache']));

        // ── Themes & Appearance (system.themes required) ──────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/themes', [ThemeController::class, 'index'])->setName('admin.themes');
            $g->get('/themes/upload', [ThemeController::class, 'upload'])->setName('admin.themes.upload');
            $g->post('/themes/upload', [ThemeController::class, 'store']);
            $g->post('/themes/activate', [ThemeController::class, 'activate']);
            $g->post('/themes/preview', [ThemeController::class, 'preview']);
            $g->post('/themes/delete', [ThemeController::class, 'destroy']);
            $g->get('/themes/{slug}/edit', [ThemeController::class, 'edit'])->setName('admin.themes.edit');
            $g->get('/themes/{slug}/colors', [ThemeController::class, 'colors'])->setName('admin.themes.colors');
            $g->post('/themes/{slug}/colors', [ThemeController::class, 'saveColors']);
            $g->post('/themes/{slug}/colors/reset', [ThemeController::class, 'resetColors']);
            $g->get('/themes/{slug}/templates/get', [ThemeController::class, 'getTemplate']);
            $g->post('/themes/{slug}/templates/save', [ThemeController::class, 'saveTemplate']);
            $g->post('/themes/{slug}/templates/create', [ThemeController::class, 'createTemplate']);
            $g->post('/themes/{slug}/templates/delete', [ThemeController::class, 'deleteTemplate']);

            $g->get('/appearance/fonts', [AppearanceController::class, 'index'])->setName('admin.appearance.fonts');
            $g->post('/appearance/fonts', [AppearanceController::class, 'update']);
        })->add(new PermissionMiddleware($session, ['system.themes']));

        // ── Plugins (system.plugins required) ────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/plugins', [PluginController::class, 'index'])->setName('admin.plugins');
            $g->post('/plugins/toggle', [PluginController::class, 'toggle'])->setName('admin.plugins.toggle');
            $g->post('/plugins/scan', [PluginController::class, 'scan'])->setName('admin.plugins.scan');
            $g->post('/plugins/nav-visibility', [PluginController::class, 'setNavVisibility'])->setName('admin.plugins.nav-visibility');
            $g->post('/plugins/install', [PluginController::class, 'install'])->setName('admin.plugins.install');
            $g->post('/plugins/check-updates', [PluginController::class, 'checkUpdates'])->setName('admin.plugins.check-updates');
            $g->post('/plugins/update', [PluginController::class, 'update'])->setName('admin.plugins.update');
            $g->post('/plugins/bulk', [PluginController::class, 'bulkAction'])->setName('admin.plugins.bulk');
            $g->post('/plugins/global', [PluginController::class, 'toggleGlobal'])->setName('admin.plugins.global');
            $g->post('/plugins/delete', [PluginController::class, 'delete'])->setName('admin.plugins.delete');
        })->add(new PermissionMiddleware($session, ['system.plugins']));

        // ── Menus (settings.view required) ────────────────────────────
        $group->group('', function (RouteCollectorProxy $g): void {
            $g->get('/menus', [MenuController::class, 'index'])->setName('admin.menus');
            $g->get('/menus/content-items', [MenuController::class, 'contentItems'])
                ->setName('admin.menus.content-items');
            $g->post('/menus', [MenuController::class, 'store']);
            $g->get('/menus/{id}/json', [MenuController::class, 'getMenuJson'])->setName('admin.menus.json');
            $g->put('/menus/{id}', [MenuController::class, 'update']);
            $g->delete('/menus/{id}', [MenuController::class, 'destroy']);
            $g->post('/menus/{id}/clone', [MenuController::class, 'cloneMenu']);
        })->add(new PermissionMiddleware($session, ['settings.view']));

        // ── Nav / Quick-actions order (any panel user) ────────────────
        $group->post('/nav-order', [NavOrderController::class, 'saveNavOrder'])->setName('admin.nav-order');
        $group->post('/quick-actions-order', [NavOrderController::class, 'saveQuickActionsOrder'])
            ->setName('admin.quick-actions-order');
    })->add(AdminCacheBustMiddleware::class)->add(AdminMiddleware::class)->add(AuthMiddleware::class);
};
