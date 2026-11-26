<?php

declare(strict_types=1);

namespace ValeApp\Application\Consent;

final class ConsentState
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly ?string $consentId,
        public readonly string $version,
        public readonly array $categories,
        public readonly bool $decided,
    ) {
    }

    public static function undecided(string $version, array $categories = ['necessary']): self
    {
        return new self(null, $version, array_values($categories), false);
    }

    public function allows(string $category): bool
    {
        return in_array($category, $this->categories, true);
    }
}
