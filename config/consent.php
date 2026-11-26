<?php

/**
 * Consent configuration — category catalog and storage settings.
 *
 * `necessary` is always granted and never shown to the visitor. Every other
 * category is opt-in: scripts assigned to it are not rendered until the
 * visitor has accepted that category.
 */

declare(strict_types=1);

return [
    'cookie_name'     => 'vl_consent',
    // 180 days — long enough to be useful, short enough to be re-asked.
    'cookie_lifetime' => 15552000,

    'categories' => [
        'necessary'  => ['required' => true],
        'functional' => ['required' => false],
        'analytics'  => ['required' => false],
        'marketing'  => ['required' => false],
    ],
];
