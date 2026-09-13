<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Vacio a proposito: no se decidio todavia si el tenant actual se resuelve
| por dominio/subdominio (mecanismo nativo de stancl/tenancy) o por el
| tenant_id del usuario autenticado via Sanctum (ver "Pendiente de decidir"
| en el CLAUDE.md raiz). El scaffold original de stancl/tenancy traia aqui
| una ruta de ejemplo con InitializeTenancyByDomain que pisaba la ruta "/"
| de routes/web.php (se registra despues y sobreescribe la entrada en la
| coleccion de rutas), rompiendo la app fuera de un dominio de tenant.
|
*/
