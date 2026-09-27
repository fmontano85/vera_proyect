import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Regla de contrasena del backend (UsuarioController::reglaClave):
 * minimo 12 caracteres, con letras y numeros. Un solo lugar para el
 * minLength y el texto de ayuda - antes repetidos en UsuarioDialog y en
 * cuenta/index.tsx; si el backend cambia la regla, aqui es el unico
 * sitio que hay que tocar en el frontend. */
export const CONTRASENA_MIN_LENGTH = 12;
export const CONTRASENA_AYUDA = 'Mínimo 12 caracteres, con letras y números.';

export function CampoContrasena({
  id,
  label,
  value,
  onChange,
  autoComplete,
  required,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (valor: string) => void;
  autoComplete: 'new-password' | 'current-password';
  required?: boolean;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type="password"
        autoComplete={autoComplete}
        required={required}
        minLength={autoComplete === 'new-password' ? CONTRASENA_MIN_LENGTH : undefined}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      {autoComplete === 'new-password' && <p className="text-muted-foreground text-xs">{CONTRASENA_AYUDA}</p>}
    </div>
  );
}
