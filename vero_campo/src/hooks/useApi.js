import { useCallback, useEffect, useState } from 'react';

// Hook simples de chamada assíncrona com estados (espelha useApi.js do VERO).
// uso: const { dados, carregando, erro, recarregar } = useApi(() => veroApi.sync('talhoes'));
export function useApi(fn, deps = [], { imediato = true } = {}) {
  const [dados, setDados] = useState(null);
  const [carregando, setCarregando] = useState(imediato);
  const [erro, setErro] = useState(null);

  const executar = useCallback(async (...args) => {
    setCarregando(true);
    setErro(null);
    try {
      const r = await fn(...args);
      setDados(r?.data !== undefined ? r.data : r);
      return r;
    } catch (e) {
      setErro(e);
      throw e;
    } finally {
      setCarregando(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  useEffect(() => {
    if (imediato) executar().catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return { dados, carregando, erro, recarregar: executar };
}

export default useApi;
