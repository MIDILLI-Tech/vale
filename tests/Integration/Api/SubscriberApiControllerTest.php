<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use ValeApp\App\Controllers\Api\SubscriberApiController;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;

final class SubscriberApiControllerTest extends TestCase
{
    private $subscribers;
    private $webhooks;
    private $controller;

    protected function setUp(): void
    {
        $this->subscribers = $this->createStub(SubscriberRepositoryInterface::class);

        $webhookRepo = $this->createStub(WebhookRepositoryInterface::class);
        $webhookRepo->method('findActive')->willReturn([]);
        $this->webhooks = new OutboundWebhookService($webhookRepo);

        $this->controller = new SubscriberApiController(
            $this->subscribers,
            $this->webhooks,
        );
    }

    public function testStoreValidatesEmptyEmail(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/subscribers')
            ->withParsedBody([]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('A valid email address is required.', $body['error']);
    }

    public function testStoreValidatesEmailFormat(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/subscribers')
            ->withParsedBody(['email' => 'not-an-email']);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('A valid email address is required.', $body['error']);
    }

    public function testStoreRejectsDuplicateEmail(): void
    {
        $this->subscribers->method('findByEmail')->willReturnCallback(function (string $email) {
            self::assertSame('existing@example.com', $email);
            return new Subscriber('existing@example.com', 'token');
        });

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/subscribers')
            ->withParsedBody(['email' => 'existing@example.com']);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('This email is already subscribed.', $body['error']);
    }

    public function testStoreCreatesSubscriberWithOptIn(): void
    {
        $this->subscribers->method('findByEmail')->willReturn(null);
        $this->subscribers->method('save')->willReturnCallback(function (Subscriber $s) {
            $s->setId(1);
            return $s;
        });

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/subscribers')
            ->withParsedBody([
                'email'      => 'test@example.com',
                'name'       => 'Test',
                'skip_optin' => true,
            ]);
        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(1, $body['data']['id']);
        self::assertSame('active', $body['data']['status']);
        self::assertSame('Test', $body['data']['name']);
    }

    public function testStoreCreatesSubscriberPending(): void
    {
        $this->subscribers->method('findByEmail')->willReturn(null);
        $this->subscribers->method('save')->willReturnCallback(function (Subscriber $s) {
            $s->setId(2);
            return $s;
        });

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/subscribers')
            ->withParsedBody(['email' => 'pending@example.com']);
        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('pending', $body['data']['status']);
    }

    public function testIndexReturnsList(): void
    {
        $this->subscribers->method('findAll')->willReturn([]);
        $this->subscribers->method('count')->willReturn(0);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/subscribers');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    public function testShowReturnsSubscriber(): void
    {
        $subscriber = new Subscriber('show@example.com', 'token');
        $subscriber->setId(5);
        $this->subscribers->method('findById')->willReturnCallback(
            function (int $id) use ($subscriber) {
                self::assertSame(5, $id);
                return $subscriber;
            }
        );

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/subscribers/5');
        $response = $this->controller->show(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '5']
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(5, $body['data']['id']);
    }

    public function testShowReturns404IfNotFound(): void
    {
        $this->subscribers->method('findById')->willReturn(null);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/subscribers/999');
        $response = $this->controller->show(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testUpdateExistingSubscriber(): void
    {
        $subscriber = new Subscriber('old@example.com', 'token');
        $subscriber->setId(3);
        $this->subscribers->method('findById')->willReturnCallback(
            function (int $id) use ($subscriber) {
                self::assertSame(3, $id);
                return $subscriber;
            }
        );
        $this->subscribers->method('findByEmail')->willReturn(null);
        $this->subscribers->method('save')->willReturn($subscriber);

        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/subscribers/3')
            ->withParsedBody(['name' => 'New Name', 'tier' => 'premium', 'tags' => ['a', 'b']]);
        $response = $this->controller->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '3']
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('New Name', $body['data']['name']);
        self::assertSame('premium', $body['data']['tier']);
        self::assertSame(['a', 'b'], $body['data']['tags']);
    }

    public function testDestroySubscriber(): void
    {
        $subscriber = new Subscriber('del@example.com', 'token');
        $subscriber->setId(9);
        $this->subscribers->method('findById')->willReturnCallback(
            function (int $id) use ($subscriber) {
                self::assertSame(9, $id);
                return $subscriber;
            }
        );

        $deleted = null;
        $this->subscribers->method('delete')->willReturnCallback(
            function (int $id) use (&$deleted): void {
                $deleted = $id;
            }
        );

        $request  = (new RequestFactory())->createRequest('DELETE', '/api/v1/subscribers/9');
        $response = $this->controller->destroy(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '9']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(9, $deleted);
    }

    public function testDestroyReturns404IfNotFound(): void
    {
        $this->subscribers->method('findById')->willReturn(null);

        $request  = (new RequestFactory())->createRequest('DELETE', '/api/v1/subscribers/999');
        $response = $this->controller->destroy(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '999']
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
