import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import CarregandoVero from '../components/CarregandoVero';
import { useRoute, useNavigation } from '@react-navigation/native';
import { Tela } from '../components/ui';
import AppHeader from '../components/AppHeader';
import MapaArea from '../components/mapa/MapaArea';
import { cores, fonte, raio, espaco } from '../theme';
import { buscarClima, wmo, FAZENDA } from '../services/clima';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';

// Onda 2 — FICHA 360° da válvula, 100% do cache offline:
//   (a) cabeçalho do vinhedo (variedade/porta-enxerto/estrutura/área/plantas) → 'talhoes'
//   (b) fase fenológica POR VARIEDADE + dias desde a poda                     → 'fenologia'
//   (c) serviços em aberto na válvula (Concluir → TarefasTab/TarefaDetalhe)   → 'apontamentos_abertos'
//   (d) últimas aplicações/DFs (status + carência)                            → 'aplicacoes'
//   (e) ações com a válvula pré-selecionada → RegistrarTab (navegação aninhada)
// Mantém monitoramento/alerta ('mip_recebidos' + 'alertas'), últimas
// atividades ('atividades') e clima. Bloco sem fonte não aparece.

const ICONE_TIPO = {
  aplicacao: '💨', nutricao: '🌱', irrigacao: '💧',
  tratos_culturais: '✂️', colheita: '🧺', outro: '📋',
};

// serviços em aberto: mesmos rótulos/forma da aba Tarefas — o objeto montado
// aqui é o MESMO 'servico' que TarefaDetalheScreen espera em route.params
const TIPO_SERVICO = {
  aplicacao: { ic: '💨', rotulo: 'Pulverização' },
  nutricao: { ic: '🌱', rotulo: 'Adubação' },
  tratos_culturais: { ic: '✂️', rotulo: 'Trato cultural' },
  colheita: { ic: '🧺', rotulo: 'Colheita' },
  abastecimento: { ic: '⛽', rotulo: 'Abastecimento' },
  outro: { ic: '📋', rotulo: 'Serviço' },
};

const TIPO_APLICACAO = {
  defensivo: 'Pulverização', fertilizante: 'Fertirrigação/adubação',
  nutricao: 'Nutrição', outro: 'Aplicação',
};

