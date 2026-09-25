import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Lista de textos libres como chips (aliases en el alta de un sujeto).
 * Enter o "+" agrega; se ignoran vacios y repetidos (sin distinguir
 * mayusculas), igual que valida el backend.
 */
export function ChipsInput({
  id,
  value,
  onChange,
  placeholder,
  max = 20,
}: {
  id?: string;
  value: string[];
  onChange: (value: string[]) => void;
  placeholder?: string;
  max?: number;
}) {
  const [texto, setTexto] = useState('');
  const limpio = texto.trim();
  const repetido = value.some((v) => v.toLocaleLowerCase('es') === limpio.toLocaleLowerCase('es'));
  const puedeAgregar = limpio !== '' && !repetido && value.length < max;

  function agregar() {
    if (!puedeAgregar) return;
    onChange([...value, limpio]);
    setTexto('');
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex gap-2">
        <Input
          id={id}
          value={texto}
          placeholder={placeholder}
          maxLength={255}
          onChange={(e) => setTexto(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              agregar();
            }
          }}
        />
        <Button type="button" variant="outline" size="icon" onClick={agregar} disabled={!puedeAgregar} aria-label="Agregar">
          <Plus />
        </Button>
      </div>
      {repetido && limpio !== '' && <p className="text-muted-foreground text-xs">Ya está en la lista.</p>}
      {value.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {value.map((item) => (
            <Badge key={item} variant="outline" className="gap-1 pr-1">
              {item}
              <button
                type="button"
                className="hover:bg-muted rounded-sm p-0.5"
                aria-label={`Quitar ${item}`}
                onClick={() => onChange(value.filter((v) => v !== item))}
              >
                <X className="size-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
}
