import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import NetInfo from '@react-native-community/netinfo';
import { contarPendentes, contarFalhas, reidratarPresos } from '../offline/fila';
import { enviarFila, sincronizarLeitura } from '../offline/sincronizador';
import { useAuth } from './AuthContext';

const SyncContext = createContext(null);

export function SyncProvider({ children }) {
  const { logado } = useAuth();
  const [online, setOnline] = useState(true);
  const [pendentes, setPendentes] = useState(0);
  const [falhas, setFalhas] = useState(0); // rejeitados pelo servidor (surfacer 5.3)
  const [sincronizando, setSincronizando] = useState(false);
  const [ultimaSync, setUltimaSync] = useState(null); // tick p/ telas relerem o cache

  const atualizarContador = useCallback(async () => {
    setPendentes(await contarPendentes());
    setFalhas(await contarFalhas());
  }, []);

  // observa conexão; ao reconectar, tenta enviar a fila sozinho
  useEffect(() => {
    // 5.1: itens presos em 'enviando' (crash no meio do envio) voltam à fila
    reidratarPresos().catch(() => {});
    const unsub = NetInfo.addEventListener((estado) => {
      const conectado = !!estado.isConnected;
      setOnline(conectado);
      if (conectado) sincronizarAgora();
    });
    atualizarContador();
    return () => unsub();
  }, []);

  const sincronizarAgora = useCallback(async () => {
    if (sincronizando) return;
    setSincronizando(true);
    try {
      await enviarFila();
      await sincronizarLeitura();
    } finally {
      await atualizarContador();
      setSincronizando(false);
      setUltimaSync(Date.now());
    }
  }, [sincronizando, atualizarContador]);

  // carga inicial ao logar (o listener de rede cuida das reconexões)
  useEffect(() => {
    if (logado) sincronizarAgora();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [logado]);

  const value = { online, pendentes, falhas, sincronizando, ultimaSync, atualizarContador, sincronizarAgora };
  return <SyncContext.Provider value={value}>{children}</SyncContext.Provider>;
}

export function useSync() {
  const ctx = useContext(SyncContext);
  if (!ctx) throw new Error('useSync deve estar dentro de SyncProvider');
  return ctx;
}

export default SyncContext;
