import React, { useState, useRef, useEffect } from "react";
import {
  Check, X, HelpCircle, Send, ChevronLeft, CalendarDays, ListOrdered,
  MessageSquare, Wallet, User, MapPin, Lock, Shield, Plus, Copy, Bell, Smartphone
} from "lucide-react";

/* ------------------------------------------------------------------ */
/*  A.S NEW CASTLE — prototipo                                         */
/*  Casaca titular roja con pinstripes, suplente celeste, escudo negro */
/* ------------------------------------------------------------------ */

const ROSTER = [
  { id: 1, name: "Cristian Savino", num: 10, pos: "MED", paid: true, rsvp: { e1: "in", e2: "in" } },
  { id: 2, name: "Sergio Quiroga", num: 5, pos: "MED", paid: true, rsvp: { e1: "in", e2: "in" } },
  { id: 3, name: "Fabián Rodríguez", num: 7, pos: "DEL", paid: false, rsvp: { e1: "maybe", e2: "in" } },
  { id: 4, name: "Andrez Rodríguez", num: 4, pos: "DEF", paid: true, rsvp: { e1: "in", e2: "in" } },
  { id: 5, name: "Mihai Ionescu", num: 1, pos: "ARQ", paid: true, rsvp: { e1: "out", e2: "in" } },
  { id: 6, name: "Răzvan Popa", num: 3, pos: "DEF", paid: true, rsvp: { e1: "in", e2: "maybe" } },
  { id: 7, name: "Diego Ferreyra", num: 9, pos: "DEL", paid: false, rsvp: { e1: "in", e2: "in" } },
  { id: 8, name: "Andrei Marin", num: 6, pos: "MED", paid: true, rsvp: { e1: "maybe", e2: "in" } },
  { id: 9, name: "Camilo Restrepo", num: 11, pos: "DEL", paid: true, rsvp: { e1: "in", e2: "out" } },
  { id: 10, name: "Vlad Dumitru", num: 2, pos: "DEF", paid: false, rsvp: { e1: "out", e2: "in" } },
  { id: 11, name: "Tomás Aguirre", num: 8, pos: "MED", paid: true, rsvp: { e1: "in", e2: "in" } },
  { id: 12, name: "Ionuț Stan", num: 14, pos: "DEF", paid: true, rsvp: { e1: "in", e2: "maybe" } },
];

const TAKEN = ROSTER.map((p) => p.num);

const DEMO_ME = { name: "Marius Ilie", num: 21, pos: "MED", foot: "Derecho", slots: ["Martes 20:30", "Sábado a la mañana"] };

const BASE_EVENTS = [
  { id: "e1", kind: "Entrenamiento", kit: "home", date: "Martes 18 de agosto", time: "20:30",
    place: "Baza Sportivă Voluntari — sintético 2", meta: "Llevar botella. El portón se cierra 20:45." },
  { id: "e2", kind: "Partido", kit: "home", home: true, rival: "CS Afumați II", date: "Sábado 22 de agosto",
    time: "11:00", place: "Teren Voluntari", meta: "Liga a V-a Ilfov · Etapa 3" },
  { id: "e3", kind: "Partido", kit: "away", home: false, rival: "AS Dascălu", date: "Sábado 29 de agosto",
    time: "17:00", place: "Teren Dascălu — visitante", meta: "Liga a V-a Ilfov · Etapa 4" },
];

const TABLE = [
  { pos: 1, team: "CS Afumați II", pj: 2, dg: 5, pts: 6 },
  { pos: 2, team: "A.S New Castle", pj: 2, dg: 3, pts: 4, us: true },
  { pos: 3, team: "Unirea Balotești II", pj: 2, dg: 2, pts: 4 },
  { pos: 4, team: "Sportiv Ștefănești", pj: 2, dg: 0, pts: 3 },
  { pos: 5, team: "AS Dascălu", pj: 2, dg: -1, pts: 3 },
  { pos: 6, team: "CS Găneasa", pj: 2, dg: -2, pts: 1 },
  { pos: 7, team: "AS Petrăchioaia", pj: 2, dg: -3, pts: 1 },
  { pos: 8, team: "AS Grădiștea", pj: 2, dg: -4, pts: 0 },
];

const SEED = [
  { id: 1, who: "Sergio", num: 5, text: "Confirmen para el sábado, la lista se manda el jueves.", at: "09:12" },
  { id: 2, who: "system", text: "Diego confirmó para el partido del sábado 22." },
  { id: 3, who: "Mihai", num: 1, text: "El martes no llego, tengo turno hasta las 21.", at: "10:04" },
  { id: 4, who: "Fabián", num: 7, text: "Llegaron las camisetas nuevas. Rojas de local, celestes de visitante.", at: "10:31" },
  { id: 5, who: "system", text: "El 29 se juega de visitante — va la casaca celeste." },
];

const POSITIONS = [
  { key: "ARQ", label: "Arquero" }, { key: "DEF", label: "Defensor" },
  { key: "MED", label: "Mediocampista" }, { key: "DEL", label: "Delantero" },
];
const FEET = ["Derecho", "Izquierdo", "Los dos"];
const SLOTS = ["Martes 20:30", "Jueves 20:30", "Sábado a la mañana", "Domingo a la mañana"];

/* ------------------------------------------------------------------ */

