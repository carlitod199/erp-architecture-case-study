import React, { useMemo, useState } from 'react';
import { View, Text, FlatList, RefreshControl, StyleSheet } from 'react-native';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { cores, fonte, espaco } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';
import { useRefrescar } from '../hooks/useRefrescar';
import { useAuth } from '../context/AuthContext';
import { Cartao, Badge, Chip } from '../components/ui';

// Contas a pagar e receber (view-only): fila de títulos do módulo Financeiro
// sincronizados como retrato. Sem escrita — o app não movimenta dinheiro.
// As abas aparecem conforme a permissão (contas_pagar.ver / contas_receber.ver).

const reais = (v) => 'R$ ' + (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hojeISO = () => new Date().toISOString().slice(0, 10);
const dataBR = (v) => {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : null;
};

// situação exibida: título aberto/previsto com vencimento no passado = VENCIDO
function situacao(t) {
  const pago = t.status === 'pago' || t.status === 'baixado';
  if (pago) return { k: 'pago', rotulo: 'Pago', bg: cores.track, fg: cores.muted2 };
  const vencido = t.data_vencimento && t.data_vencimento < hojeISO();
  if (vencido) return { k: 'vencido', rotulo: 'Vencido', bg: cores.dangerBg || '#fdecea', fg: cores.danger };
  return { k: 'aberto', rotulo: 'Em aberto', bg: cores.amberBg, fg: cores.amberDeep };
}

export default function ContasScreen() {
  const { pode } = useAuth();
  const podePagar = pode('financeiro.contas_pagar.ver');
  const podeReceber = pode('financeiro.contas_receber.ver');

  const { itens, carregado } = useDadosSync('financeiro');
  const { refrescando, aoRefrescar } = useRefrescar();

  const abas = [
    ...(podePagar ? [{ k: 'pagar', t: 'A pagar' }] : []),
    ...(podeReceber ? [{ k: 'receber', t: 'A receber' }] : []),
  ];
  const [aba, setAba] = useState(abas[0]?.k || 'pagar');

  // títulos da aba atual, abertos/vencidos primeiro, depois por vencimento
  const lista = useMemo(() => {
    const rank = (t) => {
      const s = situacao(t);
      return s.k === 'vencido' ? 0 : s.k === 'aberto' ? 1 : 2;
    };
    return (itens || [])
      .filter((t) => t.tipo === aba)
      .sort((a, b) => rank(a) - rank(b)
        || String(a.data_vencimento || '9999').localeCompare(String(b.data_vencimento || '9999')));
  }, [itens, aba]);

  // resumo do topo: total em aberto e total vencido da aba
  const resumo = useMemo(() => {
    let aberto = 0; let vencido = 0; let nVenc = 0;
    for (const t of lista) {
      const s = situacao(t);
      if (s.k === 'pago') continue;
      aberto += Number(t.valor) || 0;
      if (s.k === 'vencido') { vencido += Number(t.valor) || 0; nVenc += 1; }
    }
    return { aberto, vencido, nVenc };
  }, [lista]);

  function renderTitulo({ item: t }) {
    const s = situacao(t);
    const nome = t.descricao || t.fornecedor
      || (t.origem_tipo ? String(t.origem_tipo).replace(/_/g, ' ') : 'Título');
    const venc = dataBR(t.data_vencimento);
    return (
      <Cartao>
        <View style={styles.linha1}>
          <View style={{ flex: 1 }}>
            <Text style={styles.nome} numberOfLines={1}>{nome}</Text>
            {!!t.fornecedor && t.fornecedor !== nome && (
              <Text style={styles.sub} numberOfLines={1}>{t.fornecedor}</Text>
            )}
          </View>
          <Text style={[styles.valor, aba === 'receber' && { color: cores.pos }]}>{reais(t.valor)}</Text>
        </View>
        <View style={styles.linha2}>
          <View style={styles.vencLinha}>
            <Icone nome="data" tam={13} cor={cores.muted2} />
            <Text style={styles.venc}>{venc ? `vence ${venc}` : 'sem vencimento'}</Text>
          </View>
          <Badge bg={s.bg} fg={s.fg}>{s.rotulo}</Badge>
        </View>
      </Cartao>
    );
  }

  const rotuloAba = aba === 'receber' ? 'a receber' : 'a pagar';

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Contas" sub="A pagar e a receber" />

      {abas.length > 1 && (
        <View style={styles.tabs}>
          {abas.map((t) => (
            <Chip key={t.k} selecionado={aba === t.k} onPress={() => setAba(t.k)}>{t.t}</Chip>
          ))}
        </View>
      )}

      {/* resumo do topo */}
      <View style={styles.resumo}>
        <View style={styles.resumoItem}>
          <Text style={styles.resumoRot}>Em aberto ({rotuloAba})</Text>
          <Text style={styles.resumoVal}>{reais(resumo.aberto)}</Text>
        </View>
        <View style={[styles.resumoItem, styles.resumoDiv]}>
          <Text style={styles.resumoRot}>Vencido{resumo.nVenc ? ` · ${resumo.nVenc}` : ''}</Text>
          <Text style={[styles.resumoVal, resumo.vencido > 0 && { color: cores.danger }]}>{reais(resumo.vencido)}</Text>
        </View>
      </View>

      <FlatList
        data={lista}
        keyExtractor={(x) => String(x.id)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={cores.accent} colors={[cores.accent]} />}
        renderItem={renderTitulo}
        ListEmptyComponent={
          <Cartao>
            <Text style={styles.sub}>
              {carregado ? `Nenhuma conta ${rotuloAba} no período.` : 'Carregando…'}
            </Text>
          </Cartao>
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  tabs: { flexDirection: 'row', paddingHorizontal: espaco.md, gap: 8, paddingTop: 8, paddingBottom: 4 },
  resumo: {
    flexDirection: 'row', marginHorizontal: espaco.md, marginTop: 8,
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border, borderRadius: 14,
  },
  resumoItem: { flex: 1, paddingVertical: 12, paddingHorizontal: 14 },
  resumoDiv: { borderLeftWidth: 1, borderLeftColor: cores.border },
  resumoRot: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed },
  resumoVal: { fontSize: 16.5, color: cores.ink, fontFamily: fonte.monoSemi, marginTop: 3 },
  corpo: { padding: espaco.md, paddingBottom: 40, gap: 10 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  nome: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  sub: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 3 },
  valor: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.monoSemi },
  linha2: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 },
  vencLinha: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  venc: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi },
});
