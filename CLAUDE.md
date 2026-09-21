# VERA — Verificación de Exposición a Riesgo Adverso

Plataforma SaaS multi-tenant de *adverse media screening* y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador), con expansión prevista a Guatemala, Honduras y Costa Rica.

Este archivo es la fuente de verdad para Claude Code. Léelo completo antes de cualquier tarea. No cambies stack, librerías ni decisiones de arquitectura sin confirmación explícita del propietario.

---

## Estatus de sesión

**Última actualización:** 2026-09-14 15:26

### En qué estábamos
Sesión de continuación: el usuario dio credenciales reales de Google CSE y Anthropic. Se conectaron, se construyó el primer flujo de Fase 1 que faltaba (**consulta puntual por HTTP**, nada la disparaba todavía), se corrió el pipeline contra las APIs reales por primera vez, y en el proceso salieron dos bugs reales y un hueco de seguridad de costos que ya quedaron corregidos y con tests. **60 tests pasan.** Contenedores Docker quedan **detenidos** al cerrar esta sesión (pedido explícito del usuario) — todo lo de abajo asume que hay que levantarlos de nuevo para retomar.

### Qué se completó en esta sesión
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

**Modelos de negocio (sección 3.3)**
- Con `tenant_id` (vía `BelongsToTenant`, algunos también `DerivesTenantFromSubject` porque cuelgan de un `Subject`): `subjects`, `subject_aliases`, `sanction_matches`, `search_runs`, `matches` (modelo `MentionMatch`, no `Match` — palabra reservada en PHP).
- Globales (sin `tenant_id`, catálogo o contenido no atribuible a un tenant): `sources`, `sanction_lists`, `sanction_entries`, `articles`, `mentions`, `extractions`.
- Pendiente de sección 3.3: nada más — todo lo de Fase 1 está creado.

**Pipeline (sección 3.4)**
- `RunSubjectSearchJob`: query con nombre canónico + aliases → `GoogleCseAdapter` → `search_runs` → encola `FetchArticleJob` por URL. Idempotente (no repite la búsqueda del mismo subject+source el mismo día). `GoogleCseAdapter` impone tope duro de `GOOGLE_CSE_DAILY_LIMIT` con contador atómico en cache antes de llamar a Google — ver "Qué se completó en esta sesión".
- `FetchArticleJob`: descarga, extrae fecha (meta/JSON-LD/`<time>`, fallback genérico — selectores por medio son Fase 0), hash SHA-256, guarda evidencia en `Storage` (disco `r2` en prod, `local` en dev), descarta si está fuera de `ARTICLE_WINDOW_DAYS`. Encola `ExtractEntitiesJob`.
- `ExtractEntitiesJob`: limpia HTML, prompt versionado (`resources/prompts/extraction/v1.md`), llama a Haiku, valida con DTOs de `spatie/laravel-data` (`rol` es un enum PHP real — rechaza valores fuera del contrato antes de tocar la BD), escala a Sonnet si baja confianza o roles cruzados, crea `mentions`, encola `MatchMentionsJob`. Idempotente; `failed()` marca `estado_extraccion = fallido`.
- `MatchMentionsJob`: recorre todos los tenants (`tenancy()->runForMultiple()`), busca en Meilisearch (Scout, `Subject` es `Searchable`) filtrado por `tenant_id`, guarda cada hit en `matches` como `pendiente`. **Umbral/rarity gate sin calibrar a propósito** (sección 9 — "hasta entonces todo match es pendiente", textual).
- `ImportSanctionListsJob`: solo OFAC SDN tiene adaptador (URL pública verificada). ONU/UE sin adaptador — sus URLs candidatas no resolvieron (404/403), hace falta confirmar las vigentes. Idempotente, transaccional, upsert por lotes.

