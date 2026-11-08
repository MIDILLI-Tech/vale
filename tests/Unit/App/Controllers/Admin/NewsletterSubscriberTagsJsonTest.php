<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\NewsletterController;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class NewsletterSubscriberTagsJsonTest extends TestCase
{
    private function makeController(array $tags): NewsletterController
    {
        $subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $subscribers->method('getAllTags')->willReturn($tags);

        // Final collaborators cannot be doubled — instantiate without constructor.
        $withoutConstructor = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NewsletterController(
            $this->createStub(Translator::class),
            $this->createStub(Twig::class),
            $subscribers,
            $this->createStub(CampaignRepositoryInterface::class),
            $this->createStub(SessionManager::class),
            $this->createStub(FormRepositoryInterface::class),
            $withoutConstructor(NewsletterService::class),
            $withoutConstructor(MailService::class),
            $withoutConstructor(AutomationService::class),
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $withoutConstructor(AdminSearchCache::class),
            $withoutConstructor(SubscriptionService::class),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(PlanRepositoryInterface::class),
        );
    }

    /** @return array{data: array, total: int, truncated: bool} */
    private function fetchTags(NewsletterController $controller, string $uri): array
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        $response = new Response();

        $res = $controller->subscriberTagsJson($request, $response);
        $res->getBody()->rewind();

        self::assertSame('application/json', $res->getHeaderLine('Content-Type'));
        return json_decode((string) $res->getBody(), true);
    }

    public function testReturnsAllTagsWithoutQuery(): void
    {
        $payload = $this->fetchTags(
            $this->makeController(['promo', 'vip']),
            '/admin/newsletter/subscriber-tags/list-json',
        );

        self::assertSame([['name' => 'promo'], ['name' => 'vip']], $payload['data']);
        self::assertSame(2, $payload['total']);
        self::assertFalse($payload['truncated']);
    }

    public function testFiltersByQueryCaseInsensitively(): void
    {
        $payload = $this->fetchTags(
            $this->makeController(['promo', 'VIP', 'press']),
            '/admin/newsletter/subscriber-tags/list-json?q=vip',
        );

        self::assertSame([['name' => 'VIP']], $payload['data']);
        self::assertSame(1, $payload['total']);
        self::assertFalse($payload['truncated']);
    }

    public function testTruncatesToLimit(): void
    {
        $payload = $this->fetchTags(
            $this->makeController(['a', 'b', 'c']),
            '/admin/newsletter/subscriber-tags/list-json?limit=2',
        );

        self::assertSame([['name' => 'a'], ['name' => 'b']], $payload['data']);
        self::assertSame(3, $payload['total']);
        self::assertTrue($payload['truncated']);
    }
}
