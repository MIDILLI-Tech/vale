<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

final class ForgotPasswordController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Connection $db,
        private readonly UserRepositoryInterface $users,
        private readonly MailService $mail,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function showForgot(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'auth/forgot-password.twig', [
            'mail_driver' => $this->settings->get('mail_driver', 'log'),
        ]);
    }

    public function sendReset(Request $request, Response $response): Response
    {
        $body       = (array) $request->getParsedBody();
        $email      = trim($body['email'] ?? '');
        $mailDriver = $this->settings->get('mail_driver', 'log');

        $respArgs = [
            'mail_driver' => $mailDriver,
            'email'       => $email,
        ];

        if ($email === '') {
            $respArgs['error'] = 'Email is required.';
            return $this->twig->render($response->withStatus(422), 'auth/forgot-password.twig', $respArgs);
        }

        try {
            $emailVo = new Email($email);
        } catch (\DomainException) {
            $respArgs['error'] = 'Invalid email address.';
            return $this->twig->render($response->withStatus(422), 'auth/forgot-password.twig', $respArgs);
        }

        $user = $this->users->findByEmail($emailVo);

        // Always show success (don't reveal whether email exists)
        if ($user !== null) {
            // Invalidate any previously issued, unused reset tokens for this user.
            $this->db->executeStatement(
                'DELETE FROM ' . VL_DB_PREFIX . 'password_resets WHERE user_id = ? AND used_at IS NULL',
                [$user->getId()],
            );

            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);

            $this->db->insert(VL_DB_PREFIX . 'password_resets', [
                'user_id'    => $user->getId(),
                'token_hash' => $hash,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $resetUrl = absolute_url('/reset-password/' . $token, [], ['lang' => $user->getLocale()]);

            try {
                $this->mail->sendTemplate(
                    (string) $emailVo,
                    $this->settings->get('site_name', 'Vale') . ' — ' . $this->mail->getTranslator()->get('Reset your password', [], $user->getLocale()),
                    'mail/password-reset',
                    [
                        'name'      => $user->getName(),
                        'reset_url' => $resetUrl,
                    ],
                    locale: $user->getLocale(),
                    recipientType: 'customer',
                );
            } catch (\Throwable $e) {
                // Mail send failed — still show success for security
            }
        }

        // Show log hint when mail_driver is 'log'
        if ($mailDriver === 'log') {
            $respArgs['log_hint'] = true;
        }

        $respArgs['sent'] = true;
        return $this->twig->render($response, 'auth/forgot-password.twig', $respArgs);
    }

    public function showReset(Request $request, Response $response, array $args): Response
    {
        $token   = $args['token'] ?? '';
        $hash    = hash('sha256', $token);
        $row     = $this->findValidToken($hash);

        if ($row === null) {
            return $this->twig->render($response->withStatus(404), 'auth/reset-password.twig', [
                'invalid' => true,
            ]);
        }

        return $this->twig->render($response, 'auth/reset-password.twig', [
            'token'   => $token,
            'expires' => $row['expires_at'],
        ]);
    }

    public function doReset(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'] ?? '';
        $hash  = hash('sha256', $token);
        $row   = $this->findValidToken($hash);

        if ($row === null) {
            return $this->twig->render($response->withStatus(404), 'auth/reset-password.twig', [
                'invalid' => true,
            ]);
        }

        $body     = (array) $request->getParsedBody();
        $password = $body['password']         ?? '';
        $confirm  = $body['password_confirm'] ?? '';

        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return $this->twig->render($response->withStatus(422), 'auth/reset-password.twig', [
                'token'  => $token,
                'errors' => $errors,
            ]);
        }

        $hashPassword = $this->hasher->hash($password);

        $this->db->beginTransaction();
        try {
            $this->db->update(VL_DB_PREFIX . 'users', [
                'password'   => $hashPassword,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $row['user_id']]);

            $this->db->update(VL_DB_PREFIX . 'password_resets', [
                'used_at' => date('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);

            // Invalidate every other session for this user (incl. stolen cookies).
            $this->db->executeStatement(
                'UPDATE ' . VL_DB_PREFIX . 'users SET session_version = session_version + 1 WHERE id = ?',
                [$row['user_id']],
            );
            // Drop any remaining unused reset tokens for this user.
            $this->db->executeStatement(
                'DELETE FROM ' . VL_DB_PREFIX . 'password_resets WHERE user_id = ? AND used_at IS NULL AND id <> ?',
                [$row['user_id'], $row['id']],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return $this->twig->render($response->withStatus(500), 'auth/reset-password.twig', [
                'token'  => $token,
                'errors' => ['An error occurred. Please try again.'],
            ]);
        }

        return $this->twig->render($response, 'auth/reset-password.twig', [
            'success' => true,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findValidToken(string $hash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'password_resets' . ' WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [$hash, date('Y-m-d H:i:s')],
        );

        return $row === false ? null : $row;
    }
}
