<?php

/**
 * Public-facing web routes.
 */

declare(strict_types=1);

namespace ValeApp\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Response;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\ThemeController;
use ValeApp\App\Controllers\CronController;
use ValeApp\App\Controllers\Front\AccountController;
use ValeApp\App\Controllers\Front\AuthController;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\App\Controllers\Front\CommentController;
use ValeApp\App\Controllers\Front\CurrencyController;
use ValeApp\App\Controllers\Front\DownloadController;
use ValeApp\App\Controllers\Front\EmergencyResetController;
use ValeApp\App\Controllers\Front\ForgotPasswordController;
use ValeApp\App\Controllers\Front\FormFrontController;
use ValeApp\App\Controllers\Front\HomeController;
use ValeApp\App\Controllers\Front\MembershipCheckoutController;
use ValeApp\App\Controllers\Front\NewsletterFrontController;
use ValeApp\App\Controllers\Front\PluginAssetController;
use ValeApp\App\Controllers\Front\PostController;
use ValeApp\App\Controllers\Front\SeoController;
use ValeApp\App\Controllers\Front\ShopController;
use ValeApp\App\Controllers\Front\WebhookController;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

return function (App $app): void {
    $c = $app->getContainer();

    $app->get('/', [HomeController::class, 'index'])->setName('home');

    // Plugin assets — content/plugins/<slug>/assets/ served through PHP so a
    // plugin needs no web-server alias. Enabled plugins only; unknown, hidden
    // and executable extensions answer 404.
    $app->get('/plugin-assets/{slug}/{path:.+}', [PluginAssetController::class, 'show'])
        ->setName('plugin-asset');

    // SEO
    $app->get('/sitemap.xml', [SeoController::class, 'sitemap'])->setName('sitemap');
    $app->get('/robots.txt', [SeoController::class, 'robots'])->setName('robots');
    $app->get('/ai-robots.txt', [SeoController::class, 'aiRobots'])->setName('ai-robots');

    // Auth routes (dynamic prefixes from settings, localized per locale)
    $loginPrefix          = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_login_prefix', '/login'), '/');
    $registerPrefix       = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_register_prefix', '/register'), '/');
    $verifyEmailPrefix    = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_verify_email_prefix', '/verify-email'), '/');
    $logoutPrefix         = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_logout_prefix', '/logout'), '/');
    $forgotPasswordPrefix = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_forgot_password_prefix', '/forgot-password'), '/');
    $resetPasswordPrefix  = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_reset_password_prefix', '/reset-password'), '/');

    $app->get($loginPrefix, [AuthController::class, 'showLogin'])->setName('login');
    $app->post($loginPrefix, [AuthController::class, 'login'])->add($c->get('rate_limit.login'));
    $app->get($loginPrefix . '/two-factor', [AuthController::class, 'showTwoFactorChallenge'])->setName('login.two-factor');
    $app->post($loginPrefix . '/two-factor', [AuthController::class, 'twoFactorChallenge'])->add($c->get('rate_limit.login'));
    $app->get($registerPrefix, [AuthController::class, 'showRegister'])->setName('register');
    $app->post($registerPrefix, [AuthController::class, 'register'])->add($c->get('rate_limit.form'));
    $app->get($verifyEmailPrefix, [AuthController::class, 'verifyEmail'])->setName('verify-email');
    $app->map(['GET', 'POST'], $logoutPrefix, [AuthController::class, 'logout'])->setName('logout');

    // SSO routes
    $app->get('/auth/sso/{provider}', [AuthController::class, 'ssoRedirect'])->setName('sso.redirect');
    $app->get('/auth/sso/callback/{provider}', [AuthController::class, 'ssoCallback'])->setName('sso.callback');

    // Password reset (dynamic prefixes from settings, localized per locale)
    $app->get($forgotPasswordPrefix, [ForgotPasswordController::class, 'showForgot'])->setName('forgot-password');
    $app->post($forgotPasswordPrefix, [ForgotPasswordController::class, 'sendReset'])->add($c->get('rate_limit.form'));
    $app->get($resetPasswordPrefix . '/{token}', [ForgotPasswordController::class, 'showReset'])->setName('reset-password');
    $app->post($resetPasswordPrefix . '/{token}', [ForgotPasswordController::class, 'doReset'])
        ->add($c->get('rate_limit.form'));

    // Emergency password reset (no auth required, token from .env)
    $app->get('/admin/emergency-reset/{token}', [EmergencyResetController::class, 'show'])->setName('emergency-reset');
    $app->post('/admin/emergency-reset/{token}', [EmergencyResetController::class, 'reset'])
        ->add($c->get('rate_limit.form'));

    // Shop (gated by shop_enabled setting)
    $shopPrefix = $c->get(DbalSettingsRepository::class)->get('routing_shop_prefix', '/shop');

    $cartPrefix     = $c->get(DbalSettingsRepository::class)->get('routing_cart_prefix', '/cart');
    $checkoutPrefix = $c->get(DbalSettingsRepository::class)->get('routing_checkout_prefix', '/checkout');

    $app->group('', function (RouteCollectorProxy $group) use ($c, $shopPrefix, $cartPrefix, $checkoutPrefix) {
        $group->get($shopPrefix, [ShopController::class, 'index'])->setName('shop.index');
        $group->get($shopPrefix . '/{slug}', [ShopController::class, 'show'])->setName('shop.product');
        $group->post($shopPrefix . '/{slug}/vote', [ShopController::class, 'vote'])->setName('shop.vote');
        $group->get($cartPrefix, [ShopController::class, 'cart'])->setName('shop.cart');
        $group->get($cartPrefix . '/json', [ShopController::class, 'getCartJson'])->setName('shop.cart.json');
        $group->post($cartPrefix . '/add', [ShopController::class, 'addToCart'])->setName('shop.cart.add');
        $group->post($cartPrefix . '/update', [ShopController::class, 'updateCart'])->setName('shop.cart.update');
        $group->post($cartPrefix . '/remove', [ShopController::class, 'removeFromCart'])->setName('shop.cart.remove');
        $group->get($checkoutPrefix, [CheckoutController::class, 'index'])->setName('checkout');
        $group->post($checkoutPrefix, [CheckoutController::class, 'process']);
        $group->post($checkoutPrefix . '/apply-coupon', [CheckoutController::class, 'applyCoupon']);
        $group->post($checkoutPrefix . '/quote', [CheckoutController::class, 'quote']);
        $group->get($checkoutPrefix . '/payment', [CheckoutController::class, 'payment'])->setName('checkout.payment');
        $group->get($checkoutPrefix . '/success', [CheckoutController::class, 'success'])->setName('checkout.success');
        $group->post($checkoutPrefix . '/success', [CheckoutController::class, 'success']);
        $group->post($checkoutPrefix . '/request-refund', [CheckoutController::class, 'requestRefund'])
            ->add($c->get('rate_limit.form'));
        $group->get($checkoutPrefix . '/cancel', [CheckoutController::class, 'cancel'])->setName('checkout.cancel');
        $group->post('/set-currency', [CurrencyController::class, 'set'])->setName('currency.set');
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $s = $app->getContainer()->get(DbalSettingsRepository::class);
        if (($s->get('shop_enabled') ?? '1') === '0') {
            $twig = $app->getContainer()->get(Twig::class);
            $r    = new Response(404);
            return $twig->render($r, 'errors/feature-disabled.twig', [
                'feature' => 'shop',
                'title'   => 'Shop Disabled',
            ]);
        }
        return $handler->handle($req);
    });

    // Preview (admin logged-in users can preview drafts)
    $app->get('/themes/stop-preview', [ThemeController::class, 'stopPreview'])->setName('themes.stop-preview');
    $app->get('/preview/post/{id:[0-9]+}', [PostController::class, 'preview'])->setName('post.preview');
    $app->get('/preview/page/{id:[0-9]+}', [PostController::class, 'previewPage'])->setName('page.preview');
    $app->get('/preview/product/{id:[0-9]+}', [ShopController::class, 'preview'])->setName('product.preview');

    // Payment link resume — outside shop group (works even if shop is "disabled" in settings)
    $app->get('/pay/{token}', [CheckoutController::class, 'resumePayment'])->setName('checkout.resume');
    $app->post('/pay/{token}/claim', [CheckoutController::class, 'claimBankTransfer'])->setName('checkout.pay.claim')
        ->add($c->get('rate_limit.form'));

    // Digital file downloads — token-gated, public (no auth required)
    $app->get('/downloads/{token:[0-9a-f]{64}}', [DownloadController::class, 'serve'])->setName('download.serve');

    // Forms (M6)
    $app->group('', function (RouteCollectorProxy $group) use ($c) {
        $group->get('/form/{slug}', [FormFrontController::class, 'show'])->setName('form.show');
        $group->post('/form/{slug}', [FormFrontController::class, 'submit'])->setName('form.submit')
            ->add($c->get('rate_limit.form'));

        // External Form API
        $group->post('/api/v1/form/{slug}', [FormFrontController::class, 'submitExternal'])
            ->add($c->get('rate_limit.form'));
        $group->options('/api/v1/form/{slug}', [FormFrontController::class, 'preflightExternal'])
            ->add($c->get('rate_limit.form'));
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $s = $app->getContainer()->get(DbalSettingsRepository::class);
        if (($s->get('form_builder_enabled') ?? '1') === '0') {
            $twig = $app->getContainer()->get(Twig::class);
            $r    = new Response(404);
            return $twig->render($r, 'errors/feature-disabled.twig', [
                'feature' => 'form_builder',
                'title'   => 'Form Builder Disabled',
            ]);
        }
        return $handler->handle($req);
    });

    // Newsletter (M7)
    $app->group('', function (RouteCollectorProxy $group) use ($c) {
        $group->post('/newsletter/subscribe', [NewsletterFrontController::class, 'subscribe'])
            ->setName('newsletter.subscribe')
            ->add($c->get('rate_limit.form'));
        $group->get('/newsletter/confirm/{token}', [NewsletterFrontController::class, 'confirm'])
            ->setName('newsletter.confirm');
        $group->get('/newsletter/unsubscribe/{token}', [NewsletterFrontController::class, 'unsubscribe'])
            ->setName('newsletter.unsubscribe');
        // RFC 8058 one-click unsubscribe: Gmail posts to the List-Unsubscribe URL.
        $group->post('/newsletter/unsubscribe/{token}', [NewsletterFrontController::class, 'unsubscribe'])
            ->setName('newsletter.unsubscribe.post');

        // Newsletter tracking
        $group->get('/t/open/{token}.gif', [NewsletterFrontController::class, 'trackOpen'])->setName('nl.track.open');
        $group->get('/t/click/{token}', [NewsletterFrontController::class, 'trackClick'])->setName('nl.track.click');
        $group->get('/t/open-auto/{token}.gif', [NewsletterFrontController::class, 'trackOpen'])->setName('nl.track.auto.open');
        $group->get('/t/click-auto/{token}', [NewsletterFrontController::class, 'trackClick'])->setName('nl.track.auto.click');
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $s = $app->getContainer()->get(DbalSettingsRepository::class);
        if (($s->get('newsletter_enabled') ?? '1') === '0') {
            $twig = $app->getContainer()->get(Twig::class);
            $r    = new Response(404);
            return $twig->render($r, 'errors/feature-disabled.twig', [
                'feature' => 'newsletter',
                'title'   => 'Newsletter Disabled',
            ]);
        }
        return $handler->handle($req);
    });

    // Membership routes (dynamic prefix from routing_membership_prefix setting, gated by subscriptions_enabled)
    $membershipPrefix = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_membership_prefix', '/membership'), '/');
    $checkoutPrefix   = '/' . ltrim($c->get(DbalSettingsRepository::class)->get('routing_checkout_prefix', '/checkout'), '/');
    $app->group('', function (RouteCollectorProxy $group) use ($c, $membershipPrefix, $checkoutPrefix): void {
        $group->get($membershipPrefix, [MembershipCheckoutController::class, 'index'])->setName('membership.plans');
        $group->get($membershipPrefix . $checkoutPrefix . '/{id:[0-9]+}', [MembershipCheckoutController::class, 'checkout'])->setName('membership.checkout');
        $group->post($membershipPrefix . $checkoutPrefix . '/subscribe', [MembershipCheckoutController::class, 'createSubscription'])->setName('membership.subscribe');
        $group->post($membershipPrefix . $checkoutPrefix . '/stripe-session', [MembershipCheckoutController::class, 'stripeCheckoutSession'])->setName('membership.stripe.session');
        $group->post($membershipPrefix . $checkoutPrefix . '/apply-coupon', [MembershipCheckoutController::class, 'applyCoupon'])->setName('membership.apply_coupon');
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $s = $app->getContainer()->get(DbalSettingsRepository::class);
        if (($s->get('subscriptions_enabled') ?? '1') === '0') {
            $shopPrefix = '/' . ltrim($s->get('routing_shop_prefix', '/shop'), '/');
            return (new Response(302))->withHeader('Location', $shopPrefix);
        }
        return $handler->handle($req);
    });
    $app->get($membershipPrefix . '/stripe-return', [MembershipCheckoutController::class, 'stripeReturn'])->setName('membership.stripe.return');
    $app->get($membershipPrefix . '/paypal-return', [MembershipCheckoutController::class, 'paypalReturn'])->setName('membership.paypal.return');
    $app->get($membershipPrefix . '/cancel', [MembershipCheckoutController::class, 'cancel'])->setName('membership.cancel');
    $app->post($membershipPrefix . '/cancel', [MembershipCheckoutController::class, 'cancel'])->setName('membership.cancel.post');

    // Account routes (dynamic prefix from routing_account_prefix setting)
    $accountPrefix = $c->get(DbalSettingsRepository::class)->get('routing_account_prefix', '/account');

    $app->group('', function (RouteCollectorProxy $group) use ($c, $accountPrefix): void {
        $group->get($accountPrefix, [AccountController::class, 'index'])->setName('account.index');
        $group->map(['GET', 'POST'], $accountPrefix . '/profile', [AccountController::class, 'profile'])->setName('account.profile');
        $group->get($accountPrefix . '/verify-email/resend', [AccountController::class, 'resendVerification'])->setName('account.verify-email.resend');
        $group->get($accountPrefix . '/two-factor/setup', [AccountController::class, 'showTwoFactorSetup'])->setName('account.two-factor.setup');
        $group->post($accountPrefix . '/two-factor/setup', [AccountController::class, 'setupTwoFactor']);
        $group->get($accountPrefix . '/two-factor/forced-setup', [AccountController::class, 'showTwoFactorSetup'])->setName('account.two-factor.forced-setup');
        $group->post($accountPrefix . '/two-factor/enable', [AccountController::class, 'enableTwoFactor'])->setName('account.two-factor.enable');
        $group->post($accountPrefix . '/two-factor/disable', [AccountController::class, 'disableTwoFactor'])->setName('account.two-factor.disable');
        $group->post($accountPrefix . '/two-factor/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->setName('account.two-factor.recovery-codes');
        $group->map(['GET', 'POST'], $accountPrefix . '/addresses', [AccountController::class, 'addresses'])->setName('account.addresses');
        $group->get($accountPrefix . '/orders', [AccountController::class, 'orders'])->setName('account.orders');
        $group->get($accountPrefix . '/subscription', [AccountController::class, 'subscription'])->setName('account.subscription');
        $group->post($accountPrefix . '/subscription/cancel', [AccountController::class, 'cancelSubscription'])->setName('account.subscription.cancel');
        $group->map(['GET', 'POST'], $accountPrefix . '/delete', [AccountController::class, 'delete'])->setName('account.delete');
        $group->get($accountPrefix . '/download-data', [AccountController::class, 'downloadData'])->setName('account.download-data');
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app) {
        $session = $app->getContainer()->get(SessionManager::class);
        if ($session->get('user_id') === null) {
            $uri    = $req->getUri();
            $target = $uri->getPath();
            if ($uri->getQuery() !== '') {
                $target .= '?' . $uri->getQuery();
            }

            $loginUrl = site_url('/login') . '?redirect=' . urlencode($target);
            return (new Response(302))->withHeader('Location', $loginUrl);
        }

        return $handler->handle($req);
    });

    // Payment webhooks (no CSRF — raw POST from Stripe/PayPal/Mollie/2Checkout/Checkout.com)
    $app->post('/webhooks/stripe', [WebhookController::class, 'stripe'])->setName('webhooks.stripe');
    $app->post('/webhooks/paypal', [WebhookController::class, 'paypal'])->setName('webhooks.paypal');
    $app->post('/webhooks/mollie', [WebhookController::class, 'mollie'])->setName('webhooks.mollie');
    $app->post('/webhooks/twocheckout', [WebhookController::class, 'twocheckout'])->setName('webhooks.twocheckout');
    $app->post('/webhooks/checkoutcom', [WebhookController::class, 'checkoutcom'])->setName('webhooks.checkoutcom');
    $app->post('/webhooks/stripe/subscriptions', [WebhookController::class, 'stripeSubscription'])->setName('webhooks.stripe.subscriptions');

    // Cron HTTP endpoint — token-protected, no CSRF (called by external services)
    $app->get('/cron/run', [CronController::class, 'run'])->setName('cron.run');

    // Blog (gated by blog_enabled setting)
    $blogPrefix     = $c->get(DbalSettingsRepository::class)->get('routing_blog_prefix', '/blog');
    $categoryPrefix = $c->get(DbalSettingsRepository::class)->get('routing_category_prefix', '/category');
    $tagPrefix      = $c->get(DbalSettingsRepository::class)->get('routing_tag_prefix', '/tag');

    $app->group('', function (RouteCollectorProxy $group) use ($c, $blogPrefix, $categoryPrefix, $tagPrefix) {
        $group->get($blogPrefix, [PostController::class, 'index'])->setName('blog');
        $group->get($blogPrefix . '/feed.xml', [PostController::class, 'feed'])->setName('blog.feed');
        $group->get($blogPrefix . '/{slug}', [PostController::class, 'show'])->setName('post.show');
        $group->get($categoryPrefix . '/{slug}', [PostController::class, 'byCategory'])
            ->setName('category.archive')
            ->setArgument('type', 'post');
        $group->get($tagPrefix . '/{slug}', [PostController::class, 'byTag'])
            ->setName('tag.archive')
            ->setArgument('type', 'post');
        $group->post('/comments', [CommentController::class, 'store'])->setName('comments.store')
            ->add($c->get('rate_limit.form'));
        $group->post('/comments/{id:[0-9]+}/edit', [CommentController::class, 'edit'])->setName('comments.edit');
        $group->post('/comments/{id:[0-9]+}/delete', [CommentController::class, 'delete'])->setName('comments.delete');
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $s = $app->getContainer()->get(DbalSettingsRepository::class);
        if (($s->get('blog_enabled') ?? '1') === '0') {
            $twig = $app->getContainer()->get(Twig::class);
            $r    = new Response(404);
            return $twig->render($r, 'errors/feature-disabled.twig', [
                'feature' => 'blog',
                'title'   => 'Blog Disabled',
            ]);
        }
        return $handler->handle($req);
    });

    // Custom Content Types (gated by custom_content_types_enabled setting)
    $app->group('', function (RouteCollectorProxy $group) use ($c, $categoryPrefix, $tagPrefix): void {
        $contentTypes = $c->get(ContentTypeRepositoryInterface::class)->findActiveCustom();
        $routing      = $c->get(RoutingValidator::class);
        foreach ($contentTypes as $type) {
            if ($type->getSlug() === 'post' || $type->getSlug() === 'page') {
                continue; // Skip system types handled separately
            }
            $slug = (string) $type->getSlug();

            // Determine the base path for this content type
            $basePath = $type->getPublicBasePath();

            // Safety net: a conflicting base path would throw a FastRoute
            // "shadowed route" exception and take down the whole site (legacy
            // data, imports). Skip the broken type and log it instead — it is
            // reported by `php vale doctor` and blocked in the admin UI.
            if ($conflict = $routing->getConflictError($basePath, null, $type->getId())) {
                error_log(sprintf('[Vale] Skipping conflicting content type "%s" (%s): %s', $type->getName(), $basePath, $conflict));
                continue;
            }

            // RSS feed route - static route MUST come before {slug} route
            if (in_array('rss', $type->getSupports(), true)) {
                $group->get($basePath . '/feed.xml', [PostController::class, 'feedByType'])
                    ->setName('cpt.feed.' . $slug)
                    ->setArgument('type', $slug);
            }

            // Archive route
            $group->get($basePath, [PostController::class, 'byTypeArchive'])
                ->setName('cpt.archive.' . $slug)
                ->setArgument('type', $slug);

            // Single item route - MUST come after static routes (feed.xml, archive)
            $group->get($basePath . '/{slug}', [PostController::class, 'showByType'])
                ->setName('cpt.show.' . $slug)
                ->setArgument('type', $slug);

            // Category and tag routes
            $group->get($basePath . $categoryPrefix . '/{slug}', [PostController::class, 'byCategory'])
                ->setName('cpt.category.' . $slug)
                ->setArgument('type', $slug);
            $group->get($basePath . $tagPrefix . '/{slug}', [PostController::class, 'byTag'])
                ->setName('cpt.tag.' . $slug)
                ->setArgument('type', $slug);
        }
    })->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $s = $app->getContainer()->get(DbalSettingsRepository::class);
        if (($s->get('custom_content_types_enabled') ?? '1') === '0') {
            $twig = $app->getContainer()->get(Twig::class);
            $r    = new Response(404);
            return $twig->render($r, 'errors/feature-disabled.twig', [
                'feature' => 'custom_content_types',
                'title'   => 'Custom Content Types Disabled',
            ]);
        }
        return $handler->handle($req);
    });
};
