import React, { useEffect, useState } from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { useIsFocused } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { todos, enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { Cartao, Botao, Badge, Eyebrow, TituloSecao } from '../components/ui';

// J1 — revisão final da ronda antes do envio. As leituras vêm da FILA offline
// (itens tipo 'monitoramento' registrados hoje). O nível mostrado aqui é uma
// PRÉVIA local (quantidade × nivel_acao do alvo); o índice oficial e os
// alertas são calculados no servidor em POST /monitoramentos/enviar.

const NIVEL = {
  critico: { rotulo: '⚠️ crítico', tipo: 'crit' },
  atencao: { rotulo: 'atenção', tipo: 'at' },
  ok: { rotulo: 'abaixo do nível', tipo: 'ok' },
};

const horaAgora = () => {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

const horaDe = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

export default function EnviarLiderScreen() {
  const focada = useIsFocused();
  const { ultimaSync, sincronizarAgora } = useSync();
  // caches p/ traduzir talhao_id/alvo_id em nomes reais
  const { itens: valvulas } = useDadosSync('talhoes');
  const { itens: alvosRef } = useDadosSync('mip_referencias');

  const [leituras, setLeituras] = useState([]);
  // client_uuids que já entraram num envio ao líder — trava contra reenvio
  const [enviadosUuids, setEnviadosUuids] = useState(() => new Set());
  const [carregado, setCarregado] = useState(false);
  const [enviadoAs, setEnviadoAs] = useState(null);
  const [enviando, setEnviando] = useState(false);

  // Lê da fila os monitoramentos de HOJE (pendentes, enviando, confirmados e
  // com erro — o erro reenvia junto, a idempotência do servidor não duplica).
  useEffect(() => {
    let ativo = true;
    (async () => {
      try {
        const hoje = new Date().toISOString().slice(0, 10);
        const itens = await todos();
        // Trava contra duplicação: todo "Enviar ao líder" já vira um item
        // 'monitoramentos_enviar' na fila com os uuids das leituras. Reunimos os
        // uuids já enviados (persistente — sobrevive a fechar/reabrir o app).
        // 'falha' não conta: aquele envio não subiu, pode reenviar.
        const enviados = new Set();
        for (const i of itens) {
          if (i.tipo === 'monitoramentos_enviar' && i.estado !== 'falha') {
            try { (JSON.parse(i.payload).uuids || []).forEach((u) => enviados.add(u)); }
            catch { /* payload inválido — ignora */ }
          }
        }
        const monitoramentos = itens
          .filter((i) =>
            i.tipo === 'monitoramento' &&
            ['pendente', 'enviando', 'confirmado', 'erro'].includes(i.estado) &&
            String(i.criado_em).slice(0, 10) === hoje)
          .map((i) => ({ ...JSON.parse(i.payload), estado: i.estado, criado_em: i.criado_em }))
          // form multi-alvo (21/07): 1 item da fila pode ter N alvos — achata
          // em uma linha por alvo p/ a prévia (o envio continua por client_uuid)
          .flatMap((p) => (
            Array.isArray(p.alvos) && p.alvos.length > 0
              ? p.alvos.map((a, idx) => ({ ...p, ...a, _k: `${p.client_uuid}:${idx}` }))
              : [{ ...p, _k: p.client_uuid }]
          ));
        if (ativo) { setLeituras(monitoramentos); setEnviadosUuids(enviados); setCarregado(true); }
      } catch {
        if (ativo) setCarregado(true);
      }
    })();
    return () => { ativo = false; };
  }, [focada, ultimaSync]);

  function nomeValvula(talhaoId) {
    // o cache 'talhoes' traz setores/válvulas com a válvula-pai
    const v = valvulas.find((x) => (x.talhao_id || x.id) === talhaoId || x.id === talhaoId);
    return v ? v.nome : `Válvula ${talhaoId}`;
  }

  function alvoDe(alvoId) {
    return alvosRef.find((a) => a.id === alvoId) || null;
  }

  // ÍNDICE da leitura (mesma régua do servidor): manual vence; senão regra de
  // 3 (encontradas ÷ amostradas × 100); senão a contagem crua.
  function indiceDe(l) {
    if (l.nivel_infestacao != null && l.nivel_infestacao !== '') return Number(l.nivel_infestacao);
    const qtd = Number(l.quantidade_encontrada) || 0;
    const amostradas = Number(l.plantas_amostradas) || 0;
    return amostradas > 0 ? (qtd / amostradas) * 100 : qtd;
  }

  // Prévia local pelo ÍNDICE × nível de ação. O motor oficial roda no
  // servidor ao enviar (regra do produto: alerta, nunca recomenda).
  function nivelDa(l) {
    const alvo = alvoDe(l.alvo_id);
    if (alvo?.nivel_acao != null && indiceDe(l) >= Number(alvo.nivel_acao)) {
      return 'critico';
    }
    return 'ok';
  }

  const idsValvulas = [...new Set(leituras.map((l) => l.talhao_id))];
  const criticas = leituras.filter((l) => nivelDa(l) === 'critico');
  const primeiraCritica = criticas[0] || null;

  // RESUMO DA RONDA por alvo (desenho do gestor 23/07): índice médio/máx e
  // nº de pontos acima do nível — alvos EM ALERTA primeiro
  const resumoAlvos = (() => {
    const porAlvo = {};
    for (const l of leituras) {
      if (l.alvo_id == null) continue;
      const k = String(l.alvo_id);
      if (!porAlvo[k]) porAlvo[k] = { alvo_id: l.alvo_id, indices: [], acima: 0, piorLeitura: null };
      const idx = indiceDe(l);
      porAlvo[k].indices.push(idx);
      const alvo = alvoDe(l.alvo_id);
      if (alvo?.nivel_acao != null && idx >= Number(alvo.nivel_acao)) {
        porAlvo[k].acima += 1;
        if (!porAlvo[k].piorLeitura || idx > indiceDe(porAlvo[k].piorLeitura)) porAlvo[k].piorLeitura = l;
      }
    }
    return Object.values(porAlvo)
      .map((r) => ({
        ...r,
        alvo: alvoDe(r.alvo_id),
        media: r.indices.reduce((s, v) => s + v, 0) / r.indices.length,
        max: Math.max(...r.indices),
        pontos: r.indices.length,
      }))
      .sort((a, b) => b.acima - a.acima || b.max - a.max);
  })();

  const fmtIdx = (n) => String(Math.round(n * 10) / 10).replace('.', ',');

  // trava: leitura já mandada num envio anterior não vai de novo
  const jaEnviada = (l) => enviadosUuids.has(l.client_uuid);
  const uuidsNovos = [...new Set(
    leituras.filter((l) => l.client_uuid && !jaEnviada(l)).map((l) => l.client_uuid)
  )];
  // ronda já fechada = tem leituras, mas nenhuma pendente de envio
  const tudoEnviado = carregado && leituras.length > 0 && uuidsNovos.length === 0;

  // Enfileira o envio (rascunho → enviado + alertas no servidor) e sincroniza.
  // Manda SÓ as leituras ainda não enviadas — evita reprocessar a ronda inteira.
  async function enviar() {
    if (enviando || uuidsNovos.length === 0) return;
    setEnviando(true);
    try {
      await enfileirar({
        tipo: 'monitoramentos_enviar',
        rota: rotas.monitoramentosEnviar,
        metodo: 'POST',
        payload: { uuids: uuidsNovos },
      });
      // trava imediata: marca como enviado na hora (antes mesmo do próximo load),
      // para um 2º toque não enfileirar o mesmo envio
      setEnviadosUuids((prev) => {
        const n = new Set(prev);
        uuidsNovos.forEach((u) => n.add(u));
        return n;
      });
      setEnviadoAs(horaAgora());
      // não bloqueia: offline, o envio fica na fila e sobe com o sinal
      sincronizarAgora().catch(() => {});
    } finally {
      setEnviando(false);
    }
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Enviar ao líder" sub="Ronda de hoje · leituras do aparelho" />

      <ScrollView contentContainerStyle={styles.corpo} showsVerticalScrollIndicator={false}>
        {/* resumo da ronda */}
        <Cartao>
          <Eyebrow>Resumo da ronda</Eyebrow>
          <View style={styles.resumoLinha}>
            <View style={styles.resumoItem}>
              <Text style={styles.resumoNum}>{leituras.length}</Text>
              <Text style={styles.resumoRotulo}>leituras</Text>
            </View>
            <View style={styles.resumoItem}>
              <Text style={styles.resumoNum}>{idsValvulas.length}</Text>
              <Text style={styles.resumoRotulo}>válvulas</Text>
            </View>
            <View style={styles.resumoItem}>
              <Text style={[styles.resumoNum, criticas.length > 0 && { color: cores.danger }]}>{criticas.length}</Text>
              <Text style={styles.resumoRotulo}>acima do nível</Text>
            </View>
          </View>
          {primeiraCritica && (
            <View style={styles.destaque}>
              <Text style={styles.destaqueTxt}>
                Prévia: {alvoDe(primeiraCritica.alvo_id)?.nome || 'alvo'} com {primeiraCritica.quantidade_encontrada} em {nomeValvula(primeiraCritica.talhao_id)}
              </Text>
              <Badge tipo={NIVEL.critico.tipo}>{NIVEL.critico.rotulo}</Badge>
            </View>
          )}
        </Cartao>

        {/* leituras registradas hoje na fila offline */}
        {/* RESUMO DA RONDA por alvo — em alerta primeiro; gancho p/ emitir DF */}
        {resumoAlvos.length > 0 && (
          <>
            <TituloSecao>Resumo da ronda</TituloSecao>
            {resumoAlvos.map((r) => {
              const emAlerta = r.acima > 0;
              return (
                <Cartao key={String(r.alvo_id)} alerta={emAlerta ? cores.danger : undefined}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.leituraTitulo}>
                      {emAlerta ? '🔴 ' : '🟢 '}{r.alvo?.nome || `Alvo ${r.alvo_id}`}
                    </Text>
                    <Text style={styles.leituraHora}>
                      média {fmtIdx(r.media)} · máx {fmtIdx(r.max)}
                      {r.alvo?.nivel_acao != null ? ` · nível ${String(r.alvo.nivel_acao).replace('.', ',')}` : ''}
                      {` · ${r.acima}/${r.pontos} ${r.pontos === 1 ? 'ponto' : 'pontos'} acima`}
                    </Text>
                  </View>
                </Cartao>
              );
            })}
          </>
        )}

        <TituloSecao>Leituras desta ronda</TituloSecao>
        {carregado && leituras.length === 0 ? (
          /* fila vazia: orienta em vez de quebrar */
          <Cartao>
            <View style={{ flex: 1 }}>
              <Text style={styles.leituraTitulo}>Nenhuma leitura hoje</Text>
              <Text style={styles.leituraHora}>Registre pontos na ronda de monitoramento para enviar ao líder.</Text>
            </View>
          </Cartao>
        ) : (
          leituras.map((l) => {
            const nv = NIVEL[nivelDa(l)];
            const enviada = jaEnviada(l);
            return (
              <Cartao key={l._k || l.client_uuid} style={[styles.leituraRow, enviada && { opacity: 0.55 }]}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.leituraTitulo}>{nomeValvula(l.talhao_id)} · {alvoDe(l.alvo_id)?.nome || `Alvo ${l.alvo_id}`}</Text>
                  <Text style={styles.leituraHora}>{enviada ? '✓ enviado · ' : ''}coletada às {horaDe(l.criado_em)}</Text>
                </View>
                <Text style={styles.leituraIndice}>{l.quantidade_encontrada}</Text>
                <Badge tipo={nv.tipo}>{nv.rotulo}</Badge>
              </Cartao>
            );
          })
        )}

        {/* aviso sobre alerta */}
        <View style={styles.aviso}>
          <Text style={styles.avisoTxt}>
            ⚠️ Ao enviar, leituras acima do nível de ação disparam alerta automático
            no sistema — o líder é notificado na hora.
          </Text>
        </View>

        {/* envio — trava contra duplicação: quando não há leitura nova para
            enviar, mostra o estado "ronda enviada" e desabilita o botão */}
        {tudoEnviado ? (
          <View style={styles.sucesso}>
            <Text style={styles.sucessoIcone}>✓</Text>
            <Text style={styles.sucessoTitulo}>{enviadoAs ? `Enviado às ${enviadoAs}` : 'Ronda já enviada'}</Text>
            <Text style={styles.sucessoMsg}>Estas leituras já foram enviadas ao líder — não serão enviadas de novo. Registre novos pontos para enviar mais.</Text>
          </View>
        ) : (
          <Botao
            titulo={enviadosUuids.size > 0 ? `Enviar novas leituras (${uuidsNovos.length})` : 'Enviar para o líder'}
            disabled={uuidsNovos.length === 0 || enviando}
            onPress={enviar}
            style={{ marginTop: 6 }}
          />
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  resumoLinha: { flexDirection: 'row', marginTop: 12 },
  resumoItem: { flex: 1, alignItems: 'center' },
  resumoNum: { fontSize: 26, color: cores.ink, fontFamily: fonte.monoSemi },
  resumoRotulo: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  destaque: {
    flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 14,
    backgroundColor: cores.campo, borderRadius: raio.sm, padding: 11,
  },
  destaqueTxt: { flex: 1, fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  leituraRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  leituraTitulo: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  leituraHora: { fontSize: 11, color: cores.muted2, fontFamily: fonte.monoSemi, marginTop: 2 },
  leituraIndice: { fontSize: 17, color: cores.ink, fontFamily: fonte.monoSemi },
  aviso: { backgroundColor: cores.amberBg, borderRadius: raio.card, padding: 13, marginTop: 4 },
  avisoTxt: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansSemi, lineHeight: 18 },
  sucesso: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 20, alignItems: 'center', gap: 5, marginTop: 6 },
  sucessoIcone: { fontSize: 30, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 15.5, color: '#0a5c53', fontFamily: fonte.sansBold },
  sucessoMsg: { fontSize: 12, color: '#0a5c53', fontFamily: fonte.sansMed, textAlign: 'center' },
});
