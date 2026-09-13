# VERA — Verificación de Exposición a Riesgo Adverso

Plataforma SaaS multi-tenant de *adverse media screening* y debida diligencia para sujetos obligados bajo la Ley Contra el Lavado de Dinero y de Activos (El Salvador), con expansión prevista a Guatemala, Honduras y Costa Rica.

Este archivo es la fuente de verdad para Claude Code. Léelo completo antes de cualquier tarea. No cambies stack, librerías ni decisiones de arquitectura sin confirmación explícita del propietario.

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
- Base de datos única; todo modelo de negocio lleva `tenant_id` con global scope.
- Índices de Meilisearch únicos por entidad con atributo filtrable `tenant_id`; toda búsqueda filtra por tenant.
- Usuarios pertenecen a un solo tenant. Rol `superadmin` (propietario de la plataforma) opera fuera de tenant.
- Almacenamiento en R2 con prefijo `tenants/{tenant_id}/evidence/`.

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
- Toda consulta a modelos de negocio pasa por el scope de tenant. Prohibido `withoutGlobalScopes` fuera de superadmin.
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
- `systematic-debugging` — **no instalada** (bloqueada, ver Contexto)

### Capa 2 — recomendadas para este proyecto
- `ui-ux-pro-max` — **no instalada** (bloqueada). Aplica porque VERA es multi-módulo (consulta, vigilancia, coincidencias, evidencia, reportes, admin) y necesita un design system consistente entre pantallas. Es el default de diseño de este proyecto una vez instalada (no `frontend-design`, ya que no hay landing/pieza aislada).
- `test-driven-development` — **no instalada** (bloqueada). Aplica por la lógica de negocio compleja (matching, extracción IA, pipeline de jobs) y las APIs REST.
- `varlock` — **no instalada** (bloqueada). Aplica por el volumen de credenciales/API keys del proyecto (Google CSE, Anthropic, R2, SMTP, Sheets).
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

### Contexto importante para retomar
- **Git no está instalado** en este entorno (Windows, `spawn git ENOENT` al ejecutar `npx skills add`). Esto bloquea dos cosas: (1) instalar las skills marcadas como "no instalada" arriba — los paquetes candidatos ya están identificados, ver tabla abajo; (2) inicializar el repositorio (`git init`) que el flujo de ramas de la sección "Git" de este documento (`main`/`develop`/`feature/*`) requiere. Instalar Git (ej. `winget install --id Git.Git -e`) y reintentar.
- Paquetes candidatos ya evaluados (verificar instalaciones/reputación siguen vigentes antes de instalar, con `npx skills find <nombre>`):
  - `obra/superpowers@systematic-debugging`
  - `obra/superpowers@test-driven-development`
  - `nextlevelbuilder/ui-ux-pro-max-skill@ui-ux-pro-max`
  - `dmno-dev/varlock@varlock` (fuente oficial del proyecto, pocas instalaciones pero autor original)
- Ningún código de VERA existe todavía; solo este `CLAUDE.md`. La Fase 0 (POC de Google CSE, extracción de fecha, prompt de extracción) es el punto de partida natural una vez resuelto el bloqueo de Git.
