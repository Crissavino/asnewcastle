<?php
// Versión "en blanco" del escudo: misma silueta, solo se invierte el color
// para que se lea sobre fondo oscuro/rojo (header y sidebar). No se redibuja.
$src = 'public/img/crest.png';
$out = 'public/img/crest-white.png';

$img = imagecreatefrompng($src);
$w = imagesx($img); $h = imagesy($img);

$dst = imagecreatetruecolor($w, $h);
imagealphablending($dst, false);
imagesavealpha($dst, true);
imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocatealpha($dst, 255, 255, 255, 127));

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgba = imagecolorat($img, $x, $y);
        $alpha = ($rgba >> 24) & 0x7F;      // 0 opaco … 127 transparente
        if ($alpha < 127) {
            // Mantiene la opacidad original (anti-alias) pero pinta blanco
            imagesetpixel($dst, $x, $y, imagecolorallocatealpha($dst, 255, 255, 255, $alpha));
        }
    }
}

imagepng($dst, $out);
echo "ok: $out (".$w."x".$h.")\n";
