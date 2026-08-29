import React, { useEffect, useState } from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation, useIsFocused } from '@react-navigation/native';
import { lerRondaHoje } from '../offline/db';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { useDadosSync } from '../hooks/useDadosSync';
import { useParametro } from '../hooks/useParametro';
import { useSafraAtiva, rotuloSafra } from '../hooks/useSafraAtiva';
import { useAuth } from '../context/AuthContext';
import { Cartao, Botao, Badge } from '../components/ui';

// J1 — ronda MIP: lista de válvulas reais (cache offline, módulo 'talhoes')
// com progresso de pontos. Monitorar abre a coleta com a válvula no contexto;
// no fim, o resumo vai ao líder pela tela EnviarLider.

// Meta de pontos por válvula: tenant_parametros 'mip.meta_pontos_valvula'
// (módulo 'parametros' do sync); fallback 5, igual ao combinado com o RT.
const META_PONTOS = 5;

function statusDa(pontos, meta) {
  if (pontos >= meta) return { rotulo: 'Completa', bg: cores.posBg, fg: '#0a5c53' };
  if (pontos > 0) return { rotulo: 'Parcial', bg: cores.amberBg, fg: cores.amberDeep };
  return { rotulo: 'Não iniciada', bg: cores.track, fg: cores.muted2 };
}

// Linha de detalhe da válvula — contexto de vinhedo do sync enriquecido
function detalheDa(v) {
  const partes = [];
  if (v.variedade) partes.push(v.variedade);
  else if (v.cultura) partes.push(v.cultura);
  else if (v.talhao_nome) partes.push(v.talhao_nome);
  if (v.area_ha) partes.push(`${String(v.area_ha).replace('.', ',')} ha`);
  if (v.num_plantas) partes.push(`${v.num_plantas} plantas`);
  return partes.join(' · ');
}

export default function RondaScreen() {
  const nav = useNavigation();
  const { usuario } = useAuth();
  const focada = useIsFocused();
  const safraLabel = rotuloSafra(useSafraAtiva());
  // válvulas/setores reais do cache offline
  const { itens: valvulas, carregado } = useDadosSync('talhoes');
  const { refrescando, aoRefrescar } = useRefrescar();

  const metaPontos = useParametro('mip.meta_pontos_valvula', META_PONTOS);

  // 7.3: progresso do dia persistido em SQLite — sobrevive a fechar o app;
  // relê ao focar (a coleta acontece na tela Monitoramento).
  const [pontosPor, setPontosPor] = useState({});
  useEffect(() => {
    lerRondaHoje().then(setPontosPor).catch(() => {});
  }, [focada]);

  const totalPontos = valvulas.reduce((s, v) => s + (pontosPor[v.id] || 0), 0);
  const completas = valvulas.filter((v) => (pontosPor[v.id] || 0) >= metaPontos).length;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        titulo="Ronda de monitoramento"
        sub={[safraLabel, usuario?.nome].filter(Boolean).join(' · ') || 'Monitoramento MIP'}
      />

      {/* contexto da ronda */}
      <View style={styles.contexto}>
        <Text style={styles.contextoTitulo}>Ronda de hoje · {valvulas.length} {valvulas.length === 1 ? 'válvula' : 'válvulas'}</Text>
        <Text style={styles.contextoMeta}>Meta: {metaPontos} pontos por válvula · {totalPontos} pontos coletados hoje</Text>
      </View>

      <FlatList
        data={valvulas}
        keyExtractor={(v) => String(v.id)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor="#00464e" colors={["#00464e"]} />}
        ListEmptyComponent={
          carregado ? (
            /* cache vazio: orienta a sincronizar em vez de quebrar */
            <Cartao>
              <Text style={styles.nome}>Nenhuma válvula no aparelho</Text>
              <Text style={[styles.cultura, { marginTop: 6 }]}>
                Conecte-se à internet e sincronize para carregar as válvulas da fazenda.
              </Text>
            </Cartao>
          ) : null
        }
        renderItem={({ item: v }) => {
          const pontos = pontosPor[v.id] || 0;
          const st = statusDa(pontos, metaPontos);
          const completa = pontos >= metaPontos;
          const detalhe = detalheDa(v);
          return (
            <Cartao>
              <View style={styles.linha1}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.nome}>{v.nome}</Text>
                  {!!detalhe && <Text style={styles.cultura}>{detalhe}</Text>}
                </View>
                <Badge bg={st.bg} fg={st.fg}>{st.rotulo}</Badge>
              </View>

              {/* barra de progresso dos pontos */}
              <View style={styles.progLinha}>
                <View style={styles.trilho}>
                  <View style={[styles.preenchido, { width: `${(pontos / metaPontos) * 100}%` }, completa && { backgroundColor: cores.pos }]} />
                </View>
                <Text style={styles.progTxt}>{pontos}/{metaPontos} pontos</Text>
              </View>

              {!completa && (
                <Botao
                  titulo={pontos > 0 ? 'Continuar monitorando' : 'Monitorar'}
                  onPress={() => nav.navigate('Monitoramento', { valvula: v })}
                  style={{ marginTop: 12 }}
                />
              )}
            </Cartao>
          );
        }}
        ListFooterComponent={
          valvulas.length > 0 ? (
            <View style={styles.resumo}>
              <Text style={styles.resumoTitulo}>Resumo da ronda</Text>
              <Text style={styles.resumoTxt}>
                {completas} de {valvulas.length} válvulas completas · {totalPontos} pontos coletados
                {'\n'}Ao enviar, leituras acima do nível viram alerta para o líder.
              </Text>
              <Pressable style={styles.btnEnviar} onPress={() => nav.navigate('EnviarLider')}>
                <Text style={styles.btnEnviarTxt}>Enviar ao líder</Text>
              </Pressable>
            </View>
          ) : null
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  contexto: { paddingHorizontal: espaco.md, paddingVertical: 12 },
  contextoTitulo: { fontSize: 15, color: cores.ink, fontFamily: fonte.sansBold },
  contextoMeta: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  corpo: { paddingHorizontal: espaco.md, paddingBottom: 96, gap: 10 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  nome: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansBold },
  cultura: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  progLinha: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 12 },
  trilho: { flex: 1, height: 8, borderRadius: 4, backgroundColor: cores.track, overflow: 'hidden' },
  preenchido: { height: 8, borderRadius: 4, backgroundColor: cores.amber },
  progTxt: { fontSize: 11.5, color: cores.ink2, fontFamily: fonte.monoSemi },
  resumo: { backgroundColor: cores.sidebar, borderRadius: raio.card, padding: 16, marginTop: 6 },
  resumoTitulo: { fontSize: 14, color: '#fff', fontFamily: fonte.sansBold },
  resumoTxt: { fontSize: 12, color: 'rgba(255,255,255,0.72)', fontFamily: fonte.sansMed, marginTop: 6, lineHeight: 18 },
  btnEnviar: {
    marginTop: 14, height: 48, borderRadius: raio.r, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center',
  },
  btnEnviarTxt: { fontSize: 14, color: cores.limeInk, fontFamily: fonte.sansBold },
});
