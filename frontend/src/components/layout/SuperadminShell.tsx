import { Link, useNavigate, useRouterState } from '@tanstack/react-router';
import { Building2, LogOut, ScrollText, ShieldAlert, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUser, useLogout } from '@/features/auth/useAuth';

const NAV = [
  { to: '/superadmin', label: 'Tenants', icon: Building2, titulo: 'Tenants' },
  { to: '/superadmin/sanciones', label: 'Listas de sanciones', icon: ShieldAlert, titulo: 'Listas de sanciones' },
  { to: '/superadmin/bitacora', label: 'Bitácora', icon: ScrollText, titulo: 'Bitácora de la plataforma' },
] as const;

/** Mismo lenguaje visual que AppShell (sidebar oscuro + topbar), con el menu del superadmin. */
export function SuperadminShell({ children }: { children: ReactNode }) {
  const { data: user } = useCurrentUser();
  const logout = useLogout();
  const navigate = useNavigate();
  const ruta = useRouterState({ select: (s) => s.location.pathname.replace(/\/$/, '') });
  const titulo = NAV.find((n) => n.to === ruta)?.titulo ?? 'Panel de superadmin';

  return (
    <div className="flex min-h-dvh">
      <aside className="bg-sidebar text-sidebar-foreground fixed inset-y-0 left-0 hidden w-64 flex-col md:flex">
        <div className="flex h-14 items-center gap-2 px-4">
          <ShieldCheck className="text-sidebar-primary size-5" />
          <span className="font-semibold tracking-tight">VERA</span>
          <span className="text-sidebar-foreground/60 text-xs">Superadmin</span>
        </div>
        <nav className="flex flex-col gap-1 px-2 py-2">
          {NAV.map(({ to, label, icon: Icon }) => (
            <Link
              key={to}
              to={to}
              // '/superadmin' es prefijo de las demas: sin exact quedaria siempre activo.
              activeOptions={{ exact: true }}
              className="hover:bg-sidebar-accent hover:text-sidebar-accent-foreground data-[status=active]:bg-sidebar-accent data-[status=active]:text-sidebar-accent-foreground flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors"
            >
              <Icon className="size-4" />
              {label}
            </Link>
          ))}
        </nav>
      </aside>

      <div className="flex flex-1 flex-col md:ml-64">
        <header className="border-border bg-card sticky top-0 z-10 flex h-14 items-center justify-between border-b px-4">
          <h1 className="text-lg font-semibold">{titulo}</h1>

          {user && (
            <DropdownMenu>
              <DropdownMenuTrigger className="flex items-center gap-2 rounded-full">
                <Avatar className="size-8">
                  <AvatarFallback>SA</AvatarFallback>
                </Avatar>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuLabel>
                  <div className="flex flex-col">
                    <span className="font-medium">{user.name}</span>
                    <span className="text-muted-foreground text-xs font-normal">superadmin</span>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  variant="destructive"
                  onSelect={() => logout.mutate(undefined, { onSuccess: () => navigate({ to: '/login' }) })}
                >
                  <LogOut />
                  Cerrar sesión
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </header>

        <main className="mx-auto w-full max-w-5xl flex-1 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
