<?php

/**
 * Genera los íconos de la PWA a partir del escudo (public/img/crest.png).
 * Fondo BLANCO con el escudo negro centrado: sobre rojo el escudo (negro
 * sobre transparente) desaparecía en la vista previa de WhatsApp/link.
 *
 * Rehacer con:  php scripts/gen-icons.php
 */

$root = dirname(__DIR__);
$src = $root.'/public/img/crest.png';
$outDir = $root.'/public/img/icons';

$crest = imagecreatefrompng($src);
$cw = imagesx($crest);
$ch = imagesy($crest);

/**
 * Dibuja el escudo centrado sobre un cuadrado de fondo sólido.
 * $pad = fracción del lado que queda como margen a cada lado (0.09 = 9%).
 */
function render(GdImage $crest, int $cw, int $ch, int $size, float $pad, array $bg): GdImage
{
    $canvas = imagecreatetruecolor($size, $size);
    $fill = imagecolorallocate($canvas, $bg[0], $bg[1], $bg[2]);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $fill);

    // Caja disponible tras el margen; el escudo entra completo (contain).
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

$targets = [
    // [nombre, tamaño, margen]
    ['apple-touch-icon.png', 180, 0.10],
    ['icon-192.png', 192, 0.10],
    ['icon-512.png', 512, 0.10],
    // maskable: el sistema recorta a un círculo, así que más margen (safe zone)
    ['icon-maskable-512.png', 512, 0.22],
];

foreach ($targets as [$name, $size, $pad]) {
    $img = render($crest, $cw, $ch, $size, $pad, $white);
    imagepng($img, $outDir.'/'.$name);
    imagedestroy($img);
    echo "✓ {$name} ({$size}px, fondo blanco)\n";
}

imagedestroy($crest);
echo "listo\n";
