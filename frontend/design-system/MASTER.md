# Design System Master File — VERA

> **LOGIC:** Al construir una pantalla especifica, revisar primero `design-system/pages/[pantalla].md`.
> Si ese archivo existe, sus reglas **sobrescriben** este Master. Si no, seguir las reglas de abajo.

Generado con `ui-ux-pro-max` (2026-09-24) y **corregido a mano** para reflejar la decision real del
proyecto: paleta/tipografia inspiradas en Paper Dashboard 2 de Creative Tim
(`paper-dashboard-master/` en la raiz del repo — **solo referencia visual**, cero Bootstrap/jQuery
en el resultado), traducidas a Tailwind CSS v4 + shadcn/ui + React 19 (stack cerrado, seccion 2 del
`CLAUDE.md` raiz). La corrida automatica del skill sugirio una paleta azul/Fira Code generica que
**no** se usa — se documenta la sustitucion aqui para que quede claro por que este archivo no
coincide con un `--design-system` corrido desde cero.

---

**Proyecto:** VERA — SaaS multi-tenant de adverse media screening / AML (El Salvador)
**Categoria:** Panel de administracion interno (B2B, uso profesional diario) — no un producto de
consumo ni una landing de marketing.
**Idioma de interfaz:** Español (es-SV).

---

## Paleta de colores

Tokens en convencion shadcn/ui (`--background`, `--foreground`, etc.), mapeados con Tailwind v4
`@theme`. Traducidos desde `paper-dashboard-master/assets/scss/paper-dashboard/_variables.scss`,
ajustados donde hacia falta para cumplir contraste WCAG AA (4.5:1 texto normal).

