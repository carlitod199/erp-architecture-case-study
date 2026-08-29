import React, { useMemo, useState } from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import CarregandoVero from '../components/CarregandoVero';
import { Input, Chip, Botao } from '../components/ui';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { cores, fonte, acao, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';

// Consulta de saldo do almoxarifado — R$ oculto por perfil; a baixa vem do
// apontamento. Dados reais do cache offline (GET /sync/estoque). Lista corrida
// e enxuta (sem agrupamento) — escala p/ centenas de itens.

const fmtQtd = (n) => (Number(n) || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });

const ORDENS = [
  { id: 'nome', rotulo: 'A–Z' },
  { id: 'saldo', rotulo: 'Menor saldo' },
];

export default function EstoqueScreen() {
  const nav = useNavigation();
  const { itens, carregado } = useDadosSync('estoque');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { sincronizando, sincronizarAgora } = useSync();
  const [busca, setBusca] = useState('');
  const [soAbaixo, setSoAbaixo] = useState(false);
  const [ordem, setOrdem] = useState('nome');

  const abaixoDe = (i) => (Number(i.saldo) || 0) < (Number(i.estoque_minimo) || 0);

  const visiveis = useMemo(() => {
    const q = busca.trim().toLowerCase();
    const arr = (itens || []).filter((i) => {
      if (soAbaixo && !abaixoDe(i)) return false;
      if (q && !String(i.nome || '').toLowerCase().includes(q)
            && !String(i.codigo || '').toLowerCase().includes(q)) return false;
      return true;
    });
    if (ordem === 'saldo') {
      arr.sort((a, b) => (Number(a.saldo) || 0) - (Number(b.saldo) || 0));
    } else {
      arr.sort((a, b) => String(a.nome || '').localeCompare(String(b.nome || ''), 'pt-BR'));
    }
    return arr;
  }, [itens, busca, soAbaixo, ordem]);

  const cacheVazio = carregado && (itens || []).length === 0;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Estoque" sub="Almoxarifado · consulta" />

      <View style={styles.topo}>
        <Input
          value={busca}
          onChangeText={setBusca}
          placeholder="Buscar produto…"
          returnKeyType="search"
        />

        {/* filtro + ordenação, chips compactos */}
        <View style={styles.chips}>
          <Chip selecionado={!soAbaixo} onPress={() => setSoAbaixo(false)}>Todos</Chip>
          <Chip selecionado={soAbaixo} onPress={() => setSoAbaixo(true)}>Abaixo do mínimo</Chip>
          <View style={styles.divisor} />
          {ORDENS.map((o) => (
            <Chip key={o.id} selecionado={ordem === o.id} onPress={() => setOrdem(o.id)}>{o.rotulo}</Chip>
          ))}
        </View>
      </View>

      <FlatList
        data={visiveis}
        keyExtractor={(i) => String(i.id || i.client_uuid)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={acao.primaria} colors={[acao.primaria]} />}
        ListEmptyComponent={
          cacheVazio ? (
            <View style={styles.vazioWrap}>
              <Text style={[styles.vazio, { marginTop: 0 }]}>Nenhum produto no aparelho ainda.</Text>
              <Botao
                titulo={sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
                onPress={sincronizarAgora}
                disabled={sincronizando}
              />
            </View>
          ) : carregado ? (
            <Text style={styles.vazio}>Nenhum item encontrado.</Text>
          ) : (
            <View style={{ alignItems: 'center', marginTop: 40 }}><CarregandoVero /></View>
          )
        }
        renderItem={({ item: i }) => {
          const saldo = Number(i.saldo) || 0;
          const abaixo = saldo < (Number(i.estoque_minimo) || 0);
          return (
            <Pressable
              style={({ pressed }) => [styles.row, pressed && { backgroundColor: cores.campo }]}
              onPress={() => nav.navigate('EstoqueItem', { produto: i })}
            >
              <Text style={styles.nome} numberOfLines={1}>{i.nome}</Text>
              <Text style={[styles.saldo, abaixo && { color: cores.danger }]}>{fmtQtd(saldo)}</Text>
              <Text style={styles.unidade}>{i.unidade || ''}</Text>
            </Pressable>
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  topo: { paddingHorizontal: espaco.md, paddingTop: 10, gap: 10 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 6, paddingBottom: 6 },
  divisor: { width: 1, height: 18, backgroundColor: cores.border, marginHorizontal: 2 },
  corpo: { paddingHorizontal: espaco.md, paddingBottom: 96 },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    paddingVertical: 10, paddingHorizontal: 2,
    borderBottomWidth: 1, borderBottomColor: cores.border,
  },
  nome: { flex: 1, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed },
  saldo: { fontSize: 14, color: cores.ink, fontFamily: fonte.monoSemi, textAlign: 'right', minWidth: 52 },
  unidade: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansSemi, minWidth: 24 },
  vazioWrap: { alignItems: 'center', marginTop: 40, gap: 14, paddingHorizontal: 20 },
  vazio: { textAlign: 'center', marginTop: 40, fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },
});
