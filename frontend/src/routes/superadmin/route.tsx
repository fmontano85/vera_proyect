import { createFileRoute, Outlet, redirect } from '@tanstack/react-router';
import { SuperadminShell } from '@/components/layout/SuperadminShell';
import { authQueryKey, fetchCurrentUser } from '@/features/auth/useAuth';

/**
 * Layout del panel de superadmin (seccion 3.2 del CLAUDE.md raiz): menu
 * lateral propio, fuera de _authenticated - superadmin no tiene tenant y
 * ninguna pantalla de tenant le sirve.
 */
export const Route = createFileRoute('/superadmin')({
  beforeLoad: async ({ context }) => {
    const user = await context.queryClient.ensureQueryData({
      queryKey: authQueryKey,
      queryFn: fetchCurrentUser,
    });

    if (!user) {
      throw redirect({ to: '/login' });
    }
    if (!user.roles.includes('superadmin')) {
      throw redirect({ to: '/' });
    }
  },
  component: () => (
    <SuperadminShell>
      <Outlet />
    </SuperadminShell>
  ),
});
