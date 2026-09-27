import { useEffect, useState } from 'react';

/** Devuelve `valor` recien despues de que pasen `delayMs` sin que cambie -
 * para no disparar un request (ej. una busqueda en el servidor) en cada
 * tecla. */
export function useDebouncedValue<T>(valor: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(valor);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(valor), delayMs);
    return () => clearTimeout(id);
  }, [valor, delayMs]);

  return debounced;
}