const CSS = `
@import url('https://fonts.googleapis.com/css2?family=Archivo+Black&family=Archivo:wght@400;500;600;700&family=Rubik:wght@500;600;700&display=swap');

.nc-root {
  --red: #D22233; --red-dk: #9C1523; --aqua: #8AD4D8; --aqua-dk: #2E8288;
  --ink: #121212; --paper: #F1F2EF; --card: #FFFFFF; --stone: #767B77; --line: #DBDDD8;
  font-family: 'Archivo', system-ui, sans-serif; color: var(--ink);
  background: #E3E5E0; min-height: 100vh; display: flex; justify-content: center;
}
.nc-root *, .nc-root *::before, .nc-root *::after { box-sizing: border-box; }
.nc-app { width: 100%; max-width: 430px; min-height: 100vh; background: var(--paper);
  display: flex; flex-direction: column; position: relative; box-shadow: 0 0 0 1px var(--line); }
.nc-display { font-family: 'Archivo Black', sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.nc-num { font-family: 'Rubik', sans-serif; font-variant-numeric: tabular-nums; }
.nc-pinstripe { background: repeating-linear-gradient(90deg, transparent 0 9px, rgba(255,255,255,.30) 9px 11px), var(--red); }

/* barra demo */
.nc-demo { background: var(--ink); color: #fff; font-size: 10.5px; letter-spacing: .1em;
  text-transform: uppercase; font-weight: 700; text-align: center; padding: 7px 12px; }

/* camiseta */
.nc-kit { width: 32px; height: 36px; flex: 0 0 auto; position: relative;
  clip-path: polygon(22% 0, 36% 9%, 64% 9%, 78% 0, 100% 15%, 88% 33%, 82% 27%, 82% 100%, 18% 100%, 18% 27%, 12% 33%, 0 15%);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Rubik', sans-serif; font-weight: 700; font-size: 12px; padding-top: 9px; line-height: 1; }
.nc-kit.home { background: repeating-linear-gradient(90deg, transparent 0 5px, rgba(255,255,255,.45) 5px 6px), var(--red); color: #fff; }
.nc-kit.away { background: repeating-linear-gradient(115deg, transparent 0 5px, rgba(255,255,255,.5) 5px 6px), var(--aqua); color: var(--ink); }
.nc-kit.sm { width: 25px; height: 28px; font-size: 9px; padding-top: 7px; }
.nc-kit.lg { width: 74px; height: 84px; font-size: 27px; padding-top: 20px; }
.nc-kit.ghost { opacity: .3; filter: grayscale(.85); }

/* header */
.nc-top { position: sticky; top: 0; z-index: 20; color: #fff; padding: 14px 16px 13px;
  display: flex; align-items: center; gap: 12px; border-bottom: 3px solid var(--ink); }
.nc-eyebrow { font-size: 10px; letter-spacing: .2em; text-transform: uppercase; font-weight: 700; opacity: .85; }
.nc-h1 { font-size: 24px; line-height: .95; margin: 2px 0 0; }
.nc-role { margin-left: auto; display: flex; background: rgba(0,0,0,.28); border-radius: 2px; padding: 2px; }
.nc-role button { border: none; background: transparent; color: rgba(255,255,255,.75); cursor: pointer;
  font-family: 'Archivo', sans-serif; font-size: 10px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; padding: 6px 8px; border-radius: 2px; }
.nc-role button.on { background: #fff; color: var(--red-dk); }
.nc-body { flex: 1; padding: 16px 14px 100px; }

.nc-card { background: var(--card); border: 1px solid var(--line); border-radius: 2px; padding: 16px; margin-bottom: 12px; }
.nc-card.match { border-left: 4px solid var(--red); }
.nc-card.away-match { border-left: 4px solid var(--aqua-dk); }
.nc-label { font-size: 10px; letter-spacing: .16em; text-transform: uppercase; color: var(--stone); font-weight: 700; }
.nc-meta { font-size: 13px; color: var(--stone); line-height: 1.45; }

.nc-rsvp { display: flex; gap: 7px; margin-top: 14px; }
.nc-rsvp button { flex: 1; padding: 10px 4px; border-radius: 2px; cursor: pointer; background: #fff;
  border: 1px solid var(--line); color: var(--stone); font-family: 'Archivo', sans-serif; font-size: 12px;
  font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  display: flex; align-items: center; justify-content: center; gap: 5px; transition: background .14s, color .14s, border-color .14s; }
.nc-rsvp button:hover { border-color: var(--ink); color: var(--ink); }
.nc-rsvp button.on.in { background: var(--red); border-color: var(--red); color: #fff; }
.nc-rsvp button.on.maybe { background: var(--aqua); border-color: var(--aqua); color: var(--ink); }
.nc-rsvp button.on.out { background: var(--ink); border-color: var(--ink); color: #fff; }
.nc-root button:focus-visible, .nc-root input:focus-visible, .nc-root select:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }

.nc-count { display: flex; align-items: baseline; gap: 7px; margin-top: 16px; }
.nc-count .n { font-family: 'Archivo Black', sans-serif; font-size: 32px; line-height: 1; }
.nc-kits { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 11px; }

/* bloque delegado */
.nc-admin { margin-top: 16px; padding-top: 14px; border-top: 2px dashed var(--line); }
.nc-admin-actions { display: flex; gap: 7px; margin-top: 12px; flex-wrap: wrap; }
.nc-mini { flex: 1; min-width: 120px; padding: 10px 8px; border-radius: 2px; cursor: pointer;
  background: #fff; border: 1px solid var(--ink); color: var(--ink); font-family: 'Archivo', sans-serif;
  font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  display: flex; align-items: center; justify-content: center; gap: 6px; }
.nc-mini.solid { background: var(--ink); color: #fff; }
.nc-namelist { font-size: 13px; line-height: 1.6; color: var(--ink); margin-top: 8px; }

/* tabla */
.nc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.nc-table th { font-size: 9px; letter-spacing: .14em; text-transform: uppercase; color: var(--stone); text-align: right; padding: 0 0 8px; font-weight: 700; }
.nc-table th:nth-child(2) { text-align: left; }
.nc-table td { padding: 9px 0; border-top: 1px solid var(--line); text-align: right; }
.nc-table td:nth-child(2) { text-align: left; }
.nc-table tr.us td { background: rgba(210,34,51,.07); color: var(--red-dk); font-weight: 700; }
.nc-table tr.us td:first-child { box-shadow: inset 4px 0 0 var(--red); }

/* chat */
.nc-msg { margin-bottom: 13px; display: flex; gap: 10px; align-items: flex-start; }
.nc-msg.mine { flex-direction: row-reverse; }
.nc-bubble { background: #fff; border: 1px solid var(--line); border-radius: 2px; padding: 9px 12px; max-width: 76%; font-size: 14px; line-height: 1.42; }
.nc-msg.mine .nc-bubble { background: var(--red); border-color: var(--red); color: #fff; }
.nc-sys { text-align: center; font-size: 11px; color: var(--stone); letter-spacing: .07em; padding: 4px 0 14px; text-transform: uppercase; font-weight: 600; }
.nc-composer { display: flex; gap: 8px; padding-top: 12px; border-top: 1px solid var(--line); }
.nc-composer input { flex: 1; background: #fff; border: 1px solid var(--line); border-radius: 2px; padding: 11px 12px; color: var(--ink); font-family: 'Archivo', sans-serif; font-size: 14px; }
.nc-composer input::placeholder { color: var(--stone); }

/* botones */
.nc-btn { width: 100%; padding: 14px; border: none; border-radius: 2px; cursor: pointer; background: var(--red); color: #fff;
  font-family: 'Archivo Black', sans-serif; text-transform: uppercase; letter-spacing: .04em; font-size: 14px; }
.nc-btn:disabled { opacity: .28; cursor: not-allowed; }
.nc-btn.ghost { background: #fff; border: 1px solid var(--line); color: var(--ink); }
.nc-btn.dark { background: var(--ink); }
.nc-icon-btn { background: var(--red); border: none; border-radius: 2px; width: 44px; display: flex; align-items: center; justify-content: center; color: #fff; cursor: pointer; }

/* tabs */
.nc-tabs { position: sticky; bottom: 0; display: flex; background: #fff; border-top: 1px solid var(--line); }
.nc-tabs button { flex: 1; padding: 10px 2px 12px; background: transparent; border: none; cursor: pointer;
  color: var(--stone); display: flex; flex-direction: column; align-items: center; gap: 4px;
  font-size: 9px; letter-spacing: .09em; text-transform: uppercase; font-weight: 700;
  font-family: 'Archivo', sans-serif; border-top: 3px solid transparent; margin-top: -1px; }
.nc-tabs button.on { color: var(--red); border-top-color: var(--red); }

/* alta */
.nc-step { padding: 24px 20px 28px; display: flex; flex-direction: column; min-height: 100vh; }
.nc-progress { display: flex; gap: 4px; margin-bottom: 26px; }
.nc-progress i { flex: 1; height: 3px; background: var(--line); }
.nc-progress i.on { background: var(--red); }
.nc-q { font-size: 25px; line-height: 1.03; margin: 6px 0 20px; }
.nc-opt { width: 100%; text-align: left; padding: 15px 16px; margin-bottom: 8px; cursor: pointer;
  background: #fff; border: 1px solid var(--line); border-radius: 2px; color: var(--ink);
  font-family: 'Archivo', sans-serif; font-size: 15px; display: flex; justify-content: space-between; align-items: center; }
.nc-opt.on { border-color: var(--red); background: rgba(210,34,51,.06); color: var(--red-dk); font-weight: 600; }
.nc-input { width: 100%; background: transparent; border: none; border-bottom: 3px solid var(--ink);
  padding: 8px 0; color: var(--ink); font-family: 'Archivo Black', sans-serif; font-size: 25px; text-transform: uppercase; }
.nc-input::placeholder { color: #C6C9C4; }
.nc-numgrid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; }
.nc-numgrid button { aspect-ratio: 1; border: 1px solid var(--line); background: #fff; color: var(--ink);
  border-radius: 2px; cursor: pointer; font-family: 'Rubik', sans-serif; font-size: 13px; font-weight: 600; }
.nc-numgrid button.on { background: var(--red); border-color: var(--red); color: #fff; }
.nc-numgrid button:disabled { opacity: .3; cursor: not-allowed; text-decoration: line-through; }
.nc-skip { background: none; border: none; color: var(--stone); font-family: 'Archivo', sans-serif;
  font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  cursor: pointer; text-decoration: underline; text-underline-offset: 4px; padding: 14px; width: 100%; }

/* sheets */
.nc-sheet { position: fixed; inset: 0; background: rgba(18,18,18,.55); z-index: 60; display: flex; align-items: flex-end; justify-content: center; }
.nc-sheet-inner { width: 100%; max-width: 430px; background: #fff; border-top: 4px solid var(--red);
  padding: 22px 20px 26px; animation: nc-up .2s ease-out; max-height: 92vh; overflow-y: auto; }
.nc-sheet-inner.plain { border-top-color: var(--ink); padding: 0; }
@keyframes nc-up { from { transform: translateY(16px); opacity: 0 } to { transform: none; opacity: 1 } }
.nc-row { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-top: 1px solid var(--line); }
.nc-bar { height: 7px; background: var(--line); border-radius: 2px; overflow: hidden; margin-top: 12px; }
.nc-bar i { display: block; height: 100%; }
.nc-pill { font-size: 10px; letter-spacing: .12em; text-transform: uppercase; font-weight: 700; padding: 4px 8px; border-radius: 2px; }
.nc-pill.ok { background: rgba(46,130,136,.14); color: var(--aqua-dk); }
.nc-pill.no { background: rgba(210,34,51,.11); color: var(--red-dk); }

/* formulario */
.nc-field-l { display: block; margin-bottom: 12px; }
.nc-field-l input, .nc-field-l select { width: 100%; padding: 11px 12px; border: 1px solid var(--line);
  border-radius: 2px; font-family: 'Archivo', sans-serif; font-size: 14px; color: var(--ink); background: #fff; margin-top: 5px; }

/* whatsapp */
.wa { background: #EFE6DE; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
.wa-top { background: #075E54; color: #fff; padding: 12px 14px; display: flex; align-items: center; gap: 11px; }
.wa-avatar { width: 36px; height: 36px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; overflow: hidden; flex: 0 0 auto; }
.wa-chat { padding: 16px 12px 18px; }
.wa-day { text-align: center; margin-bottom: 14px; }
.wa-day span { background: rgba(255,255,255,.85); color: #54656F; font-size: 11px; padding: 4px 10px; border-radius: 6px; }
.wa-in, .wa-out { max-width: 84%; border-radius: 8px; font-size: 14px; line-height: 1.42; color: #111B21;
  box-shadow: 0 1px 1px rgba(0,0,0,.13); margin-bottom: 3px; }
.wa-in { background: #fff; }
.wa-out { background: #D9FDD3; margin-left: auto; padding: 7px 10px 5px; }
.wa-in .wa-text { padding: 8px 10px 5px; }
.wa-time { font-size: 10.5px; color: #667781; text-align: right; padding: 0 10px 6px; }
.wa-btns { border-top: 1px solid #E9EDEF; }
.wa-btns button { width: 100%; background: transparent; border: none; border-top: 1px solid #E9EDEF;
  color: #027EB5; font-size: 14px; font-weight: 500; padding: 11px 0; cursor: pointer; font-family: inherit; }
.wa-btns button:first-child { border-top: none; }
.wa-btns button:disabled { color: #8696A0; cursor: default; }
.wa-note { background: #fff; padding: 16px 18px 20px; border-top: 1px solid var(--line); }

@media (prefers-reduced-motion: reduce) { .nc-root *, .nc-root *::before { animation: none !important; transition: none !important; } }
`;

