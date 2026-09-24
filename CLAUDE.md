# VERA — Verificación de Exposición a Riesgo Adverso

Plataforma SaaS multi-tenant de *adverse media screening* y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador), con expansión prevista a Guatemala, Honduras y Costa Rica.

Este archivo es la fuente de verdad para Claude Code. Léelo completo antes de cualquier tarea. No cambies stack, librerías ni decisiones de arquitectura sin confirmación explícita del propietario.

---

## Estatus de sesión

**Última actualización:** 2026-09-24 (séptimo bloque — PR frontend de la sección 3.7 implementado y verificado end-to-end)

### En qué estábamos
**PR frontend del flujo bajo demanda implementado y verificado con datos reales (rama `feature/resultados-bajo-demanda`, sin comitear todavía junto con el backend).** Plan presentado y confirmado por el usuario. Verificado con `webapp-testing` (Playwright real, no solo build/lint): consulta puntual → tarjetas "Nuevo" → "Sacar información de noticia" → GAP real (404 real de `elsalvador.com`) → captura manual con PDF real → tarjeta "Extraído" con match "Confirmado" → filtro por estado, todo con datos reales contra Brave/backend, sujeto real "Christopher Yuvini Carrillo".

Lo construido:
- **Tipos** (`frontend/src/types/api.ts`): `EstadoSearchResult`, `GapMotivo`, `OrigenMention`, interfaz `SearchResult`; `Mention`/`MentionMatch` actualizados a los campos nullable/nuevos de la sección 3.7.
- **`features/resultados/useSearchResults.ts`** (nuevo): `useSearchResults` (poll de 3s mientras haya algo `procesando` o la búsqueda se acabe de encolar), `useExtraer`, `useDescartar`, `useCapturaManual` (multipart `FormData`, ya soportado por `lib/api.ts` sin cambios).
- **`ResultadoCard.tsx`** (nuevo): tarjeta por `search_result` con título/medio/fecha/snippet, badge de estado, acciones según estado, y las menciones+match anidados (reutiliza `useProponer`/`useResolver` ya existentes).
- **`CapturaManualDialog.tsx`** (nuevo): formulario completo con delitos como chips de texto libre y subida de PDF obligatoria.
- **`FiltroEstado.tsx`** (nuevo): filtro Todos/Nuevos/GAP/Extraídos/Descartados con `Tabs` de shadcn, client-side.
- **`EstadoSearchResultBadge.tsx`/`GapMotivoBadge.tsx`** (nuevos) + token semántico `--gap` en `index.css` (light+dark) y su tabla en `design-system/MASTER.md`.
- **`$subjectId.tsx`** reescrito: quita el bloque viejo "Coincidencias propuestas" + `MatchCard` standalone, usa `ResultadoCard`/`FiltroEstado`/`useSearchResults`. El botón "Consulta puntual" se mantiene igual (mismo endpoint viejo `POST /buscar`, que ya internamente puebla `search_results` desde el PR de backend).
- **`features/coincidencias/useMatches.ts`**: se quitó el query `useMatches` (lista plana), quedó muerto tras el cambio — solo se dejan `useProponer`/`useResolver`, invalidando ahora `['subjects', id, 'resultados']`.
- Build (`tsc -b && vite build`) y `npm run lint` (oxlint) limpios, sin warnings nuevos.

**Bug real encontrado y corregido durante la verificación (no era de este código, era de despliegue):** el worker de Horizon del contenedor llevaba ~4-5 horas corriendo con las clases viejas de `RunSubjectSearchJob`/`FetchArticleJob` en memoria (de antes del PR de backend) — el gotcha ya documentado en este archivo ("Horizon carga las clases una sola vez al arrancar"). Síntoma real: `search_runs` sí se creaba con resultados de Brave, pero `search_results` quedaba en 0 y en su lugar se veían `FetchArticleJob` viejos fallando en la cola (confirmado inspeccionando el payload serializado del job fallido: traía la firma vieja `string $url`, no `int $searchResultId`). Solución: `docker exec vera_api php artisan horizon:terminate`. **Refuerza el patrón ya documentado — cualquier sesión que retome un PR de backend con los contenedores ya corriendo desde antes debe correr esto antes de probar.**

**Hallazgo de calidad de datos, fuera de alcance de este PR:** algunos `snippet`/`titulo` guardados desde Brave pierden tildes/eñes (ej. "años" → "aos", "Selección" → "Seleccin") en páginas de `historico.elsalvador.com`/`elsalvador.com`. No se tocó — es un problema de datos (probablemente encoding de las páginas fuente o del lado de Brave), no de la UI ni del `BraveSearchAdapter` (que solo hace `strip_tags`). Anotado en "Pendiente".

<details><summary>Sesión anterior (sexto bloque — PR backend de la sección 3.7)</summary>

**PR backend del flujo bajo demanda implementado y verificado (rama `feature/resultados-bajo-demanda`, sin mergear/comitear todavía).** Plan presentado y confirmado por el usuario (con el ajuste de que el disco de evidencia manual es configurable, no fijo a R2 — ver abajo). **101 tests pasan** (79 → 101, +22 nuevos).

Lo construido:
- **5 migraciones nuevas**: `search_results` (tabla completa de la sección 3.7), y 4 alteraciones a `mentions`/`matches` que el plan original no detallaba pero hicieron falta para que la captura manual funcionara de verdad: `mentions.article_id` nullable (una mention manual no tiene Article, el fetch falló), `mentions.search_result_id` (enlace confiable mention→resultado, se llena siempre desde ahora), `mentions.origen`/`creado_por`, `mentions.fecha_hecho`/`resumen` (campos del formulario de captura manual — no hay `Extraction` detrás para guardarlos ahí), y `mentions.confianza`/`matches.score_meilisearch` nullable (no hay puntaje de IA ni de Meilisearch en un registro manual — sección 3.5: "nunca inventar" aplica igual aquí).
- **Enums nuevos**: `EstadoSearchResult`, `GapMotivo`, `OrigenMention`.
- **`SourceAdapterInterface` cambió de contrato**: antes devolvía solo `urls`, ahora devuelve `resultados` con `url`/`titulo`/`descripcion`/`medio`/`fecha` (necesario para listar sin descargar). `BraveSearchAdapter` y `GoogleCseAdapter` (inactivo pero se mantuvo consistente) actualizados. Campos reales de Brave confirmados contra la API real: `title`, `description` (trae HTML con `<strong>`, se limpia con `strip_tags`), `meta_url.hostname`, `page_age`.
- **`RunSubjectSearchJob`**: ya no encola `FetchArticleJob` — crea un `SearchResult` por resultado (`firstOrCreate` por `subject_id`+`url_hash`, para no duplicar tarjetas ni resetear el estado de un resultado que el analista ya procesó si Brave lo vuelve a encontrar otro día).
- **`FetchArticleJob`**: firma cambió de `string $url` a `int $searchResultId`. Reescrito para nunca lanzar excepción en los casos esperados — captura `RequestException`/`ConnectionException` y marca GAP con su motivo (`http_403`, `http_error`, `timeout`, `no_html` por Content-Type, `sin_contenido`, `fuera_de_ventana`) en vez de fallar el job. **Bug real propio encontrado por TDD:** el primer chequeo de "contenido vacío" usaba `strip_tags()` solo, que no borra el contenido de `<script>` — una página pura de `<script>` pasaba como "con contenido". Corregido igual que ya lo hacía `ExtractEntitiesJob::textoLimpio()`. También maneja reutilizar un `Article` ya existente (de otro subject o de una búsqueda anterior): si ya está `completado`, no vuelve a pagar Anthropic, solo redespacha `MatchMentionsJob` por cada mention ya extraída (idempotente por el unique de `matches`).
- **`ExtractEntitiesJob`**: acepta `?int $searchResultId` opcional; al terminar marca ese resultado como `extraido` (≥1 persona) o `sin_menciones`.
- **`SearchResultPolicy`**: `extraer`/`descartar` para `admin`/`oficial_cumplimiento`/`analista`; `capturaManual` **solo** `admin`/`oficial_cumplimiento` (capturar a mano deja el match ya resuelto, sin pasar por proponer→resolver — mismo criterio que `MentionMatchPolicy::resolver`).
- **Actions**: `ExtraerResultado` (válido en `nuevo`/`gap`, marca `procesando`, encola `FetchArticleJob`), `DescartarResultado` (audita `descartado_por`/`en`), `CapturaManual` (transacción: sube el PDF, crea `Mention` con `origen: manual`, crea `MentionMatch` ya resuelto, marca el `search_result` como `extraido`).
- **4 endpoints nuevos** en `SearchResultController`: `GET /subjects/{subject}/resultados`, `POST /resultados/{resultado}/extraer`, `POST /resultados/{resultado}/descartar`, `POST /resultados/{resultado}/captura-manual` (con `CapturaManualRequest`: PDF obligatorio, `mimes:pdf`, máx. 10MB). Las rutas viejas (`POST /subjects/{id}/buscar`, `GET /subjects/{id}/matches`) se dejaron intactas a propósito — las reemplaza el PR de frontend, no este.
- **Disco de evidencia manual configurable, independiente de `FILESYSTEM_DISK`** (pedido explícito del usuario, para no depender de R2 en dev ni en producción salvo que se decida a propósito): `config('vera.evidencia_manual_disk')` ← `EVIDENCIA_MANUAL_DISK` (default `local`).
- Tests nuevos: `SearchResultsTest` (8), `CapturaManualTest` (5), más ampliaciones a `RunSubjectSearchJobTest`, `FetchArticleJobTest` (reescrito completo, +8 casos de GAP) y `ExtractEntitiesJobTest`.

</details>

**Sesión del 2026-09-24 (quinto bloque — rediseño del flujo de consulta puntual, definido, SIN código todavía):** el usuario revisó `/subjects/{id}` con un caso real (Christopher Yuvini Carrillo: 2 matches correctos desde `lanoticiasv.com` y `diario1.com`) y definió un cambio de flujo: **Brave lista resultados, el analista decide cuáles procesar**. Se eliminan el scrape y la extracción IA automáticos. Especificación completa en la **sección 3.7** (nueva), que es la fuente de verdad para implementar. Decisiones confirmadas por el usuario:
- Resultados de Brave visibles todos en la vista del subject, sin scrape previo.
- Botón por resultado "Sacar información de noticia" → recién ahí Fetch → Extract → Match.
- Si el scrape falla (403, timeout, etc.) el resultado queda marcado con la etiqueta visual **GAP** (badge CSS, no es un concepto de evidencia) y admite captura manual de los datos.
- Delitos en captura manual: **texto libre**.
- Datos capturados manualmente **quedan resueltos** por quien los ingresa (no pasan por proponer → resolver).
- Consecuencia derivada de la sección 3.2 (no es una suposición nueva): como capturar = resolver, la captura manual queda limitada a `oficial_cumplimiento`/`admin`. Si el usuario quiere que `analista` también capture, debe decirlo explícitamente (rompería el control de dos pasos).
- **Sin decidir:** evidencia adjunta en captura manual (hoy: sin adjunto, respaldo solo en `activity_log`), backfill de matches previos al cambio, y si el monitoreo continuo de Fase 2 también será bajo demanda. Ver "Pendiente".

