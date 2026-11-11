<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../lib/Service/OptionBuilder.php';

use OCA\ScanToPdf\Service\OptionBuilder as B;

const SETTINGS = ['source' => '', 'side' => 'front', 'paper' => 'a4', 'textDpi' => 300, 'photoDpi' => 300, 'brightness' => 0, 'contrast' => 0, 'deskew' => false, 'skipBlank' => false, 'sheets' => 0];

function value(array $args, string $name): ?string {
    $index = array_search($name, $args, true);
    return $index === false ? null : $args[$index + 1];
}

// Output of scanimage -A for a sheet-fed scanner, y still reports the default letter height
$sheetFed = B::parseOptions(<<<'OUTPUT'
Options specific to device `fujitsu:ScanSnap S1500:11497':
  Standard:
    --source ADF Front|ADF Back|ADF Duplex [ADF Front]
    --mode Lineart|Halftone|Gray|Color [Lineart]
    --resolution 50..600dpi (in steps of 1) [600]
  Geometry:
    -x 0..221.121mm (in steps of 0.0211639) [215.872]
    -y 0..279.364mm (in steps of 0.0211639) [279.364]
    --page-width 0..221.121mm (in steps of 0.0211639) [215.872]
    --page-height 0..876.695mm (in steps of 0.0211639) [279.364]
  Enhancement:
    --brightness -127..127 (in steps of 1) [0]
    --contrast -127..127 (in steps of 1) [0]
    --swdeskew[=(yes|no)] [no]
    --swskip 0..100% (in steps of 0.100006) [0]
    --swcrop[=(yes|no)] [no]
    --ald[=(yes|no)] [no] [advanced]
  Sensors:
    --scan[=(yes|no)] [no] [hardware]
OUTPUT);

$flatbed = B::parseOptions(<<<'OUTPUT'
    --source Flatbed [Flatbed]
    --mode Gray|Color [Gray]
    --resolution 75|150|300dpi [150]
    -x 0..216mm [216]
    -y 0..297mm [297]
    --brightness 0..255 [128]
OUTPUT);

check('range', $sheetFed['resolution'], ['min' => 50.0, 'max' => 600.0]);
check('list without unit', $flatbed['resolution']['values'], ['75', '150', '300']);
check('list', $sheetFed['source']['values'], ['ADF Front', 'ADF Back', 'ADF Duplex']);
check('switch', $sheetFed['swdeskew'], ['switch' => true, 'sensor' => false]);
check('sensor', $sheetFed['scan'], ['switch' => true, 'sensor' => true]);

check('button found', B::hasButton($sheetFed), true);
check('software switch is no button', B::hasButton(['swdeskew' => ['switch' => true, 'sensor' => false]]), false);
check('no button', B::hasButton($flatbed), false);

$args = B::build($sheetFed, SETTINGS, 'text');
check('a4 width', value($args, '--page-width'), '210');
check('a4 page height', value($args, '--page-height'), '297');
check('a4 is not cut to the reported letter height', value($args, '-y'), '297');
check('text is gray', value($args, '--mode'), 'Gray');
check('photo is color', value(B::build($sheetFed, SETTINGS, 'photo'), '--mode'), 'Color');
check('resolution', value($args, '--resolution'), '300');
check('resolution clamped', value(B::build($sheetFed, ['textDpi' => 1200] + SETTINGS, 'text'), '--resolution'), '600');
check('nearest listed resolution', value(B::build($flatbed, ['textDpi' => 200] + SETTINGS, 'text'), '--resolution'), '150');
check('photo uses its own resolution', value(B::build($sheetFed, ['textDpi' => 150, 'photoDpi' => 600] + SETTINGS, 'photo'), '--resolution'), '600');

check('front', value($args, '--source'), 'ADF Front');
check('back', value(B::build($sheetFed, ['side' => 'back'] + SETTINGS, 'text'), '--source'), 'ADF Back');
check('both sides', value(B::build($sheetFed, ['side' => 'both'] + SETTINGS, 'text'), '--source'), 'ADF Duplex');
check('saved source wins', value(B::build($sheetFed, ['source' => 'ADF Back', 'side' => 'both'] + SETTINGS, 'text'), '--source'), 'ADF Back');
check('unknown saved source ignored', value(B::build($sheetFed, ['source' => 'Gone'] + SETTINGS, 'text'), '--source'), 'ADF Front');

check('brightness middle', value($args, '--brightness'), '0');
check('brightness full', value(B::build($sheetFed, ['brightness' => 100] + SETTINGS, 'text'), '--brightness'), '127');
check('brightness on a positive range', value(B::build($flatbed, ['brightness' => -100] + SETTINGS, 'text'), '--brightness'), '0');

check('deskew off', in_array('--swdeskew=yes', $args, true), false);
check('deskew on', in_array('--swdeskew=yes', B::build($sheetFed, ['deskew' => true] + SETTINGS, 'text'), true), true);
check('deskew unsupported', in_array('--swdeskew=yes', B::build($flatbed, ['deskew' => true] + SETTINGS, 'text'), true), false);
check('skip blank pages', value(B::build($sheetFed, ['skipBlank' => true] + SETTINGS, 'text'), '--swskip'), '1');

$auto = B::build($sheetFed, ['paper' => 'auto'] + SETTINGS, 'text');
check('auto scans the maximum', value($auto, '--page-height'), '876.695');
check('auto crops', in_array('--swcrop=yes', $auto, true), true);
check('auto without crop support', value(B::build($flatbed, ['paper' => 'auto'] + SETTINGS, 'text'), '-y'), null);

check('advanced switch', $sheetFed['ald'], ['switch' => true, 'sensor' => false]);
check('normal paper does not detect the end', in_array('--ald=yes', B::build($sheetFed, SETTINGS, 'text'), true), false);

$receipt = B::build($sheetFed, ['paper' => 'receipt'] + SETTINGS, 'text');
check('receipt width', [value($receipt, '--page-width'), value($receipt, '-x')], ['80', '80']);
check('receipt allows the longest page', [value($receipt, '--page-height'), value($receipt, '-y')], ['876.695', '876.695']);
check('receipt detects the paper end', in_array('--ald=yes', $receipt, true), true);
check('receipt ignores both sides', value(B::build($sheetFed, ['paper' => 'receipt', 'side' => 'both'] + SETTINGS, 'text'), '--source'), 'ADF Front');
check('receipt ignores a saved duplex source', value(B::build($sheetFed, ['paper' => 'receipt', 'source' => 'ADF Duplex'] + SETTINGS, 'text'), '--source'), 'ADF Front');
check('other papers stay duplex', value(B::build($sheetFed, ['paper' => 'a5', 'side' => 'both'] + SETTINGS, 'text'), '--source'), 'ADF Duplex');

$receipt = B::build($flatbed, ['paper' => 'receipt'] + SETTINGS, 'text');
check('receipt without page size', [value($receipt, '-x'), value($receipt, '-y')], ['80', '297']);
check('receipt without detection', in_array('--ald=yes', $receipt, true), false);

$args = B::build($flatbed, SETTINGS, 'text');
check('flatbed without page size', [value($args, '-x'), value($args, '-y')], ['210', '297']);
check('flatbed scans one page', in_array('--batch-count=1', $args, true), true);
check('feeder scans until empty', in_array('--batch-count=1', B::build($sheetFed, SETTINGS, 'text'), true), false);
check('one sheet from the feeder', in_array('--batch-count=1', B::build($sheetFed, ['sheets' => 1] + SETTINGS, 'text'), true), true);
check('one sheet, both sides', in_array('--batch-count=2', B::build($sheetFed, ['sheets' => 1, 'side' => 'both'] + SETTINGS, 'text'), true), true);
check('one sheet on a flatbed is one page', array_count_values(B::build($flatbed, ['sheets' => 1] + SETTINGS, 'text'))['--batch-count=1'], 1);
check('feeder', B::isFlatbed('ADF Duplex'), false);
check('flatbed', B::isFlatbed('Flatbed'), true);

finish();
