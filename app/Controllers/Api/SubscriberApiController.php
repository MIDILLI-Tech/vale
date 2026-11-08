<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

final class SubscriberApiController
{
    private const ALLOWED_STATUSES = ['pending', 'active', 'unsubscribed'];

    public function __construct(
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly OutboundWebhookService $webhooks,
    ) {
    }

    /** GET /api/v1/subscribers */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 30)));

        $filters = [];
        if (!empty($params['status'])) {
            $filters['status'] = (string) $params['status'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = (string) $params['search'];
        }

        $items = $this->subscribers->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->subscribers->count($filters);

        return $this->json($response, [
            'data' => array_map(fn (Subscriber $s) => $this->serializeOne($s), $items),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ]);
    }

    /** GET /api/v1/subscribers/{id} */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $subscriber = $this->subscribers->findById((int) ($args['id'] ?? 0));
        if ($subscriber === null) {
            return $this->error($response, 404, 'Subscriber not found');
        }

        return $this->json($response, ['data' => $this->serializeOne($subscriber)]);
    }

    /** POST /api/v1/subscribers */
    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data  = (array) $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error($response, 422, 'A valid email address is required.');
        }

        if ($this->subscribers->findByEmail($email) !== null) {
            return $this->error($response, 422, 'This email is already subscribed.');
        }

        $subscriber = new Subscriber($email, bin2hex(random_bytes(16)));
        $this->applyWritableFields($subscriber, $data);

        $skipOptin = filter_var($data['skip_optin'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($skipOptin) {
            $subscriber->confirm();
        }

        $this->subscribers->save($subscriber);

        $this->webhooks->dispatch('subscriber.created', [
            'subscriber_id'     => $subscriber->getId(),
            'subscriber_email'  => $subscriber->getEmail(),
            'subscriber_name'   => $subscriber->getName(),
            'subscriber_status' => $subscriber->getStatus(),
            'subscriber_tier'   => $subscriber->getTier(),
            'subscriber_tags'   => $subscriber->getTags(),
        ]);

        return $this->json($response->withStatus(201), [
            'data' => $this->serializeOne($subscriber),
        ]);
    }

    /** PUT /api/v1/subscribers/{id} */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $subscriber = $this->subscribers->findById((int) ($args['id'] ?? 0));
        if ($subscriber === null) {
            return $this->error($response, 404, 'Subscriber not found');
        }

        $data = (array) $request->getParsedBody();

        if (isset($data['email'])) {
            $email = trim((string) $data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->error($response, 422, 'A valid email address is required.');
            }
            $existing = $this->subscribers->findByEmail($email);
            if ($existing !== null && $existing->getId() !== $subscriber->getId()) {
                return $this->error($response, 422, 'This email is already in use.');
            }
        }

        $this->applyWritableFields($subscriber, $data);

        $this->subscribers->save($subscriber);

        return $this->json($response, ['data' => $this->serializeOne($subscriber)]);
    }

    /** DELETE /api/v1/subscribers/{id} */
    public function destroy(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id         = (int) ($args['id'] ?? 0);
        $subscriber = $this->subscribers->findById($id);
        if ($subscriber === null) {
            return $this->error($response, 404, 'Subscriber not found');
        }

        $payload = [
            'id'    => $id,
            'email' => $subscriber->getEmail(),
        ];
        $this->subscribers->delete($id);

        return $this->json($response, ['message' => 'Subscriber deleted', 'data' => $payload]);
    }

    /** @param array<string, mixed> $data */
    private function applyWritableFields(Subscriber $subscriber, array $data): void
    {
        if (isset($data['email'])) {
            $subscriber->setEmail((string) $data['email']);
        }
        if (array_key_exists('name', $data)) {
            $name = $data['name'] !== null && $data['name'] !== '' ? (string) $data['name'] : null;
            $subscriber->setName($name);
        }
        if (isset($data['tags']) && is_array($data['tags'])) {
            $tags = array_map(static fn ($t) => trim((string) $t), $data['tags']);
            $subscriber->setTags(array_values(array_filter($tags)));
        }
        if (isset($data['tier']) && $data['tier'] !== '') {
            $subscriber->setTier((string) $data['tier']);
        }
        if (isset($data['status'])) {
            $status = (string) $data['status'];
            if (in_array($status, self::ALLOWED_STATUSES, true)) {
                if ($status === 'active' && !$subscriber->isActive()) {
                    $subscriber->confirm();
                } else {
                    $subscriber->setStatus($status);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function serializeOne(Subscriber $s): array
    {
        return [
            'id'                  => $s->getId(),
            'email'               => $s->getEmail(),
            'name'                => $s->getName(),
            'status'              => $s->getStatus(),
            'tier'                => $s->getTier(),
            'tags'                => $s->getTags(),
            'token'               => $s->getToken(),
            'source_form_id'      => $s->getSourceFormId(),
            'user_id'             => $s->getUserId(),
            'subscribed_at'       => $s->getSubscribedAt()?->format(\DateTimeInterface::ATOM),
            'confirmed_at'        => $s->getConfirmedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'          => $s->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at'          => $s->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $data */
    private function error(
        ResponseInterface $response,
        int $status,
        string $message
    ): ResponseInterface {
        return $this->json($response->withStatus($status), ['error' => $message]);
    }
}