También en este bloque se verificó por búsqueda web que **Brave eliminó su tier gratuito el 12-feb-2026**: ahora da $5 de crédito mensual (~1,000 requests a $5/1000), tarjeta obligatoria como instrumento de cobro activo, sin tope de gasto, y el crédito solo se conserva con atribución pública a Brave ("Powered by Brave"). El valor actual `BRAVE_SEARCH_MONTHLY_LIMIT=2000` **no** equivale a $0 (≈ $5/mes con atribución, ≈ $10/mes sin ella). Además, Brave exige un plan con derechos de almacenamiento para guardar resultados total o parcialmente — el nuevo flujo guarda título/snippet, así que esto sube de prioridad (ver sección 9).

**Sesión del 2026-09-24 (cuarto bloque — primera validación real del producto):** el usuario probó el frontend a mano, creó el sujeto real "Cristian Omar Umaña Interiano" (un caso judicial real y reciente, verificado por el usuario con una búsqueda de Google) y reportó "no veo resultados ni feedback". Diagnóstico con Playwright (skill `webapp-testing`) headless contra el dev server real:
- **El feedback sí funcionaba** (toast confirmado por screenshot), pero era transitorio — una vez desaparecía, el mensaje de "sin coincidencias" quedaba idéntico antes y después de buscar, sin forma de distinguir "nunca busqué" de "ya busqué y no hay nada real". **Corregido:** estado local `buscando`/`yaBuscoAlgunaVez` en `$subjectId.tsx` con un indicador de carga explícito ("Buscando… esto puede tardar unos segundos") y un mensaje final distinto una vez pasada la ventana de espera.
- **Hallazgo real y más serio, encontrado probando el caso concreto que trajo el usuario:** el pipeline no encontró el artículo real (condena por homicidio, en La Noticia SV) porque **ese medio no estaba en la lista de 6 dominios de la sección 4** — nunca fue un bug de query, Brave simplemente no tiene ese caso indexado en `laprensagrafica.com`/`elsalvador.com`/etc., solo en `lanoticiasv.com`. El usuario pidió agregarlo. **Corregido:** `lanoticiasv.com` añadido a `RunSubjectSearchJob::MEDIOS_DEFAULT`. Re-corrida completa verificada: Brave devolvió el artículo real como primer resultado, `ExtractEntitiesJob` extrajo correctamente `rol: condenado`, `delitos: ["homicidio simple"]`, `confianza: 0.95`, y `MatchMentionsJob` generó el match con `score: 98.32`. **Primera validación end-to-end del producto con un caso judicial real, no sintético.**
- De paso, en la investigación salió una pista técnica a vigilar (no se actuó sobre ella, no era necesaria para este caso): combinar comillas de frase exacta con `site:` en Brave a veces devuelve **0 resultados** aunque el contenido exista (`"Cristian Umaña" site:laprensagrafica.com` → 0; la misma frase sin comillas → 20). No se tocó el comportamiento de comillas en `construirQuery()` porque agregar el dominio ya resolvió este caso — pero es información relevante para cuando el usuario calibre cobertura/recall en su Fase 0.
- Nota aparte, sin relación con el bug: `npm run build` puede fallar con "Rolldown panicked... out of memory" si `npm run dev` está corriendo al mismo tiempo en la misma máquina (contención de memoria, no es un bug de código) — parar el dev server antes de buildear.


**Sesión del 2026-09-24 (tercer bloque — Frontend Fase 1):** el usuario pidió usar `paper-dashboard-master/` (Paper Dashboard 2 de Creative Tim, Bootstrap 4 + jQuery, plantilla de terceros dejada en la raíz del repo) como base del frontend. Se aclaró con el usuario **cómo** integrarlo, porque las 3 opciones posibles cambiaban completamente el resultado: se eligió usarlo **solo como referencia visual** (paleta/tipografía/layout), construyendo igual en el stack ya cerrado (React 19 + TS estricto + Tailwind v4 + shadcn/ui + TanStack Router/Query) — cero Bootstrap/jQuery en el código final. La plantilla se agregó a `.gitignore` (no es parte del producto, licencia propia de Creative Tim).

Con eso resuelto, se construyó **todo el frontend funcional de Fase 1** de punta a punta:
- **Design system** generado con el skill `ui-ux-pro-max`, corregido a mano para usar la paleta real de Paper Dashboard 2 (no la sugerencia genérica azul/Fira Code del skill) — documentado en `frontend/design-system/MASTER.md`, incluida una tabla de colores semánticos específica de VERA (`nivel_riesgo`, `matches.estado`) con una advertencia explícita: **`confirmado` es rojo, no verde** (confirma un hallazgo de riesgo, no una operación exitosa — el anti-patrón más fácil de cometer por accidente en este dominio).
- **shadcn/ui instalado** sobre el scaffold de Vite (Tailwind v4 y el alias `@/` nunca habían quedado realmente conectados pese a estar en `package.json` — se conectaron ahora). El CLI de shadcn (v4.21 y v4.20) tiene un bug real: escribe los archivos en una carpeta literal `@/` en la raíz en vez de resolver el alias a `src/` — se corrigió a mano después de cada `add`, dos veces.
- **`strict: true` agregado a `tsconfig.app.json`/`tsconfig.node.json`** — la sección 2 del CLAUDE.md exige TypeScript estricto y nunca se había configurado.
- **Bug real de backend encontrado al construir el login: nunca existió un endpoint de autenticación.** Todo el testing de sesiones anteriores usaba tokens creados por tinker/`vera:demo`, nunca un login real por cookies. Se construyó `AuthController` (`POST /api/login`, `POST /api/logout`, `GET /api/user` ahora incluye `roles`) con throttling (`throttle:5,1`, OWASP A07). De paso se encontró que **`config/cors.php` nunca se había publicado** — sin `supports_credentials: true` explícito, Sanctum SPA por cookies no puede funcionar entre `localhost:5173` y `localhost:8000` bajo ninguna circunstancia. Ambos corregidos y **verificados con un login real por curl de punta a punta** (csrf-cookie → login con cookies reales → `/api/user` → `/api/subjects`, los 4 con 200 real).
- **Frontend construido:** cliente API (`src/lib/api.ts`, maneja el handshake CSRF de Sanctum solo), tipos TS de las respuestas del backend, `useAuth`/`useSubjects`/`useMatches` (TanStack Query), rutas de TanStack Router file-based (`_authenticated` como layout route con guard vía `beforeLoad` + `queryClient.ensureQueryData`), `AppShell` (sidebar+topbar), y las 5 pantallas de Fase 1 en 3 rutas: `/login`, `/subjects` (lista + crear = consulta puntual), `/subjects/$subjectId` (detalle + botón "Consulta puntual" + lista de coincidencias con evidencia inline + acciones proponer/resolver). **Build (`tsc -b && vite build`) limpio, `oxlint` sin errores, dev server probado.**
- **79 tests de backend pasan** (sin cambios de conteo desde el bloque anterior — el trabajo de hoy en backend fue el login/CORS, con 4 tests nuevos en `AuthTest.php` que sí están incluidos en el 79).

**Pendiente real para cerrar Fase 1 del todo:** nada bloqueante de código - falta que el usuario pruebe el frontend contra el backend con un usuario real (más allá del smoke test por curl que ya se hizo) y decida si la Fase 0 (que corre él mismo) cambia algo. Adaptadores ONU/UE quedaron confirmados fuera de alcance.

**Resolución de coincidencias (dos pasos, bloque anterior de la misma sesión):** el principio no negociable de la sección 1 ("el sistema propone, el analista resuelve") no tenía ningún endpoint — los `matches` quedaban en `pendiente` para siempre. Se aclaró con el usuario que la sección 3.2 describe un flujo de dos pasos tipo control AML (quien propone no es quien aprueba): `analista` **propone** una resolución, `oficial_cumplimiento`/`admin` la **resuelve** en firme (puede coincidir con la propuesta o no). Implementado: migración (`propuesta_estado`/`propuesta_por`/`propuesta_en` en `matches`), `MentionMatchPolicy`, `ProponerResolucion`/`ResolverMatch` (Actions), `MatchController`, rutas `POST /api/matches/{id}/proponer` y `POST /api/matches/{id}/resolver`, auditoría en `activity_log` (sección 7). 75 tests en ese momento.

**Sesión del 2026-09-24 (primera mitad):** el bloqueo de Google CSE (facturación) se declaró **no negociable por el momento** — el usuario decidió evaluar proveedores alternativos en vez de seguir insistiendo. Investigación en el momento reveló algo más importante que el bloqueo puntual: **Google Custom Search JSON API está cerrada a clientes nuevos desde 2025 y Google la apaga por completo el 1 de enero de 2027**, sin excepción — así que cambiar de proveedor no era solo un parche al bloqueo de hoy, era la decisión correcta de todos modos. Se comparó Brave Search API / Perplexity Search API / SerpApi (este último descartado: Google los está demandando por scraping) y el usuario eligió **Brave Search API**. Se implementó el reemplazo completo: `BraveSearchAdapter` nuevo, migración para el enum de `sources.tipo`, `RunSubjectSearchJob` ruteando a `brave` por default, `IniciarConsultaPuntual`/`vera:demo` actualizados. `GoogleCseAdapter` se queda intacto en el código (Google sigue sirviendo a clientes existentes hasta enero 2027) pero deja de ser la fuente activa. Después, el usuario puso la key real de Brave y se probó el pipeline completo con datos reales por primera vez — salió un bug real más (sin restricción de sitio en la query) que ya quedó corregido. **67 tests pasan.**

**Sesión del 2026-09-21 (corta):** solo se comitió todo el trabajo acumulado de Fase 1 — commit `121631c` en `develop` ("Agrega pipeline de Fase 1 y endpoint de consulta puntual"), **sin `Co-Authored-By`** (el `CLAUDE.md` del proyecto lo prohíbe y prevalece sobre el recordatorio de atribución del sistema). No se corrieron tests en esa sesión ni se tocó código.

**Sesión anterior (2026-09-14 tarde):** continuación: el usuario dio credenciales reales de Google CSE y Anthropic. Se conectaron, se construyó el primer flujo de Fase 1 que faltaba (**consulta puntual por HTTP**, nada la disparaba todavía), se corrió el pipeline contra las APIs reales por primera vez, y en el proceso salieron dos bugs reales y un hueco de seguridad de costos que ya quedaron corregidos y con tests. **60 tests pasan.** Contenedores Docker quedan **detenidos** al cerrar esta sesión (pedido explícito del usuario) — todo lo de abajo asume que hay que levantarlos de nuevo para retomar.

