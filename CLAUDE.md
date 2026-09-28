# VERA — Verificación de Exposición a Riesgo Adverso

Plataforma SaaS multi-tenant de *adverse media screening* y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador), con expansión prevista a Guatemala, Honduras y Costa Rica.

Este archivo es la fuente de verdad para Claude Code. Léelo completo antes de cualquier tarea. No cambies stack, librerías ni decisiones de arquitectura sin confirmación explícita del propietario.

---

## Estatus de sesión

**Última actualización:** 2026-09-28 (bloque 22: code-review de protección de datos corregido + **PDF de evidencia** + **reportes de auditoría**; 378 tests backend pasan; comiteado en `develop`, sin push. Bloque 21: **protección de datos completa — bloques A, B, C y D implementados** + panel de superadmin con menú lateral; comiteado en `develop`, sin push; 350 tests backend pasan)

### En qué estábamos
**Sesión del 2026-09-28 (vigésimo segundo bloque — code-review + PDF de evidencia + reportes):** el usuario pidió, en orden: `/code-review high` de la protección de datos, reportes de auditoría exportables y PDF de evidencia. Decisiones del usuario: **dompdf** (no Gotenberg ni Cloudflare), **CSV** (no .xlsx) y plan confirmado.
- **Code-review (`ca63bfd`), 10 hallazgos, todos corregidos con test que fallaba antes:** (1) `each()` pagina por offset y al borrar mientras recorre se salta filas → `lazyById()` en `DepurarDatosVencidosJob` y `DarDeBajaTenant` (`$lote` público para testear). (2-3) borrar/exportar una persona ignoraba capturas manuales hechas desde búsqueda por tags → `App\Services\ProteccionDatos\ResultadosDePersona::ids()` (compartido). (4) exportación de tenant cargaba todos los PDF en memoria → `App\Support\ArchivoZip` (copia por stream + `addFile`, borra temporales siempre). (5) `catch (RuntimeException)` disfrazaba fallos de BD (QueryException es RuntimeException) como 422 → `App\Exceptions\OperacionNoPermitida extends DomainException`. (6) job de depuración con timeout 3600. (7) `tenant_exportado` se registraba antes de entregar el ZIP → `App\Support\DescargaZip`: registra solo si `readfile` terminó sin `connection_aborted()`, y borra el temporal siempre (`register_shutdown_function`). (8) `Activity::tenantDe` toma el `tenant_id` del modelo auditado (superadmin creando el admin de un tenant). (9) ZIP temporales con datos personales podían quedar en /tmp → (4)+(7). (10) N+1 en listado de tenants → `EstadoDocumentosLegales::alDiaPorTenant()` (una consulta agrupada).
- **PDF de evidencia (`a195575`, `c51b272`, sección 3.6):** `GET /api/resultados/{id}/evidencia/pdf` → portada (URL, fecha de publicación, captura UTC, hash SHA-256 y **verificación de integridad** re-hasheando el snapshot guardado) + texto principal del artículo (`TextoDeEvidencia`: usa `<article>`/`<main>` si existen, descarta nav/header/footer/aside/form/scripts; el texto se imprime escapado, nunca se renderiza el HTML de terceros). Botón "Descargar PDF" en cada resultado con snapshot. Plantilla común `resources/views/pdf/layout.blade.php`.
- **Reportes (`d6e0f28`, `91d1aac`, `62ff561`, sección 1 punto 5):** tabla `reports` (tenant, tipo, formato, parámetros, estado pendiente|generando|listo|fallido, `personas` json, archivo en `tenants/{id}/reportes/`). Tipos: **ficha de persona** (reusa `ExportarSubject::datos()`), **actividad de un periodo** (máx. 1 año: coincidencias y sanciones resueltas, consultas, búsquedas por tags, extracciones, seguimientos), **lista por nivel de riesgo** (con conteos por estado). PDF o CSV (BOM UTF-8, **neutraliza fórmulas** `= + - @`). `GenerarReporteJob` (cola `imports`, timeout 900). Todos los roles del tenant (incl. `lectura`) generan y descargan; solicitud y descarga a la bitácora. Lo no resuelto = "Pendiente de resolución" + aviso en cada PDF. `BorrarSubject` borra los reportes que incluyen a la persona (la ficha registra la persona desde que se solicita). Pantalla `/reportes` (menú para todos), consulta cada 3 s mientras se generan. Verificado en navegador con el trabajador real de Horizon (3 tipos generados y descargados).
- **Gotchas:** (1) El cast `FechaSinHora` devuelve medianoche UTC: formatearla con zona horaria muestra el día anterior — en PHP usar `format('d/m/Y')` sin convertir (`ConstructorReportes::diaCalendario`). (2) Python en heredoc de Git Bash pierde las `\` de los namespaces (`App\Exceptions` → `Appxceptions`): editar PHP con Edit o con scripts `.py` en archivo. (3) Sin visor de PDF en la máquina (no hay poppler): revisar diseño renderizando la misma vista Blade en Chromium. (4) Una prueba de conteo de consultas puede pasar por coincidencia (el primer request tiene consultas únicas): contar solo la consulta que interesa.
- `lang/es/validation.php`: agregados `date_format` y `after_or_equal` (la bitácora ya los usaba sin mensaje).

**Sesión del 2026-09-28 (vigésimo primer bloque — sección 3.9 terminada):**
- **Panel de superadmin reorganizado** (pedido del usuario: "la bitácora debe ser opción de menú" y la pantalla de OFAC "no tan vulgar"): layout propio `routes/superadmin/route.tsx` + `components/layout/SuperadminShell.tsx` con menú lateral — Tenants, Listas de sanciones (estado de la última importación, versión, entradas; modo de descarga como opciones descritas), Términos y contratos, Bitácora. Acciones por tenant en un menú "Opciones" (Sanciones, depuración, exportar, dar de baja). `GET /superadmin/configuracion-sanciones` ahora devuelve `lista` (null si nunca se importó).
- **Bloque B — términos y contrato** (`8cfea3c`, `f264f76`): `documentos_legales` (global, tipo `terminos|contrato_encargo`, versión null = borrador, publicado = inmutable) y `aceptaciones_documentos` (por tenant: quién, cuándo, IP). Superadmin redacta/publica en `/superadmin/documentos`; admin acepta en `/documentos` (casilla + botón); aviso en `AppShell` para todos mientras haya pendientes (`/api/user.terminos_pendientes`). Middleware `documentos` (`EnsureDocumentosAceptados`) bloquea con 403 `codigo: terminos_pendientes`: alta de persona, alias, consulta puntual, búsqueda por tags, extraer, captura manual, cruce de sanciones. **Sin documentos publicados no bloquea nada** (en producción hay que publicarlos antes del primer cliente). Tenants del superadmin muestran `documentos_al_dia`.
- **Bloque C — retención, depuración, exportación y borrado** (`081bba3`, `63f1257`): `subjects.desactivado_en` (hook del modelo; relleno con `updated_at` para las ya inactivas). Retención por tenant `retencion_anios` (admin, `/configuracion`, mín. 15 máx. 100, auditado `retencion_cambiada`); depuración `depuracion_habilitada` (superadmin, con confirmación, auditada `depuracion_cambiada`). **`App\Actions\ProteccionDatos\BorrarSubject` es el único punto de borrado:** borra persona, aliases, coincidencias, sanciones, menciones manuales, resultados y búsquedas (la query lleva el nombre), alertas, índice de Meilisearch y evidencia manual (después del commit); conserva artículos y menciones automáticas (globales, solo pierden el enlace); vacía `attribute_changes`/`properties` de sus registros de bitácora y agrega `persona_eliminada` con el motivo. `DELETE /api/subjects/{id}` (admin, confirmando el nombre) y `GET /api/subjects/{id}/exportar` (admin, ZIP con `persona.json` + PDFs), en la ficha. `DepurarDatosVencidosJob` diario 04:00 El Salvador (inactivas con `desactivado_en` vencido; solo tenants habilitados; registra `depuracion_ejecutada` solo si borró algo).
- **Bloque D — baja de tenant** (`fcebcac`, `689c341`): `GET /api/superadmin/tenants/{id}/exportar` (ZIP: `tenant.json`, `personas/{id}/`, `busquedas-por-tags.json`, `bitacora.json`; registra `tenant_exportado`) — **único caso en que el superadmin maneja datos personales** (devolución al cliente). `POST .../baja` exige exportación en los últimos 7 días y confirmar el nombre; borra todo (personas vía `BorrarSubject`, búsquedas por tags, usuarios con tokens/sesiones/roles, configuración, evidencia `tenants/{id}/`, bitácora) y deja solo `tenant_dado_de_baja`. No es una sola transacción: idempotente, si falla a mitad se repite. Verificado contra MariaDB real y en navegador.
- **Hallazgo preexistente, NO corregido (anotado en Pendiente):** el `Tenant` de `stancl/tenancy` solo trata `id` como columna real; `name` y `sanciones_habilitado` (y ahora `retencion_anios`/`depuracion_habilitada`) se guardan en el JSON `data`, y las columnas reales `name`/`sanciones_habilitado` quedan siempre vacías. Nada las consulta por SQL, así que funciona. Los ajustes nuevos no tienen columna a propósito (defaults en `App\Support\ConfiguracionTenant`); **nunca filtrar tenants por esos atributos en SQL**.
- **Gotchas:** (1) Git Bash reescribe rutas como `/tmp` en `docker cp`/`docker exec` → `export MSYS_NO_PATHCONV=1`. (2) En Playwright, los avisos de Sonner son `<li>`: un `locator('li', {hasText})` los encuentra también. (3) Docker Desktop reinició su motor solo a mitad de la sesión ("Bad response from Docker engine"); se recuperó sin intervención. (4) Un scope `orderByRaw('version is null desc')` es portable SQLite/MariaDB.
- Datos de dev: sin documentos legales publicados (se limpiaron tras el QA para no bloquear al tenant sin admin); retención del tenant demo restaurada a 15.

**Sesión del 2026-09-28 (vigésimo bloque — protección de datos: decisiones + bloque A de 4):**
- **Decisiones del usuario sobre quién controla cada función de la 3.9** (tabla en la sección 3.9): términos/contrato los edita el superadmin y los acepta el admin del tenant; **plazo de retención lo configura el admin de cada tenant** (piso 15 años); job de depuración lo habilita/deshabilita el superadmin (por tenant, criterio por defecto); exportación/borrado de una persona = admin del tenant; baja de tenant = superadmin; bitácora la consultan el admin (su tenant, completa) y el superadmin (**todos los tenants, sin datos personales — opción A**).
- **Plan de 4 bloques presentado y confirmado** ("procede"): A bitácora → B términos y aceptación → C retención, depuración, exportación/borrado de persona → D baja de tenant. Criterios por defecto aceptados: retención cuenta desde `desactivado_en` (activas nunca se depuran); depuración por tenant; sin aceptar términos se bloquea crear/buscar (consultar sigue); al borrar una persona, sus registros de bitácora se conservan sin datos personales. **No se registra abrir la ficha** (recomendación adoptada; el usuario no la objetó).
- **Bloque A implementado** (commits `beb33c7` backend, `b1b49a1` frontend): `activity_log.tenant_id` (migración con relleno portable; 24 registros viejos de datos QA borrados quedan sin tenant), `App\Models\Activity` (asigna tenant al crear; **sin** `BelongsToTenant` a propósito, toda consulta filtra explícito), `App\Support\RegistroDeAccesos` (consulta puntual, búsqueda por tags, extracción, descarte, descarga de evidencia, cruce de sanciones, login/logout), `GET /api/bitacora` (+`/eventos`, gate `ver-bitacora-tenant` = admin), `GET /api/superadmin/bitacora` (+`/eventos`, `SerializadorBitacora::sinDatosPersonales`: nunca descripción/cambios/propiedades). Frontend: `/bitacora` (admin) y tarjeta en `/superadmin`. 20 tests nuevos; verificado con Playwright (11/11) en los 3 perfiles, sin nombres de personas en la vista del superadmin.
- **Gotchas:** (1) `superadmin()` helper movido a `tests/Pest.php`. (2) El login tiene `throttle:5,1` por IP: scripts de Playwright con varias sesiones seguidas chocan con él — esperar ~60 s entre corridas. (3) Chromium en caché es `chromium-1217`; el paquete `playwright` nuevo pide otra build — lanzar con `executablePath: %LOCALAPPDATA%/ms-playwright/chromium-1217/chrome-win64/chrome.exe` en vez de descargar. (4) Se creó `qa-superadmin@vera.test` / `Demo1234!` (no había ningún superadmin en la BD de dev).
- **Próximo: bloque B** (términos y aceptación) — `documentos_legales` + `aceptaciones_documentos`, edición/publicación por superadmin, aceptación por admin, bloqueo de crear/buscar sin aceptación.

**Sesión del 2026-09-28 (decimonoveno bloque — plazo de retención AML + termina la interfaz de superadmin):** el usuario pidió "lo que más convenga" para el plazo de retención y que se terminara la interfaz de superadmin.
- **Plazo de retención AML verificado leyendo el texto real de la ley (no de terceros):** `Art. 26` de la Ley Contra el Lavado de Dinero y de Activos, **Decreto 426** (reforma vigente — el Decreto 498 original decía 5 años, pero quedó superado): **"Los sujetos obligados deben mantener por un período no menor de quince años los registros..."** Dos plazos, ambos de 15 años, con arranque distinto: transacciones/documentación desde que termina cada operación; datos de identificación del cliente desde que termina la relación comercial. Corregido en la sección 3.9 y en la sección 9 (antes decía "GAFI exige mínimo 5 años", un dato de terceros que resultó obsoleto). **No se implementó la función de retención/depuración en sí** (borrado real de datos de clientes) — eso sigue requiriendo su propio plan y confirmación antes de codificar, como ya decía la sección 3.9.
- **Interfaz de superadmin completada:** hasta hoy solo podía activar/desactivar Sanciones y el modo de descarga de OFAC; crear o renombrar un tenant todavía requería tinker. Ahora `POST /api/superadmin/tenants` crea el tenant junto con su primer usuario admin en una sola operación (reutiliza `App\Actions\Usuarios\CrearUsuario`, la misma Action de `UsuarioController::store`) — un tenant sin ningún usuario es inútil, y no hay forma circular de crear el primer usuario después (`POST /api/usuarios` exige ya estar autenticado *dentro* de un tenant). `PATCH /api/superadmin/tenants/{tenant}` ahora también acepta `name` (antes solo `sanciones_habilitado`). Frontend: botón "Nuevo tenant" con diálogo (nombre + datos del primer admin) y renombrado inline por fila, mismo patrón visual que el catálogo de tags.
- **Gotcha de test:** `comoFrontend()` (bypassa CSRF + header de referer para probar `POST /api/login` en un test) vivía duplicada solo en `AuthTest.php` — las funciones de un archivo de test SÍ están disponibles en otros (Pest los corre en el mismo proceso), pero solo si el archivo que las define ya se cargó antes; para evitar ese acoplamiento entre archivos se subió a `tests/Pest.php`, mismo criterio que `usuarioDeTenant()`.
- **Verificado con datos reales contra MariaDB:** alta de un tenant con su admin, el admin nuevo inició sesión de inmediato, renombrado del tenant — todo limpiado después (tenant, usuario y sus filas de `activity_log`).
- 290 tests pasan (282 + 8 nuevos). Comiteado backend+frontend+docs.

**Sesión del 2026-09-28 (decimoctavo bloque — decisión de protección de datos, SIN código):** confirmado por el usuario: **la carga legal del tratamiento de datos personales recae en el cliente, no en VERA.** Esquema adoptado bajo la Ley para la Protección de Datos Personales de El Salvador (Decreto n.º 144, vigente desde el 28-nov-2024, supervisada por la Agencia de Ciberseguridad del Estado): **cliente (sujeto obligado AML) = responsable del tratamiento; VERA = encargado del tratamiento.** Especificación en la **sección 3.9** (nueva). Se aclaró que una política de privacidad por sí sola no basta: las personas vigiladas nunca ven la política de VERA, y retención, derechos ARCO, brechas y transferencias se cumplen con contratos y funciones del sistema. Nada de esto está implementado todavía. **Sin decidir:** validación legal de la plantilla contractual, y respuesta de Brave sobre almacenamiento (opción 2 de minimización registrada en 3.9 como alternativa si Brave lo niega). ~~Plazo de retención AML exacto~~ — resuelto en el bloque 19 (arriba).

**Sesión del 2026-09-28 (bloque intermedio — Fase 0: comillas + informe parcial, SIN bloque propio documentado en su momento):** dos ítems de la Fase 0 (sección 5) cerrados a partir de "REALIZAR" del usuario: (A) `LimiteDeQuery::armar()` ya no envuelve los términos en comillas — dos hallazgos ya documentados en sesiones previas (comillas + `site:` devolvía 0 con contenido real existente; Brave ya devolvía `cleaned_query` sin las comillas enviadas) apuntaban a que era más riesgo que beneficio; verificado con 1 request real (query respetada tal cual, 20 hits reales). (B) Decisión del usuario: selectores de fecha por medio quedan **manuales**, sin automatizar por medio — se mantiene el mecanismo genérico ya implementado. (D) `docs/poc/informe-fase-0.md` creado: primer informe de Fase 0, documenta lo verificado (2/20 casos reales) y deja la Fase 0 explícitamente pendiente en sus 2 ítems más caros (campaña de 20 nombres, validación del prompt sobre 30 artículos — ninguno se ha corrido, ambos gastan cuota real). 282 tests pasan. Commits `2ac7d38` (código) y `3bd0ce8` (informe + CLAUDE.md).

**Sesión del 2026-09-28 (decimoséptimo bloque — code-review + Sanciones opcional por tenant + primer panel de superadmin):** dos partes.

**Parte 1 — corrección del `/code-review high` del bloque 16 (169f874..HEAD):** 8 agentes en paralelo, 16 hallazgos reportados (4 CONFIRMED de correctness, corregidos con test que falla sin el fix; 12 PLAUSIBLE de duplicación/eficiencia, también corregidos). Confirmados: (1) `ActualizarUsuario` cerraba TODAS las sesiones al restablecer la propia contraseña desde `/usuarios` — el admin se autodeslogueaba; ahora acepta `exceptoSesion` igual que `CuentaController::cambiarContrasena`. (2) `SancionController::cruzar` disfrazaba cualquier excepción (incluido un bug de código real) como "índice no disponible" 503 — ahora solo lo hace para `Meilisearch\Exceptions\ExceptionInterface`. (3) `config/cors.php` no exponía `Content-Disposition` — en un navegador real la descarga de evidencia perdía el nombre/extensión del archivo (verificado: falla sin el fix, pasa con él). (4) `CruceSanciones` llamaba `$subject->aliases()` (método, siempre reconsulta) en vez de `->aliases` (la relación), anulando el eager-load de `MatchSanctionsJob` — confirmado por 3 agentes independientes. Plausibles corregidos: `CruceSanciones` pasó de un request a Meilisearch por alias (hasta 21 por subject) a un solo `multiSearch()`; score de Meilisearch extraído a `PuntajeMeilisearch` (compartido con `MatchMentionsJob`); `SancionController` extraído a `ListarSanciones`/`SerializadorSancion`; 7 helpers de test duplicados consolidados en `usuarioDeTenant()` (`tests/Pest.php`); `activo` centralizado en el middleware `EnsureUserIsActive` (antes repetido en 3 sitios, `/cuenta` sin ninguno); mitigación XSS extraída a `App\Support\DescargaSegura`; `api.ts download()` ahora reusa el mapeo de errores de `request()` y ya no arriesga perder la descarga (ancla al DOM, revoca en el siguiente tick); buscador de personas de `CapturaManualDialog` extraído a `BuscadorSubjectAsync` + `useDebouncedValue`; regla de contraseña extraída a `CampoContrasena`. 268 tests (261+... ver commits `cf3abd7`/`eb279fa`).

**Parte 2 — Sanciones (OFAC) deshabilitada por defecto + primer panel de superadmin:** el usuario pidió que Sanciones no esté activa por defecto y que el superadmin decida, tenant por tenant, si la habilita — más un modo de descarga de la lista OFAC (manual/automático). Antes de codificar se detectaron y resolvieron 3 ambigüedades con el usuario (`AskUserQuestion`): el modo de descarga es **configuración global del superadmin** (la lista es un catálogo compartido, no tiene sentido un modo distinto por tenant); el habilitar/deshabilitar Sanciones **sí es por tenant**; y se construye una **pantalla real** de superadmin, no solo un endpoint (hoy no existía ningún panel — Fase 3 sin empezar).
- `tenants.name` (nueva, nullable) y `tenants.sanciones_habilitado` (bool, default **false**). `configuracion_sanciones` (fila única global, `modo_descarga_ofac` manual|automatico, default automatico, auditada). `TenantPolicy::gestionar` (solo superadmin) registrada a mano en `AppServiceProvider::boot()` — `Tenant` es de `stancl/tenancy`, el autodescubrimiento de policies no lo encuentra.
- `SuperadminController`: tenants (listar/togglear Sanciones), configuración de descarga, "actualizar lista ahora" (dispara el chain en cualquier modo). `ActualizarListaOfacProgramadaJob` (nuevo, reemplaza el `Schedule::call` anónimo) solo dispara la cadena semanal si el modo es `automatico` — testeable directo, a diferencia del closure anterior.
- `MatchSanctionsJob` salta tenants sin la función habilitada; `SancionController` responde 404 (no 403) en sus 3 endpoints si no está habilitada; `/api/user` expone `sanciones_habilitado` del tenant (para que el frontend oculte el menú sin pegarle al 404 real).
- **Bug real encontrado en MariaDB (no en SQLite, que no tipa estricto):** `activity_log.subject_id` nació `unsignedBigInteger` (todo lo auditado hasta ahora tenía PK entero) — auditar un `Tenant` (PK string/UUID) truena con "Data truncated". Migración que ensancha la columna a `string(36)`, reversible, probada rollback+migrate en MariaDB real.
- Frontend: `/superadmin` (pantalla nueva, fuera de `_authenticated` — superadmin no tiene tenant, se le redirige ahí al loguearse y desde cualquier otra pantalla); "Sanciones" oculto del menú/Inicio/ficha si no está habilitada; `/sanciones` muestra un mensaje amigable si se llega por URL directa estando deshabilitada.
- **Verificado con Playwright real (Node, instalado en el scratchpad — sin `pip` en esta máquina, así que no fue el `webapp-testing` de Python):** login superadmin → panel → habilitar Sanciones → login admin real → ítem "Sanciones" aparece, pantalla funciona → volver a deshabilitar → admin ya no la ve, URL directa muestra el mensaje → `analista` intentando `/superadmin` es redirigido a `/`. 0 errores de consola. Todo restaurado al estado original (tenant real sin Sanciones, modo automático) al terminar. **Gotcha real del script de prueba, no de la app:** un `waitForLoadState('networkidle')` tras un click de navegación de TanStack Router no espera el re-render de React — un screenshot tomado justo después puede mostrar la pantalla anterior aunque `page.url()` ya muestre la nueva ruta; hace falta un `waitForTimeout` adicional o esperar por contenido específico de la pantalla destino.
- **Limpieza:** se encontró y borró un `package-lock.json` vacío en la raíz del repo (residuo de un `npm run dev` corrido por error fuera de `frontend/`).
- Manual actualizado: nueva sección 7.1 (panel de superadmin), tabla de roles, tabla de schedule (sección 10), conteo de tests, estado del proyecto. También se corrigió ahí un punto ciego encontrado por el usuario: la sección 7 mencionaba "npm run dev" sin repetir `cd frontend`, y el usuario lo corrió desde la raíz por error.
- 282 tests pasan (268 + 14 nuevos). Comiteado en 4 commits: `2f2f5fa` (backend Sanciones+superadmin), `0a56306` (frontend), más los 2 de code-review antes (`cf3abd7`, `eb279fa`). Sin push.

<details><summary>Sesión anterior (bloque 16 — interfaz completa salvo móvil)</summary>
**Sesión del 2026-09-26 (decimosexto bloque — "terminar la interfaz", bloques 2 a 8 del plan; SIN COMITEAR):** plan presentado y confirmado. Decisiones del usuario: **navegación en móvil aplazada** (no le interesa por ahora), **sanciones sí se hacen** (había que construir el cruce, no existía), **usuarios: el admin crea usuario + contraseña; invitación por correo queda para cuando haya SMTP**. **261 tests backend pasan** (212 → 261). `tsc -b` y `oxlint` limpios (`npm run build` no corrido: dev server activo → OOM). **NO se probó en navegador**: el equipo no tiene Playwright ni `pip` (el `webapp-testing` corre con Python Playwright; no se instaló nada sin permiso). En su lugar: login real por cookies con curl contra MariaDB real y todos los endpoints nuevos respondieron bien (ojo: el token `XSRF-TOKEN` rota tras el login, releerlo en cada POST). **Toda esta sesión está en el árbol de trabajo sin comitear** (ver "Archivos tocados").
- **Node no está en el PATH del host:** `source ~/.nvm/nvm.sh` antes de `npx`/`npm` (v22).
- **Evidencia:** `GET /api/resultados/{id}/evidencia/{snapshot|manual}` (`SearchResultController::evidencia`). El snapshot HTML es contenido de terceros → se sirve SIEMPRE como adjunto `text/plain` + `nosniff` (XSS almacenado). Frontend: `api.download()` (fetch + blob, con cookies) y botones en `ResultadoCard` con el SHA-256. Test de acceso entre tenants.
- **Historial de auditoría:** `GET /api/subjects/{id}/historial` (`ListarHistorialSubject`): subject + coincidencias + aliases (los borrados se identifican por `attribute_changes->attributes|old->subject_id`, JSON path verificado en SQLite y MariaDB). Solo expone el nombre del usuario, nunca el correo. UI: `features/consulta/HistorialSubject.tsx` (colapsable, paginado) al final de la ficha.
- **Tags:** `GET /tags-busqueda?todos=1` + `PATCH /tags-busqueda/{tag}` solo `admin`/`oficial_cumplimiento` (`SearchTagPolicy::gestionar`); `SearchTag` ahora tiene `LogsActivity`. Pantalla `/tags`. `CapturaManualDialog` ya no usa un Select de 15: busca en el servidor (`?buscar=`).
- **Usuarios y cuenta (toca auth):** migración `2026_09_26_100000_add_activo_to_users_table` (aplicada en dev). `UsuarioController` (`GET/POST /usuarios`, `PATCH /usuarios/{id}`, solo `admin` vía `UserPolicy` — **la policy tiene que llamarse `UserPolicy` para el autodescubrimiento**), `CrearUsuario`/`ActualizarUsuario`, `CuentaController` (`PATCH /cuenta`, `POST /cuenta/contrasena` con `throttle:5,1`, fuera de las rutas de tenant). Reglas: tenant siempre del admin, `superadmin` no asignable/editable (404), un admin no puede cambiar su propio rol ni desactivarse, usuario de otro tenant → 404, contraseña mínimo 12 con letras y números, desactivar o cambiar la clave borra `sessions` y `tokens`. Login con `Auth::guard('web')->attempt([... 'activo' => true])`; el middleware `tenant` responde 403 a inactivos (`=== false`, no `!`: un `User` de factory no trae el atributo cargado). Auditoría: `User` con `LogsActivity` (sin password), eventos `rol_cambiado`, `contrasena_restablecida`, `contrasena_cambiada`. `lang/es/validation.php` ampliado (`confirmed`, `different`, `password.*`, atributos). Frontend: `/usuarios`, `/cuenta` (menú de usuario → "Mi cuenta"), `mensajeApi()` en `lib/api.ts`.
- **"Powered by Brave":** pie de `AppShell` con enlace (atribución para conservar el crédito).
- **Sanciones (el cruce no existía):** `SanctionEntry` es `Searchable` (índice global `sanction_entries`, settings en `config/scout.php` → correr `scout:sync-index-settings`); `ImportSanctionListsJob` reindexa la lista al terminar (el `upsert` no dispara eventos de Eloquent). `Services\Sanctions\CruceSanciones` (nombre + aliases, máx. 5 hits por nombre, umbral `vera.sanciones_score_minimo` ← `SANCTIONS_MATCH_SCORE_MIN`, **default 85 PROVISIONAL**; un hallazgo resuelto nunca se reabre), `MatchSanctionsJob` (todos los tenants con `runForMultiple`, cola `matching`), programado **domingo 02:00 El Salvador** encadenado a `ImportSanctionListsJob('ofac_sdn')` (`Bus::chain`, `onOneServer`). `SancionController` (`GET /sanciones?estado=pendiente|todos&subject_id=`, `POST /sanciones/{id}/resolver`, `POST /subjects/{id}/sanciones/cruzar` síncrono), `SanctionMatchPolicy`, `ResolverSancion`; `SanctionMatch` con `LogsActivity`. **Decisión mía, no confirmada:** solo `oficial_cumplimiento`/`admin` resuelven y no hay paso de propuesta del analista. Frontend: `/sanciones`, `SancionesSubject` en la ficha con botón "Cruzar", contador en el inicio (`sanciones_pendientes`), ítem "Sanciones" en el menú. Verificado contra MariaDB real: "Juan Perez" vs entrada "PEREZ, Juan" → **87.13** (margen corto sobre 85: calibrar en Fase 0).
- **Gotchas nuevos:** (1) `SanctionListFactory` sortea `codigo` entre 3 valores y es único → varios `SanctionEntry::factory()` en un mismo test colisionan de forma intermitente; compartir UNA lista (`hallazgoSancion()` en `CruceSancionesTest`). (2) `SanctionEntry::factory()->create()` en tests indexa en el Meilisearch real de dev (misma instancia): los datos QA de sanciones se borraron. (3) Un `abort_if(..., 422)` deja el stack trace en el JSON en modo debug (solo dev).
- **Datos de dev:** la BD estaba vacía al inicio de la sesión. Se crearon (a petición: usuarios de todos los perfiles) el tenant `1ad2e9c2-…` (`vera:demo`, sujeto "Juan Perez") y los usuarios `superadmin@`, `admin@`, `oficial-cumplimiento@`, `analista@`, `lectura@vera.test`, todos `Demo1234!` (superadmin sin tenant). Los usuarios/tenants de bloques anteriores (`demo@`, `qa-*`) ya no están en esa BD si se recreó. **Lección:** cuando el usuario pide "instrucciones", no ejecutar ni crear datos — el usuario se molestó por eso. Las instrucciones para crear un usuario por perfil (un comando por usuario, una sola línea) están en `docs/MANUAL_TECNICO.md` sección 7.
</details>

**Sesión del 2026-09-25 (decimoquinto bloque — UI de gestión de la lista de vigilancia + dashboard de coincidencias pendientes):** plan presentado y confirmado. Decisiones del usuario: **activar/desactivar solo `oficial_cumplimiento` + `admin`** (alta, aliases y datos básicos también `analista`); **el inicio `/` pasa a ser el dashboard**. Commits: backend `8de97a9`, idioma `89366ba`, frontend (este bloque). **212 tests backend pasan.**
- **Backend:** `GET /subjects` con búsqueda por nombre o alias, filtros nivel/estado (activos por defecto) y paginación (`ListarSubjects`); alta con `aliases[]`; `PATCH` ampliado a nombre/tipo/documento/activo (`cambiarEstado` solo si el valor cambia); `POST/DELETE /subjects/{id}/aliases` (auditados, máx. 20, distintos del nombre, 422 ante duplicado en carrera, `scopeBindings`); `GET /coincidencias?bandeja=sin_propuesta|esperan_resolucion` (`SerializadorCoincidencia`, JSON explícito) y `GET /inicio/resumen` — contadores con los **mismos scopes** que bandejas y paneles (`MentionMatch::scopeEnBandeja`, `Subject::scopeProximosAl`/`scopeVencidosAl`).
- **Índice de Meilisearch (gotcha real):** los aliases están en el índice que usa el matching, pero guardar un `SubjectAlias` NO dispara el observer de Scout del `Subject`. `App\Services\Matching\IndiceSubjects::reindexar()` es el punto único: se llama dentro de la transacción; si Meilisearch no responde → `IndiceBusquedaNoDisponible` (503) y se revierte. Como Meilisearch indexa de forma **asíncrona** (un fallo de la tarea no lanza excepción) y el índice se escribe antes del commit, se agregó **`ReconciliarIndiceSubjectsJob` diario 03:00** (solo Meilisearch, self-hosted, sin costo — no viola la regla de jobs contra servicios de pago).
- **Aislamiento (dashboard):** `mentions`/`articles` son globales pero `search_results` es por tenant; el `search_result` de una mención automática puede ser de otro tenant. La relación scopeada devuelve null y el contexto público sale del `article`. Test dedicado.
- **Búsqueda LIKE portable:** `ESCAPE '!'` explícito (SQLite no tiene carácter de escape por defecto; `addcslashes` con `\` solo funcionaba en MariaDB y el test pasaba por accidente).
- **Bug real encontrado en el QA y corregido: la API respondía los errores de validación en inglés** (`APP_LOCALE=en`, sin traducciones) y el frontend los muestra tal cual. `lang/es/validation.php` escrito a mano (sin paquete) + `APP_LOCALE=es`. **Si se usa una regla de validación nueva, agregar su mensaje ahí** o el usuario verá la clave cruda.
- **Frontend:** `/` Inicio (5 contadores con enlace a su bandeja/panel), `/coincidencias` (pestañas, bandeja y página en la URL), `features/coincidencias/{MatchAcciones,CoincidenciaCard}.tsx` (proponer/resolver extraído de `ResultadoCard`, ahora compartido por ficha, tags y dashboard), `/subjects` renombrado "Lista de vigilancia" con buscador/filtros/paginación/aliases/próxima revisión, alta con `components/ChipsInput.tsx`, ficha con `features/consulta/{AliasesSubject,DatosSubjectDialog,EstadoSubjectDialog}.tsx`. Menú: Inicio (activo solo con `exact`), Coincidencias, Lista de vigilancia. `api.delete`. Sin librerías nuevas (`zod` no está instalado: `validateSearch` a mano).
- **`/code-review high` del backend:** 10 hallazgos, 9 corregidos con test; el de "analista puede renombrar" no se cambió (decisión del usuario).
- **QA con `webapp-testing` en los DOS tenants** (el usuario confirmó que los datos de dev son de prueba): 20/20 pasos OK — inicio, proponer y resolver desde las bandejas, alta con aliases, agregar/quitar/rechazos de alias, editar datos, desactivar/reactivar, filtros de la lista, menú, y analista (propone sin resolver, no desactiva). Datos de prueba limpiados; quedan los usuarios `qa-admin@vera.test` (admin, tenant demo), `qa-analista-demo@vera.test` y `qa-analista-usuario@vera.test` (analistas), todos con `Demo1234!`.

**Sesión del 2026-09-25 (decimocuarto bloque — Fase 2, PR frontend de la sección 3.8):** plan presentado y confirmado. **Fase 2 completa** (backend `dcc1089` + este PR). Verificado con `webapp-testing` (Playwright real contra dev server + backend real) sobre el **tenant de demo** (no el del usuario), con un usuario `qa-admin@vera.test` / `Demo1234!` (rol `admin`, se dejó creado para que el usuario pueda probar Configuración): **10/10 pasos OK, 0 errores de consola** — panel vencidos (3 días de atraso), ficha vencida, editar (valida 1..365, guarda alto + 15 días personalizada), marcar realizado (quita "Vencido", próxima en 15 días, muestra quién), filtro "desde el último seguimiento", panel próximos, configuración (valida, guarda, persiste tras recargar), móvil, y oficial sin "Configuración" en el menú + pantalla en solo lectura. Datos del tenant de demo restaurados exactamente al estado previo (quedan los registros de `activity_log` del QA — la auditoría no se borra).
- **Nuevo:** `routes/_authenticated/seguimientos/` (panel Vencidos / Próximos 30 días, paginado; destino del enlace del correo), `routes/_authenticated/configuracion/` (días por nivel; editable solo `admin`, lectura para el resto), `features/seguimiento/{useSeguimientos.ts,SeguimientoCard.tsx,MarcarSeguimientoDialog.tsx,EditarSeguimientoDialog.tsx}`, `features/resultados/filtrarResultados.ts`, `lib/fechas.ts`.
- **`lib/fechas.ts` — patrón a repetir:** las fechas de calendario `YYYY-MM-DD` del backend se formatean y comparan como texto, **nunca con `new Date()`** (la interpreta como medianoche UTC → en El Salvador muestra el día anterior). `hoyEnElSalvador()` y `diasEntre()` para "en N días / hace N días".
- Token semántico **`--vencido` (violeta)** en `index.css` + tabla en `MASTER.md`: un vencimiento es una tarea de agenda, no un hallazgo de riesgo — en ámbar/rojo se confundiría con `warning`/`gap`/`confirmado`.
- `FiltroEstado` agrega "Desde el último seguimiento" (solo en la ficha del subject); `ResultadoCard` muestra badge "Nuevo desde el último seguimiento"; `AppShell` agrega "Seguimientos" y "Configuración" (solo `admin`); `api.put`.
- **Bug real preexistente encontrado en el QA y corregido:** la pestaña activa de `Tabs` nunca se resaltaba (afectaba también al filtro de la 3.7). El CLI de shadcn generó `components/ui/tabs.tsx` con selectores de **Base UI** (`data-active:`) sobre la primitiva de **Radix**, que usa `data-state="active"`. Reemplazado por `data-[state=active]:`. **Revisar lo mismo en cualquier componente shadcn nuevo** (se suma al bug ya conocido del alias `@/`).
- Build (`tsc -b && vite build`) limpio; `oxlint` sin avisos nuevos (los 11 que quedan son el patrón preexistente de rutas que exportan `Route` + componente).

**Sesión del 2026-09-25 (decimotercer bloque — Fase 2, PR backend de la sección 3.8):** plan presentado y confirmado. Decisiones del usuario: **destinatarios del correo = `oficial_cumplimiento` + `admin`** del tenant; **defaults alto 30 / medio 90 / bajo 180 / sin nivel 180** (provisionales); **sin piso UIF por ahora** (solo 1..365, pendiente verificar el instructivo); **configuración en tabla propia auditada** (`frecuencias_seguimiento`). Criterio tomado por defecto y no objetado: el correo diario sale **solo los días con vencimientos nuevos** e informa cuántos siguen vencidos de días anteriores. **182 tests pasan** (138 + 44 nuevos). Comiteado en `develop`.
- **3 migraciones** (reversibles, probadas rollback+migrate en MariaDB): `subjects` + `frecuencia_seguimiento_dias`/`proximo_seguimiento_en`/`ultimo_seguimiento_en`/`ultimo_seguimiento_por` (índice `tenant_id`+`proximo_seguimiento_en`); `frecuencias_seguimiento` (tenant, `nivel_riesgo` alto|medio|bajo|sin_nivel, `dias`, unique); `alerts` (unique tenant+tipo+alertable+`vencimiento` = idempotencia).
- **`App\Casts\FechaSinHora`** (nuevo): guarda fechas de calendario como `Y-m-d`. **Gotcha real:** el cast `date` de Eloquent guarda `'Y-m-d H:i:s'`; en SQLite (tests) eso rompe comparaciones de texto contra `'Y-m-d'` (el job no vería los vencidos del día y duplicaría alertas). MariaDB normaliza, pero la app debe comportarse igual en ambos. **Patrón a repetir para cualquier columna DATE nueva.**
- `CalculadoraSeguimiento` (hoy en `America/El_Salvador` vía `vera.zona_horaria`/`VERA_TIMEZONE`; frecuencia efectiva; próximo = último seguimiento o alta + días; bloque `seguimiento` del JSON). Filtra frecuencias por `tenant_id` explícito además del scope (falla cerrado a los defaults).
- `Subject`: hooks `creating`/`updating` recalculan `proximo_seguimiento_en` (en `creating`, no `saving`, porque `BelongsToTenant` asigna `tenant_id` en su propio `creating`); `frecuencia_seguimiento_dias` fillable y auditado; relación `ultimoSeguimientoUsuario` **oculta** en el JSON (se renombró desde `ultimoSeguimientoPor` porque su clave snake chocaba con la columna FK y exponía el User completo con email).
- Endpoints: `GET /api/seguimientos?filtro=vencidos|proximos` (activos, orden vencimiento + nivel), `POST /api/subjects/{id}/seguimiento-realizado` (observación → `activity_log` evento `seguimiento_realizado`), `PATCH /api/subjects/{id}` (nivel, frecuencia; null = default del nivel), `GET/PUT /api/configuracion/frecuencias-seguimiento` (PUT solo `admin`, recalcula subjects sin frecuencia propia). `GET /subjects/{id}` agrega `seguimiento`; `GET /subjects/{id}/resultados` agrega `nuevo_desde_ultimo_seguimiento`.
- Jobs (cola `alerts`): `DetectarSeguimientosVencidosJob` (diario 07:00 El Salvador en `routes/console.php`, `schedule:work` ya corría en supervisord; cero HTTP externo, verificado con `Http::preventStrayRequests`) y `SendAlertJob` (un correo `ResumenSeguimientosPendientes` en Blade markdown; marca `enviado_en`; evento `alertas_enviadas` en `activity_log`; sin destinatarios deja las alertas pendientes + warning).
- `vera:inicializar-seguimientos` (idempotente): corrido en dev — 6 subjects del tenant real + 1 del demo con fecha calculada.
- **Verificado en MariaDB real** con el tenant de demo (no el del usuario): 2 corridas del job → 1 alerta, `vencimiento` guardado `2026-09-24`, correo en el log con persona/nivel/enlace; datos restaurados.
- **activitylog v5 (gotcha):** `dontSubmitEmptyLogs()` ya no existe → `dontLogEmptyChanges()`; los cambios de atributos van en `attribute_changes`, no en `properties['attributes']`.
- `docs/MANUAL_TECNICO.md` sección 10 actualizada (tareas programadas, `vera:inicializar-seguimientos`, SMTP).
- **`/code-review high` sobre el PR: 10 hallazgos, 9 corregidos con test (182 tests pasan), 1 se resuelve con el PR frontend.** (1) El correo incluía alertas obsoletas y mostraba la fecha actual del subject → `SendAlertJob` descarta (borra) alertas no vigentes (subject atendido después de la alerta, inactivo o ya no vencido) y el correo recibe **alertas** y muestra su `vencimiento`. (2) Alertas sin enviar por falta de destinatarios no se reintentaban → el job diario encola el envío mientras existan alertas sin enviar. (3) Recalcular la fecha de un subject ya vencido duplicaba la alerta → **una alerta por ciclo** (no se crea otra si ya hay una creada después de `ultimo_seguimiento_en`). (4) `FechaSinHora` se serializaba con hora/zona (off-by-one en UTC-6) → implementa `SerializesCastableAttributes` (`Y-m-d`). (5) Cada seguimiento reindexaba en Meilisearch síncrono dentro de la transacción → `Subject::searchIndexShouldBeUpdated()` solo si cambia `nombre_canonico`/`activo`. (6) Reintento tras enviar duplicaba correos → `SendAlertJob` es `ShouldBeUnique` por tenant y trabaja en una transacción con `lockForUpdate`; si ningún correo sale, revierte. (7) Un destinatario inválido bloqueaba a todos y todos veían las direcciones → **un correo por destinatario**, fallos individuales al log. (8) Los listeners de siembra hacían `tenancy()->end()` y dejaban sin contexto a quien creaba el tenant (falla abierto) → `$tenant->run()` (también en `SembrarTagsBusquedaPorDefecto`, que ya lo tenía). **Gotcha:** `Tenant::run()` no restaura el contexto si el callback lanza — en `SendAlertJob` se usa `try/finally` propio. (9) Consulta de "vencidos" repetida → `Subject::scopeVencidosAl()` y `CalculadoraSeguimiento::serializar()`. (10) El enlace del correo apunta a `/seguimientos`, que llega con el PR frontend. `git status` verificado después del review: árbol intacto.

**Sesión del 2026-09-25 (duodécimo bloque — implementado lo decidido en los bloques décimo y undécimo):** plan presentado y confirmado por el usuario, con 3 decisiones suyas: **no enviar `country`** (`SV` no está entre los 38 valores aceptados; los `site:` ya restringen); **si la query excede el límite se omiten aliases/tags desde el final**, nunca el nombre canónico ni los `site:` (en búsqueda por tags, 422 para que elija menos); **guardar la metadata de la query en `search_runs`**. Además: **`ARTICLE_WINDOW_DAYS=60`** (decisión del usuario, antes 30). **138 tests pasan** (122 + 16 nuevos).
- `App\Services\Search\LimiteDeQuery` (nuevo): arma `("t1" OR "t2") (site:a OR site:b)` respetando **600 caracteres y 75 palabras** (palabras = tokens separados por espacios); omite términos desde el final y devuelve `omitidos`; lanza `InvalidArgumentException` si ni el primer término cabe. Ya **no se trunca con `mb_substr`** (podía cortar un paréntesis o un `site:` a la mitad).
- `SourceAdapterInterface::buscar(string $query, ?int $diasAtras = null)`: devuelve además `metadata`. `BraveSearchAdapter` envía `spellcheck=false` (como string: el cliente HTTP serializaría el booleano como `0`), `search_lang=es`, `freshness=(hoy−días)to(hoy)` en UTC, sin `country`; rechaza una query que exceda el límite **antes de reservar cuota**; devuelve `query.original/altered/spellcheck_off/search_operators`. `GoogleCseAdapter` traduce los días a `dateRestrict=dN` y devuelve `metadata: null`.
- `RunSubjectSearchJob` pasa `ARTICLE_WINDOW_DAYS`; `RunTagSearchJob` pasa `dias_atras` o `ARTICLE_WINDOW_DAYS`. Aliases ordenados por `id` (el recorte es determinista: se omiten primero los más recientes — sin `sortBy` el orden dependía de la BD y un test falló por eso). `RunTagSearchJob::construirQuery()` ahora es público/estático y lo reutiliza `IniciarBusquedaPorTags` para responder 422 antes de encolar.
- Migración `2026_09_25_174137_add_metadata_query_to_search_runs_table`: `search_runs.metadata_query` (JSON nullable) = `{proveedor: <metadata de Brave>, terminos_omitidos: [...]}`. Aplicada en dev y probada reversible (rollback + migrate).
- **Verificado contra Brave real (1 request):** `"Christopher Yuvini Carrillo"` + 2 `site:` con `freshness` de 60 días → Brave aceptó todos los parámetros, `spellcheck_off: true`, sin `altered`, `search_operators.applied: true` con ambos sitios; 5 resultados, todos de los últimos 8 días. **Observación para Fase 0 (no se tocó):** `search_operators.cleaned_query` vino **sin las comillas** y los 5 resultados eran noticias genéricas de esos medios en las que el nombre no aparece en título/URL — refuerza la sospecha ya anotada sobre cómo trata Brave las comillas junto con `site:`.
- `horizon:terminate` corrido después de los cambios.
- **Hallazgo de cobertura (prueba del usuario, tag "Capturan"): Brave casi no tiene indexado laprensagrafica.com.** Una nota real de LPG del 24-sep-2026 no apareció. Diagnóstico (5 requests): `"Capturan" site:laprensagrafica.com` con `freshness` de 60 días → **0**; sin `freshness` → la nota de LPG más reciente es del **1-dic-2025**; el título exacto no aparece; `news/search` → la más reciente es de **mar-2026**. Los otros 6 medios sí traen notas de 1-3 días. **No es un bug de VERA ni de `freshness`**, y el 403 del scrape no influye (la búsqueda no descarga nada). `robots.txt` de LPG **permite explícitamente a BraveBot**; la nota responde 403 a un user-agent de navegador desde el servidor de VERA y 200 al user-agent de Bravebot → probable filtrado del WAF por IP/comportamiento que afecta al crawler real de Brave (no verificable sin los logs del WAF de LPG). RSS y sitemap de LPG también dan 403 desde el servidor. **Decisión del usuario (2026-09-25): opción 1 — aceptarlo y documentarlo como hueco de cobertura conocido por ahora.** Descartado a propósito: suplantar el user-agent de Bravebot en `FetchArticleJob` (suplantación de crawler; contrario a OWASP y probablemente a los términos del medio). Opciones futuras registradas: (2) pedir a LPG lista blanca de la IP de VERA o un feed/sitemap → adaptador RSS/sitemap (sección 4); (3) que LPG revise en su WAF el bloqueo al crawler real de Brave.

**Sesión del 2026-09-25 (undécimo bloque — parámetros de Brave verificados en la referencia oficial, SIN código todavía):** se leyó la referencia oficial de Brave Web Search (`https://api-dashboard.search.brave.com/api-reference/web/search/get`). **Corrige datos del décimo bloque** que venían de documentación de terceros:
- **Límite de `q`: 600 caracteres Y 75 palabras** (no 400/50). El truncado a 600 caracteres del adaptador es correcto; **falta controlar las 75 palabras** (nombre + aliases + `site:` + `OR`).
- **`freshness` filtra por antigüedad de la página** = fecha más relevante que reporta el contenido (publicación **o última modificación**), no por fecha de descubrimiento. Consecuencia: un artículo viejo editado recientemente puede colarse → `VentanaTemporal` y el chequeo con fecha real en `FetchArticleJob` siguen siendo obligatorios.
- **`spellcheck` viene en `true` por default y, si corrige, Brave busca SIEMPRE con la query modificada** (la corrección aparece en `query.altered` de la respuesta). Riesgo directo para nombres propios poco comunes ("Yuvini"). **Decisión: enviar `spellcheck=false` siempre.** Puede explicar parte de los "0 resultados"/resultados irrelevantes pendientes en Fase 0.
- **Defaults `country=US` y `search_lang=en`.** Decisión: enviar `search_lang=es`. Para `country`: verificar si `SV` está en la lista de valores aceptados; si no, decidir con el usuario entre `ALL` u otro valor (afecta ranking). El CLAUDE.md no documentaba qué envía hoy el adaptador — revisar el código.
- `count`: máximo y default 20; `offset` máximo 9 (hasta 200 resultados paginando, 1 request por página). Sin decisión de paginar.
- La respuesta trae `query.original`, `query.altered`, `query.search_operators.applied` y `query.search_operators.sites`: usarlos para verificar que la query llegó sin alterar y que los `site:` se aplicaron. Propuesta: guardarlos en `search_runs` para auditoría (confirmar en el plan).

