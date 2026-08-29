import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, FlatList, RefreshControl, StyleSheet, Alert } from 'react-native';
import { useIsFocused } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { useSync } from '../context/SyncContext';
import { todos, marcar, descartar } from '../offline/fila';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { Cartao, Badge, Botao, TituloSecao } from '../components/ui';
import Icone from '../components/Icone';

// Fila de envio REAL (SQLite, offline/fila.js) — pendente → enviando →
// confirmado | erro. Idempotente por client_uuid.

const ESTADOS = {
  confirmado: { icone: '✓', rotulo: 'Confirmado', bg: cores.posBg, fg: '#0a5c53' },
  pendente: { icone: '⏳', rotulo: 'Pendente', bg: cores.amberBg, fg: cores.amberDeep },
  enviando: { icone: '↻', rotulo: 'Enviando…', bg: cores.track, fg: cores.accent },
  erro: { icone: '⚠️', rotulo: 'Vai retentar', bg: cores.amberBg, fg: cores.amberDeep },
  falha: { icone: '✕', rotulo: 'Não aceito', bg: cores.dangerBg, fg: '#8f2a20' },
};

const TIPOS = {
  horimetro: { icone: '⏱️', rotulo: 'Horímetro' },
  abastecimento: { icone: '⛽', rotulo: 'Abastecimento' },
  apontamento: { icone: '✂️', rotulo: 'Apontamento' },
  apontamento_concluir: { icone: '✅', rotulo: 'Finalização de apontamento' },
  apontamento_producao: { icone: '👥', rotulo: 'Pessoas do apontamento' },
  colheita_registro: { icone: '🧺', rotulo: 'Registro de colheita' },
  colheita_realizado: { icone: '🧺', rotulo: 'Produção da colheita' },
  aplicacao_emitir: { icone: '🧪', rotulo: 'DF — emissão' },
  recebimento: { icone: '📦', rotulo: 'Recebimento de insumo' },
  monitoramento: { icone: '🐛', rotulo: 'Monitoramento MIP' },
  irrigacao: { icone: '💧', rotulo: 'Irrigação' },
  anexo: { icone: '📷', rotulo: 'Foto / anexo' },
  status: { icone: '✓', rotulo: 'Status de tarefa' },
  alerta: { icone: '🔔', rotulo: 'Alerta reconhecido' },
  aplicacao_confirmar: { icone: '🧪', rotulo: 'DF — confirmação' },
  aplicacao_assinar: { icone: '✍️', rotulo: 'DF — assinatura' },
  ph_beep: { icone: '📦', rotulo: 'Bipe de caixa (packing)' },
  ph_recepcao: { icone: '🚚', rotulo: 'Recepção de cargas (packing)' },
};

const rotuloTipo = (tipo) => {
  if (TIPOS[tipo]) return TIPOS[tipo];
  const t = String(tipo || 'registro');
  return { icone: '📤', rotulo: t.charAt(0).toUpperCase() + t.slice(1) };
};

// tipo do registro → nome no set de Icone (só apresentação da lista)
const ICONE_TIPO = {
  horimetro: 'servico',
  abastecimento: 'abastecimento',
  apontamento: 'tarefas',
  apontamento_concluir: 'tarefas',
  apontamento_producao: 'tarefas',
  colheita_registro: 'colheita',
  colheita_realizado: 'colheita',
  aplicacao_emitir: 'aplicacao',
  recebimento: 'estoque',
  monitoramento: 'monitoramento',
  irrigacao: 'irrigacao',
  anexo: 'camera',
  status: 'tarefas',
  alerta: 'alerta',
  aplicacao_confirmar: 'aplicacao',
  aplicacao_assinar: 'tarefas',
  ph_beep: 'scanner',
  ph_recepcao: 'colheita',
};

