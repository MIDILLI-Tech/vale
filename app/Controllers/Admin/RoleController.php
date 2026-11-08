<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Roles\Entity\Role;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class RoleController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly RoleRepositoryInterface $roles,
        private readonly SessionManager $session,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $roles       = $this->roles->findAll();
        $permissions = $this->roles->findAllPermissions();
        $total       = count($roles);

        return $this->twig->render($response, 'admin/roles/index.twig', [
            'roles'          => $roles,
            'permissions'    => $permissions,
            'groupLabels'    => $this->groupLabels(),
            'total'          => $total,
            'client_side'    => true,
            'page'           => 1,
            'per_page'       => 20,
            'base_url'       => admin_url('/roles'),
            'flash_success'  => $this->session->flash('flash_success'),
            'flash_error'    => $this->session->flash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/roles/form.twig', [
            'role'        => null,
            'is_edit'     => false,
            'permissions' => $this->roles->findAllPermissions(),
            'groupLabels' => $this->groupLabels(),
            'errors'      => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $errors = $this->validate($body);

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'admin/roles/form.twig', [
                'role'        => null,
                'is_edit'     => false,
                'permissions' => $this->roles->findAllPermissions(),
                'groupLabels' => $this->groupLabels(),
                'errors'      => $errors,
                'old'         => $body,
            ]);
        }

        $slug = $this->makeSlug($body['name'] ?? '');
        $role = new Role($slug, trim($body['name']));
        $role->setDescription(!empty($body['description']) ? trim($body['description']) : null);

        $this->roles->save($role);
        $this->roles->syncPermissions((int) $role->getId(), array_map('intval', (array) ($body['permission_ids'] ?? [])));

        $this->session->set('flash_success', $this->translator->get('Role created.'));
        return $response->withHeader('Location', admin_url('/roles'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $role = $this->roles->findById((int) $args['id']);
        if ($role === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        return $this->twig->render($response, 'admin/roles/form.twig', [
            'role'        => $role,
            'is_edit'     => true,
            'permissions' => $this->roles->findAllPermissions(),
            'groupLabels' => $this->groupLabels(),
            'errors'      => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $role = $this->roles->findById((int) $args['id']);
        if ($role === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $body   = (array) $request->getParsedBody();
        $errors = $this->validate($body);

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'admin/roles/form.twig', [
                'role'        => $role,
                'is_edit'     => true,
                'permissions' => $this->roles->findAllPermissions(),
                'groupLabels' => $this->groupLabels(),
                'errors'      => $errors,
                'old'         => $body,
            ]);
        }

        $previousPermissionIds = $role->getPermissionIds();

        if (!$role->isSystem()) {
            $role->setSlug($this->makeSlug($body['name'] ?? ''));
        }
        $role->setName(trim($body['name']));
        $role->setDescription(!empty($body['description']) ? trim($body['description']) : null);

        $this->roles->save($role);
        $permissionIds = array_map('intval', (array) ($body['permission_ids'] ?? []));
        $this->roles->syncPermissions((int) $role->getId(), $permissionIds);

        // Force re-login for affected users when permissions changed
        if ($this->permissionIdsChanged($previousPermissionIds, $permissionIds)) {
            $this->users->incrementSessionVersionForRole((int) $role->getId());
        }

        // Refresh the role entity with updated permissions
        $role = $this->roles->findById((int) $role->getId());

        $this->session->set('flash_success', $this->translator->get('Role updated.'));
        return $response->withHeader('Location', admin_url('/roles/' . $args['id'] . '/edit'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $role = $this->roles->findById((int) $args['id']);
        if ($role !== null && !$role->isSystem()) {
            // Bump before delete — the pivot rows are gone after CASCADE
            $this->users->incrementSessionVersionForRole((int) $role->getId());
            $this->roles->delete((int) $role->getId());
            $this->session->set('flash_success', $this->translator->get('Role deleted.'));
        } else {
            $this->session->set('flash_error', $this->translator->get('System roles cannot be deleted.'));
        }

        return $response->withHeader('Location', admin_url('/roles'))->withStatus(302);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, string> slug => label */
    private function groupLabels(): array
    {
        $map = [];
        foreach ($this->contentTypes->findAll() as $ct) {
            $map["cpt_{$ct->getSlug()}"] = 'CPT: ' . $ct->getName();
        }
        return $map;
    }

    /** @param array<string, mixed> $body
     *  @return array<string, string> */
    private function validate(array $body): array
    {
        $errors = [];
        if (empty(trim((string) ($body['name'] ?? '')))) {
            $errors['name'] = $this->translator->get('Name is required.');
        }
        return $errors;
    }

    private function makeSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)) ?? '');
    }

    /** @param int[] $previous @param int[] $current */
    private function permissionIdsChanged(array $previous, array $current): bool
    {
        $previous = array_map('intval', $previous);
        sort($previous);
        $current = array_map('intval', $current);
        sort($current);

        return $previous !== $current;
    }
}
