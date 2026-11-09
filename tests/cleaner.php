<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../lib/Service/PageCleaner.php';

use OCA\ScanToPdf\Service\PageCleaner;

const WIDTH = 100;
const HEIGHT = 200;
// 4 mm at 300 dpi
const ZONE = 48;

$path = tempnam(sys_get_temp_dir(), 'scantopdf');

/** @param array<int, int> $darkRows row => number of black pixels at its start */
function page(array $darkRows): string {
    $pixels = '';
    for ($y = 0; $y < HEIGHT; $y++) {
        $dark = $darkRows[$y] ?? 0;
        $pixels .= str_repeat("\x00", $dark) . str_repeat("\xFF", WIDTH - $dark);
    }
    return 'P5 ' . WIDTH . ' ' . HEIGHT . " 255\n" . $pixels;
}

function cleaned(string $content): string {
    global $path;
    file_put_contents($path, $content);
    PageCleaner::cleanPnm($path, 300);
    return file_get_contents($path);
}

$line = HEIGHT - 10;
check('line at the paper end removed', cleaned(page([$line => WIDTH])), page([]));
check('everything below the line removed', cleaned(page([$line => WIDTH, $line + 5 => 3])), page([]));
check('dark row at the zone start removed', cleaned(page([HEIGHT - ZONE => WIDTH])), page([]));
check('above the zone kept', cleaned(page([HEIGHT - ZONE - 1 => WIDTH])), page([HEIGHT - ZONE - 1 => WIDTH]));
check('text above the line kept', cleaned(page([50 => 40, $line => WIDTH])), page([50 => 40]));
check('sparse text in the zone kept', cleaned(page([$line => 14])), page([$line => 14]));
check('exactly 15 percent counts as a line', cleaned(page([$line => 15])), page([]));
check('white page unchanged', cleaned(page([])), page([]));
check('other formats left alone', cleaned("P6 1 1 255\n\x00\x00\x00"), "P6 1 1 255\n\x00\x00\x00");
check('comment in the header', cleaned('P5 # scan' . "\n" . WIDTH . ' ' . HEIGHT . " 255\n" . str_repeat("\x00", WIDTH * HEIGHT)) === 'P5 # scan' . "\n" . WIDTH . ' ' . HEIGHT . " 255\n" . str_repeat("\x00", WIDTH * (HEIGHT - ZONE)) . str_repeat("\xFF", WIDTH * ZONE), true);

if (function_exists('imagecreatetruecolor')) {
    $image = static function (array $darkRows): GdImage {
        $image = imagecreatetruecolor(WIDTH, HEIGHT);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        foreach ($darkRows as $y => $dark) {
            imagefilledrectangle($image, 0, $y, $dark - 1, $y, 0);
        }
        return $image;
    };
    $pixel = static fn (GdImage $image, int $y): int => imagecolorat($image, 0, $y) & 255;

    $color = $image([$line => WIDTH]);
    PageCleaner::cleanImage($color, 300);
    check('image: line removed', $pixel($color, $line), 255);

    $color = $image([50 => WIDTH]);
    PageCleaner::cleanImage($color, 300);
    check('image: above the zone kept', $pixel($color, 50), 0);

    $color = $image([$line => 8]);
    PageCleaner::cleanImage($color, 300);
    check('image: sparse text kept', $pixel($color, $line), 0);
} else {
    echo "skip image tests need the GD extension\n";
}

unlink($path);
finish();
