import { useEffect, useState } from 'react';
import { useIsFocused } from '@react-navigation/native';
import { lerCache } from '../offline/db';
import { useSync } from '../context/SyncContext';

// Lê os itens de um módulo do cache local (offline-first) e relê quando a
// tela ganha foco ou quando uma sincronização termina.
// uso: const { itens, carregado, sincronizando } = useDadosSync('talhoes');
export function useDadosSync(modulo) {
  const { ultimaSync, sincronizando } = useSync();
  const focada = useIsFocused();
  const [itens, setItens] = useState([]);
  const [carregado, setCarregado] = useState(false);

  useEffect(() => {
    let ativo = true;
    lerCache(modulo)
      .then((lidos) => {
        if (ativo) {
          setItens(lidos);
          setCarregado(true);
        }
      })
      .catch(() => {
        if (ativo) setCarregado(true);
      });
    return () => {
      ativo = false;
    };
  }, [modulo, ultimaSync, focada]);

  return { itens, carregado, sincronizando };
}

export default useDadosSync;
