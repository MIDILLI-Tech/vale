<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Infrastructure\Admin\NavOrderService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Handles AJAX persistence of admin sidebar order and quick-actions order.
 */
final class NavOrderController
{
    /** Allowed quick-action IDs (must match qa_static_actions + new_cpt_* in FlashMiddleware). */
    private const QUICK_ACTION_IDS = [
        'new_post', 'new_page', 'new_user', 'new_product', 'view_orders',
        'new_campaign', 'new_subscriber', 'new_coupon', 'optimize', 'warm_cache',
    ];

    public function __construct(
        private readonly NavOrderService $navOrderService,
        private readonly PluginManager $plugins,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    /**
     * POST /admin/nav-order
     * Body: JSON { "order": ["dashboard","users","group_content", …] }
     */
    public function saveNavOrder(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['order']) || !is_array($data['order'])) {
            return $this->json($response, ['error' => 'Invalid payload'], 400);
        }

        $sanitized = $this->navOrderService->sanitizeOrder($data['order'], $this->pluginNavIds());

        $this->settings->set('admin_nav_order', json_encode($sanitized), 'general');

        if (isset($data['children']) && is_array($data['children'])) {
            $childOrder = $this->navOrderService->sanitizeChildOrder(
                $data['children'],
                $this->pluginChildGroups(),
            );
            $this->settings->set('admin_nav_child_order', json_encode($childOrder), 'general');
        }

        return $this->json($response, ['ok' => true]);
    }

    /**
     * POST /admin/quick-actions-order
     * Body: JSON { "order": ["new_post","new_page", …] }
     */
    public function saveQuickActionsOrder(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['order']) || !is_array($data['order'])) {
            return $this->json($response, ['error' => 'Invalid payload'], 400);
        }

        $sanitized = array_values(array_filter(
            $data['order'],
            static function ($id) {
                if (!is_string($id)) {
                    return false;
                }
                if (in_array($id, self::QUICK_ACTION_IDS, true)) {
                    return true;
                }
                return str_starts_with($id, 'new_cpt_');
            }
        ));

        $this->settings->set('admin_quick_actions_order', json_encode($sanitized), 'general');

        return $this->json($response, ['ok' => true]);
    }

    /**
     * Declarative plugin entries use `plugin-<slug>` ids; they are only valid
     * while the plugin is enabled, matching what the sidebar can render.
     *
     * @return list<string>
     */
    private function pluginNavIds(): array
    {
        return array_map(
            static fn (string $slug): string => 'plugin-' . $slug,
            $this->plugins->getEnabledSlugs(),
        );
    }

    /**
     * @return array<string, string> plugin nav id => declared group id
     */
    private function pluginChildGroups(): array
    {
        $groups = [];
        foreach ($this->plugins->findAll() as $plugin) {
            if (($plugin['enabled'] ?? false) !== true) {
                continue;
            }

            $admin = $plugin['admin'] ?? null;
            $group = is_array($admin) ? ($admin['group'] ?? null) : null;
            if (is_string($group) && $group !== '') {
                $groups['plugin-' . $plugin['slug']] = $group;
            }
        }

        return $groups;
    }

    private function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
