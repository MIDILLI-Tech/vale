<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks if the authenticated API user has the required ability.
 * Expects 'api_abilities' attribute from ApiAuthMiddleware.
 */
final class ApiAbilityMiddleware
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory
    ) {
    }

    /**
     * Factory-like method to handle the parameter in Slim 4 middleware.
     */
    public function __invoke(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $requiredAbility
    ): ResponseInterface {
        $abilities = $request->getAttribute('api_abilities', '');

        if ($abilities === '*') {
            return $handler->handle($request);
        }

        $allowed = array_map('trim', explode(',', $abilities));
        if (in_array($requiredAbility, $allowed, true)) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(403);
        $payload  = json_encode([
            'error'   => 'Forbidden',
            'message' => "Token lacks required ability: {$requiredAbility}",
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