/* ---------------------------- ESCUDO ------------------------------ */
/* Escudo oficial del club, sin retoques: el archivo original con el
   fondo recortado para que apoye sobre cualquier superficie.        */

const CREST = "data:image/png;base64,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";

function Crest({ size = 38, style = {} }) {
  return (
    <img src={CREST} alt="Escudo A.S New Castle" width={size} height={Math.round(size * 1.05)}
      style={{ display: "block", objectFit: "contain", ...style }} />
  );
}

function Kit({ n, kit = "home", size = "", ghost = false }) {
  return <div className={`nc-kit ${kit} ${size} ${ghost ? "ghost" : ""}`}>{n}</div>;
}

/* ------------------------ WHATSAPP PREVIEW ------------------------ */

function WhatsAppSheet({ ev, onClose }) {
  const [answered, setAnswered] = useState(false);
  const kitLabel = ev.kit === "away" ? "Casaca celeste (visitante)" : "Casaca roja (local)";
  const head = ev.rival ? `${ev.kind} vs ${ev.rival}` : ev.kind;

  return (
    <div className="nc-sheet" onClick={onClose}>
      <div className="nc-sheet-inner plain" onClick={(e) => e.stopPropagation()}>
        <div className="wa">
          <div className="wa-top">
            <div className="wa-avatar"><Crest size={26} /></div>
            <div>
              <div style={{ fontSize: 15, fontWeight: 600 }}>A.S New Castle</div>
              <div style={{ fontSize: 11, opacity: .8 }}>cuenta de empresa</div>
            </div>
            <button onClick={onClose} style={{ marginLeft: "auto", background: "none", border: "none", color: "#fff", cursor: "pointer" }} aria-label="Cerrar">
              <X size={20} />
            </button>
          </div>

          <div className="wa-chat">
            <div className="wa-day"><span>jueves</span></div>

            <div className="wa-in">
              <div className="wa-text">
                <strong>{head}</strong><br />
                {ev.date} · {ev.time}<br />
                {ev.place}<br />
                {kitLabel}<br /><br />
                ¿Vas?
              </div>
              <div className="wa-time">18:00</div>
              <div className="wa-btns">
                <button disabled={answered} onClick={() => setAnswered(true)}>Voy</button>
                <button disabled={answered} onClick={() => setAnswered(true)}>No voy</button>
                <button disabled={answered} onClick={() => setAnswered(true)}>Duda</button>
              </div>
            </div>

            {answered && (
              <>
                <div className="wa-out">
                  Voy
                  <div className="wa-time" style={{ padding: "2px 0 0" }}>18:04 ✓✓</div>
                </div>
                <div className="wa-in" style={{ marginTop: 10 }}>
                  <div className="wa-text">
                    Listo, Marius. Sos el 8º confirmado — faltan 3 para el once.<br /><br />
                    Ver la convocatoria:<br />
                    <span style={{ color: "#027EB5" }}>newcastle.app/e/{ev.id}</span>
                  </div>
                  <div className="wa-time">18:04</div>
                </div>
              </>
            )}
          </div>

          <div className="wa-note">
            <div className="nc-label">Cómo funciona</div>
            <p className="nc-meta" style={{ marginTop: 7 }}>
              {answered
                ? "La respuesta entra directo a la app: el contador de confirmados sube solo y nadie tiene que leer 40 mensajes del grupo. El link abre la app ya logueada, sin contraseña."
                : "Tocá un botón para ver qué pasa. El jugador no instala nada ni abre ningún link: contesta desde la notificación como en cualquier chat."}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ------------------------- NUEVO EVENTO --------------------------- */

function NuevoEvento({ onCreate, onClose }) {
  const [f, setF] = useState({ kind: "Partido", rival: "", date: "", time: "11:00", place: "", kit: "home" });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));
  const ok = f.date.trim() && f.place.trim() && (f.kind === "Entrenamiento" || f.rival.trim());

  return (
    <div className="nc-sheet" onClick={onClose}>
      <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
        <div className="nc-label">Delegado</div>
        <h3 className="nc-display" style={{ fontSize: 21, margin: "5px 0 16px" }}>Nuevo evento</h3>

        <label className="nc-field-l">
          <span className="nc-label">Tipo</span>
          <select value={f.kind} onChange={(e) => set("kind", e.target.value)}>
            <option>Partido</option><option>Entrenamiento</option>
          </select>
        </label>

        {f.kind === "Partido" && (
          <label className="nc-field-l">
            <span className="nc-label">Rival</span>
            <input value={f.rival} onChange={(e) => set("rival", e.target.value)} placeholder="CS Găneasa" />
          </label>
        )}

        <label className="nc-field-l">
          <span className="nc-label">Cuándo</span>
          <input value={f.date} onChange={(e) => set("date", e.target.value)} placeholder="Sábado 5 de septiembre" />
        </label>

        <label className="nc-field-l">
          <span className="nc-label">Hora</span>
          <input value={f.time} onChange={(e) => set("time", e.target.value)} placeholder="11:00" />
        </label>

        <label className="nc-field-l">
          <span className="nc-label">Cancha</span>
          <input value={f.place} onChange={(e) => set("place", e.target.value)} placeholder="Teren Voluntari" />
        </label>

        <label className="nc-field-l">
          <span className="nc-label">Casaca</span>
          <select value={f.kit} onChange={(e) => set("kit", e.target.value)}>
            <option value="home">Roja — local</option>
            <option value="away">Celeste — visitante</option>
          </select>
        </label>

        <button className="nc-btn" style={{ marginTop: 8 }} disabled={!ok}
          onClick={() => { onCreate({ ...f, home: f.kit === "home", meta: "Liga a V-a Ilfov" }); onClose(); }}>
          Publicar y avisar por WhatsApp
        </button>
        <p className="nc-meta" style={{ textAlign: "center", marginTop: 11 }}>
          Se le manda la convocatoria a los {ROSTER.length + 1} jugadores del plantel.
        </p>
      </div>
    </div>
  );
}

