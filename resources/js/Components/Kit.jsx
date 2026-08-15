// La camiseta con el dorsal. kit: home (roja) | away (celeste).
// size: "" | "sm" | "lg". ghost: apagada, para los que están en duda.
export default function Kit({ n, kit = 'home', size = '', ghost = false }) {
    return <div className={`nc-kit ${kit} ${size} ${ghost ? 'ghost' : ''}`}>{n}</div>;
}