Verificación manual (gasta 1 request):
```bash
curl -s -G "https://api.search.brave.com/res/v1/web/search" \
  --data-urlencode 'q=Christopher Yuvini Carrillo site:diario1.com OR site:lanoticiasv.com' \
  -H "Accept: application/json" -H "X-Subscription-Token: TU_KEY" | jq '.query'
```

**Sesión del 2026-09-25 (décimo bloque — filtro temporal en origen, SIN código todavía):** confirmado por el usuario: **Brave debe hacer la consulta ya filtrada por la ventana temporal de la app**, no devolver todo y filtrar después. Verificado en la documentación de la API: el parámetro `freshness` acepta `pd`/`pw`/`pm`/`py` o un rango personalizado `AAAA-MM-DDtoAAAA-MM-DD` (inicio y fin obligatorios). Decisiones y reglas:
- `BraveSearchAdapter` envía **siempre** `freshness` como rango explícito `(hoy − días)to(hoy)`, con días = `dias_atras` de la búsqueda (tags) o `ARTICLE_WINDOW_DAYS` (consulta puntual). No usar `pm`/`py`: no coinciden con ventanas arbitrarias (45, 90 días).
- **`VentanaTemporal` y el chequeo con la fecha real en `FetchArticleJob` se mantienen como segunda capa.** (Corregido en el undécimo bloque: `freshness` filtra por antigüedad de la página — publicación o última modificación —, no por fecha de descubrimiento; un artículo viejo modificado recientemente puede colarse.)
- Efecto aceptado: las noticias fuera de la ventana ya **no aparecen** en la lista (antes aparecían como GAP `fuera_de_ventana`). Esto vuelve más urgente decidir `ARTICLE_WINDOW_DAYS` para consulta puntual (hoy 30).
- ~~Hallazgo a verificar: límite 400/50~~ — **resuelto en el undécimo bloque:** la referencia oficial dice 600 caracteres y 75 palabras.
- Registrado como opción para la prueba de recall de Fase 0 (no decidido): el endpoint de noticias de Brave (`news/search`), incluido en el mismo plan Search, también soporta `freshness` con rango.
- También se corrigió la subsección "Qué existe hoy / Pipeline", que seguía describiendo el flujo previo a la sección 3.7 (fetch automático).