const fmtHora = (iso) => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}/${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}`;
};

export default function SincronizacaoScreen() {
  const { online, sincronizando, sincronizarAgora, ultimaSync } = useSync();
  const { refrescando, aoRefrescar } = useRefrescar();
  const focada = useIsFocused();
  const [fila, setFila] = useState([]);

  const recarregar = useCallback(() => {
    todos()
      .then((itens) => setFila(itens || []))
      .catch(() => setFila([]));
  }, []);

  // relê a fila ao focar e sempre que uma sincronização termina
  useEffect(() => {
    recarregar();
  }, [focada, ultimaSync, recarregar]);

  const aEnviar = fila.filter((i) => i.estado === 'pendente' || i.estado === 'enviando' || i.estado === 'erro').length;
  const comErro = fila.filter((i) => i.estado === 'falha').length;

  const ultimoSyncTxt = ultimaSync
    ? (() => {
        const d = new Date(ultimaSync);
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
      })()
    : '—';

  async function tentarDeNovo(clientUuid) {
    try {
      await marcar(clientUuid, 'pendente');
      recarregar();
      sincronizarAgora().catch(() => {});
    } catch {
      // fila indisponível — nada a fazer agora
    }
  }

  // 22/07: registro recusado pelo sistema pode ser DESCARTADO (some da fila
  // e do banner; anexos filhos vão junto)
  function descartarItem(item) {
    const t = rotuloTipo(item.tipo);
    Alert.alert(
      'Descartar registro',
      `${t.rotulo} recusado pelo sistema será apagado do aparelho — ele NÃO foi gravado no VERO.\n\nMotivo da recusa: ${item.erro || 'não informado'}`,
      [
        { text: 'Manter', style: 'cancel' },
        {
          text: 'Descartar', style: 'destructive',
          onPress: async () => {
            try { await descartar(item.client_uuid); } catch { /* fila indisponível */ }
            recarregar();
            sincronizarAgora().catch(() => {}); // atualiza contagem do banner
          },
        },
      ]
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Sincronização" sub="Fila de envio" />

      <FlatList
        data={fila}
        keyExtractor={(i) => String(i.client_uuid || i.id)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor="#00464e" colors={["#00464e"]} />}
        ListHeaderComponent={
          <View style={{ gap: 10 }}>
            {/* Estado da conexão */}
            <Cartao>
              <View style={styles.conexao}>
                <View style={[styles.bolinha, { backgroundColor: online ? cores.pos : cores.danger }]} />
                <Text style={styles.conexaoTxt}>
                  {online ? 'Conectado ao servidor' : 'Sem conexão — registros guardados no aparelho'}
                </Text>
              </View>
              <View style={styles.resumo}>
                <View style={styles.resumoItem}>
                  <Text style={styles.resumoNum}>{aEnviar}</Text>
                  <Text style={styles.resumoRotulo}>a enviar</Text>
                </View>
                <View style={styles.resumoSep} />
                <View style={styles.resumoItem}>
                  <Text style={[styles.resumoNum, comErro > 0 && { color: cores.danger }]}>{comErro}</Text>
                  <Text style={styles.resumoRotulo}>{comErro === 1 ? 'não aceito' : 'não aceitos'}</Text>
                </View>
                <View style={styles.resumoSep} />
                <View style={styles.resumoItem}>
                  <Text style={styles.resumoNum}>{ultimoSyncTxt}</Text>
                  <Text style={styles.resumoRotulo}>último sync</Text>
                </View>
              </View>
              <Botao
                titulo={sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
                icone={<Icone nome="sync" tam={18} cor={cores.surface} />}
                onPress={() => sincronizarAgora().catch(() => {})}
                disabled={sincronizando}
                estilo={{ marginTop: 12 }}
              />
            </Cartao>
            <TituloSecao>Registros na fila</TituloSecao>
          </View>
        }
        ListEmptyComponent={
          <Text style={styles.vazio}>Nada na fila — tudo sincronizado ✓</Text>
        }
        renderItem={({ item: i }) => {
          const st = ESTADOS[i.estado] || ESTADOS.pendente;
          const t = rotuloTipo(i.tipo);
          const confirmado = i.estado === 'confirmado';
          const detalhe = (i.estado === 'erro' || i.estado === 'falha') && i.erro
            ? `${i.erro} · ${fmtHora(i.criado_em)}`
            : `${i.rota} · ${fmtHora(i.criado_em)}`;
          return (
            <Cartao style={[styles.item, confirmado && { opacity: 0.62 }]}>
              <View style={styles.linha1}>
                <Icone nome={ICONE_TIPO[i.tipo] || 'upload'} tam={20} cor={cores.muted} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.titulo}>{t.rotulo}</Text>
                  <Text style={[styles.detalhe, i.estado === 'falha' && { color: cores.danger }]}>
                    {detalhe}
                  </Text>
                </View>
                <Badge bg={st.bg} fg={st.fg}>{st.icone} {st.rotulo}</Badge>
              </View>
              {(i.estado === 'erro' || i.estado === 'falha') && (
                <View style={styles.acoesLinha}>
                  <Botao titulo="Tentar de novo" variante="secundaria" tamanho="sm" style={{ flex: 1 }} onPress={() => tentarDeNovo(i.client_uuid)} />
                  {i.estado === 'falha' && (
                    <Botao titulo="Descartar" variante="perigo" tamanho="sm" onPress={() => descartarItem(i)} />
                  )}
                </View>
              )}
            </Cartao>
          );
        }}
        ListFooterComponent={
          <Text style={styles.nota}>
            Cada registro tem um identificador único (client_uuid) — reenviar nunca duplica no servidor.
          </Text>
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  conexao: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  bolinha: { width: 10, height: 10, borderRadius: 5 },
  conexaoTxt: { flex: 1, fontSize: 12.5, color: cores.ink, fontFamily: fonte.sansSemi },
  resumo: {
    flexDirection: 'row', alignItems: 'center', marginTop: 14,
    backgroundColor: cores.campo, borderRadius: raio.r, paddingVertical: 12,
  },
  resumoItem: { flex: 1, alignItems: 'center' },
  resumoNum: { fontSize: 18, color: cores.ink, fontFamily: fonte.monoSemi },
  resumoRotulo: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  resumoSep: { width: 1, height: 26, backgroundColor: cores.border },
  vazio: { textAlign: 'center', fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 14 },
  item: { padding: 13 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  titulo: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  detalhe: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  acoesLinha: { flexDirection: 'row', gap: 8, marginTop: 11 },
  nota: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 4, lineHeight: 15 },
});
