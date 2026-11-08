<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;

/**
 * The block editor renders tables with the exact markup the front end gets:
 * table-level declarations in the <table> inline style, cell-level styling in
 * the <td>/<th> inline style. That only stays true while every theme ships the
 * same table rules, so drift in one theme is a regression.
 */
final class TableStyleContractTest extends TestCase
{
    private const LAYOUTS = [
        'default' => 'resources/views/layout.twig',
        'dark'    => 'themes/dark/templates/layout.twig',
        'nova'    => 'themes/nova/templates/layout.twig',
        'vivid'   => 'themes/vivid/templates/layout.twig',
    ];

    private const REQUIRED_RULES = [
        '.prose table {',
        '.prose table th,',
        'var(--vl-cell-border',
        'var(--vl-table-cell-padding',
        'table[style*="--vl-cell-border"]',
        'table[style*="--vl-cell-border"] th',
        'max-width: 100%',
    ];

    public function testEveryThemeShipsTheSameTableRules(): void
    {
        $blocks = [];

        foreach (self::LAYOUTS as $theme => $path) {
            $block = $this->tableCssBlock($path);

            self::assertNotSame('', $block, sprintf('%s: table CSS block not found', $theme));

            foreach (self::REQUIRED_RULES as $rule) {
                self::assertStringContainsString($rule, $block, sprintf('%s: missing %s', $theme, $rule));
            }

            $blocks[$theme] = $this->normalizeThemeColors($block);
        }

        $reference = $blocks['default'];

        foreach ($blocks as $theme => $block) {
            self::assertSame(
                $reference,
                $block,
                sprintf('Table CSS drifted in the "%s" theme; keep all themes in sync.', $theme),
            );
        }
    }

    public function testEditorCssUsesTheSameCellVariables(): void
    {
        $editorCss = (string) file_get_contents($this->root() . '/public/assets/css/editor.css');

        self::assertStringContainsString('var(--vl-cell-border', $editorCss);
        self::assertStringContainsString('var(--vl-table-cell-padding', $editorCss);
        self::assertStringContainsString('var(--vl-table-head-bg', $editorCss);
        self::assertStringContainsString('var(--vl-table-head-text', $editorCss);
    }

    /**
     * The editor preview mirrors the active theme's table palette. The colours
     * live in two places — the theme's layout CSS (what visitors get) and
     * theme.json "tables" (what the editor reads) — so a change to one without
     * the other silently breaks WYSIWYG. This test is that guard.
     */
    public function testThemeJsonPaletteMatchesThemeCss(): void
    {
        foreach (self::LAYOUTS as $theme => $path) {
            $css = (string) file_get_contents($this->root() . '/' . $path);

            [$borderColor, $headerBackground] = $this->effectivePalette($css, $theme);

            $jsonFile = $this->root() . '/themes/' . $theme . '/theme.json';
            self::assertFileExists($jsonFile, sprintf('%s: theme.json missing', $theme));
            $meta = json_decode((string) file_get_contents($jsonFile), true);

            self::assertSame(
                $borderColor,
                $meta['tables']['border'] ?? null,
                sprintf('%s: theme.json tables.border does not match the theme CSS', $theme),
            );
            self::assertSame(
                $headerBackground,
                $meta['tables']['header_background'] ?? null,
                sprintf('%s: theme.json tables.header_background does not match the theme CSS', $theme),
            );
        }
    }

    /**
     * Colours a visitor actually sees for a `.prose` table: an `html.dark …`
     * override (the dark theme) outranks the base rule, so it is read first.
     *
     * @return array{0: string, 1: string} [border colour, header background]
     */
    private function effectivePalette(string $css, string $theme): array
    {
        $border = null;
        if (preg_match('/html\.dark \.prose table th,[\s\S]{0,120}?border-color: (#[0-9a-fA-F]{3,8})/', $css, $match) === 1) {
            $border = $match[1];
        } elseif (preg_match('/var\(--vl-cell-border, 1px solid (#[0-9a-fA-F]{3,8})\)/', $css, $match) === 1) {
            $border = $match[1];
        }

        $header = null;
        if (preg_match('/html\.dark \.prose table th \{ background: (#[0-9a-fA-F]{3,8})/', $css, $match) === 1) {
            $header = $match[1];
        } elseif (preg_match('/\.prose table th \{ background: (#[0-9a-fA-F]{3,8})/', $css, $match) === 1) {
            $header = $match[1];
        }

        self::assertNotNull($border, sprintf('%s: cell border colour not found in layout CSS', $theme));
        self::assertNotNull($header, sprintf('%s: header background not found in layout CSS', $theme));

        return [$border, $header];
    }

    /**
     * Extracts the table section (parity comment → end of the outside-prose
     * block) from a theme layout.
     */
    private function tableCssBlock(string $path): string
    {
        $html = (string) file_get_contents($this->root() . '/' . $path);

        $start = strpos($html, '/* Editor parity contract');
        if ($start === false) {
            return '';
        }

        $lastOutsideProse = strrpos($html, 'table[style*="--vl-cell-border"] th {');
        if ($lastOutsideProse === false || $lastOutsideProse < $start) {
            return '';
        }

        $end = strpos($html, '}', $lastOutsideProse);
        if ($end === false) {
            return '';
        }

        return substr($html, $start, $end - $start + 1);
    }

    private function normalizeThemeColors(string $css): string
    {
        $css = (string) preg_replace('/\s+/', ' ', $css);

        return strtr(trim($css), [
            '#d1d5db' => '#BORDER',
            '#374151' => '#BORDER',
            '#475569' => '#BORDER',
            '#f3f4f6' => '#HEADER',
            '#1f2937' => '#HEADER',
            '#1e293b' => '#HEADER',
            '#f1faf5' => '#HEADER',
        ]);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
