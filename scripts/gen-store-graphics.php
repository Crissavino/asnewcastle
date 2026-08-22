<?php

/**
 * Genera los gráficos para la ficha de Google Play:
 *  - play-icon-512.png       (ícono, 512×512)
 *  - play-feature-1024x500.png (feature graphic / banner)
 * Salida en scripts/store-assets/. Ejecutar: php scripts/gen-store-graphics.php
 */

$root = dirname(__DIR__);
$out = $root.'/scripts/store-assets';
@mkdir($out, 0755, true);

$crestBlack = imagecreatefrompng($root.'/public/img/crest.png');       // negro
$crestWhite = imagecreatefrompng($root.'/public/img/crest-white.png'); // blanco
$font = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';

$RED = [210, 34, 51];
$WHITE = [255, 255, 255];
$INK = [18, 18, 18];

/** Escudo centrado (contain) sobre un cuadrado de color. */
function iconOnColor(GdImage $crest, int $size, float $pad, array $bg): GdImage
{
    $cw = imagesx($crest); $ch = imagesy($crest);
    $c = imagecreatetruecolor($size, $size);
    imagefilledrectangle($c, 0, 0, $size, $size, imagecolorallocate($c, ...$bg));
    $box = $size * (1 - 2 * $pad);
    $s = min($box / $cw, $box / $ch);
    $dw = (int) round($cw * $s); $dh = (int) round($ch * $s);
    imagealphablending($c, true);
    imagecopyresampled($c, $crest, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $cw, $ch);
    return $c;
}

// --- Ícono 512×512: blanco con escudo negro ---
$icon = iconOnColor($crestBlack, 512, 0.12, $WHITE);
imagepng($icon, $out.'/play-icon-512.png');
echo "✓ play-icon-512.png\n";

// --- Feature graphic 1024×500 ---
$W = 1024; $H = 500;
$fg = imagecreatetruecolor($W, $H);
imagefilledrectangle($fg, 0, 0, $W, $H, imagecolorallocate($fg, ...$RED));
// pinstripes verticales sutiles (como el header de la app)
$stripe = imagecolorallocatealpha($fg, 255, 255, 255, 100);
for ($x = 0; $x < $W; $x += 22) {
    imagefilledrectangle($fg, $x, 0, $x + 2, $H, $stripe);
}
// borde inferior: filete celeste (color suplente)
imagefilledrectangle($fg, 0, $H - 8, $W, $H, imagecolorallocate($fg, 138, 212, 216));

// escudo blanco a la izquierda
$cw = imagesx($crestWhite); $ch = imagesy($crestWhite);
$target = 360; $s = $target / $ch;
$dw = (int) round($cw * $s); $dh = (int) round($ch * $s);
imagealphablending($fg, true);
imagecopyresampled($fg, $crestWhite, 90, (int) (($H - $dh) / 2), 0, 0, $dw, $dh, $cw, $ch);

// texto a la derecha
$white = imagecolorallocate($fg, ...$WHITE);
$shadow = imagecolorallocatealpha($fg, 0, 0, 0, 80);
$tx = 470;
imagettftext($fg, 60, 0, $tx + 3, 233, $shadow, $font, 'A.S NEW');
imagettftext($fg, 60, 0, $tx, 230, $white, $font, 'A.S NEW');
imagettftext($fg, 60, 0, $tx + 3, 313, $shadow, $font, 'CASTLE');
imagettftext($fg, 60, 0, $tx, 310, $white, $font, 'CASTLE');
imagettftext($fg, 22, 0, $tx + 2, 372, $shadow, $font, 'La app del club');
imagettftext($fg, 22, 0, $tx, 370, imagecolorallocate($fg, 235, 235, 235), $font, 'La app del club');

imagepng($fg, $out.'/play-feature-1024x500.png');
echo "✓ play-feature-1024x500.png\n";
echo "listo → scripts/store-assets/\n";