/* ---------------------------- ALTA -------------------------------- */

function Alta({ onDone, onSkip }) {
  const [step, setStep] = useState(0);
  const [d, setD] = useState({ name: "", pos: "", foot: "", num: null, slots: [] });
  const set = (k, v) => setD((p) => ({ ...p, [k]: v }));
  const toggle = (s) => setD((p) => ({ ...p, slots: p.slots.includes(s) ? p.slots.filter((x) => x !== s) : [...p.slots, s] }));

  const steps = [
    { q: "¿Cómo te anotamos en la planilla?", hint: "Nombre y apellido, como figura en el documento.",
      ok: d.name.trim().length > 2,
      body: <input className="nc-input" autoFocus placeholder="Tu nombre" value={d.name} onChange={(e) => set("name", e.target.value)} /> },
    { q: "¿En qué puesto jugás?", hint: "Podés cambiarlo después. Sirve para armar la convocatoria.", ok: !!d.pos,
      body: POSITIONS.map((p) => (
        <button key={p.key} className={`nc-opt ${d.pos === p.key ? "on" : ""}`} onClick={() => set("pos", p.key)}>
          {p.label}<span className="nc-num" style={{ fontSize: 11, opacity: .55 }}>{p.key}</span>
        </button>)) },
    { q: "¿Perfil hábil?", ok: !!d.foot,
      body: FEET.map((f) => <button key={f} className={`nc-opt ${d.foot === f ? "on" : ""}`} onClick={() => set("foot", f)}>{f}</button>) },
    { q: "Elegí tu número", hint: "Va estampado en las dos casacas. Los tachados ya están tomados.", ok: d.num !== null,
      body: (
        <div className="nc-numgrid">
          {Array.from({ length: 30 }, (_, i) => i + 1).map((n) => (
            <button key={n} disabled={TAKEN.includes(n)} className={d.num === n ? "on" : ""} onClick={() => set("num", n)}>{n}</button>
          ))}
        </div>) },
    { q: "¿Cuándo podés?", hint: "Marcá todo lo que te sirva. Después te avisamos solo de eso.", ok: d.slots.length > 0,
      body: SLOTS.map((s) => (
        <button key={s} className={`nc-opt ${d.slots.includes(s) ? "on" : ""}`} onClick={() => toggle(s)}>
          {s}{d.slots.includes(s) && <Check size={16} />}
        </button>)) },
  ];
  const cur = steps[step];

  return (
    <div className="nc-app">
      <div className="nc-demo">Demo · datos de muestra</div>
      <div className="nc-step">
        {step === 0 && (
          <div style={{ textAlign: "center", marginBottom: 22 }}>
            <div style={{ display: "flex", justifyContent: "center" }}><Crest size={68} /></div>
            <h1 className="nc-display" style={{ fontSize: 27, margin: "12px 0 0" }}>A.S New Castle</h1>
            <div className="nc-label" style={{ marginTop: 4 }}>Voluntari · Ilfov · Liga a V-a</div>
          </div>
        )}

        <div className="nc-progress">{steps.map((_, i) => <i key={i} className={i <= step ? "on" : ""} />)}</div>

        <div className="nc-label">Paso {step + 1} de {steps.length}</div>
        <h2 className="nc-display nc-q">{cur.q}</h2>
        {cur.hint && <p className="nc-meta" style={{ marginTop: -12, marginBottom: 20 }}>{cur.hint}</p>}

        <div>{cur.body}</div>

        {d.num !== null && step === 3 && (
          <div style={{ display: "flex", justifyContent: "center", gap: 18, marginTop: 26 }}>
            <div style={{ textAlign: "center" }}>
              <Kit n={d.num} kit="home" size="lg" /><div className="nc-label" style={{ marginTop: 8 }}>Titular</div>
            </div>
            <div style={{ textAlign: "center" }}>
              <Kit n={d.num} kit="away" size="lg" /><div className="nc-label" style={{ marginTop: 8 }}>Suplente</div>
            </div>
          </div>
        )}

        <div style={{ flex: 1, minHeight: 24 }} />

        <div style={{ display: "flex", gap: 8 }}>
          {step > 0 && (
            <button className="nc-btn ghost" style={{ width: 54 }} onClick={() => setStep(step - 1)} aria-label="Volver">
              <ChevronLeft size={18} />
            </button>
          )}
          <button className="nc-btn" disabled={!cur.ok} onClick={() => (step === steps.length - 1 ? onDone(d) : setStep(step + 1))}>
            {step === steps.length - 1 ? "Entrar al equipo" : "Seguir"}
          </button>
        </div>

        <button className="nc-skip" onClick={onSkip}>Saltar el alta y ver la app</button>
      </div>
    </div>
  );
}

