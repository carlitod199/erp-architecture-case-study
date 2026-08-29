import React, { useMemo, useState } from 'react';
import { View, Text, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { cores, fonte, espaco, acao, elevacao } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';
import { useRefrescar } from '../hooks/useRefrescar';
import { useSync } from '../context/SyncContext';
import { useAuth } from '../context/AuthContext';
import { papelApp, Cartao, Botao, Badge, Chip } from '../components/ui';
import Icone from '../components/Icone';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';

// Compras no app (view-only p/ pedidos): Solicitações (criar+acompanhar),
// Pedidos (status + receber), Aprovações (caixa do supervisor). A criação de
// pedido/cotação e os R$ ficam no VERO web. Recebimento reusa o motor do web
// (estoque custo-médio/FEFO + contas a pagar) — o app não envia dinheiro.

const SOL_STATUS = {
  aberta: { rotulo: 'Aberta', bg: cores.amberBg, fg: cores.amberDeep },
  em_cotacao: { rotulo: 'Em cotação', bg: cores.amberBg, fg: cores.amberDeep },
  convertida: { rotulo: 'Virou pedido ✓', bg: cores.posBg, fg: '#0a5c53' },
  cancelada: { rotulo: 'Cancelada', bg: cores.track, fg: cores.muted2 },
};
const PED_STATUS = {
  aprovacao: { rotulo: 'Em aprovação', bg: cores.amberBg, fg: cores.amberDeep },
  aprovado: { rotulo: 'A receber', bg: cores.posBg, fg: '#0a5c53' },
  recebido_parcial: { rotulo: 'Receb. parcial', bg: cores.amberBg, fg: cores.amberDeep },
  recebido: { rotulo: 'Recebido ✓', bg: cores.track, fg: cores.muted2 },
};

const reais = (v) => 'R$ ' + (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dataCurta = (v) => {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}` : '';
};

export default function ComprasScreen() {
  const nav = useNavigation();
  const { usuario } = useAuth();
  const ehSupervisor = papelApp(usuario) === 'encarregado';
  const { sincronizarAgora } = useSync();
  const { refrescando, aoRefrescar } = useRefrescar();

  const { itens: solicitacoes } = useDadosSync('compras_solicitacoes');
  const { itens: pedidos } = useDadosSync('compras_pedidos');
  const { itens: aprovacoes } = useDadosSync('compras_aprovacoes_pendentes');

  const [aba, setAba] = useState('solicitacoes');
  // pedidos que o supervisor acabou de decidir — some da caixa antes do próximo sync
  const [decididos, setDecididos] = useState(() => new Set());

  const abas = [
    { k: 'solicitacoes', t: 'Solicitações' },
    { k: 'pedidos', t: 'Pedidos' },
    ...(ehSupervisor ? [{ k: 'aprovacoes', t: `Aprovações${aprovacoes.length ? ` (${aprovacoes.filter((a) => !decididos.has(a.id)).length})` : ''}` }] : []),
  ];

  const solsOrdenadas = useMemo(() => (
    [...(solicitacoes || [])].sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
  ), [solicitacoes]);
  const pedidosOrdenados = useMemo(() => {
    const rank = (s) => (s === 'aprovado' ? 0 : s === 'recebido_parcial' ? 1 : s === 'aprovacao' ? 2 : 3);
    return [...(pedidos || [])].sort((a, b) => rank(a.status) - rank(b.status)
      || String(b.updated_at || '').localeCompare(String(a.updated_at || '')));
  }, [pedidos]);
  const aprovacoesPendentes = useMemo(() => (
    (aprovacoes || []).filter((a) => !decididos.has(a.id))
  ), [aprovacoes, decididos]);

  async function decidir(pedido, decisao) {
    setDecididos((prev) => new Set(prev).add(pedido.id));
    await enfileirar({
      tipo: 'compra_aprovacao',
      rota: rotas.compraDecidir(pedido.id, decisao),
      metodo: 'POST',
      payload: { observacao: '' },
    });
    sincronizarAgora().catch(() => {});
  }

  function itensResumo(itens) {
    return (itens || [])
      .map((i) => `${i.produto || i.descricao || 'item'} · ${String(i.quantidade).replace('.', ',')}`)
      .join('  ·  ');
  }

  // ---- renderers por aba ----
  function renderSolicitacao({ item: s }) {
    const st = SOL_STATUS[s.status] || SOL_STATUS.aberta;
    return (
      <Cartao>
        <View style={styles.linha1}>
          <Text style={styles.doc}>{s.numero}</Text>
          <Badge bg={st.bg} fg={st.fg}>{st.rotulo}</Badge>
        </View>
        {!!s.justificativa && <Text style={styles.sub}>{s.justificativa}</Text>}
        <Text style={styles.itens}>{itensResumo(s.itens)}</Text>
        {!!s.data_solicitacao && (
          <View style={styles.dataLinha}>
            <Icone nome="data" tam={13} cor={cores.muted2} />
            <Text style={styles.data}>{dataCurta(s.data_solicitacao)}</Text>
          </View>
        )}
      </Cartao>
    );
  }

  function renderPedido({ item: p }) {
    const st = PED_STATUS[p.status] || { rotulo: p.status, bg: cores.track, fg: cores.muted2 };
    const podeReceber = p.status === 'aprovado' || p.status === 'recebido_parcial';
    return (
      <Cartao
        destaque={podeReceber}
        onPress={podeReceber ? () => nav.navigate('ReceberPedido', { pedido: p }) : undefined}
      >
        <View style={styles.linha1}>
          <View style={{ flex: 1 }}>
            <Text style={styles.doc}>{p.numero} · {reais(p.valor_total)}</Text>
            <Text style={styles.sub}>{p.fornecedor || 'fornecedor —'}</Text>
          </View>
          <Badge bg={st.bg} fg={st.fg}>{st.rotulo}</Badge>
        </View>
        <Text style={styles.itens}>{itensResumo(p.itens)}</Text>
        {podeReceber && <Text style={styles.abrir}>Receber ›</Text>}
      </Cartao>
    );
  }

  function renderAprovacao({ item: a }) {
    return (
      <Cartao destaque>
        <View style={styles.linha1}>
          <View style={{ flex: 1 }}>
            <Text style={styles.doc}>{a.numero} · {reais(a.valor_total)}</Text>
            <Text style={styles.sub}>{a.fornecedor || 'fornecedor —'} · {a.itens_qtd} item(ns)</Text>
          </View>
          {Number(a.acima_orcamento) === 1 && (
            <Badge tipo="crit">fora do orçam.</Badge>
          )}
        </View>
        <View style={styles.decisao}>
          <Botao titulo="Rejeitar" variante="perigo" tamanho="sm" style={{ flex: 1 }} onPress={() => decidir(a, 'rejeitar')} />
          <Botao titulo="Aprovar" variante="primaria" tamanho="sm" style={{ flex: 1 }} onPress={() => decidir(a, 'aprovar')} />
        </View>
      </Cartao>
    );
  }

  const dados = aba === 'solicitacoes' ? solsOrdenadas
    : aba === 'pedidos' ? pedidosOrdenados : aprovacoesPendentes;
  const render = aba === 'solicitacoes' ? renderSolicitacao
    : aba === 'pedidos' ? renderPedido : renderAprovacao;
  const vazio = {
    solicitacoes: 'Nenhuma solicitação ainda. Toque em "＋ Nova solicitação".',
    pedidos: 'Nenhum pedido para acompanhar. Os pedidos são criados no VERO web.',
    aprovacoes: 'Nenhum pedido aguardando sua aprovação. 🎉',
  }[aba];

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Compras" sub="Solicitação · pedido · recebimento" />

      {/* abas */}
      <View style={styles.tabs}>
        {abas.map((t) => (
          <Chip key={t.k} selecionado={aba === t.k} onPress={() => setAba(t.k)}>{t.t}</Chip>
        ))}
      </View>

      <FlatList
        data={dados}
        keyExtractor={(x) => String(x.id)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={acao.primaria} colors={[acao.primaria]} />}
        renderItem={render}
        ListEmptyComponent={<Cartao><Text style={styles.sub}>{vazio}</Text></Cartao>}
      />

      {aba === 'solicitacoes' && (
        <Botao
          titulo="＋ Nova solicitação"
          onPress={() => nav.navigate('NovaSolicitacao')}
          style={styles.fab}
          estilo={elevacao.n2}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  tabs: { flexDirection: 'row', paddingHorizontal: espaco.md, gap: 8, paddingTop: 8, paddingBottom: 4 },
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  doc: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.monoSemi },
  sub: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 3 },
  itens: { fontSize: 12, color: cores.ink2, fontFamily: fonte.sansSemi, marginTop: 8 },
  dataLinha: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 8 },
  data: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi },
  abrir: { fontSize: 12.5, color: cores.accent, fontFamily: fonte.sansBold, marginTop: 9, alignSelf: 'flex-end' },
  decisao: { flexDirection: 'row', gap: 10, marginTop: 12 },
  fab: { position: 'absolute', left: espaco.md, right: espaco.md, bottom: 18 },
});
