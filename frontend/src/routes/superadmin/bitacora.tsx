import { createFileRoute } from '@tanstack/react-router';
import { BitacoraGlobal } from '@/features/bitacora/BitacoraGlobal';

/** Bitacora de todos los tenants, sin datos personales (seccion 3.9, punto 7). */
export const Route = createFileRoute('/superadmin/bitacora')({
  component: BitacoraGlobal,
});
