import { Link, useNavigate } from '@tanstack/react-router';
import { CalendarClock, LogOut, Search, Settings, ShieldCheck, Tags } from 'lucide-react';
import type { ReactNode } from 'react';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { esAdmin, useCurrentUser, useLogout } from '@/features/auth/useAuth';

const NAV_ITEMS = [
  { to: '/subjects', label: 'Sujetos', icon: Search },
  { to: '/seguimientos', label: 'Seguimientos', icon: CalendarClock },
  { to: '/busqueda-tags', label: 'Búsqueda por tags', icon: Tags },
] as const;

/** Solo admin (seccion 3.2: configuracion del tenant). El backend igual
 * responde 403 al PUT para cualquier otro rol. */
const NAV_ADMIN = [{ to: '/configuracion', label: 'Configuración', icon: Settings }] as const;

export function AppShell({ title, children }: { title: string; children: ReactNode }) {
  const { data: user } = useCurrentUser();
  const logout = useLogout();
  const navigate = useNavigate();

  function iniciales(nombre: string) {
    return nombre
      .split(' ')
      .slice(0, 2)
      .map((p) => p[0])
      .join('')
      .toUpperCase();
  }

  return (
    <div className="flex min-h-dvh">
      <aside className="bg-sidebar text-sidebar-foreground fixed inset-y-0 left-0 hidden w-64 flex-col md:flex">
        <div className="flex h-14 items-center gap-2 px-4">
          <ShieldCheck className="text-sidebar-primary size-5" />
          <span className="font-semibold tracking-tight">VERA</span>
        </div>
        <nav className="flex flex-col gap-1 px-2 py-2">
          {[...NAV_ITEMS, ...(esAdmin(user) ? NAV_ADMIN : [])].map(({ to, label, icon: Icon }) => (
            <Link
              key={to}
              to={to}
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
          <h1 className="text-lg font-semibold">{title}</h1>

          {user && (
            <DropdownMenu>
              <DropdownMenuTrigger className="flex items-center gap-2 rounded-full">
                <Avatar className="size-8">
                  <AvatarFallback>{iniciales(user.name)}</AvatarFallback>
                </Avatar>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuLabel>
                  <div className="flex flex-col">
                    <span className="font-medium">{user.name}</span>
                    <span className="text-muted-foreground text-xs font-normal">
                      {user.roles.join(', ')}
                    </span>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  variant="destructive"
                  onSelect={() => logout.mutate(undefined, { onSuccess: () => navigate({ to: '/login' }) })}
                >
                  <LogOut />
                  Cerrar sesion
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </header>

        <main className="flex-1 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
