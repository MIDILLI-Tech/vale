<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class NewsletterFrontController
{
    use CaptchaTrait;

    public function __construct(
        private readonly Twig $twig,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly CampaignRepositoryInterface $campaigns,
        private readonly MailService $mail,
        private readonly SessionManager $session,
        private readonly AutomationService $automationService,
        private readonly Translator $translator,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    public function subscribe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body   = (array) $request->getParsedBody();
        $email  = trim($body['email'] ?? '');
        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
        $tag    = trim($body['tag'] ?? '');

        // The captcha type is rendered into the session when the form HTML is
        // generated. It is deliberately NOT read from the request body: a
        // visitor-supplied value would let bots downgrade or disable the check.
        $captchaType = strtolower(trim((string) $this->session->get('newsletter_captcha_type', 'disabled')));
        if (in_array($captchaType, ['none', 'off', '0', ''], true)) {
            $captchaType = 'disabled';
        }
        if ($captchaType !== 'disabled') {
            $captchaError = $this->validateNewsletterCaptcha($body, $captchaType);
            if ($captchaError !== null) {
                if ($isAjax) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => $this->translator->get($captchaError),
                    ]));
                    return $response->withHeader('Content-Type', 'application/json');
                }
                $this->session->flash('error', $this->translator->get($captchaError));
                $referer = $request->getHeaderLine('Referer') ?: '/';
                return $response->withHeader('Location', $referer)->withStatus(302);
            }
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($isAjax) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => $this->translator->get('Please enter a valid email address.')]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $this->session->flash('error', $this->translator->get('Please enter a valid email address.'));
            $referer = $request->getHeaderLine('Referer') ?: '/';
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        $existing = $this->subscribers->findByEmail($email);
        if ($existing !== null && $existing->isActive()) {
            if ($isAjax) {
                $response->getBody()->write(json_encode(['success' => true, 'message' => $this->translator->get('You are already subscribed!')]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $this->session->flash('info', $this->translator->get('You are already subscribed!'));
            $referer = $request->getHeaderLine('Referer') ?: '/';
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        if ($existing === null) {
            $subscriber = new Subscriber($email, bin2hex(random_bytes(16)));
        } else {
            // Resend confirmation
            $subscriber = $existing;
        }

        if ($tag !== '') {
            $tags = $subscriber->getTags();
            foreach (array_filter(array_map('trim', explode(',', $tag))) as $singleTag) {
                if (!in_array($singleTag, $tags, true)) {
                    $tags[] = $singleTag;
                }
            }
            $subscriber->setTags($tags);
        }

        $this->subscribers->save($subscriber);

        // Send double opt-in confirmation email
        $confirmUrl = $this->getBaseUrl() . '/newsletter/confirm/' . $subscriber->getToken();
        $locale     = $this->translator->getLocale();
        try {
            $this->mail->sendTemplate(
                to: $email,
                subject: $this->translator->get('Confirm your subscription', [], $locale),
                template: 'mail/newsletter-confirmation',
                context: [
                    'confirm_url' => $confirmUrl,
                    'site_url'    => $this->getBaseUrl(),
                    'site_name'   => 'Vale',
                ],
                locale: $locale,
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            // Subscriber row is already saved — never blow up into an error page
            // for a provider failure. The user can request the email again.
            error_log('[Newsletter] Confirmation email failed for ' . $email . ': ' . $e->getMessage());
            $mailFailed = true;
        }

        if (isset($mailFailed)) {
            if ($isAjax) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => $this->translator->get('Subscription saved, but the confirmation email could not be sent. Please try again later.')]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $this->session->flash('error', $this->translator->get('Subscription saved, but the confirmation email could not be sent. Please try again later.'));
            $referer = $request->getHeaderLine('Referer') ?: '/';
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        if ($isAjax) {
            $response->getBody()->write(json_encode(['success' => true, 'message' => $this->translator->get('Please check your email to confirm your subscription.')]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $this->session->flash('success', $this->translator->get('Please check your email to confirm your subscription.'));
        $referer = $request->getHeaderLine('Referer') ?: '/';
        return $response->withHeader('Location', $referer)->withStatus(302);
    }

    public function confirm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token      = preg_replace('/[^a-f0-9]/', '', $args['token'] ?? '');
        $subscriber = $token ? $this->subscribers->findByToken($token) : null;

        if ($subscriber === null) {
            return $this->twig->render($response->withStatus(404), 'newsletter/confirm.twig', [
                'success' => false,
                'message' => $this->translator->get('Invalid or expired confirmation link.'),
            ]);
        }

        $subscriber->confirm();
        $this->subscribers->save($subscriber);
        $this->automationService->enrollSubscriber($subscriber);

        return $this->twig->render($response, 'newsletter/confirm.twig', [
            'success' => true,
            'message' => $this->translator->get('Thank you! Your subscription is confirmed.'),
        ]);
    }

    public function unsubscribe(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token      = preg_replace('/[^a-f0-9]/', '', $args['token'] ?? '');
        $subscriber = $token ? $this->subscribers->findByToken($token) : null;

        if ($subscriber !== null) {
            $subscriber->unsubscribe();
            $this->subscribers->save($subscriber);
        }

        // RFC 8058 one-click: Gmail POSTs here, expects 2xx, ignores the body.
        if ($request->getMethod() === 'POST') {
            $response->getBody()->write('Unsubscribed');
            return $response->withHeader('Content-Type', 'text/plain')->withStatus(200);
        }

        return $this->twig->render($response, 'newsletter/unsubscribe.twig', [
            'success' => $subscriber !== null,
        ]);
    }

    public function trackOpen(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = preg_replace('/[^a-f0-9]/', '', $args['token'] ?? '');
        if ($token !== '') {
            $path = $request->getUri()->getPath();
            if (str_contains($path, '/open-auto/')) {
                $this->automationService->recordOpen($token);
            } else {
                $this->campaigns->recordOpen($token);
            }
        }

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $response->getBody()->write($gif);
        return $response
            ->withHeader('Content-Type', 'image/gif')
            ->withHeader('Cache-Control', 'no-store, no-cache')
            ->withStatus(200);
    }

    public function trackClick(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = preg_replace('/[^a-f0-9]/', '', $args['token'] ?? '');
        $url   = $request->getQueryParams()['url'] ?? '';

        if ($token !== '') {
            $path = $request->getUri()->getPath();
            if (str_contains($path, '/click-auto/')) {
                $this->automationService->recordClick($token);
            } else {
                $this->campaigns->recordClick($token);
            }
        }

        $url = str_replace(["\r", "\n", "\0"], '', (string) $url);

        $isHttp = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
        if ($url === '' || !$isHttp) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }

        return $response->withStatus(302)->withHeader('Location', $url);
    }

    private function validateNewsletterCaptcha(array $body, string $type): ?string
    {
        $honeypotField = (string) $this->session->get('newsletter_honeypot_name', '_hp_name');
        if ($honeypotField === '') {
            $honeypotField = '_hp_name';
        }

        return $this->validateCaptchaValue($body, $type, 'newsletter_captcha_answer', $honeypotField);
    }

    private function getBaseUrl(): string
    {
        return __require_app_url();
    }
}
