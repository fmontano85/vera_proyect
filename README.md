# VERA — Verificación de Exposición a Riesgo Adverso

> Plataforma SaaS multi-tenant de *adverse media screening* y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador).
> Expansión prevista a Guatemala, Honduras y Costa Rica.

**Estado:** Fase 1 (MVP) en desarrollo. El backend del pipeline está completo y probado; el frontend es solo un scaffold.

---

## Qué hace

Permite que un oficial de cumplimiento:

1. Consulte puntualmente si una persona (natural o jurídica) aparece en medios salvadoreños y fuentes oficiales vinculada a procesos judiciales.
2. Mantenga una lista de vigilancia con monitoreo continuo y alertas *(Fase 2)*.
3. Cruce nombres contra listas de sanciones (OFAC SDN; ONU y UE pendientes).
4. Registre evidencia auditable (snapshot + hash SHA-256 + timestamp) y la resolución humana de cada coincidencia.
5. Genere reportes exportables *(Fase 3)*.

> **Principio no negociable:** el sistema *propone* coincidencias, el analista *resuelve*. Ninguna pantalla ni reporte afirma que una persona "está involucrada" sin una resolución humana registrada.

## Cómo funciona

```
Consulta puntual ─► RunSubjectSearchJob ─► FetchArticleJob ─► ExtractEntitiesJob ─► MatchMentionsJob
   (POST /buscar)     Google CSE           descarga + hash     Claude Haiku/Sonnet    Meilisearch
                                           + evidencia         (JSON validado)        └► matches "pendiente"
```

Cada job es idempotente y reintentable, y corre en colas de Horizon con prioridad `alerts > matching > extraction > fetch > search > imports`.

## Stack

| Capa | Tecnología |
|------|-----------|
| Backend | PHP 8.4 · Laravel 13 (API REST) · Sanctum · Horizon · Scout |
| Multi-tenancy | `stancl/tenancy` (BD única + `tenant_id`) |
| Permisos y auditoría | `spatie/laravel-permission` · `spatie/laravel-activitylog` · `spatie/laravel-data` |
| Base de datos | MariaDB 11 |
| Búsqueda | Meilisearch (matching fuzzy de nombres) |
| Colas | Redis 7 |
| IA | Anthropic Claude (Haiku para extracción, Sonnet para escalado) |
| Fuentes | Google Custom Search API · listas OFAC |
| Frontend | React 19 · Vite · TypeScript · Tailwind v4 · TanStack Query/Router |
| Infraestructura | Docker Compose · Apache2 (reverse proxy) · Cloudflare R2 y Pages |

## Requisitos

- Docker Desktop (o Docker Engine + Compose v2).
- Node.js para el frontend.

> **IMPORTANTE:** no se instala PHP, Composer, MariaDB, Meilisearch ni Redis en el host. Todo el backend corre dentro de Docker Compose.

## Puesta en marcha (desarrollo)

Desde la raíz del repositorio:

```bash
# 1. Variables de Docker Compose
cp .env.example .env          # ajustar contraseñas y MEILISEARCH_KEY

# 2. Variables de la aplicación
cp backend/.env.example backend/.env   # agregar GOOGLE_CSE_API_KEY, GOOGLE_CSE_CX, ANTHROPIC_API_KEY

# 3. Levantar contenedores (api, mariadb, meilisearch, redis)
docker compose up -d

# 4. Migraciones y roles
docker exec vera_api php artisan migrate --seed

# 5. Tests
docker exec vera_api php artisan test
```

| Servicio | Puerto |
|----------|--------|
| API | `:8000` |
| Meilisearch | `:7700` |
| MariaDB | `:3306` |
| Redis | `:6379` |

> **IMPORTANTE:** Horizon carga el `.env` una sola vez al arrancar. Si cambias una API key con los contenedores corriendo, ejecuta `docker compose restart api`.

### Probar el pipeline sin frontend

```bash
# Crea tenant + usuario + token Sanctum + fuente CSE + subject de prueba (solo dev)
docker exec vera_api php artisan vera:demo "Nombre a buscar"

# Con el token impreso:
curl -X POST http://localhost:8000/api/subjects/{id}/buscar   -H "Authorization: Bearer <token>"
curl         http://localhost:8000/api/subjects/{id}/matches  -H "Authorization: Bearer <token>"

# Si una llamada externa falla:
docker exec vera_api php artisan queue:failed
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

## API (Fase 1)

Todas las rutas de negocio requieren `auth:sanctum` y resuelven el tenant desde el usuario autenticado.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/subjects` | Lista de subjects del tenant |
| `POST` | `/api/subjects` | Crea un subject |
| `GET` | `/api/subjects/{subject}` | Detalle |
| `POST` | `/api/subjects/{subject}/buscar` | Dispara la consulta puntual |
| `GET` | `/api/subjects/{subject}/matches` | Coincidencias propuestas |

## Roles

| Rol | Alcance |
|-----|---------|
| `superadmin` | Tenants, planes y fuentes globales (sin acceso a datos de negocio de un tenant) |
| `admin` | Usuarios y configuración del tenant |
| `oficial_cumplimiento` | Resuelve coincidencias, aprueba reportes, gestiona vigilancia |
| `analista` | Ejecuta consultas, propone resoluciones |
| `lectura` | Solo consulta y reportes |

## Estructura del proyecto

```
VERA/
├── backend/            Laravel (API, jobs, adaptadores de fuentes, tests)
│   ├── app/{Actions,Jobs,Sources,Services,Data,Models,Policies}
│   └── resources/prompts/extraction/   Prompts de IA versionados
├── frontend/           React + Vite (scaffold)
├── docker/             Dockerfile de la API y config de MariaDB
├── docs/               MANUAL_TECNICO.md y resultados de PoC
├── docker-compose.yml
└── CLAUDE.md           Fuente de verdad: arquitectura, decisiones y estatus
```

## Fases

- **Fase 0** — Pruebas de concepto (cobertura de CSE, fechas de publicación, calibración de prompts).
- **Fase 1** — MVP: multi-tenant, consulta puntual, sanciones, resolución manual, evidencia. *(en curso)*
- **Fase 2** — Monitoreo continuo, alertas por correo y Google Sheets.
- **Fase 3** — Planes, facturación, reportes de auditoría, API pública.

## Pendientes conocidos

- Vincular facturación en Google Cloud: sin ella Google CSE responde `403 PERMISSION_DENIED`.
- Adaptadores de ONU consolidada y UE.
- Pantallas funcionales del frontend.

## Documentación

- [`docs/MANUAL_TECNICO.md`](docs/MANUAL_TECNICO.md) — instalación, configuración y despliegue en producción.
- [`CLAUDE.md`](CLAUDE.md) — arquitectura, convenciones y decisiones del proyecto.

## Seguridad

- Los secretos viven solo en `.env` (git-ignorado); nunca se comitean.
- Aislamiento por `tenant_id` con global scope; el acceso entre tenants falla cerrado.
- Toda resolución de coincidencia queda registrada en `activity_log`.
- Las llamadas a servicios de pago por uso tienen tope duro de cuota diaria (`GOOGLE_CSE_DAILY_LIMIT`).
