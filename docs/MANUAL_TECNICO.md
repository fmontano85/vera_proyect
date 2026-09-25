# VERA — Manual Técnico

> Plataforma SaaS multi-tenant de adverse media screening y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador).
> Arquitectura multi-tenant de base de datos única (`tenant_id` + global scope), API REST en Laravel, frontend SPA en React, todo en Docker Compose.

> **Estado del proyecto (2026-09-14):** Fase 1 (MVP) en construcción. Existen el backend base, multi-tenancy, roles y los primeros modelos de negocio (`subjects`, `sources`, sanciones). **No existen todavía**: el pipeline de búsqueda/extracción (sección 3.4), el frontend funcional, ni las cuentas de Google CSE/Anthropic (siguen vacías en `.env`). Este manual documenta el camino a producción tal como está diseñado en el `CLAUDE.md` raíz del proyecto — varias secciones (pipeline, frontend) describen el destino, no el estado actual. Se marca explícitamente dónde aplica esto.

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
- [Apéndice B — Apache2 como reverse proxy delante de Docker](#apéndice-b--apache2-como-reverse-proxy-delante-de-docker)

---

## 1. Requisitos

### Desarrollo (Windows/Linux/Mac — todo corre en Docker, nada se instala en el host)

| Componente | Versión |
|------------|---------|
| Docker Desktop (o Docker Engine + Compose v2 en Linux) | 24+ |
| Git | 2.40+ |

> **IMPORTANTE:** PHP, Composer, Node, MariaDB, Redis y Meilisearch **no se instalan en la máquina de desarrollo**. Todo se construye y corre dentro de los contenedores definidos en `docker-compose.yml`. Esta es una decisión confirmada del proyecto (ver `CLAUDE.md` raíz, sección "Decisiones confirmadas"), no una preferencia opcional.

### Producción (Linux, VPS)

| Componente | Versión |
|------------|---------|
| Ubuntu Server (o distribución equivalente) | 22.04 LTS+ |
| Docker Engine + Compose v2 | 24+ |
| Apache2 (reverse proxy con TLS, fuera de Docker) | 2.4+ |
| Certbot (Let's Encrypt) | última estable |

**Presupuesto de recursos (VPS de referencia):** 2 vCore, 4 GB RAM, 120 GB NVMe. RAM del producto: máximo 600 MB. Horizon: máximo 3 workers. No agregar contenedores fuera de `api`, `mariadb`, `meilisearch` (Redis va compartido con el host en producción, no en un contenedor propio).

---

## 2. Stack tecnológico

| Capa | Tecnología | Versión |
|------|-----------|---------|
| Backend | PHP | 8.4 |
| Backend | Laravel | 13 |
| Auth | Laravel Sanctum (SPA, cookies) | — |
| Colas | Laravel Horizon + Redis | 7 |
| Multi-tenancy | stancl/tenancy (BD única + `tenant_id`) | v3 |
| Roles/permisos | spatie/laravel-permission | — |
| Auditoría | spatie/laravel-activitylog | — |
| DTOs/validación IA | spatie/laravel-data | — |
| Búsqueda | Laravel Scout + Meilisearch | v1.11 |
| Base de datos | MariaDB | 11 |
| Frontend | React + Vite + TypeScript (strict) | React 19 |
| Frontend | Tailwind CSS | v4 |
| Frontend | shadcn/ui | — |
| Frontend | TanStack Query + TanStack Router | — |
| Testing backend | Pest | v4 |
| IA | Anthropic Claude (`claude-haiku-4-5`, `claude-sonnet-5`) | — |
| Búsqueda de medios | Google Custom Search JSON API | — |
| Almacenamiento de evidencia | Cloudflare R2 (driver S3 de Laravel) | — |
| PDF bajo demanda | Gotenberg **o** Cloudflare Browser Rendering | **sin decidir** — ver sección 5.6 |
| Despliegue frontend | Cloudflare Pages | — |
| Infraestructura | Docker Compose + Apache2 (reverse proxy) | — |

---

## 3. Arquitectura

**Multi-tenancy:** un tenant = un sujeto obligado (empresa cliente). Base de datos única; todo modelo de negocio lleva `tenant_id` con global scope (`Stancl\Tenancy\Database\Concerns\BelongsToTenant`). El tenant actual se resuelve por el `tenant_id` del usuario autenticado vía Sanctum (`App\Http\Middleware\InitializeTenancyFromAuthenticatedUser`), **no** por dominio/subdominio. `superadmin` no atraviesa este middleware — sus rutas (Fase 3, panel superadmin) son aparte.

**Flujo de un request de negocio:**
```
Cliente (SPA) → Sanctum (cookie de sesión) → auth:sanctum
             → tenant (resuelve tenant_id del usuario, inicializa tenancy())
             → Controller (delgado) → Action/Service → Modelo (global scope tenant_id)
```

**Pipeline de screening (sección 3.4 del CLAUDE.md — diseñado, jobs aún no implementados):**
```
RunSubjectSearchJob → FetchArticleJob → ExtractEntitiesJob → MatchMentionsJob → SendAlertJob
```
Colas por prioridad: `alerts` > `matching` > `extraction` > `fetch` > `search` > `imports`. Cada job debe ser idempotente y reintentable.

**Evidencia:** snapshot HTML + hash SHA-256 + timestamp UTC, inmutable una vez guardado, en R2 con prefijo `tenants/{tenant_id}/evidence/`.

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

# Construir e iniciar los contenedores
docker compose up -d --build

# Generar APP_KEY (solo la primera vez; el entrypoint no la regenera despues)
docker exec vera_api php artisan key:generate

# Migrar y sembrar roles base
docker exec vera_api php artisan migrate --force
docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
```

Verificar que levantó todo:

```bash
docker ps --format "table {{.Names}}\t{{.Status}}"
curl -i http://localhost:8000/api/user   # debe responder 401 (Sanctum activo, sin sesion)
```

API en `http://localhost:8000`, Meilisearch en `:7700`, MariaDB en `:3306`.

---

## 5. Configuración del entorno y servicios de terceros

### 5.1 Variables de entorno — desarrollo (`.env` en la raíz)

```ini
# ── Base de datos (contenedor mariadb) ─────────────────────────
DB_DATABASE=vera
DB_USERNAME=vera
DB_PASSWORD=tu_password_seguro
DB_ROOT_PASSWORD=otro_password_seguro

# ── Meilisearch ─────────────────────────────────────────────────
MEILISEARCH_KEY=una_master_key_larga_y_aleatoria

# ── Redis (dev: contenedor propio via docker-compose.override.yml) ──
# En dev no hace falta declarar REDIS_HOST - el override ya lo fija a "redis".
```

### 5.2 Variables de entorno — `backend/.env` (aplicación Laravel)

```ini
APP_ENV=local
APP_KEY=                          # generado por: php artisan key:generate
APP_URL=http://localhost:8000

# ── Base de datos ────────────────────────────────────────────────
DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_DATABASE=vera
DB_USERNAME=vera
DB_PASSWORD=tu_password_seguro

# ── Colas / cache ────────────────────────────────────────────────
QUEUE_CONNECTION=redis
REDIS_HOST=redis                  # en produccion: host.docker.internal (ver seccion 11)

# ── Busqueda ─────────────────────────────────────────────────────
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=una_master_key_larga_y_aleatoria   # debe coincidir con el .env raiz

# ── Sanctum (SPA) ────────────────────────────────────────────────
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173

# ── Google Custom Search ─────────────────────────────────────────
GOOGLE_CSE_API_KEY=
GOOGLE_CSE_CX=
GOOGLE_CSE_DAILY_LIMIT=100

# ── Anthropic Claude ─────────────────────────────────────────────
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL_FAST=claude-haiku-4-5
ANTHROPIC_MODEL_ESCALATION=claude-sonnet-5
EXTRACTION_CONFIDENCE_THRESHOLD=0.6

# ── Ventana de busqueda / matching (calibrar en Fase 0) ──────────
ARTICLE_WINDOW_DAYS=30
MATCH_SCORE_THRESHOLD=            # sin definir todavia - ver seccion 9 del CLAUDE.md

# ── Cloudflare R2 (evidencia) ─────────────────────────────────────
FILESYSTEM_DISK=r2
R2_ACCOUNT_ID=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=vera-evidence

# ── Correo (alertas) ───────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=

# ── Google Sheets (opcional) ───────────────────────────────────────
GOOGLE_SHEETS_CREDENTIALS_JSON=
```

### 5.3 Google Custom Search JSON API (búsqueda en medios salvadoreños)

Es el servicio que consulta los 6 medios de la sección 4 del `CLAUDE.md` (laprensagrafica.com, elsalvador.com, diarioelmundo.com, lapagina.com.sv, diario1.com, elmundo.sv).

1. Entrar a [Google Cloud Console](https://console.cloud.google.com/) y crear un proyecto (o usar uno existente).
2. En **APIs y servicios → Biblioteca**, buscar "Custom Search API" y habilitarla.
3. En **APIs y servicios → Credenciales → Crear credenciales → Clave de API**. Copiar el valor a `GOOGLE_CSE_API_KEY`.
4. Ir a [Programmable Search Engine](https://programmablesearchengine.google.com/) → **Agregar**.
5. En "Sitios que se van a buscar", agregar cada dominio de medio, uno por línea (un solo motor `cx` multi-sitio, no uno por medio — así lo define la sección 4).
6. Guardar y copiar el **Search engine ID** (aparece como `cx=...` en el panel de control del motor). Va en `GOOGLE_CSE_CX`.
7. Cuota gratuita: **100 consultas/día** (`GOOGLE_CSE_DAILY_LIMIT`). El scheduler del pipeline (sección 3.4) debe respetar este límite y diferir el excedente al día siguiente — si se necesita más volumen, activar facturación en la misma consola de Cloud (Custom Search JSON API cobra por bloque de 1000 consultas adicionales).

> **IMPORTANTE:** la validación de cobertura real (Fase 0, sección 5 del CLAUDE.md) todavía no se hizo. No dar por sentado que el `cx` multi-sitio indexa con la latencia necesaria hasta correr esa prueba con los 20 casos de referencia.

### 5.4 Anthropic Claude API (extracción de entidades)

1. Crear cuenta en [console.anthropic.com](https://console.anthropic.com/).
2. Cargar créditos/método de pago (Settings → Billing) — sin esto las llamadas fallan aunque la API key sea válida.
3. Ir a **API Keys → Create Key**. Copiar el valor a `ANTHROPIC_API_KEY`.
4. No hace falta configurar nada más por modelo: `ANTHROPIC_MODEL_FAST` (`claude-haiku-4-5`) se usa para extracción masiva, `ANTHROPIC_MODEL_ESCALATION` (`claude-sonnet-5`) para los casos que escalan por baja confianza (`EXTRACTION_CONFIDENCE_THRESHOLD`, sección 3.5 del CLAUDE.md).
5. Registrar tokens y costo por extracción es un requisito del proyecto (tabla `extractions`) — no depende de configuración adicional en la consola de Anthropic, se implementa en el job de extracción.

> El stack de IA está cerrado a Anthropic (sección 7 del CLAUDE.md: "no se abre a OpenAI ni otros proveedores por ahora"). No agregar otro proveedor sin confirmación explícita del propietario.

### 5.5 Cloudflare R2 (almacenamiento de evidencia)

1. En el dashboard de Cloudflare, ir a **R2 → Create bucket**. Nombre sugerido: `vera-evidence` (o el que se use en `R2_BUCKET`).
2. **R2 → Manage API tokens → Create API token**, con permisos de lectura/escritura sobre ese bucket. Copiar **Access Key ID** y **Secret Access Key**.
3. El **Account ID** aparece en la barra lateral del dashboard de Cloudflare (o en la URL de R2). Va en `R2_ACCOUNT_ID`.
4. `FILESYSTEM_DISK=r2` activa el driver S3 de Laravel apuntando a R2 (no requiere paquete adicional, el driver S3 nativo de Laravel es compatible con la API S3 de R2).
5. Los objetos se guardan bajo el prefijo `tenants/{tenant_id}/evidence/` (sección 3.1) — no crear un bucket por tenant.

### 5.6 PDF bajo demanda: Gotenberg o Cloudflare Browser Rendering (decisión pendiente)

La sección 2 del CLAUDE.md deja esto sin decidir todavía. Los dos caminos, para cuando se resuelva:

- **Gotenberg** (self-hosted, requiere un contenedor adicional): rompería el presupuesto de "no agregar contenedores" de la sección 7 salvo que se ejecute en un VPS/servicio aparte, o bajo demanda vía un job que levanta el contenedor puntualmente. Ver [gotenberg.dev](https://gotenberg.dev/) para la imagen Docker oficial.
- **Cloudflare Browser Rendering**: servicio administrado (API de Cloudflare Workers), sin contenedor propio — más alineado con el presupuesto de RAM del VPS. Requiere una cuenta de Cloudflare Workers con Browser Rendering habilitado (actualmente en el mismo dashboard donde se gestiona R2 y Pages).

**No implementar ninguno de los dos sin confirmar con el propietario del proyecto** (sección 7: no introducir servicios fuera de la sección 2 sin preguntar — y este es, literalmente, el caso en que la sección 2 no decide).

### 5.7 SMTP (alertas por correo)

Cualquier proveedor SMTP del dominio del cliente sirve — el proyecto no fija uno. Configurar `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` según el proveedor (ej. el propio hosting de correo del dominio, o un servicio transaccional como Postmark/SES si se decide más adelante). **No usar Slack para alertas** (sección 7) — el canal es correo y, opcionalmente, Google Sheets.

### 5.8 Google Sheets API (opcional — exportación de alertas)

1. En Google Cloud Console (el mismo proyecto de la sección 5.3 sirve), habilitar **Google Sheets API**.
2. Crear una **cuenta de servicio** (IAM y administración → Cuentas de servicio → Crear cuenta de servicio).
3. Generar una clave JSON para esa cuenta de servicio y guardar su contenido en `GOOGLE_SHEETS_CREDENTIALS_JSON` (como ruta a archivo, o el JSON completo, según cómo se implemente el cliente).
4. Compartir la hoja de cálculo de destino con el email de la cuenta de servicio (termina en `...@<proyecto>.iam.gserviceaccount.com`), con permiso de editor.
5. Esta integración es opcional (sección 3.4: "Google Sheets si está configurado") — si `GOOGLE_SHEETS_CREDENTIALS_JSON` queda vacío, el pipeline solo envía alertas por correo.

---

## 6. Base de datos

MariaDB 11, base de datos única (sección 3.1) — **nunca** una base de datos por tenant, ni usar el `DatabaseTenancyBootstrapper` de `stancl/tenancy` (está deshabilitado a propósito en `config/tenancy.php`).

```bash
# Migrar
docker exec vera_api php artisan migrate --force

# Revertir la ultima migracion (nunca en produccion sin backup previo)
docker exec vera_api php artisan migrate:rollback --step=1

# Estado de las migraciones
docker exec vera_api php artisan migrate:status
```

**No usar FULLTEXT de MariaDB para nombres** (sección 2) — el matching fuzzy de nombres va por Meilisearch, con `tenant_id` como atributo filtrable en cada índice.

---

## 7. Gestión de tenants

Todavía no existe un panel de superadmin (Fase 3) ni un endpoint de alta de tenants. Mientras tanto, se gestionan por `tinker`:

```bash
docker exec -it vera_api php artisan tinker
```

```php
// Crear un tenant
$tenant = \Stancl\Tenancy\Database\Models\Tenant::create();

// Crear un usuario y asignarlo a ese tenant
$user = \App\Models\User::factory()->create([
    'name' => 'Oficial de Cumplimiento',
    'email' => 'oficial@ejemplo.com',
]);
$user->forceFill(['tenant_id' => $tenant->id])->save();
$user->assignRole('oficial_cumplimiento'); // ver seccion 8

// Token de acceso para probar la API sin frontend (Bearer token)
$user->createToken('manual')->plainTextToken;
```

Con el token: `curl -H "Authorization: Bearer <token>" http://localhost:8000/api/subjects`.

---

## 8. Roles y permisos

Roles definidos en la sección 3.2 del CLAUDE.md, gestionados con `spatie/laravel-permission` (roles globales, sin la feature de "teams" — cada usuario ya pertenece a un solo tenant vía `tenant_id`, así que el rol es solo la etiqueta de capacidad dentro de ese tenant):

| Rol | Alcance |
|-----|---------|
| `superadmin` | Gestión de tenants, planes, facturación, fuentes globales. **No** accede a datos de negocio de ningún tenant — el middleware `tenant` lo rechaza explícitamente en esas rutas. |
| `admin` | Gestión de usuarios y configuración del tenant. |
| `oficial_cumplimiento` | Resuelve coincidencias, aprueba reportes, gestiona lista de vigilancia. |
| `analista` | Ejecuta consultas, propone resoluciones, no aprueba. |
| `lectura` | Solo consulta y reportes. |

Sembrar los roles (idempotente, usa `firstOrCreate`):

```bash
docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
```

---

## 9. Backup y recuperación

| Qué | Cómo | Frecuencia sugerida |
|-----|------|---------------------|
| MariaDB | `docker exec vera_mariadb mariadb-dump -u root -p"$DB_ROOT_PASSWORD" --all-databases > backup.sql` | Diario |
| Evidencia (R2) | Versionado/replicación del lado de Cloudflare R2, o `rclone` a otro bucket | Según política de retención del tenant (sección 9 — todavía sin definir) |
| Índices de Meilisearch | Reconstruibles desde MariaDB (`php artisan scout:import`) — no requieren backup propio | — |
| `.env` (raíz y `backend/`) | Gestor de secretos separado del repositorio (nunca en git) | Al cambiar cualquier credencial |

**Restaurar MariaDB:**
```bash
docker exec -i vera_mariadb mariadb -u root -p"$DB_ROOT_PASSWORD" < backup.sql
```

> La política de retención de datos por tenant y el registro de base legal (Ley de Protección de Datos Personales, 2024) están pendientes de definir (sección 9 del CLAUDE.md) — no automatizar el borrado de evidencia hasta que exista esa política.

---

## 10. Schedule de tareas

El scheduler de Laravel corre dentro del contenedor `api` (proceso `schedule` bajo supervisor, junto a `php-fpm` y `horizon` — ver `docker/api/supervisord.conf`). No hace falta un cron en el host: el contenedor ya invoca `schedule:run` cada minuto internamente.

> **IMPORTANTE:** ningún job programado llama a servicios externos de pago (Brave, Anthropic). Toda consulta o extracción la dispara un usuario (secciones 3.7 y 3.8 del `CLAUDE.md`).

Tareas programadas (`backend/routes/console.php`):

| Tarea | Frecuencia | Qué hace |
|-------|-----------|----------|
| `DetectarSeguimientosVencidosJob` | Diario 07:00 `America/El_Salvador` (`schedule:list` lo muestra en UTC: `0 13 * * *`) | Solo BD propia: crea una alerta por sujeto con `proximo_seguimiento_en <= hoy` (idempotente) y encola `SendAlertJob`, que manda un correo de resumen por tenant a `oficial_cumplimiento` + `admin` |
| `ImportSanctionListsJob` | Semanal (pendiente de programar) | OFAC SDN, ONU consolidada, UE |

Al desplegar la agenda de seguimiento sobre una base con sujetos existentes, correr una vez (idempotente):
```bash
docker exec vera_api php artisan vera:inicializar-seguimientos
```

> **IMPORTANTE:** el correo requiere SMTP real en producción (`MAIL_MAILER=smtp` y credenciales). Con `MAIL_MAILER=log` (desarrollo) el resumen queda en `storage/logs/laravel.log`. Si un tenant no tiene usuarios `oficial_cumplimiento` ni `admin`, las alertas quedan pendientes de envío y se registra un warning en el log.

Verificar que el scheduler está corriendo:
```bash
docker exec vera_api supervisorctl status
```

---

## 11. Despliegue en producción

1. **Provisionar el VPS** (Ubuntu 22.04+, Docker Engine + Compose v2 instalados).
2. **Clonar el repo** y configurar `.env`/`backend/.env` con los valores de producción (nunca reutilizar las claves de desarrollo).
3. En producción, `REDIS_HOST` debe apuntar a `host.docker.internal` (Redis compartido con el host, no en contenedor propio — `docker-compose.yml` ya trae `extra_hosts: host.docker.internal:host-gateway` para esto). El `docker-compose.override.yml` (que fija `REDIS_HOST=redis`) es **solo para desarrollo** — no debe existir en el servidor de producción, o Docker Compose lo aplicará también ahí.
4. Levantar el stack:
   ```bash
   docker compose up -d --build
   docker exec vera_api php artisan migrate --force
   docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
   ```
5. Configurar Apache2 como reverse proxy hacia `http://127.0.0.1:8000` (Apéndice B) con TLS (Apéndice A).
6. Desplegar el frontend (React) en Cloudflare Pages, apuntando `FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` al dominio real de producción.
7. Verificar Horizon: máximo 3 workers (`config/horizon.php`), dentro del presupuesto de 600 MB de RAM del producto.

> No hay todavía un pipeline de CI/CD definido en este proyecto — el despliegue de arriba es manual. Documentar aquí si se agrega uno.

---

## 12. Estructura del proyecto

```
vera/
├── docker/                  # Dockerfiles, supervisord, config de mariadb
├── docker-compose.yml
├── docker-compose.override.yml   # solo desarrollo (redis en contenedor propio)
├── backend/                 # API Laravel
│   ├── app/
│   │   ├── Actions/         # logica de escritura, una accion por caso de uso
│   │   ├── Http/Controllers # delgados - delegan a Actions/Services
│   │   ├── Http/Middleware
│   │   ├── Jobs/            # pipeline (seccion 3.4, en construccion)
│   │   ├── Models/
│   │   ├── Models/Concerns/ # traits compartidos (ej. DerivesTenantFromSubject)
│   │   ├── Policies/
│   │   ├── Providers/
│   │   └── Sources/         # adaptadores por fuente (seccion 4, en construccion)
│   ├── database/{migrations,factories,seeders}/
│   ├── routes/{api,web,console}.php
│   └── tests/{Feature,Unit}/
└── frontend/                # SPA React + Vite (scaffold, sin pantallas funcionales todavia)
    └── src/features/{consulta,vigilancia,coincidencias,evidencia,reportes,admin}/
```

---

## 13. Convenciones de código

- Controladores delgados; lógica en `Actions` y `Services`.
- Toda consulta a modelos de negocio pasa por el scope de tenant (`BelongsToTenant`). Prohibido `withoutGlobalScopes`/`withoutTenancy()` fuera de superadmin.
- Cualquier modelo con `tenant_id` propio que además cuelgue de un padre tenant-scoped debe derivar su `tenant_id` del padre (ver `App\Models\Concerns\DerivesTenantFromSubject`), nunca confiar en el tenant ambiente ni usar `withoutTenancy()` para "adivinarlo" — debe fallar cerrado.
- Migraciones con claves foráneas e índices explícitos en `tenant_id` + columnas de búsqueda.
- Pruebas: Pest. Cobertura obligatoria en matching, extracción (con respuestas grabadas) y aislamiento de tenant.
- Secretos solo en `.env`; nunca en código ni en commits.
- Commits en español, imperativo, sin emojis. Un PR por feature; no mezclar backend y frontend salvo cambio de contrato.

---

## 14. Referencia rápida de comandos

### Docker
```bash
docker compose up -d              # levantar el stack
docker compose down               # detener
docker compose logs -f api        # logs del contenedor api
docker exec -it vera_api bash     # shell dentro del contenedor
```

### Artisan (siempre dentro del contenedor)
```bash
docker exec vera_api php artisan migrate --force
docker exec vera_api php artisan migrate:rollback --step=1
docker exec vera_api php artisan db:seed --class=Database\\Seeders\\RoleSeeder
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

---

## 15. Feature Tests

El proyecto usa Pest. Cobertura obligatoria (sección 6 del CLAUDE.md): aislamiento de tenant, matching, extracción con respuestas de IA grabadas (fixtures, no llamadas reales en tests).

```bash
docker exec vera_api php artisan test           # suite completa
docker exec vera_api php artisan test --filter=SubjectTenantIsolationTest
```

`tests/Pest.php` aplica `RefreshDatabase` a todo `tests/Feature` — cada test corre contra una base SQLite en memoria, no contra la MariaDB de desarrollo.

---

## 16. Solución de problemas

| Síntoma | Causa probable | Solución |
|---------|----------------|---------|
| `docker compose up` falla pidiendo una variable | `.env` (raíz) sin `DB_PASSWORD`/`DB_ROOT_PASSWORD`/`MEILISEARCH_KEY` | Completar `.env` a partir de `.env.example` — son obligatorias a propósito, sin default débil |
| `GET /api/user` responde 500 en vez de 401 | Falta `$middleware->statefulApi()` o `APP_KEY` vacía | Verificar `bootstrap/app.php` y correr `php artisan key:generate` |
| Un usuario ve datos de otro tenant | Ruta de negocio sin el middleware `tenant`, o modelo sin `BelongsToTenant` | Revisar que la ruta esté en el grupo `['auth:sanctum','tenant']` y que el modelo use el trait |
| Un modelo con `tenant_id` propio queda con el tenant equivocado | Se creó mientras el tenant ambiente en `tenancy()` no coincidía con el del padre | Usar `App\Models\Concerns\DerivesTenantFromSubject` en vez de confiar en `tenancy()->tenant` |
| `MEILISEARCH_KEY` distinto entre `api` y `meilisearch` | Definida en dos archivos `.env` diferentes | Debe venir del mismo `.env` raíz para ambos servicios |
| Búsquedas de Google CSE agotadas a media mañana | `GOOGLE_CSE_DAILY_LIMIT` superado por el scheduler | Revisar la priorización por `nivel_riesgo` del scheduler, o subir el límite (con costo) |
| Llamadas a Anthropic fallan con error de autenticación/billing | Cuenta sin créditos cargados, o `ANTHROPIC_API_KEY` vacía | Cargar billing en console.anthropic.com y verificar la key en `backend/.env` |
| Aparece un archivo suelto `vera` (SQLite) en `backend/` | Algún comando resolvió la conexión `sqlite` con `DB_DATABASE=vera` del `.env` en vez de usar `mariadb` | Borrar el archivo; no afecta la BD real. Investigar si se repite seguido |
| `composer require` falla por conflicto de versiones de `phpunit/phpunit` | Un paquete (ej. Pest) requiere una versión de PHPUnit más baja que la instalada | Reintentar con `-W` para permitir que Composer ajuste `phpunit/phpunit` dentro del rango permitido por `composer.json` |

---

## Apéndice A — SSL/TLS con Let's Encrypt

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d api.tudominio.com
```

Certbot configura la renovación automática (`certbot renew` vía systemd timer). Verificar con:
```bash
sudo certbot renew --dry-run
```

---

## Apéndice B — Apache2 como reverse proxy delante de Docker

Apache2 corre en el host (fuera de Docker) y reenvía al contenedor `api`, que expone `8000` en el host.

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

En `backend/.env` de producción, `APP_URL`, `SANCTUM_STATEFUL_DOMAINS` y `SESSION_DOMAIN` deben usar el dominio real (`api.tudominio.com`), no `localhost`.
