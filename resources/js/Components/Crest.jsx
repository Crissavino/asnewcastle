import { usePage } from '@inertiajs/react';

// El escudo oficial. Misma silueta siempre; `white` usa la versión en blanco
// (knockout) para fondos oscuros/rojos —header y sidebar—, negro en el resto.
export default function Crest({ size = 38, white = false, style = {} }) {
    const { club } = usePage().props;
    const src = white ? '/img/crest-white.png' : (club?.crest ?? '/img/crest.png');

    return (
        <img
            src={src}
            alt={club?.name ?? 'A.S New Castle'}
            width={size}
            height={Math.round(size * 1.05)}
            style={{ display: 'block', objectFit: 'contain', ...style }}
        />
    );
}