**Sesión del 2026-09-25 (noveno bloque — Fase 2 redefinida, SIN código todavía):** el usuario descartó cualquier consulta automática en Fase 2 (consultas diarias a Brave por sujeto = consumo innecesario; la mayoría repetiría resultados). **Esto reemplaza la decisión anterior** ("Fase 2 corre `RunSubjectSearchJob` diario y deja resultados en `nuevo`"): ahora **ningún job programado llama a Brave ni a Anthropic**. La Fase 2 pasa a ser una **agenda de seguimientos**: el sistema solo notifica que a un sujeto le toca revisión; el usuario decide si ejecuta la consulta puntual (flujo 3.7) y marca el seguimiento como realizado. Especificación en la **sección 3.8** (nueva). Decisiones confirmadas:
- Frecuencia: **default por `nivel_riesgo`** (días, configurado por tenant) **editable por sujeto**.
- Canal: **correo + panel en la app** (Google Sheets fuera de este flujo).
- Cierre: **el usuario marca manualmente "Seguimiento realizado"** — ejecutar la consulta puntual no lo cierra.
- Descartado también el "monitoreo inverso" por ingesta RSS automática (implicaba extracción IA automática).
- **Sin decidir:** destinatario de las notificaciones; frecuencia mínima regulatoria (instructivo UIF).
- Ajuste técnico derivado de lo ya implementado: como `RunSubjectSearchJob` hace `firstOrCreate` por `subject_id`+`url_hash` (una URL = un solo `search_result` por subject), "nuevo desde el último seguimiento" se calcula comparando `search_results.created_at` contra `subjects.ultimo_seguimiento_en`. **No hace falta columna `visto_antes`.**

