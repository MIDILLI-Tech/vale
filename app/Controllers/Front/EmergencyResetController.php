<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Security\RateLimiter;

final class EmergencyResetController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Connection $db,
        private readonly RateLimiter $rateLimiter,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        if (!$this->isTokenValid($args['token'] ?? '')) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        return $this->twig->render($response, 'auth/emergency-reset.twig', [
            'token' => $args['token'],
        ]);
    }

    public function reset(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';

        if (!$this->isTokenValid($token)) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $ip = vl_client_ip($request);
        if (!$this->rateLimiter->attempt('emergency_reset', $ip, 5, 900)) {
            return $this->twig->render($response->withStatus(429), 'auth/emergency-reset.twig', [
                'token'  => $token,
                'errors' => ['Too many attempts. Please wait 15 minutes.'],
            ]);
        }

        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email'] ?? '');
        $password = $body['password']         ?? '';
        $confirm  = $body['password_confirm'] ?? '';

        $errors = [];

        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'auth/emergency-reset.twig', [
                'token'  => $token,
                'errors' => $errors,
                'email'  => $email,
            ]);
        }

        try {
            $emailVo = new Email($email);
        } catch (\DomainException) {
            return $this->twig->render($response->withStatus(422), 'auth/emergency-reset.twig', [
                'token'  => $token,
                'errors' => ['Invalid email address.'],
                'email'  => $email,
            ]);
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name FROM ' . VL_DB_PREFIX . 'users' . ' WHERE email = ?',
            [(string) $emailVo],
        );

        if (!$user) {
            return $this->twig->render($response->withStatus(422), 'auth/emergency-reset.twig', [
                'token'  => $token,
                'errors' => ['User not found with that email.'],
                'email'  => $email,
            ]);
        }

        $hash = $this->hasher->hash($password);

        $this->db->update(VL_DB_PREFIX . 'users', [
            'password'                  => $hash,
            'two_factor_enabled'        => 0,
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'updated_at'                => date('Y-m-d H:i:s'),
        ], ['id' => $user['id']]);

        // Invalidate any active sessions for this user.
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'users SET session_version = session_version + 1 WHERE id = ?',
            [$user['id']],
        );

        return $this->twig->render($response, 'auth/emergency-reset.twig', [
            'token'   => $token,
            'success' => true,
            'name'    => $user['name'],
        ]);
    }

    private function isTokenValid(string $token): bool
    {
        $envToken = $_ENV['EMERGENCY_RESET_TOKEN'] ?? '';
        if ($envToken === '') {
            return false;
        }

        return hash_equals($envToken, $token);
    }
}
