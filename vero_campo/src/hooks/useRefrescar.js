import { useCallback, useState } from 'react';
import { useSync } from '../context/SyncContext';

// Pull-to-refresh padrão do app: arrastar para baixo dispara o sync completo
// (fila + leitura). uso:
//   const { refrescando, aoRefrescar } = useRefrescar();
//   <FlatList refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} />} …
export function useRefrescar() {
  const { sincronizarAgora } = useSync();
  const [refrescando, setRefrescando] = useState(false);

  const aoRefrescar = useCallback(async () => {
    setRefrescando(true);
    try {
      await sincronizarAgora();
    } catch (_) {
      // offline/erro: o spinner some e a fila cuida do resto
    } finally {
      setRefrescando(false);
    }
  }, [sincronizarAgora]);

  return { refrescando, aoRefrescar };
}

export default useRefrescar;
