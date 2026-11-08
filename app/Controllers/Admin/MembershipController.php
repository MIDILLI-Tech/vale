<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Membership\Entity\MembershipPlan;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class MembershipController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly PlanRepositoryInterface $plans,
        private readonly SlugService $slugService,
        private readonly SessionManager $session,
        private readonly SubscriberRepositoryInterface $subscribers,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $plans = $this->plans->findAll();

        $planData = array_map(fn (MembershipPlan $p) => [
            'id'            => $p->getId(),
            'name'          => $p->getName(),
            'slug'          => $p->getSlug(),
            'description'   => $p->getDescription(),
            'price'         => $p->getPrice(),
            'interval'      => $p->getInterval(),
            'intervalLabel' => $p->intervalLabel(),
            'gateway'       => $p->getGateway(),
            'tag'           => $p->getTag(),
            'trialDays'     => $p->getTrialDays(),
            'trialPrice'    => $p->getTrialPrice(),
            'trialTag'      => $p->getTrialTag(),
            'sortOrder'     => $p->getSortOrder(),
            'active'        => $p->isActive(),
        ], $plans);

        $allTags = $this->collectAllTags($plans);

        return $this->twig->render($response, 'admin/membership/index.twig', [
            'plans'         => $planData,
            'allTags'       => $allTags,
            'flash_success' => $this->session->flash('flash_success'),
            'flash_error'   => $this->session->flash('flash_error'),
        ]);
    }

    /** @return string[] */
    private function collectAllTags(array $plans): array
    {
        $tags = [];

        foreach ($plans as $plan) {
            $tag = $plan->getTag();
            if ($tag !== '') {
                foreach (explode(',', $tag) as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $tags[$t] = true;
                    }
                }
            }
            $trialTag = $plan->getTrialTag();
            if ($trialTag !== null && $trialTag !== '') {
                foreach (explode(',', $trialTag) as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $tags[$t] = true;
                    }
                }
            }
        }

        foreach ($this->subscribers->getAllTags() as $tag) {
            $t = trim($tag);
            if ($t !== '') {
                $tags[$t] = true;
            }
        }

        $all = array_keys($tags);
        sort($all);
        return $all;
    }

    public function create(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $this->session->flash('flash_error', $this->translator->get('Name is required.'));
            return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
        }
        $tag = trim($body['tag'] ?? '');
        if ($tag === '') {
            $this->session->flash('flash_error', $this->translator->get('Access tag is required.'));
            return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
        }

        $rawSlug = trim($body['slug'] ?? '');
        $slug    = $rawSlug !== ''
            ? (string) $this->slugService->unique($rawSlug, VL_DB_PREFIX . 'membership_plans', 'slug')
            : (string) $this->slugService->generate($name, VL_DB_PREFIX . 'membership_plans', 'slug');

        $plan = new MembershipPlan(
            $name,
            $slug,
            trim($body['description'] ?? '') ?: null,
            str_replace(',', '.', $body['price'] ?? '0.00'),
            $body['interval'] ?? 'monthly',
            $body['gateway']  ?? 'stripe',
            $tag,
            (int) ($body['trial_days'] ?? 0),
            trim($body['trial_tag'] ?? '') ?: null,
            str_replace(',', '.', $body['trial_price'] ?? '0.00'),
            (int) ($body['sort_order'] ?? 0),
            !empty($body['is_active']),
        );

        $this->plans->save($plan);
        $this->session->flash('flash_success', $this->translator->get('Plan created.'));
        return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $plan = $this->plans->findById((int) $args['id']);
        if ($plan === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $this->session->flash('flash_error', $this->translator->get('Name is required.'));
            return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
        }
        $tag = trim($body['tag'] ?? '');
        if ($tag === '') {
            $this->session->flash('flash_error', $this->translator->get('Access tag is required.'));
            return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
        }

        $plan->setName($name);
        $rawSlug = trim($body['slug'] ?? '');
        if ($rawSlug !== '') {
            $plan->setSlug((string) $this->slugService->unique($rawSlug, VL_DB_PREFIX . 'membership_plans', 'slug', $plan->getId()));
        }
        $plan->setDescription(trim($body['description'] ?? '') ?: null);
        $plan->setPrice(str_replace(',', '.', $body['price'] ?? '0.00'));
        $plan->setInterval($body['interval'] ?? 'monthly');
        $plan->setGateway($body['gateway'] ?? 'stripe');
        $plan->setTag($tag);
        $plan->setTrialDays((int) ($body['trial_days'] ?? 0));
        $plan->setTrialPrice(str_replace(',', '.', $body['trial_price'] ?? '0.00'));
        $plan->setTrialTag(trim($body['trial_tag'] ?? '') ?: null);
        $plan->setSortOrder((int) ($body['sort_order'] ?? 0));
        $plan->setIsActive(!empty($body['is_active']));

        $this->plans->save($plan);
        $this->session->flash('flash_success', $this->translator->get('Plan updated.'));
        return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->plans->delete((int) $args['id']);
        $this->session->flash('flash_success', $this->translator->get('Plan deleted.'));
        return $response->withHeader('Location', admin_url('/membership/plans'))->withStatus(302);
    }

    /** JSON list for the coupon plan picker — returns all plans (id, name, price, interval). */
    public function listJson(Request $request, Response $response): Response
    {
        $plans = $this->plans->findAll();
        $data  = array_values(array_map(fn (MembershipPlan $p) => [
            'id'       => $p->getId(),
            'name'     => $p->getName(),
            'price'    => $p->getPrice(),
            'interval' => $p->getInterval(),
            'tag'      => $p->getTag(),
        ], $plans));

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