### Qué se completó (sesión 2026-09-24; el resto de sesiones ver "En qué estábamos" arriba)
- **Reemplazo de Google CSE por Brave Search API como fuente activa por default:**
  - `App\Sources\BraveSearchAdapter` nuevo — `GET https://api.search.brave.com/res/v1/web/search`, auth por header `X-Subscription-Token` (no query param), mapea `web.results[].url`, trunca la query a 600 caracteres (límite real de Brave que Google CSE no tenía). Mismo patrón de candado de cuota que `GoogleCseAdapter` pero **mensual** (`BRAVE_SEARCH_MONTHLY_LIMIT`, default 2000). **Corrección del bloque 5:** el supuesto original de "2000 gratis/mes = $0" es falso desde el 12-feb-2026 — ver sección 9.
  - Migración nueva (`2026_09_24_112255_add_brave_to_sources_tipo_enum`) agrega `'brave'` al enum de `sources.tipo`. Usa `Schema::table(...)->enum(...)->change()` (schema builder nativo de Laravel 13, sin doctrine/dbal) — **no** SQL crudo `ALTER TABLE ... MODIFY`, porque eso rompe la suite de tests (corre contra SQLite en memoria, `phpunit.xml`) aunque funcione perfecto en MariaDB. Primer intento de esta sesión sí fue con SQL crudo y tronó 52 tests — corregido antes de seguir.
  - `RunSubjectSearchJob::adapterFor()` rutea `'brave'` a `BraveSearchAdapter`; `'cse'`/`GoogleCseAdapter` se quedan intactos en el código, ya no son el default.
  - `IniciarConsultaPuntual` y `vera:demo` actualizados para filtrar/crear `Source` de tipo `brave`, no `cse`.
  - Tests: `tests/Unit/BraveSearchAdapterTest.php` (6 nuevos: mapeo de urls, respuesta vacía, header de auth correcto, truncado de query, tope de cuota, cuota por mes distinto), `tests/Feature/RunSubjectSearchJobTest.php` (+1, ruteo a Brave), `tests/Feature/SubjectSearchEndpointTest.php` (actualizado de `cse` a `brave`). **65 tests pasan.**
  - Decisión tomada con el usuario tras comparar Brave / Perplexity Search API / SerpApi (descartado: riesgo legal, Google los demanda por scraping) — Brave ganó por precio ($5/1000, 2000 gratis/mes) y por no requerir el lío de proyecto+facturación de Google Cloud que causó el bloqueo original.
- **Key real de Brave puesta y pipeline probado de punta a punta con datos reales** (mismo día, segunda mitad de la sesión):
  - **Bug real encontrado y corregido — sin restricción de sitio en la query:** con Google CSE, la restricción a los 6 medios salvadoreños vivía en el `cx` (configurado del lado de Google) — la query nunca necesitó filtrar por dominio. Brave no tiene ese concepto: sin `site:` en la query busca en toda la web. Primera prueba real con "Juan Carlos Pérez" devolvió un jugador de béisbol de Wikipedia/MLB, no noticias salvadoreñas. Corregido: `RunSubjectSearchJob::construirQuery()` ahora agrega `site:` por cada dominio de `Source.config['dominios']`, con fallback a la lista de la sección 4 si la Source no trae la suya. 2 tests nuevos. **67 tests pasan.**
  - **Gotcha real de Horizon — código nuevo no se toma en caliente:** igual que con las API keys, los workers de Horizon cargan las clases de los jobs una sola vez al arrancar. Cambiar `RunSubjectSearchJob.php` no tuvo efecto hasta correr `php artisan horizon:terminate` (el supervisor los relanza solo, no hace falta reiniciar el contenedor completo). **Patrón a repetir:** cualquier cambio a una clase de Job/Adapter con Horizon ya corriendo necesita esto para probarse en caliente.
  - **Confirmado con datos 100% reales:** `RunSubjectSearchJob` → Brave devolvió URLs reales de `elsalvador.com`/`diario1.com`/`elmundo.sv` → `FetchArticleJob` descargó y guardó evidencia de 16 artículos → `ExtractEntitiesJob` llamó a Anthropic 22 veces de verdad (costo real, pequeño) y devolvió `personas: []` en todos — correcto, el subject de prueba no tiene contenido judicial real en esos artículos (deportes/obituarios). **0 `mentions`/0 `matches` es el resultado esperado para este subject, no una falla del pipeline.**
  - **2 hallazgos nuevos para el backlog** (código de sesiones anteriores, no relacionados con el cambio de proveedor — no se tocaron hoy):
    - `FetchArticleJob` no manda User-Agent de navegador — sitios detrás de Cloudflare (se vio en rutas de `elsalvador.com`) devuelven 403 "Just a moment...", evidencia real que se puede estar perdiendo.
    - `AnthropicClient::extraer()` no maneja con un mensaje claro el caso raro (1 de 22 en esta prueba) de que Sonnet devuelva una respuesta sin bloque de texto usable al escalar.
- Diagnóstico de por qué Google CSE seguía en 403 con la key nueva: primero fue `API_KEY_SERVICE_BLOCKED` (la key tenía marcada la API equivocada en sus restricciones — se corrigió), y después volvió al 403 genérico de siempre (`This project does not have the access to Custom Search JSON API`) — ahí se confirmó que el problema de fondo es la facturación del proyecto, no la key. Quedó ahí cuando el usuario decidió no seguir insistiendo.
- **Hallazgo importante para el roadmap:** Google Custom Search JSON API está cerrada a altas nuevas desde 2025 y se apaga por completo el 1 de enero de 2027 para todos los clientes existentes, sin extensión — confirmado por búsqueda web, no es un rumor. Cualquier decisión futura de volver a Google CSE como fuente debe considerar esa fecha límite.

### Qué se completó (sesión 2026-09-14 tarde)
- **Credenciales reales cargadas** en `backend/.env` (`GOOGLE_CSE_API_KEY`, `GOOGLE_CSE_CX`, `ANTHROPIC_API_KEY`) — confirmado que `backend/.env` está git-ignorado antes de tocarlo.
- **Bug real corregido — modelo de Anthropic mal configurado:** `ANTHROPIC_MODEL_FAST` apuntaba a `claude-haiku-4-5` (sin sufijo de fecha), que no es un id real de la API — toda llamada real habría fallado. Corregido a `claude-haiku-4-5-20251001` en `.env` y en el default de `config/services.php`; 2 tests que tenían el nombre viejo hardcodeado ahora comparan contra `config('services.anthropic.model_fast'/'model_escalation')`. Verificado con una llamada real (HTTP 200).
- **Endpoint de consulta puntual (primer flujo real de Fase 1, sección 5):**
  - `POST /api/subjects/{subject}/buscar` — dispara `RunSubjectSearchJob` contra todas las `sources` activas de tipo `cse` (`App\Actions\Subjects\IniciarConsultaPuntual`). 403 para `lectura`/`superadmin`, 422 si no hay ninguna fuente `cse` activa. Nueva ability `buscar` en `SubjectPolicy` (mismo set de roles que `create`: `admin`/`oficial_cumplimiento`/`analista`).
  - `GET /api/subjects/{subject}/matches` — lista las `matches` propuestas para ese subject (mismo permiso que `view`).
  - `php artisan vera:demo "Nombre"` (`app/Console/Commands/CrearDemo.php`) — crea tenant + usuario `oficial_cumplimiento` + token Sanctum + `Source` cse activa + `Subject` de prueba de un tiro, e imprime los `curl` listos. Solo dev (se niega en `production`).
  - Tests nuevos: `tests/Feature/SubjectSearchEndpointTest.php` (permisos + dispatch + 422).
- **Bug de seguridad de costos corregido — sin tope de cuota diaria:** `GOOGLE_CSE_DAILY_LIMIT` estaba definido en config pero **nada lo hacía cumplir en ningún lado** (el comentario viejo en `GoogleCseAdapter` decía "es responsabilidad del scheduler", que ni existe todavía). Con el endpoint de consulta puntual ya en producción, cualquier `analista` repitiendo la llamada podía pasarse de la cuota gratis sin ningún freno. Ahora `GoogleCseAdapter::buscar()` reserva cupo con un contador atómico en cache (`Cache::add()` + `Cache::increment()`, llave por día UTC) **antes** de llamar a Google — si ya se alcanzó el límite, lanza excepción y la llamada real nunca sale de la app. Funciona igual con `CACHE_STORE=array` (tests) y `redis` (prod/dev) — probado contra el Redis real del contenedor. 2 tests nuevos en `tests/Unit/GoogleCseAdapterTest.php`.
- **Primera corrida real del pipeline** (vía `vera:demo` + `POST /buscar`): confirmó que Anthropic funciona (HTTP 200 real) y que Google CSE está bloqueado — ver "Pendiente" abajo, no es un bug de código.
- Diagnóstico de un falso arranque: tras rotar la API key de Google en el proyecto, Horizon siguió fallando con la key vieja porque el worker la carga en memoria una sola vez al arrancar — hubo que `docker compose restart api`. Ya documentado como paso obligatorio abajo.
- Limpieza repetida del archivo espurio `backend/vera` (SQLite) — reapareció dos veces esta sesión, se sigue borrando sin investigar más (ver nota ya existente sobre esto).

### Qué existe hoy (por módulo)

**Multi-tenancy y auth**
- Tenant se resuelve por `tenant_id` del usuario autenticado (Sanctum), no por dominio (`App\Http\Middleware\InitializeTenancyFromAuthenticatedUser`). `superadmin` recibe 403 en toda ruta de tenant (su alcance es tenants/planes/fuentes globales, no datos de negocio — ver "Bugs relevantes" abajo).
- 5 roles de la sección 3.2 (`RoleSeeder`), `spatie/laravel-permission`. `SubjectPolicy` controla create (no `lectura`).
- `Subject` tiene `LogsActivity` (spatie/laravel-activitylog) — crear/editar queda auditado.
- **Login real (`AuthController`, nuevo 2026-09-24):** `POST /api/login` (Sanctum SPA por cookies, `throttle:5,1`), `POST /api/logout`, `GET /api/user` (incluye `roles`). `config/cors.php` con `supports_credentials: true` — antes de hoy nunca se había publicado y el login por cookies era imposible entre `localhost:5173`/`localhost:8000`. Verificado con un handshake real por curl (csrf-cookie → login → /user → /subjects, los 4 con 200).

**Frontend (Fase 1, nuevo 2026-09-24)**
- React 19 + Vite + TypeScript **estricto** (`strict: true` agregado hoy, faltaba pese a estar en la sección 2) + Tailwind v4 + shadcn/ui (instalado hoy) + TanStack Router (file-based, `_authenticated` como layout route con guard) + TanStack Query.
- Design system en `frontend/design-system/MASTER.md` — paleta/tipografía inspiradas en Paper Dashboard 2 (referencia visual únicamente, `paper-dashboard-master/` gitignored, cero Bootstrap/jQuery en el resultado), con tabla de colores semánticos de dominio (`nivel_riesgo`, `matches.estado`) — **`confirmado` es rojo** (confirma un hallazgo de riesgo, no verde como "éxito").
- Pantallas: `/login`, `/subjects` (lista + crear = consulta puntual), `/subjects/$subjectId` (detalle + botón "Consulta puntual" + coincidencias con evidencia inline + proponer/resolver). `AppShell` con sidebar fijo oscuro + topbar con menú de usuario.
- `frontend/src/lib/api.ts` maneja el handshake CSRF de Sanctum automáticamente (GET a `/sanctum/csrf-cookie` antes de cualquier mutación si falta la cookie).
- Build (`npm run build`: `tsc -b && vite build`) y `npm run lint` (oxlint) limpios.

**Modelos de negocio (sección 3.3)**
- Con `tenant_id` (vía `BelongsToTenant`, algunos también `DerivesTenantFromSubject` porque cuelgan de un `Subject`): `subjects`, `subject_aliases`, `sanction_matches`, `search_runs`, `matches` (modelo `MentionMatch`, no `Match` — palabra reservada en PHP).
- Globales (sin `tenant_id`, catálogo o contenido no atribuible a un tenant): `sources`, `sanction_lists`, `sanction_entries`, `articles`, `mentions`, `extractions`.
- Pendiente de sección 3.3: nada más — todo lo de Fase 1 está creado.

