import React, { useMemo } from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import CarregandoVero from '../components/CarregandoVero';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { useDadosSync } from '../hooks/useDadosSync';
import { Cartao, Badge } from '../components/ui';
import Icone from '../components/Icone';

// Modelo do gestor (22/07 — INVERSÃO): apontamento NUNCA começa pelo app.
// O escritório inicia no sistema; o app lista os serviços em aberto e o
// operador CONCLUI preenchendo a execução (quantidade, produção, observação)
// na tela de conclusão. Não existe "concluir de um toque".

export const TIPO_INFO = {
  aplicacao: { ic: '💨', rotulo: 'Pulverização' },
  nutricao: { ic: '🌱', rotulo: 'Adubação' },
  tratos_culturais: { ic: '✂️', rotulo: 'Trato cultural' },
  poda: { ic: '✂️', rotulo: 'Poda' },
  colheita: { ic: '🧺', rotulo: 'Colheita' },
  abastecimento: { ic: '⛽', rotulo: 'Abastecimento' },
  outro: { ic: '📋', rotulo: 'Serviço' },
};

// 'YYYY-MM-DD HH:MM:SS' → "hoje 07:12" / "dd/mm 07:12"
function rotuloInicio(valor) {
  const m = String(valor || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return '';
  const hoje = new Date();
  const ehHoje = Number(m[1]) === hoje.getFullYear()
    && Number(m[2]) === hoje.getMonth() + 1 && Number(m[3]) === hoje.getDate();
  const dia = ehHoje ? 'hoje' : `${m[3]}/${m[2]}`;
  return m[4] ? `${dia} · ${m[4]}:${m[5]}` : dia;
}

// item cru do sync → objeto que a TarefaDetalhe consome (compartilhado c/ a Voz)
export function servicoDe(a) {
  const info = TIPO_INFO[a.tipo] || TIPO_INFO.outro;
  return {
    id: String(a.id),
    ic: info.ic,
    titulo: info.rotulo,
    local: a.talhao_nome || 'sem válvula',
    inicio: rotuloInicio(a.iniciado_em || a.data_apontamento),
    responsavel: a.responsavel || null,
    observacao: a.observacao || '',
    tipo: a.tipo,
    talhao_id: a.talhao_id,
  };
}

// tipo do serviço → nome no set de Icone (só apresentação; NÃO altera TIPO_INFO)
const ICONE_TIPO = {
  aplicacao: 'aplicacao',
  nutricao: 'adubacao',
  tratos_culturais: 'poda',
  poda: 'poda',
  colheita: 'colheita',
  abastecimento: 'abastecimento',
  outro: 'tarefas',
};

export default function TarefasScreen() {
  const nav = useNavigation();
  const { itens, carregado, sincronizando } = useDadosSync('apontamentos_abertos');
  // colheitas lançadas no escritório aguardando a PRODUÇÃO do campo (23/07)
  const { itens: colheitasPend } = useDadosSync('colheitas_pendentes');
  const { itens: valvulasCache } = useDadosSync('talhoes');
  const { refrescando, aoRefrescar } = useRefrescar();

  const servicos = useMemo(() => (
    (itens || [])
      .map(servicoDe)
      .sort((a, b) => b.id.localeCompare(a.id, undefined, { numeric: true }))
  ), [itens]);

  // 'YYYY-MM-DD…' → 'dd/mm'
  const dataCurta = (v) => {
    const m = String(v || '').match(/^\d{4}-(\d{2})-(\d{2})/);
    return m ? `${m[2]}/${m[1]}` : '';
  };

  // preencher produção: abre o formulário de colheita amarrado ao registro
  function preencherColheita(c) {
    const valvula = (valvulasCache || []).find((v) => String(v.id) === String(c.setor_id)) || null;
    nav.navigate('RegistrarTab', {
      screen: 'Apontamento',
      params: { soTipo: 'colheita', registroId: c.id, valvula: valvula || undefined },
    });
  }

  const totalItens = servicos.length + (colheitasPend || []).length;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        semVoltar
        titulo="Serviços em aberto"
        sub={sincronizando ? 'sincronizando…' : `${totalItens} ${totalItens === 1 ? 'pendente' : 'pendentes'}`}
        direita={
          /* exceção 23/07: só a COLHEITA pode iniciar pelo app */
          <Pressable
            style={styles.btnNovaColheita}
            onPress={() => nav.navigate('RegistrarTab', { screen: 'Apontamento', params: { soTipo: 'colheita' } })}
            accessibilityLabel="Iniciar colheita"
          >
            <Icone nome="colheita" tam={18} cor={cores.surface} />
            <Text style={styles.btnNovaColheitaTxt}>＋</Text>
          </Pressable>
        }
      />

      <FlatList
        data={servicos}
        keyExtractor={(s) => s.id}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={cores.accent} colors={[cores.accent]} />}
        ListHeaderComponent={
          (colheitasPend || []).length > 0 ? (
            <View style={{ gap: 10, marginBottom: 4 }}>
              {(colheitasPend || []).map((c) => (
                <Cartao key={`col-${c.id}`} alerta={cores.amber} onPress={() => preencherColheita(c)}>
                  <View style={styles.linha1}>
                    <Icone nome="colheita" tam={20} cor={cores.muted} />
                    <View style={{ flex: 1 }}>
                      <Text style={styles.titulo}>
                        Colheita — {c.variedade || c.talhao_nome || 'variedade'}
                      </Text>
                      <Text style={styles.meta}>
                        {c.setor_nome || c.talhao_nome}
                        {c.data_colheita ? ` · prevista ${dataCurta(c.data_colheita)}` : ''}
                      </Text>
                    </View>
                  </View>
                  <View style={styles.rodapeCard}>
                    <Text style={styles.abrir}>Preencher produção ›</Text>
                  </View>
                </Cartao>
              ))}
            </View>
          ) : null
        }
        ListEmptyComponent={
          !carregado ? (
            <View style={{ alignItems: 'center', marginTop: 40, gap: 12 }}>
              <CarregandoVero />
              <Text style={styles.vazio}>Carregando…</Text>
            </View>
          ) : (colheitasPend || []).length > 0 ? null : (
            <View style={styles.vazioWrap}>
              <Text style={styles.vazioIc}>🌤️</Text>
              <Text style={styles.vazio}>Nenhum serviço em aberto.</Text>
              <Text style={styles.vazioSub}>
                Os serviços são iniciados pelo escritório no VERO — quando abrirem, aparecem aqui para você executar e concluir.
              </Text>
            </View>
          )
        }
        renderItem={({ item: s }) => (
          <Cartao onPress={() => nav.navigate('TarefaDetalhe', { servico: s })}>
            <View style={styles.linha1}>
              <Icone nome={ICONE_TIPO[s.tipo] || 'tarefas'} tam={20} cor={cores.muted} />
              <View style={{ flex: 1 }}>
                <Text style={styles.titulo}>{s.titulo} — {s.local}</Text>
                <Text style={styles.meta}>
                  ▶ iniciado {s.inicio}{s.responsavel ? ` · ${s.responsavel}` : ''}
                </Text>
              </View>
            </View>

            {!!s.observacao && <Text style={styles.obs} numberOfLines={2}>{s.observacao}</Text>}

            <View style={styles.rodapeCard}>
              <Text style={styles.abrir}>Preencher execução e concluir ›</Text>
            </View>
          </Cartao>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  vazioWrap: { alignItems: 'center', marginTop: 32, gap: 6, paddingHorizontal: 24 },
  vazioIc: { fontSize: 34 },
  vazio: { textAlign: 'center', fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },
  vazioSub: { textAlign: 'center', fontSize: 11.5, color: cores.muted2, fontFamily: fonte.sansMed, lineHeight: 17 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  titulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  meta: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  obs: { fontSize: 11.5, color: cores.ink2, fontFamily: fonte.sansMed, marginTop: 9, lineHeight: 16 },
  rodapeCard: { marginTop: 10, alignItems: 'flex-end' },
  abrir: { fontSize: 12.5, color: cores.accent, fontFamily: fonte.sansBold },
  btnNovaColheita: {
    minHeight: 36, paddingHorizontal: 10, borderRadius: raio.sm, flexDirection: 'row', gap: 5,
    backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center',
  },
  btnNovaColheitaTxt: { fontSize: 13, color: cores.surface, fontFamily: fonte.sansBold },
});