**Terceros — todo el código listo, solo faltan credenciales**
- `GOOGLE_CSE_API_KEY`/`GOOGLE_CSE_CX` (Google CSE) y `ANTHROPIC_API_KEY` (Anthropic) vacías en `backend/.env`. Nada quedó a medias esperándolas: la lógica está 100% testeada con fakes/fixtures.
- `costo` en `search_runs`/`extractions` queda `null` a propósito (Google CSE no da costo por request; Anthropic necesita tarifa real vigente, no inventada).

**API HTTP (Fase 1, sección 5 — "consulta puntual")**
- `POST /api/subjects/{subject}/buscar` y `GET /api/subjects/{subject}/matches` (`SubjectController`), primer flujo de Fase 1 que dispara el pipeline desde fuera de tinker. Ver "Qué se completó en esta sesión".
- `php artisan vera:demo "Nombre"` — bootstrap de datos de prueba (tenant + usuario + token + source + subject) para probar por HTTP sin frontend.

**Documentación**
- `docs/MANUAL_TECNICO.md`: manual completo de producción (estructura de Regla 6), con guía paso a paso de Google CSE/Anthropic/R2/SMTP/Sheets. Marca como pendiente Gotenberg vs. Cloudflare Browser Rendering (sección 2 no lo resuelve).

### Bugs/riesgos reales encontrados y corregidos (histórico — el más reciente arriba)
- **Sin tope de cuota diaria de Google CSE (2026-09-14, tarde):** ver "Qué se completó en esta sesión" — nada hacía cumplir `GOOGLE_CSE_DAILY_LIMIT` y el endpoint de consulta puntual ya podía dispararse repetidamente. Corregido con contador atómico en `GoogleCseAdapter`.
- **`ANTHROPIC_MODEL_FAST` con id de modelo inválido (2026-09-14, tarde):** apuntaba a `claude-haiku-4-5` sin fecha, no es un id real de la API — toda llamada real habría fallado. Corregido a `claude-haiku-4-5-20251001`.
- **IDOR entre tenants vía route-model-binding**: `SubstituteBindings` corría antes que el middleware `tenant`. Fix con `$middleware->prependToPriorityList()` en `bootstrap/app.php` — cubre cualquier modelo futuro, no solo `subjects`.
- **Fuga cross-tenant vía superadmin**: el primer diseño del bypass lo dejaba pasar por rutas de tenant sin inicializar tenancy, viendo todo mezclado. Ahora superadmin recibe 403 en esas rutas — su alcance no incluye datos de negocio de un tenant.
- **Bypass de tenant en `DerivesTenantFromSubject`**: la primera versión usaba `Subject::withoutTenancy()->find()`, que permitía referenciar el `subject_id` de OTRO tenant y atribuirle el hijo silenciosamente. Ahora usa `Subject::findOrFail()` normal (con su scope) — falla cerrado.
- **Pipeline no conectado**: cada job pasaba sus tests aislados pero `FetchArticleJob` nunca disparaba `ExtractEntitiesJob` — un artículo se quedaba en `pendiente` para siempre. Ya conectado.
- **Incidente de un subagente de `/code-review`**: borró de la working tree real trabajo legítimo de la sesión (pensó que eran restos propios) y revirtió una config. Se detectó porque el propio reporte lo confesó; se reconstruyó todo. **Corre `git status` después de cada `/code-review`, no confíes solo en su reporte de hallazgos.**
- Detalle completo de cada ronda de code-review (idempotencia de jobs, transacciones, timeouts de Horizon, etc.) vive en el historial de commits/PRs una vez que se comitee — no se repite aquí para no inflar este archivo.

### Cómo probarlo en desarrollo (con credenciales reales — ya cargadas)
1. `docker compose up -d` (raíz del proyecto) — quedaron detenidos al cerrar la sesión anterior.
2. `docker exec vera_api php artisan vera:demo "Nombre a buscar"` — imprime tenant/usuario/token/source/subject y los `curl` listos para copiar.
3. `curl -X POST .../api/subjects/{id}/buscar` con el token impreso, esperar unos segundos, luego `curl .../api/subjects/{id}/matches`.
4. Si algo falla en la llamada real a Google/Anthropic (no en el código): `docker exec vera_api php artisan queue:failed` para ver la excepción real capturada.
5. **Importante:** si se rota alguna API key en el proveedor (Google/Anthropic) con los contenedores ya corriendo, hace falta `docker compose restart api` — Horizon carga el `.env` una sola vez al arrancar el worker, no relee cambios en caliente.

