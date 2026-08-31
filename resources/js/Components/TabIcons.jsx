/**
 * Íconos de las tabs, dibujados a mano en el estilo plano del club (línea,
 * currentColor → heredan el rojo activo / gris inactivo de la tab). Imitan la
 * API de lucide (size, strokeWidth) para entrar directo en NavLinks.
 * Conceptos tomados del set del cliente: calendario, podio, camiseta, billetera,
 * jugador — pero planos y nítidos a tamaño de tab.
 */
function Svg({ size = 24, strokeWidth = 2, children }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none"
            stroke="currentColor" strokeWidth={strokeWidth}
            strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {children}
        </svg>
    );
}

// AGENDA — calendario con renglones de convocatoria
export function IconAgenda(props) {
    return (
        <Svg {...props}>
            <rect x="3" y="4.5" width="18" height="16" rx="2.5" />
            <path d="M3 9h18" />
            <path d="M8 2.8v3.4M16 2.8v3.4" />
            <path d="M6.8 13h6M6.8 16.5h8.4" />
        </Svg>
    );
}

// TABLA — podio (barras 2·1·3), lectura de posiciones
export function IconTabla(props) {
    return (
        <Svg {...props}>
            <path d="M3 21h18" />
            <rect x="4.2" y="12" width="4.4" height="9" rx="1" />
            <rect x="9.8" y="7.5" width="4.4" height="13.5" rx="1" />
            <rect x="15.4" y="15" width="4.4" height="6" rx="1" />
        </Svg>
    );
}

// VESTUARIO — la camiseta del club (motivo Kit)
export function IconVestuario(props) {
    return (
        <Svg {...props}>
            <path d="M9 4 4.5 6 3 9.5 6 11v9h12v-9l3-1.5L19.5 6 15 4a3 3 0 0 1-6 0Z" />
        </Svg>
    );
}

// CUOTA — billetera con cierre
export function IconCuota(props) {
    return (
        <Svg {...props}>
            <rect x="3" y="6" width="18" height="13" rx="2.5" />
            <path d="M3 10.5h18" />
            <circle cx="16.7" cy="14.6" r="1.4" />
        </Svg>
    );
}

// PERFIL — jugador
export function IconPerfil(props) {
    return (
        <Svg {...props}>
            <circle cx="12" cy="8" r="3.5" />
            <path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" />
        </Svg>
    );
}
