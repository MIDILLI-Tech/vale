<?php

declare(strict_types=1);

namespace ValeApp\Application\Form;

use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

final class FormAccessService
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    /**
     * Evaluate whether the current session user may view and submit a form.
     *
     * Shared by the standalone form page and the `[form]` shortcode so both
     * render the same restrictions the submit endpoint enforces.
     *
     * @return array{has_access: bool, require_login: bool, missing_membership_tags: bool, access_tags: array}
     */
    public function evaluate(Form $form): array
    {
        $settings          = $form->getSettings();
        $onlyRegistered    = !empty($settings['only_registered']);
        $membershipEnabled = $this->settings->get('membership_enabled', '1') === '1';
        $accessTags        = $membershipEnabled ? array_values(array_filter((array) ($settings['access_tags'] ?? []))) : [];

        $hasAccess             = true;
        $requireLogin          = false;
        $missingMembershipTags = false;

        if ($onlyRegistered || $accessTags !== []) {
            $userId = $this->session->get('user_id');

            if ($onlyRegistered && $userId === null) {
                $hasAccess    = false;
                $requireLogin = true;
            }

            if ($accessTags !== []) {
                if ($userId === null) {
                    $hasAccess             = false;
                    $missingMembershipTags = true;
                } else {
                    $subscriber = $this->subscribers->findByUserId((int) $userId);
                    $userTags   = $subscriber !== null ? $subscriber->getTags() : [];
                    if (empty(array_intersect($accessTags, $userTags))) {
                        $hasAccess             = false;
                        $missingMembershipTags = true;
                    }
                }
            }
        }

        return [
            'has_access'              => $hasAccess,
            'require_login'           => $requireLogin,
            'missing_membership_tags' => $missingMembershipTags,
            'access_tags'             => $accessTags,
        ];
    }
}
