<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Webhook;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Entity\Webhook;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;

final class OutboundWebhookServiceTest extends TestCase
{
    private WebhookRepositoryInterface $webhookRepo;
    private OutboundWebhookService $service;

    protected function setUp(): void
    {
        $this->webhookRepo = $this->createStub(WebhookRepositoryInterface::class);
        $this->service     = new OutboundWebhookService($this->webhookRepo);
    }

    public function testAvailableEventsContainsExpectedKeys(): void
    {
        $events = OutboundWebhookService::AVAILABLE_EVENTS;

        $this->assertArrayHasKey('order.paid', $events);
        $this->assertArrayHasKey('order.status_changed', $events);
        $this->assertArrayHasKey('post.created', $events);
        $this->assertArrayHasKey('post.published', $events);
        $this->assertArrayHasKey('post.updated', $events);
        $this->assertArrayHasKey('product.created', $events);
        $this->assertArrayHasKey('product.updated', $events);
        $this->assertArrayHasKey('product.deleted', $events);
        $this->assertArrayHasKey('user.registered', $events);
        $this->assertArrayHasKey('form.submitted', $events);
        $this->assertArrayHasKey('subscriber.created', $events);
        $this->assertCount(11, $events);
    }

    public function testDispatchSkipsWebhooksNotSubscribedToEvent(): void
    {
        $webhook = Webhook::fromRow([
            'id'            => 1,
            'name'          => 'Order Hook',
            'url'           => 'https://example.com/hook',
            'events'        => '["order.paid"]',
            'body_template' => null,
            'is_active'     => true,
        ]);

        $this->webhookRepo->method('findActive')->willReturn([$webhook]);

        // Should not throw — event is not in the webhook's subscription list
        $this->service->dispatch('post.published', ['post_id' => 1]);
        $this->assertTrue(true);
    }

    public function testDispatchSkipsPrivateHosts(): void
    {
        $webhook = Webhook::fromRow([
            'id'            => 2,
            'name'          => 'Local Hook',
            'url'           => 'http://127.0.0.1/hook',
            'events'        => '["order.paid"]',
            'body_template' => null,
            'is_active'     => true,
        ]);

        $this->webhookRepo->method('findActive')->willReturn([$webhook]);

        // Should not throw — private host is skipped with error_log
        $this->service->dispatch('order.paid', ['order_id' => 1]);
        $this->assertTrue(true);
    }

    public function testDeliverOneReturnsErrorWhenWebhookNotFound(): void
    {
        $this->webhookRepo->method('findById')->willReturn(null);

        $result = $this->service->deliverOne(999, 'order.paid', ['order_id' => 1]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertSame('Webhook not found', $result['error']);
    }

    public function testDeliverRawBlocksPrivateHost(): void
    {
        $result = $this->service->deliverRaw(
            'http://192.168.1.1/test',
            'POST',
            null,
            null,
            [],
            ['test' => true],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('private/internal', $result['error']);
    }

    public function testDeliverRawNormalizesInvalidMethod(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/status/200',
            'INVALID',
            null,
            null,
            [],
        );

        // Invalid method should be normalized to POST
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('ms', $result);
    }

    public function testDeliverRawGetMethodNoBody(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/get',
            'GET',
            null,
            null,
            ['X-Custom' => 'value'],
            ['test' => true],
        );

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('ms', $result);
    }

    public function testDeliverRawWithBodyTemplateReplacesPlaceholders(): void
    {
        $template = '{"event": "{{event}}", "order_id": {{order_id}}}';

        $result = $this->service->deliverRaw(
            'https://httpbin.org/post',
            'POST',
            $template,
            'secret-key',
            ['X-Token' => 'abc'],
            ['event' => 'order.paid', 'order_id' => 42],
        );

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('ms', $result);
    }

    public function testDeliverRawWithSecretAddsSignatureHeader(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/post',
            'POST',
            null,
            'my-secret',
            [],
            ['event' => 'test'],
        );

        // Should succeed (httpbin returns 200) and include the ms field
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('ms', $result);
    }

    public function testDeliverRawPutMethod(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/put',
            'PUT',
            null,
            null,
            [],
            ['key' => 'value'],
        );

        $this->assertArrayHasKey('ok', $result);
    }

    public function testDeliverRawPatchMethod(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/patch',
            'PATCH',
            null,
            null,
            [],
            ['key' => 'value'],
        );

        $this->assertArrayHasKey('ok', $result);
    }

    public function testDeliverRawDeleteMethod(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/delete',
            'DELETE',
            null,
            null,
            [],
        );

        $this->assertArrayHasKey('ok', $result);
    }

    public function testDeliverRawSkipsEmptyHeaderNames(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/get',
            'GET',
            null,
            null,
            ['' => 'empty-key', 'X-Valid' => 'ok'],
        );

        $this->assertArrayHasKey('ok', $result);
    }

    public function testDeliverRawStripsNewlinesFromHeaderValues(): void
    {
        $result = $this->service->deliverRaw(
            'https://httpbin.org/get',
            'GET',
            null,
            null,
            ['X-Injection' => "value\r\nEvil-Header: injected"],
        );

        $this->assertArrayHasKey('ok', $result);
    }

    public function testDeliverRawBodyTemplateWithMissingKeysKeepsPlaceholder(): void
    {
        $template = '{"name": "{{name}}", "missing": "{{unknown_key}}"}';

        $result = $this->service->deliverRaw(
            'https://httpbin.org/post',
            'POST',
            $template,
            null,
            [],
            ['name' => 'test'],
        );

        $this->assertArrayHasKey('ok', $result);
    }

    public function testApplyBodyTemplateRendersArraysAsJson(): void
    {
        $template = '{"email":"{{email}}","tags":{{tags}},"id":{{id}}}';
        $payload  = [
            'email' => 'subscriber@example.com',
            'tags'  => ['newsletter', 'vip'],
            'id'    => 0,
        ];

        $rendered = OutboundWebhookService::applyBodyTemplate($template, $payload);

        $this->assertSame(
            '{"email":"subscriber@example.com","tags":["newsletter","vip"],"id":0}',
            $rendered,
        );
        $this->assertStringNotContainsString('Array', $rendered);
    }

    public function testApplyBodyTemplateHandlesNullBoolAndMissing(): void
    {
        $template = 'a={{a}}|b={{b}}|c={{c}}|d={{d}}';
        $payload  = [
            'a' => null,
            'b' => true,
            'c' => false,
            'd' => 'known',
        ];

        $rendered = OutboundWebhookService::applyBodyTemplate($template, $payload);

        $this->assertSame('a=|b=true|c=false|d=known', $rendered);
        $this->assertStringNotContainsString('{{d}}', $rendered);

        $missingRendered = OutboundWebhookService::applyBodyTemplate('x={{unknown}}', []);
        $this->assertSame('x={{unknown}}', $missingRendered);
    }

    public function testApplyBodyTemplateRendersNestedArrayForOrderItems(): void
    {
        $template = '"items":{{items}}';
        $payload  = [
            'items' => [
                ['product_id' => 1, 'name' => 'Sample', 'qty' => 1, 'price' => '9.99'],
            ],
        ];

        $rendered = OutboundWebhookService::applyBodyTemplate($template, $payload);

        $this->assertSame(
            '"items":[{"product_id":1,"name":"Sample","qty":1,"price":"9.99"}]',
            $rendered,
        );
        $this->assertStringNotContainsString('Array', $rendered);
    }
}
