<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Shop\WithdrawalService;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\WithdrawalRequest;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Translation\Translator;

final class WithdrawalController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly WithdrawalService $service,
        private readonly SettingsRepositoryInterface $settings,
        private readonly OrderRepositoryInterface $orders,
        private readonly MailService $mail,
        private readonly Translator $translator,
        private readonly IpAnonymizer $ipAnonymizer,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isEnabled()) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $query = $request->getQueryParams();

        return $this->render($response, [
            'success'    => false,
            'errors'     => [],
            'old'        => ['order_number' => trim((string) ($query['order'] ?? ''))],
            'withdrawal' => null,
            'order'      => null,
        ]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->isEnabled()) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $body   = (array) $request->getParsedBody();
        $errors = $this->service->validate($body);

        if ($errors !== []) {
            return $this->render($response->withStatus(422), [
                'success'    => false,
                'errors'     => $errors,
                'old'        => $body,
                'withdrawal' => null,
                'order'      => null,
            ]);
        }

        $ipMode = (string) ($this->settings->get('ip_privacy_mode', 'mask') ?? 'mask');
        $locale = $this->translator->getLocale();

        $withdrawal = $this->service->submit(
            $body,
            $this->ipAnonymizer->anonymize(vl_client_ip($request), $ipMode),
            $locale,
        );

        $order = $withdrawal->getOrderId() !== null
            ? $this->orders->findById((int) $withdrawal->getOrderId())
            : null;

        $this->sendEmails($withdrawal, $order, $locale);

        return $this->render($response, [
            'success'    => true,
            'errors'     => [],
            'old'        => $body,
            'withdrawal' => $withdrawal,
            'order'      => $order,
        ]);
    }

    private function isEnabled(): bool
    {
        return ($this->settings->get('shop_enabled', '1') ?? '1')                   === '1'
            && ($this->settings->get('compliance_withdrawal_function', '0') ?? '0') === '1';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ResponseInterface $response, array $data): ResponseInterface
    {
        return $this->twig->render($response, 'withdrawal/show.twig', $data + [
            'return_policy_slug' => (string) ($this->settings->get('policy_page_returns', '') ?? ''),
            'withdrawal_days'    => max(1, (int) ($this->settings->get('refund_period_days', '14') ?? 14)),
        ]);
    }

    private function sendEmails(WithdrawalRequest $withdrawal, ?Order $order, string $locale): void
    {
        $context = [
            'withdrawal' => $withdrawal,
            'order'      => $order,
            'site_url'   => site_url(''),
        ];

        try {
            $this->mail->sendTemplate(
                $withdrawal->getCustomerEmail(),
                $this->translator->get('Withdrawal received — :reference', [':reference' => $withdrawal->getReference()], $locale),
                'mail/withdrawal-confirmation',
                $context,
                locale: $locale,
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            error_log('[Withdrawal] Customer confirmation failed for ' . $withdrawal->getReference() . ': ' . $e->getMessage());
        }

        $adminEmail = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '') {
            $adminEmail = trim((string) ($this->settings->get('mail_from_address', '') ?? ''));
        }
        if ($adminEmail === '') {
            return;
        }

        $adminLocale = (string) ($this->settings->get('locale', 'en') ?? 'en');

        try {
            $this->mail->sendTemplate(
                $adminEmail,
                $this->translator->get('New withdrawal request — :reference', [':reference' => $withdrawal->getReference()], $adminLocale),
                'mail/withdrawal-admin',
                $context + ['admin_url' => admin_url('/withdrawals/' . (int) $withdrawal->getId())],
                locale: $adminLocale,
                recipientType: 'admin',
            );
        } catch (\Throwable $e) {
            error_log('[Withdrawal] Admin notification failed for ' . $withdrawal->getReference() . ': ' . $e->getMessage());
        }
    }
}
