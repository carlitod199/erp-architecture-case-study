import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useRoute } from '@react-navigation/native';
import { Tela, Cartao, Botao, Badge, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';
import AppHeader from '../components/AppHeader';
import { useSync } from '../context/SyncContext';
import { useDadosSync } from '../hooks/useDadosSync';
import { enfileirar } from '../offline/fila';
import veroApi, { rotas } from '../services/veroApi';
import { cores, fonte, severidade } from '../theme';

// J4 — detalhe do alerta. Recebe o alerta real via route.params (lista de
// /sync/alertas). Reconhecer é otimista e entra na fila offline.
// Evolução/histórico vêm do cache de mip_recebidos (mesma válvula do alerta);
// produtos indicados vêm de GET /mip/alvos/{id}/produtos (RT × saldo) quando
// houver rede — sem fonte, os blocos simplesmente não aparecem (nada de demo).

const SEV_ROTULO = { critico: 'Crítico', atencao: 'Atenção', info: 'Info' };
const ALTURA_GRAFICO = 96;

// 'YYYY-MM-DD[ HH:MM:SS]' → "dd/mm/aaaa[ · hh:mm]"
function dataBr(valor) {
  if (!valor) return '';
  const m = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return String(valor);
  const base = `${m[3]}/${m[2]}/${m[1]}`;
  return m[4] ? `${base} · ${m[4]}:${m[5]}` : base;
}

function dataCurta(valor) {
  const m = String(valor || '').match(/^\d{4}-(\d{2})-(\d{2})/);
  return m ? `${m[2]}/${m[1]}` : '';
}

export default function AlertaDetalheScreen() {
  const route = useRoute();
  const alerta = route.params?.alerta || null;
  const { sincronizarAgora } = useSync();
  const { itens: recebidos } = useDadosSync('mip_recebidos');
  const [reconhecido, setReconhecido] = useState(alerta ? alerta.status === 'reconhecido' : false);
  const [produtos, setProdutos] = useState(null); // null = sem fonte (esconde o card)

  const sev = alerta && severidade[alerta.sev] ? alerta.sev : 'critico';
  const corSev = severidade[sev];

  // leituras reais da mesma válvula do alerta, mais antigas → mais novas
  const leituras = useMemo(() => {
    if (!alerta?.talhao_id) return [];
    return (recebidos || [])
      .filter((l) => String(l.talhao_id) === String(alerta.talhao_id))
      .sort((a, b) => String(a.data_monitoramento || '').localeCompare(String(b.data_monitoramento || '')));
  }, [recebidos, alerta]);

  const ultimas = leituras.slice(-5);
  const maisRecente = ultimas[ultimas.length - 1] || null;
  const nivelAcao = maisRecente?.nivel_acao != null ? Number(maisRecente.nivel_acao) : null;
  const valores = ultimas.map((l) => Number(l.nivel_infestacao) || 0);
  const teto = Math.max(10, ...valores, nivelAcao || 0) * 1.2;
  const nomeValvula = maisRecente?.talhao_nome || (alerta?.talhao_id ? `válvula ${alerta.talhao_id}` : '');

  // produtos indicados pelo RT para o alvo da leitura mais recente (só com rede)
  useEffect(() => {
    const alvoId = maisRecente?.alvo_id;
    if (!alvoId) return;
    let ativo = true;
    veroApi.produtosDoAlvo(alvoId)
      .then((resp) => { if (ativo) setProdutos(resp?.data?.itens || null); })
      .catch(() => {}); // offline/sem permissão: card não aparece
    return () => { ativo = false; };
  }, [maisRecente?.alvo_id]);

  function reconhecer() {
    setReconhecido(true); // otimista
    if (alerta) {
      enfileirar({
        tipo: 'alerta',
        rota: rotas.alertaReconhecer(alerta.id),
        metodo: 'POST',
        payload: {},
      })
        .then(() => { sincronizarAgora(); })
        .catch(() => {});
    }
  }

  if (!alerta) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Detalhe do alerta" />
        <Tela>
          <Cartao>
            <Text style={styles.alertaMsg}>Alerta não encontrado. Volte à lista e tente de novo.</Text>
          </Cartao>
        </Tela>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        titulo={alerta.titulo}
        sub={[SEV_ROTULO[sev], alerta.categoria || alerta.origem, dataBr(alerta.data)].filter(Boolean).join(' · ')}
      />
      <Tela>
        {/* Card principal — dados reais do alerta */}
        <Cartao>
          <View style={styles.cabecalho}>
            <View style={[styles.pontoSev, { backgroundColor: corSev }]} />
            <Eyebrow>{`Alerta ${SEV_ROTULO[sev].toLowerCase()} · ${alerta.categoria || 'geral'}`}</Eyebrow>
          </View>
          <Text style={styles.alertaTitulo}>{alerta.titulo}</Text>
          {!!alerta.detalhe && <Text style={styles.alertaMsg}>{alerta.detalhe}</Text>}
          <View style={styles.metaLinha}>
            {!!nomeValvula && (
              <View style={styles.metaItem}>
                <Icone nome="mapa" tam={12} cor={cores.muted} />
                <Text style={styles.meta}>{nomeValvula}</Text>
              </View>
            )}
            {!!alerta.data && (
              <View style={styles.metaItem}>
                <Icone nome="data" tam={12} cor={cores.muted} />
                <Text style={styles.meta}>{dataBr(alerta.data)}</Text>
              </View>
            )}
            <Badge tipo={reconhecido ? 'ok' : 'crit'} style={{ marginLeft: 'auto' }}>
              {reconhecido ? '✓ Reconhecido' : 'Aberto'}
            </Badge>
          </View>
        </Cartao>

        {/* Evolução das leituras — mip_recebidos da mesma válvula (cache offline) */}
        {ultimas.length > 0 && (
          <Cartao>
            <Eyebrow>Evolução das leituras</Eyebrow>
            <View style={styles.grafico}>
              {nivelAcao != null && (
                <>
                  <View style={[styles.linhaAcao, { bottom: (nivelAcao / teto) * ALTURA_GRAFICO }]} />
                  <Text style={[styles.linhaAcaoTxt, { bottom: (nivelAcao / teto) * ALTURA_GRAFICO + 2 }]}>
                    nível de ação {nivelAcao}
                  </Text>
                </>
              )}
              {ultimas.map((l, i) => {
                const valor = Number(l.nivel_infestacao) || 0;
                const acima = nivelAcao != null && valor >= nivelAcao;
                return (
                  <View key={l.id || i} style={styles.colBarra}>
                    <Text style={[styles.barraValor, acima && { color: severidade.critico }]}>{valor}</Text>
                    <View
                      style={[
                        styles.barra,
                        {
                          height: Math.max(4, (valor / teto) * ALTURA_GRAFICO),
                          backgroundColor: acima ? severidade.critico : cores.olive,
                        },
                      ]}
                    />
                    <Text style={styles.barraRotulo}>{dataCurta(l.data_monitoramento)}</Text>
                  </View>
                );
              })}
            </View>
            <Text style={styles.leituraNota}>
              {maisRecente?.alvo_nome ? `Alvo: ${maisRecente.alvo_nome} · ` : ''}unidade {maisRecente?.unidade || '%'}
            </Text>
          </Cartao>
        )}

        {/* Produtos indicados — GET /mip/alvos/{id}/produtos (RT × saldo) */}
        {Array.isArray(produtos) && produtos.length > 0 && (
          <Cartao>
            <Eyebrow>Produtos indicados (RT × saldo)</Eyebrow>
            {produtos.map((p, i) => {
              const saldo = Number(p.saldo) || 0;
              const ok = saldo > 0;
              return (
                <View key={p.produto_id || i} style={[styles.produto, i > 0 && styles.produtoSep]}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.produtoNome}>{p.nome}</Text>
                    <Text style={styles.produtoDose}>
                      {[p.dose, p.dose_unidade].filter(Boolean).join(' ') || 'dose no cadastro do RT'}
                    </Text>
                  </View>
                  <Badge tipo={ok ? 'ok' : 'at'} style={styles.saldoBadge}>
                    {ok ? '✓ ' : '⚠️ '}{saldo} em estoque
                  </Badge>
                </View>
              );
            })}
            <Text style={styles.rtNota}>O VERO não recomenda produtos — a lista vem do cadastro do RT.</Text>
          </Cartao>
        )}

        {/* Histórico de monitoramento — leituras reais mais recentes */}
        {leituras.length > 0 && (
          <Cartao>
            <Eyebrow>Histórico do monitoramento</Eyebrow>
            {[...leituras].reverse().slice(0, 3).map((l, i) => {
              const valor = Number(l.nivel_infestacao) || 0;
              const acima = l.nivel_acao != null && valor >= Number(l.nivel_acao);
              const inicial = (l.monitor_nome || '?').trim()[0]?.toUpperCase() || '?';
              return (
                <View key={l.id || i} style={styles.histLinha}>
                  <View style={[styles.avatar, i > 0 && { backgroundColor: cores.track }]}>
                    <Text style={[styles.avatarTxt, i > 0 && { color: cores.muted2 }]}>{inicial}</Text>
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.histNome}>{l.monitor_nome || 'Monitor'}</Text>
                    <Text style={styles.histInfo}>
                      {[dataBr(l.data_monitoramento), l.alvo_nome].filter(Boolean).join(' · ')}
                    </Text>
                  </View>
                  <Text style={[styles.histIndice, !acima && { color: cores.amber }]}>
                    {valor}{l.unidade === '%' ? '%' : ''}
                  </Text>
                </View>
              );
            })}
          </Cartao>
        )}

        {/* Ações — a OS de pulverização é gerada no VERO web pelo líder/RT */}
        {!reconhecido ? (
          <Botao titulo="Reconhecer alerta" onPress={reconhecer} />
        ) : (
          <Botao titulo="✓ Alerta reconhecido" tipo="fantasma" onPress={() => {}} />
        )}
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  cabecalho: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  pontoSev: { width: 10, height: 10, borderRadius: 5 },
  alertaTitulo: { fontSize: 17, color: cores.ink, fontFamily: fonte.sansBold, marginTop: 10, lineHeight: 23 },
  alertaMsg: { fontSize: 13, color: cores.ink2, fontFamily: fonte.sansMed, marginTop: 7, lineHeight: 19 },
  metaLinha: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginTop: 12 },
  metaItem: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  meta: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed },
  grafico: {
    flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-around',
    marginTop: 16, paddingTop: 20, position: 'relative',
  },
  linhaAcao: {
    position: 'absolute', left: 0, right: 0, height: 0,
    borderTopWidth: 1.5, borderStyle: 'dashed', borderColor: severidade.critico, opacity: 0.55,
  },
  linhaAcaoTxt: {
    position: 'absolute', right: 0, fontSize: 9,
    color: severidade.critico, fontFamily: fonte.sansBold, opacity: 0.8,
  },
  colBarra: { alignItems: 'center', width: 52 },
  barraValor: { fontSize: 11, color: cores.muted, fontFamily: fonte.monoSemi, marginBottom: 4 },
  barra: { width: 26, borderTopLeftRadius: 6, borderTopRightRadius: 6 },
  barraRotulo: { fontSize: 10, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 5 },
  leituraNota: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 10 },
  produto: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 11 },
  produtoSep: { borderTopWidth: 1, borderTopColor: cores.track },
  produtoNome: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  produtoDose: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  saldoBadge: { maxWidth: 165 },
  rtNota: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 6, fontStyle: 'italic' },
  histLinha: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 12 },
  avatar: {
    width: 34, height: 34, borderRadius: 17,
    backgroundColor: cores.posBg, alignItems: 'center', justifyContent: 'center',
  },
  avatarTxt: { fontSize: 14, color: cores.accent, fontFamily: fonte.sansBold },
  histNome: { fontSize: 12.5, color: cores.ink, fontFamily: fonte.sansSemi },
  histInfo: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  histIndice: { fontSize: 14, color: severidade.critico, fontFamily: fonte.monoSemi },
});
