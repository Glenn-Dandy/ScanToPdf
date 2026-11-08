<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Service;

use GdImage;

/** Removes the dark line that sheet-fed scanners leave where the paper ends */
class PageCleaner {
    private const ZONE_MM = 4;
    private const DARK_SHARE = 0.15;
    private const DARK_LEVEL = 128;

    private static function zoneRows(int $dpi, int $height): int {
        return min($height, (int)ceil(self::ZONE_MM * $dpi / 25.4));
    }

    public static function cleanPnm(string $path, int $dpi): void {
        $content = file_get_contents($path);
        if ($content === false || preg_match('/\AP5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(?:#[^\n]*\n\s*)*(\d+)\s+(?:#[^\n]*\n\s*)*255\s/', $content, $match) !== 1) {
            return;
        }

        $width = (int)$match[1];
        $height = (int)$match[2];
        $start = strlen($match[0]);
        for ($y = $height - self::zoneRows($dpi, $height); $y < $height; $y++) {
            $counts = count_chars(substr($content, $start + $y * $width, $width), 1);
            $dark = array_sum(array_filter($counts, static fn (int $value) => $value < self::DARK_LEVEL, ARRAY_FILTER_USE_KEY));
            if ($dark >= $width * self::DARK_SHARE) {
                file_put_contents($path, substr_replace($content, str_repeat("\xFF", ($height - $y) * $width), $start + $y * $width));
                return;
            }
        }
    }

    public static function cleanImage(GdImage $image, int $dpi): void {
        $width = imagesx($image);
        $height = imagesy($image);
        $samples = (int)ceil($width / 4);
        for ($y = $height - self::zoneRows($dpi, $height); $y < $height; $y++) {
            $dark = 0;
            for ($x = 0; $x < $width; $x += 4) {
                $color = imagecolorat($image, $x, $y);
                if ((($color >> 16 & 255) + ($color >> 8 & 255) + ($color & 255)) / 3 < self::DARK_LEVEL) {
                    $dark++;
                }
            }
            if ($dark >= $samples * self::DARK_SHARE) {
                imagefilledrectangle($image, 0, $y, $width - 1, $height - 1, imagecolorallocate($image, 255, 255, 255));
                return;
            }
        }
    }
}