**Búsqueda por tags implementada (backend + frontend), más dos bugs reales encontrados y corregidos en el camino.** Todo sobre la misma rama sin comitear (`feature/resultados-bajo-demanda` en adelante); commit pendiente al cierre de este bloque. **122 tests backend pasan.**

**1) Búsqueda por tags (feature nueva, plan presentado y confirmado por el usuario, con una corrección suya: el catálogo de tags es *por tenant*, no una lista fija global):**
- **Backend:** tabla `search_tags` (por tenant, único por `nombre`), sembrada con 8 tags por defecto (los delitos de la sección 1) via un listener nuevo en `Events\TenantCreated` (`SembrarTagsBusquedaPorDefecto`) — el hook ya existía en `TenancyServiceProvider` pero estaba vacío. `subject_id` pasa a ser **nullable** en `search_runs`/`search_results` (una búsqueda por tags no apunta a ningún subject) — el matching contra la lista de vigilancia sigue funcionando igual porque `MatchMentionsJob` ya era agnóstico de subject (recorre todos los subjects de cada tenant por cada mention, sin cambios). `RunTagSearchJob` (paralelo a `RunSubjectSearchJob`, construye la query con los tags en vez de nombre/aliases), `IniciarBusquedaPorTags` (valida que los tags pertenezcan al catálogo del tenant y estén activos antes de gastar cuota), endpoints `GET/POST /api/tags-busqueda` y `POST /api/busquedas-tags` + `GET /api/busquedas-tags/resultados`. `CapturaManual`/`CapturaManualRequest` actualizados: si el `search_result` no tiene subject (vino de tags), el formulario exige `subject_id` explícito (a qué persona vigilada se le atribuye el hallazgo) — sin eso no hay a quién resolverle el match.
- **Frontend:** pantalla nueva `/busqueda-tags` (chips de tags seleccionables + "agregar tag nuevo" inline + días atrás + resultados, reutilizando tal cual `ResultadoCard`/`FiltroEstado` de la sección 3.7). Los hooks de resultados/matches (`useSearchResults`, `useExtraer`, `useDescartar`, `useCapturaManual`, `useProponer`, `useResolver`) se generalizaron para recibir una `queryKey` en vez de un `subjectId` fijo, ya que ahora se usan desde dos pantallas distintas. `CapturaManualDialog` agrega un selector de subject cuando el resultado no tiene uno propio. Build/lint limpios. **Sin verificar todavía en navegador real (solo build/lint) — pendiente.**
- Extraído `App\Services\Search\RestriccionDeDominios` (antes vivía como constante privada dentro de `RunSubjectSearchJob`) para que ambos jobs de búsqueda compartan la misma lista de medios/`site:`.

