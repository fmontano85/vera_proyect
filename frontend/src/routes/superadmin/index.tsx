import { createFileRoute } from '@tanstack/react-router';
import { TenantsPanel } from '@/features/superadmin/TenantsPanel';

/** Pantalla inicial del panel de superadmin: tenants (alta, renombrado, Sanciones por tenant). */
export const Route = createFileRoute('/superadmin/')({
  component: TenantsPanel,
});
