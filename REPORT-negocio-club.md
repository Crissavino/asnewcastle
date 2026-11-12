# Análisis de negocio — A.S New Castle

**Fecha:** 12.11.2026 · **Fuente:** producción, comando `app:analisis-negocio` (solo lectura)
**Período medido:** septiembre, octubre y noviembre 2026 (noviembre, al día 12)

---

## 0. El titular

**El club cobra cada mes menos cuotas, y en noviembre quedó parado justo encima de su punto
de equilibrio.**

| | Sep | Oct | Nov (al 12) |
|---|---:|---:|---:|
| Cuotas cobradas | **17** | **13** | **11** |
| Cuotas impagas | 5 | 11 | 9 |
| Tasa de cobro | 77,5% | 53,5% | 54,1% |
| Cobrado | 4.660 RON | 3.800 RON | 3.180 RON |
| Gastado | 3.639 RON | 2.968 RON | 2.575 RON |
| **Resultado del mes** | **+1.021** | **+832** | **+605** |

**El punto de equilibrio son 11,4 cuotas cobradas por mes** (gasto promedio 3.303 RON ÷ 290
RON de cuota efectiva promedio). Noviembre va 11.

El club sigue en azul, pero el azul se achicó 40% en dos meses y la línea de flotación está
tocando el casco. El mes todavía no cerró y pueden entrar cuotas; la dirección, igual, ya está
marcada.

---

## 1. Configuración actual

- Cuota de lista: **300 RON/mes**
- Descuento por débito automático: **20 RON** → el suscripto paga 280 (6,7% off)
- Moneda: RON · Comisión de plataforma cobrada: **0**

**Padrón:** 37 filas históricas → **28 activos**, 8 dados de baja, 1 oculto.
De los 29 sin baja: 24 jugadores, 5 managers. **21 pagan cuota normal, 8 son becados.**

---

## 2. Dónde se va la plata

Gasto histórico total: **9.482 RON**

| Categoría | Cargas | Monto | % |
|---|---:|---:|---:|
| Cancha | 11 | 5.442 RON | 57,4% |
| Liga | 2 | 2.130 RON | 22,5% |
| Árbitro | 2 | 1.660 RON | 17,5% |
| Otros | 1 | 250 RON | 2,6% |

**La cancha es el 57% del gasto y es el único costo verdaderamente recurrente.** Liga y
árbitro son pagos en bloque.

### ⚠️ El gasto registrado no es el gasto real

Se jugaron **9 partidos** y hay **2 cargas de árbitro**. Se hicieron 29 eventos entre
partidos y entrenos, y hay **11 cargas de cancha**.

O falta cargar gastos, o alguien los está pagando de su bolsillo y no entran al sistema.
En cualquiera de los dos casos **el superávit de 2.158 RON que muestra la caja es optimista**,
y todos los márgenes de §0 son techos, no pisos.

Esto es lo primero que arreglaría, antes que cualquier otra cosa de este informe: sin el
gasto completo, no hay análisis que valga.

---

## 3. La morosidad es el problema central

**Deuda abierta: 7.350 RON.** Para dimensionarla:

- Es **3,4 veces el saldo de caja** (2.158 RON)
- Es el **63% de todo lo que el club cobró en su historia** (11.640 RON)
- Es **más de dos meses completos de gasto**

Y crece: 5 morosos en septiembre, 11 en octubre, 9 en noviembre.

**Lo más grave:** los 1.350 RON de septiembre **siguen abiertos dos meses y medio después.**
La deuda vieja no se recupera. A efectos prácticos ya no es deuda, es pérdida — solo que
todavía figura como activo en la caja y hace ver al club mejor de lo que está.

---

## 4. El débito automático: la palanca comprada y sin usar

| | |
|---|---|
| Suscripciones activas | **4** |
| En mora (`past_due`) | 2 |
| Canceladas | 1 |
| **Adopción sobre los 21 que pagan** | **19%** |
| **Deserción** (de 7 que lo activaron, 3 se cayeron) | **43%** |

Hay un dato que conviene leer junto al anterior: **Mollie procesó 29 pagos por 8.360 RON
—el 72% de todo el dinero del club— pero solo 4 son débito automático.** Casi todo lo que
entra online entra como pago suelto, uno por uno, cada mes.

Dicho de otro modo: **la gente ya paga online sin problema. Lo que no hace es automatizarlo.**
Eso es una noticia buenísima, porque la barrera no es tecnológica ni de confianza — es de
incentivo. Y el incentivo lo fijás vos.

### El descuento está mal calibrado

Hoy el descuento es de **20 RON sobre 300 (6,7%)**, contra una morosidad del **46%**.

La cuenta es brutal: cada moroso que pasa a débito automático vale **300 RON/mes recuperados**
contra un descuento de, digamos, 50 RON. **Ratio 6 a 1.** Mientras el descuento convierta
aunque sea 1 de cada 6 morosos, ya se pagó.

