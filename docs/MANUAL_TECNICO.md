# VERA — Manual Técnico

> Plataforma SaaS multi-tenant de *adverse media screening* y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador), con expansión prevista a Guatemala, Honduras y Costa Rica.
> Arquitectura multi-tenant de base de datos única (`tenant_id` + global scope), API REST en Laravel, frontend SPA en React, todo en Docker Compose (backend) + Node.js en el host (frontend).

> **Estado del proyecto (2026-09-28):** Fase 1 (MVP) y Fase 2 (agenda de seguimiento de la lista de vigilancia) completas e implementadas — backend y frontend, verificadas con datos reales contra MariaDB y con Playwright (Node, no el `webapp-testing` de Python — sin `pip` en esta máquina). **350 tests de backend pasan.** Interfaz completa salvo navegación en móvil (aplazada): evidencia descargable, historial de auditoría, catálogo de tags, usuarios del tenant, cuenta propia, sanciones OFAC (deshabilitada por defecto, la habilita el superadmin por tenant — sección 7.1) y atribución a Brave. Panel de superadmin con menú lateral (`/superadmin`: tenants, listas de sanciones, términos y contratos, bitácora). Funciones de protección de datos completas (sección 8.2): bitácora de accesos, términos con aceptación por tenant, retención y depuración, exportación y borrado de una persona, baja de tenant. Pendientes principales: navegación en móvil, calibrar el umbral de sanciones, despliegue real a producción (nunca se ha desplegado fuera de desarrollo) y SMTP real. Detalle completo en el `CLAUDE.md` de la raíz del repositorio, secciones "Estatus de sesión" y "Pendiente / próximo paso".

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Stack tecnológico](#2-stack-tecnológico)
3. [Arquitectura](#3-arquitectura)
4. [Instalación desde cero](#4-instalación-desde-cero)
5. [Configuración del entorno y servicios de terceros](#5-configuración-del-entorno-y-servicios-de-terceros)
6. [Base de datos](#6-base-de-datos)
7. [Gestión de tenants](#7-gestión-de-tenants)
8. [Roles y permisos](#8-roles-y-permisos)
9. [Backup y recuperación](#9-backup-y-recuperación)
10. [Schedule de tareas](#10-schedule-de-tareas)
11. [Despliegue en producción](#11-despliegue-en-producción)
12. [Estructura del proyecto](#12-estructura-del-proyecto)
13. [Convenciones de código](#13-convenciones-de-código)
14. [Referencia rápida de comandos](#14-referencia-rápida-de-comandos)
15. [Feature Tests](#15-feature-tests)
16. [Solución de problemas](#16-solución-de-problemas)

**Apéndices**
- [Apéndice A — SSL/TLS con Let's Encrypt](#apéndice-a--ssltls-con-lets-encrypt)
- [Apéndice B — Reverse proxy delante de Docker (Apache2 o Nginx)](#apéndice-b--reverse-proxy-delante-de-docker-apache2-o-nginx)
- [Apéndice C — Docker Compose en detalle](#apéndice-c--docker-compose-en-detalle)
- [Apéndice D — Migración de Google CSE a Brave Search](#apéndice-d--migración-de-google-cse-a-brave-search)

---

## 1. Requisitos

### Desarrollo (Windows/Linux/Mac)

| Componente | Versión | Dónde corre |
|------------|---------|-------------|
| Docker Desktop (o Docker Engine + Compose v2 en Linux) | 24+ | Backend completo (API, MariaDB, Meilisearch, Redis en dev) |
| Git | 2.40+ | Host |
| Node.js | 20+ (verificado con 22) | Host — el frontend **no** corre en Docker |
| npm | 10+ | Host |

> **IMPORTANTE:** PHP, Composer, MariaDB, Redis y Meilisearch **no se instalan en la máquina de desarrollo**. Todo el backend se construye y corre dentro de los contenedores definidos en `docker-compose.yml` — decisión confirmada del proyecto (`CLAUDE.md` raíz, sección "Decisiones confirmadas" y sección 7). El frontend es la única excepción: es una SPA estática que se compila con Node/Vite directamente en el host (no hay contenedor `frontend` en `docker-compose.yml`).

### Producción (Linux, VPS)

| Componente | Versión |
|------------|---------|
| Ubuntu Server (o distribución equivalente) | 22.04 LTS+ |
| Docker Engine + Compose v2 | 24+ |
| Apache2 **o** Nginx (reverse proxy con TLS, fuera de Docker) | Apache 2.4+ / Nginx 1.24+ |
| Certbot (Let's Encrypt) | última estable |

**Presupuesto de recursos (VPS de referencia):** 2 vCore, 4 GB RAM, 120 GB NVMe. RAM del producto: máximo 600 MB. Horizon: máximo 3 workers. No agregar contenedores fuera de `api`, `mariadb`, `meilisearch` (Redis va compartido con el host en producción, no en un contenedor propio — ver `docker-compose.yml` vs. `docker-compose.override.yml` en la sección 3).

---

## 2. Stack tecnológico

| Capa | Tecnología | Versión |
|------|-----------|---------|
| Backend | PHP | 8.4 (contenedor `api`; `php:8.4-fpm`) |
| Backend | Laravel | 13 |
| Auth | Laravel Sanctum (SPA, cookies) | — |
| Colas | Laravel Horizon + Redis | 7 |
| Multi-tenancy | `stancl/tenancy` (BD única + `tenant_id`) | v3 |
| Roles/permisos | `spatie/laravel-permission` | — |
| Auditoría | `spatie/laravel-activitylog` | v5 |
| DTOs/validación IA | `spatie/laravel-data` | — |
| Búsqueda | Laravel Scout + Meilisearch | v1.11 |
| Base de datos | MariaDB | 11 |
| Frontend | React + Vite + TypeScript (`strict: true`) | React 19 · Vite 8 · TS 6 |
| Frontend | Tailwind CSS | v4 |
| Frontend | shadcn/ui (Radix) | — |
| Frontend | TanStack Query + TanStack Router (file-based) | — |
| Testing backend | Pest | v4 |
| IA | Anthropic Claude (`claude-haiku-4-5-20251001` extracción, `claude-sonnet-5` escalado) | — |
| Búsqueda en medios | **Brave Search API** (fuente activa) | — |
| Búsqueda en medios (legado) | Google Custom Search JSON API — código intacto, **inactivo**, cierra en enero 2027 (ver Apéndice D) | — |
| Almacenamiento de evidencia automática | Cloudflare R2 (driver S3 de Laravel) | — |
| Almacenamiento de evidencia manual | Disco configurable, independiente del anterior (`EVIDENCIA_MANUAL_DISK`, default `local`) | — |
| PDF bajo demanda | Gotenberg **o** Cloudflare Browser Rendering | **sin decidir** — ver sección 5.6 |
| Despliegue frontend | Cloudflare Pages | **pendiente** — nunca se ha desplegado, solo corre en `localhost:5173` |
| Infraestructura | Docker Compose + Apache2/Nginx (reverse proxy) | — |
| Cliente API tipado desde OpenAPI | `dedoc/scramble` — decidido, **no instalado todavía** | — |

---

## 3. Arquitectura

**Multi-tenancy:** un tenant = un sujeto obligado (empresa cliente). Base de datos única; todo modelo de negocio lleva `tenant_id` con global scope (`Stancl\Tenancy\Database\Concerns\BelongsToTenant`). El tenant actual se resuelve por el `tenant_id` del usuario autenticado vía Sanctum (`App\Http\Middleware\InitializeTenancyFromAuthenticatedUser`, alias `tenant`), **no** por dominio/subdominio. `superadmin` recibe 403 en toda ruta de tenant — su alcance (Fase 3) es aparte.

**Flujo de un request de negocio:**
```
Cliente (SPA) → Sanctum (cookie de sesión) → auth:sanctum
             → tenant (resuelve tenant_id del usuario, inicializa tenancy())
             → Controller (delgado) → Action/Service → Modelo (global scope tenant_id)
```

Por seguridad de aislamiento entre tenants, `InitializeTenancyFromAuthenticatedUser` se antepone explícitamente a `SubstituteBindings` en `bootstrap/app.php` (`prependToPriorityList`) — sin esto, un modelo con route-model-binding (`Subject $subject`) se resolvería antes de inicializar el tenant, permitiendo IDOR entre tenants.

**Pipeline de screening (jobs en Horizon, colas por prioridad `alerts` > `matching` > `extraction` > `fetch` > `search` > `imports`):**

```
RunSubjectSearchJob / RunTagSearchJob   → un search_result por resultado de Brave (estado "nuevo"),
                                           marca GAP "fuera_de_ventana" en el momento si la fecha de
                                           Brave ya cae fuera de ARTICLE_WINDOW_DAYS. NO descarga nada.
        │
        │  (solo si el analista pulsa "Sacar información de noticia" o hace captura manual)
        ▼
FetchArticleJob   → descarga, valida fecha real/contenido/Content-Type, hash SHA-256, evidencia en
                     Storage. Fallo (403/timeout/sin contenido/etc.) → marca GAP con su motivo, sin
                     lanzar excepción (un GAP es un resultado válido, no un fallo de job).
        ▼
ExtractEntitiesJob   → Claude Haiku (o Sonnet si escala por baja confianza), crea mentions.
        ▼
MatchMentionsJob   → busca en Meilisearch (todos los tenants), crea matches "pendiente".
        ▼
(proponer → resolver, por HTTP, dos pasos — ver sección 8)
```

**Agenda de seguimiento (sección 3.8, sin llamadas a servicios de pago):**
```
DetectarSeguimientosVencidosJob (diario 07:00 America/El_Salvador)
    → detecta subjects con proximo_seguimiento_en <= hoy (solo BD propia)
    → crea "alerts" (idempotente por tenant+alertable+vencimiento)
    → encola SendAlertJob (un correo agrupado por tenant a oficial_cumplimiento + admin)
```

**Reconciliación del índice de búsqueda (red de seguridad, sin costo):**
```
ReconciliarIndiceSubjectsJob (diario 03:00 America/El_Salvador)
    → reindexa en Meilisearch todos los subjects activos/inactivos según la BD
      (guardar un SubjectAlias no dispara el observer de Scout del Subject padre)
```

**Búsqueda por tags** (paralela a la consulta por subject, sin resultado atribuido a nadie todavía): cada tenant tiene un catálogo `search_tags` (sembrado con 8 delitos por defecto al crear el tenant). `RunTagSearchJob` construye la query con tags en vez de nombre/aliases; los `search_results` resultantes tienen `subject_id` nulo hasta que alguien los resuelve (captura manual pide elegir a qué persona de la lista de vigilancia se atribuye el hallazgo).

**Evidencia:**
- Automática (`FetchArticleJob`): snapshot HTML + hash SHA-256 + timestamp UTC, inmutable, en `FILESYSTEM_DISK` (R2 en producción) — **global**, sin prefijo de tenant (`articles` es un catálogo deduplicado por URL, no pertenece a un tenant).
- Manual (captura manual de un GAP): PDF subido por el analista, obligatorio, en `EVIDENCIA_MANUAL_DISK` (independiente del disco anterior — decisión explícita para no depender de R2 en dev), prefijo `tenants/{tenant_id}/evidencia-manual/{search_result_id}.pdf` (esta sí es específica de un tenant).

---

## 4. Instalación desde cero

```bash
git clone <url-del-repo> vera
cd vera

# Secretos del stack (mariadb, meilisearch, redis en dev)
cp .env.example .env
# Editar .env y completar las variables obligatorias (ver seccion 5)

# Secretos de la aplicacion Laravel
cp backend/.env.example backend/.env
# IMPORTANTE: MEILISEARCH_KEY en backend/.env debe ser IDENTICO al .env de
# la raiz (son procesos separados, si no coinciden el contenedor api no
# puede autenticarse contra meilisearch). DB_PASSWORD tambien debe coincidir.

# Construir e iniciar los contenedores del backend
docker compose up -d --build
```

El `entrypoint.sh` del contenedor `api` ya hace, automáticamente, cada vez que arranca:
- Copia `.env.example` → `.env` si no existe.
- `composer install` si no existe `vendor/`.
- Espera a que MariaDB responda.
- `php artisan key:generate --force` si `APP_KEY` está vacía.
- `php artisan migrate --force`.

Falta hacer a mano, solo la primera vez:

```bash
# Sembrar los roles base (idempotente)
docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder

# Sincronizar los atributos filtrables del indice de Meilisearch (tenant_id).
# SIN ESTO cualquier busqueda/match falla con
# "Attribute `tenant_id` is not filterable" - paso real que falta la
# primera vez que se levanta un Meilisearch nuevo, no esta en el
# scheduler porque solo hace falta una vez (o si se cambian los
# atributos filtrables/ordenables del modelo Subject).
docker exec vera_api php artisan scout:sync-index-settings   # cubre los indices subjects y sanction_entries

# Primera carga de la lista OFAC SDN (despues corre sola cada domingo, seccion 10).
# Sin esto el cruce de sanciones no tiene contra que comparar. Descarga publica y gratuita.
docker exec vera_api php artisan tinker --execute='dispatch_sync(new App\Jobs\ImportSanctionListsJob("ofac_sdn"));'
```

Verificar que levantó todo:

```bash
docker ps --format "table {{.Names}}\t{{.Status}}"
curl -i http://localhost:8000/api/user   # debe responder 401 (Sanctum activo, sin sesion)
docker exec vera_api php artisan test    # deben pasar 350 tests
```

API en `http://localhost:8000`, Meilisearch en `:7700`, MariaDB en `:3306`, Redis en `:6379` (contenedor propio solo en dev, vía `docker-compose.override.yml`).

### Frontend

```bash
cd frontend
cp .env.example .env      # VITE_API_URL=http://localhost:8000
npm install
npm run dev                # http://localhost:5173
```

Para probar el login real hace falta un usuario con contraseña conocida (ver sección 7, "Crear un usuario de cada perfil" — `vera:demo` crea un token Sanctum para curl, no una contraseña para el login por cookies del frontend).

---

## 5. Configuración del entorno y servicios de terceros

### 5.1 Variables de entorno — desarrollo (`.env` en la raíz)

```ini
# ── App / stack ──────────────────────────────────────────────────
APP_ENV=local

# ── Base de datos (contenedor mariadb) ───────────────────────────
DB_DATABASE=vera
DB_USERNAME=vera
DB_PASSWORD=tu_password_seguro
DB_ROOT_PASSWORD=otro_password_seguro

# ── Meilisearch ───────────────────────────────────────────────────
MEILISEARCH_KEY=una_master_key_larga_y_aleatoria

# ── Redis ──────────────────────────────────────────────────────────
# Solo relevante en produccion (docker-compose.yml sin el override de
# desarrollo). En desarrollo, docker-compose.override.yml fija
# REDIS_HOST=redis automaticamente y este valor se ignora.
REDIS_HOST=host.docker.internal
```

### 5.2 Variables de entorno — `backend/.env` (aplicación Laravel)

```ini
APP_ENV=local
APP_KEY=                          # generado por el entrypoint (o: php artisan key:generate)
APP_URL=http://localhost:8000
APP_LOCALE=es                     # mensajes de validacion en espanol (lang/es/validation.php)
APP_FALLBACK_LOCALE=en

# ── Base de datos ────────────────────────────────────────────────
DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_DATABASE=vera
DB_USERNAME=vera
DB_PASSWORD=tu_password_seguro

# ── Colas / cache ────────────────────────────────────────────────
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=redis                  # en produccion: host.docker.internal (ver seccion 11)

# ── Busqueda ─────────────────────────────────────────────────────
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=una_master_key_larga_y_aleatoria   # debe coincidir con el .env raiz

# ── Sanctum (SPA) / CORS ──────────────────────────────────────────
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173   # tambien usado por config/cors.php y por los links del correo de seguimiento

# ── Brave Search (fuente activa) ──────────────────────────────────
BRAVE_SEARCH_API_KEY=
BRAVE_SEARCH_MONTHLY_LIMIT=1000   # coincide con el credito gratis mensual real de Brave ($5/1000 req)

# ── Google Custom Search (legado, inactivo - ver Apendice D) ──────
GOOGLE_CSE_API_KEY=
GOOGLE_CSE_CX=
GOOGLE_CSE_DAILY_LIMIT=100

# ── Anthropic Claude ─────────────────────────────────────────────
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL_FAST=claude-haiku-4-5-20251001
ANTHROPIC_MODEL_ESCALATION=claude-sonnet-5
EXTRACTION_CONFIDENCE_THRESHOLD=0.6

# ── Ventana de busqueda / matching ────────────────────────────────
ARTICLE_WINDOW_DAYS=60            # tambien es el "freshness" que se envia a Brave en origen
MATCH_SCORE_THRESHOLD=            # sin definir todavia - ver seccion 9 del CLAUDE.md raiz

# ── Zona horaria de negocio (agenda de seguimiento) ───────────────
SANCTIONS_MATCH_SCORE_MIN=85       # PROVISIONAL (0-100): puntaje minimo de Meilisearch para registrar un hallazgo de sanciones; calibrar en Fase 0
VERA_TIMEZONE=America/El_Salvador  # la app corre en UTC; esto es solo para el corte diario de vencimientos

# ── Cloudflare R2 (evidencia automatica) ──────────────────────────
FILESYSTEM_DISK=r2                # en dev/test queda "local" salvo que se configure
R2_ACCOUNT_ID=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=vera-evidence
R2_URL=

# ── Evidencia manual (captura manual, seccion 3.7) ────────────────
# INDEPENDIENTE de FILESYSTEM_DISK a proposito - se puede dejar en
# "local" en produccion sin depender de R2 hasta que se decida a proposito.
EVIDENCIA_MANUAL_DISK=local

# ── Correo (alertas de seguimiento) ───────────────────────────────
MAIL_MAILER=log                   # "log" en dev: el correo queda en storage/logs/laravel.log
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=

# ── Google Sheets (opcional) ───────────────────────────────────────
GOOGLE_SHEETS_CREDENTIALS_JSON=
```

> **IMPORTANTE:** `MEILISEARCH_KEY` debe ser exactamente el mismo valor en el `.env` de la raíz (que lo inyecta al contenedor `meilisearch`) y en `backend/.env` (que lo usa el cliente de Scout dentro de `api`) — son dos archivos distintos leídos por dos servicios distintos.

### 5.3 Brave Search API (búsqueda en medios salvadoreños — fuente activa)

Reemplazó a Google Custom Search desde el 2026-09-24 (ver Apéndice D). Es el servicio que consulta los 7 medios de la sección 4 del `CLAUDE.md` raíz (`laprensagrafica.com`, `elsalvador.com`, `diarioelmundo.com`, `lapagina.com.sv`, `diario1.com`, `elmundo.sv`, `lanoticiasv.com`) vía `site:` en la query — Brave no tiene el equivalente al `cx` multi-sitio de Google, así que la restricción de dominio vive en la query misma (`App\Services\Search\RestriccionDeDominios`).

1. Crear cuenta en [api-dashboard.search.brave.com](https://api-dashboard.search.brave.com/) — solo pide una API key, sin el flujo de proyecto+facturación de Google Cloud.
2. Copiar la key a `BRAVE_SEARCH_API_KEY`.
3. **Facturación real (confirmar antes de producción):** desde el 12-feb-2026 Brave ya no tiene tier gratuito — da $5 de crédito automático cada mes (~1,000 requests a $5/1000), tarjeta obligatoria como instrumento de cobro activo, **sin tope de gasto de su lado**. El único freno real es `BRAVE_SEARCH_MONTHLY_LIMIT` (contador atómico en cache, reservado antes de cada llamada — `BraveSearchAdapter::reservarCupoMensual()`). El crédito exige atribución pública ("Powered by Brave") para conservarse — **ubicación sin decidir todavía** en el frontend.
4. **Guardar resultados requiere un plan con derechos de almacenamiento** — `search_results` guarda título y snippet (sección 3.7). Confirmar con Brave por escrito antes de tener clientes pagando (riesgo abierto, sección 9 del `CLAUDE.md` raíz).
5. Parámetros que envía `BraveSearchAdapter` (verificados contra la referencia oficial 2026-09-25): `spellcheck=false` (Brave altera nombres propios poco comunes si no se desactiva), `search_lang=es`, `freshness=(hoy−ARTICLE_WINDOW_DAYS)to(hoy)` en UTC, sin `country` (`SV` no es un valor aceptado). La query respeta 600 caracteres y 75 palabras (`App\Services\Search\LimiteDeQuery`); si no cabe, se omiten aliases/tags desde el final, nunca el nombre canónico ni los `site:`.
6. **Hueco de cobertura conocido:** el índice de Brave para `laprensagrafica.com` está atrasado meses — con `freshness` de 60 días no aporta resultados recientes. Aceptado como limitación conocida (decisión del usuario, 2026-09-25) mientras no se resuelva con el medio o con un adaptador RSS/sitemap.

### 5.4 Google Custom Search JSON API (legado, código intacto, inactivo)

Ver Apéndice D para el detalle completo de la migración y de cómo retomarlo si hiciera falta antes de que Google la apague (enero 2027).

### 5.5 Anthropic Claude API (extracción de entidades)

1. Crear cuenta en [console.anthropic.com](https://console.anthropic.com/).
2. Cargar créditos/método de pago (Settings → Billing) — sin esto las llamadas fallan aunque la API key sea válida.
3. **API Keys → Create Key**. Copiar el valor a `ANTHROPIC_API_KEY`.
4. `ANTHROPIC_MODEL_FAST` (`claude-haiku-4-5-20251001`, con sufijo de fecha — un id sin fecha no es válido) para extracción masiva; `ANTHROPIC_MODEL_ESCALATION` (`claude-sonnet-5`) para los casos que escalan por `confianza_global < EXTRACTION_CONFIDENCE_THRESHOLD` o más de 3 personas con roles cruzados (sección 3.5 del `CLAUDE.md` raíz).
5. El gasto es bajo demanda desde la sección 3.7 (2026-09-24): ninguna extracción ocurre sin que el analista pulse "Sacar información de noticia" o falle el fetch y se capture a mano.

> El stack de IA está cerrado a Anthropic (sección 7 del `CLAUDE.md` raíz: "no se abre a OpenAI ni otros proveedores por ahora"). No agregar otro proveedor sin confirmación explícita del propietario.

### 5.6 Cloudflare R2 (evidencia automática)

1. **R2 → Create bucket**. Nombre sugerido: `vera-evidence` (`R2_BUCKET`).
2. **R2 → Manage API tokens → Create API token** con lectura/escritura sobre ese bucket. Copiar **Access Key ID** y **Secret Access Key**.
3. El **Account ID** aparece en la barra lateral del dashboard de Cloudflare. Va en `R2_ACCOUNT_ID`.
4. `FILESYSTEM_DISK=r2` activa el disco `r2` de `config/filesystems.php` (driver S3 nativo de Laravel, compatible con la API S3 de R2 — endpoint `https://{R2_ACCOUNT_ID}.r2.cloudflarestorage.com`, `use_path_style_endpoint: true`).
5. Objetos globales bajo la raíz del bucket (sin prefijo de tenant — `articles` es un catálogo deduplicado por URL, sección 3.1).

### 5.7 Evidencia manual — disco independiente (`EVIDENCIA_MANUAL_DISK`)

A propósito **independiente** de `FILESYSTEM_DISK` (decisión explícita del propietario): la captura manual de un GAP puede quedar en `local` tanto en desarrollo como en producción sin depender de R2. Si se quiere subir a R2 también, apuntar `EVIDENCIA_MANUAL_DISK=r2` (reutiliza el mismo disco de la sección 5.6) — no es obligatorio.

### 5.8 PDF bajo demanda: Gotenberg o Cloudflare Browser Rendering (decisión pendiente)

Sigue sin decidirse. Los dos caminos, para cuando se resuelva:

- **Gotenberg** (self-hosted, requiere un contenedor adicional): rompería el presupuesto de "no agregar contenedores" salvo que corra en un VPS/servicio aparte, o bajo demanda vía un job que lo levanta puntualmente. Ver [gotenberg.dev](https://gotenberg.dev/).
- **Cloudflare Browser Rendering**: servicio administrado, sin contenedor propio — más alineado con el presupuesto de RAM del VPS. Mismo dashboard donde se gestiona R2 y Pages.

**No implementar ninguno sin confirmar con el propietario del proyecto.**

### 5.9 SMTP (alertas por correo — agenda de seguimiento, sección 3.8)

Cualquier proveedor SMTP del dominio del cliente sirve — el proyecto no fija uno. Configurar `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`. En desarrollo, `MAIL_MAILER=log` deja el correo de resumen diario en `storage/logs/laravel.log` en vez de enviarlo — **sin SMTP real, el resumen de seguimientos no sale del servidor en producción.** Si un tenant no tiene usuarios `oficial_cumplimiento` ni `admin`, la alerta queda pendiente de envío (se reintenta el siguiente corte diario) y se registra un warning en el log. **No usar Slack para alertas** (sección 7) — el canal es correo y, opcionalmente, Google Sheets.

### 5.10 Google Sheets API (opcional — exportación de alertas)

1. En Google Cloud Console, habilitar **Google Sheets API**.
2. Crear una **cuenta de servicio** y generar una clave JSON; guardar su contenido en `GOOGLE_SHEETS_CREDENTIALS_JSON`.
3. Compartir la hoja de cálculo de destino con el email de la cuenta de servicio (`...@<proyecto>.iam.gserviceaccount.com`), permiso de editor.
4. Opcional — vacío, el pipeline solo envía alertas por correo.

---

## 6. Base de datos

MariaDB 11, base de datos única (sección 3.1) — **nunca** una base de datos por tenant, ni el `DatabaseTenancyBootstrapper` de `stancl/tenancy` (deshabilitado a propósito en `config/tenancy.php`).

```bash
docker exec vera_api php artisan migrate --force
docker exec vera_api php artisan migrate:rollback --step=1   # nunca en produccion sin backup previo
docker exec vera_api php artisan migrate:status
```

**No usar FULLTEXT de MariaDB para nombres** — el matching fuzzy va por Meilisearch, con `tenant_id` como atributo filtrable en el índice `subjects`.

> **IMPORTANTE — paso que se olvida fácil:** después de `migrate` en una instancia de Meilisearch nueva (o si cambian los atributos filtrables/ordenables de `Subject::toSearchableArray()`), correr `php artisan scout:sync-index-settings`. Sin esto, cualquier búsqueda o match falla con `Attribute 'tenant_id' is not filterable` — no es un bug del pipeline, es un paso de configuración de índice que Scout no aplica solo. Ver sección 4 y 16.

**Tablas de negocio principales** (detalle completo del modelo en la sección 3.3 del `CLAUDE.md` raíz): `tenants`, `users`, `subjects` (con `nivel_riesgo`, `frecuencia_seguimiento_dias`, `proximo_seguimiento_en`, `ultimo_seguimiento_en`), `subject_aliases`, `sources`, `search_runs` (`subject_id` nullable, `metadata_query`, `tags[]`, `dias_atras`), `search_results` (`estado`, `gap_motivo`, `evidencia_manual_path`), `search_tags`, `articles` (global, único por URL), `extractions`, `mentions` (`origen` automático/manual), `matches` (`propuesta_estado`/`resuelto_por` — dos pasos), `sanction_lists`/`sanction_entries`/`sanction_matches`, `alerts`, `frecuencias_seguimiento`, `activity_log` (spatie).

---

## 7. Gestión de tenants

**Desde el panel de superadmin (sección 7.1), recomendado en producción:** `/superadmin` crea el tenant junto con su primer usuario admin en un solo paso. Para probar rápido en dev, o para casos que el panel no cubre todavía (más usuarios, tokens sin frontend), quedan estos dos caminos:

### Por comando (recomendado para probar rápido)

```bash
docker exec vera_api php artisan vera:demo "Nombre a buscar"
```

Crea tenant + usuario + `Source` tipo `brave` activa + `Subject` de prueba + token Sanctum, e imprime los `curl` listos. **Se niega en `production`.** El token que crea sirve para probar por `curl`, no para el login por cookies del frontend (no tiene contraseña).

### Por tinker (control fino, o para crear un usuario con contraseña)

```bash
docker exec -it vera_api php artisan tinker
```

```php
$tenant = \Stancl\Tenancy\Database\Models\Tenant::create();

$user = \App\Models\User::factory()->create([
    'name' => 'Oficial de Cumplimiento',
    'email' => 'oficial@ejemplo.com',
    'password' => \Illuminate\Support\Facades\Hash::make('una_password_segura'),
]);
$user->forceFill(['tenant_id' => $tenant->id])->save();
$user->assignRole('oficial_cumplimiento'); // ver seccion 8

// Solo si se necesita un Bearer token para probar sin frontend:
$user->createToken('manual')->plainTextToken;
```

Con el token: `curl -H "Authorization: Bearer <token>" http://localhost:8000/api/subjects`.
Con contraseña, el login real es por el frontend (`/login`, cookies de Sanctum) o replicando el handshake CSRF por curl (`GET /sanctum/csrf-cookie` → `POST /api/login` con el header `X-XSRF-TOKEN`).

### Crear un usuario de cada perfil (para probar el login y los permisos)

Requisitos previos, en este orden (si falta alguno el paso falla):

1. Contenedores arriba (`docker compose up -d`).
2. Roles sembrados (sección 4): `docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder`.
3. **Un tenant existente.** Créalo con `docker exec vera_api php artisan vera:demo "Juan Perez"` (o con `Tenant::create()` de arriba). Sin tenant, los usuarios de negocio quedan sin `tenant_id` y no pueden entrar a nada.

Un comando por usuario. Cada uno va en **una sola línea** (cópialo completo, sin partirlo):

```bash
# superadmin
docker exec vera_api php artisan tinker --execute='$u = App\Models\User::factory()->create(["name"=>"Superadmin Demo","email"=>"superadmin@vera.test","password"=>Hash::make("Demo1234!")]); $u->forceFill(["tenant_id"=>null])->save(); $u->assignRole("superadmin"); echo "ok\n";'
```

```bash
# admin
docker exec vera_api php artisan tinker --execute='$u = App\Models\User::factory()->create(["name"=>"Admin Demo","email"=>"admin@vera.test","password"=>Hash::make("Demo1234!")]); $u->forceFill(["tenant_id"=>Stancl\Tenancy\Database\Models\Tenant::first()->id])->save(); $u->assignRole("admin"); echo "ok\n";'
```

```bash
# oficial_cumplimiento
docker exec vera_api php artisan tinker --execute='$u = App\Models\User::factory()->create(["name"=>"Oficial_cumplimiento Demo","email"=>"oficial-cumplimiento@vera.test","password"=>Hash::make("Demo1234!")]); $u->forceFill(["tenant_id"=>Stancl\Tenancy\Database\Models\Tenant::first()->id])->save(); $u->assignRole("oficial_cumplimiento"); echo "ok\n";'
```

```bash
# analista
docker exec vera_api php artisan tinker --execute='$u = App\Models\User::factory()->create(["name"=>"Analista Demo","email"=>"analista@vera.test","password"=>Hash::make("Demo1234!")]); $u->forceFill(["tenant_id"=>Stancl\Tenancy\Database\Models\Tenant::first()->id])->save(); $u->assignRole("analista"); echo "ok\n";'
```

```bash
# lectura
docker exec vera_api php artisan tinker --execute='$u = App\Models\User::factory()->create(["name"=>"Lectura Demo","email"=>"lectura@vera.test","password"=>Hash::make("Demo1234!")]); $u->forceFill(["tenant_id"=>Stancl\Tenancy\Database\Models\Tenant::first()->id])->save(); $u->assignRole("lectura"); echo "ok\n";'
```

Usuarios resultantes (contraseña de todos: `Demo1234!`, solo para desarrollo):

| Email | Rol | Tenant | Qué se puede probar |
|-------|-----|--------|---------------------|
| `superadmin@vera.test` | `superadmin` | ninguno | El login funciona, pero toda ruta de tenant responde 403 (comportamiento esperado, sección 8) |
| `admin@vera.test` | `admin` | el primero | Todo, incluida la pantalla Configuración (editar frecuencias de seguimiento) |
| `oficial-cumplimiento@vera.test` | `oficial_cumplimiento` | el primero | Resolver coincidencias, captura manual, activar/desactivar sujetos |
| `analista@vera.test` | `analista` | el primero | Proponer (no resolver), alta y edición de sujetos; sin captura manual ni desactivar |
| `lectura@vera.test` | `lectura` | el primero | Solo consulta |

> **IMPORTANTE:** el email es único. Si ejecutas el bloque dos veces falla con "Duplicate entry"; borra los usuarios previos o cambia los emails. Estas contraseñas son solo de desarrollo: en producción usa contraseñas fuertes y únicas.

Probar el login en `http://localhost:5173/login`. Si el frontend no está corriendo:

```bash
cd frontend   # el package.json vive ahi, no en la raiz del repo
npm run dev
```

### 7.1 Panel de superadmin (`/superadmin`, implementado 2026-09-28)

Menú lateral propio (`routes/superadmin/route.tsx` + `SuperadminShell`): **Tenants** (`/superadmin`), **Listas de sanciones** (`/superadmin/sanciones`: estado de la última importación de OFAC, versión, número de entradas y modo de descarga), **Términos y contratos** (`/superadmin/documentos`, sección 8.2) y **Bitácora** (`/superadmin/bitacora`, sección 8.1). Las acciones de cada tenant están en su menú "Opciones": habilitar Sanciones, habilitar la depuración, exportar todos sus datos y darlo de baja.

Primera pantalla real de un panel de superadmin (planes/facturación siguen en Fase 3). Fuera de las rutas de tenant (`auth:sanctum` + `activo`, sin el middleware `tenant`): el usuario `superadmin` no tiene `tenant_id` y ninguna pantalla de `/subjects`, `/coincidencias`, etc. le sirve — al iniciar sesión se le redirige directo a `/superadmin`, y si visita cualquier otra pantalla se le redirige de vuelta ahí.

**Qué gestiona hoy, todo con auditoría en `activity_log`:**
- **Alta de tenants:** el panel crea el tenant junto con su primer usuario (rol `admin`, contraseña inicial) en una sola operación — un tenant sin ningún usuario es inútil (nadie puede entrar a él, y `POST /api/usuarios` exige ya estar autenticado *dentro* de un tenant, no hay forma circular de resolverlo después). El superadmin entrega la contraseña inicial al cliente por un medio seguro.
- **Renombrar un tenant** (`tenants.name`, nullable — ningún tenant tenía nombre hasta esta sección): editable inline en el panel.
- **Sanciones (cruce contra OFAC SDN) por tenant** — `sanciones_habilitado` en `tenants`, **`false` por defecto en todo tenant nuevo**. Sin esto habilitado, el tenant no ve el ítem "Sanciones" en el menú, el contador de Inicio, ni las 3 rutas de `SancionController` (responden 404, no 403, para no confirmar que la función existe). `MatchSanctionsJob` salta los tenants sin la función habilitada.
- **Bitácora de todos los tenants, sin datos personales** (sección 8.1): quién, qué evento, cuándo y el número interno del objeto ("Persona #12"), filtrable por tenant, evento y fechas. Nunca nombres, documentos, descripciones ni cambios de las personas vigiladas.
- **Modo de descarga de la lista OFAC** (`configuracion_sanciones`, fila única global — la lista es un catálogo compartido por todos los tenants, no tiene sentido un modo distinto por cada uno): `automatico` (default, corre el domingo 02:00) o `manual`. En modo manual, el botón "Actualizar lista ahora" del panel es la única forma de refrescarla — dispara el mismo `Bus::chain([ImportSanctionListsJob, MatchSanctionsJob])` de la sección 10 sin esperar al domingo.

**Endpoints:** `GET/POST /api/superadmin/tenants`, `PATCH /api/superadmin/tenants/{tenant}` (acepta `name` y/o `sanciones_habilitado`, al menos uno), `GET/PUT /api/superadmin/configuracion-sanciones`, `POST /api/superadmin/sanciones/actualizar-lista`, `GET /api/superadmin/bitacora` (+ `/eventos`). Autorización via `TenantPolicy::gestionar` (registrada a mano en `AppServiceProvider::boot()` — `Tenant` es un modelo de `stancl/tenancy`, el autodescubrimiento de policies de Laravel no lo encuentra solo). El alta reutiliza `App\Actions\Usuarios\CrearUsuario`, la misma Action que usa `UsuarioController::store`.

Verificado con datos reales contra MariaDB: alta de un tenant con su admin, el admin nuevo pudo iniciar sesión de inmediato, renombrado del tenant — todo limpiado después de la prueba.

---

## 8. Roles y permisos

Roles de la sección 3.2 del `CLAUDE.md` raíz, gestionados con `spatie/laravel-permission` (roles globales, sin la feature de "teams" — cada usuario ya pertenece a un solo tenant vía `tenant_id`):

| Rol | Alcance |
|-----|---------|
| `superadmin` | Gestión de tenants, planes, facturación, fuentes globales — incluido el panel de la sección 7.1. **No** accede a datos de negocio de ningún tenant — el middleware `tenant` lo rechaza (403). |
| `admin` | Gestión de usuarios y configuración del tenant; todo lo de `oficial_cumplimiento`. |
| `oficial_cumplimiento` | Resuelve coincidencias en firme, gestiona la lista de vigilancia (alta, aliases, activar/desactivar), captura manual, configura frecuencias de seguimiento del tenant (solo `admin` en este último punto). |
| `analista` | Ejecuta consultas puntuales, propone resoluciones (no resuelve en firme), da de alta sujetos y edita datos básicos/aliases/frecuencia por sujeto, marca seguimientos realizados. No activa/desactiva sujetos ni hace captura manual. |
| `lectura` | Solo consulta y reportes. |

Sembrar los roles (idempotente):

```bash
docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
```

### Usuarios del tenant y cuenta propia

- **Solo `admin`** gestiona usuarios (`/usuarios` en el frontend; `GET/POST /api/usuarios`, `PATCH /api/usuarios/{id}`): alta con contraseña inicial, cambio de nombre y rol, restablecer contraseña, desactivar/reactivar. El admin entrega la contraseña inicial a la persona por un medio seguro; **el envío por correo (invitación) queda para cuando haya SMTP real**.
- Reglas de seguridad ya implementadas: el tenant sale siempre del admin (nunca del body); `superadmin` no es asignable ni editable desde un tenant; un admin no puede cambiar su propio rol ni desactivarse; usuarios de otro tenant responden 404.
- **Contraseñas:** mínimo 12 caracteres con letras y números. Desactivar a un usuario o cambiarle la contraseña cierra sus sesiones y revoca sus tokens; un usuario desactivado no puede iniciar sesión (`users.activo`).
- Auditoría (`activity_log`): alta/edición de usuario (nunca la contraseña), `rol_cambiado`, `contrasena_restablecida`, `contrasena_cambiada`.
- **Cuenta propia** (cualquier rol, `/cuenta`): `PATCH /api/cuenta` (solo nombre) y `POST /api/cuenta/contrasena` (exige la actual, límite de 5 intentos por minuto, cierra las otras sesiones). No hay recuperación de contraseña por correo todavía: si se olvida, un admin la restablece.

### Matriz de acciones sensibles (por lo que ya se implementó — no todo vive en un único Policy)

| Acción | Roles permitidos | Por qué |
|--------|------------------|---------|
| Crear/editar datos básicos del sujeto, aliases, frecuencia propia | `admin`, `oficial_cumplimiento`, `analista` | `SubjectPolicy::create`/`update` |
| Ejecutar consulta puntual (`POST /buscar`) | `admin`, `oficial_cumplimiento`, `analista` | Gasta cuota de Brave/Anthropic — mismo set que "ejecuta consultas" (sección 3.2) |
| **Activar/desactivar** un sujeto | **solo** `admin`, `oficial_cumplimiento` | Decisión de cumplimiento, saca/devuelve al sujeto del matching y de la agenda — `SubjectPolicy::cambiarEstado` |
| Marcar "Seguimiento realizado" | `admin`, `oficial_cumplimiento`, `analista` | No es una resolución de coincidencia, no aplica el control de dos pasos |
| **Proponer** una resolución de match | `analista`, `oficial_cumplimiento`, `admin` | Primer paso del control de dos pasos (sección 3.2) |
| **Resolver** un match en firme | **solo** `oficial_cumplimiento`, `admin` | Segundo paso — quien propone no es quien aprueba |
| Extraer/descartar un `search_result` | `admin`, `oficial_cumplimiento`, `analista` | `SearchResultPolicy` |
| **Captura manual** de un GAP | **solo** `admin`, `oficial_cumplimiento` | Queda ya resuelta, sin pasar por proponer→resolver — mismo criterio que resolver un match |
| Configurar días por nivel de riesgo del tenant | **solo** `admin` | `ConfiguracionController` |
| Consultar la bitácora del tenant | **solo** `admin` | Gate `ver-bitacora-tenant` (sección 8.1) |
| Aceptar términos y contrato | **solo** `admin` | Gate `aceptar-documentos-legales` (sección 8.2) |
| Cambiar el plazo de conservación de datos | **solo** `admin` (mínimo 15 años) | Gate `configurar-retencion` |
| **Exportar** o **borrar** una persona | **solo** `admin` | `SubjectPolicy::exportar` / `delete` |
| Redactar/publicar términos, habilitar depuración, exportar o dar de baja un tenant | **solo** `superadmin` | `TenantPolicy::gestionar` |

`superadmin` recibe 403 en absolutamente todas las rutas de este grupo (`auth:sanctum` + `tenant`) — su alcance vive aparte, fuera de las rutas de tenant (sección 7.1).

### 8.1 Bitácora y registro de accesos (sección 3.9 del `CLAUDE.md` raíz, implementado 2026-09-28)

`activity_log` guarda el tenant de cada registro (`tenant_id`, lo asigna `App\Models\Activity` al crear: tenancy activa → tenant sobre el que actuó el superadmin → tenant del usuario que causó el evento). Además de los cambios de modelos, se registran estos **accesos** (`App\Support\RegistroDeAccesos`):

| Evento | Cuándo |
|--------|--------|
| `inicio_sesion` / `cierre_sesion` | Login y logout |
| `consulta_puntual` | Consulta puntual a Brave sobre una persona |
| `busqueda_tags` | Búsqueda por tags (guarda los tags y los días) |
| `extraccion_solicitada` | "Sacar información de noticia" (envía texto a Anthropic) |
| `resultado_descartado` | Descarte de un resultado |
| `evidencia_descargada` | Descarga de evidencia (solo si de verdad se sirvió el archivo) |
| `sanciones_cruzadas` | Cruce manual contra listas de sanciones |

**No** se registra abrir la ficha de una persona (decisión del usuario, por volumen).

**Quién la consulta:**
- **Admin del tenant** (`/bitacora`, `GET /api/bitacora` + `/eventos`): registro completo de su tenant, con filtros por usuario, evento y fechas (días de calendario de El Salvador).
- **Superadmin** (panel `/superadmin`, `GET /api/superadmin/bitacora`): todos los tenants **sin datos personales** (sección 7.1).

Registros anteriores a la migración `2026_09_28_120000_add_tenant_id_to_activity_log` se rellenaron a partir del objeto auditado, de `properties.tenant_id` o del usuario causante; los que no tenían forma de saberse (objetos ya borrados sin causante) quedan "Sin tenant" y solo los ve el superadmin.

### 8.2 Protección de datos personales (sección 3.9 del `CLAUDE.md` raíz, implementado 2026-09-28)

VERA es **encargado** del tratamiento; el cliente (tenant) es el **responsable**.

**Términos y contrato de encargo.** El superadmin los redacta y publica por versión en `/superadmin/documentos` (`documentos_legales`: un borrador editable por tipo; una versión publicada no se modifica). El admin de cada tenant los lee y acepta en `/documentos` (queda quién, cuándo e IP en `aceptaciones_documentos`). Mientras haya una versión vigente sin aceptar, el middleware `documentos` responde 403 (`codigo: terminos_pendientes`) al dar de alta personas o aliases, buscar, extraer, capturar a mano y cruzar sanciones; consultar sigue permitido y todos los usuarios ven un aviso. Publicar una versión nueva obliga a aceptarla de nuevo.

> **IMPORTANTE:** si no hay ningún documento publicado, no se bloquea nada. En producción publica los términos y el contrato (validados por un abogado) **antes** del primer cliente.

**Conservación y depuración.** El plazo corre desde que la persona se desactiva (`subjects.desactivado_en`). El admin lo fija en `/configuracion` (mínimo 15 años: Art. 26, Ley Contra el Lavado de Dinero y de Activos; máximo 100). La depuración automática viene **deshabilitada**; la habilita el superadmin por tenant (con confirmación). Las personas activas nunca se depuran.

**Exportar o borrar una persona** (admin, en la ficha): la exportación es un ZIP con `persona.json` y los PDF de evidencia manual. El borrado se confirma escribiendo el nombre y es irreversible: se eliminan la persona, sus aliases, coincidencias, hallazgos de sanciones, capturas manuales, resultados, búsquedas, alertas, evidencia y su entrada en Meilisearch. Los artículos y las menciones automáticas son contenido público global y se conservan. En la bitácora quedan los eventos sin datos personales y un registro `persona_eliminada`.

**Baja de tenant** (superadmin): primero "Exportar todos sus datos" (ZIP con `tenant.json`, `personas/`, `busquedas-por-tags.json` y `bitacora.json`, para entregarlo al cliente); después "Dar de baja", que exige una exportación de los últimos 7 días y escribir el nombre del tenant. Borra todos sus datos, usuarios y evidencia; en la bitácora solo queda `tenant_dado_de_baja`. Si falla a mitad, repetir la baja continúa donde quedó.

> **IMPORTANTE:** la exportación completa del tenant es el único caso en que el superadmin maneja datos personales de personas vigiladas; queda registrada como `tenant_exportado`.

---

## 9. Backup y recuperación

| Qué | Cómo | Frecuencia sugerida |
|-----|------|---------------------|
| MariaDB | `docker exec vera_mariadb mariadb-dump -u root -p"$DB_ROOT_PASSWORD" --all-databases > backup.sql` | Diario |
| Evidencia automática (R2) | Versionado/replicación del lado de Cloudflare R2, o `rclone` a otro bucket | Según política de retención del tenant (sin definir — ver más abajo) |
| Evidencia manual (`EVIDENCIA_MANUAL_DISK`) | Igual que arriba si se apunta a R2; si queda en `local`, incluirla en el backup del volumen del contenedor `api` | Diario si es `local` |
| Índices de Meilisearch | Reconstruibles desde MariaDB (`php artisan scout:import "App\Models\Subject"` + `scout:sync-index-settings`) — no requieren backup propio | — |
| `.env` (raíz y `backend/`) | Gestor de secretos separado del repositorio (nunca en git) | Al cambiar cualquier credencial |

**Restaurar MariaDB:**
```bash
docker exec -i vera_mariadb mariadb -u root -p"$DB_ROOT_PASSWORD" < backup.sql
```

> La política de retención de datos por tenant y el registro de base legal (Ley de Protección de Datos Personales, 2024) están pendientes de definir — no automatizar el borrado de evidencia hasta que exista esa política.

---

## 10. Schedule de tareas

El scheduler de Laravel corre dentro del contenedor `api` como proceso `schedule` bajo `supervisord` (junto a `php-fpm`, `horizon` y `serve` — ver `docker/api/supervisord.conf`). No hace falta un cron en el host.

> **IMPORTANTE:** ningún job programado llama a servicios externos de pago (Brave, Anthropic). Toda consulta o extracción la dispara un usuario (secciones 3.7 y 3.8 del `CLAUDE.md` raíz).

Tareas programadas (`backend/routes/console.php`):

| Tarea | Frecuencia | Qué hace |
|-------|-----------|----------|
| `DetectarSeguimientosVencidosJob` | Diario 07:00 `America/El_Salvador` (`schedule:list` la muestra en UTC) | Solo BD propia: crea una alerta por sujeto con `proximo_seguimiento_en <= hoy` (idempotente por tenant+alertable+vencimiento) y encola `SendAlertJob` (correo de resumen por tenant a `oficial_cumplimiento` + `admin`, solo si hay vencimientos nuevos) |
| `ReconciliarIndiceSubjectsJob` | Diario 03:00 `America/El_Salvador` | Reindexa en Meilisearch (self-hosted, sin costo) todos los subjects según su estado real en la BD — red de seguridad para el gotcha de que un `SubjectAlias` nuevo no dispara el observer de Scout del `Subject` padre |
| `ActualizarListaOfacProgramadaJob` → `ImportSanctionListsJob` → `MatchSanctionsJob` | Semanal, domingo 02:00 `America/El_Salvador` | Solo dispara la cadena si el modo de descarga global es `automatico` (sección 7.1 — en `manual` no hace nada, se niega en silencio). Descarga OFAC SDN (pública y gratuita; único adaptador con URL vigente confirmada, ONU/UE sin adaptador), la reindexa en Meilisearch (`sanction_entries`) y, solo si la importación terminó bien, cruza los subjects activos de los tenants **con Sanciones habilitada** (el resto se salta, `MatchSanctionsJob` lo revisa por tenant). Los hallazgos nacen `pendiente` y los resuelve una persona. Cero servicios de pago |
| `DepurarDatosVencidosJob` | Diario 04:00 `America/El_Salvador` | Solo en tenants con la depuración habilitada por el superadmin (sección 8.2): borra las personas inactivas cuya desactivación supera el plazo de conservación del tenant. Solo BD propia, Storage y Meilisearch. Registra `depuracion_ejecutada` únicamente si borró algo |

Al desplegar la agenda de seguimiento sobre una base con sujetos existentes, correr una vez (idempotente):
```bash
docker exec vera_api php artisan vera:inicializar-seguimientos
```

> El correo requiere SMTP real en producción (sección 5.9). Con `MAIL_MAILER=log` (desarrollo) el resumen queda en `storage/logs/laravel.log`.

Verificar que el scheduler está corriendo:
```bash
docker exec vera_api supervisorctl status
```

---

## 11. Despliegue en producción

1. **Provisionar el VPS** (Ubuntu 22.04+, Docker Engine + Compose v2 instalados).
2. **Clonar el repo** y configurar `.env`/`backend/.env` con valores de producción reales (nunca reutilizar los de desarrollo).
3. En producción, `REDIS_HOST` debe apuntar a `host.docker.internal` (Redis compartido con el host — `docker-compose.yml` ya trae `extra_hosts: host.docker.internal:host-gateway`). `docker-compose.override.yml` (que fija `REDIS_HOST=redis`, contenedor propio) es **solo para desarrollo** — no debe existir en el servidor de producción, o Compose lo aplicará también ahí. Ver Apéndice C.
4. Levantar el stack:
   ```bash
   docker compose up -d --build
   docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
   docker exec vera_api php artisan scout:sync-index-settings
   ```
   (`migrate` y `key:generate` ya los hace el entrypoint automáticamente al arrancar.)
5. Configurar **Apache2 o Nginx** como reverse proxy hacia `http://127.0.0.1:8000` (Apéndice B) con TLS (Apéndice A). El proyecto no tiene preferencia fijada entre los dos — usar el que ya administre el equipo de infraestructura.
6. **Desplegar el frontend** (React) en Cloudflare Pages, apuntando `VITE_API_URL`/`FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` al dominio real de producción. **Nunca se ha desplegado todavía** — hoy solo corre en `localhost:5173` vía `npm run dev`; validar `npm run build` (`tsc -b && vite build`) contra el dominio de producción antes del primer despliegue real.
7. Verificar Horizon: máximo 3 workers (`config/horizon.php`), dentro del presupuesto de 600 MB de RAM.
8. Decidir y dejar visible la atribución pública "Powered by Brave" en el frontend (requisito para conservar el crédito mensual — sin decidir dónde va todavía).
9. Programar `ImportSanctionListsJob` en `routes/console.php` si todavía no se hizo (hoy no está programado, sección 10).

> No hay pipeline de CI/CD definido en este proyecto — el despliegue de arriba es manual. Documentar aquí si se agrega uno.

---

## 12. Estructura del proyecto

```
vera/
├── docker/
│   ├── api/                 # Dockerfile, entrypoint.sh, supervisord.conf
│   └── mariadb/my.cnf
├── docker-compose.yml
├── docker-compose.override.yml   # solo desarrollo (redis en contenedor propio)
├── backend/                 # API Laravel
│   ├── app/
│   │   ├── Actions/{Matches,SearchResults,Seguimiento,Subjects,TagSearches}/
│   │   ├── Casts/           # FechaSinHora (columnas DATE sin hora)
│   │   ├── Console/Commands/  # vera:demo, vera:inicializar-seguimientos
│   │   ├── Data/Extraction/  # DTOs de la respuesta de Anthropic (spatie/laravel-data)
│   │   ├── Enums/           # EstadoSearchResult, GapMotivo, OrigenMention, RolMencion, TipoAlerta
│   │   ├── Http/{Controllers,Middleware,Requests}/
│   │   ├── Jobs/            # pipeline completo (seccion 3.4)
│   │   ├── Listeners/       # siembra de tags al crear un tenant
│   │   ├── Mail/            # ResumenSeguimientosPendientes (Blade markdown)
│   │   ├── Models/ + Models/Concerns/  # BelongsToTenant, DerivesTenantFromSubject
│   │   ├── Policies/
│   │   ├── Providers/
│   │   ├── Services/{Extraction,Matching,Search,Seguimiento}/
│   │   └── Sources/{ + Sanctions/}   # adaptadores por fuente (seccion 4)
│   ├── database/{migrations,factories,seeders}/
│   ├── resources/prompts/extraction/v1.md
│   ├── lang/es/validation.php   # mensajes de validacion en espanol
│   ├── routes/{api,web,console}.php
│   └── tests/{Feature,Unit}/
└── frontend/                # SPA React + Vite (Node en el host, no Docker)
    ├── design-system/MASTER.md
    ├── components.json      # shadcn/ui
    └── src/
        ├── components/{ui/, layout/AppShell, badges}
        ├── features/{auth,consulta,coincidencias,resultados,seguimiento}/
        ├── lib/{api,utils,fechas}.ts
        ├── types/api.ts
        └── routes/           # file-based (TanStack Router): login, _authenticated/{index,
                                # subjects,coincidencias,busqueda-tags,seguimientos,configuracion}
```

---

## 13. Convenciones de código

### Backend
- Controladores delgados; lógica en `Actions` y `Services`.
- Toda consulta a modelos de negocio pasa por el scope de tenant (`BelongsToTenant`). Prohibido `withoutGlobalScopes`/`withoutTenancy()` fuera de superadmin.
- Un modelo con `tenant_id` propio que cuelga de un padre tenant-scoped deriva su `tenant_id` del padre (`App\Models\Concerns\DerivesTenantFromSubject`), nunca `withoutTenancy()` para "adivinarlo" — debe fallar cerrado.
- Cualquier Job/comando `artisan`/`tinker` fuera de un request HTTP normal debe inicializar `tenancy()` explícitamente antes de tocar un modelo con `BelongsToTenant` — el scope no filtra si `tenancy()` no está inicializado (falla abierto, no cerrado).
- Migraciones nunca con SQL crudo específico de un driver (`ALTER TABLE ... MODIFY`) — usar siempre el schema builder de Laravel (`->change()`, etc.): la suite de tests corre contra SQLite en memoria y el código de producción contra MariaDB.
- Cualquier llamada a un servicio externo de pago por uso lleva su propio candado de cuota en el punto exacto del HTTP call (ver `BraveSearchAdapter::reservarCupoMensual()`).
- Columnas DATE (sin hora) usan `App\Casts\FechaSinHora`, nunca el cast `date` nativo de Eloquent (guarda con hora, rompe comparaciones de texto en SQLite).
- Pruebas: Pest. Cobertura obligatoria en matching, extracción (con respuestas grabadas) y aislamiento de tenant.
- Secretos solo en `.env`; nunca en código ni en commits.

### Frontend
- Componentes en `src/features/{consulta,coincidencias,resultados,seguimiento,auth}`.
- Fechas de calendario (`YYYY-MM-DD`) del backend se formatean y comparan como texto (`lib/fechas.ts`), **nunca con `new Date()`** — lo interpreta como medianoche UTC y corre el día en El Salvador (UTC-6).
- Sin estado global innecesario; TanStack Query maneja servidor.
- Idioma de la interfaz: español (es-SV).
- Al instalar un componente nuevo de shadcn/ui, revisar dos bugs conocidos del CLI: (1) escribe en una carpeta literal `@/` en la raíz en vez de resolver el alias a `src/`; (2) algunos componentes traen selectores de Base UI (`data-active:`) sobre primitivas de Radix, que usan `data-state="active"` — hay que corregirlos a mano.

### Git
- Ramas: `main` (producción), `develop`, `feature/*`, `fix/*`.
- Commits en español, imperativo, sin emojis, **sin línea `Co-Authored-By`** (regla explícita del propietario).
- Un PR por feature; no mezclar backend y frontend en el mismo PR salvo cambio de contrato.

---

## 14. Referencia rápida de comandos

### Docker
```bash
docker compose up -d              # levantar el stack (backend)
docker compose down                # detener
docker compose logs -f api         # logs del contenedor api
docker exec -it vera_api bash      # shell dentro del contenedor
docker exec vera_api php artisan horizon:terminate   # obligatorio tras cambiar una clase Job/Adapter (Horizon la carga una sola vez al arrancar)
```

### Artisan (siempre dentro del contenedor)
```bash
docker exec vera_api php artisan migrate --force
docker exec vera_api php artisan migrate:rollback --step=1
docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
docker exec vera_api php artisan scout:sync-index-settings
docker exec vera_api php artisan vera:demo "Nombre de prueba"
docker exec vera_api php artisan vera:inicializar-seguimientos
docker exec vera_api php artisan queue:failed
docker exec vera_api php artisan tinker
docker exec vera_api supervisorctl status
```

### Tests
```bash
docker exec vera_api php artisan test
docker exec vera_api vendor/bin/pest --filter=NombreDelTest
```

### Composer (dentro del contenedor, nunca en el host)
```bash
docker exec vera_api composer require <paquete>
docker exec vera_api composer dump-autoload
```

### Frontend (en el host)
```bash
cd frontend
npm run dev        # http://localhost:5173
npm run build       # tsc -b && vite build - detener "npm run dev" antes (OOM si corren juntos)
npm run lint        # oxlint
```

---

## 15. Feature Tests

El proyecto usa Pest. **350 tests pasan** (verificado 2026-09-28). Cobertura obligatoria: aislamiento de tenant, matching, extracción con respuestas de IA grabadas (fixtures, no llamadas reales en tests).

```bash
docker exec vera_api php artisan test              # suite completa
docker exec vera_api php artisan test --filter=SubjectTenantIsolationTest
```

`phpunit.xml` fuerza `APP_ENV`/`DB_DATABASE` con `force="true"` en **ambos** `<env>` y `<server>` — Laravel resuelve `env()` priorizando `$_SERVER`, que solo lo toca `<server>`; sin ambos, la suite corre contra la MariaDB real en vez de SQLite en memoria (gotcha real ya encontrado y corregido, ver `CLAUDE.md` raíz). Cada test de `tests/Feature` corre con `RefreshDatabase` contra SQLite en memoria, no contra la MariaDB de desarrollo — los índices de Meilisearch de test comparten instancia con dev (bajo impacto: cada test usa un `tenant_id` nuevo).

---

## 16. Solución de problemas

| Síntoma | Causa probable | Solución |
|---------|----------------|---------|
| `docker compose up` falla pidiendo una variable | `.env` (raíz) sin `DB_PASSWORD`/`DB_ROOT_PASSWORD`/`MEILISEARCH_KEY` | Completar `.env` a partir de `.env.example` — son obligatorias a propósito, sin default débil |
| El contenedor `vera_api` queda en `Restarting`, log dice "No se encontro composer.json" | El bind mount `./backend:/var/www/html` no ve el `backend/` esperado (ruta del proyecto movida/sincronizada después de que el contenedor ya existía) | `docker compose up -d` para recrear el contenedor con la ruta actual — un bind mount no se "actualiza solo" en un contenedor ya creado |
| Cualquier test de `AuthTest`/`ExampleTest` falla con `MissingAppKeyException`, o `artisan key:generate` dice "No APP_KEY variable was found" | `backend/.env` incompleto o con contenido equivocado (ej. quedó con solo `VITE_API_URL`, que es del frontend) | Recrear desde `cp backend/.env.example backend/.env`, ajustar `MEILISEARCH_KEY`/`DB_PASSWORD` para que coincidan con el `.env` raíz, y correr `php artisan key:generate --force` |
| Un test o una búsqueda real falla con `Attribute 'tenant_id' is not filterable. This index does not have configured filterable attributes.` | Nunca se corrió `scout:sync-index-settings` contra esta instancia de Meilisearch (primera vez, o volumen de Meilisearch nuevo) | `docker exec vera_api php artisan scout:sync-index-settings` |
| `GET /api/user` responde 500 en vez de 401 | Falta `$middleware->statefulApi()` o `APP_KEY` vacía | Verificar `bootstrap/app.php` y correr `php artisan key:generate` |
| El login del frontend nunca manda/recibe la cookie de sesión | `config/cors.php` sin `supports_credentials: true`, o `FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` no coinciden con el origen real | Revisar los tres juntos — Sanctum SPA por cookies no funciona con ninguno mal alineado |
| Un usuario ve datos de otro tenant | Ruta de negocio sin el middleware `tenant`, o modelo sin `BelongsToTenant` | Revisar que la ruta esté en el grupo `['auth:sanctum','tenant']` y que el modelo use el trait |
| Un modelo con `tenant_id` propio queda con el tenant equivocado | Se creó mientras el tenant ambiente en `tenancy()` no coincidía con el del padre | Usar `App\Models\Concerns\DerivesTenantFromSubject` en vez de confiar en `tenancy()->tenant` |
| Un cambio en una clase `Job`/`Adapter` no tiene efecto aunque el código ya esté actualizado | Horizon carga las clases una sola vez al arrancar el worker | `docker exec vera_api php artisan horizon:terminate` (el supervisor lo relanza solo) |
| Cambios en variables de `.env` no toman efecto con los contenedores ya corriendo | Horizon/PHP-FPM leen el `.env` una sola vez al arrancar | `docker compose restart api` |
| `npm run build` falla con "Rolldown panicked... out of memory" | `npm run dev` sigue corriendo en la misma máquina (contención de memoria, no es un bug de código) | Detener el dev server antes de compilar |
| Búsquedas de Brave agotadas a media jornada | `BRAVE_SEARCH_MONTHLY_LIMIT` superado (candado propio, Brave no impone tope) | Confirmar con el propietario antes de subir el límite (tiene costo real) o esperar al siguiente mes |
| Llamadas a Anthropic fallan con error de autenticación/billing | Cuenta sin créditos cargados, o `ANTHROPIC_API_KEY` vacía | Cargar billing en console.anthropic.com y verificar la key en `backend/.env` |
| El resumen diario de seguimientos nunca llega al correo real | `MAIL_MAILER=log` (desarrollo) o falta SMTP real en producción | Configurar SMTP (sección 5.9) — en dev, revisar `storage/logs/laravel.log` |
| El correo de seguimientos no sale para un tenant | Ese tenant no tiene ningún usuario `oficial_cumplimiento` ni `admin` | Asignar el rol a algún usuario del tenant; la alerta queda pendiente y se reintenta sola |
| Aparece un archivo suelto `vera` (SQLite) en `backend/` | Algún comando resolvió la conexión `sqlite` con `DB_DATABASE=vera` del `.env` en vez de `mariadb` — no viene de `phpunit.xml` (ya fuerza `:memory:` con `force="true"`) | Borrar el archivo; investigar si algún proceso del host (fuera de Docker) está corriendo `artisan`/PHPUnit con el `.env` normal |
| `composer require` falla por conflicto de versiones de `phpunit/phpunit` | Un paquete (ej. Pest) requiere una versión de PHPUnit más baja que la instalada | Reintentar con `-W` para que Composer ajuste `phpunit/phpunit` dentro del rango permitido por `composer.json` |

---

## Apéndice A — SSL/TLS con Let's Encrypt

**Con Apache2:**
```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d api.tudominio.com
```

**Con Nginx:**
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.tudominio.com
```

Certbot configura la renovación automática (`certbot renew` vía systemd timer) en ambos casos. Verificar con:
```bash
sudo certbot renew --dry-run
```

---

## Apéndice B — Reverse proxy delante de Docker (Apache2 o Nginx)

El servidor web corre en el host (fuera de Docker) y reenvía al contenedor `api`, que expone `8000` en el host. Elegir uno de los dos — el proyecto no tiene preferencia fijada, ambos son equivalentes para este caso.

### Opción 1 — Apache2

```bash
sudo a2enmod proxy proxy_http ssl headers
```

```apache
<VirtualHost *:443>
    ServerName api.tudominio.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/api.tudominio.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/api.tudominio.com/privkey.pem

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8000/
    ProxyPassReverse / http://127.0.0.1:8000/

    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>

<VirtualHost *:80>
    ServerName api.tudominio.com
    Redirect permanent / https://api.tudominio.com/
</VirtualHost>
```

```bash
sudo a2ensite api.tudominio.com.conf
sudo systemctl reload apache2
```

### Opción 2 — Nginx

```nginx
server {
    listen 80;
    server_name api.tudominio.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name api.tudominio.com;

    ssl_certificate     /etc/letsencrypt/live/api.tudominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.tudominio.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/api.tudominio.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### En ambos casos

En `backend/.env` de producción, `APP_URL`, `SANCTUM_STATEFUL_DOMAINS` y `SESSION_DOMAIN` deben usar el dominio real (`api.tudominio.com`), no `localhost`. `FRONTEND_URL` debe apuntar al dominio real donde quede publicado el frontend (Cloudflare Pages, sección 11).

---

## Apéndice C — Docker Compose en detalle

Dos archivos, ambos en la raíz del repo:

- **`docker-compose.yml`** — siempre se usa, en dev y en producción. Define `api` (PHP 8.4-FPM + `artisan serve` + Horizon + scheduler, todo bajo `supervisord`), `mariadb` y `meilisearch`. `REDIS_HOST` por defecto es `host.docker.internal` (producción: Redis compartido con el host, `extra_hosts: host.docker.internal:host-gateway` ya está declarado para que funcione).
- **`docker-compose.override.yml`** — se aplica **automáticamente** cuando existe en el mismo directorio (comportamiento nativo de `docker compose`, no hace falta ningún flag). Solo debe existir en máquinas de desarrollo: agrega un contenedor `redis` propio y sobreescribe `REDIS_HOST=redis` en `api`. **En el VPS de producción, este archivo no debe existir** — si se clona el repo completo, hay que borrarlo o renombrarlo ahí, o Compose lo va a aplicar igual y el `api` intentará hablar con un Redis en contenedor que no existe.

**Contenedor `api` — qué corre dentro (`docker/api/supervisord.conf`):**

| Proceso | Comando | Rol |
|---------|---------|-----|
| `php-fpm` | `php-fpm -F` | Motor PHP (puerto 9000, interno) |
| `serve` | `php artisan serve --host=0.0.0.0 --port=8000` | Sirve la API HTTP en el puerto expuesto `8000` — **es el servidor real que atiende requests**, no un reverse proxy interno hacia `php-fpm` |
| `horizon` | `php artisan horizon` | Workers de colas (máx. 3, `config/horizon.php`) |
| `schedule` | `php artisan schedule:work` | Scheduler — invoca las tareas de la sección 10 cada minuto, sin cron del host |

> `php-fpm` queda corriendo pero **no** es lo que atiende las requests HTTP externas — eso lo hace `artisan serve` en el mismo contenedor. Si en el futuro se quiere servir con FPM real detrás de un reverse proxy (más robusto para producción con más tráfico), hay que agregar Nginx/Apache **dentro** del contenedor `api` apuntando a `php-fpm:9000` y quitar el proceso `serve` — cambio de arquitectura, no hacerlo sin confirmar con el propietario.

**`entrypoint.sh` (corre antes del `CMD` de `supervisord`):** copia `.env.example` → `.env` si falta, `composer install` si falta `vendor/`, espera a MariaDB, genera `APP_KEY` si falta, corre `migrate --force`. Todo idempotente — se puede recrear el contenedor cuantas veces haga falta sin duplicar nada.

**Volúmenes con nombre (persisten aunque se recree el contenedor, se pierden solo con `docker compose down -v`):** `mariadb_data`, `meilisearch_data`. El código de `api` es un bind mount (`./backend:/var/www/html`), no un volumen — cambios en el host se reflejan sin rebuild; sí hace falta `docker compose up -d` (recrear) si la ruta del proyecto en el host cambió, y `horizon:terminate` si cambió una clase de Job/Adapter (sección 14/16).

---

## Apéndice D — Migración de Google CSE a Brave Search

Contexto histórico, por si se necesita retomar Google CSE antes de que Google la apague (1 de enero de 2027) o entender por qué el código de `GoogleCseAdapter` sigue en el repo sin usarse.

**Por qué se cambió (2026-09-24):**
1. La cuenta de Google CSE del proyecto quedó bloqueada por facturación sin resolver en el proyecto de Google Cloud.
2. Investigando el bloqueo salió algo más importante: **Google Custom Search JSON API está cerrada a clientes nuevos desde 2025 y Google la apaga por completo el 1 de enero de 2027**, sin excepción, para todos los clientes existentes.
3. Se comparó Brave Search API / Perplexity Search API / SerpApi (descartado: riesgo legal, Google los demanda por scraping) y se eligió **Brave** por precio y por no requerir el flujo de proyecto+facturación de Google Cloud.

**Diferencias de implementación que importan si se retoma CSE:**
- Con Google CSE, la restricción a los medios salvadoreños vivía en el `cx` (configurado del lado de Google) — la query nunca necesitaba `site:`. **Brave no tiene ese concepto**: sin `site:` busca en toda la web. `App\Services\Search\RestriccionDeDominios` agrega `site:` por cada dominio — si se reactivara CSE, esa restricción quedaría redundante pero no dañina (Google la ignoraría al no reconocer el operador de la misma forma dentro del `cx`).
- Brave cobra desde el primer request fuera del crédito mensual ($5/1000); Google CSE daba 100 consultas/día gratis y cobraba por bloque de 1000 adicionales activando facturación.
- El candado de cuota de Brave es **mensual** (`BraveSearchAdapter::reservarCupoMensual()`); el de Google CSE era **diario** (`GoogleCseAdapter::reservarCupoDiario()`) — ambos siguen implementados y activos en el código, cada uno atado a su adaptador.
- El contrato `SourceAdapterInterface::buscar()` es compartido por los dos adaptadores — cualquiera de los dos puede ser la fuente activa de una `Source` (`tipo: 'brave'` o `tipo: 'cse'`) sin cambios en `RunSubjectSearchJob`/`RunTagSearchJob`.

**Para reactivar Google CSE como fuente (sin quitar Brave):**
1. Vincular facturación en [console.cloud.google.com/billing/linkedaccount](https://console.cloud.google.com/billing/linkedaccount) al proyecto dueño de `GOOGLE_CSE_API_KEY`.
2. Completar `GOOGLE_CSE_API_KEY`/`GOOGLE_CSE_CX` en `backend/.env` (ver la sección 5.2 de este manual y la 5.3 de la versión anterior de este documento, en el historial de git, para el paso a paso de creación del motor de búsqueda programable).
3. Crear una `Source` con `tipo: 'cse'` para el tenant que la vaya a usar — `RunSubjectSearchJob::adapterFor()` ya rutea por el `tipo` de la `Source`, no hace falta tocar código.

**No hacer esto sin necesidad real** — mientras Brave siga funcionando, no hay razón operativa para reactivar Google CSE antes de que Google la apague.