// 'YYYY-MM-DD HH:MM:SS' → "hoje 07:12" / "dd/mm 07:12" (padrão da aba Tarefas)
function rotuloInicio(valor) {
  const m = String(valor || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return '';
  const hoje = new Date();
  const ehHoje = Number(m[1]) === hoje.getFullYear()
    && Number(m[2]) === hoje.getMonth() + 1 && Number(m[3]) === hoje.getDate();
  const dia = ehHoje ? 'hoje' : `${m[3]}/${m[2]}`;
  return m[4] ? `${dia} · ${m[4]}:${m[5]}` : dia;
}

// maior carência (dias) entre os produtos do DF — mesma regra da fila de DFs
function maiorCarencia(itensDf) {
  const dias = (itensDf || []).map((i) => Number(i.carencia_dias) || 0);
  const max = Math.max(0, ...dias);
  return max > 0 ? max : null;
}

const coordOu = (v, padrao) => {
  const n = Number(v);
  return v != null && v !== '' && !Number.isNaN(n) && n !== 0 ? n : padrao;
};

const formatarArea = (v) => {
  const n = Number(v);
  if (v == null || v === '' || Number.isNaN(n)) return null;
  return `${n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ha`;
};

// 'YYYY-MM-DD…' → 'dd/mm'
const dataCurta = (valor) => {
  const m = String(valor || '').match(/^\d{4}-(\d{2})-(\d{2})/);
  return m ? `${m[2]}/${m[1]}` : '';
};

export default function TalhaoScreen() {
  const route = useRoute();
  const nav = useNavigation();
  const { itens } = useDadosSync('talhoes');
  const { itens: fenologias } = useDadosSync('fenologia');
  const { itens: recebidos } = useDadosSync('mip_recebidos');
  const { itens: alertas } = useDadosSync('alertas');
  const { itens: atividades } = useDadosSync('atividades');
  const { itens: abertos } = useDadosSync('apontamentos_abertos');
  const { itens: aplicacoes } = useDadosSync('aplicacoes');
  const { online } = useSync();
  // rota nova traz a válvula nos params; casa com o cache p/ ter geometria fresca
  const paramValv = route.params?.valvula || null;
  const valvula = useMemo(() => (
    (itens || []).find((x) => String(x.id) === String(paramValv?.id)) || paramValv || (itens || [])[0] || null
  ), [itens, paramValv]);
  const talhaoId = valvula ? (valvula.talhao_id || valvula.id) : null;

  const [clima, setClima] = useState(null);
  const [climaErro, setClimaErro] = useState(false);

  const lat = coordOu(valvula?.centroide_lat, FAZENDA.latitude);
  const lng = coordOu(valvula?.centroide_lng, FAZENDA.longitude);

  useEffect(() => {
    let ativo = true;
    setClima(null);
    setClimaErro(false);
    (async () => {
      try {
        const d = await buscarClima({ latitude: lat, longitude: lng });
        if (ativo) setClima(d);
      } catch (_) {
        if (ativo) setClimaErro(true);
      }
    })();
    return () => { ativo = false; };
  }, [lat, lng]);

  // fase fenológica real da válvula (módulo 'fenologia', chave = talhao_id)
  const feno = useMemo(
    () => (fenologias || []).find((f) => String(f.talhao_id) === String(talhaoId)) || null,
    [fenologias, talhaoId]
  );
  const fenoProgresso = useMemo(() => {
    if (!feno?.fase_nome || feno.dia_inicio == null || feno.dia_fim == null) return null;
    const dur = Number(feno.dia_fim) - Number(feno.dia_inicio);
    if (dur <= 0) return null;
    const p = (Number(feno.dias_desde_poda) - Number(feno.dia_inicio)) / dur;
    return Math.min(1, Math.max(0, p));
  }, [feno]);

  // última leitura MIP e alerta aberto desta válvula (dados que já chegam)
  const ultimaLeitura = useMemo(() => {
    const ls = (recebidos || [])
      .filter((l) => String(l.talhao_id) === String(talhaoId))
      .sort((a, b) => String(b.data_monitoramento || '').localeCompare(String(a.data_monitoramento || '')));
    return ls[0] || null;
  }, [recebidos, talhaoId]);
  const alertaAberto = useMemo(
    () => (alertas || []).find((a) => String(a.talhao_id) === String(talhaoId) && a.status === 'aberto') || null,
    [alertas, talhaoId]
  );
  const temServicoAberto = useMemo(
    () => (abertos || []).some((a) => String(a.talhao_id) === String(talhaoId)),
    [abertos, talhaoId]
  );

  // últimas atividades reais da válvula
  const ultimasAtvs = useMemo(() => (
    (atividades || [])
      .filter((a) => String(a.talhao_id) === String(talhaoId))
      .sort((a, b) => String(b.data_planejada || '').localeCompare(String(a.data_planejada || '')))
      .slice(0, 3)
  ), [atividades, talhaoId]);

  // (c) serviços em aberto NESTA válvula — no formato que TarefaDetalhe espera
  const servicosAbertos = useMemo(() => (
    (abertos || [])
      .filter((a) => String(a.talhao_id) === String(talhaoId))
      .map((a) => {
        const info = TIPO_SERVICO[a.tipo] || TIPO_SERVICO.outro;
        return {
          id: String(a.id),
          ic: info.ic,
          titulo: info.rotulo,
          local: a.talhao_nome || 'sem válvula',
          inicio: rotuloInicio(a.iniciado_em || a.data_apontamento),
          responsavel: a.responsavel || null,
          observacao: a.observacao || '',
          tipo: a.tipo,
        };
      })
      .sort((a, b) => b.id.localeCompare(a.id, undefined, { numeric: true }))
  ), [abertos, talhaoId]);

  // (d) últimas aplicações/DFs da válvula (status + maior carência)
  const ultimasAplic = useMemo(() => (
    (aplicacoes || [])
      .filter((a) => String(a.talhao_id) === String(talhaoId))
      .map((a) => ({
        ...a,
        pendente: a.status === 'planejada' || a.status === 'rascunho',
        carencia: maiorCarencia(a.itens),
        quando: dataCurta(a.data_prevista || a.data),
      }))
      .sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
      .slice(0, 3)
  ), [aplicacoes, talhaoId]);

  const titulo = valvula?.nome || 'Válvula';
  const sub = valvula
    ? [valvula.variedade || valvula.cultura || valvula.talhao_nome, formatarArea(valvula.area_ha)]
        .filter(Boolean)
        .join(' · ')
    : 'sincronize para carregar a válvula';

  // (a) linha de contexto de viticultura (migs 155/161 via sync 'talhoes')
  const contexto = valvula
    ? [
        valvula.variedade || null,
        valvula.porta_enxerto ? `porta-enxerto ${valvula.porta_enxerto}` : null,
        valvula.estrutura_sistema || null,
        formatarArea(valvula.area_ha),
        valvula.num_plantas ? `${valvula.num_plantas} plantas` : null,
      ].filter(Boolean).join(' · ')
    : '';

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo={titulo} sub={sub} />
      <Tela>
        {/* Mapa da ÁREA da válvula — satélite Esri com o polígono (sem geometria
            ou offline: o componente se oculta/cai no esquemático) */}
        <MapaArea
          valvulas={valvula ? [valvula] : []}
          statusPorId={{ [String(valvula?.id)]: alertaAberto ? 'danger' : (temServicoAberto ? 'amber' : 'pos') }}
          selecionadaId={valvula?.id}
          online={online}
        />

        {/* Contexto de viticultura */}
        {!!contexto && (
          <View style={styles.card}>
            <Text style={styles.eyebrow}>Vinhedo</Text>
            <Text style={styles.contexto}>{contexto}</Text>
            {!!feno?.safra && <Text style={styles.contextoSub}>Safra {feno.safra} · poda em {dataCurta(feno.data_poda)}</Text>}
          </View>
        )}

        {/* Fase fenológica real (por variedade, dia0 = poda) */}
        {feno?.fase_nome ? (
          <View style={styles.card}>
            <Text style={styles.eyebrow}>Fase fenológica · {feno.variedade || 'variedade'}</Text>
            <View style={styles.faseLinha}>
              <Text style={styles.faseNome}>🌱 {feno.fase_nome}</Text>
              <Text style={styles.faseDia}>dia {feno.dias_desde_poda} desde a poda</Text>
            </View>
            {fenoProgresso !== null && (
              <View style={styles.trilho}>
                <View style={[styles.trilhoFill, { width: `${Math.round(fenoProgresso * 100)}%` }]} />
              </View>
            )}
            {feno.volume_mm_dia != null && (
              <Text style={styles.faseNota}>💧 lâmina de referência da fase: {String(feno.volume_mm_dia).replace('.', ',')} mm/dia</Text>
            )}
          </View>
        ) : null}

        {/* KPIs reais (só o que tem fonte no sync) */}
        <View style={styles.grade}>
          <View style={[styles.card, styles.kpi]}>
            <Text style={styles.eyebrow}>Monitoramento</Text>
            <Text style={[styles.kpiValor, { color: cores.ink }]}>
              {ultimaLeitura ? dataCurta(ultimaLeitura.data_monitoramento) : '—'}
            </Text>
            <Text style={styles.kpiDetalhe}>
              {ultimaLeitura
                ? `${ultimaLeitura.alvo_nome || 'alvo'} · nível ${ultimaLeitura.nivel_infestacao}`
                : 'sem leitura recebida'}
            </Text>
          </View>
          <View style={[styles.card, styles.kpi]}>
            <Text style={styles.eyebrow}>Alerta ativo</Text>
            <Text style={[styles.kpiValor, { color: alertaAberto ? cores.amberDeep : cores.ink }]}>
              {alertaAberto ? '⚠️' : '—'}
            </Text>
            <Text style={styles.kpiDetalhe} numberOfLines={2}>
              {alertaAberto ? alertaAberto.titulo : 'nenhum alerta aberto'}
            </Text>
          </View>
        </View>

        {/* (c) Serviços em aberto nesta válvula — Concluir abre o detalhe na aba Tarefas */}
        {servicosAbertos.length > 0 && (
          <View style={styles.card}>
            <Text style={styles.eyebrow}>Serviços em aberto nesta válvula</Text>
            {servicosAbertos.map((s, i) => (
              <View key={s.id} style={[styles.srv, i > 0 && styles.atvSep]}>
                <Text style={styles.atvIc}>{s.ic}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={styles.atvTitulo}>{s.titulo}</Text>
                  <Text style={styles.atvQuando}>
                    ▶ iniciado {s.inicio}{s.responsavel ? ` · ${s.responsavel}` : ''}
                  </Text>
                </View>
                <Pressable
                  style={({ pressed }) => [styles.btnConcluir, pressed && { opacity: 0.85 }]}
                  onPress={() => nav.navigate('TarefasTab', { screen: 'TarefaDetalhe', params: { servico: s } })}
                  accessibilityLabel={`Concluir ${s.titulo}`}
                >
                  <Text style={styles.btnConcluirTxt}>Concluir</Text>
                </Pressable>
              </View>
            ))}
          </View>
        )}

        {/* (d) Últimas aplicações/DFs da válvula — status + carência */}
        {ultimasAplic.length > 0 && (
          <View style={styles.card}>
            <Text style={styles.eyebrow}>Últimas aplicações</Text>
            {ultimasAplic.map((a, i) => (
              <View key={a.id} style={[styles.srv, i > 0 && styles.atvSep]}>
                <Text style={styles.atvIc}>🧪</Text>
                <View style={{ flex: 1 }}>
                  <Text style={styles.atvTitulo}>
                    DF #{a.id} · {TIPO_APLICACAO[a.tipo] || a.tipo || 'Aplicação'}
                  </Text>
                  <Text style={styles.atvQuando}>
                    {[a.quando, a.carencia ? `carência ${a.carencia} dia${a.carencia === 1 ? '' : 's'}` : null]
                      .filter(Boolean).join(' · ') || '—'}
                  </Text>
                </View>
                <View style={[styles.stBadge, { backgroundColor: a.pendente ? cores.amberBg : cores.posBg }]}>
                  <Text style={[styles.stBadgeTxt, { color: a.pendente ? cores.amberDeep : cores.pos }]}>
                    {a.pendente ? 'Aguardando' : 'Confirmada ✓'}
                  </Text>
                </View>
              </View>
            ))}
          </View>
        )}

        {/* Últimas atividades reais */}
        {ultimasAtvs.length > 0 && (
          <View style={styles.card}>
            <Text style={styles.eyebrow}>Últimas atividades</Text>
            {ultimasAtvs.map((a, i) => (
              <View key={a.id} style={[styles.atv, i > 0 && styles.atvSep]}>
                <Text style={styles.atvIc}>{ICONE_TIPO[a.tipo] || ICONE_TIPO.outro}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={styles.atvTitulo}>{a.descricao || 'Atividade'}</Text>
                  <Text style={[styles.atvQuando, a.status === 'em_execucao' && { color: '#0a5c53' }]}>
                    {[dataCurta(a.data_planejada), a.status === 'em_execucao' ? 'em andamento' : a.status]
                      .filter(Boolean).join(' · ')}
                  </Text>
                </View>
              </View>
            ))}
          </View>
        )}

        {/* (e) Ações com a válvula pré-selecionada — navegação aninhada.
            Inversão 22/07: apontamento NÃO nasce no app (o escritório inicia;
            o app conclui pela aba Tarefas) — ação de apontar saiu daqui. */}
        {!!valvula && (
          <View style={styles.card}>
            <Text style={styles.eyebrow}>Ações nesta válvula</Text>
            <Pressable
              style={({ pressed }) => [styles.acao, styles.acaoSec, pressed && { opacity: 0.88 }]}
              onPress={() => nav.navigate('RegistrarTab', { screen: 'Monitoramento', params: { valvula } })}
              accessibilityLabel="Monitorar esta válvula"
            >
              <Text style={[styles.acaoTxt, { color: cores.accent }]}>🎯 Monitorar</Text>
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.acao, styles.acaoSec, pressed && { opacity: 0.88 }]}
              onPress={() => nav.navigate('RegistrarTab', { screen: 'Irrigacao', params: { valvula } })}
              accessibilityLabel="Registrar irrigação"
            >
              <Text style={[styles.acaoTxt, { color: cores.accent }]}>💧 Irrigar</Text>
            </Pressable>
          </View>
        )}

        {/* Clima local (opcional, com fallback discreto) */}
        <View style={styles.card}>
          <Text style={styles.eyebrow}>Clima na válvula</Text>
          {climaErro && <Text style={styles.climaErro}>Clima indisponível no momento.</Text>}
          {!climaErro && !clima && (
            <View style={styles.climaCentro}><CarregandoVero tamanho={36} /></View>
          )}
          {!climaErro && clima && (
            <View style={styles.climaLinha}>
              <Text style={styles.climaIc}>{wmo(clima.current.weather_code)[0]}</Text>
              <View style={{ flex: 1 }}>
                <Text style={styles.climaTemp}>{Math.round(clima.current.temperature_2m)}°C</Text>
                <Text style={styles.climaDesc}>{wmo(clima.current.weather_code)[1]}</Text>
              </View>
              <View style={styles.climaMetas}>
                <Text style={styles.climaMeta}>💧 {clima.current.relative_humidity_2m}%</Text>
                <Text style={styles.climaMeta}>🌬 {Math.round(clima.current.wind_speed_10m)} km/h</Text>
              </View>
            </View>
          )}
        </View>
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14 },
  eyebrow: { fontSize: 10, letterSpacing: 1, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold },
  contexto: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansSemi, marginTop: 7 },
  contextoSub: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 3 },
  faseLinha: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', marginTop: 8 },
  faseNome: { fontSize: 17, color: cores.ink, fontFamily: fonte.sansBold },
  faseDia: { fontSize: 12, color: cores.muted, fontFamily: fonte.monoSemi },
  trilho: { height: 6, borderRadius: 4, backgroundColor: cores.track, marginTop: 10, overflow: 'hidden' },
  trilhoFill: { height: 6, borderRadius: 4, backgroundColor: cores.olive },
  faseNota: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 9 },
  grade: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  kpi: { width: '48.4%', flexGrow: 1 },
  kpiValor: { fontSize: 21, fontFamily: fonte.monoSemi, marginTop: 6 },
  kpiDetalhe: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 3, lineHeight: 14 },
  atv: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 9 },
  atvSep: { borderTopWidth: 1, borderTopColor: cores.track },
  atvIc: { fontSize: 17 },
  atvTitulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  atvQuando: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  // serviços em aberto / últimas aplicações
  srv: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 9 },
  btnConcluir: {
    minHeight: 38, paddingHorizontal: 14, borderRadius: raio.r,
    backgroundColor: cores.accent, alignItems: 'center', justifyContent: 'center',
  },
  btnConcluirTxt: { fontSize: 12, color: cores.surface, fontFamily: fonte.sansBold },
  stBadge: { paddingHorizontal: 9, paddingVertical: 4, borderRadius: 20 },
  stBadgeTxt: { fontSize: 10, fontFamily: fonte.sansBold },

  // ações com a válvula pré-selecionada (alvo de toque ≥ espaco.toque)
  acao: {
    minHeight: espaco.toque, borderRadius: raio.r, marginTop: 10,
    alignItems: 'center', justifyContent: 'center',
  },
  acaoPrim: { backgroundColor: cores.accent },
  acaoSec: { backgroundColor: cores.campo, borderWidth: 1.5, borderColor: cores.border2 },
  acaoTxt: { fontSize: 14, fontFamily: fonte.sansBold },
  climaCentro: { alignItems: 'center', justifyContent: 'center', minHeight: 56, marginTop: 6 },
  climaErro: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 8 },
  climaLinha: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 8 },
  climaIc: { fontSize: 30 },
  climaTemp: { fontSize: 22, color: cores.ink, fontFamily: fonte.monoSemi },
  climaDesc: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed },
  climaMetas: { alignItems: 'flex-end', gap: 3 },
  climaMeta: { fontSize: 11.5, color: cores.ink2, fontFamily: fonte.sansSemi },
});
