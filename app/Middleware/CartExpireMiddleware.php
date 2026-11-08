<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Removes expired products from the cart on every request.
 * Shows a flash message when items are removed.
 */
final class CartExpireMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SessionManager $session,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cart = $this->session->get('cart', []);
        if (empty($cart)) {
            return $handler->handle($request);
        }

        $pIds = [];
        foreach (array_keys($cart) as $key) {
            $pIds[] = (int) explode('_', (string) $key)[0];
        }
        $pIds = array_unique($pIds);

        $loadedProducts = [];
        foreach ($pIds as $id) {
            $p = $this->products->findById($id);
            if ($p) {
                $loadedProducts[$id] = $p;
            }
        }

        $cartChanged = false;
        $cartFlash   = [];

        foreach ($cart as $cartKey => $data) {
            $productId = (int) explode('_', (string) $cartKey, 2)[0];
            $product   = $loadedProducts[$productId] ?? null;

            if ($product === null) {
                continue;
            }

            if ($product->isExpired()) {
                unset($cart[$cartKey]);
                $cartChanged = true;
                $cartFlash[] = $this->translator->get('":name" is no longer available and was removed from your cart.', [':name' => htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8')]);
            }
        }

        if ($cartChanged) {
            $this->session->set('cart', $cart);
            $this->session->flash('error', implode('<br>', $cartFlash));
        }

        return $handler->handle($request);
    }
}
