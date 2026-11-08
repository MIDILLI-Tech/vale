<?php

/**
 * Validates a Bearer token from the Authorization header against api_tokens.
 * Tokens are stored as SHA-256 hashes — incoming tokens are hashed before comparison.
 * On success, sets request attribute 'api_user_id'.
 */

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiAuthMiddleware implements MiddlewareInterface
{
    private const TABLE = VL_DB_PREFIX . 'api_tokens';

    public function __construct(
        private readonly Connection $db,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Bearer token required.');
        }

        $plainToken = trim(substr($header, 7));
        if ($plainToken === '') {
            return $this->unauthorized('Bearer token required.');
        }

        $hashed = hash('sha256', $plainToken);

        $row = $this->db->fetchAssociative(
            'SELECT id, user_id, abilities FROM ' . self::TABLE
            . ' WHERE token = ? AND (expires_at IS NULL OR expires_at > ?)',
            [$hashed, date('Y-m-d H:i:s')],
        );

        if ($row === false) {
            return $this->unauthorized('Invalid or expired token.');
        }

        // Track usage
        $this->db->update(self::TABLE, ['last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);

        $request = $request->withAttribute('api_user_id', (int) $row['user_id']);
        $request = $request->withAttribute('api_abilities', $row['abilities'] ?? '');

        return $handler->handle($request);
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(401);
        $response->getBody()->write(
            json_encode(['error' => 'Unauthorized', 'message' => $message], JSON_THROW_ON_ERROR)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer');
    }
}
