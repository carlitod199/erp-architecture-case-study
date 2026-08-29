import React, { useMemo, useRef, useState } from 'react';
import { View, Text, TextInput, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import CarregandoVero from '../components/CarregandoVero';
import Icone from '../components/Icone';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';

// Onda 2 — destino MAPA da barra: mapa esquemático em SVG no topo (offline,
// centroides do cache 'talhoes' normalizados; cor = situação da válvula,
// cruzando 'apontamentos_abertos' e 'alertas' por talhao_id) + a lista de
// válvulas de sempre (segue sendo o acesso principal). Tocar num pino
// seleciona e rola até o card correspondente.

const formatarArea = (v) => {
  const n = Number(v);
  if (v == null || v === '' || Number.isNaN(n)) return null;
  return `${n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ha`;
};

// situação da válvula → cores + rótulo (badge com o código + pílula de status)
const STATUS_VAL = {
  pos:    { fg: '#15803D', bg: '#DDF0E3', lbl: null },
  amber:  { fg: '#B45309', bg: '#FBEDDA', lbl: 'Serviço' },
  danger: { fg: '#B91C1C', bg: '#F7E1DF', lbl: 'Alerta' },
};

export default function TalhoesScreen() {
  const nav = useNavigation();
  const listaRef = useRef(null);
  const [busca, setBusca] = useState('');
  const [selecionadaId, setSelecionadaId] = useState(null);
  const { itens, carregado } = useDadosSync('talhoes');
  const { itens: abertos } = useDadosSync('apontamentos_abertos');
  const { itens: alertas } = useDadosSync('alertas');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { sincronizarAgora, sincronizando } = useSync();

  const visiveis = useMemo(() => {
    const q = busca.trim().toLowerCase();
    if (!q) return itens;
    return itens.filter((v) =>
      [v.nome, v.codigo, v.cultura, v.talhao_nome]
        .filter(Boolean)
        .some((campo) => String(campo).toLowerCase().includes(q))
    );
  }, [busca, itens]);

  // situação de cada válvula p/ a cor do pino (cruzamento por talhao_id):
  //   danger (alerta aberto) > amber (serviço em aberto) > pos (em dia)
  const statusPorId = useMemo(() => {
    const comServico = new Set(
      (abertos || []).filter((a) => a.talhao_id != null).map((a) => String(a.talhao_id))
    );
    const comAlerta = new Set(
      (alertas || [])
        .filter((a) => a.talhao_id != null && a.status === 'aberto')
        .map((a) => String(a.talhao_id))
    );
    const mapa = {};
    for (const v of itens) {
      const chave = String(v.talhao_id || v.id);
      mapa[String(v.id)] = comAlerta.has(chave) ? 'danger' : comServico.has(chave) ? 'amber' : 'pos';
    }
    return mapa;
  }, [itens, abertos, alertas]);

  // toque no pino: seleciona e rola até o card na lista (se a busca estiver
  // escondendo a válvula, limpa a busca antes de rolar)
  function tocarPino(v) {
    const id = String(v.id);
    setSelecionadaId(id);
    let idx = visiveis.findIndex((x) => String(x.id) === id);
    if (idx < 0) {
      setBusca('');
      idx = itens.findIndex((x) => String(x.id) === id);
    }
    if (idx >= 0) {
      setTimeout(() => {
        listaRef.current?.scrollToIndex({ index: idx, viewPosition: 0.2, animated: true });
      }, 80);
    }
  }

  const semDados = carregado && itens.length === 0;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        semVoltar
        titulo="Mapa da fazenda"
        sub={carregado ? `${itens.length} válvula${itens.length === 1 ? '' : 's'} ativa${itens.length === 1 ? '' : 's'}` : 'Carregando…'}
      />

      <View style={styles.buscaWrap}>
        <TextInput
          style={styles.busca}
          value={busca}
          onChangeText={setBusca}
          placeholder="Buscar por nome, código ou cultura…"
          placeholderTextColor={cores.muted2}
          returnKeyType="search"
        />
      </View>

      <FlatList
        ref={listaRef}
        data={visiveis}
        keyExtractor={(v) => String(v.id)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={cores.accent} colors={[cores.accent]} />}
        onScrollToIndexFailed={(info) => {
          // lista ainda sem medida: aproxima pelo tamanho médio e tenta de novo
          listaRef.current?.scrollToOffset({ offset: info.averageItemLength * info.index, animated: true });
          setTimeout(() => {
            listaRef.current?.scrollToIndex({ index: info.index, viewPosition: 0.2, animated: true });
          }, 220);
        }}
        ListHeaderComponent={null}
        ListEmptyComponent={
          semDados ? (
            <View style={styles.cardVazio}>
              <Text style={styles.vazioTitulo}>Nenhuma válvula sincronizada ainda</Text>
              <Text style={styles.vazioTxt}>
                Verifique a conexão e puxe para sincronizar.
              </Text>
              <Pressable
                style={[styles.btnSync, sincronizando && { opacity: 0.6 }]}
                onPress={sincronizarAgora}
                disabled={sincronizando}
              >
                <Text style={styles.btnSyncTxt}>
                  {sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
                </Text>
              </Pressable>
            </View>
          ) : (
            carregado ? (
            <Text style={styles.vazio}>Nenhuma válvula encontrada.</Text>
          ) : (
            <View style={{ alignItems: 'center', marginTop: 40 }}><CarregandoVero /></View>
          )
          )
        }
        renderItem={({ item: v }) => {
          const area = formatarArea(v.area_ha);
          const cultura = v.cultura || v.talhao_nome || null;
          const sel = String(v.id) === String(selecionadaId);
          return (
            <Pressable
              style={({ pressed }) => [styles.card, sel && styles.cardSel, pressed && styles.cardPress]}
              onPress={() => nav.navigate('Talhao', { valvula: v })}
            >
              <View style={{ flex: 1 }}>
                <Text style={styles.nome} numberOfLines={1}>{v.nome}</Text>
                <Text style={styles.sub} numberOfLines={1}>
                  {[v.codigo, cultura, area].filter(Boolean).join(' · ') || 'sem área vinculada'}
                </Text>
              </View>
              <Text style={styles.seta}>›</Text>
            </Pressable>
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  buscaWrap: { paddingHorizontal: espaco.md, paddingVertical: 10 },
  busca: {
    height: 46, borderRadius: raio.r, backgroundColor: cores.surface,
    paddingHorizontal: 14, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed,
  },
  corpo: { paddingHorizontal: espaco.md, paddingBottom: 96, gap: 10 },
  vazio: { textAlign: 'center', marginTop: 40, fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },
  cardVazio: {
    backgroundColor: cores.surface, borderRadius: raio.card, padding: 18,
    alignItems: 'center', gap: 6, marginTop: 20,
  },
  vazioTitulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold, textAlign: 'center' },
  vazioTxt: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, textAlign: 'center' },
  btnSync: {
    marginTop: 10, height: 42, paddingHorizontal: 20, borderRadius: raio.r,
    backgroundColor: cores.accent, alignItems: 'center', justifyContent: 'center',
  },
  btnSyncTxt: { fontSize: 13, color: cores.surface, fontFamily: fonte.sansBold },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    backgroundColor: cores.surface, borderRadius: 14, paddingHorizontal: 12, paddingVertical: 12,
    borderWidth: 1, borderColor: cores.border,
    shadowColor: cores.shadowInk, shadowOpacity: 0.05, shadowRadius: 6, shadowOffset: { width: 0, height: 2 }, elevation: 1,
  },
  cardSel: { borderColor: cores.accent, borderWidth: 1.5 },
  cardPress: { backgroundColor: cores.campo },
  vBadge: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  vBadgeTxt: { fontSize: 13.5, fontFamily: fonte.monoSemi },
  nome: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansBold },
  sub: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  stPill: { borderRadius: 20, paddingHorizontal: 9, paddingVertical: 4 },
  stPillTxt: { fontSize: 10, fontFamily: fonte.sansBold },
  seta: { fontSize: 18, color: cores.faint, fontFamily: fonte.sansBold },
});
