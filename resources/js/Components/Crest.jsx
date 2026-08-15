import { usePage } from '@inertiajs/react';

// El escudo oficial, sin retoques. Archivo estático en public/img/crest.png.
export default function Crest({ size = 38, style = {} }) {
    const { club } = usePage().props;
    const src = club?.crest ?? '/img/crest.png';

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
