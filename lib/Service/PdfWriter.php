<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Service;

use RuntimeException;

/** Wraps JPEG and PNG pages into a PDF without re-encoding them */
class PdfWriter {
    private const PAPER_WHITE = 208;
    private const GRAY_LEVELS = 8;

    /** @param string[] $images paths of JPEG, PNG or PNM files */
    public static function write(array $images, int $dpi, string $target): void {
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not write the PDF');
        }

        $offsets = [];
        $position = 0;
        $emit = static function (string $data) use ($out, &$position): void {
            fwrite($out, $data);
            $position += strlen($data);
        };
        $object = static function (int $number, string $body) use ($emit, &$offsets, &$position): void {
            $offsets[$number] = $position;
            $emit($number . " 0 obj\n" . $body . "\nendobj\n");
        };

        $emit("%PDF-1.5\n");

        // Objects 1 and 2 are the catalog and page tree, each page then uses three objects
        $count = count($images);
        $kids = [];
        for ($index = 0; $index < $count; $index++) {
            $kids[] = (3 + $index * 3) . ' 0 R';
        }
        $object(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $object(2, '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>');

        foreach ($images as $index => $image) {
            [$pixelWidth, $pixelHeight, $dictionary, $data] = self::read($image);
            $width = round($pixelWidth / $dpi * 72, 2);
            $height = round($pixelHeight / $dpi * 72, 2);

            $pageId = 3 + $index * 3;
            $contentId = $pageId + 1;
            $imageId = $pageId + 2;

            $object($pageId, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $width . ' ' . $height . '] /Resources << /XObject << /Im0 ' . $imageId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>');

            $content = 'q ' . $width . ' 0 0 ' . $height . ' 0 0 cm /Im0 Do Q';
            $object($contentId, '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream");

            $object($imageId, '<< /Type /XObject /Subtype /Image /Width ' . $pixelWidth . ' /Height ' . $pixelHeight . ' ' . $dictionary
                . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream");
        }

        $total = 2 + $count * 3;
        $xref = $position;
        $emit('xref' . "\n0 " . ($total + 1) . "\n0000000000 65535 f \n");
        for ($number = 1; $number <= $total; $number++) {
            $emit(sprintf("%010d 00000 n \n", $offsets[$number]));
        }
        $emit('trailer' . "\n<< /Size " . ($total + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n");
        fclose($out);
    }

    /** @return array{int, int, string, string} width, height, PDF image dictionary entries, stream data */
    private static function read(string $image): array {
        $content = file_get_contents($image);
        if ($content === false) {
            throw new RuntimeException('A scanned page could not be read');
        }
        if (str_starts_with($content, 'P5') || str_starts_with($content, 'P6')) {
            return self::readPnm($content);
        }
        return str_starts_with($content, "\x89PNG") ? self::readPng($content) : self::readJpeg($image, $content);
    }

    private static function readJpeg(string $image, string $content): array {
        $info = getimagesize($image);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('A scanned page is not a valid image');
        }
        $space = ($info['channels'] ?? 3) === 1 ? '/DeviceGray' : '/DeviceRGB';
        return [$info[0], $info[1], '/ColorSpace ' . $space . ' /BitsPerComponent 8 /Filter /DCTDecode', $content];
    }

    // Gray pages keep only a few levels, which removes the noise around letters and compresses well
    private static function readPnm(string $content): array {
        if (preg_match('/\A(P[56])\s+(?:#[^\n]*\n\s*)*(\d+)\s+(?:#[^\n]*\n\s*)*(\d+)\s+(?:#[^\n]*\n\s*)*(\d+)\s/', $content, $match) !== 1 || (int)$match[4] !== 255) {
            throw new RuntimeException('A scanned page is not a valid image');
        }
        $width = (int)$match[2];
        $height = (int)$match[3];
        $pixels = substr($content, strlen($match[0]));

        if ($match[1] === 'P6') {
            return [$width, $height, '/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode', gzcompress($pixels)];
        }

        // PDF has no 3 bit images, so the levels are stored with 4 bits
        return [$width, $height, '/ColorSpace /DeviceGray /BitsPerComponent 4 /Filter /FlateDecode', gzcompress(self::reduceLevels($pixels, $width, $height))];
    }

    private static function reduceLevels(string $pixels, int $width, int $height): string {
        // Paper is never perfectly white, so its brightest shades all count as white to keep the background clean
        $levels = [];
        for ($value = 0; $value < 256; $value++) {
            $levels[$value] = $value >= self::PAPER_WHITE ? 15 : (int)round(intdiv($value * self::GRAY_LEVELS, 256) * 15 / (self::GRAY_LEVELS - 1));
        }

        $packed = '';
        for ($y = 0; $y < $height; $y++) {
            $row = substr($pixels, $y * $width, $width);
            for ($x = 0; $x < $width; $x += 2) {
                $packed .= chr($levels[ord($row[$x])] << 4 | $levels[ord($row[$x + 1] ?? "\0")]);
            }
        }
        return $packed;
    }

    // PDF can take the compressed PNG data as it is
    private static function readPng(string $content): array {
        $offset = 8;
        $data = '';
        $header = null;
        while ($offset + 8 <= strlen($content)) {
            $length = unpack('N', substr($content, $offset, 4))[1];
            $type = substr($content, $offset + 4, 4);
            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', substr($content, $offset + 8, $length));
            } elseif ($type === 'IDAT') {
                $data .= substr($content, $offset + 8, $length);
            }
            $offset += 12 + $length;
        }

        if ($header === null || $header['interlace'] !== 0 || !in_array($header['color'], [0, 2], true) || $data === '') {
            throw new RuntimeException('A scanned page is not a valid image');
        }

        $colors = $header['color'] === 2 ? 3 : 1;
        $space = $colors === 3 ? '/DeviceRGB' : '/DeviceGray';
        return [$header['width'], $header['height'],
            '/ColorSpace ' . $space . ' /BitsPerComponent ' . $header['depth'] . ' /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 15 /Colors ' . $colors . ' /BitsPerComponent ' . $header['depth'] . ' /Columns ' . $header['width'] . ' >>',
            $data];
    }
}