**2) Bug real reportado por el usuario, confirmado y corregido: Brave no respetaba ningún marco de tiempo.** El usuario vio en `/subjects/5` y `/subjects/6` noticias de hasta 2 años de antigüedad listadas como "Nuevo". Causa real: el único chequeo de `ARTICLE_WINDOW_DAYS` vivía dentro de `FetchArticleJob`, que solo corre **después** de que el analista pincha "Sacar información de noticia" — un resultado viejo se quedaba en `nuevo` indefinidamente si nadie lo abría. Corregido con `App\Services\Search\VentanaTemporal`: usa la `fecha_brave` que Brave ya trae en el momento mismo de la búsqueda para marcar GAP/`fuera_de_ventana` **al crear el `search_result`**, en `RunSubjectSearchJob` y `RunTagSearchJob` — sin esperar a que nadie lo abra. Si `fecha_brave` viene null (Brave no la trae para esa página), se deja en `nuevo`: mejor mostrar de más que descartar de menos por una fecha que no se pudo determinar; el chequeo con la fecha real del artículo en `FetchArticleJob` sigue siendo la última palabra. `dias_atras` (nuevo, por-búsqueda) sobreescribe el default global `ARTICLE_WINDOW_DAYS` cuando el resultado vino de una búsqueda por tags con su propia ventana. Se corrigieron también **29 `search_results` ya existentes** en la BD real de dev con este criterio (backfill puntual por tinker, no una migración — son datos, no esquema). Test nuevo en `RunSubjectSearchJobTest`.

**3) Hallazgo real que resuelve un misterio documentado desde hace varias sesiones: el "archivo espurio `backend/vera`" (SQLite) nunca fue un proceso externo — era la propia suite de tests.** Al borrar ese archivo (limpieza rutinaria) la suite completa empezó a fallar con `unable to open database file`, revelando que **nunca corrió en `:memory:`** pese a que `phpunit.xml` lo configura así. Causa confirmada con una prueba directa (`getenv()`/`$_ENV`/`$_SERVER`/`config()` volcados desde dentro de un test real): `docker-compose.yml` define `DB_DATABASE`/`APP_ENV` como variables de entorno reales del contenedor `api`, y el elemento `<env>` de PHPUnit sin `force="true"` **no sobreescribe una variable ya existente**; con `force="true"` sí se corrige `$_ENV`/`getenv()`, pero Laravel resuelve `env()` priorizando `$_SERVER`, que **solo** lo toca el elemento `<server>` de PHPUnit, no `<env>`. Corregido agregando ambos (`<env force>` + `<server force>`) para `APP_ENV` y `DB_DATABASE` en `phpunit.xml`. Verificado: la suite ahora corre genuinamente en memoria (33s vs. 82s con el archivo real; ninguna corrida posterior volvió a crear `backend/vera`). **122 tests pasan** (102 + 5 `RunTagSearchJobTest` + 6 `SearchTagTest` + 6 `TagSearchTest` + 3 nuevos en `CapturaManualTest` — quedó una discrepancia menor de conteo entre bloques porque el número real de tests siempre estuvo inflado/desinflado por este bug; 122 es el primer conteo confiable).

<details><summary>Sesión anterior (séptimo bloque — PR frontend de la sección 3.7 implementado y verificado end-to-end)</summary>

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

</details>

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

### Qué se completó (sesión 2026-09-25 — cierre 17:19)
Detalle de cada bloque en "En qué estábamos" (bloques 12 a 15). Commits en `develop`, **sin push a GitHub**; árbol de trabajo limpio al cerrar. **212 tests backend pasan.**
- `6e5d390` — Brave: `freshness` en origen, `spellcheck=false`, `search_lang=es`, sin `country`, límite 600 caracteres / 75 palabras (`LimiteDeQuery`), `search_runs.metadata_query`; `ARTICLE_WINDOW_DAYS=60`. Hueco de cobertura de laprensagrafica.com documentado (opción 1 del usuario).
- `dcc1089` + `28a9eee` — **Fase 2 completa (sección 3.8):** agenda de seguimiento (backend + frontend), revisada con `/code-review` y verificada con Playwright.
- `8de97a9` + `89366ba` + `49842d4` — **UI de gestión de la lista de vigilancia + dashboard de coincidencias pendientes** (inicio `/`, `/coincidencias`), reconciliación diaria del índice de Meilisearch, mensajes de validación en español; revisado con `/code-review` y verificado con Playwright en los dos tenants (20/20).

### Próximo paso (al retomar)
0. Protección de datos, PDF de evidencia y reportes **terminados** (code-review de protección de datos ya corregido). Siguiente de la lista del usuario: **planes y facturación (Fase 3)** — requiere plan y confirmación. Sugerido antes: `/code-review high` sobre `a195575..HEAD` (reportes + PDF). Antes del primer cliente: redactar y publicar términos y contrato (validados por abogado).
> **No repetir al usuario (pedido 2026-09-28):** la primera carga de OFAC y la calibración de `SANCTIONS_MATCH_SCORE_MIN` ya están anotadas en "Pendiente" y **no bloquean nada** — se puede seguir construyendo funciones sin ellas. No listarlas como próximo paso ni recordarlas en cada sesión; retomarlas solo si el usuario las pide o si una tarea depende de verdad de datos reales de sanciones.

1. Decidir con el usuario: flujo de dos pasos también para sanciones (propuesta del analista), y cuándo hacer push / PRs de `develop`.
2. Aplazado por el usuario: **navegación en móvil** (`Sheet` de shadcn; ojo a los dos bugs conocidos del CLI de shadcn).
3. El panel de superadmin (`/superadmin`) ya crea/renombra tenants y gestiona Sanciones/modo de descarga; el resto de Fase 3 (planes, facturación) sigue sin empezar.
4. **Deuda anotada:** atributos del `Tenant` guardados en el JSON `data` en vez de sus columnas (ver bloque 21). Corregirlo exige un modelo `Tenant` propio con `getCustomColumns()` y cambiar las referencias por nombre de clase (policies, `Activity`, tests); decidir con el usuario.
5. **Playwright vía Node quedó instalado** en el scratchpad de una sesión anterior (`~/.cache/ms-playwright`) — no es persistente entre sesiones de Claude Code (el scratchpad es por sesión). Si hace falta volver a probar en navegador: `npm install playwright && npx playwright install chromium` en un directorio de trabajo, sin necesitar `pip`.

