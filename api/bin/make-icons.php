<?php

declare(strict_types=1);

// Генератор иконок приложения из логотипа (дизайн frontend/public/logo.svg, воспроизведён средствами GD).
// Запуск: php api/bin/make-icons.php

$outDir = dirname(__DIR__, 2) . '/frontend/public';

$targets = [
    512 => 'icon-512.png',
    192 => 'icon-192.png',
    180 => 'apple-touch-icon.png',
    32 => 'favicon.png',
];

foreach ($targets as $size => $name) {
    $image = renderIcon($size);
    imagepng($image, $outDir . '/' . $name);
    imagedestroy($image);
    echo 'written ' . $name . ' (' . $size . 'x' . $size . ")\n";
}

function renderIcon(int $size): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imageantialias($image, true);

    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefilledrectangle($image, 0, 0, $size, $size, $transparent);
    imagealphablending($image, true);

    $scale = $size / 64;
    $radius = 14 * $scale;

    for ($y = 0; $y < $size; $y++) {
        [$x1, $x2] = tileSpan($y, $size, $radius);

        if ($x2 <= $x1) {
            continue;
        }

        $t = $y / max(1, $size - 1);
        $color = imagecolorallocate(
            $image,
            (int) round(59 + (29 - 59) * $t),
            (int) round(130 + (78 - 130) * $t),
            (int) round(246 + (216 - 246) * $t)
        );

        imageline($image, $x1, $y, $x2, $y, $color);
    }

    $white = imagecolorallocate($image, 255, 255, 255);
    roundedRect($image, 14 * $scale, 18 * $scale, 36 * $scale, 24 * $scale, 6 * $scale, $white);
    imagefilledpolygon($image, [
        32 * $scale, 41 * $scale,
        23 * $scale, 50 * $scale,
        23 * $scale, 41 * $scale,
    ], $white);

    $blue = imagecolorallocate($image, 37, 99, 235);
    imagesetthickness($image, max(2, (int) round(4.5 * $scale)));
    imageline($image, (int) round(24 * $scale), (int) round(30 * $scale), (int) round(29 * $scale), (int) round(35 * $scale), $blue);
    imageline($image, (int) round(29 * $scale), (int) round(35 * $scale), (int) round(40 * $scale), (int) round(24 * $scale), $blue);

    return $image;
}

/** @return array{0: int, 1: int} */
function tileSpan(int $y, int $size, float $radius): array
{
    $dy = 0.0;

    if ($y < $radius) {
        $dy = $radius - $y;
    } elseif ($y > $size - $radius) {
        $dy = $y - ($size - $radius);
    }

    $inset = $dy > 0 ? $radius - sqrt(max(0.0, $radius * $radius - $dy * $dy)) : 0.0;

    return [(int) ceil($inset), (int) floor($size - 1 - $inset)];
}

function roundedRect(GdImage $image, float $x, float $y, float $w, float $h, float $r, int $color): void
{
    imagefilledrectangle($image, (int) ($x + $r), (int) $y, (int) ($x + $w - $r), (int) ($y + $h), $color);
    imagefilledrectangle($image, (int) $x, (int) ($y + $r), (int) ($x + $w), (int) ($y + $h - $r), $color);

    $d = (int) round($r * 2);

    imagefilledellipse($image, (int) ($x + $r), (int) ($y + $r), $d, $d, $color);
    imagefilledellipse($image, (int) ($x + $w - $r), (int) ($y + $r), $d, $d, $color);
    imagefilledellipse($image, (int) ($x + $r), (int) ($y + $h - $r), $d, $d, $color);
    imagefilledellipse($image, (int) ($x + $w - $r), (int) ($y + $h - $r), $d, $d, $color);
}