### Pendiente / próximo paso
- [ ] **Vincular facturación en Google Cloud** al proyecto dueño de `GOOGLE_CSE_API_KEY` (https://console.cloud.google.com/billing/linkedaccount) — confirmado con el usuario que hoy NO está vinculada. Sin esto, Google responde `403 PERMISSION_DENIED: "This project does not have the access to Custom Search JSON API"` aunque la API figure habilitada y la key sea válida (no cobra dentro de la cuota gratis de 100/día, pero Google exige la cuenta vinculada para servir la API). Confirmado que no es propagación (se probó 6 veces en 3 min tras habilitar la API, siempre 403).
- [ ] Una vez vinculada la facturación: reprobar la key directo, `docker exec vera_api php artisan queue:retry all` (ya hay un `RunSubjectSearchJob` fallido en cola del subject de prueba `Juan Carlos Pérez`), y revisar `search_runs`/`matches`.
- [ ] Adaptadores de ONU consolidada y UE — falta confirmar la URL pública vigente de cada una.
- [ ] Enriquecer `OfacSdnAdapter` con `alt.csv`/`add.csv` (aliases, país) — hoy vacíos.
- [ ] Costo real por request/token de Google CSE y Anthropic — falta la tarifa vigente.
- [ ] Índice de Meilisearch de test comparte instancia con dev (bajo impacto, cada test usa `tenant_id` nuevo).
- [ ] `DatabaseSeeder` usa `WithoutModelEvents` — si algún día se siembran modelos con `BelongsToTenant`/`DerivesTenantFromSubject` ahí, no se ejecutarían esos hooks. No es problema hoy.
- [ ] Frontend: sigue siendo solo scaffold (Vite+React+TanStack), sin pantallas funcionales.

### Contexto importante para retomar
- **Contenedores quedaron detenidos** al cerrar esta sesión (`docker compose stop`, pedido explícito del usuario) — los datos persisten (volúmenes `mariadb_data`/`meilisearch_data` intactos, no se hizo `down -v`). Levantar con `docker compose up -d`. API `:8000`, Meilisearch `:7700`, MariaDB `:3306`, Redis `:6379`. Tests: `docker exec vera_api php artisan test`.
- **Si Docker Desktop mismo no está corriendo** (no solo los contenedores): esta sesión tuvo que lanzarlo dos veces desde `C:\Program Files\Docker\Docker\Docker Desktop.exe` porque se cayó a mitad de sesión sin causa clara — si al levantar da error de "daemon not running", lanzar Docker Desktop y esperar antes de `docker compose up -d`.
- El tenant/usuario/subject/source que dejó `vera:demo` esta sesión (`Juan Carlos Pérez`) siguen en la BD real (persistente), pero el **token Sanctum no quedó guardado en ningún lado** — Sanctum solo lo muestra una vez al crearlo. Para retomar la prueba: correr `vera:demo` de nuevo (crea un tenant/usuario nuevo, no rompe nada) o generar un token nuevo para el usuario existente por tinker.
- **Patrón a repetir:** un modelo con `tenant_id` propio que cuelga de un `Subject` usa `App\Models\Concerns\DerivesTenantFromSubject` — nunca `withoutTenancy()` para esto, debe fallar cerrado. Un modelo de negocio nuevo expuesto por route-model-binding ya queda protegido por el fix de prioridad de middleware, no hace falta repetirlo.
- **Patrón a repetir:** cualquier llamada a un servicio externo de pago por uso (Google CSE hoy; si se agrega otro proveedor con cuota/tarifa después) debe tener su propio candado de cuota en el punto exacto donde se hace el HTTP call, no confiar en que el caller de arriba lo respete — ver `GoogleCseAdapter::reservarCupoDiario()`.
- El usuario prefiere que las sub-decisiones ya cubiertas por un criterio explícito se resuelvan directo, sin volver a preguntar — solo preguntar cuando no hay default razonable o la decisión toca auth/BD (ahí sí, plan + confirmar). Ver memoria `feedback-stop-asking-confirm-and-proceed`.
- **No auto-bloquearse por una credencial faltante**: si una tarea tiene partes que no la necesitan, seguir con esas en vez de parar todo. Ver memoria `feedback-dont-self-block-decompose`.
- El archivo espurio `backend/vera` (SQLite) reapareció dos veces esta sesión sin que corriera ningún `/code-review` — la causa documentada (subagente de code-review) no aplica esta vez; origen real todavía sin confirmar (sospecha: algo del editor/IDE corriendo artisan fuera de Docker). Se borra cuando aparece, no es dañino.
- Sin línea `Co-Authored-By` en los commits de este proyecto — el usuario lo pidió explícitamente.
- Detalle técnico de Git (PATH de PowerShell) y decisiones de la sesión del 2026-09-12: ver sección 10 más abajo.

### Archivos tocados en esta sesión
- `backend/.env`, `backend/config/services.php` — credenciales reales + fix del id de modelo de Anthropic.
- `backend/app/Sources/GoogleCseAdapter.php` — candado de cuota diaria.
- `backend/app/Actions/Subjects/IniciarConsultaPuntual.php` (nuevo), `backend/app/Http/Controllers/SubjectController.php`, `backend/app/Policies/SubjectPolicy.php`, `backend/routes/api.php` — endpoint de consulta puntual.
- `backend/app/Console/Commands/CrearDemo.php` (nuevo) — comando `vera:demo`.
- `backend/tests/Feature/SubjectSearchEndpointTest.php` (nuevo), `backend/tests/Unit/GoogleCseAdapterTest.php` (2 tests nuevos), `backend/tests/Feature/ExtractEntitiesJobTest.php` (2 asserts corregidos).
- Sesión anterior (2026-09-14 mañana), sin cambios adicionales esta sesión: prácticamente todo `backend/app/{Models,Jobs,Http,Sources,Services,Data,Enums,Policies,Actions}`, `backend/database/{migrations,factories,seeders}`, `backend/config/{tenancy,scout,services,filesystems,vera}.php`, `backend/tests/`, `backend/resources/prompts/`, `docs/MANUAL_TECNICO.md`. Para el detalle exacto archivo por archivo, `git status`/`git diff` en la raíz del repo es más confiable que mantener una lista manual aquí.

---

## 1. Propósito del producto

Permitir que un oficial de cumplimiento:

1. Consulte puntualmente si una persona (natural o jurídica) aparece en medios de comunicación salvadoreños y fuentes oficiales vinculada a procesos judiciales (hurto, estafa, extorsión, lavado, narcotráfico, corrupción, etc.).
2. Mantenga una lista de vigilancia (clientes, empleados, proveedores) con monitoreo continuo y alertas por coincidencias nuevas.
3. Cruce nombres contra listas de sanciones internacionales (OFAC SDN, ONU consolidada, UE).
4. Registre evidencia auditable (snapshot + hash + timestamp) y la resolución humana de cada coincidencia (confirmada / falso positivo / homónimo).
5. Genere reportes exportables por persona, periodo y nivel de riesgo.

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
- React 19 + Vite + TypeScript (strict)
- Tailwind CSS v4
- shadcn/ui
- TanStack Query (estado de servidor), TanStack Router o React Router (confirmar antes de elegir)
- Despliegue estático en Cloudflare Pages

### Servicios externos
- Google Custom Search JSON API (`cx` multi-sitio de medios salvadoreños)
- Anthropic Claude API: `claude-haiku-4-5` para extracción masiva; `claude-sonnet-5` para escalado por baja confianza
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
sources                    (medio/fuente: nombre, tipo [cse|rss|oficial|sanciones], config, activo, global)
search_runs                (ejecución de búsqueda: subject_id, source_id, query, resultados, costo)
articles                   (url única, título, medio, fecha_publicacion, hash_contenido,
                            evidence_path, estado_extraccion)
extractions                (article_id, modelo, json_resultado, confianza, tokens_in/out, costo)
mentions                   (persona detectada en un artículo: article_id, nombre_extraido,
                            rol [imputado|condenado|víctima|testigo|otro], delitos[], confianza)
matches                    (mention_id, subject_id, score_meilisearch, estado
                            [pendiente|confirmado|falso_positivo|homonimo], resuelto_por, resuelto_en)
sanction_lists             (ofac_sdn, un_consolidated, eu; versión, fecha_importación)
sanction_entries           (lista, nombre, aliases[], tipo, programa, país, raw_json)
sanction_matches           (subject_id, sanction_entry_id, score, estado, resolución)
alerts                     (tenant_id, tipo, referencia polimórfica, canal, enviado_en)
reports                    (tenant_id, tipo, parámetros, generado_por, path)
activity_log               (spatie)
```

### 3.4 Pipeline (jobs en Horizon)

Colas y prioridad: `alerts` > `matching` > `extraction` > `fetch` > `search` > `imports`.

1. `RunSubjectSearchJob` — por subject y source: construye query (nombre canónico + aliases), llama Google CSE o RSS, persiste `search_runs`, encola `FetchArticleJob` por cada URL nueva.
2. `FetchArticleJob` — descarga HTML, extrae `fecha_publicacion` de metadatos (`article:published_time`, JSON-LD, `<time>`), calcula SHA-256, sube snapshot a R2, persiste `articles`. Descarta artículos fuera de la ventana configurada.
3. `ExtractEntitiesJob` — envía texto limpio a Claude Haiku con esquema JSON estricto; valida con `spatie/laravel-data`; persiste `extractions` y `mentions`. Si `confianza < umbral` → reencola con Sonnet 5.
4. `MatchMentionsJob` — por cada mention, consulta Meilisearch (índice `subjects`, filtro `tenant_id`) con tolerancia a errores; aplica normalización (unaccent, minúsculas, orden de tokens) y *rarity gate* por frecuencia de apellidos; persiste `matches` en estado `pendiente`.
5. `SendAlertJob` — correo (y Google Sheets si está configurado) al oficial de cumplimiento del tenant.
6. `ImportSanctionListsJob` — semanal; descarga OFAC SDN (CSV/XML), ONU consolidada (XML), UE (XML); reindexa en Meilisearch (`sanction_entries`); ejecuta `MatchSanctionsJob` para todos los subjects activos.

Scheduler:
- Monitoreo continuo diario, priorizado por `nivel_riesgo` para no agotar la cuota de Google CSE (100 consultas/día gratis).
- Rate limiter global para Google CSE configurable por entorno.
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

---

## 4. Fuentes iniciales

Medios (vía Google CSE, un solo `cx` multi-sitio):
- laprensagrafica.com
- elsalvador.com
- diarioelmundo.com
- lapagina.com.sv
- diario1.com
- elmundo.sv
- (ampliar tras la prueba de cobertura)

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
1. Crear `cx` de Google CSE con los medios listados. Ejecutar 20 nombres de casos judiciales recientes conocidos; medir recall y latencia de indexación.
2. Validar extracción de `fecha_publicacion` en 30 artículos de los 6 medios; documentar selectores por medio.
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
GOOGLE_CSE_API_KEY, GOOGLE_CSE_CX, GOOGLE_CSE_DAILY_LIMIT=100
ANTHROPIC_API_KEY, ANTHROPIC_MODEL_FAST=claude-haiku-4-5, ANTHROPIC_MODEL_ESCALATION=claude-sonnet-5
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
- Cuota Google CSE: el scheduler nunca debe superar el límite diario configurado; excedentes se difieren al día siguiente por prioridad.
- Router del frontend y generador OpenAPI: confirmar antes de instalar.
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
- [ ] `webapp-testing` — activar cuando existan 3+ módulos funcionales.
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
