<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Translation\Translator;

class ReadingTimeService
{
    public function __construct(
        private readonly Translator $translator,
        private readonly DbalSettingsRepository $settings,
        int $wpm = 200
    ) {
        $this->wpm = max(1, $wpm);
    }

    private int $wpm;

    public function calculate(?string $content): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        $text = strip_tags($content);
        $text = preg_replace('/\[[^\]]+\]/', '', $text);

        $words     = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;
        $minutes   = (int) ceil($wordCount / $this->getWpm());

        if ($minutes <= 1) {
            return $this->translator->get('Less than a minute read');
        }

        return sprintf($this->translator->get('%d min read'), $minutes);
    }

    private function getWpm(): int
    {
        $setting = $this->settings->get('reading_time_wpm');

        if ($setting !== null && is_numeric($setting)) {
            return max(1, (int) $setting);
        }

        return $this->wpm;
    }
}
