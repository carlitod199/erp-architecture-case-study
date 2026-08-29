import { useMemo } from 'react';
import { useDadosSync } from './useDadosSync';

// Lê um parâmetro do tenant do cache de /sync/parametros (tenant_parametros).
// uso: const meta = useParametro('mip.meta_pontos_valvula', 5);
export function useParametro(chave, padrao = null) {
  const { itens } = useDadosSync('parametros');
  return useMemo(() => {
    const p = (itens || []).find((i) => i.chave === chave);
    if (!p || p.valor === null || p.valor === undefined || p.valor === '') return padrao;
    const n = Number(String(p.valor).replace(',', '.'));
    return Number.isFinite(n) ? n : p.valor;
  }, [itens, chave, padrao]);
}

export default useParametro;
