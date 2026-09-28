# Fase 0 — Informe de pruebas de concepto

> Estado: **parcial**. La sección 5 del `CLAUDE.md` raíz asigna la Fase 0 al propietario del
> producto (correr los 20 nombres reales y medir recall es una tarea suya, no de Claude Code).
> Este informe documenta lo que **ya se verificó con datos reales** en sesiones de desarrollo
> anteriores más las dos decisiones tomadas hoy (2026-09-28), y deja explícito qué falta para
> considerar la Fase 0 completa.

---

## 1. Recall y latencia de indexación (20 nombres — parcial: 2/20)

La campaña formal de 20 nombres judiciales reales sigue sin correr. Lo verificado hasta hoy con
datos reales, no sintéticos:

| Caso | Medio donde apareció | Resultado |
|------|----------------------|-----------|
| Cristian Omar Umaña Interiano | lanoticiasv.com | Encontrado como primer resultado; extracción correcta (`rol: condenado`, `delitos: ["homicidio simple"]`, `confianza: 0.95`), match generado (`score: 98.32`). Reveló que la lista de 6 medios originales era angosta — `lanoticiasv.com` no estaba y hubo que agregarlo. |
| Christopher Yuvini Carrillo | lanoticiasv.com, diario1.com | 2 matches correctos. Usado como caso de referencia para verificar parámetros de Brave (`freshness`, `spellcheck`, comillas — ver sección 3). |

**Conclusión parcial:** con solo 2 casos no se puede medir recall real (¿qué porcentaje de casos
reales aparece?). El hallazgo de `lanoticiasv.com` sugiere que la cobertura de 7 medios puede
seguir siendo angosta — la campaña de 20 nombres es la única forma de confirmarlo.

**Pendiente:** el propietario aporta 20 nombres de casos judiciales recientes y conocidos (o
autoriza expresamente que se busquen candidatos y se gaste la cuota de Brave necesaria — a la
fecha de este informe no se ha pedido ni gastado cuota adicional para esto).

---

## 2. Selectores de fecha por medio (30 artículos, 7 medios)

**Decisión del usuario (2026-09-28): manual.** No se van a construir selectores de fecha
específicos por medio (ej. un XPath/CSS distinto para cada uno de los 7 dominios). Este ítem de
la Fase 0 queda **cerrado con esta decisión**, no pendiente.

Lo que ya existe y se mantiene como solución definitiva (`App\Jobs\FetchArticleJob`):
1. Metadatos estándar (`article:published_time`, JSON-LD, `<time>`).
2. Si ninguno aparece, un fallback genérico (regex sobre el HTML).
3. Verificación de la fecha real del artículo contra la ventana configurada (`ARTICLE_WINDOW_DAYS`)
   como segunda capa — independiente de lo que Brave haya reportado en la búsqueda
   (`App\Services\Search\VentanaTemporal`, sección 3.7/3.4 del `CLAUDE.md` raíz).

Si en el futuro se detecta que este mecanismo genérico falla sistemáticamente en algún medio en
particular, se evalúa un selector puntual para ese caso — no una batería completa por adelantado.

---

## 3. Estructura de la query — comillas de frase exacta (decisión + verificación real, 2026-09-28)

### Hallazgo (documentado en sesiones anteriores, sin acción hasta hoy)
Dos señales independientes indicaban que envolver los términos en comillas (`"Juan Perez"`) para
forzar coincidencia de frase exacta era más riesgo que beneficio cuando se combina con `site:`:

1. **Verificado con la API real (2026-09-24):** `"Cristian Umaña" site:laprensagrafica.com` → **0
   resultados**. La misma frase sin comillas → **20 resultados**, con contenido real existente. Un
   falso negativo silencioso: la persona sí tenía cobertura, pero las comillas la escondían.
2. **Verificado con la API real (2026-09-25):** en la respuesta de Brave, `search_operators.cleaned_query`
   venía **sin las comillas** que sí se habían enviado — señal de que Brave ya las trata de forma
   inconsistente por su cuenta antes de que este hallazgo se resolviera.

### Decisión
Se quitaron las comillas de `App\Services\Search\LimiteDeQuery::armar()`. La query pasa de
`("Juan Perez" OR "J. Perez") (site:a.com)` a `(Juan Perez OR J. Perez) (site:a.com)`. No cambia
el conteo de palabras (los límites de 600 caracteres / 75 palabras siguen igual), solo reduce el
riesgo de coincidencia exacta fallida. Cambio en `RunSubjectSearchJob` y `RunTagSearchJob` (ambos
usan `LimiteDeQuery`), con sus tests actualizados. **282 tests backend pasan.**

### Verificación real tras el cambio (1 request, 2026-09-28)
```
q = "(Christopher Yuvini Carrillo) (site:diario1.com OR site:lanoticiasv.com)"
freshness = 60 días, spellcheck=false, search_lang=es
```
Respuesta real de Brave:
- `query.original` = exactamente lo enviado (ninguna alteración).
- `query.search_operators.applied: true`, ambos `site:` reconocidos.
- **20 resultados**, con contenido real vigente dentro de la ventana.

Confirma que, sin comillas, Brave respeta la query tal cual se envía y sigue devolviendo
resultados reales — el mecanismo funciona como se esperaba.

**Pendiente:** repetir esta comparación (con y sin comillas) dentro de la campaña formal de 20
nombres para cuantificar cuántos casos reales se estaban perdiendo por este motivo antes del
2026-09-28 — hoy solo hay 1 caso puntual reproducido.

---

## 4. Precisión del prompt de extracción (30 artículos) — sin empezar

No se ha hecho todavía una validación formal de precisión de roles/nombres contra revisión manual
sobre una muestra de 30 artículos. Lo que existe es la validación end-to-end de los 2 casos reales
de la sección 1 (ambos con extracción correcta), que no sustituye una muestra de 30.

**Pendiente:** definir junto con el usuario la muestra de 30 artículos y el criterio de revisión
manual antes de correrla (gasta cuota de Anthropic).

---

## 5. Hallazgos de cobertura ya documentados (sin acción nueva en este informe)

- **laprensagrafica.com prácticamente no aporta resultados recientes en el índice de Brave**
  (diagnóstico completo en el `CLAUDE.md` raíz, duodécimo bloque). Aceptado por el usuario como
  hueco de cobertura conocido (opción 1: documentar, no suplantar el user-agent del crawler).
- La lista de 7 medios puede seguir siendo angosta — la campaña de 20 nombres (sección 1) es la
  forma de confirmarlo o descartarlo con datos, no una suposición.

---

## Resumen de lo que falta para cerrar la Fase 0

| Ítem | Estado |
|------|--------|
| 1. Recall/latencia con 20 nombres reales | **Pendiente** — necesita los 20 nombres del propietario o su autorización para buscarlos y gastar cuota |
| 2. Selectores de fecha por medio | **Cerrado** — decisión: manual, sin selectores por medio |
| 3. Estructura de la query (comillas) | **Cerrado** — comillas quitadas, verificado con datos reales |
| 4. Precisión del prompt de extracción (30 artículos) | **Pendiente** — sin empezar |
| 5. Cobertura de medios (LPG y posibles medios faltantes) | **Documentado**, sin acción nueva — depende del ítem 1 |