A 20 RON no está comprando nada: es demasiado chico para cambiarle la conducta a nadie.

**Recomendación concreta: subir el descuento a 40-50 RON y comunicarlo como lo que es** —
"pagás 250 en vez de 300 si lo ponés en automático". Costo máximo con 15 suscriptos: 750
RON/mes. Beneficio si convierte a la mitad de los morosos: ~1.500 RON/mes de deuda que
nunca nace.

Es, por lejos, la decisión de mayor retorno disponible hoy.

---

## 5. Los becados: 28% del padrón

8 de 29 miembros son becados. Son **2.400 RON/mes de ingreso al que el club renuncia** —
casi tres cuartas partes del gasto mensual.

Becar no está mal; retener al que no puede pagar sin bajarle la cuota a todos es
exactamente para lo que sirve. Pero hay un detalle técnico que vale la pena mirar:

**las cuotas becadas tienen monto y figuran como `waived`, o sea se generaron primero como
pendientes y se becaron después.** La beca se está aplicando de forma *reactiva*, sobre una
deuda que ya existía.

Si eso es decisión tomada de antemano, perfecto. Si en algún caso es "no me paga, lo beco
para limpiar la lista", entonces **parte de la morosidad real está entrando al sistema
disfrazada de beca** y los números de §0 y §3 son mejores que la realidad.

Es la única pregunta de este informe que no puedo responder desde los datos. La sabés vos.

---

## 6. Señales de enganche (el leading indicator)

### El vestuario está muerto como chat

543 mensajes, de los cuales **527 son del sistema**. Quedan 16 humanos en tres meses.

| | Sep | Oct | Nov |
|---|---:|---:|---:|
| Autores humanos distintos | 7 | 3 | **0** |

El chat no desplazó a WhatsApp, y la caída es monótona. Hoy el vestuario es un tablón de
anuncios automáticos.

### La asistencia a entrenos es floja

- **Partidos:** 156 "voy" vs 62 "no voy" → **69%**
- **Entrenos:** 189 "voy" vs 170 "no voy" → **49,5%**

Medio plantel no va a entrenar, y la cancha —que es el 57% del gasto— se paga igual.

### El equipo pierde

9 partidos: **2 ganados, 3 empatados, 4 perdidos.** GF 22 – GC 28.

### Las tres cosas son la misma cosa

Cuotas 17→13→11. Autores en el vestuario 7→3→0. Récord negativo. Entrenos al 49%.

**Eso no son cuatro problemas: es uno solo, y es de enganche, no de plata.** La gente se está
despegando del club, y la morosidad es el síntoma que se ve en la caja porque es el último
en aparecer. Nadie deja de pagar primero y se desengancha después; es al revés.

Por eso ninguna medida puramente financiera —apretar con la deuda, subir la cuota— va a
funcionar sola. Pueden incluso acelerar la salida.

Un dato al margen que vale la pena registrar: **el 100% de las respuestas de asistencia
entran por la app, cero por WhatsApp.** Las push nativas reemplazaron a WhatsApp por
completo para el uso operativo. Eso funcionó.

---

## 7. Qué haría, en este orden

1. **Cargar todos los gastos.** Sin eso no sabés si el club gana o pierde (§2). Es gratis y
   es condición para todo lo demás.
2. **Subir el descuento por débito a 40-50 RON.** La mejor relación costo/beneficio
   disponible, y ya está construido y pago (§4).
3. **Cerrar la deuda de septiembre, de una forma u otra.** Cobrarla o becarla
   explícitamente, pero sacarla de "pendiente". Una deuda de dos meses y medio no se cobra y
   mientras tanto infla el activo (§3).
4. **Atacar el enganche, no la caja.** El orden causal va de la cancha a la cuota, no al
   revés (§6). Menos entrenos y mejor asistidos cuesta menos y engancha más que más entrenos
   a media máquina.
5. **Revisar el régimen de becas.** 28% es alto; lo que importa es saber cuánto de eso es
   decisión y cuánto es morosidad renombrada (§5).

---

## 8. Nota sobre el modelo de datos

Dos huecos que limitan este tipo de análisis, en orden:

1. **No se puede registrar un ingreso que no sea una cuota.** Sponsor, rifa, venta de
   indumentaria, o un aporte tuyo para tapar un mes: no tienen dónde entrar. Mientras siga
   así, "la caja" no es la caja, y un aporte tuyo es indistinguible de un club sano.
2. **No hay período contable ni saldo arrastrado.** La caja es `cobrado − gastado` desde
   siempre. No se puede responder "¿cómo nos fue en octubre?" sin salir a la base.

Ninguno de los dos es urgente comparado con §7.1, pero el primero es el que convierte este
informe en algo que se puede repetir solo todos los meses.