/* --------------------------- AGENDA ------------------------------- */

function Agenda({ me, rsvp, setRsvp, events, addEvent, isAdmin }) {
  const [wa, setWa] = useState(null);
  const [nuevo, setNuevo] = useState(false);
  const [copiado, setCopiado] = useState(null);

  return (
    <>
      {isAdmin && (
        <button className="nc-btn dark" style={{ marginBottom: 14, display: "flex", alignItems: "center", justifyContent: "center", gap: 8 }}
          onClick={() => setNuevo(true)}>
          <Plus size={17} /> Nuevo evento
        </button>
      )}

      {events.map((ev) => {
        const going = ROSTER.filter((p) => p.rsvp[ev.id] === "in");
        const maybe = ROSTER.filter((p) => p.rsvp[ev.id] === "maybe");
        const out = ROSTER.filter((p) => p.rsvp[ev.id] === "out");
        const sin = ROSTER.length - going.length - maybe.length - out.length;
        const mine = rsvp[ev.id];
        const total = going.length + (mine === "in" ? 1 : 0);
        const isMatch = ev.kind === "Partido";

        return (
          <div key={ev.id} className={`nc-card ${isMatch ? (ev.kit === "away" ? "away-match" : "match") : ""}`}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 10 }}>
              <div>
                <div className="nc-label" style={{ color: isMatch ? (ev.kit === "away" ? "var(--aqua-dk)" : "var(--red)") : "var(--stone)" }}>
                  {isMatch ? (ev.home ? "Partido · Local" : "Partido · Visitante") : "Entrenamiento"}
                </div>
                <h3 className="nc-display" style={{ fontSize: 19, margin: "5px 0 2px" }}>
                  {ev.rival ? `vs ${ev.rival}` : ev.date}
                </h3>
                {ev.rival && <div className="nc-meta">{ev.date}</div>}
              </div>
              <div style={{ textAlign: "right" }}>
                <div className="nc-num" style={{ fontSize: 19, fontWeight: 700 }}>{ev.time}</div>
                <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 6 }}>
                  <Kit n={me.num} kit={ev.kit} size="sm" />
                </div>
              </div>
            </div>

            <div className="nc-meta" style={{ marginTop: 10, display: "flex", gap: 6 }}>
              <MapPin size={13} style={{ marginTop: 3, flexShrink: 0 }} /><span>{ev.place}</span>
            </div>
            <div className="nc-meta" style={{ marginTop: 3 }}>{ev.meta}</div>

            {!isAdmin && (
              <div className="nc-rsvp">
                {[{ k: "in", label: "Voy", Icon: Check }, { k: "maybe", label: "Duda", Icon: HelpCircle }, { k: "out", label: "No voy", Icon: X }].map(({ k, label, Icon }) => (
                  <button key={k} className={`${k} ${mine === k ? "on" : ""}`} onClick={() => setRsvp(ev.id, k)}>
                    <Icon size={13} /> {label}
                  </button>
                ))}
              </div>
            )}

            <div className="nc-count">
              <span className="n">{total}</span>
              <span className="nc-meta">
                confirmados{maybe.length > 0 && ` · ${maybe.length} en duda`}
                {isMatch && total < 11 && ` · faltan ${11 - total} para el once`}
              </span>
            </div>

            <div className="nc-kits">
              {mine === "in" && <Kit n={me.num} kit={ev.kit} size="sm" />}
              {going.map((p) => <Kit key={p.id} n={p.num} kit={ev.kit} size="sm" />)}
              {maybe.map((p) => <Kit key={p.id} n={p.num} kit={ev.kit} size="sm" ghost />)}
            </div>

            {isAdmin ? (
              <div className="nc-admin">
                <div className="nc-label">Convocatoria</div>
                <div className="nc-namelist">
                  {going.length > 0 ? going.map((p) => `${p.num} ${p.name}`).join(" · ") : "Todavía no contestó nadie."}
                </div>
                {(maybe.length > 0 || out.length > 0 || sin > 0) && (
                  <div className="nc-meta" style={{ marginTop: 8 }}>
                    {maybe.length} en duda · {out.length} no van · {sin} sin contestar
                  </div>
                )}
                <div className="nc-admin-actions">
                  <button className="nc-mini" onClick={() => { setCopiado(ev.id); setTimeout(() => setCopiado(null), 1600); }}>
                    <Copy size={13} /> {copiado === ev.id ? "Copiada" : "Copiar lista"}
                  </button>
                  <button className="nc-mini solid" onClick={() => setWa(ev)}>
                    <Bell size={13} /> Recordar a {sin}
                  </button>
                </div>
              </div>
            ) : (
              <button className="nc-mini" style={{ width: "100%", marginTop: 14 }} onClick={() => setWa(ev)}>
                <Smartphone size={13} /> Ver el aviso de WhatsApp
              </button>
            )}
          </div>
        );
      })}

      <p className="nc-meta" style={{ textAlign: "center", padding: "8px 22px" }}>
        La casaca de cada fecha es la que hay que llevar: roja de local, celeste de visitante.
      </p>

      {wa && <WhatsAppSheet ev={wa} onClose={() => setWa(null)} />}
      {nuevo && <NuevoEvento onCreate={addEvent} onClose={() => setNuevo(false)} />}
    </>
  );
}

