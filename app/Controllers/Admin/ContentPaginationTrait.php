<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use ValeApp\Domain\Shared\ValueObject\Pagination;

trait ContentPaginationTrait
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $filters
     * @return array{Pagination, bool, int}
     */
    private function resolvePagination(array $params, array &$filters, int $total): array
    {
        // search_body forces a server round-trip so content search works
        // even on small catalogs that otherwise render client-side.
        $clientSide = $total <= client_side_search_fetch_limit() && empty($params['search_body']);

        if ($clientSide) {
            $pagination = new Pagination(1, 1000000);
        } else {
            if (!empty($params['search'])) {
                $filters['search'] = $params['search'];
                if (!empty($params['search_body'])) {
                    $filters['search_body'] = true;
                }
                $total             = $this->posts->count($filters);
            }
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');
        }

        return [$pagination, $clientSide, $total];
    }
}
