<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Service;

/** Turns device options from scanimage -A and the user's settings into scanimage arguments */
class OptionBuilder {
    // Share of dark pixels below which the driver treats a page as empty
    private const BLANK_PAGE_PERCENT = 1;

    // Width and height in millimetres
    private const PAPER = [
        'a4' => [210, 297],
        'a5' => [148, 210],
        'a6' => [105, 148],
        'letter' => [215.9, 279.4],
        'legal' => [215.9, 355.6],
    ];

    public static function parseOptions(string $output): array {
        $options = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s+--([\w-]+)\[=\(yes\|no\)\]/', $line, $switch) === 1) {
                $options[$switch[1]] = ['switch' => true, 'sensor' => str_contains($line, '[hardware]')];
                continue;
            }
            if (preg_match('/^\s+(--?[\w-]+)\s+(.*?)\s*\[(.*)\]\s*$/', $line, $match) !== 1) {
                continue;
            }
            $name = ltrim($match[1], '-');
            $range = preg_replace('/\s*\(in steps of.*\)\s*$/', '', $match[2]);

            if (preg_match('/^(-?[\d.]+)\.\.(-?[\d.]+)/', $range, $bounds) === 1) {
                $options[$name] = ['min' => (float)$bounds[1], 'max' => (float)$bounds[2]];
            } elseif (str_contains($range, '|')) {
                $options[$name] = ['values' => array_map(static fn (string $value) => preg_replace('/(dpi|mm)$/', '', $value), explode('|', $range))];
            }
        }
        return $options;
    }

    private static function find(array $values, array $words): ?string {
        foreach ($words as $word) {
            foreach ($values as $value) {
                if (stripos($value, $word) !== false) {
                    return $value;
                }
            }
        }
        return null;
    }

    public static function hasButton(array $options): bool {
        foreach ($options as $name => $option) {
            if (($option['sensor'] ?? false) && preg_match('/^(scan|email|copy|file|button|send|web)([-_]?\d+)?$/', (string)$name) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function isFlatbed(string $source): bool {
        return self::find([$source], ['adf', 'feeder', 'einzug', 'duplex']) === null;
    }

    // Returns the source to use or null if the device has no choice
    public static function pickSource(array $options, array $settings): ?string {
        $values = $options['source']['values'] ?? [];
        if ($values === []) {
            return null;
        }
        if ($settings['source'] !== '' && in_array($settings['source'], $values, true)) {
            return $settings['source'];
        }

        return match ($settings['side']) {
            'both' => self::find($values, ['duplex']) ?? self::find($values, ['adf', 'feeder']) ?? $values[0],
            'back' => self::find($values, ['back']) ?? self::find($values, ['adf', 'feeder']) ?? $values[0],
            default => self::find($values, ['front', 'adf', 'feeder']) ?? $values[0],
        };
    }

    // Maps -100..100 onto the device's own range with 0 at its middle
    private static function scale(array $range, int $value): int {
        $middle = ($range['min'] + $range['max']) / 2;
        return (int)round($middle + ($value / 100) * ($range['max'] - $middle));
    }

    private static function fit(array $option, float $value): float {
        if (isset($option['values'])) {
            $numbers = array_map('floatval', $option['values']);
            usort($numbers, static fn (float $a, float $b) => abs($a - $value) <=> abs($b - $value));
            return $numbers[0];
        }
        return max($option['min'], min($option['max'], $value));
    }

    /** @return string[] */
    private static function paperArgs(array $options, string $paper): array {
        $hasPageSize = isset($options['page-width']['max'], $options['page-height']['max']);

        if (!isset(self::PAPER[$paper])) {
            // Sheet-fed scanners only know the paper is over when told how long it may be, so scan the maximum and crop
            if (!$hasPageSize || !isset($options['swcrop'])) {
                return [];
            }
            return ['--page-width', (string)$options['page-width']['max'], '--page-height', (string)$options['page-height']['max'], '--swcrop=yes'];
        }

        [$width, $height] = self::PAPER[$paper];
        $args = [];
        if ($hasPageSize) {
            array_push($args, '--page-width', (string)self::fit($options['page-width'], $width), '--page-height', (string)self::fit($options['page-height'], $height));
            // -A reports the scan area for the current page size, which the two options above have just changed
            array_push($args, '-x', (string)min($width, $options['page-width']['max']), '-y', (string)min($height, $options['page-height']['max']));
            return $args;
        }

        foreach (['x' => $width, 'y' => $height] as $name => $size) {
            if (isset($options[$name]['max'])) {
                array_push($args, '-' . $name, (string)self::fit($options[$name], $size));
            }
        }
        return $args;
    }

    /** @return string[] arguments for scanimage */
    public static function build(array $options, array $settings, string $kind): array {
        $args = [];

        if (isset($options['mode']['values'])) {
            $mode = $kind === 'photo'
                ? self::find($options['mode']['values'], ['color', 'colour'])
                : self::find($options['mode']['values'], ['gray', 'grey']);
            if ($mode !== null) {
                array_push($args, '--mode', $mode);
            }
        }

        if (isset($options['resolution'])) {
            $dpi = self::fit($options['resolution'], $kind === 'photo' ? $settings['photoDpi'] : $settings['textDpi']);
            array_push($args, '--resolution', (string)(int)$dpi);
        }

        $source = self::pickSource($options, $settings);
        if ($source !== null) {
            array_push($args, '--source', $source);
        }

        foreach (['brightness', 'contrast'] as $name) {
            if (isset($options[$name]['min'])) {
                array_push($args, '--' . $name, (string)self::scale($options[$name], $settings[$name]));
            }
        }

        array_push($args, ...self::paperArgs($options, $settings['paper']));

        if ($settings['deskew'] && isset($options['swdeskew'])) {
            $args[] = '--swdeskew=yes';
        }
        if ($settings['skipBlank'] && isset($options['swskip']['max'])) {
            array_push($args, '--swskip', (string)self::BLANK_PAGE_PERCENT);
        }

        // A flatbed never runs out of paper, so a batch would not stop by itself
        if ($source === null || self::isFlatbed($source)) {
            $args[] = '--batch-count=1';
        }

        return $args;
    }
}