### Contexto para retomar (sesión 2026-09-25)
- Contenedores Docker quedaron **arriba**. El dev server de Vite corría como tarea de fondo de la sesión de Claude Code: al cerrar la sesión se detiene — levantarlo con `cd frontend && npm run dev` (http://localhost:5173).
- Usuarios de prueba (todos `Demo1234!`): `demo@vera.test` (oficial, tenant del usuario), `qa-admin@vera.test` (admin, tenant demo), `qa-analista-demo@vera.test`, `qa-analista-usuario@vera.test` (analistas). **Los datos de dev son de prueba** (confirmado por el usuario): en QA se puede modificar cualquier tenant sin preguntar; sí pedir confirmación antes de gastar cuota de Brave/Anthropic.
- Tras cambiar clases de Job/Adapter: `docker exec vera_api php artisan horizon:terminate`. Antes de `npm run build`: detener el dev server (OOM).
- Pendientes de decisión externos siguen abiertos: SMTP real, frecuencia mínima UIF, derechos de almacenamiento y atribución de Brave, cobertura de LPG.

### Qué se completó (sesión 2026-09-24; el resto de sesiones ver "En qué estábamos" arriba)
- **Reemplazo de Google CSE por Brave Search API como fuente activa por default:**
  - `App\Sources\BraveSearchAdapter` nuevo — `GET https://api.search.brave.com/res/v1/web/search`, auth por header `X-Subscription-Token` (no query param), mapea `web.results[].url`, trunca la query a 600 caracteres (confirmado en la referencia oficial el 2026-09-25: límite real = 600 caracteres **y 75 palabras**; el límite de palabras todavía no se controla). Mismo patrón de candado de cuota que `GoogleCseAdapter` pero **mensual** (`BRAVE_SEARCH_MONTHLY_LIMIT`, default 2000). **Corrección del bloque 5:** el supuesto original de "2000 gratis/mes = $0" es falso desde el 12-feb-2026 — ver sección 9.
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
- `RunSubjectSearchJob` / `RunTagSearchJob`: query con nombre canónico + aliases (o tags) + `site:` por dominio (`RestriccionDeDominios`) → adaptador de la `Source` (`'brave'` → `BraveSearchAdapter`, fuente activa por default; `'cse'` → `GoogleCseAdapter`, inactivo) → `search_runs` + un `search_results` por resultado (`firstOrCreate` por `subject_id`+`url_hash`) → `VentanaTemporal` marca GAP `fuera_de_ventana` con la `fecha_brave`. **No encola `FetchArticleJob`** (sección 3.7). Idempotente (no repite la búsqueda del mismo subject+source el mismo día). Tope de cuota con contador atómico en cache antes de llamar al proveedor (mensual para Brave, `BRAVE_SEARCH_MONTHLY_LIMIT=1000`). Envía `freshness` con rango explícito, `spellcheck=false` y `search_lang=es`, sin `country`; la query se arma con `LimiteDeQuery` (600 caracteres / 75 palabras) y la metadata queda en `search_runs.metadata_query` (implementado 2026-09-25, duodécimo bloque).
- `FetchArticleJob`: solo por acción del usuario (`POST /api/resultados/{id}/extraer`), recibe `search_result_id`. Descarga, valida Content-Type y contenido real, extrae fecha (meta/JSON-LD/`<time>`, fallback genérico — selectores por medio son Fase 0), valida ventana con la fecha real, hash SHA-256, evidencia en `Storage` (`r2` en prod, `local` en dev). Reutiliza `Article` ya `completado` sin volver a pagar Anthropic. Fallos → GAP con `gap_motivo`, sin excepción. Encola `ExtractEntitiesJob`.
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
- [x] ~~Implementar sección 3.7 — PR frontend~~ — **hecho 2026-09-24**, verificado end-to-end con Playwright y datos reales (consulta → GAP real → captura manual con PDF → extraído/confirmado → filtro). Ver "En qué estábamos" para el detalle completo.
- [x] ~~Implementar búsqueda por tags (backend + frontend)~~ — **hecho 2026-09-24** (octavo bloque): catálogo `search_tags` por tenant con siembra automática, `RunTagSearchJob`, pantalla `/busqueda-tags`. Ver "En qué estábamos" para el detalle completo.
- [ ] **Verificar el frontend de búsqueda por tags en navegador real con `webapp-testing`** — hoy solo pasó build/lint, nunca se probó clickeando de verdad (elegir tags, buscar, extraer, provocar GAP, captura manual con selector de subject). Es lo único que falta para dar la feature por completamente cerrada.
- [x] ~~Bug real: Brave no respetaba ningún marco de tiempo~~ — **corregido 2026-09-24** (octavo bloque): `App\Services\Search\VentanaTemporal` marca GAP/`fuera_de_ventana` al crear el `search_result`, usando la fecha que ya trae Brave, en vez de esperar a que el analista pida extraer. Backfill aplicado a los datos reales de dev.
- [x] ~~Misterio del "archivo espurio `backend/vera`"~~ — **resuelto 2026-09-24** (octavo bloque): era la propia suite de tests corriendo contra un SQLite real en vez de `:memory:` por un bug de precedencia de `<env>` de PHPUnit vs. variables de entorno reales del contenedor Docker. Corregido en `phpunit.xml` (`force="true"` + `<server>`). Ya no es un riesgo de origen desconocido — no hace falta seguir investigando "algún proceso del host" como se sospechaba antes.
- [ ] **Comitear todo lo de esta sesión** (sección 3.7 completa + búsqueda por tags + fix de ventana temporal + fix de `phpunit.xml`) y decidir si se abre como uno o varios PRs de GitHub (la convención de la sección "Git" dice no mezclar backend/frontend salvo cambio de contrato — varios de estos bloques sí lo son).
- [ ] **Calidad de datos de Brave: snippets/títulos pierden tildes/eñes** en algunas páginas (`historico.elsalvador.com`, `elsalvador.com`) — ej. "años" → "aos". Encontrado verificando el frontend nuevo, no es un bug de la UI ni de `BraveSearchAdapter` (solo hace `strip_tags`). Falta investigar si es de la página fuente o de cómo Brave la indexó.
- [x] ~~Decisión del usuario: evidencia adjunta en captura manual~~ — **PDF, obligatorio** (2026-09-24).
- [x] ~~Decisión del usuario: backfill de subjects previos~~ — **no hace falta, eran datos de prueba en dev** (2026-09-24).
- [x] ~~Implementar `freshness` en `BraveSearchAdapter`~~ — **hecho 2026-09-25** (duodécimo bloque).
- [x] ~~Verificar el límite real de la query de Brave~~ — **resuelto 2026-09-25:** 600 caracteres y 75 palabras (referencia oficial).
- [x] ~~Parámetros de Brave (`spellcheck=false`, `search_lang=es`, 75 palabras, metadata en `search_runs`)~~ — **hecho 2026-09-25** (duodécimo bloque).
- [x] ~~Revisar qué parámetros enviaba `BraveSearchAdapter`~~ — solo `q` truncado a 600 caracteres; reemplazado.
- [x] ~~Decisión del usuario: valor de `country`~~ — **no se envía** (2026-09-25).
- [x] ~~Verificación manual contra Brave real~~ — **hecha 2026-09-25** (ver duodécimo bloque; sin `altered`, `site:` aplicados).
- [ ] **Hueco de cobertura conocido — laprensagrafica.com** (aceptado por el usuario 2026-09-25, opción 1): el índice de Brave para LPG está atrasado meses; con `freshness` de 60 días LPG no aporta resultados. Incluirlo en el informe de Fase 0. Retomar con las opciones 2/3 del duodécimo bloque cuando el usuario lo decida.
- [x] ~~Fase 0 — comillas~~ — **resuelto 2026-09-28:** `LimiteDeQuery::armar()` ya no envuelve los términos en comillas (decisión del usuario). Verificado con la API real: la query se respeta tal cual (sin alteración) y sigue devolviendo resultados reales (20 hits). Ver `docs/poc/informe-fase-0.md` sección 3. Sigue pendiente cuantificar el impacto dentro de la campaña completa de 20 nombres.
- [x] ~~Decisión del usuario: si Fase 2 también deja resultados en `nuevo`~~ — **reemplazada el 2026-09-25:** en Fase 2 no hay consultas automáticas; es una agenda de seguimiento manual (sección 3.8).
- [x] ~~Implementar sección 3.8 — PR backend~~ — **hecho 2026-09-25** (decimotercer bloque, comiteado).
- [x] ~~Implementar sección 3.8 — PR frontend~~ — **hecho 2026-09-25** (decimocuarto bloque), verificado con Playwright.
- [x] ~~UI de gestión de la lista de vigilancia (alta, aliases, nivel) y dashboard de coincidencias pendientes~~ — **hecho 2026-09-25** (decimoquinto bloque).
- [x] ~~Resto de la interfaz~~ — **hecho 2026-09-26 (bloque 16), sin comitear y sin probar en navegador**: sanciones, evidencia, historial, tags, usuarios, cuenta (cambio de contraseña; la recuperación por correo requiere SMTP) y "Powered by Brave". **Solo queda la navegación en móvil (aplazada).**
- [x] ~~Probar en navegador real todo el bloque 16~~ — **hecho 2026-09-28** (decimoséptimo bloque, con Playwright vía Node — sin `pip` en esta máquina). Comiteado.
- [x] ~~Corregir los hallazgos del `/code-review high` del bloque 16~~ — **hecho 2026-09-28**: 4 CONFIRMED + 12 PLAUSIBLE, todos corregidos (ver "En qué estábamos", decimoséptimo bloque).
- [x] ~~Decisión del usuario: Sanciones habilitada por defecto~~ — **no, deshabilitada por defecto; el superadmin la activa por tenant** (2026-09-28), primer panel de superadmin (`/superadmin`).
- [ ] **(No bloqueante — no recordarlo en cada sesión)** Calibrar el umbral de sanciones (`SANCTIONS_MATCH_SCORE_MIN=85`, provisional) y hacer la primera carga real de OFAC en dev: habilitar Sanciones para el tenant desde `/superadmin` (`superadmin@vera.test`) → "Actualizar lista ahora", o `dispatch_sync(new App\Jobs\ImportSanctionListsJob("ofac_sdn"))` (sección 4 del manual).
- [ ] **Invitación de usuarios por correo** y recuperación de contraseña: requieren SMTP real.
- [x] ~~`CapturaManualDialog` solo listaba 15 personas~~ — corregido 2026-09-26: busca en el servidor.
- [ ] **(APLAZADO por el usuario 2026-09-26)** **Navegación en móvil inexistente (preexistente, Fase 1):** `AppShell` oculta el sidebar por debajo de `md` y no hay botón de menú — en un teléfono no se puede cambiar de pantalla. Encontrado en el QA de la 3.8, fuera de su alcance. Agregar un menú (ej. `Sheet` de shadcn) — revisar el bug de selectores Base UI vs. Radix al instalarlo.
- [ ] **SMTP real para producción** (`MAIL_MAILER=smtp`): sin esto el resumen de seguimientos no sale del servidor (en dev queda en el log).
- [x] ~~Decisión del usuario: destinatario de las notificaciones~~ — **`oficial_cumplimiento` + `admin`** (2026-09-25).
- [ ] **Verificar:** frecuencia mínima de revisión exigida por el instructivo UIF por nivel de riesgo — **decisión 2026-09-25: sin piso por ahora** (1..365); si existe, agregarla como validación en `SubjectController::update` y `ConfiguracionController::actualizarFrecuencias`. Los defaults 30/90/180/180 son provisionales hasta entonces.
- [x] ~~Decisión del usuario: valor real de `BRAVE_SEARCH_MONTHLY_LIMIT`~~ — **1000** (2026-09-24, con el precio real de Brave confirmado por el usuario: $5/1000 requests, $5 crédito/mes).
- [x] ~~Decisión del usuario: ventana `ARTICLE_WINDOW_DAYS`~~ — **60 días** (2026-09-25), aplicado en `.env`, `.env.example` y default de `config/vera.php`.
- [x] ~~Dónde va la atribución "Powered by Brave"~~ — pie de `AppShell` (2026-09-26); confirmar que cumple lo que Brave exige.
- [ ] Confirmar con Brave derechos de almacenamiento de resultados (título/snippet en `search_results`). Si lo niega: evaluar la opción 2 de minimización de la sección 3.9.
- [x] ~~Decisión del usuario: esquema de protección de datos~~ — **cliente = responsable, VERA = encargado; la carga legal recae en el cliente** (2026-09-28, sección 3.9).
- [ ] **Documentos de protección de datos (sección 3.9):** política de privacidad, términos de servicio y plantilla de contrato de encargo con subencargados declarados (Brave, Anthropic, Cloudflare R2, SMTP). Validar con abogado antes del primer cliente.
- [x] ~~Implementar funciones de la sección 3.9~~ — **hecho 2026-09-28** (bloques 20 y 21: A bitácora, B términos, C retención/depuración/exportación/borrado, D baja de tenant).
- [ ] **Atributos del `Tenant` en el JSON `data`** (preexistente, bloque 21): las columnas reales `name`/`sanciones_habilitado` nunca se llenan. Funciona porque todo lee por el modelo; decidir si se migra a un `Tenant` propio.
- [x] ~~Verificar plazo de retención AML~~ — **resuelto 2026-09-28: 15 años, no 5** (Art. 26, Decreto 426 — texto de la ley confirmado directamente, no de terceros). Ver sección 3.9. La función de retención/depuración en sí (punto 2/3 de la lista de funciones) sigue sin construirse — eso requiere su propio plan y confirmación antes de codificar, por tocar borrado real de datos de clientes.
- [x] ~~Que el usuario pruebe el frontend real~~ — hecho: probado por el usuario, encontró 2 problemas reales (feedback poco visible, `lanoticiasv.com` faltante), ambos corregidos y re-verificados con el caso real.
- [ ] **Cobertura de medios probablemente insuficiente** — el hallazgo de `lanoticiasv.com` fue suerte de que el usuario probó justo un caso que no cubríamos; sugiere que la lista de 6-7 dominios de la sección 4 es angosta. Esto es exactamente lo que la Fase 0 (que hace el usuario) mide con los 20 nombres reales — anotar cualquier dominio nuevo que salga de ahí.
- [x] ~~Comillas de frase exacta + `site:` a veces devuelven 0 en Brave~~ — **corregido 2026-09-28**, ver arriba.
- [x] ~~Decisión del usuario: selectores de fecha por medio~~ — **manual, sin selectores por medio** (2026-09-28); se mantiene el mecanismo genérico ya implementado (`FetchArticleJob`: meta/JSON-LD/`<time>`/fallback + `VentanaTemporal` como segunda capa). Ítem 2 de la Fase 0 (sección 5 del CLAUDE.md) queda cerrado con esta decisión. Ver `docs/poc/informe-fase-0.md` sección 2.
- [ ] **`docs/poc/informe-fase-0.md` (creado 2026-09-28): informe parcial de Fase 0.** Cerrados: comillas (ítem 3) y selectores de fecha (ítem 2). Pendientes: campaña de 20 nombres reales (ítem 1, solo 2/20 validados) y validación del prompt de extracción sobre 30 artículos (ítem 4) — ninguno de los dos se ha corrido todavía, ambos gastan cuota real.
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

### Qué se completó (bloques 20 y 21, 2026-09-28 — 13 commits `beb33c7..82cd56f` en `develop`, sin push; árbol limpio)
- Decisiones de control de la 3.9 (tabla en la sección 3.9) y plan de 4 bloques confirmado.
- Bloque A bitácora · B términos y aceptación · C retención/depuración/exportación/borrado de persona · D exportación y baja de tenant — backend + frontend, 350 tests, Playwright y MariaDB real.
- Panel de superadmin con menú lateral (Tenants, Listas de sanciones, Términos y contratos, Bitácora).
- Manual técnico: secciones 7.1, 8 (matriz), 8.1 bitácora, 8.2 protección de datos, 10 (depuración 04:00).

### Archivos tocados en los bloques 20 y 21 (2026-09-28, comiteado)
- **Backend nuevo:** `Actions/Bitacora/ListarBitacora`, `Actions/ProteccionDatos/{AceptarDocumentoLegal,BorrarSubject,DarDeBajaTenant,ExportarSubject,ExportarTenant,PublicarDocumentoLegal}`, `Http/Controllers/{BitacoraController,DocumentoLegalController,DocumentoLegalSuperadminController}`, `Http/Middleware/EnsureDocumentosAceptados`, `Jobs/DepurarDatosVencidosJob`, `Models/{Activity,AceptacionDocumento,DocumentoLegal}`, `Services/Bitacora/SerializadorBitacora`, `Services/ProteccionDatos/{EstadoDocumentosLegales,SerializadorDocumentoLegal}`, `Support/{ConfiguracionTenant,RegistroDeAccesos}`, migraciones `2026_09_28_120000/130000/140000`, tests `{Bitacora,DocumentosLegales,ProteccionDatosPersona,BajaTenant}Test`.
- **Backend modificado:** `Http/Controllers/{Auth,Configuracion,Sancion,SearchResult,Subject,Superadmin,TagSearch}Controller`, `Models/Subject`, `Policies/SubjectPolicy`, `Providers/AppServiceProvider`, `bootstrap/app.php`, `config/activitylog.php`, `routes/{api,console}.php`, `tests/{Pest.php,Feature/AlertasSeguimientoTest,Feature/SuperadminTest}`.
- **Frontend nuevo:** `components/layout/SuperadminShell`, `features/bitacora/*`, `features/configuracion/RetencionCard`, `features/consulta/ProteccionDatosSubject`, `features/documentos/useDocumentosLegales`, `features/superadmin/TenantsPanel`, `routes/_authenticated/{bitacora,documentos}/index`, `routes/superadmin/{route,sanciones,documentos,bitacora}`, `types/{bitacora,documentosLegales}`.
- **Frontend modificado:** `AppShell`, `lib/api.ts` (`delete` con body), `routes/superadmin/index`, `routes/_authenticated/{configuracion/index,subjects/$subjectId}`, `features/superadmin/useSuperadmin`, `types/{api,superadmin}`.
- **Docs:** `CLAUDE.md`, `docs/MANUAL_TECNICO.md`.

### Archivos tocados en el bloque 19 (2026-09-28, comiteado)
- **Retención AML:** `CLAUDE.md` (secciones 3.9, 9 y pendiente).
- **Fase 0 (comillas + informe, commits `2ac7d38`/`3bd0ce8`):** `backend/app/Services/Search/LimiteDeQuery.php`, `backend/tests/{Unit/LimiteDeQueryTest,Feature/RunSubjectSearchJobTest,Feature/RunTagSearchJobTest}.php`, `docs/poc/informe-fase-0.md` (nuevo).
- **Interfaz de superadmin:** backend modificado `app/Http/Controllers/SuperadminController.php`, `routes/api.php`, `tests/Feature/SuperadminTest.php`, `tests/Pest.php` (+`comoFrontend`), `tests/Feature/AuthTest.php` (quita el duplicado). Frontend modificado `features/superadmin/useSuperadmin.ts`, `routes/superadmin/index.tsx`, `types/superadmin.ts`.
- **Docs:** `docs/MANUAL_TECNICO.md` (sección 7.1 completada, sección 7 intro corregida, conteo de tests 290).

### Archivos tocados en el bloque 17 (2026-09-28, comiteado)
- **Code-review (commits `cf3abd7`, `eb279fa`):** backend modificado `Actions/Usuarios/ActualizarUsuario`, `Http/Controllers/{UsuarioController,SancionController}`, `Services/Sanctions/CruceSanciones`, `Jobs/{MatchSanctionsJob,MatchMentionsJob}`, `config/cors.php`, tests `{GestionUsuarios,CruceSanciones,EvidenciaDescarga}Test`; nuevo `Actions/Sanctions/ListarSanciones`, `Services/{Matching/PuntajeMeilisearch,Sanctions/SerializadorSancion}`, `Support/DescargaSegura`, `Http/Middleware/EnsureUserIsActive`, `tests/Pest.php` (helper `usuarioDeTenant`). Frontend: `lib/api.ts` (`download()`), `features/consulta/BuscadorSubjectAsync.tsx`, `lib/useDebouncedValue.ts`, `features/usuarios/CampoContrasena.tsx`, `features/usuarios/UsuarioDialog.tsx`, `routes/_authenticated/cuenta/index.tsx`, `features/resultados/CapturaManualDialog.tsx`.
- **Sanciones opcional + superadmin (commits `2f2f5fa`, `0a56306`):** backend nuevo `Http/Controllers/SuperadminController`, `Jobs/ActualizarListaOfacProgramadaJob`, `Models/ConfiguracionSanciones`, `Policies/TenantPolicy`, `database/factories/ConfiguracionSancionesFactory`, migraciones `add_name_y_sanciones_a_tenants`/`create_configuracion_sanciones_table`/`ensancha_subject_id_de_activity_log`, `tests/Feature/SuperadminTest`. Backend modificado: `Http/Controllers/{AuthController,InicioController,SancionController}`, `Jobs/MatchSanctionsJob`, `Providers/AppServiceProvider`, `routes/{api,console}.php`, `tests/{Pest.php,Feature/CruceSancionesTest}`. Frontend nuevo: `routes/superadmin/index.tsx`, `features/superadmin/useSuperadmin.ts`, `types/superadmin.ts`. Frontend modificado: `components/layout/AppShell.tsx`, `routes/{login,_authenticated,_authenticated/index,_authenticated/sanciones/index,_authenticated/subjects/$subjectId}.tsx`, `features/sanciones/useSanciones.ts`, `types/api.ts`.
- **Docs:** `docs/MANUAL_TECNICO.md` (sección 7.1 nueva, tabla de roles, tabla de schedule, conteo de tests, estado del proyecto, fix del `cd frontend` en la sección 7).
- Eliminado: `package-lock.json` suelto en la raíz del repo (residuo de un `npm run dev` corrido fuera de `frontend/`).

### Archivos tocados en el bloque 16 (2026-09-26, comiteado 2026-09-27)
- **Backend nuevo:** `Actions/{Sanctions/ResolverSancion,Subjects/ListarHistorialSubject,Usuarios/{CrearUsuario,ActualizarUsuario}}`, `Controllers/{CuentaController,SancionController,UsuarioController}`, `Jobs/MatchSanctionsJob`, `Policies/{SanctionMatchPolicy,UserPolicy}`, `Services/Sanctions/CruceSanciones`, migración `2026_09_26_100000_add_activo_to_users_table`, tests `{CruceSanciones,CuentaPropia,EvidenciaDescarga,GestionTags,GestionUsuarios,HistorialSubject}Test`.
- **Backend modificado:** `AuthController`, `InicioController`, `SearchResultController`, `SearchTagController`, `SubjectController`, `InitializeTenancyFromAuthenticatedUser`, `ImportSanctionListsJob`, `Models/{SanctionEntry,SanctionMatch,SearchTag,User}`, `SearchTagPolicy`, `config/{scout,vera}.php`, `lang/es/validation.php`, `routes/{api,console}.php`.
- **Frontend nuevo:** `features/{consulta/HistorialSubject,sanciones/*,usuarios/*}`, `routes/_authenticated/{cuenta,sanciones,tags,usuarios}/index.tsx`.
- **Frontend modificado:** `AppShell`, `CapturaManualDialog`, `ResultadoCard`, `useSearchTags`, `lib/api.ts`, `routes/_authenticated/index.tsx`, `routes/_authenticated/subjects/$subjectId.tsx`, `types/api.ts`.
- **Docs:** `docs/MANUAL_TECNICO.md` (estado, 261 tests, sección 4 primera carga OFAC, sección 7 usuarios de todos los perfiles, sección 8 usuarios/cuenta, sección 10 schedule de sanciones, `SANCTIONS_MATCH_SCORE_MIN`).

### Archivos tocados en esta sesión (2026-09-24)
- **Búsqueda por tags + fixes (octavo bloque):** backend nuevo: `backend/app/Models/SearchTag.php`, `backend/database/factories/SearchTagFactory.php`, `backend/database/migrations/2026_09_24_163920_...php` (subject_id nullable + tags/dias_atras) y `..._163945_create_search_tags_table.php`, `backend/app/Listeners/SembrarTagsBusquedaPorDefecto.php`, `backend/app/Jobs/RunTagSearchJob.php`, `backend/app/Actions/TagSearches/IniciarBusquedaPorTags.php`, `backend/app/Http/Controllers/{SearchTagController,TagSearchController}.php`, `backend/app/Policies/SearchTagPolicy.php`, `backend/app/Services/Search/{RestriccionDeDominios,VentanaTemporal}.php` (nuevos, extraídos de `RunSubjectSearchJob`). Backend modificado: `RunSubjectSearchJob.php` (usa los Services nuevos), `FetchArticleJob.php` (usa `VentanaTemporal::diasPara()`), `Models/{SearchResult,SearchRun}.php` (`tags`/`dias_atras`), `Models/Concerns/DerivesTenantFromSubject.php` (no deriva nada si `subject_id` es null), `Actions/SearchResults/CapturaManual.php` + `Http/Requests/CapturaManualRequest.php` (subject_id condicional), `Providers/TenancyServiceProvider.php` (registra el listener), `routes/api.php` (+4 rutas). Tests nuevos: `RunTagSearchJobTest`, `SearchTagTest`, `TagSearchTest`, +3 en `CapturaManualTest`, +1 en `RunSubjectSearchJobTest`. **`backend/phpunit.xml`** — `force="true"` + `<server>` para `APP_ENV`/`DB_DATABASE` (fix del misterio del archivo espurio). Frontend: `frontend/src/features/resultados/{useSearchResults,ResultadoCard,CapturaManualDialog}.tsx` (generalizados a `queryKey`), `frontend/src/features/coincidencias/useMatches.ts` (ídem), `frontend/src/features/resultados/useSearchTags.ts` (nuevo), `frontend/src/routes/_authenticated/busqueda-tags/index.tsx` (nuevo), `frontend/src/components/layout/AppShell.tsx` (+link), `frontend/src/types/api.ts` (`SearchTag`, `subject_id` nullable), `frontend/.gitignore` (+`.tanstack/`).
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
2. Mantenga una lista de vigilancia (clientes, empleados, proveedores) con agenda de seguimiento: el sistema notifica cuándo toca revisar a cada persona y el usuario ejecuta la revisión manualmente (sección 3.8). Sin consultas automáticas.
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
- **dompdf** (`dompdf/dompdf` 3.x, librería PHP dentro del contenedor `api` — decisión del usuario 2026-09-28, descartados Gotenberg por agregar contenedor y Cloudflare Browser Rendering por ser servicio de pago y subencargado extra): PDF **solo bajo demanda** (evidencia y reportes), nunca en el pipeline automático. Configuración cerrada: sin recursos remotos, JavaScript ni PHP embebido (`App\Services\Evidence\GeneradorPdf`).

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
                            documento opcional, nivel_riesgo, activo,
                            frecuencia_seguimiento_dias (nullable = usa default del tenant por nivel),
                            proximo_seguimiento_en, ultimo_seguimiento_en, ultimo_seguimiento_por)
                            ← campos de seguimiento: NUEVO, sección 3.8, pendiente de implementar
