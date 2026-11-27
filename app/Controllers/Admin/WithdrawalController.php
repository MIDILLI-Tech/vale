<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\WithdrawalRequest;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\WithdrawalRequestRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class WithdrawalController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly WithdrawalRequestRepositoryInterface $requests,
        private readonly OrderRepositoryInterface $orders,
        private readonly SessionManager $session,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params   = $request->getQueryParams();
        $status   = trim((string) ($params['status'] ?? ''));
        $search   = trim((string) ($params['search'] ?? ''));
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = max(1, min(100, (int) ($params['per_page'] ?? 25)));

        $filters = array_filter([
            'status' => $status,
            'search' => $search,
        ], static fn (string $value): bool => $value !== '');

        $pagination = new Pagination($page, $perPage);
        $total      = $this->requests->count($filters);
        $requests   = $this->requests->findAll($pagination, $filters);

        $query = array_filter([
            'status'   => $status,
            'search'   => $search,
            'per_page' => $perPage !== 25 ? (string) $perPage : '',
        ], static fn (string $value): bool => $value !== '');

        return $this->twig->render($response, 'admin/withdrawals/index.twig', [
            'requests'    => $requests,
            'total'       => $total,
            'counts'      => $this->requests->countByStatus(),
            'filters'     => ['status' => $status, 'search' => $search],
            'pagination'  => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $pagination->totalPages($total),
                'base_url'    => admin_url('/withdrawals') . ($query !== [] ? '?' . http_build_query($query) : ''),
            ],
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $withdrawal = $this->requests->findById((int) ($args['id'] ?? 0));
        if ($withdrawal === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $order = $withdrawal->getOrderId() !== null
            ? $this->orders->findById((int) $withdrawal->getOrderId())
            : null;

        return $this->twig->render($response, 'admin/withdrawals/show.twig', [
            'withdrawal' => $withdrawal,
            'order'      => $order,
            'statuses'   => WithdrawalRequest::STATUSES,
        ]);
    }

    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $withdrawal = $this->requests->findById((int) ($args['id'] ?? 0));
        if ($withdrawal === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $body   = (array) $request->getParsedBody();
        $status = (string) ($body['status'] ?? '');

        if (!in_array($status, WithdrawalRequest::STATUSES, true)) {
            $this->session->flash('error', $this->translator->get('Unknown status.'));

            return $this->redirect($response, (int) $withdrawal->getId());
        }

        $withdrawal->setStatus($status);
        $withdrawal->setAdminNote((string) ($body['admin_note'] ?? ''));
        if ($status === WithdrawalRequest::STATUS_PENDING) {
            $withdrawal->setProcessedAt(null);
        } else {
            $withdrawal->markProcessed();
        }
        $this->requests->save($withdrawal);

        $this->session->flash('success', $this->translator->get('Withdrawal :reference updated.', [':reference' => $withdrawal->getReference()]));

        return $this->redirect($response, (int) $withdrawal->getId());
    }

    private function redirect(ResponseInterface $response, int $id): ResponseInterface
    {
        return $response->withHeader('Location', admin_url('/withdrawals/' . $id))->withStatus(302);
    }
}
