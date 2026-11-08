<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

final class RefundPolicyTest extends TestCase
{
    public function testResolveEffectiveRefundModeInheritance(): void
    {
        $products   = $this->createStub(ProductRepositoryInterface::class);
        $orders     = $this->createStub(OrderRepositoryInterface::class);
        $session    = $this->createStub(SessionManager::class);
        $settings   = $this->createStub(DbalSettingsRepository::class);
        $twig       = $this->createStub(Twig::class);
        $forms      = $this->createStub(FormRepositoryInterface::class);
        $coupons    = $this->createStub(CouponRepositoryInterface::class);
        $countryTax = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $events     = $this->createStub(EventDispatcherInterface::class);
        $webhooks   = new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class));

        $controller = new CheckoutController(
            $twig,
            $products,
            $orders,
            $session,
            $settings,
            $forms,
            $coupons,
            $countryTax,
            $events,
            $webhooks
        );

        $prod = new Product('Test T-Shirt', new Slug('test-t-shirt'));
        $prod->setRefundMode('non_refundable');

        // Product level setting
        $this->assertSame('non_refundable', $controller->resolveEffectiveRefundMode($prod, null));

        // Variation level override
        $variationOverride = ['refund_mode' => 'allow'];
        $this->assertSame('allow', $controller->resolveEffectiveRefundMode($prod, $variationOverride));

        // Variation use_parent
        $variationParent = ['refund_mode' => 'use_parent'];
        $this->assertSame('non_refundable', $controller->resolveEffectiveRefundMode($prod, $variationParent));
    }

    public function testOrderRefundabilityAnalysisWithDownloadedDigitalItem(): void
    {
        $products     = $this->createStub(ProductRepositoryInterface::class);
        $orders       = $this->createStub(OrderRepositoryInterface::class);
        $session      = $this->createStub(SessionManager::class);
        $settings     = $this->createStub(DbalSettingsRepository::class);
        $twig         = $this->createStub(Twig::class);
        $forms        = $this->createStub(FormRepositoryInterface::class);
        $coupons      = $this->createStub(CouponRepositoryInterface::class);
        $countryTax   = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $events       = $this->createStub(EventDispatcherInterface::class);
        $webhooks     = new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class));
        $downloadRepo = $this->createStub(DownloadRepositoryInterface::class);

        $prod = new Product('E-Book PDF', new Slug('e-book-pdf'));
        $prod->setId(10);
        $prod->setRefundMode('allow');

        $products->method('findByIds')->willReturn([$prod]);
        $products->method('findVariationsByProductIds')->willReturn([]);

        $downloadRepo->method('findGrantsByOrderId')->willReturn([
            [
                'id'             => 1,
                'order_id'       => 100,
                'product_id'     => 10,
                'variation_id'   => null,
                'download_count' => 1,
            ]
        ]);

        $controller = new CheckoutController(
            $twig,
            $products,
            $orders,
            $session,
            $settings,
            $forms,
            $coupons,
            $countryTax,
            $events,
            $webhooks,
            [],
            null,
            null,
            null,
            null,
            null,
            $downloadRepo
        );

        $order = new Order('ORD-100', ['email' => 'test@example.com'], 1);
        $order->setId(100);
        $order->setItems([
            [
                'product_id' => 10,
                'name'       => 'E-Book PDF',
                'qty'        => 1,
                'price'      => 15.00,
            ]
        ]);

        $summary = $controller->analyzeOrderRefundability($order);

        $this->assertTrue($summary['has_non_refundable']);
        $this->assertTrue($summary['all_non_refundable']);
        $this->assertSame(1, $summary['non_refundable_count']);
    }
}
