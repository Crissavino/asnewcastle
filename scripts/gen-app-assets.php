<?php

/**
 * Genera los assets fuente para los íconos y splash de la app nativa
 * (Android + iOS), a partir del escudo. Después: npx capacitor-assets generate.
 *
 *  - icon (app): fondo BLANCO, escudo negro centrado (lo aprobado para la PWA).
 *  - adaptive foreground/background para Android (escudo con safe zone).
 *  - splash: fondo ROJO del club, escudo blanco centrado (de marca).
 */

$root = dirname(__DIR__);
$crestBlack = imagecreatefrompng($root.'/public/img/crest.png');       // negro sobre transparente
$crestWhite = imagecreatefrompng($root.'/public/img/crest-white.png'); // blanco sobre transparente
$out = $root.'/assets';
@mkdir($out, 0755, true);

/** Compone el escudo centrado (contain) sobre un fondo sólido o transparente. */
function make(GdImage $crest, int $size, float $pad, ?array $bg): GdImage
{
    $cw = imagesx($crest);
    $ch = imagesy($crest);
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    if ($bg === null) {
        imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    } else {
        imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocate($canvas, $bg[0], $bg[1], $bg[2]));
    }

    $box = $size * (1 - 2 * $pad);
    $scale = min($box / $cw, $box / $ch);
    $dw = (int) round($cw * $scale);
    $dh = (int) round($ch * $scale);
    $dx = (int) round(($size - $dw) / 2);
    $dy = (int) round(($size - $dh) / 2);

    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $crest, $dx, $dy, 0, 0, $dw, $dh, $cw, $ch);

    return $canvas;
}

$white = [255, 255, 255];
$red = [210, 34, 51]; // #D22233, casaca titular

$targets = [
    // [nombre, imagen, tamaño, padding, fondo]
    ['icon-only.png', $crestBlack, 1024, 0.12, $white],       // ícono app (iOS/Android legacy)
    ['icon-foreground.png', $crestBlack, 1024, 0.26, null],   // adaptive foreground (safe zone Android)
    ['icon-background.png', $crestBlack, 1024, 0.50, $white], // adaptive background: blanco liso
    ['splash.png', $crestWhite, 2732, 0.38, $red],            // splash claro
    ['splash-dark.png', $crestWhite, 2732, 0.38, $red],       // splash oscuro (mismo, de marca)
];

foreach ($targets as [$name, $crest, $size, $pad, $bg]) {
    // el background adaptive va sin escudo: solo color liso
    if ($name === 'icon-background.png') {
        $img = imagecreatetruecolor($size, $size);
        imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, $white[0], $white[1], $white[2]));
    } else {
        $img = make($crest, $size, $pad, $bg);
    }
    imagepng($img, $out.'/'.$name);
    imagedestroy($img);
    echo "✓ assets/{$name} ({$size}px)\n";
}

echo "listo\n";
