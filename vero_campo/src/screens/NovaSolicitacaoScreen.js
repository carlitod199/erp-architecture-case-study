import React, { useMemo, useState } from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { Cartao, Botao, Input, TituloSecao } from '../components/ui';

// Cria uma solicitação de compra (SC). O item pode ser um produto do estoque
// (vira produto_id) ou uma descrição livre. Sem R$ — preço/fornecedor é o web.

export default function NovaSolicitacaoScreen() {
  const nav = useNavigation();
  const route = useRoute();
  const { sincronizarAgora } = useSync();
  const { itens: produtos } = useDadosSync('estoque');

  // pré-carrega o produto quando aberto pela ficha do estoque ("Solicitar compra")
  const preProduto = route.params?.produto || null;
  const [itens, setItens] = useState(() => (
    preProduto ? [{ produto_id: preProduto.id, nome: preProduto.nome, unidade: preProduto.unidade, quantidade: '' }] : []
  ));
  const [busca, setBusca] = useState('');
  const [livre, setLivre] = useState('');
  const [justificativa, setJustificativa] = useState('');
  const [enviado, setEnviado] = useState(false);
  const [enviando, setEnviando] = useState(false);

  const achados = useMemo(() => {
    const q = busca.trim().toLowerCase();
    if (q.length < 2) return [];
    return (produtos || [])
      .filter((p) => String(p.nome || '').toLowerCase().includes(q) || String(p.codigo || '').toLowerCase().includes(q))
      .filter((p) => !itens.some((i) => i.produto_id === p.id))
      .slice(0, 8);
  }, [busca, produtos, itens]);

  function addProduto(p) {
    setItens((xs) => [...xs, { produto_id: p.id, nome: p.nome, unidade: p.unidade, quantidade: '' }]);
    setBusca('');
  }
  function addLivre() {
    const t = livre.trim();
    if (!t) return;
    setItens((xs) => [...xs, { produto_id: null, nome: t, unidade: '', quantidade: '' }]);
    setLivre('');
  }
  function setQtd(ix, v) {
    setItens((xs) => xs.map((it, i) => (i === ix ? { ...it, quantidade: v.replace(/[^\d.,]/g, '') } : it)));
  }
  function remover(ix) {
    setItens((xs) => xs.filter((_, i) => i !== ix));
  }

  const numDec = (v) => {
    const s = String(v).replace(/\./g, '').replace(',', '.');
    const n = Number(s);
    return Number.isFinite(n) ? n : 0;
  };
  const prontos = itens.filter((i) => numDec(i.quantidade) > 0);
  const pronto = prontos.length > 0;

  async function enviar() {
    if (!pronto || enviando) return;
    setEnviando(true);
    try {
      await enfileirar({
        tipo: 'solicitacao_compra',
        rota: rotas.compraSolicitar,
        metodo: 'POST',
        payload: {
          justificativa: justificativa.trim() || null,
          itens: prontos.map((i) => ({
            produto_id: i.produto_id ?? undefined,
            descricao: i.produto_id ? undefined : i.nome,
            quantidade: numDec(i.quantidade),
          })),
        },
      });
      setEnviado(true);
      sincronizarAgora().catch(() => {});
    } finally {
      setEnviando(false);
    }
  }

  if (enviado) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Solicitação de compra" />
        <View style={styles.corpo}>
          <View style={styles.sucesso}>
            <Text style={styles.sucessoIcone}>✓</Text>
            <Text style={styles.sucessoTitulo}>Solicitação registrada</Text>
            <Text style={styles.sucessoMsg}>Vai para o escritório cotar/gerar o pedido. Acompanhe o status em Compras.</Text>
            <Botao titulo="Voltar às compras" onPress={() => nav.navigate('Compras')} style={{ marginTop: 10 }} />
          </View>
        </View>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Nova solicitação" sub="O que precisa comprar" />
      <ScrollView contentContainerStyle={styles.corpo} keyboardShouldPersistTaps="handled">
        {/* itens adicionados */}
        {itens.map((it, ix) => (
          <Cartao key={`${it.produto_id ?? 'l'}-${ix}`} style={styles.itemCard}>
            <View style={{ flex: 1 }}>
              <Text style={styles.itemNome}>{it.nome}{it.produto_id ? '' : '  ·  (livre)'}</Text>
              {!!it.unidade && <Text style={styles.itemUn}>em {it.unidade}</Text>}
            </View>
            <Input
              value={it.quantidade}
              onChangeText={(v) => setQtd(ix, v)}
              keyboardType="decimal-pad"
              placeholder="qtd"
              estiloCampo={styles.qtdCampo}
            />
            <Pressable onPress={() => remover(ix)} style={styles.remover}>
              <Text style={styles.removerTxt}>✕</Text>
            </Pressable>
          </Cartao>
        ))}

        {/* buscar produto do estoque */}
        <TituloSecao>Adicionar item</TituloSecao>
        <Input
          value={busca}
          onChangeText={setBusca}
          placeholder="Buscar produto do estoque…"
        />
        {achados.map((p) => (
          <Pressable key={p.id} style={styles.achado} onPress={() => addProduto(p)}>
            <Text style={styles.achadoNome}>{p.nome}</Text>
            <Text style={styles.achadoAdd}>＋ adicionar</Text>
          </Pressable>
        ))}

        {/* item livre */}
        <View style={styles.livreLinha}>
          <Input
            style={{ flex: 1 }}
            value={livre}
            onChangeText={setLivre}
            placeholder="…ou descreva um item fora do estoque"
          />
          <Botao titulo="＋" onPress={addLivre} style={styles.livreAdd} estilo={styles.livreAddInner} />
        </View>

        {/* justificativa */}
        <TituloSecao>Justificativa (opcional)</TituloSecao>
        <Input
          value={justificativa}
          onChangeText={setJustificativa}
          placeholder="Por que precisa desta compra?"
          multiline
          estiloCampo={{ minHeight: 72, textAlignVertical: 'top', paddingTop: 10 }}
        />

        <Botao
          titulo={pronto ? 'Enviar solicitação' : 'Adicione um item com quantidade'}
          disabled={!pronto || enviando}
          onPress={enviar}
          style={{ marginTop: 10 }}
        />
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  itemCard: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  itemNome: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  itemUn: { fontSize: 11, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 2 },
  qtdCampo: {
    width: 76, fontSize: 14, color: cores.ink, fontFamily: fonte.monoSemi, textAlign: 'right',
  },
  remover: { paddingHorizontal: 6, paddingVertical: 8 },
  removerTxt: { fontSize: 14, color: cores.muted2, fontFamily: fonte.sansBold },
  achado: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    backgroundColor: cores.campo, borderRadius: raio.sm, paddingHorizontal: 12, paddingVertical: 11,
  },
  achadoNome: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  achadoAdd: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },
  livreLinha: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  livreAdd: { width: 46 },
  livreAddInner: { minHeight: 46, paddingHorizontal: 0 },
  sucesso: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 22, alignItems: 'center', gap: 6 },
  sucessoIcone: { fontSize: 32, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 16, color: '#0a5c53', fontFamily: fonte.sansBold },
  sucessoMsg: { fontSize: 12.5, color: '#0a5c53', fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 18 },
});
