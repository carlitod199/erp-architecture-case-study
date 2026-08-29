import React, { useMemo, useState } from 'react';
import { View, Text, FlatList, RefreshControl, StyleSheet } from 'react-native';
import AppHeader from '../components/AppHeader';
import CarregandoVero from '../components/CarregandoVero';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { cores, fonte, raio, espaco, severidade } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { Cartao, Botao, Badge } from '../components/ui';

// Caixa do líder — o outro lado do "enviar ao líder". Lista real do cache de
// /sync/mip_recebidos: cada envio é o grupo de leituras do mesmo monitor no
// mesmo dia (data_monitoramento). Leitura crítica = nivel_infestacao >= nivel_acao.
// "Novo/Visto" é local da sessão (o servidor ainda não guarda leitura vista).

// 'YYYY-MM-DD[ HH:MM:SS]' → Date local
function parseData(valor) {
  if (!valor) return null;
  const m = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return null;
  return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0));
}

// "hoje", "ontem" ou dd/mm/aaaa
function rotuloDia(valor) {
  const d = parseData(valor);
  if (!d) return 'sem data';
  const dia = new Date(d);
  dia.setHours(0, 0, 0, 0);
  const hoje = new Date();
  hoje.setHours(0, 0, 0, 0);
  const diff = Math.round((hoje - dia) / 86400000);
  if (diff === 0) return 'hoje';
  if (diff === 1) return 'ontem';
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

// número em pt-BR (vírgula), sem zeros sobrando: 18.00 → "18", 7.5 → "7,5"
function numeroBr(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return String(v ?? '');
  return String(Math.round(n * 10) / 10).replace('.', ',');
}

function valorLeitura(m) {
  const num = numeroBr(m.nivel_infestacao);
  const un = m.unidade || '';
  return un === '%' ? `${num}%` : (un ? `${num} ${un}` : num);
}

export default function MonitoramentosRecebidosScreen() {
  const { itens, carregado, sincronizando } = useDadosSync('mip_recebidos');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { sincronizarAgora } = useSync();
  const [vistos, setVistos] = useState({});
  const [expandido, setExpandido] = useState(null);

  // agrupa leituras por monitor + dia → cada grupo é um "envio"
  const envios = useMemo(() => {
    const grupos = new Map();
    (itens || []).forEach((m) => {
      const dia = String(m.data_monitoramento || '').slice(0, 10) || 'sem-data';
      const chave = `${m.monitor_id || m.monitor_nome || 'monitor'}|${dia}`;
      if (!grupos.has(chave)) {
        grupos.set(chave, {
          id: chave,
          remetente: m.monitor_nome || 'Monitor',
          dia,
          hora: rotuloDia(m.data_monitoramento),
          talhoes: new Set(),
          leituras: [],
        });
      }
      const g = grupos.get(chave);
      if (m.talhao_nome) g.talhoes.add(m.talhao_nome);
      const nivel = Number(m.nivel_infestacao);
      const acao = m.nivel_acao === null || m.nivel_acao === undefined ? null : Number(m.nivel_acao);
      g.leituras.push({
        id: String(m.id),
        alvo: m.alvo_nome || 'Alvo não informado',
        talhao: m.talhao_nome || '',
        valor: valorLeitura(m),
        nivel: Number.isFinite(nivel) ? nivel : 0,
        acao,
        qtd: Number(m.quantidade_encontrada) || 0,
        plantas: Number(m.plantas_amostradas) || 0,
        local: m.local_infestacao || null,
        critico: acao !== null && Number.isFinite(nivel) && nivel >= acao,
        obs: m.observacao || '',
      });
    });

    return [...grupos.values()]
      .map((g) => {
        const pior = g.leituras.reduce((a, b) => (b.nivel > a.nivel ? b : a), g.leituras[0]);
        const temCritico = g.leituras.some((l) => l.critico);

        // 8.1 — CONSOLIDAÇÃO POR ÁREA (válvula×alvo): regra de 3 sobre o total
        // do dia (Σ encontradas ÷ Σ plantas × 100); sem plantas amostradas,
        // vale o pior índice das leituras.
        const porChave = new Map();
        g.leituras.forEach((l) => {
          const k = `${l.talhao}|${l.alvo}`;
          if (!porChave.has(k)) {
            porChave.set(k, { talhao: l.talhao, alvo: l.alvo, qtd: 0, plantas: 0, pontos: 0, piorNivel: 0, acao: l.acao });
          }
          const c = porChave.get(k);
          c.qtd += l.qtd;
          c.plantas += l.plantas;
          c.pontos += 1;
          if (l.nivel > c.piorNivel) c.piorNivel = l.nivel;
          if (c.acao == null && l.acao != null) c.acao = l.acao;
        });
        const consolidado = [...porChave.values()].map((c) => {
          const incidencia = c.plantas > 0 ? Math.round((c.qtd / c.plantas) * 1000) / 10 : c.piorNivel;
          return { ...c, incidencia, critico: c.acao != null && incidencia >= c.acao };
        }).sort((a, b) => b.incidencia - a.incidencia);

        return {
          ...g,
          consolidado,
          resumo: [...g.talhoes].join(' · ') || 'Sem válvula',
          sev: temCritico || consolidado.some((c) => c.critico) ? 'critico' : 'baixa',
          alvoPior: pior ? pior.alvo : '',
          valorPior: pior ? pior.valor : '',
        };
      })
      .sort((a, b) => b.dia.localeCompare(a.dia) || a.remetente.localeCompare(b.remetente));
  }, [itens]);

  const novos = envios.filter((e) => !vistos[e.id]).length;

  function abrir(id) {
    // marca como visto (local) e alterna o accordion
    setVistos((v) => ({ ...v, [id]: true }));
    setExpandido((atual) => (atual === id ? null : id));
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        titulo="Monitoramentos recebidos"
        sub={sincronizando ? 'sincronizando…' : `${novos} novos · ${envios.length} envios`}
      />

      <FlatList
        data={envios}
        keyExtractor={(e) => e.id}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor="#00464e" colors={["#00464e"]} />}
        ListEmptyComponent={
          !carregado ? (
            <View style={{ alignItems: 'center', marginTop: 40, gap: 12 }}>
              <CarregandoVero />
              <Text style={styles.vazio}>Carregando monitoramentos…</Text>
            </View>
          ) : (
            <View style={styles.vazioWrap}>
              <Text style={styles.vazioIc}>🔍</Text>
              <Text style={styles.vazio}>Nenhum monitoramento recebido ainda.</Text>
              <Text style={styles.vazioSub}>Quando um monitor enviar leituras, elas aparecem aqui.</Text>
              <Botao
                titulo={sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
                onPress={() => sincronizarAgora()}
                disabled={sincronizando}
                style={{ marginTop: 12 }}
              />
            </View>
          )
        }
        renderItem={({ item: e }) => {
          const cor = severidade[e.sev];
          const aberto = expandido === e.id;
          const novo = !vistos[e.id];
          return (
            <Cartao destaque={aberto} onPress={() => abrir(e.id)}>
              <View style={styles.linha1}>
                <View style={styles.avatar}>
                  <Text style={styles.avatarTxt}>{e.remetente[0]}</Text>
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.remetente}>{e.remetente}</Text>
                  <Text style={styles.resumo}>{e.resumo}</Text>
                </View>
                <Badge bg={novo ? cores.accent : cores.track} fg={novo ? '#fff' : cores.muted2}>
                  {novo ? 'Novo' : 'Visto'}
                </Badge>
              </View>

              <View style={styles.linha2}>
                <Text style={styles.hora}>{e.hora}</Text>
                <Text style={styles.qtd}>{e.leituras.length} {e.leituras.length === 1 ? 'leitura' : 'leituras'}</Text>
                <View style={styles.indiceWrap}>
                  <View style={[styles.pontoSev, { backgroundColor: cor }]} />
                  <Text style={[styles.indice, { color: cor }]} numberOfLines={1}>
                    {e.alvoPior} {e.valorPior}{e.sev === 'critico' ? ' · crítico' : ' · ok'}
                  </Text>
                </View>
              </View>

              {aberto && (
                <View style={styles.detalhe}>
                  {/* 8.1 — consolidado por válvula×alvo (regra de 3 do dia) */}
                  {e.consolidado.length > 0 && (
                    <View style={styles.consolidado}>
                      <Text style={styles.consolidadoTitulo}>Consolidado do dia (por área)</Text>
                      {e.consolidado.map((c, i) => (
                        <View key={i} style={styles.consolidadoLinha}>
                          <Text style={styles.consolidadoAlvo} numberOfLines={1}>
                            {[c.talhao, c.alvo].filter(Boolean).join(' · ')}
                          </Text>
                          <Text style={[styles.consolidadoValor, c.critico && { color: severidade.critico }]}>
                            {String(c.incidencia).replace('.', ',')}%
                            {c.plantas > 0 ? ` (${c.qtd}/${c.plantas} plantas · ${c.pontos} ${c.pontos === 1 ? 'ponto' : 'pontos'})` : ''}
                            {c.critico ? ' ⚠️' : ''}
                          </Text>
                        </View>
                      ))}
                    </View>
                  )}
                  {e.leituras.map((l) => (
                    <View key={l.id} style={styles.leitura}>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.leituraPonto}>{l.alvo}</Text>
                        {!!l.obs && <Text style={styles.leituraObs}>{l.obs}</Text>}
                      </View>
                      {l.critico && <Text style={styles.leituraCritico}>⚠️</Text>}
                      <Text style={[styles.leituraValor, l.critico && { color: severidade.critico }]}>
                        {l.valor}
                      </Text>
                    </View>
                  ))}
                  <Text style={styles.dica}>
                    Toque de novo para recolher · leituras enviadas por {e.remetente}
                  </Text>
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
  vazioWrap: { alignItems: 'center', marginTop: 32, gap: 6 },
  vazioIc: { fontSize: 34 },
  vazio: { textAlign: 'center', marginTop: 8, fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },
  vazioSub: { textAlign: 'center', fontSize: 11.5, color: cores.muted2, fontFamily: fonte.sansMed },
  consolidado: { backgroundColor: cores.campo, borderRadius: raio.sm, padding: 11, marginBottom: 8 },
  consolidadoTitulo: { fontSize: 10.5, letterSpacing: 0.8, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold, marginBottom: 6 },
  consolidadoLinha: { flexDirection: 'row', justifyContent: 'space-between', gap: 10, paddingVertical: 3 },
  consolidadoAlvo: { flex: 1, fontSize: 12, color: cores.ink, fontFamily: fonte.sansSemi },
  consolidadoValor: { fontSize: 12, color: cores.ink2, fontFamily: fonte.monoSemi },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  avatar: {
    width: 38, height: 38, borderRadius: 19,
    backgroundColor: cores.campo, alignItems: 'center', justifyContent: 'center',
  },
  avatarTxt: { fontSize: 15, color: cores.accent, fontFamily: fonte.sansBold },
  remetente: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  resumo: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  linha2: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 10 },
  hora: { fontSize: 11, color: cores.muted2, fontFamily: fonte.monoSemi },
  qtd: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed },
  indiceWrap: { flexDirection: 'row', alignItems: 'center', gap: 5, marginLeft: 'auto', flexShrink: 1 },
  pontoSev: { width: 8, height: 8, borderRadius: 4 },
  indice: { fontSize: 11.5, fontFamily: fonte.sansBold, flexShrink: 1 },
  detalhe: { marginTop: 12, borderTopWidth: 1, borderTopColor: cores.track, paddingTop: 4 },
  leitura: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: cores.track,
  },
  leituraPonto: { fontSize: 12, color: cores.ink2, fontFamily: fonte.sansSemi },
  leituraObs: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed, fontStyle: 'italic', marginTop: 1 },
  leituraCritico: { fontSize: 12 },
  leituraValor: { fontSize: 12.5, color: cores.ink, fontFamily: fonte.monoSemi, minWidth: 38, textAlign: 'right' },
  dica: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 9 },
});
