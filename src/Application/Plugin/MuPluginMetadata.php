<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

/**
 * Optional display metadata for a single-file must-use drop-in.
 *
 * A mu-plugin has no manifest, so it can declare a WordPress-style header
 * block at the top of the file:
 *
 *   Plugin Name: Demo Must-Use
 *   Version: 1.0.0
 *   Description: Adds a response header.
 *   License: MIT
 *   Website: https://example.com
 *
 * Missing values fall back to the file name and are rendered as empty fields.
 */
final class MuPluginMetadata
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $version = null,
        public readonly ?string $description = null,
        public readonly ?string $license = null,
        public readonly ?string $homepage = null,
    ) {
    }

    public static function fromFile(string $file, string $fallbackName): self
    {
        $headers = self::readHeaders($file);

        return new self(
            $headers['name']        ?? $fallbackName,
            $headers['version']     ?? null,
            $headers['description'] ?? null,
            $headers['license']     ?? null,
            self::url($headers['homepage'] ?? null),
        );
    }

    /**
     * @return array{name?:string, version?:string, description?:string, license?:string, homepage?:string}
     */
    private static function readHeaders(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $source = @file_get_contents($file, false, null, 0, 8192);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $labels = [
            'name'        => ['Plugin Name'],
            'version'     => ['Version'],
            'description' => ['Description'],
            'license'     => ['License'],
            'homepage'    => ['Website', 'Plugin URI'],
        ];

        $headers = [];
        foreach ($labels as $key => $names) {
            foreach ($names as $label) {
                $pattern = '/^[ \t]*\*?[ \t]*' . preg_quote($label, '/') . '[ \t]*:[ \t]*(.*?)[ \t]*$/mi';
                if (preg_match($pattern, $source, $matches) !== 1) {
                    continue;
                }

                $value = trim($matches[1]);
                if ($value !== '') {
                    $headers[$key] = $value;
                    break;
                }
            }
        }

        return $headers;
    }

    /**
     * A mu-plugin header must never turn into a javascript: link.
     */
    private static function url(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
        ) {
            return null;
        }

        return $value;
    }
}
