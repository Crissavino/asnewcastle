/**
 * Íconos de las tabs, dibujados a mano en el estilo plano del club (línea,
 * currentColor → heredan el rojo activo / gris inactivo de la tab). Imitan la
 * API de lucide (size, strokeWidth) para entrar directo en NavLinks.
 * Conceptos tomados del set del cliente: calendario, podio, camiseta, billetera,
 * jugador — pero planos y nítidos a tamaño de tab.
 */
function Svg({ size = 24, strokeWidth = 2, children, ...rest }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none"
            stroke="currentColor" strokeWidth={strokeWidth}
            strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...rest}>
            {children}
        </svg>
    );
}

// AGENDA — calendario con renglones de convocatoria y un día marcado
export function IconAgenda(props) {
    return (
        <Svg {...props}>
            <rect x="3" y="4.5" width="18" height="16" rx="2.5" />
            <path d="M3 9h18" />
            <path d="M8 2.8v3.4M16 2.8v3.4" />
            <path d="M6.8 13h6.2M6.8 16.4h8.4" />
            <circle cx="16.5" cy="13" r="1.1" fill="currentColor" stroke="none" />
        </Svg>
    );
}

// TABLA — podio (barras 2·1·3), lectura de posiciones
export function IconTabla(props) {
    return (
        <Svg {...props}>
            <path d="M3 21h18" />
            <rect x="4.3" y="12.5" width="4.3" height="8.5" rx="1.3" />
            <rect x="9.85" y="8" width="4.3" height="13" rx="1.3" />
            <rect x="15.4" y="15" width="4.3" height="6" rx="1.3" />
        </Svg>
    );
}

// VESTUARIO — la camiseta del club (motivo Kit), cuello en V
export function IconVestuario(props) {
    return (
        <Svg {...props}>
            <path d="M8.5 3.5 4 5.6 2.5 9.6 5.9 11v9.5h12.2V11l3.4-1.4L20 5.6l-4.5-2.1-1.8 2.1a2.6 2.6 0 0 1-3.4 0Z" />
        </Svg>
    );
}

// CUOTA — billetera con cierre
export function IconCuota(props) {
    return (
        <Svg {...props}>
            <rect x="3" y="6.5" width="18" height="12.5" rx="2.5" />
            <path d="M3 10.6h18" />
            <circle cx="16.8" cy="14.8" r="1.4" fill="currentColor" stroke="none" />
        </Svg>
    );
}

// PERFIL — jugador (cuellito en V para que sea futbolista)
export function IconPerfil(props) {
    return (
        <Svg {...props}>
            <circle cx="12" cy="7.5" r="3.4" />
            <path d="M4.8 20.2c0-3.7 3.2-6.2 7.2-6.2s7.2 2.5 7.2 6.2" />
            <path d="M10.3 14.3 12 16.3l1.7-2" />
        </Svg>
    );
}

// ---- Íconos de acción (mismo estilo plano) ----

// NOTIFICACIONES — campanita
export function IconBell(props) {
    return (
        <Svg {...props}>
            <path d="M6 9.5a6 6 0 0 1 12 0c0 4.4 1.4 5.4 2.4 6.5H3.6C4.6 14.9 6 13.9 6 9.5Z" />
            <path d="M9.8 19a2.3 2.3 0 0 0 4.4 0" />
        </Svg>
    );
}

// FIGURA DEL PARTIDO — estrella (acepta fill para el voto marcado)
export function IconFigura({ size = 24, strokeWidth = 2, fill = 'none' }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill={fill}
            stroke="currentColor" strokeWidth={strokeWidth}
            strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M12 2.4 14.7 8.6 21.6 9.2 16.4 13.7 18 20.4 12 16.9 6 20.4 7.6 13.7 2.4 9.2 9.3 8.6Z" />
        </svg>
    );
}

// FOTO — cámara
export function IconCamara(props) {
    return (
        <Svg {...props}>
            <path d="M3 8.7a2 2 0 0 1 2-2h1.8l1.2-1.9h6l1.2 1.9H19a2 2 0 0 1 2 2v8.6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" />
            <circle cx="12" cy="13" r="3.4" />
        </Svg>
    );
}

// CANCHA — pin de ubicación
export function IconUbicacion(props) {
    return (
        <Svg {...props}>
            <path d="M12 21.4c4-4.2 7-7.6 7-11.4a7 7 0 1 0-14 0c0 3.8 3 7.2 7 11.4Z" />
            <circle cx="12" cy="10" r="2.6" />
        </Svg>
    );
}