subject_aliases            (variantes de nombre por subject)
sources                    (medio/fuente: nombre, tipo [brave|cse|rss|oficial|sanciones], config, activo, global)
search_runs                (ejecución de búsqueda: subject_id (NULLABLE desde busqueda por tags),
                            source_id, query, metadata_query (JSON: proveedor + terminos_omitidos,
                            2026-09-25), tags[] (NUEVO), dias_atras (NUEVO), resultados, costo)
search_results             (IMPLEMENTADO 2026-09-24, sección 3.7 — un registro por resultado de
                            búsqueda: search_run_id, subject_id (NULLABLE - null si vino de una
                            busqueda por tags, sin subject_id el tenant_id ya no se deriva de un
                            subject sino del tenant ambiente), url, url_hash, titulo, snippet, medio,
                            fecha_brave, dias_atras (NUEVO), estado, http_status, gap_motivo,
                            article_id (nullable), evidencia_manual_path, descartado_por,
                            descartado_en)
search_tags                (NUEVO, busqueda por tags - catalogo por tenant: nombre, activo;
                            sembrado con 8 defaults al crear el tenant)
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
alerts                     (IMPLEMENTADO 2026-09-25: tenant_id, tipo [seguimiento_pendiente], referencia
                            polimórfica, vencimiento, canal, enviado_en; unique por alertable+vencimiento)
frecuencias_seguimiento    (IMPLEMENTADO 2026-09-25: tenant_id, nivel_riesgo [alto|medio|bajo|sin_nivel],
                            dias; auditado; sembrado al crear el tenant)