/* --------------------------- TABLA -------------------------------- */

function Tabla() {
  return (
    <>
      <div className="nc-card">
        <div className="nc-label">Liga a V-a Ilfov · Serie A</div>
        <table className="nc-table" style={{ marginTop: 12 }}>
          <thead>
            <tr><th style={{ width: 26 }}>#</th><th>Equipo</th><th style={{ width: 32 }}>PJ</th><th style={{ width: 36 }}>DG</th><th style={{ width: 34 }}>Pts</th></tr>
          </thead>
          <tbody>
            {TABLE.map((r) => (
              <tr key={r.pos} className={r.us ? "us" : ""}>
                <td className="nc-num" style={{ paddingLeft: 9 }}>{r.pos}</td>
                <td>{r.team}</td>
                <td className="nc-num">{r.pj}</td>
                <td className="nc-num">{r.dg > 0 ? `+${r.dg}` : r.dg}</td>
                <td className="nc-num" style={{ fontWeight: 700 }}>{r.pts}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="nc-meta" style={{ textAlign: "center", padding: "0 22px" }}>
        Posiciones de muestra. En producción se cargan después de cada fecha o se importan del acta de la liga.
      </p>
    </>
  );
}

/* ---------------------------- CHAT -------------------------------- */

function Chat({ me }) {
  const [msgs, setMsgs] = useState(SEED);
  const [draft, setDraft] = useState("");
  const end = useRef(null);
  useEffect(() => { end.current?.scrollIntoView({ behavior: "smooth", block: "end" }); }, [msgs]);

  const send = () => {
    if (!draft.trim()) return;
    setMsgs((m) => [...m, { id: Date.now(), who: me.name.split(" ")[0], num: me.num, text: draft.trim(), at: "ahora", mine: true }]);
    setDraft("");
  };

  return (
    <div style={{ display: "flex", flexDirection: "column", minHeight: "100%" }}>
      <div style={{ flex: 1 }}>
        {msgs.map((m) =>
          m.who === "system"
            ? <div key={m.id} className="nc-sys">{m.text}</div>
            : (
              <div key={m.id} className={`nc-msg ${m.mine ? "mine" : ""}`}>
                <Kit n={m.num} size="sm" />
                <div>
                  <div className="nc-bubble">{m.text}</div>
                  <div className="nc-meta" style={{ fontSize: 11, marginTop: 3, textAlign: m.mine ? "right" : "left" }}>
                    {m.mine ? "vos" : m.who} · {m.at}
                  </div>
                </div>
              </div>
            )
        )}
        <div ref={end} />
      </div>
      <div className="nc-composer">
        <input value={draft} onChange={(e) => setDraft(e.target.value)} onKeyDown={(e) => e.key === "Enter" && send()} placeholder="Escribile al equipo" />
        <button className="nc-icon-btn" onClick={send} aria-label="Enviar"><Send size={17} /></button>
      </div>
    </div>
  );
}

/* ---------------------------- CUOTA ------------------------------- */

function Cuota({ paid, setPaid, isAdmin }) {
  const [sheet, setSheet] = useState(false);
  const [done, setDone] = useState(false);
  const [avisado, setAvisado] = useState(false);
  const deben = ROSTER.filter((p) => !p.paid);
  const alDia = ROSTER.filter((p) => p.paid).length + (paid ? 1 : 0);
  const total = ROSTER.length + 1;
  const cobrado = alDia * 120, objetivo = total * 120;

  const pagar = () => { setDone(true); setTimeout(() => { setPaid(true); setSheet(false); setDone(false); }, 1100); };

  return (
    <>
      {!isAdmin && (
        <div className="nc-card">
          <div className="nc-label">Tu cuota · Agosto 2026</div>
          <div style={{ display: "flex", alignItems: "baseline", gap: 8, margin: "10px 0 0" }}>
            <span className="nc-display" style={{ fontSize: 42, lineHeight: 1 }}>120</span>
            <span className="nc-num nc-meta" style={{ fontSize: 14, fontWeight: 600 }}>RON / mes</span>
          </div>
          <div style={{ marginTop: 12 }}>
            <span className={`nc-pill ${paid ? "ok" : "no"}`}>{paid ? "Al día" : "Vence el 20 de agosto"}</span>
          </div>
          <p className="nc-meta" style={{ marginTop: 14 }}>
            Cubre alquiler de cancha, arbitraje, inscripción a la liga y el equipamiento nuevo. Se debita solo todos los días 1.
          </p>
          {!paid && <button className="nc-btn" style={{ marginTop: 16 }} onClick={() => setSheet(true)}>Pagar 120 RON</button>}
        </div>
      )}

      <div className="nc-card">
        <div className="nc-label">Caja del club · Agosto</div>
        <div style={{ display: "flex", alignItems: "baseline", gap: 8, marginTop: 10 }}>
          <span className="nc-display" style={{ fontSize: 30 }}>{cobrado.toLocaleString("es-AR")}</span>
          <span className="nc-num nc-meta" style={{ fontSize: 13 }}>de {objetivo.toLocaleString("es-AR")} RON</span>
        </div>
        <div className="nc-bar"><i style={{ width: `${(cobrado / objetivo) * 100}%`, background: "var(--red)" }} /></div>
        <div className="nc-meta" style={{ marginTop: 8 }}>{alDia} de {total} jugadores al día</div>

        <div style={{ marginTop: 16 }}>
          <div className="nc-label" style={{ marginBottom: 2 }}>Deben este mes</div>
          {deben.map((p) => (
            <div key={p.id} className="nc-row">
              <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                <Kit n={p.num} size="sm" /><span style={{ fontSize: 14 }}>{p.name}</span>
              </div>
              <button className="nc-btn ghost" style={{ width: "auto", padding: "7px 12px", fontSize: 11 }}>Recordar</button>
            </div>
          ))}
        </div>

        {isAdmin && (
          <div className="nc-admin">
            <div className="nc-label">Delegado</div>
            <div className="nc-meta" style={{ marginTop: 6 }}>
              Faltan {(deben.length * 120).toLocaleString("es-AR")} RON para cerrar el mes.
            </div>
            <div className="nc-admin-actions">
              <button className="nc-mini solid" onClick={() => setAvisado(true)}>
                <Bell size={13} /> {avisado ? `Avisados los ${deben.length}` : `Reclamar a los ${deben.length}`}
              </button>
            </div>
          </div>
        )}
      </div>

      <p className="nc-meta" style={{ textAlign: "center", padding: "0 22px", display: "flex", gap: 6, justifyContent: "center" }}>
        <Shield size={13} style={{ marginTop: 2, flexShrink: 0 }} />
        <span>La plata entra a la cuenta de la asociación, no a una cuenta personal.</span>
      </p>

      {sheet && (
        <div className="nc-sheet" onClick={() => !done && setSheet(false)}>
          <div className="nc-sheet-inner" onClick={(e) => e.stopPropagation()}>
            {done ? (
              <div style={{ textAlign: "center", padding: "26px 0" }}>
                <Check size={40} color="var(--aqua-dk)" />
                <h3 className="nc-display" style={{ fontSize: 21, marginTop: 12 }}>Cuota pagada</h3>
                <p className="nc-meta">Te mandamos el recibo por mail.</p>
              </div>
            ) : (
              <>
                <div style={{ display: "flex", alignItems: "center", gap: 11 }}>
                  <Crest size={34} />
                  <div>
                    <div className="nc-label">Asociația Sportivă New Castle</div>
                    <h3 className="nc-display" style={{ fontSize: 19, margin: "3px 0 0" }}>Cuota de agosto · 120 RON</h3>
                  </div>
                </div>
                <div className="nc-row" style={{ marginTop: 14 }}>
                  <span className="nc-meta">Tarjeta</span><span className="nc-num" style={{ fontSize: 14 }}>•••• 4242</span>
                </div>
                <div className="nc-row">
                  <span className="nc-meta">Se repite</span><span style={{ fontSize: 14 }}>Todos los días 1</span>
                </div>
                <button className="nc-btn" style={{ marginTop: 18 }} onClick={pagar}>Confirmar pago</button>
                <p className="nc-meta" style={{ textAlign: "center", marginTop: 12, display: "flex", gap: 6, justifyContent: "center" }}>
                  <Lock size={12} style={{ marginTop: 2 }} /> Simulación de Stripe — no se cobra nada
                </p>
              </>
            )}
          </div>
        </div>
      )}
    </>
  );
}

/* ---------------------------- PERFIL ------------------------------ */

function Perfil({ me, rsvp, paid }) {
  const asist = Object.values(rsvp).filter((v) => v === "in").length;
  const posLabel = POSITIONS.find((p) => p.key === me.pos)?.label ?? me.pos;

  return (
    <>
      <div className="nc-card">
        <div style={{ display: "flex", gap: 14, alignItems: "center" }}>
          <Kit n={me.num} size="lg" />
          <div>
            <h2 className="nc-display" style={{ fontSize: 21, lineHeight: 1 }}>{me.name}</h2>
            <div className="nc-meta" style={{ marginTop: 5 }}>{posLabel} · perfil {me.foot.toLowerCase()}</div>
            <div style={{ marginTop: 8 }}>
              <span className={`nc-pill ${paid ? "ok" : "no"}`}>{paid ? "Cuota al día" : "Cuota pendiente"}</span>
            </div>
          </div>
        </div>
        <div className="nc-row" style={{ marginTop: 14 }}>
          <span className="nc-meta">Tu equipamiento</span>
          <div style={{ display: "flex", gap: 7 }}>
            <Kit n={me.num} kit="home" size="sm" /><Kit n={me.num} kit="away" size="sm" />
          </div>
        </div>
      </div>

      <div className="nc-card">
        <div className="nc-label">Tu temporada</div>
        <div style={{ display: "flex", gap: 26, marginTop: 12 }}>
          {[["Confirmadas", asist], ["Partidos", 2], ["Goles", 1]].map(([l, v]) => (
            <div key={l}>
              <div className="nc-display" style={{ fontSize: 30, lineHeight: 1 }}>{v}</div>
              <div className="nc-label" style={{ marginTop: 3 }}>{l}</div>
            </div>
          ))}
        </div>
      </div>

      <div className="nc-card">
        <div className="nc-label">Disponibilidad</div>
        <div style={{ marginTop: 6 }}>
          {me.slots.map((s) => (
            <div key={s} className="nc-row"><span style={{ fontSize: 14 }}>{s}</span><Check size={15} color="var(--aqua-dk)" /></div>
          ))}
        </div>
      </div>

      <div className="nc-card">
        <div className="nc-label">Plantel</div>
        <div style={{ marginTop: 6 }}>
          {ROSTER.map((p) => (
            <div key={p.id} className="nc-row">
              <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                <Kit n={p.num} size="sm" /><span style={{ fontSize: 14 }}>{p.name}</span>
              </div>
              <span className="nc-label">{p.pos}</span>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}

/* ----------------------------- APP -------------------------------- */

export default function NewCastleApp() {
  const [me, setMe] = useState(null);
  const [tab, setTab] = useState("agenda");
  const [rsvp, setR] = useState({});
  const [paid, setPaid] = useState(false);
  const [role, setRole] = useState("jugador");
  const [events, setEvents] = useState(BASE_EVENTS);

  const isAdmin = role === "delegado";
  const setRsvp = (id, v) => setR((p) => ({ ...p, [id]: p[id] === v ? undefined : v }));
  const addEvent = (e) => setEvents((prev) => [...prev, { ...e, id: `n${prev.length + 1}` }]);

  const titles = {
    agenda: [isAdmin ? "Convocatorias" : "Próximos", "Agenda"],
    tabla: ["Liga a V-a Ilfov", "Tabla"],
    chat: [`${ROSTER.length} jugadores`, "Vestuario"],
    cuota: ["Agosto 2026", "Cuota"],
    perfil: ["Tu ficha", "Perfil"],
  };
  const tabs = [
    { k: "agenda", label: "Agenda", Icon: CalendarDays },
    { k: "tabla", label: "Tabla", Icon: ListOrdered },
    { k: "chat", label: "Vestuario", Icon: MessageSquare },
    { k: "cuota", label: "Cuota", Icon: Wallet },
    { k: "perfil", label: "Perfil", Icon: User },
  ];

  if (!me) {
    return (
      <div className="nc-root">
        <style>{CSS}</style>
        <Alta onDone={setMe} onSkip={() => setMe(DEMO_ME)} />
      </div>
    );
  }

  return (
    <div className="nc-root">
      <style>{CSS}</style>
      <div className="nc-app">
        <div className="nc-demo">Demo · jugadores, posiciones y pagos son de muestra</div>

        <header className="nc-top nc-pinstripe">
          <Crest size={38} />
          <div>
            <div className="nc-eyebrow">{titles[tab][0]}</div>
            <h1 className="nc-display nc-h1">{titles[tab][1]}</h1>
          </div>
          <div className="nc-role">
            <button className={role === "jugador" ? "on" : ""} onClick={() => setRole("jugador")}>Jugador</button>
            <button className={role === "delegado" ? "on" : ""} onClick={() => setRole("delegado")}>Delegado</button>
          </div>
        </header>

        <main className="nc-body">
          {tab === "agenda" && <Agenda me={me} rsvp={rsvp} setRsvp={setRsvp} events={events} addEvent={addEvent} isAdmin={isAdmin} />}
          {tab === "tabla" && <Tabla />}
          {tab === "chat" && <Chat me={me} />}
          {tab === "cuota" && <Cuota paid={paid} setPaid={setPaid} isAdmin={isAdmin} />}
          {tab === "perfil" && <Perfil me={me} rsvp={rsvp} paid={paid} />}
        </main>

        <nav className="nc-tabs">
          {tabs.map(({ k, label, Icon }) => (
            <button key={k} className={tab === k ? "on" : ""} onClick={() => setTab(k)}>
              <Icon size={18} strokeWidth={2} />{label}
            </button>
          ))}
        </nav>
      </div>
    </div>
  );
}
