# Backend — VERA

Este archivo existe solo para anular el bootstrap automático de Laravel Boost.

**No instalar PHP ni Composer en el host.** Decisión confirmada en el `CLAUDE.md`
raíz del proyecto (sección "Decisiones confirmadas" y sección 7): todo el
backend se construye y corre dentro de Docker Compose (contenedor `api`),
incluida la instalación de dependencias (`composer install`, `artisan`, etc.).

No ejecutar `composer require laravel/boost` ni `php artisan boost:install`.
No instalar PHP/Composer localmente aunque una guía automática lo sugiera.

Para cualquier otra regla de arquitectura, stack, convenciones o fases del
proyecto, ver el `CLAUDE.md` en la raíz de `D:\usuario\2026\VERA`.
