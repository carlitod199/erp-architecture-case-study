import React, { useState } from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { useSync } from '../context/SyncContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { Cartao, Botao, Input, Chip } from '../components/ui';

// Recebe itens contra um pedido aprovado. O app manda só quantidade + validade;
// custo/parcelas saem do próprio pedido no servidor (P-75). Baixa parcial ok.

const numDec = (v) => {
  const s = String(v).replace(/\./g, '').replace(',', '.');
  const n = Number(s);
  return Number.isFinite(n) ? n : 0;
};
const fmt = (n) => String(Math.round((Number(n) || 0) * 10000) / 10000).replace('.', ',');

function maisMeses(m) {
  const d = new Date();
  d.setMonth(d.getMonth() + m);
  return d.toISOString().slice(0, 10);
}

export default function ReceberPedidoScreen() {
  const nav = useNavigation();
  const route = useRoute();
  const { sincronizarAgora } = useSync();
  const pedido = route.params?.pedido || null;

  const itensPedido = (pedido?.itens || []).map((i) => {
    const pendente = (Number(i.quantidade) || 0) - (Number(i.quantidade_recebida) || 0);
    return { ...i, pendente };
  });

  const [linhas, setLinhas] = useState(() => (
    itensPedido.reduce((acc, i) => {
      acc[i.pedido_item_id] = { quantidade: i.pendente > 0 ? fmt(i.pendente) : '', validade: '' };
      return acc;
    }, {})
  ));
  const [enviado, setEnviado] = useState(false);
  const [enviando, setEnviando] = useState(false);

  function setCampo(id, campo, v) {
    setLinhas((L) => ({ ...L, [id]: { ...L[id], [campo]: v } }));
  }

  const aReceber = itensPedido
    .map((i) => ({ item: i, qtd: numDec(linhas[i.pedido_item_id]?.quantidade), validade: linhas[i.pedido_item_id]?.validade }))
    .filter((r) => r.qtd > 0);
  const excede = aReceber.some((r) => r.qtd > r.item.pendente + 0.0001);
  const pronto = aReceber.length > 0 && !excede;

  async function confirmar() {
    if (!pronto || enviando || !pedido) return;
    setEnviando(true);
    try {
      await enfileirar({
        tipo: 'compra_recebimento',
        rota: rotas.compraReceber(pedido.id),
        metodo: 'POST',
        payload: {
          itens: aReceber.map((r) => ({
            pedido_item_id: r.item.pedido_item_id,
            quantidade: r.qtd,
            validade: /^\d{4}-\d{2}-\d{2}$/.test(String(r.validade || '')) ? r.validade : undefined,
          })),
        },
      });
      setEnviado(true);
      sincronizarAgora().catch(() => {});
    } finally {
      setEnviando(false);
    }
  }

  if (!pedido) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Receber pedido" />
        <View style={styles.corpo}><Cartao><Text style={styles.sub}>Pedido não encontrado. Volte às compras.</Text></Cartao></View>
      </View>
    );
  }

  if (enviado) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Receber pedido" sub={pedido.numero} />
        <View style={styles.corpo}>
          <View style={styles.sucesso}>
            <Text style={styles.sucessoIcone}>✓</Text>
            <Text style={styles.sucessoTitulo}>Recebimento registrado</Text>
            <Text style={styles.sucessoMsg}>Dá entrada no estoque e lança a conta a pagar no escritório. Será enviado quando houver sinal.</Text>
            <Botao titulo="Voltar às compras" onPress={() => nav.navigate('Compras')} style={{ marginTop: 10 }} />
          </View>
        </View>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Receber pedido" sub={`${pedido.numero} · ${pedido.fornecedor || ''}`} />
      <ScrollView contentContainerStyle={styles.corpo} keyboardShouldPersistTaps="handled">
        <Text style={styles.nota}>
          Informe o que chegou. Pode receber parcial — o que faltar continua pendente no pedido.
        </Text>

        {itensPedido.map((i) => {
          const L = linhas[i.pedido_item_id] || {};
          const jaTudo = i.pendente <= 0.0001;
          const excedeItem = numDec(L.quantidade) > i.pendente + 0.0001;
          return (
            <Cartao key={i.pedido_item_id} style={jaTudo && { opacity: 0.5 }}>
              <Text style={styles.itemNome}>{i.produto || i.descricao || `Item #${i.pedido_item_id}`}</Text>
              <Text style={styles.itemInfo}>
                pedido {fmt(i.quantidade)} · recebido {fmt(i.quantidade_recebida)} · pendente {fmt(i.pendente)}
              </Text>
              {!jaTudo && (
                <>
                  <View style={styles.qtdLinha}>
                    <Text style={styles.qtdRot}>Recebendo</Text>
                    <Input
                      value={L.quantidade}
                      onChangeText={(v) => setCampo(i.pedido_item_id, 'quantidade', v.replace(/[^\d.,]/g, ''))}
                      keyboardType="decimal-pad"
                      placeholder="0"
                      estiloCampo={[styles.qtdCampo, excedeItem && styles.qtdCampoErro]}
                    />
                  </View>
                  {excedeItem && <Text style={styles.erro}>maior que o pendente ({fmt(i.pendente)})</Text>}
                  {Number(i.controla_validade) === 1 && (
                    <View style={{ marginTop: 8 }}>
                      <Text style={styles.qtdRot}>Validade do lote</Text>
                      <Input
                        value={L.validade}
                        onChangeText={(v) => setCampo(i.pedido_item_id, 'validade', v)}
                        placeholder="AAAA-MM-DD"
                        estiloCampo={styles.validadeCampo}
                        style={{ marginTop: 6 }}
                      />
                      <View style={styles.chips}>
                        {[6, 12, 24].map((m) => (
                          <Chip key={m} onPress={() => setCampo(i.pedido_item_id, 'validade', maisMeses(m))}>+{m}m</Chip>
                        ))}
                      </View>
                    </View>
                  )}
                </>
              )}
            </Cartao>
          );
        })}

        <Botao
          titulo={pronto ? 'Confirmar recebimento' : 'Informe a quantidade recebida'}
          disabled={!pronto || enviando}
          onPress={confirmar}
          style={{ marginTop: 10 }}
        />
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  nota: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 17 },
  sub: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed },
  itemNome: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  itemInfo: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi, marginTop: 4 },
  qtdLinha: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 },
  qtdRot: { fontSize: 12, color: cores.ink2, fontFamily: fonte.sansSemi },
  qtdCampo: {
    width: 110, fontSize: 15, color: cores.ink, fontFamily: fonte.monoSemi, textAlign: 'right',
  },
  qtdCampoErro: { borderColor: cores.danger, borderWidth: 1.5 },
  erro: { fontSize: 11, color: cores.danger, fontFamily: fonte.sansSemi, marginTop: 5, alignSelf: 'flex-end' },
  validadeCampo: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.monoSemi },
  chips: { flexDirection: 'row', gap: 8, marginTop: 8 },
  sucesso: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 22, alignItems: 'center', gap: 6 },
  sucessoIcone: { fontSize: 32, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 16, color: '#0a5c53', fontFamily: fonte.sansBold },
  sucessoMsg: { fontSize: 12.5, color: '#0a5c53', fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 18 },
});
