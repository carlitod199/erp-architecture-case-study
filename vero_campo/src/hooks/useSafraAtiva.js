import { useMemo } from 'react';
import { useDadosSync } from './useDadosSync';

// Safra ativa do cache de /sync/safras — fim do "Safra 2026.1" fixo nas telas.
// A identificação real (ex.: 2026.1-01) nasce na tela Abertura de Safra do VERO web.
export function useSafraAtiva() {
  const { itens } = useDadosSync('safras');
  return useMemo(() => {
    const lista = itens || [];
    const aberta = lista.find((s) => s.status && s.status !== 'encerrada' && s.status !== 'cancelada');
    if (aberta) return aberta;
    // sem status utilizável: a de início mais recente
    return [...lista].sort((a, b) =>
      String(b.data_inicio || '').localeCompare(String(a.data_inicio || ''))
    )[0] || null;
  }, [itens]);
}

// rótulo pronto para subtítulos; null quando ainda não sincronizou
export function rotuloSafra(safra) {
  return safra?.identificacao ? `Safra ${safra.identificacao}` : null;
}

export default useSafraAtiva;
