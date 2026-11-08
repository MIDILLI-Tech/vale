<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\WebhookAdminController;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Entity\Webhook;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class WebhookSecretPlaceholderTest extends TestCase
{
    private WebhookRepositoryInterface $webhooks;
    private WebhookAdminController $controller;
    /** @var list<Webhook> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->webhooks = $this->createStub(WebhookRepositoryInterface::class);
        $this->webhooks->method('save')->willReturnCallback(function (Webhook $webhook): Webhook {
            $this->saved[] = $webhook;
            return $webhook;
        });

        $this->controller = new WebhookAdminController(
            $this->createStub(Translator::class),
            $this->createStub(Twig::class),
            $this->webhooks,
            new OutboundWebhookService($this->webhooks),
            $this->createStub(SessionManager::class),
        );
    }

    private function storedWebhook(): Webhook
    {
        return Webhook::fromRow([
            'id'            => 7,
            'name'          => 'Order Hook',
            'url'           => 'https://8.8.8.8/hook',
            'method'        => 'POST',
            'secret'        => 'real-secret',
            'headers'       => '{}',
            'events'        => '["order.paid"]',
            'body_template' => null,
            'is_active'     => 1,
        ]);
    }

    /** @param array<string,mixed> $body */
    private function update(array $body): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/admin/webhooks/7')
            ->withParsedBody($body);

        $response = $this->controller->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '7']
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $this->saved);
    }

    /** @return array<string,mixed> */
    private function validBody(string $secret): array
    {
        return [
            'name'      => 'Order Hook',
            'url'       => 'https://8.8.8.8/hook',
            'method'    => 'POST',
            'secret'    => $secret,
            'events'    => ['order.paid'],
            'is_active' => '1',
        ];
    }

    public function testPlaceholderSecretLeavesStoredSecretUnchanged(): void
    {
        $this->webhooks->method('findById')->willReturn($this->storedWebhook());

        $this->update($this->validBody('••••••••'));

        self::assertSame('real-secret', $this->saved[0]->getSecret());
    }

    public function testBlankSecretLeavesStoredSecretUnchanged(): void
    {
        $this->webhooks->method('findById')->willReturn($this->storedWebhook());

        $this->update($this->validBody(''));

        self::assertSame('real-secret', $this->saved[0]->getSecret());
    }

    public function testNewSecretReplacesStoredSecret(): void
    {
        $this->webhooks->method('findById')->willReturn($this->storedWebhook());

        $this->update($this->validBody('brand-new-secret'));

        self::assertSame('brand-new-secret', $this->saved[0]->getSecret());
    }
}
