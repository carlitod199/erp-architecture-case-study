import React, { useMemo } from 'react';
import { View, Text, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { useDadosSync } from '../hooks/useDadosSync';
import { useAuth } from '../context/AuthContext';
import { papelApp, Cartao, Badge } from '../components/ui';

// J4 / P-APP-2 — fila REAL de DFs (cache /sync/aplicacoes): emitidos no web,
// aguardando execução no campo. Tocar na pendente abre a confirmação, que
// enfileira POST /aplicacoes/{id}/confirmar e depois a assinatura.

const STATUS = {
  pendente: { rotulo: 'Aguardando execução', bg: cores.amberBg, fg: cores.amberDeep },
  registrada: { rotulo: 'Confirmada ✓', bg: cores.posBg, fg: '#0a5c53' },
  aguardaRt: { rotulo: 'Assinar (RT)', bg: cores.amberBg, fg: cores.amberDeep },
};

const TIPO_ROTULO = {
  defensivo: 'Pulverização', fertilizante: 'Fertirrigação/adubação',
  nutricao: 'Nutrição', outro: 'Aplicação',
};

// 'YYYY-MM-DD…' → 'dd/mm'
const dataCurta = (valor) => {
  const m = String(valor || '').match(/^\d{4}-(\d{2})-(\d{2})/);
  return m ? `${m[2]}/${m[1]}` : '';
};

function resumoProdutos(itens) {
  return (itens || [])
    .map((i) => [i.produto, i.dose_valor ? `${String(i.dose_valor).replace('.', ',')} ${i.dose_unidade || ''}`.trim() : null]
      .filter(Boolean).join(' · '))
    .join(' + ');
}

function maiorCarencia(itens) {
  const dias = (itens || []).map((i) => Number(i.carencia_dias) || 0);
  const max = Math.max(0, ...dias);
  return max > 0 ? max : null;
}

export default function AplicacoesScreen() {
  const nav = useNavigation();
  const { itens, carregado, sincronizando } = useDadosSync('aplicacoes');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { usuario } = useAuth();
  // caixa do RT: só o supervisor (encarregado/gestor) assina o receituário
  const ehSupervisor = papelApp(usuario) === 'encarregado';

  const dfs = useMemo(() => (
    (itens || [])
      .map((a) => ({
        ...a,
        pendente: a.status === 'planejada' || a.status === 'rascunho',
        // confirmada pelo operador, mas ainda sem a assinatura do RT
        aguardandoRt: a.status === 'registrada'
          && Number(a.assinado_operador) === 1 && Number(a.assinado_rt) !== 1,
        produtos: resumoProdutos(a.itens),
        carencia: maiorCarencia(a.itens),
        quando: dataCurta(a.data_prevista || a.data),
      }))
      // pendentes de execução primeiro; depois as que aguardam o RT; resto por data
      .sort((a, b) => {
        const rank = (x) => (x.pendente ? 0 : x.aguardandoRt ? 1 : 2);
        return rank(a) - rank(b) || String(b.updated_at || '').localeCompare(String(a.updated_at || ''));
      })
  ), [itens]);

  const pendentes = dfs.filter((a) => a.pendente).length;
  const aguardandoRt = ehSupervisor ? dfs.filter((a) => a.aguardandoRt).length : 0;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        titulo="Aplicações"
        sub={sincronizando ? 'sincronizando…'
          : `${pendentes} aguardando execução${aguardandoRt > 0 ? ` · ${aguardandoRt} p/ assinar (RT)` : ''}`}
      />

      <FlatList
        data={dfs}
        keyExtractor={(a) => String(a.id)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={cores.accent} colors={[cores.accent]} />}
        ListHeaderComponent={
          dfs.length > 0 ? (
            <View style={{ gap: 10, marginBottom: 4 }}>
              <Text style={styles.nota}>
                Aplicações emitidas pelo escritório. Confirme a execução (é aí que baixa o estoque) e colha as assinaturas.
              </Text>
            </View>
          ) : null
        }
        ListEmptyComponent={
          carregado ? (
            <View style={styles.vazioWrap}>
              <Text style={styles.vazioIc}>🌤️</Text>
              <Text style={styles.vazio}>Nenhuma aplicação por aqui.</Text>
              <Text style={styles.vazioSub}>
                Quando o escritório emitir um DF, ele aparece nesta fila após a sincronização.
              </Text>
            </View>
          ) : null
        }
        renderItem={({ item: a }) => {
          const rtParaMim = ehSupervisor && a.aguardandoRt;
          const acionavel = a.pendente || rtParaMim;
          const st = a.pendente ? STATUS.pendente : rtParaMim ? STATUS.aguardaRt : STATUS.registrada;
          return (
            <Cartao
              alerta={acionavel ? cores.amber : undefined}
              style={!acionavel ? styles.cardAssinada : undefined}
              onPress={acionavel ? () => {
                if (a.pendente) nav.navigate('AplicacaoConfirmar', { df: a });
                else if (rtParaMim) nav.navigate('AplicacaoAssinatura', { df: a, papel: 'rt' });
              } : undefined}
            >
              <View style={styles.linha1}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.df}>DF #{a.id} · {TIPO_ROTULO[a.tipo] || a.tipo || 'Aplicação'}</Text>
                  <Text style={styles.local}>
                    {[a.talhao_nome, a.area_aplicada_ha ? `${String(a.area_aplicada_ha).replace('.', ',')} ha` : null]
                      .filter(Boolean).join(' · ') || 'sem válvula vinculada'}
                  </Text>
                </View>
                <Badge bg={st.bg} fg={st.fg}>{st.rotulo}</Badge>
              </View>

              {!!a.produtos && <Text style={styles.produtos}>🧪 {a.produtos}</Text>}

              <View style={styles.linha2}>
                {!!a.quando && (
                  <View style={styles.quandoLinha}>
                    <Icone nome="data" tam={13} cor={cores.ink2} />
                    <Text style={styles.quando}>{a.quando}</Text>
                  </View>
                )}
                {a.pendente && <Text style={styles.abrir}>Confirmar execução ›</Text>}
                {rtParaMim && <Text style={styles.abrir}>Assinar como RT ›</Text>}
              </View>

              {!!a.carencia && (
                <View style={styles.carencia}>
                  <Text style={styles.carenciaTxt}>⏳ carência {a.carencia} dias após a aplicação</Text>
                </View>
              )}
            </Cartao>
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  nota: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 17 },
  vazioWrap: { alignItems: 'center', marginTop: 32, gap: 6, paddingHorizontal: 24 },
  vazioIc: { fontSize: 34 },
  vazio: { textAlign: 'center', fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },
  vazioSub: { textAlign: 'center', fontSize: 11.5, color: cores.muted2, fontFamily: fonte.sansMed, lineHeight: 17 },
  cardAssinada: { opacity: 0.72 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  df: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.monoSemi },
  local: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  produtos: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 8 },
  linha2: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 9 },
  quandoLinha: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  quando: { fontSize: 11.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  abrir: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },
  carencia: { backgroundColor: cores.amberBg, borderRadius: raio.sm, paddingHorizontal: 10, paddingVertical: 7, marginTop: 10 },
  carenciaTxt: { fontSize: 11.5, color: cores.amberDeep, fontFamily: fonte.sansSemi },
});
