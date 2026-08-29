import React, { useMemo, useState } from 'react';
import { View, Text, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import CarregandoVero from '../components/CarregandoVero';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { cores, fonte, espaco, severidade } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { Cartao, Botao, Badge, Chip } from '../components/ui';

// J4 — Avisos (raiz da stack do sino). Lista real do cache de /sync/alertas
// (severidade: critico|atencao|info; status: aberto|reconhecido). Reconhecer é
// otimista e entra na fila offline (POST /alertas/{id}/reconhecer).

const FILTROS = [
  { id: 'todos', rotulo: 'Todos' },
  { id: 'critico', rotulo: 'Críticos' },
  { id: 'atencao', rotulo: 'Atenção' },
  { id: 'info', rotulo: 'Info' },
];

const SEV_ROTULO = { critico: 'Crítico', atencao: 'Atenção', info: 'Info' };

const CATEGORIA_ROTULO = {
  mip: 'Monitoramento MIP',
  estoque: 'Estoque',
  clima: 'Clima',
  financeiro: 'Financeiro',
  agenda: 'Agenda',
  pessoas: 'Pessoas',
};

// 'YYYY-MM-DD[ HH:MM:SS]' → Date local
function parseData(valor) {
  if (!valor) return null;
  const m = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
  if (!m) return null;
  return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
}

// tempo relativo em pt-BR: "agora", "há 35 min", "há 2 h", "ontem", "há 4 dias", "03/06"
function tempoRelativo(valor) {
  const d = parseData(valor);
  if (!d) return '';
  const min = Math.floor((Date.now() - d.getTime()) / 60000);
  if (min < 1) return 'agora';
  if (min < 60) return `há ${min} min`;
  const h = Math.floor(min / 60);
  if (h < 24) return `há ${h} h`;
  const dias = Math.floor(h / 24);
  if (dias === 1) return 'ontem';
  if (dias < 30) return `há ${dias} dias`;
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

export default function AlertasScreen() {
  const nav = useNavigation();
  const { itens, carregado, sincronizando } = useDadosSync('alertas');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { sincronizarAgora } = useSync();
  const [filtro, setFiltro] = useState('todos');
  // reconhecimentos otimistas ainda não confirmados pelo sync
  const [reconhecidosLocais, setReconhecidosLocais] = useState({});

  const alertas = useMemo(() => (itens || []).map((a) => {
    const sev = severidade[a.severidade] ? a.severidade : 'info';
    const categoria = CATEGORIA_ROTULO[a.categoria] || a.categoria || 'Geral';
    return {
      id: String(a.id),
      sev,
      titulo: a.titulo || 'Alerta',
      origem: a.talhao_id ? `${categoria} · válvula ${a.talhao_id}` : categoria,
      categoria,
      tempo: tempoRelativo(a.data),
      detalhe: a.mensagem || '',
      data: a.data,
      talhao_id: a.talhao_id,
      status: reconhecidosLocais[a.id] ? 'reconhecido' : (a.status || 'aberto'),
    };
  }), [itens, reconhecidosLocais]);

  const visiveis = useMemo(() => {
    const base = filtro === 'todos' ? alertas : alertas.filter((a) => a.sev === filtro);
    // críticos primeiro, reconhecidos por último, mais recentes no topo
    const peso = { critico: 0, atencao: 1, info: 2 };
    return [...base].sort((a, b) => {
      if (a.status !== b.status) return a.status === 'aberto' ? -1 : 1;
      if (peso[a.sev] !== peso[b.sev]) return peso[a.sev] - peso[b.sev];
      return String(b.data || '').localeCompare(String(a.data || ''));
    });
  }, [alertas, filtro]);

  const abertos = alertas.filter((a) => a.status === 'aberto').length;

  function reconhecer(id) {
    // otimista: badge muda já; a fila leva ao servidor quando der
    setReconhecidosLocais((m) => ({ ...m, [id]: true }));
    enfileirar({
      tipo: 'alerta',
      rota: rotas.alertaReconhecer(id),
      metodo: 'POST',
      payload: {},
    })
      .then(() => { sincronizarAgora(); })
      .catch(() => {});
  }

  const vazioGeral = carregado && alertas.length === 0;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        semVoltar
        titulo="Avisos"
        sub={sincronizando ? 'sincronizando…' : `${abertos} abertos`}
      />

      <View style={styles.filtros}>
        {FILTROS.map((f) => (
          <Chip key={f.id} selecionado={filtro === f.id} onPress={() => setFiltro(f.id)}>{f.rotulo}</Chip>
        ))}
      </View>

      <FlatList
        data={visiveis}
        keyExtractor={(a) => a.id}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor="#00464e" colors={["#00464e"]} />}
        ListEmptyComponent={
          !carregado ? (
            <View style={{ alignItems: 'center', marginTop: 40, gap: 12 }}>
              <CarregandoVero />
              <Text style={styles.vazio}>Carregando avisos…</Text>
            </View>
          ) : vazioGeral ? (
            <View style={styles.vazioWrap}>
              <Text style={styles.vazioIc}>🎉</Text>
              <Text style={styles.vazio}>Nenhum alerta 🎉</Text>
              <Botao
                titulo={sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
                onPress={() => sincronizarAgora()}
                disabled={sincronizando}
                style={{ marginTop: 12 }}
              />
            </View>
          ) : (
            <Text style={styles.vazio}>Nenhum aviso neste filtro. 🎉</Text>
          )
        }
        renderItem={({ item: a }) => {
          const cor = severidade[a.sev];
          const reconhecido = a.status === 'reconhecido';
          return (
            <Cartao
              alerta={cor}
              onPress={() => nav.navigate('AlertaDetalhe', { alerta: a })}
              style={reconhecido && { opacity: 0.62 }}
            >
              <View style={styles.linha1}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.titulo}>{a.titulo}</Text>
                  <Text style={styles.origem}>{a.origem}</Text>
                </View>
              </View>

              <View style={styles.linha2}>
                <Text style={[styles.sevTxt, { color: cor }]}>{SEV_ROTULO[a.sev]}</Text>
                <Text style={styles.tempo}>{a.tempo}</Text>
              </View>
              {!!a.detalhe && <Text style={styles.detalhe}>{a.detalhe}</Text>}

              {!reconhecido && (
                <Botao
                  titulo="Reconhecer"
                  variante="secundaria"
                  tamanho="sm"
                  onPress={(e) => { e.stopPropagation?.(); reconhecer(a.id); }}
                  style={{ marginTop: 11 }}
                />
              )}
            </Cartao>
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  filtros: {
    flexDirection: 'row', gap: 7,
    paddingHorizontal: espaco.md, paddingVertical: 10, backgroundColor: cores.page,
  },
  corpo: { paddingHorizontal: espaco.md, paddingBottom: 96, gap: 10 },
  vazioWrap: { alignItems: 'center', marginTop: 32, gap: 6 },
  vazioIc: { fontSize: 34 },
  vazio: { textAlign: 'center', marginTop: 8, fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },
  linha1: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  titulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold, lineHeight: 19 },
  origem: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  linha2: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 8 },
  sevTxt: { fontSize: 11, fontFamily: fonte.sansBold, textTransform: 'uppercase', letterSpacing: 0.4 },
  tempo: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi },
  detalhe: { fontSize: 11.5, color: cores.ink2, fontFamily: fonte.sansMed, marginTop: 5, lineHeight: 17 },
});
