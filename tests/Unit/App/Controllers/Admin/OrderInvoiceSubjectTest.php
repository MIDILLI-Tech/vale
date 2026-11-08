<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\OrderController;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\OrderLog;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;
use ValeApp\Tests\Support\OrderControllerCollaborators;

final class OrderInvoiceSubjectTest extends TestCase
{
    use OrderControllerCollaborators;

    public function testInvoiceSubjectUsesCustomerLocale(): void
    {
        $order = new Order('ORD-2026-1003', [
            'first_name' => 'Mehmet',
            'last_name'  => 'Yilmaz',
            'email'      => 'm.yilmaz@example.com',
            'city'       => 'Istanbul',
            'country'    => 'TR',
        ]);
        $order->setId(3);
        $order->setStatus('processing');
        $order->setLocale('tr');

        $snapshot = [
            'invoice_number' => 'INV-ORD-2026-1003',
            'issued_at'      => '2026-09-26 11:20:19',
            'billing'        => ['email' => 'm.yilmaz@example.com'],
        ];
        $logs = [
            OrderLog::fromRow([
                'id'         => 11,
                'order_id'   => 3,
                'event'      => 'invoice_issued',
                'note'       => json_encode($snapshot),
                'created_at' => '2026-09-26 11:20:19',
                'type'       => 'system',
                'user_id'    => 1,
                'added_by'   => 'Admin',
            ]),
        ];

        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);

        $orderLogs = $this->createStub(OrderLogRepositoryInterface::class);
        $orderLogs->method('findByOrderId')->willReturn($logs);

        $translator = $this->createMock(Translator::class);
        $translator->expects(self::once())->method('get')->with(
            'Invoice :number',
            [':number' => 'INV-ORD-2026-1003'],
            'tr',
        )->willReturn('Fatura INV-ORD-2026-1003');

        $sent   = [];
        $mailer = $this->createMock(MailInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(
            static function (MailMessage $message) use (&$sent): void {
                $sent[] = [$message->to, $message->subject];
            },
        );

        $twigEnv = $this->createStub(\Twig\Environment::class);
        $twigEnv->method('render')->willReturn('');
        $twig = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($twigEnv);

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'mail_driver' => 'log',
                default       => $default,
            },
        );

        $mail = new MailService($mailer, $twig, $settings, $translator, $this->createStub(ThemeManager::class));

        $session = $this->createStub(SessionManager::class);

        $collaborators = $this->makeOrderCollaborators(
            $orders,
            $orderLogs,
            $this->createStub(EventDispatcherInterface::class),
        );

        $controller = new OrderController(
            translator: $translator,
            twig: $this->createStub(Twig::class),
            orders: $orders,
            session: $session,
            products: $this->createStub(ProductRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            orderLogs: $orderLogs,
            settings: $settings,
            countryTaxRates: $this->createStub(CountryTaxRateRepositoryInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            orderStatusEffects: $collaborators['effects'],
            changeOrderStatus: $collaborators['changeOrderStatus'],
            updateOrderTracking: $collaborators['updateOrderTracking'],
            orderEstimator: $collaborators['estimator'],
            carrierCatalog: $collaborators['carriers'],
            mail: $mail,
            searchCache: new AdminSearchCache($this->createStub(CacheInterface::class)),
        );

        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/3/invoice/send');
        $response = $controller->sendInvoiceToCustomer($request, (new ResponseFactory())->createResponse(), ['id' => '3']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame([['m.yilmaz@example.com', 'Fatura INV-ORD-2026-1003']], $sent);
    }
}
