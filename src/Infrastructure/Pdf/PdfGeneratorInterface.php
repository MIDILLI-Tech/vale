<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Pdf;

interface PdfGeneratorInterface
{
    public function generate(string $html): string;

    public function isAvailable(): bool;
}