reports                    (tenant_id, tipo, parámetros, generado_por, path)
activity_log               (spatie)
```

### 3.4 Pipeline (jobs en Horizon)

Colas y prioridad: `alerts` > `matching` > `extraction` > `fetch` > `search` > `imports`.

1. `RunSubjectSearchJob` — por subject y source: construye query (nombre canónico + aliases + `site:` por medio), llama Brave **con `freshness=AAAA-MM-DDtoAAAA-MM-DD` calculado desde la ventana de la búsqueda, `spellcheck=false`, `search_lang=es` y sin `country`** (implementado 2026-09-25; `RunTagSearchJob` igual, con `dias_atras`); la query respeta 600 caracteres y 75 palabras, persiste `search_runs` y **un `search_results` por resultado en estado `nuevo`. NO encola `FetchArticleJob`** (cambio 2026-09-24, sección 3.7).
2. `FetchArticleJob` — **solo se encola por acción del analista** (`POST /api/resultados/{id}/extraer`). Recibe `search_result_id`. Descarga HTML, extrae `fecha_publicacion` de metadatos (`article:published_time`, JSON-LD, `<time>`), calcula SHA-256, sube snapshot a R2, persiste `articles`. Artículos fuera de la ventana configurada, 403, timeout, contenido vacío o no-HTML → marca el `search_result` como `gap` con `gap_motivo` y termina sin excepción (un GAP es un resultado válido, no un fallo de job).
3. `ExtractEntitiesJob` — envía texto limpio a Claude Haiku con esquema JSON estricto; valida con `spatie/laravel-data`; persiste `extractions` y `mentions`. Si `confianza < umbral` → reencola con Sonnet 5. Al terminar marca el `search_result` como `extraido` (≥1 persona) o `sin_menciones` (lista vacía).
4. `MatchMentionsJob` — por cada mention, consulta Meilisearch (índice `subjects`, filtro `tenant_id`) con tolerancia a errores; aplica normalización (unaccent, minúsculas, orden de tokens) y *rarity gate* por frecuencia de apellidos; persiste `matches` en estado `pendiente`.
5. `SendAlertJob` — correo de resumen diario por tenant con los seguimientos vencidos (sección 3.8). Un correo agrupado, no uno por sujeto. Google Sheets no aplica a este flujo.
6. `ImportSanctionListsJob` — semanal; descarga OFAC SDN (CSV/XML), ONU consolidada (XML), UE (XML); reindexa en Meilisearch (`sanction_entries`); ejecuta `MatchSanctionsJob` para todos los subjects activos.

Scheduler:
- **No existe monitoreo automático contra servicios externos de pago (decisión 2026-09-25, reemplaza la del 2026-09-24).** `RunSubjectSearchJob` solo se ejecuta por clic del usuario. El único job diario de Fase 2 consulta la BD propia: detecta `subjects` con `proximo_seguimiento_en <= hoy`, crea `alerts` tipo `seguimiento_pendiente` y encola `SendAlertJob`. Cero llamadas a Brave o Anthropic (sección 3.8).
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
- **Frontend: IMPLEMENTADO 2026-09-24**, verificado end-to-end con Playwright y datos reales (detalle en "Estatus de sesión", séptimo bloque).

**Impacto esperado:** el gasto en Anthropic pasa a ser bajo demanda (en la prueba de `Juan Carlos Pérez` se gastaron 22 llamadas en artículos irrelevantes).

### 3.8 Seguimiento de lista de vigilancia (Fase 2 — definido 2026-09-25; backend y frontend IMPLEMENTADOS 2026-09-25, verificado en navegador)

**Decisiones confirmadas (2026-09-25):** correo a `oficial_cumplimiento` + `admin`; defaults alto 30 / medio 90 / bajo 180 / sin nivel 180 días (provisionales); sin piso UIF por ahora (1..365); configuración en tabla `frecuencias_seguimiento` auditada; permisos tal como la propuesta de abajo; correo solo los días con vencimientos nuevos (informa además cuántos siguen vencidos); corte diario 07:00 `America/El_Salvador`.

**Principio:** el sistema agenda y notifica; el usuario decide y ejecuta. Ninguna consulta a Brave ni extracción IA ocurre sin clic del usuario.

**Flujo:**
1. Cada subject activo tiene `proximo_seguimiento_en`.
2. Job diario (Scheduler, sin llamadas externas) detecta vencidos → `alerts` tipo `seguimiento_pendiente` → `SendAlertJob` (resumen agrupado por tenant). Idempotente: no repite la alerta para el mismo subject y vencimiento.
3. El usuario ve los vencidos en el correo y en el panel "Seguimientos pendientes".
4. Abre el subject y, a su criterio, ejecuta "Consulta puntual" (flujo 3.7 sin cambios) o no.
5. **Marca manualmente "Seguimiento realizado"** (observación opcional). Ejecutar la consulta puntual NO lo cierra.
6. Al marcar: `ultimo_seguimiento_en`/`ultimo_seguimiento_por` = ahora/usuario; `proximo_seguimiento_en` = ahora + frecuencia efectiva. Registro en `activity_log` (observación en las propiedades).

**Frecuencia:**
- Default por `nivel_riesgo`, en días, configurado por el tenant (mecanismo de almacenamiento de la configuración del tenant: proponer en el plan y confirmar).
- Editable por subject (`frecuencia_seguimiento_dias`); `null` = usa el default de su nivel.
- Frecuencia efectiva = la del subject si existe, si no la del nivel. Cambiar `nivel_riesgo` o la frecuencia recalcula `proximo_seguimiento_en` desde `ultimo_seguimiento_en` (o desde la fecha de alta si nunca tuvo seguimiento).
- Todo cambio de frecuencia o nivel queda en `activity_log` (regla de la sección 7 sobre la lista de vigilancia).

**Resultados nuevos desde el último seguimiento:**
- `RunSubjectSearchJob` ya hace `firstOrCreate` por `subject_id`+`url_hash`: una URL ya vista no crea registro nuevo ni resetea su estado.
- "Nuevo desde el último seguimiento" = `search_results.created_at > subjects.ultimo_seguimiento_en`. Sin columna adicional.
- La vista del subject destaca esos resultados y permite filtrarlos (se suma a `FiltroEstado`).

**Frontend:**
- Panel "Seguimientos pendientes": vencidos y próximos, ordenados por vencimiento y `nivel_riesgo`, con acceso directo al subject.
- En `/subjects/$subjectId`: último y próximo seguimiento, frecuencia efectiva (indicando si es default o personalizada), botón "Seguimiento realizado".
- Configuración del tenant (solo `admin`): días por nivel de riesgo.

**Permisos (propuesta derivada de la sección 3.2, confirmar en el plan):** marcar seguimiento realizado y editar frecuencia por subject: `admin`, `oficial_cumplimiento`, `analista`. Configurar defaults del tenant: `admin`. `lectura`: solo ver.

**Fuera de alcance:** búsquedas por tags (`search_results` sin `subject_id`) no participan en la agenda de seguimiento.

**Implementación:** backend y frontend en PRs separados; presentar plan antes de codificar. Requiere SMTP configurado.

### 3.9 Protección de datos personales (decisión 2026-09-28 — funciones IMPLEMENTADAS 2026-09-28; documentos legales pendientes de redactar y validar)

**Decisión del usuario: la carga legal recae en el cliente, no en VERA.**

| Rol | Quién | Responsabilidades |
|---|---|---|
| **Responsable del tratamiento** | El cliente (tenant, sujeto obligado AML) | Decide a quién vigilar y con qué finalidad; aporta la base legal (su obligación de debida diligencia AML); informa a sus clientes/empleados/proveedores en sus propios contratos y avisos; atiende las solicitudes ARCO de las personas vigiladas; define el plazo de conservación dentro de lo que exija la normativa AML |
| **Encargado del tratamiento** | VERA | Trata los datos solo por instrucción del cliente y para la finalidad contratada; seguridad técnica; confidencialidad; ejecuta borrado/exportación cuando el cliente lo ordena; notifica brechas al cliente; declara subencargados |

**Documentos (no son código; revisar una vez con abogado y usar como plantilla):**
- Política de privacidad de VERA (cubre a los usuarios de la plataforma, no a las personas vigiladas).
- Términos de servicio: el cliente declara que es sujeto obligado, que tiene base legal para cada persona que carga y que es el responsable del tratamiento.
- **Contrato de encargo de tratamiento** con cada cliente: finalidad, instrucciones, seguridad, confidencialidad, devolución/borrado al terminar, notificación de brechas, y **subencargados y transferencias internacionales declarados**: Brave Search (EE. UU., recibe nombres en la query), Anthropic (EE. UU., recibe texto de artículos), Cloudflare R2 (evidencia), proveedor SMTP.

**Quién controla cada función (decisión del usuario 2026-09-28):**

| Función | Quién la controla |
|---|---|
| Términos y contrato (texto y versiones) | **superadmin** los edita; el admin del tenant los acepta |
| Plazo de retención por tenant | **admin de cada tenant** (cambio del usuario 2026-09-28, antes superadmin; piso legal 15 años validado en el servidor) |
| Job de depuración | **superadmin** lo habilita o deshabilita |
| Exportación y borrado de una persona | **admin de cada tenant** |
| Baja de tenant | **superadmin** |
| Consulta del registro de accesos (`activity_log`) | **admin de cada tenant** (solo su tenant, completo) **y superadmin** (todos los tenants, **sin datos personales** — opción A, decisión 2026-09-28: ve quién/qué/cuándo y "persona #id", nunca nombres, documentos ni `attribute_changes`) |

**Funciones de sistema que implica (a implementar; plan y confirmación antes de codificar):**
1. **Aceptación de términos y contrato por el tenant:** registro de quién aceptó, versión del documento y fecha (auditado). Sin aceptación, el tenant no puede cargar sujetos ni buscar.
2. **Plazo de retención configurable por tenant** (lo decide el cliente como responsable; **piso legal verificado 2026-09-28: 15 años, no 5** — Art. 26 de la Ley Contra el Lavado de Dinero y de Activos, Decreto 426, texto de la reforma vigente; el Decreto 498 original decía 5 años pero quedó superado. Dos plazos, ambos de 15 años: transacciones/documentación desde que termina cada operación; datos de identificación del cliente desde que termina la relación comercial. Un tenant puede configurar más de 15 años, nunca menos — validar ese piso cuando se construya la función).
3. **Depuración al vencer el plazo:** job programado (solo BD propia y Storage, sin servicios de pago — regla de la sección 7) que borra o anonimiza sujetos inactivos y sus datos asociados (aliases, matches, evidencia específica del tenant, captura manual). Registro en `activity_log` de qué se depuró, sin los datos borrados.
4. **Exportación de los datos de una persona** (para que el cliente atienda un derecho de acceso): todo lo que VERA tiene sobre un subject del tenant en un archivo descargable.
5. **Borrado de una persona por orden del cliente** (derecho de cancelación u oposición aceptado por el cliente), con la misma lógica que la depuración.
6. **Baja de tenant:** exportación completa y borrado de todos sus datos al terminar el contrato.
7. **Registro de accesos — IMPLEMENTADO 2026-09-28 (bloque A):** ver manual técnico sección 8.1. Antes de implementarlo: hoy audita cambios (subjects, aliases, matches, sanciones, tags, usuarios, frecuencias, seguimientos, superadmin) pero **no** lecturas ni acciones sin cambio de modelo: consulta puntual, búsqueda por tags, "sacar información", descartar (solo queda en la columna `descartado_por`), descarga de evidencia, cruce manual de sanciones, ver la ficha de una persona, login/logout.

**Almacenamiento de datos de Brave (`search_results`):** se guardan URL, título, snippet y fecha porque el flujo 3.7 necesita listar resultados sin descargarlos, conservar su estado, deduplicar, filtrar "nuevo desde el último seguimiento" y auditar qué vio el analista. Pendiente de confirmar con Brave si el plan Search lo permite. **Alternativa registrada si Brave lo niega (opción 2, no decidida):** conservar título y snippet solo mientras el resultado está en `nuevo`; al descartarlo o extraerlo, borrarlos y conservar URL, estado y los datos extraídos del propio artículo.

**Límite de esta decisión:** reparte la carga legal, no la elimina. VERA sigue obligada como encargado (seguridad, confidencialidad, notificación de brechas en 72 h según la ley, transferencias declaradas). No es asesoría legal: la plantilla contractual y la interpretación de la base legal deben validarse con un abogado salvadoreño antes del primer cliente.

---

## 4. Fuentes iniciales

Medios (vía Brave Search API, `site:` por dominio o combinado en la query — ver "Estatus de sesión" sobre el cambio desde Google CSE):
- laprensagrafica.com (**hueco conocido 2026-09-25:** Brave casi no la tiene indexada — nada reciente; su WAF responde 403 a VERA en notas, RSS y sitemap. Ver duodécimo bloque)
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

### Fase 2 — Seguimiento de lista de vigilancia (sin monitoreo automático, ver 3.8)
- Lista de vigilancia, aliases, nivel de riesgo.
- Agenda de seguimiento: frecuencia default por nivel de riesgo, editable por subject.
- Job diario solo sobre BD propia → alertas por correo (resumen agrupado) + panel de seguimientos pendientes.
- Cierre manual del seguimiento por el usuario; resultados nuevos desde el último seguimiento destacados.
- Dashboard de coincidencias pendientes. **IMPLEMENTADO 2026-09-25** (inicio `/` + bandejas `/coincidencias`), junto con la UI de gestión de la lista de vigilancia (alta con aliases, aliases, datos, activar/desactivar).

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
- Ningún job programado llama a servicios externos de pago (Brave, Anthropic). Toda consulta o extracción la dispara una acción del usuario (secciones 3.7 y 3.8).
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
ARTICLE_WINDOW_DAYS=60 (confirmado 2026-09-25, antes 30; también es el freshness de Brave en consulta puntual; configurable por tenant)
FILESYSTEM_DISK=r2, R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET
MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
GOOGLE_SHEETS_CREDENTIALS_JSON (opcional)
VERA_TIMEZONE=America/El_Salvador (corte diario de la agenda de seguimiento, sección 3.8; la app sigue en UTC)
SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN, FRONTEND_URL
```

---

## 9. Riesgos y decisiones pendientes

- Homónimos: umbral de Meilisearch y *rarity gate* se calibran en Fase 0 con datos reales; hasta entonces todo match es `pendiente`.
- Protección de datos: **decidido 2026-09-28 — cliente = responsable, VERA = encargado (sección 3.9).** Riesgos que siguen abiertos: (1) transferencias internacionales a Brave y Anthropic (EE. UU.) — deben quedar declaradas en el contrato de encargo; (2) plantilla contractual sin validación legal; (3) si la ley exigiera consentimiento de la persona vigilada en algún supuesto, el esquema debe revisarse. **Plazo de retención AML ya verificado: 15 años (Art. 26, Decreto 426) — resuelto, ver sección 3.9.**
- Cuota de búsqueda (Brave Search, antes Google CSE): desde el 2026-09-25 ningún proceso automático consume Brave; todo consumo es por acción del usuario. El candado duro `BraveSearchAdapter::reservarCupoMensual()` sigue siendo el único tope. La priorización por scheduler quedó descartada (sección 3.8).
- Brave — derechos de almacenamiento: Brave exige un plan con derechos de almacenamiento para guardar resultados total o parcialmente. `search_runs` ya guardaba URLs y `search_results` (3.7) guardará título y snippet. Confirmar con Brave por escrito antes de tener clientes pagando.
- Brave — costo: sin tope de gasto del proveedor (esto sigue siendo cierto); **`BRAVE_SEARCH_MONTHLY_LIMIT` ya se resolvió: 1000** (confirmado 2026-09-24, coincide exacto con el crédito gratis mensual — precio real confirmado por el usuario desde la propia página de precios de Brave: $5/1000 requests, $5 de crédito automático cada mes, 50 req/seg). Dónde va la atribución pública "Powered by Brave" sigue sin decidir — falta antes de producción si se quiere seguir conservando ese crédito.
- ~~Captura manual sin adjunto~~ — **resuelto 2026-09-24: sí lleva adjunto, en PDF** (ver sección 3.7 "Captura manual"). Ya no es un riesgo abierto.
- Seguimiento manual (3.8): la detección depende de que el usuario atienda las notificaciones. El sistema no lo compensa, pero deja constancia en `activity_log` de alertas enviadas, seguimientos realizados y vencidos sin atender (útil ante revisión de la UIF).
- `freshness` de Brave: filtra por antigüedad de la página (fecha de publicación o de última modificación reportada por el contenido). Puede colar artículos viejos modificados recientemente (los atrapa `VentanaTemporal`) y excluir páginas sin fecha detectable por Brave. Con el filtro en origen, lo que queda fuera de la ventana ya no se ve ni como GAP.
- `spellcheck` de Brave activo por default: altera nombres propios y Brave busca con la query alterada. Mientras no se envíe `spellcheck=false`, los resultados de nombres poco comunes no son confiables (afecta la prueba de recall de Fase 0).
- `country` no se envía (default de Brave: `US`): `SV` no es un valor aceptado; el ranking puede sesgarse, pero los `site:` restringen los medios. `search_lang=es` sí se envía.
- Ventana temporal: `ARTICLE_WINDOW_DAYS=60` (decidido 2026-09-25). Con `freshness` en origen, condenas de hace más de 60 días ya no aparecen en la consulta puntual; la búsqueda por tags admite `dias_atras` propio (máx. 365).
- Cobertura por medio depende del índice de Brave: laprensagrafica.com prácticamente no aporta resultados recientes (hallazgo 2026-09-25). Un "sin resultados" de VERA no garantiza que un medio no haya publicado sobre la persona — relevante para cómo se presenta el resultado al oficial de cumplimiento y ante la UIF.
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