**Pipeline (sección 3.4)**
- `RunSubjectSearchJob`: query con nombre canónico + aliases → adaptador de la `Source` (`'brave'` → `BraveSearchAdapter`, fuente activa por default desde 2026-09-24; `'cse'` → `GoogleCseAdapter`, código intacto pero ya no default — ver "Estatus de sesión") → `search_runs` → encola `FetchArticleJob` por URL. Idempotente (no repite la búsqueda del mismo subject+source el mismo día). Ambos adaptadores imponen su propio tope de cuota con contador atómico en cache antes de llamar al proveedor (diario para Google, mensual para Brave).
- `FetchArticleJob`: descarga, extrae fecha (meta/JSON-LD/`<time>`, fallback genérico — selectores por medio son Fase 0), hash SHA-256, guarda evidencia en `Storage` (disco `r2` en prod, `local` en dev), descarta si está fuera de `ARTICLE_WINDOW_DAYS`. Encola `ExtractEntitiesJob`.
- `ExtractEntitiesJob`: limpia HTML, prompt versionado (`resources/prompts/extraction/v1.md`), llama a Haiku, valida con DTOs de `spatie/laravel-data` (`rol` es un enum PHP real — rechaza valores fuera del contrato antes de tocar la BD), escala a Sonnet si baja confianza o roles cruzados, crea `mentions`, encola `MatchMentionsJob`. Idempotente; `failed()` marca `estado_extraccion = fallido`.
- `MatchMentionsJob`: recorre todos los tenants (`tenancy()->runForMultiple()`), busca en Meilisearch (Scout, `Subject` es `Searchable`) filtrado por `tenant_id`, guarda cada hit en `matches` como `pendiente`. **Umbral/rarity gate sin calibrar a propósito** (sección 9 — "hasta entonces todo match es pendiente", textual).
- **Resolución de coincidencias (dos pasos, sección 1/3.2, nuevo 2026-09-24):** `POST /api/matches/{id}/proponer` (`analista`/`oficial_cumplimiento`/`admin`) guarda una sugerencia en `propuesta_estado`/`propuesta_por`/`propuesta_en`, sin tocar `estado`. `POST /api/matches/{id}/resolver` (solo `oficial_cumplimiento`/`admin`) fija `estado` en firme — puede coincidir con la propuesta o no. Ambos rechazan actuar sobre un match que ya no está `pendiente` (422). `MentionMatch` ahora tiene `LogsActivity` (sección 7: toda resolución queda auditada).
- `ImportSanctionListsJob`: solo OFAC SDN tiene adaptador (URL pública verificada). ONU/UE sin adaptador — sus URLs candidatas no resolvieron (404/403), hace falta confirmar las vigentes. Idempotente, transaccional, upsert por lotes.

**Terceros**
- Anthropic: credenciales reales cargadas y verificadas (HTTP 200 real).
- Brave Search: key real puesta y pipeline verificado con datos reales. Facturación: $5 de crédito/mes (~1,000 requests), luego $5/1000, sin tope de gasto del proveedor — el único tope es `BraveSearchAdapter::reservarCupoMensual()`. El crédito exige atribución pública a Brave. Guardar resultados requiere plan con derechos de almacenamiento (pendiente de confirmar con Brave).
- Google CSE: código intacto (`GoogleCseAdapter`, tipo `'cse'`), bloqueado por facturación y ya no es la fuente activa por default — se apaga por completo en enero 2027 de todos modos.
- `costo` en `search_runs`/`extractions` queda `null` a propósito (ni Google CSE ni Brave dan costo por request individual; Anthropic necesita tarifa real vigente, no inventada).

**API HTTP (Fase 1, sección 5 — "consulta puntual")**
- `POST /api/subjects/{subject}/buscar` y `GET /api/subjects/{subject}/matches` (`SubjectController`), primer flujo de Fase 1 que dispara el pipeline desde fuera de tinker. Ver "Qué se completó en esta sesión".
- `php artisan vera:demo "Nombre"` — bootstrap de datos de prueba (tenant + usuario + token + source + subject) para probar por HTTP sin frontend.

**Documentación**
- `docs/MANUAL_TECNICO.md`: manual completo de producción (estructura de Regla 6), con guía paso a paso de Google CSE/Anthropic/R2/SMTP/Sheets. Marca como pendiente Gotenberg vs. Cloudflare Browser Rendering (sección 2 no lo resuelve).

### Bugs/riesgos reales encontrados y corregidos (histórico — el más reciente arriba)
- **Migración con SQL crudo no portable (2026-09-24):** la migración del enum de `sources.tipo` se escribió primero con `ALTER TABLE ... MODIFY` (sintaxis MariaDB) — funciona perfecto en MariaDB pero rompe 52 tests porque la suite corre contra SQLite en memoria (`phpunit.xml`, decisión de velocidad ya existente, no viola la sección 7 que prohíbe SQLite como motor real). Corregido usando `Schema::table(...)->enum(...)->change()` (schema builder nativo de Laravel 13, portable). **Patrón a repetir:** cualquier migración nueva debe evitar SQL crudo específico de un driver a menos que sea estrictamente necesario — probarla siempre corre contra SQLite vía la suite de tests, no solo contra MariaDB manualmente.
- **Sin tope de cuota diaria de Google CSE (2026-09-14, tarde):** ver "Qué se completó en esta sesión" — nada hacía cumplir `GOOGLE_CSE_DAILY_LIMIT` y el endpoint de consulta puntual ya podía dispararse repetidamente. Corregido con contador atómico en `GoogleCseAdapter`.
- **`ANTHROPIC_MODEL_FAST` con id de modelo inválido (2026-09-14, tarde):** apuntaba a `claude-haiku-4-5` sin fecha, no es un id real de la API — toda llamada real habría fallado. Corregido a `claude-haiku-4-5-20251001`.
- **IDOR entre tenants vía route-model-binding**: `SubstituteBindings` corría antes que el middleware `tenant`. Fix con `$middleware->prependToPriorityList()` en `bootstrap/app.php` — cubre cualquier modelo futuro, no solo `subjects`.
- **Fuga cross-tenant vía superadmin**: el primer diseño del bypass lo dejaba pasar por rutas de tenant sin inicializar tenancy, viendo todo mezclado. Ahora superadmin recibe 403 en esas rutas — su alcance no incluye datos de negocio de un tenant.
- **Bypass de tenant en `DerivesTenantFromSubject`**: la primera versión usaba `Subject::withoutTenancy()->find()`, que permitía referenciar el `subject_id` de OTRO tenant y atribuirle el hijo silenciosamente. Ahora usa `Subject::findOrFail()` normal (con su scope) — falla cerrado.
- **Pipeline no conectado**: cada job pasaba sus tests aislados pero `FetchArticleJob` nunca disparaba `ExtractEntitiesJob` — un artículo se quedaba en `pendiente` para siempre. Ya conectado.
- **Incidente de un subagente de `/code-review`**: borró de la working tree real trabajo legítimo de la sesión (pensó que eran restos propios) y revirtió una config. Se detectó porque el propio reporte lo confesó; se reconstruyó todo. **Corre `git status` después de cada `/code-review`, no confíes solo en su reporte de hallazgos.**
- Detalle completo de cada ronda de code-review (idempotencia de jobs, transacciones, timeouts de Horizon, etc.) vive en el historial de commits/PRs una vez que se comitee — no se repite aquí para no inflar este archivo.

### Cómo probarlo en desarrollo