| Token shadcn | Hex (light) | Origen / uso |
|---|---|---|
| `--background` | `#F4F3EF` | `$default-body-bg` de Paper Dashboard — fondo calido, no blanco puro |
| `--foreground` | `#2C2C2C` | `$black-color` — texto principal |
| `--card` | `#FFFFFF` | tarjetas sobre el fondo calido |
| `--card-foreground` | `#2C2C2C` | |
| `--primary` | `#2FA9AC` | `$primary-color` (#51cbce) oscurecido ~15% para pasar 4.5:1 sobre blanco |
| `--primary-foreground` | `#FFFFFF` | |
| `--secondary` | `#3AA6C2` | `$info-color` (#51bcda) oscurecido, uso: enlaces secundarios, info |
| `--secondary-foreground` | `#FFFFFF` | |
| `--muted` | `#E9E7E0` | fondos de fila alterna en tablas, inputs deshabilitados |
| `--muted-foreground` | `#66615B` | `$font-color` — texto secundario |
| `--accent` | `#F1EAE0` | `$medium-pale-bg` — hover sutil sobre tarjetas/filas |
| `--destructive` | `#D9532E` | `$danger-color` (#ef8157) oscurecido para contraste |
| `--destructive-foreground` | `#FFFFFF` | |
| `--border` | `#DDDDDD` | `$medium-gray` |
| `--input` | `#DDDDDD` | |
| `--ring` | `#2FA9AC` | mismo tono que `--primary` |
| `--sidebar` | `#1E1F26` | oscuro solido (NO el fondo con imagen+overlay del template original — se descarta por decorativo, sección "Anti-patrones") |
| `--sidebar-foreground` | `#E7E7E5` | |
| `--sidebar-primary` | `#2FA9AC` | item de sidebar activo |

### Colores semánticos de dominio (específicos de VERA — no genéricos)

Estos dos mapeos se usan en **toda** la app (tarjetas de subject, tablas de matches, badges) — no
improvisar variantes por pantalla.

**`subjects.nivel_riesgo`** (sección 3.3 del CLAUDE.md raíz):

| Valor | Token | Hex | Razonamiento |
|---|---|---|---|
| `bajo` | `--success` | `#4FAF7A` (`$success-color` #6bd098 oscurecido) | riesgo controlado |
| `medio` | `--warning` | `#D89A2E` (`$warning-color` #fbc658 oscurecido) | atención moderada |
| `alto` | `--destructive` | `#D9532E` | requiere atención inmediata |

**`matches.estado`** (sección 3.3/1 — el signficado NO es intuitivo, léase con cuidado):

| Valor | Color | Por qué (no es lo que parece a primera vista) |
|---|---|---|
| `pendiente` | `--warning` (ámbar) | esperando revisión humana — nunca gris/neutro, necesita llamar la atención del analista |
| `confirmado` | `--destructive` (rojo) | **es la mala noticia**: se confirmó que la persona sí tiene el hallazgo de riesgo — no usar verde/éxito aquí, sería literalmente al revés |
| `falso_positivo` | `--success` (verde) | resuelto, sin riesgo real — es la resolución "buena" |
| `homonimo` | `--muted-foreground` (gris neutro) | resuelto, sin riesgo real, pero no es exactamente "éxito" — es neutral |

> **Anti-patrón específico de este proyecto:** nunca pintar `confirmado` en verde solo porque
> "confirmado" suena a positivo en otros dominios (ej. "pago confirmado"). Aquí confirma un
> **riesgo**, no una operación exitosa.

**`search_results.estado`** (sección 3.7, flujo bajo demanda — nuevo token `--gap`):

| Valor | Token | Por qué |
|---|---|---|
| `nuevo` | `--muted` | sin acción tomada todavía, neutro |
| `procesando` | `--secondary` | job en curso, es información transitoria |
| `extraido` | `--success` | se pudo leer y procesar automático |
| `sin_menciones` | `--muted` | leído, sin hallazgos — no es ni bueno ni malo |
| `gap` | `--gap` (ámbar-marrón `#8A6D3B` claro / `#C99A53` oscuro) | el fetch automático falló — **no es un error del sistema**, es un estado válido que requiere acción humana (reintentar o captura manual). Deliberadamente distinto de `--warning` (que ya significa "coincidencia pendiente de revisar") para no confundir un GAP de scraping con una coincidencia de riesgo sin resolver |
| `descartado` | `--muted` con opacidad reducida | marcado irrelevante por el usuario, tarjeta atenuada |

---

## Tipografía

Fiel a la referencia (Paper Dashboard 2 usa Montserrat en todo el template):

- **Encabezados y cuerpo:** Montserrat (400, 500, 600, 700)
- **Datos tabulares/números** (montos, fechas, IDs): `font-variant-numeric: tabular-nums` sobre
  Montserrat — no se agrega una fuente monoespaciada aparte, evitar sobrecargar el sistema
  tipográfico de un panel interno.

```css
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');
```

Escala tipográfica (Tailwind default, sin tokens custom): `text-xs` (labels/badges) · `text-sm`
(cuerpo de tabla, 14px) · `text-base` (cuerpo general, 16px — mínimo en formularios) · `text-lg`/
`text-xl` (subtítulos de tarjeta) · `text-2xl`/`text-3xl` (títulos de página).

---

## Espaciado y sombras

(Sin cambios respecto a la corrida automática — son defaults razonables, no específicos de un
dominio.)

| Token | Valor | Uso |
|---|---|---|
| `--space-xs` | `4px` | gaps ajustados |
| `--space-sm` | `8px` | gaps de íconos, espaciado inline |
| `--space-md` | `16px` | padding estándar |
| `--space-lg` | `24px` | padding de sección |
| `--space-xl` | `32px` | gaps grandes |

| Nivel | Valor | Uso |
|---|---|---|
| `shadow-sm` | `0 1px 2px rgba(0,0,0,.05)` | elevación sutil |
| `shadow-md` | `0 4px 6px rgba(0,0,0,.1)` | tarjetas |
| `shadow-lg` | `0 10px 15px rgba(0,0,0,.1)` | modales, dropdowns |

---

## Patrón de layout: Sidebar Admin Shell

Se descarta el patrón "Real-Time/Operations Landing" que sugirió la corrida automática (es una
estructura de landing de marketing — hero, CTA, métricas — no aplica a un panel interno). El
patrón real es el que ya trae Paper Dashboard 2 como referencia estructural (no visual):

- **Sidebar fijo** a la izquierda (`--sidebar`, oscuro), ancho ~260px desktop, colapsable a íconos
  en tablet, oculto tras un botón hamburguesa en móvil (< 768px).
- **Top bar** con: breadcrumb/título de la página actual, menú de usuario (nombre, rol, logout) a
  la derecha.
- **Área de contenido**: fondo `--background`, padding `--space-lg`, `max-width` contenido en
  desktop grande (no estirar tablas al 100% en pantallas ultra anchas).
- Sin analytics ni gráficos de bienvenida en el home — el home es directamente la pantalla de
  consulta puntual o el listado de subjects (es una herramienta de trabajo diario, no un dashboard
  de vanidad).

Estilo general: **Data-Dense Dashboard** (de la corrida automática, sí aplica) — tablas y tarjetas
con padding moderado (no denso al extremo, esto es cumplimiento AML, la legibilidad importa más
que caber el máximo de datos posible), estados de carga con skeleton (no spinners genéricos para
listas), acciones primarias claras por pantalla.

---

## Especificaciones de componentes (shadcn/ui + Tailwind, NO CSS a mano)

Se usan los componentes de shadcn/ui tal cual (`Button`, `Card`, `Input`, `Dialog`, `Badge`,
`Table`) configurados con los tokens de arriba en `tailwind.config`/`globals.css` — no se escriben
clases `.btn-primary` custom como sugería la corrida automática (eso es CSS a mano, contradice usar
shadcn/ui).

- **Badge de `estado`/`nivel_riesgo`:** `<Badge variant="...">` mapeado a los colores semánticos de
  arriba — nunca texto sin badge para estos valores (regla de accesibilidad: no solo color, el
  texto del estado siempre visible dentro del badge, no solo un punto de color).
- **Botón primario:** uno solo por pantalla (ej. "Buscar" en consulta puntual, "Resolver" en el
  panel de una coincidencia) — el resto son `variant="outline"`/`variant="ghost"`.
- **Tablas** (subjects, matches): `sticky` header, fila completa clickeable donde tenga sentido
  (ver detalle), paginación con los metadatos que ya devuelve Laravel (`paginate()`).

---

## Anti-patrones (no usar)

- ❌ Fondo de sidebar con imagen + overlay de color (el template original lo trae; se descarta por
  decorativo — sidebar sólido oscuro en su lugar).
- ❌ Pintar `confirmado` en verde (ver sección de colores semánticos arriba).
- ❌ Emojis como íconos — usar Lucide (ya es dependencia típica de shadcn/ui).
- ❌ Gráficos/KPIs de bienvenida sin dato real detrás — no inventar métricas de "vanidad" en el
  home solo por parecer un dashboard.
- ❌ Texto gris sobre gris, contraste bajo — mínimo 4.5:1 en texto normal.
- ❌ Transiciones instantáneas (0ms) o mayores a 300ms en micro-interacciones.

## Checklist antes de dar una pantalla por terminada

- [ ] Badge de `estado`/`nivel_riesgo` usa el color semántico correcto de esta tabla (no gris/verde
      por default)
- [ ] Un solo botón primario visible por pantalla
- [ ] Contraste de texto ≥ 4.5:1 (verificado, no asumido)
- [ ] Responsive probado en 375px / 768px / 1024px
- [ ] Estados de carga (skeleton) y vacío (empty state) cubiertos, no solo el caso feliz
- [ ] Foco de teclado visible en todos los elementos interactivos
- [ ] Sin scroll horizontal en móvil
