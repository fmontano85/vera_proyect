import { createFileRoute, Outlet, redirect } from '@tanstack/react-router';
import { authQueryKey, fetchCurrentUser } from '@/features/auth/useAuth';

/**
 * Layout route sin segmento de URL (prefijo _): envuelve toda ruta
 * protegida. beforeLoad usa el queryClient del contexto del router
 * (ver main.tsx) para compartir cache con useCurrentUser() - un solo
 * GET /api/user, no uno por el guard y otro por el hook.
 */
export const Route = createFileRoute('/_authenticated')({
  beforeLoad: async ({ context }) => {
    const user = await context.queryClient.ensureQueryData({
      queryKey: authQueryKey,
      queryFn: fetchCurrentUser,
    });

    if (!user) {
      throw redirect({ to: '/login' });
    }

    return { user };
  },
  component: Outlet,
});