**Backend + pipeline (Brave Search — fuente activa por default):**
1. Poner `BRAVE_SEARCH_API_KEY` real en `backend/.env` (obtenerla en https://api-dashboard.search.brave.com — solo pide API key, sin el lío de proyecto+facturación de Google Cloud).
2. `docker compose up -d` (raíz del proyecto).
3. `docker exec vera_api php artisan vera:demo "Nombre a buscar"` — ya crea la `Source` con `tipo: 'brave'`, imprime tenant/usuario/token/subject y los `curl` listos.
4. `curl -X POST .../api/subjects/{id}/buscar` con el token impreso, esperar unos segundos, luego `curl .../api/subjects/{id}/matches`.
5. Si algo falla en la llamada real a Brave/Anthropic (no en el código): `docker exec vera_api php artisan queue:failed` para ver la excepción real capturada.
6. **Importante:** si se rota alguna API key con los contenedores ya corriendo, hace falta `docker compose restart api` — Horizon carga el `.env` una sola vez al arrancar el worker, no relee cambios en caliente. Lo mismo aplica a cambios de código en clases de Job/Adapter: `php artisan horizon:terminate` (el supervisor los relanza solo).

**Frontend (nuevo 2026-09-24):**
1. `cd frontend && npm install` (si no se ha hecho) `&& npm run dev` — sirve en `http://localhost:5173`.
2. El backend debe estar corriendo en `http://localhost:8000` (`docker compose up -d`) — `frontend/.env` ya apunta ahí (`VITE_API_URL`).
3. `vera:demo` no crea un usuario con contraseña conocida (crea un token Sanctum, no sirve para el login por cookies del frontend) — para probar el login real hace falta un usuario con password conocido. Crear uno rápido por tinker:
   ```
   docker exec vera_api php artisan tinker --execute='
   $u = App\Models\User::factory()->create(["email"=>"demo@vera.test","password"=>Hash::make("Demo1234!")]);
   $u->forceFill(["tenant_id" => Stancl\Tenancy\Database\Models\Tenant::first()->id])->save();
   $u->assignRole("oficial_cumplimiento");
   '
   ```
   Login en `http://localhost:5173/login` con `demo@vera.test` / `Demo1234!`.
4. `npm run build` (`tsc -b && vite build`) y `npm run lint` (oxlint) para verificar antes de dar una pantalla por terminada.

### Pendiente / próximo paso
- [x] ~~Implementar sección 3.7 — PR backend~~ — **hecho 2026-09-24** (rama `feature/resultados-bajo-demanda`, sin mergear/comitear todavía; 101 tests en verde). Ver sección 3.7 para el detalle completo.
- [x] ~~Implementar sección 3.7 — PR frontend~~ — **hecho 2026-09-24**, verificado end-to-end con Playwright y datos reales (consulta → GAP real → captura manual con PDF → extraído/confirmado → filtro). Ver "En qué estábamos" para el detalle completo. Sin comitear todavía junto con el backend.
- [ ] **Comitear el PR combinado de la sección 3.7** (backend+frontend, ambos en la misma rama `feature/resultados-bajo-demanda`) y decidir si se abre como uno o dos PRs de GitHub (la convención de la sección "Git" dice no mezclar backend/frontend salvo cambio de contrato — este sí lo es).
- [ ] **Calidad de datos de Brave: snippets/títulos pierden tildes/eñes** en algunas páginas (`historico.elsalvador.com`, `elsalvador.com`) — ej. "años" → "aos". Encontrado verificando el frontend nuevo, no es un bug de la UI ni de `BraveSearchAdapter` (solo hace `strip_tags`). Falta investigar si es de la página fuente o de cómo Brave la indexó.
- [x] ~~Decisión del usuario: evidencia adjunta en captura manual~~ — **PDF, obligatorio** (2026-09-24).
- [x] ~~Decisión del usuario: backfill de subjects previos~~ — **no hace falta, eran datos de prueba en dev** (2026-09-24).
- [x] ~~Decisión del usuario: si Fase 2 también deja resultados en `nuevo`~~ — **sí, mismo flujo manual siempre** (2026-09-24).
- [x] ~~Decisión del usuario: valor real de `BRAVE_SEARCH_MONTHLY_LIMIT`~~ — **1000** (2026-09-24, con el precio real de Brave confirmado por el usuario: $5/1000 requests, $5 crédito/mes).
- [ ] **Decisión del usuario, sin contestar todavía:** ¿ventana temporal distinta para `ARTICLE_WINDOW_DAYS` (hoy 30)? Se preguntó junto con las otras 4 pero no se resolvió explícitamente — se mantiene 30 hasta nueva instrucción.
- [ ] Dónde va la atribución pública "Powered by Brave" (requisito para conservar el crédito mensual) — sin decidir.
- [ ] Confirmar con Brave derechos de almacenamiento de resultados (título/snippet en `search_results`, ahora más relevante porque sí se van a guardar).
- [x] ~~Que el usuario pruebe el frontend real~~ — hecho: probado por el usuario, encontró 2 problemas reales (feedback poco visible, `lanoticiasv.com` faltante), ambos corregidos y re-verificados con el caso real.
- [ ] **Cobertura de medios probablemente insuficiente** — el hallazgo de `lanoticiasv.com` fue suerte de que el usuario probó justo un caso que no cubríamos; sugiere que la lista de 6-7 dominios de la sección 4 es angosta. Esto es exactamente lo que la Fase 0 (que hace el usuario) mide con los 20 nombres reales — anotar cualquier dominio nuevo que salga de ahí.
- [ ] **Comillas de frase exacta + `site:` a veces devuelven 0 en Brave** aunque el contenido exista (verificado: `"Cristian Umaña" site:laprensagrafica.com` → 0 resultados; la misma frase sin comillas → 20). No se tocó `construirQuery()` porque no hizo falta para el caso de hoy, pero es un riesgo de falsos negativos silenciosos — vale la pena revisarlo junto con la calibración de Fase 0.
- [ ] **`FetchArticleJob` sin User-Agent de navegador** — sitios detrás de Cloudflare (visto en `elsalvador.com`) devuelven 403 "Just a moment...", evidencia real que se puede estar perdiendo.
- [ ] **`AnthropicClient::extraer()` no maneja el caso de respuesta sin bloque de texto usable** al escalar a Sonnet (1 de 22 llamadas reales) — hoy solo lanza `RuntimeException` genérica; sería útil loguear la respuesta cruda para diagnosticar por qué pasó.
- [ ] `npm run build` puede fallar con "Rolldown panicked... out of memory" si `npm run dev` sigue corriendo al mismo tiempo — parar el dev server antes de buildear (no es un bug de código, es contención de memoria en la máquina).
- [ ] (Opcional, ya no bloquea nada) Si en algún momento se quiere retomar Google CSE antes de enero 2027: vincular facturación en https://console.cloud.google.com/billing/linkedaccount al proyecto dueño de `GOOGLE_CSE_API_KEY`, y crear una `Source` con `tipo: 'cse'` — el código sigue intacto y funcional.
- [ ] (Opcional, fuera del alcance de Fase 1 — decisión del usuario 2026-09-24) Adaptadores de ONU consolidada y UE — falta confirmar la URL pública vigente de cada una.
- [ ] Enriquecer `OfacSdnAdapter` con `alt.csv`/`add.csv` (aliases, país) — hoy vacíos.
- [ ] Costo real por request/token de Brave Search y Anthropic — falta calcularlo bien (Brave sí publica tarifa fija, pero depende de si ya se gastó la cuota gratis del mes).
- [ ] Índice de Meilisearch de test comparte instancia con dev (bajo impacto, cada test usa `tenant_id` nuevo).
- [ ] `DatabaseSeeder` usa `WithoutModelEvents` — si algún día se siembran modelos con `BelongsToTenant`/`DerivesTenantFromSubject` ahí, no se ejecutarían esos hooks. No es problema hoy.

### Contexto importante para retomar
- Contenedores quedaron **arriba** al cerrar esta sesión (a diferencia de la sesión del 2026-09-14, que los dejó detenidos a pedido explícito — hoy no se pidió lo mismo). Si igual no están corriendo al retomar: `docker compose up -d`. API `:8000`, Meilisearch `:7700`, MariaDB `:3306`, Redis `:6379`. Tests: `docker exec vera_api php artisan test`.
- **Si Docker Desktop mismo no está corriendo** (no solo los contenedores): ha pasado varias sesiones seguidas — si al levantar da error de "daemon not running", lanzar `C:\Program Files\Docker\Docker\Docker Desktop.exe` y esperar antes de `docker compose up -d`.
- **Puerto 3306 puede chocar con otros proyectos locales:** esta máquina tiene otros contenedores (`ecosnews_db`, `dulcecalc_mysql`) que a veces ya ocupan `0.0.0.0:3306`/`3307`. Si `vera_mariadb` no arranca con "port is already allocated", es eso — revisar `docker ps` para ver qué más está corriendo, no es un problema de VERA. No se dejó ningún cambio permanente en `docker-compose.yml`/`docker-compose.override.yml` por esto (se probó un remapeo temporal de puerto para poder correr los tests y se revirtió antes de terminar).
- El tenant/usuario/subject/source que dejó `vera:demo` en la sesión del 2026-09-14 (`Juan Carlos Pérez`, con `Source` tipo `cse` — obsoleta) siguen en la BD real; el 2026-09-24 se corrió de nuevo con `Source` tipo `brave`. Para probar Brave desde cero, lo más simple es correr `vera:demo` de nuevo (crea tenant/usuario/source nuevos con `tipo: 'brave'`, no rompe nada existente).
- **Patrón a repetir:** un modelo con `tenant_id` propio que cuelga de un `Subject` usa `App\Models\Concerns\DerivesTenantFromSubject` — nunca `withoutTenancy()` para esto, debe fallar cerrado. Un modelo de negocio nuevo expuesto por route-model-binding ya queda protegido por el fix de prioridad de middleware, no hace falta repetirlo.
- **Patrón a repetir:** cualquier llamada a un servicio externo de pago por uso debe tener su propio candado de cuota en el punto exacto donde se hace el HTTP call, no confiar en que el caller de arriba lo respete — ver `BraveSearchAdapter::reservarCupoMensual()` y `GoogleCseAdapter::reservarCupoDiario()` como los dos ejemplos ya existentes.
- **Patrón a repetir:** ninguna migración nueva debe usar SQL crudo específico de un driver (`ALTER TABLE ... MODIFY`, etc.) — usar siempre el schema builder de Laravel (`->change()`, etc.), porque la suite de tests corre contra SQLite y el código de producción contra MariaDB; SQL crudo que solo sirve a uno de los dos rompe al otro en silencio hasta que corres los tests.
- **Bug conocido del CLI de shadcn/ui (v4.20/v4.21):** `npx shadcn add ...` escribe los componentes en una carpeta literal `@/` en la raíz del proyecto en vez de resolver el alias a `src/`, aunque la validación previa del alias pase. Hay que mover los archivos a mano (`src/components/ui/...`, `src/lib/...`) y borrar la carpeta `@/` después de cada `add`. También pisa gran parte de `src/index.css` (paleta/fuente) con sus defaults — revisar y restaurar los tokens de `design-system/MASTER.md` después de cualquier `add` nuevo.
- **Patrón a repetir (auth:sanctum + guards):** el middleware `auth:sanctum` deja el guard "default" en `sanctum` para el resto del request — `Auth::check()`/`Auth::guard()` sin argumento después de eso ya no reflejan el guard `web` aunque se haya hecho `Auth::guard('web')->logout()` explícito. Cualquier código (o test) que necesite comprobar el estado de `web` después de pasar por `auth:sanctum` debe pedirlo explícito: `Auth::guard('web')`/`assertGuest('web')`.
- El tenant/usuario/subject/source que dejó `vera:demo` (`Juan Carlos Pérez`, tipo `brave`) y el usuario de smoke-test del login (`smoketest@vera.test`) siguen en la BD real de desarrollo — datos de prueba, no hace falta limpiarlos.
- El usuario prefiere que las sub-decisiones ya cubiertas por un criterio explícito se resuelvan directo, sin volver a preguntar — solo preguntar cuando no hay default razonable o la decisión toca auth/BD/arquitectura (ahí sí, plan + confirmar). Ver memoria `feedback-stop-asking-confirm-and-proceed`. El cambio de proveedor de búsqueda sí se preguntó (cambia el stack cerrado de la sección 2 y tiene costo real de por medio).
- **No auto-bloquearse por una credencial faltante**: si una tarea tiene partes que no la necesitan, seguir con esas en vez de parar todo. Ver memoria `feedback-dont-self-block-decompose`.
- El archivo espurio `backend/vera` (SQLite) volvió a aparecer esta sesión — se sigue borrando cuando aparece. Hipótesis revisada: `phpunit.xml` fuerza `DB_DATABASE=:memory:` para los tests dentro de Docker, así que no viene de ahí; sospecha más fuerte ahora es algún proceso con PHP en el HOST (fuera de Docker) corriendo `artisan`/PHPUnit con el `.env` normal del proyecto (`DB_DATABASE=vera`) pero forzando el driver a `sqlite` — quizás una integración de IDE/editor. Sigue sin confirmarse, no bloquea nada.
- Sin línea `Co-Authored-By` en los commits de este proyecto — el usuario lo pidió explícitamente.
- Detalle técnico de Git (PATH de PowerShell) y decisiones de la sesión del 2026-09-12: ver sección 10 más abajo.

### Archivos tocados en esta sesión (2026-09-24)
- **PR frontend sección 3.7 (séptimo bloque):** `frontend/src/types/api.ts` (tipos nuevos/actualizados), `frontend/src/features/resultados/{useSearchResults,ResultadoCard,CapturaManualDialog,FiltroEstado}.tsx` (nuevos), `frontend/src/components/{EstadoSearchResultBadge,GapMotivoBadge}.tsx` (nuevos), `frontend/src/index.css` (token `--gap`), `frontend/design-system/MASTER.md` (tabla de `search_results.estado`), `frontend/src/routes/_authenticated/subjects/$subjectId.tsx` (reescrito), `frontend/src/features/coincidencias/useMatches.ts` (se quita el query muerto `useMatches`), `frontend/src/features/consulta/useSubjects.ts` (invalidación apunta a `resultados`).
- `backend/app/Jobs/RunSubjectSearchJob.php` — `lanoticiasv.com` agregado a `MEDIOS_DEFAULT` (caso judicial real que solo aparecía ahí). `frontend/src/routes/_authenticated/subjects/$subjectId.tsx` — estado "buscando" persistente en vez de depender solo del toast transitorio.
- `backend/app/Sources/BraveSearchAdapter.php` (nuevo) — adaptador de Brave Search con candado de cuota mensual.
- `backend/database/migrations/2026_09_24_112255_add_brave_to_sources_tipo_enum.php` (nuevo) — agrega `'brave'` al enum de `sources.tipo`.
- `backend/app/Jobs/RunSubjectSearchJob.php` — rutea `'brave'` a `BraveSearchAdapter`; `construirQuery()` ahora agrega `site:` por dominio (fix del hallazgo real de esta sesión).
- `backend/app/Actions/Subjects/IniciarConsultaPuntual.php`, `backend/app/Console/Commands/CrearDemo.php` — filtran/crean `Source` tipo `brave` en vez de `cse` (incluye corregir un rótulo de texto que decía "cse" por error).
- `backend/config/services.php`, `backend/.env` — nueva sección `brave_search` (`BRAVE_SEARCH_API_KEY` con key real puesta, `BRAVE_SEARCH_MONTHLY_LIMIT=2000`).
- `backend/database/factories/SourceFactory.php` — `'brave'` agregado al pool de tipos aleatorios.
- `backend/tests/Unit/BraveSearchAdapterTest.php` (nuevo, 6 tests), `backend/tests/Feature/RunSubjectSearchJobTest.php` (+3 tests: ruteo a Brave, restricción de dominio por default, dominio propio de la source), `backend/tests/Feature/SubjectSearchEndpointTest.php` (actualizado de `cse` a `brave`).
- **Resolución de coincidencias:** `backend/database/migrations/2026_09_24_123234_add_propuesta_to_matches_table.php`, `backend/app/Policies/MentionMatchPolicy.php`, `backend/app/Actions/Matches/{ProponerResolucion,ResolverMatch}.php`, `backend/app/Http/Controllers/MatchController.php`, `backend/routes/api.php` (+2 rutas), `backend/app/Models/MentionMatch.php` (agrega `LogsActivity`, relaciones `propuestaPor()`, ajusta `Fillable`), `backend/tests/Feature/MatchResolutionTest.php` (nuevo, 8 tests).
- **Login/CORS (nuevo):** `backend/app/Http/Controllers/AuthController.php` (nuevo), `backend/config/cors.php` (nuevo, nunca publicado), `backend/routes/api.php` (+login/logout/user), `backend/tests/Feature/AuthTest.php` (nuevo, 4 tests).
- **Frontend (nuevo, prácticamente desde cero):** `frontend/vite.config.ts` (plugins Tailwind+TanStack Router+alias `@/`), `frontend/tsconfig.{app,node}.json` (`strict: true`, alias), `frontend/src/index.css` (paleta real, reescrito varias veces por el bug de shadcn), `frontend/components.json` (nuevo), `frontend/src/components/ui/*` (shadcn, 16 componentes), `frontend/src/lib/{api,utils}.ts`, `frontend/src/types/api.ts`, `frontend/src/features/{auth,consulta,coincidencias}/*`, `frontend/src/components/{layout/AppShell,EstadoMatchBadge,NivelRiesgoBadge}.tsx`, `frontend/src/routes/*` (login, `_authenticated` + subjects), `frontend/src/main.tsx`, `frontend/design-system/MASTER.md` (nuevo), `frontend/.env`/`.env.example` (nuevos), `.gitignore` raíz (+`paper-dashboard-master/`).
- Sesiones anteriores, sin cambios adicionales hoy en esas áreas: prácticamente todo `backend/app/{Models,Jobs,Http,Sources,Services,Data,Enums,Policies,Actions}`, `backend/database/{migrations,factories,seeders}`, `backend/config/{tenancy,scout,services,filesystems,vera}.php`, `backend/tests/`, `backend/resources/prompts/`, `docs/MANUAL_TECNICO.md`. Para el detalle exacto archivo por archivo, `git status`/`git diff` en la raíz del repo es más confiable que mantener una lista manual aquí.

---

## 1. Propósito del producto

Permitir que un oficial de cumplimiento:

1. Consulte puntualmente si una persona (natural o jurídica) aparece en medios de comunicación salvadoreños y fuentes oficiales vinculada a procesos judiciales (hurto, estafa, extorsión, lavado, narcotráfico, corrupción, etc.).
2. Mantenga una lista de vigilancia (clientes, empleados, proveedores) con monitoreo continuo y alertas por coincidencias nuevas.
3. Cruce nombres contra listas de sanciones internacionales (OFAC SDN, ONU consolidada, UE).
4. Registre evidencia auditable (snapshot + hash + timestamp) y la resolución humana de cada coincidencia (confirmada / falso positivo / homónimo).
5. Genere reportes exportables por persona, periodo y nivel de riesgo.

Principio de flujo (confirmado 2026-09-24): **la búsqueda lista, el analista decide qué procesar**. Ningún resultado de búsqueda se descarga ni se envía a la IA sin una acción explícita del analista (ver sección 3.7).

Principio no negociable: **el sistema propone coincidencias, el analista resuelve**. Ninguna pantalla ni reporte afirma que una persona "está involucrada" sin resolución humana registrada.

---

## 2. Stack (cerrado)

### Backend
- PHP 8.4, Laravel 13 como API REST pura (sin Blade para vistas de aplicación; Blade solo para plantillas de reportes y correos)
- Laravel Sanctum (autenticación SPA con cookies)
- Laravel Horizon + Redis 7 (colas y supervisión)
- Laravel Scheduler (jobs programados)
- Laravel Scout con driver Meilisearch
- `stancl/tenancy` — multi-tenant con base de datos única y columna `tenant_id`
- `spatie/laravel-permission` — roles y permisos
- `spatie/laravel-activitylog` — auditoría inmutable
- `spatie/laravel-data` — DTOs y validación de respuestas de IA
- Symfony DomCrawler (incluido en Laravel) para RSS/HTML

### Base de datos y búsqueda
- MariaDB 11 (persistencia)
- Meilisearch (self-hosted, Docker) — matching fuzzy de nombres. **No usar FULLTEXT de MariaDB para nombres.**

### Frontend
- React 19 + Vite + TypeScript (strict) — **implementado 2026-09-24**, `strict: true` real en `tsconfig.app.json`/`tsconfig.node.json`.
- Tailwind CSS v4 — **implementado**, tokens en `frontend/src/index.css` según `frontend/design-system/MASTER.md`.
- shadcn/ui — **instalado** (ver "Contexto importante" sobre el bug del CLI con el alias `@/`).
- TanStack Query (estado de servidor) + TanStack Router (file-based, confirmado — ver sección 10) — **implementado**.
- Despliegue estático en Cloudflare Pages — pendiente, todavía no se ha desplegado a ningún lado.

### Servicios externos
- **Brave Search API** (fuente activa por default desde 2026-09-24 — ver "Estatus de sesión"). Reemplaza a Google Custom Search JSON API: esa API está cerrada a clientes nuevos desde 2025 y Google la apaga por completo el 1 de enero de 2027, además de un bloqueo de facturación sin resolver en el proyecto de Google Cloud del propietario. El código de Google CSE (`GoogleCseAdapter`, tipo `'cse'`) se queda en el repo por si se retoma antes de esa fecha, pero no es la fuente activa.
- Anthropic Claude API: `claude-haiku-4-5-20251001` para extracción masiva; `claude-sonnet-5` para escalado por baja confianza
- Cloudflare R2 (evidencia, driver S3 de Laravel)
- SMTP para alertas (correo del dominio)
- Google Sheets API (exportación opcional de alertas)
- Gotenberg o Cloudflare Browser Rendering: PDF **solo bajo demanda**, nunca en el pipeline automático

### Infraestructura
- Docker Compose en VPS (2 vCore, 4 GB RAM, 120 GB NVMe): contenedores `api` (PHP-FPM + Horizon), `mariadb`, `meilisearch`. Redis compartido con el host.
- Apache2 como reverse proxy con TLS
- Presupuesto de RAM del producto: máximo 600 MB. Horizon: máximo 3 workers.
- No usar Slack. Alertas por correo y Google Sheets.

---

## 3. Arquitectura

### 3.1 Multi-tenancy
- Un tenant = un sujeto obligado (empresa cliente).
- Base de datos única; todo modelo de negocio lleva `tenant_id` con global scope. **Excepción confirmada:** `articles` y `sources` son catálogos globales deduplicados (por URL y por fuente respectivamente), sin `tenant_id` propio — el aislamiento de tenant en el pipeline de screening vive en `mentions`/`matches`, que sí llevan `tenant_id`.
- Índices de Meilisearch únicos por entidad con atributo filtrable `tenant_id`; toda búsqueda filtra por tenant.
- Usuarios pertenecen a un solo tenant. Rol `superadmin` (propietario de la plataforma) opera fuera de tenant.
- Almacenamiento en R2: evidencia de `articles` es global (sin prefijo de tenant, ver excepción arriba); cualquier otro archivo específico de un tenant va con prefijo `tenants/{tenant_id}/...`.

### 3.2 Roles
- `superadmin`: gestión de tenants, planes, facturación, fuentes globales.
- `admin`: gestión de usuarios y configuración del tenant.
- `oficial_cumplimiento`: resuelve coincidencias, aprueba reportes, gestiona lista de vigilancia.
- `analista`: ejecuta consultas, propone resoluciones, no aprueba.
- `lectura`: solo consulta y reportes.

### 3.3 Modelo de datos (núcleo)

```
tenants
plans                      (básico, profesional, empresarial; límites de personas/usuarios)
subscriptions              (tenant, plan, estado, ciclo)
users                      (tenant_id, roles)
subjects                   (persona vigilada: tenant_id, tipo natural/jurídica, nombre canónico,
                            documento opcional, nivel_riesgo, activo)
subject_aliases            (variantes de nombre por subject)
sources                    (medio/fuente: nombre, tipo [brave|cse|rss|oficial|sanciones], config, activo, global)
search_runs                (ejecución de búsqueda: subject_id, source_id, query, resultados, costo)
search_results             (IMPLEMENTADO 2026-09-24, sección 3.7 — un registro por resultado de
                            búsqueda: search_run_id, subject_id, tenant_id derivado del subject, url,
                            url_hash, titulo, snippet, medio, fecha_brave, estado, http_status,
                            gap_motivo, article_id (nullable), evidencia_manual_path,
                            descartado_por, descartado_en)
articles                   (url única, título, medio, fecha_publicacion, hash_contenido,
                            evidence_path, estado_extraccion)
extractions                (article_id, modelo, json_resultado, confianza, tokens_in/out, costo)
mentions                   (persona detectada: article_id (NULLABLE desde 3.7 - una mention manual
                            no tiene Article), search_result_id (NUEVO, enlace confiable
                            mention→resultado), nombre_extraido, rol [imputado|condenado|víctima|
                            testigo|otro], fecha_hecho (NUEVO), delitos[], confianza (NULLABLE -
                            null en captura manual), resumen (NUEVO), origen [automatico|manual],
                            creado_por)
matches                    (mention_id, subject_id, score_meilisearch (NULLABLE - null en captura
                            manual), estado [pendiente|confirmado|falso_positivo|homonimo],
                            resuelto_por, resuelto_en)
sanction_lists             (ofac_sdn, un_consolidated, eu; versión, fecha_importación)
sanction_entries           (lista, nombre, aliases[], tipo, programa, país, raw_json)
sanction_matches           (subject_id, sanction_entry_id, score, estado, resolución)
alerts                     (tenant_id, tipo, referencia polimórfica, canal, enviado_en)
reports                    (tenant_id, tipo, parámetros, generado_por, path)
activity_log               (spatie)
```

### 3.4 Pipeline (jobs en Horizon)

Colas y prioridad: `alerts` > `matching` > `extraction` > `fetch` > `search` > `imports`.

1. `RunSubjectSearchJob` — por subject y source: construye query (nombre canónico + aliases + `site:` por medio), llama Brave (o RSS/oficial), persiste `search_runs` y **un `search_results` por resultado en estado `nuevo`. NO encola `FetchArticleJob`** (cambio 2026-09-24, sección 3.7).
2. `FetchArticleJob` — **solo se encola por acción del analista** (`POST /api/resultados/{id}/extraer`). Recibe `search_result_id`. Descarga HTML, extrae `fecha_publicacion` de metadatos (`article:published_time`, JSON-LD, `<time>`), calcula SHA-256, sube snapshot a R2, persiste `articles`. Artículos fuera de la ventana configurada, 403, timeout, contenido vacío o no-HTML → marca el `search_result` como `gap` con `gap_motivo` y termina sin excepción (un GAP es un resultado válido, no un fallo de job).
3. `ExtractEntitiesJob` — envía texto limpio a Claude Haiku con esquema JSON estricto; valida con `spatie/laravel-data`; persiste `extractions` y `mentions`. Si `confianza < umbral` → reencola con Sonnet 5. Al terminar marca el `search_result` como `extraido` (≥1 persona) o `sin_menciones` (lista vacía).
4. `MatchMentionsJob` — por cada mention, consulta Meilisearch (índice `subjects`, filtro `tenant_id`) con tolerancia a errores; aplica normalización (unaccent, minúsculas, orden de tokens) y *rarity gate* por frecuencia de apellidos; persiste `matches` en estado `pendiente`.
5. `SendAlertJob` — correo (y Google Sheets si está configurado) al oficial de cumplimiento del tenant.
6. `ImportSanctionListsJob` — semanal; descarga OFAC SDN (CSV/XML), ONU consolidada (XML), UE (XML); reindexa en Meilisearch (`sanction_entries`); ejecuta `MatchSanctionsJob` para todos los subjects activos.

Scheduler:
- Monitoreo continuo diario, priorizado por `nivel_riesgo` para no agotar la cuota mensual de Brave (`BRAVE_SEARCH_MONTHLY_LIMIT`). **Confirmado 2026-09-24:** el monitoreo continuo de Fase 2 usa exactamente el mismo flujo manual de la sección 3.7 — deja los resultados en `nuevo` para que el analista decida, nunca procesa automático. No hace falta una rama de lógica distinta para Fase 2; el mismo `RunSubjectSearchJob` sirve para ambas.
- Tope de cuota por proveedor impuesto en el adaptador (ya existe para Brave y Google CSE).
- Importación de sanciones: semanal.

### 3.5 Extracción con IA — contrato

Prompt de sistema versionado en `resources/prompts/extraction/v{n}.md`. Salida JSON obligatoria:

```json
{
  "personas": [
    {
      "nombre": "string",
      "rol": "imputado|condenado|victima|testigo|otro",
      "delitos": ["string"],
      "institucion_relacionada": "string|null",
      "confianza": 0.0
    }
  ],
  "fecha_hecho": "YYYY-MM-DD|null",
  "resumen": "string (máximo 2 oraciones)",
  "confianza_global": 0.0
}
```

Reglas:
- Nunca inventar nombres; si el artículo usa iniciales (ej. "Julio César M."), devolverlas tal cual.
- Registrar tokens y costo por extracción.
- Escalar a Sonnet 5 si `confianza_global < 0.6` o si hay más de 3 personas con roles cruzados.

### 3.6 Evidencia
- Snapshot HTML completo + cabeceras HTTP + hash SHA-256 + timestamp UTC + URL original.
- Inmutable una vez guardado. Cualquier re-captura crea una versión nueva.
- PDF bajo demanda desde la evidencia guardada, no desde la URL viva.

### 3.7 Flujo de consulta puntual bajo demanda (definido 2026-09-24 — IMPLEMENTADO 2026-09-24, backend y frontend, verificado end-to-end)

**Flujo:**
1. El analista ejecuta "Consulta puntual" → Brave → se guardan y listan todos los resultados en `/subjects/{id}`. Sin scrape, sin IA.
2. El analista revisa cada resultado (título, medio, fecha, snippet) y decide.
3. "Sacar información de noticia" → Fetch → Extract → Match para ese resultado.
4. Si el Fetch falla → etiqueta **GAP** + captura manual.

**Estados de `search_results` (enum PHP real):**

| Estado | Condición | Acciones en la vista |
|---|---|---|
| `nuevo` | Resultado de búsqueda sin procesar | "Sacar información de noticia", "Descartar" |
| `procesando` | Job encolado/en curso | Indicador de carga, sin acciones |
| `extraido` | Extracción con ≥1 mención, o captura manual | Menciones (rol, delitos) y su match con proponer/resolver |
| `sin_menciones` | Artículo leído, la IA no encontró personas | Etiqueta informativa, "Descartar" |
| `gap` | Fetch fallido (ver motivos) | Badge GAP con motivo, "Reintentar", "Ingresar datos manualmente" (solo roles que resuelven) |
| `descartado` | Marcado irrelevante por el usuario | Tarjeta atenuada; auditado |

**`gap_motivo` (enum PHP real):** `http_403`, `http_error`, `timeout`, `sin_contenido`, `fuera_de_ventana`, `no_html`.

**Endpoints (controladores delgados, lógica en `app/Actions/SearchResults/`, `SearchResultPolicy`):**
- `GET /api/subjects/{subject}/resultados` — resultados del subject (más recientes primero) con article, mentions y matches del subject anidados. Permiso: `view`.
- `POST /api/resultados/{searchResult}/extraer` — solo en `nuevo` o `gap` (reintento); 422 en otro estado. Roles: `admin`, `oficial_cumplimiento`, `analista`.
- `POST /api/resultados/{searchResult}/descartar` — mismos roles. Auditado.
- `POST /api/resultados/{searchResult}/captura-manual` — **solo `admin` y `oficial_cumplimiento`**, solo en estado `gap`.
- `superadmin` recibe 403 en todos (regla existente).

**Captura manual (reglas confirmadas):**
- Campos: `nombre_como_aparece` (req), `rol` (enum existente, req), `delitos` (**texto libre**, array, mínimo 1), `fecha_hecho` (opcional), `resumen` (opcional), `estado_resolucion` (`confirmado`|`falso_positivo`|`homonimo`, req).
- Crea `mention` (`origen: manual`, `creado_por`) + `match` del subject **ya resuelto** (`estado = estado_resolucion`, `resuelto_por`, `resuelto_en`) y marca el `search_result` como `extraido`. Todo en una transacción. Registro en `activity_log`.
- No pasa por proponer → resolver (decisión del usuario). Por eso solo la pueden hacer los roles que ya resuelven (sección 3.2).
- **Evidencia (confirmado 2026-09-24): adjunto en PDF, obligatorio.** El analista sube el PDF (ej. la página impresa a PDF desde su navegador, ya que el fetch automático falló — por eso es GAP) junto con el formulario. Se guarda como cualquier evidencia de la sección 3.6: inmutable una vez subida, con hash SHA-256 y timestamp UTC, prefijo `tenants/{tenant_id}/evidencia-manual/{search_result_id}.pdf` en R2 (evidencia manual SÍ es específica de un tenant, a diferencia de `articles` que es global — sección 3.1). Columna nueva `search_results.evidencia_manual_path`. Validar tipo `application/pdf` y tamaño máximo (definir en el plan del PR, ej. 10MB) antes de subir.

**Frontend (`/subjects/$subjectId`) — IMPLEMENTADO 2026-09-24, verificado end-to-end con datos reales:**
- Reemplaza la sección separada "Coincidencias propuestas" por una tarjeta por resultado, con sus menciones y match anidados dentro.
- Filtro por estado (Todos / Nuevos / GAP / Extraídos / Descartados).
- Mientras haya resultados en `procesando`: `refetchInterval` de 3 s, detenerlo cuando no quede ninguno.
- La tarjeta indica si el registro fue ingresado manualmente y por quién.
- **GAP es un estilo visual:** token semántico `gap` en `src/index.css`, documentado en la tabla de colores de `frontend/design-system/MASTER.md`, distinguible de los estados de matches (recordar: `confirmado` es rojo) y del resto de estados de `search_results`.
- Captura manual en un Dialog de shadcn; delitos como input de texto libre que agrega chips.

**Implementación:** dos PRs (convención de Git): backend primero (cambia el contrato), frontend después. Antes de codificar cada uno, presentar el plan (migraciones/endpoints o componentes) y esperar confirmación del usuario.
- **Backend: IMPLEMENTADO 2026-09-24.** 5 migraciones (`search_results` nueva; `mentions`/`matches` con columnas nuevas/nullable para captura manual), 3 enums (`EstadoSearchResult`, `GapMotivo`, `OrigenMention`), modelo `SearchResult`, contrato `SourceAdapterInterface::buscar()` cambiado a `{resultados[], costo}`, `RunSubjectSearchJob`/`FetchArticleJob` reescritos (ya no descargan automático — el fetch solo corre cuando el analista pide "Sacar información de noticia"), `ExtractEntitiesJob` actualizado para cerrar el ciclo de `search_results.estado`, `SearchResultPolicy` (captura manual solo `admin`/`oficial_cumplimiento`), 3 Actions nuevas (`ExtraerResultado`, `DescartarResultado`, `CapturaManual`), `EVIDENCIA_MANUAL_DISK` configurable independiente de `FILESYSTEM_DISK` (default `local`, dev y producción). 101 tests en verde (233 assertions), sin regresiones. Detalle completo en "Estatus de sesión".
- **Frontend: pendiente.** Es el siguiente paso — no empezar sin presentar el plan de componentes primero.

**Impacto esperado:** el gasto en Anthropic pasa a ser bajo demanda (en la prueba de `Juan Carlos Pérez` se gastaron 22 llamadas en artículos irrelevantes).

---

## 4. Fuentes iniciales

Medios (vía Brave Search API, `site:` por dominio o combinado en la query — ver "Estatus de sesión" sobre el cambio desde Google CSE):
- laprensagrafica.com
- elsalvador.com
- diarioelmundo.com
- lapagina.com.sv
- diario1.com
- elmundo.sv
- lanoticiasv.com (agregado 2026-09-24 — un caso judicial real solo aparecía ahí, no en los 6 originales; confirmado por el usuario)
- (seguir ampliando tras la prueba de cobertura — el hallazgo de `lanoticiasv.com` sugiere que la lista original de 6 medios puede ser más angosta de lo necesario para cobertura real)

Oficiales (RSS/HTTP directo):
- fiscalia.gob.sv (comunicados)
- csj.gob.sv (centros judiciales)
- pnc.gob.sv

Sanciones:
- OFAC SDN: sdnlist / CSV oficial
- ONU: lista consolidada XML
- UE: lista consolidada XML

Cada fuente es un registro en `sources` con adaptador propio bajo `app/Sources/{Nombre}Adapter.php` implementando `SourceAdapterInterface`.

---

## 5. Fases

### Fase 0 — Pruebas de concepto (antes de codificar el producto)
1. Ejecutar 20 nombres de casos judiciales recientes conocidos contra Brave con `site:` por medio (la referencia original a un `cx` de Google CSE quedó obsoleta); medir recall y latencia de indexación. Incluir el caso de comillas + `site:` que devuelve 0 resultados. Avance: 2 casos reales validados (Cristian Umaña, Christopher Yuvini Carrillo).
2. Validar extracción de `fecha_publicacion` en 30 artículos de los 7 medios; documentar selectores por medio.
3. Prompt de extracción: probar Haiku sobre 30 artículos; medir precisión de roles y nombres contra revisión manual.

Resultado esperado: informe corto en `docs/poc/` con métricas y decisiones.

### Fase 1 — MVP (3–4 semanas)
- Multi-tenant, roles, autenticación.
- Consulta puntual: subject temporal → pipeline completo → coincidencias → resolución manual → evidencia.
- Sanciones OFAC/ONU/UE.
- Frontend: login, consulta puntual, resultado, evidencia, resolución.

### Fase 2 — Monitoreo continuo
- Lista de vigilancia, aliases, nivel de riesgo.
- Scheduler diario priorizado, alertas por correo, Google Sheets opcional.
- Dashboard de coincidencias pendientes.

### Fase 3 — SaaS
- Planes, límites, suscripciones, facturación (PayPal Empresa).
- Reportes de auditoría exportables.
- API pública para plan Empresarial.
- Panel superadmin.

---

## 6. Convenciones de código

### Backend
- Arquitectura: `app/Actions`, `app/Jobs`, `app/Sources`, `app/Services/{Search,Extraction,Matching,Evidence,Sanctions}`, `app/Data` (DTOs).
- Controladores delgados; lógica en Actions y Services.
- Toda consulta a modelos de negocio pasa por el scope de tenant. Prohibido `withoutGlobalScopes`/`withoutTenancy()` fuera de superadmin.
- **El scope de tenant (`Stancl\Tenancy\Database\Concerns\BelongsToTenant`) no filtra si `tenancy()` no está inicializado — falla abierto, no cerrado.** Todo código que consulte un modelo con ese trait fuera de un request HTTP normal (Jobs, comandos `artisan`, `tinker`, seeders) DEBE llamar `tenancy()->initialize($tenant)` explícitamente antes de la consulta, o se lee/escribe entre todos los tenants sin darse cuenta. Los controladores están cubiertos por el middleware `tenant`; los Jobs del pipeline (sección 3.4) tendrán que inicializar tenancy ellos mismos (revisar al implementarlos).
- Pruebas: Pest. Cobertura obligatoria en matching, extracción (con respuestas grabadas) y aislamiento de tenant.
- Migraciones con claves foráneas e índices explícitos en `tenant_id` + columnas de búsqueda.
- Secretos solo en `.env`; nunca en código ni en commits.

### Frontend
- Componentes en `src/features/{consulta,vigilancia,coincidencias,evidencia,reportes,admin}`.
- Cliente API tipado generado desde OpenAPI (Laravel: `dedoc/scramble` o equivalente; confirmar antes de agregar).
- Sin estado global innecesario; TanStack Query maneja servidor.
- Idioma de la interfaz: español (es-SV). Preparar i18n desde el inicio, sin traducir aún.

### Git
- Ramas: `main` (producción), `develop`, `feature/*`, `fix/*`.
- Commits en español, imperativo, sin emojis.
- Un PR por feature; no mezclar backend y frontend en el mismo PR salvo cambio de contrato.

---

## 7. Reglas para Claude Code

- Trabajar en español.
- No introducir librerías, servicios ni tecnologías fuera de la sección 2 sin preguntar.
- No generar código de pago, facturación ni integración PayPal hasta Fase 3.
- No usar Slack, Postgres, SQLite, ElasticSearch, Next.js, Inertia ni Livewire.
- Ante ambigüedad en reglas de negocio (umbrales de matching, ventana temporal, roles), preguntar antes de asumir.
- Cada job debe ser idempotente y reintentable.
- Registrar en `activity_log` toda resolución de coincidencia y todo cambio en lista de vigilancia.
- Mantener el presupuesto de RAM: no agregar contenedores.
- Antes de cerrar una tarea: pruebas pasando, migraciones reversibles, documentación mínima en `docs/`.

---

## 8. Variables de entorno (referencia)

```
APP_ENV, APP_KEY, APP_URL
DB_CONNECTION=mariadb, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
REDIS_HOST, REDIS_PASSWORD
QUEUE_CONNECTION=redis
SCOUT_DRIVER=meilisearch, MEILISEARCH_HOST, MEILISEARCH_KEY
BRAVE_SEARCH_API_KEY, BRAVE_SEARCH_MONTHLY_LIMIT=1000 (confirmado 2026-09-24 — coincide exacto con el crédito gratis mensual real de Brave, $5/1000 requests, ver sección 9)
GOOGLE_CSE_API_KEY, GOOGLE_CSE_CX, GOOGLE_CSE_DAILY_LIMIT=100 (ya no es la fuente activa, ver sección 2)
ANTHROPIC_API_KEY, ANTHROPIC_MODEL_FAST=claude-haiku-4-5-20251001, ANTHROPIC_MODEL_ESCALATION=claude-sonnet-5
EXTRACTION_CONFIDENCE_THRESHOLD=0.6
MATCH_SCORE_THRESHOLD (definir en Fase 0)
ARTICLE_WINDOW_DAYS (por defecto 30 para consulta puntual; configurable por tenant)
FILESYSTEM_DISK=r2, R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET
MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
GOOGLE_SHEETS_CREDENTIALS_JSON (opcional)
SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN, FRONTEND_URL
```

---

## 9. Riesgos y decisiones pendientes

- Homónimos: umbral de Meilisearch y *rarity gate* se calibran en Fase 0 con datos reales; hasta entonces todo match es `pendiente`.
- Protección de datos: Ley de Protección de Datos Personales (2024). Definir política de retención por tenant y registro de base legal (obligación AML).
- Cuota de búsqueda (Brave Search, antes Google CSE): el scheduler nunca debe superar el límite configurado (`BRAVE_SEARCH_MONTHLY_LIMIT`); excedentes se difieren por prioridad. El candado duro ya existe a nivel de adaptador (`BraveSearchAdapter::reservarCupoMensual()`) — lo que falta de esto es la lógica de *priorización* del scheduler en sí, que todavía no existe (ver "Pendiente" en Estatus de sesión).
- Brave — derechos de almacenamiento: Brave exige un plan con derechos de almacenamiento para guardar resultados total o parcialmente. `search_runs` ya guardaba URLs y `search_results` (3.7) guardará título y snippet. Confirmar con Brave por escrito antes de tener clientes pagando.
- Brave — costo: sin tope de gasto del proveedor (esto sigue siendo cierto); **`BRAVE_SEARCH_MONTHLY_LIMIT` ya se resolvió: 1000** (confirmado 2026-09-24, coincide exacto con el crédito gratis mensual — precio real confirmado por el usuario desde la propia página de precios de Brave: $5/1000 requests, $5 de crédito automático cada mes, 50 req/seg). Dónde va la atribución pública "Powered by Brave" sigue sin decidir — falta antes de producción si se quiere seguir conservando ese crédito.
- ~~Captura manual sin adjunto~~ — **resuelto 2026-09-24: sí lleva adjunto, en PDF** (ver sección 3.7 "Captura manual"). Ya no es un riesgo abierto.
- Ventana temporal: `ARTICLE_WINDOW_DAYS=30` en consulta puntual deja fuera condenas de hace meses o años; con el flujo 3.7 esos artículos quedan como GAP (`fuera_de_ventana`). **Sigue sin decidirse un valor distinto** — se preguntó junto con lo demás el 2026-09-24 pero no se contestó explícitamente; se mantiene 30 hasta que el usuario diga otra cosa.
- Marca: verificar dominio y registro en CNR (clases 42 y 45) antes de identidad visual.

---

## 10. Skills activas (workflow `mi-workflow`)

Selección hecha el 2026-09-12 aplicando la Regla 0 (sistema de skills por capas) de `mi-workflow`.

### Capa 1 — obligatorias siempre
- `mi-workflow` — instalada
- `owasp-security` — instalada
- `software-architecture` — instalada
- `systematic-debugging` — instalada (2026-09-13, `obra/superpowers@systematic-debugging`)

### Capa 2 — recomendadas para este proyecto
- `ui-ux-pro-max` — instalada (2026-09-13, `nextlevelbuilder/ui-ux-pro-max-skill@ui-ux-pro-max`). Aplica porque VERA es multi-módulo (consulta, vigilancia, coincidencias, evidencia, reportes, admin) y necesita un design system consistente entre pantallas. Es el default de diseño de este proyecto (no `frontend-design`, ya que no hay landing/pieza aislada). **Nota:** el Gen security risk assessment del instalador la marcó como "High Risk" (Socket y Snyk la dan como bajo riesgo) — revisar el contenido antes de un uso serio.
- `test-driven-development` — instalada (2026-09-13, `obra/superpowers@test-driven-development`). Aplica por la lógica de negocio compleja (matching, extracción IA, pipeline de jobs) y las APIs REST.
- `varlock` — instalada (2026-09-13, `dmno-dev/varlock@varlock`). Aplica por el volumen de credenciales/API keys del proyecto (Google CSE, Anthropic, R2, SMTP, Sheets).
- `pdf` — instalada. Aplica por los reportes/PDF bajo demanda del pipeline (sección 3.6 y 5).

### Capa 3 — pendientes según hito (no activar aún)
- [x] `webapp-testing` — ya usada el 2026-09-24 para diagnosticar el frontend contra el dev server real.
- [ ] `security-audit` — activar en el sprint final antes del deploy a producción.
- [ ] `claude-mem` — evaluar si el proyecto se extiende por semanas/meses (probable, dado que tiene 3 fases).
- [ ] `agile-workflow` — solo si se suma un equipo de 2+ personas.

### Decisiones confirmadas (2026-09-12)
- **Router frontend:** TanStack Router (se integra con TanStack Query ya elegido; type-safety end-to-end en TS strict).
- **Generador OpenAPI backend:** `dedoc/scramble`.
- **Proveedor de IA:** se mantiene únicamente Claude (Haiku/Sonnet) como stack cerrado de la sección 2 — no se abre a OpenAI ni otros proveedores por ahora.
- **PHP/Composer local:** no se instalan en el host. Todo el backend se construye y corre dentro de Docker Compose (contenedor `api`), incluida la creación inicial del proyecto Laravel.

### Decisiones confirmadas (2026-09-13)
- **Resolución de tenant:** por el `tenant_id` del usuario autenticado vía Sanctum (`App\Http\Middleware\InitializeTenancyFromAuthenticatedUser`, alias `tenant`), no por dominio/subdominio. Consistente con BD única + `tenant_id` (sección 3.1) y con el frontend SPA de un solo dominio (Cloudflare Pages) — evita gestionar wildcard TLS/DNS por tenant en un VPS de 2 vCore/4GB. Detalle en "Estatus de sesión".
- **Roles:** los 5 roles de la sección 3.2 son globales (`spatie/laravel-permission` sin la feature de "teams") — no hace falta un rol por tenant porque cada usuario ya pertenece a un solo tenant vía `tenant_id`; el rol es solo la etiqueta de capacidad dentro de ese tenant.
- **`articles` es global, deduplicado por URL** (no una fila por tenant). La sección 3.3 ("url única") y la 3.1 ("evidencia con prefijo `tenants/{tenant_id}/evidence/`") eran ambiguas entre sí sobre esto; se resolvió a favor de la lectura literal de "url única". El aislamiento de tenant en el pipeline de screening vive en `mentions`/`matches`, no en `articles`.

### Contexto importante para retomar (acumulado, no cronológico)
- **Git:** estaba instalado en el sistema (`C:\Program Files\Git\bin\git.exe`) pero no en el PATH de la sesión de PowerShell. Cada invocación de PowerShell de esta herramienta arranca un proceso nuevo que **no** hereda el PATH refrescado — anteponer `$env:Path += ";C:\Program Files\Git\bin"` en cada comando que use `git`, hasta que el usuario reinicie su entorno/terminal real.
- Repositorio en `D:\usuario\2026\VERA` con ramas `main` y `develop` (convención de la sección "Git").
- Se corrigieron `backend/CLAUDE.md` y `backend/AGENTS.md`: el scaffold de Laravel Boost traía instrucciones automáticas para instalar PHP/Composer en el host, que contradicen la decisión confirmada de trabajar todo dentro de Docker Compose. Ambos archivos ahora solo remiten a este `CLAUDE.md` raíz y prohíben instalar PHP/Composer local.
- **Regla reforzada por el usuario (2026-09-13):** nada del producto (PHP, Composer, MariaDB, Meilisearch, Redis, etc.) se instala en la PC — todo se construye y corre dentro de Docker Compose.
- Ver la sección "## Estatus de sesión" al inicio del archivo para el detalle de la sesión más reciente y el pendiente activo.
