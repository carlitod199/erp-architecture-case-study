import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { cores, fonte, raio, espaco } from '../theme';
import { useAuth } from '../context/AuthContext';
import { useSync } from '../context/SyncContext';
import http from '../services/http';
import veroApi, { rotas } from '../services/veroApi';
import { enfileirar } from '../offline/fila';
import { novoClientUuid } from '../offline/idempotencia';
import { Cartao, Botao, Input, Chip, TituloSecao } from '../components/ui';

// Recepção de cargas no Packing House: o conferente escolhe a unidade, marca
// as cargas que chegaram do campo, informa o cabeçalho do caminhão e aceita.
// Os 5 gates (carência/certificação/rastreabilidade/licença/SO₂) são avaliados
// online antes de aceitar; se algum BLOQUEIA, aceitar registra a recepção como
// REJEITADA (regra do servidor). Escrita idempotente por client_uuid — pode
// entrar na fila offline como as demais.

const UNIDADE_KEY = 'vero_ph_unidade';

const GATES = [
  ['carencia', 'Carência'],
  ['certificacao', 'Certificação'],
  ['rastreabilidade', 'Rastreabilidade'],
  ['licenca', 'Licença'],
  ['so2', 'SO₂'],
];
const COR_GATE = {
  ok: cores.pos, aviso: cores.amber, bloqueio: cores.danger, sem_dado: cores.muted2,
};
const COR_RELOGIO = {
  verde: cores.pos, amarelo: cores.amber, vermelho: cores.danger, sem_dado: cores.faint,
};
const METODO_ROTULO = {
  segregacao: 'Segregação',
  identidade_preservada: 'Identidade preservada',
};

const numDec = (v) => {
  const n = Number(String(v).replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};
const fmtKg = (v) => (Number(v) || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
const dataBR = (v) => {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}` : '';
};

export default function PackingRecepcaoScreen() {
  const { pode } = useAuth();
  const { sincronizarAgora } = useSync();
  const podeVer = pode('packing.recepcao.ver');
  const podeEditar = pode('packing.recepcao.editar');

  // contexto (unidades/mercados/métodos) — leitura direta, precisa de rede
  const [ctx, setCtx] = useState(null);
  const [ctxErro, setCtxErro] = useState(null);
  const [carregandoCtx, setCarregandoCtx] = useState(true);
  const [unidade, setUnidade] = useState(null); // {id, nome}

  // cargas pendentes da unidade
  const [pend, setPend] = useState(null); // {proximo_numero, cargas}
  const [pendErro, setPendErro] = useState(null);
  const [carregandoPend, setCarregandoPend] = useState(false);

  // seleção: carga_id → {metodo, contentores, temperatura, turma}
  const [sel, setSel] = useState({});

  // cabeçalho do caminhão
  const [placa, setPlaca] = useState('');
  const [motorista, setMotorista] = useState('');
  const [transportadora, setTransportadora] = useState('');
  const [pesoBruto, setPesoBruto] = useState('');
  const [pesoTara, setPesoTara] = useState('');
  const [mercadoId, setMercadoId] = useState(null);
  const [observacao, setObservacao] = useState('');

  // gates + envio
  const [gates, setGates] = useState(null); // resposta do avaliar
  const [avaliando, setAvaliando] = useState(false);
  const [gatesErro, setGatesErro] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const [resultado, setResultado] = useState(null); // {numero,status} | {fila:true}

  const metodos = ctx?.metodos_rastreabilidade || ['segregacao', 'identidade_preservada'];
  const metodoPadrao = metodos[0] || 'segregacao';

  const carregarContexto = useCallback(async () => {
    setCarregandoCtx(true);
    setCtxErro(null);
    try {
      const r = await veroApi.packingContexto();
      const d = r.data || {};
      setCtx(d);
      // restaura a última unidade usada neste aparelho (se ainda existir)
      let salva = null;
      try { salva = await SecureStore.getItemAsync(UNIDADE_KEY); } catch (_) {}
      const unidades = d.unidades || [];
      const lembrada = unidades.find((u) => String(u.id) === String(salva));
      if (lembrada) setUnidade(lembrada);
      else if (unidades.length === 1) setUnidade(unidades[0]);
    } catch (e) {
      setCtxErro(e?.codigo === 'sem_conexao'
        ? 'A recepção precisa de sinal para carregar as unidades e as cargas pendentes.'
        : (e?.message || 'Não foi possível carregar o packing.'));
    } finally {
      setCarregandoCtx(false);
    }
  }, []);
  useEffect(() => { carregarContexto(); }, [carregarContexto]);

  const carregarPendentes = useCallback(async () => {
    if (!unidade) return;
    setCarregandoPend(true);
    setPendErro(null);
    try {
      const r = await veroApi.packingPendentes(unidade.id);
      setPend(r.data || { proximo_numero: '', cargas: [] });
    } catch (e) {
      setPend(null);
      setPendErro(e?.codigo === 'sem_conexao'
        ? 'Sem sinal — conecte para ver as cargas pendentes.'
        : (e?.message || 'Não foi possível carregar as cargas.'));
    } finally {
      setCarregandoPend(false);
    }
  }, [unidade]);
  useEffect(() => { carregarPendentes(); }, [carregarPendentes]);

  async function escolherUnidade(u) {
    setUnidade(u);
    setSel({});
    setGates(null);
    setResultado(null);
    try { await SecureStore.setItemAsync(UNIDADE_KEY, String(u.id)); } catch (_) {}
  }

  // qualquer mexida na seleção/método invalida a avaliação anterior
  function alternarCarga(c) {
    setGates(null);
    setSel((s) => {
      const novo = { ...s };
      if (novo[c.carga_id]) delete novo[c.carga_id];
      else novo[c.carga_id] = { metodo: metodoPadrao, contentores: '', temperatura: '', turma: '' };
      return novo;
    });
  }
  function setCampoCarga(cargaId, campo, v) {
    if (campo === 'metodo') setGates(null);
    setSel((s) => ({ ...s, [cargaId]: { ...s[cargaId], [campo]: v } }));
  }

  const idsSel = Object.keys(sel).map(Number);
  const nSel = idsSel.length;

  function montarItens() {
    return idsSel.map((cargaId) => {
      const o = sel[cargaId] || {};
      const cont = numDec(o.contentores);
      const temp = o.temperatura !== '' ? Number(String(o.temperatura).replace(',', '.')) : null;
      return {
        carga_id: cargaId,
        metodo: o.metodo || metodoPadrao,
        ...(cont !== null && cont > 0 ? { n_contentores: Math.round(cont) } : {}),
        ...(temp !== null && Number.isFinite(temp) ? { temperatura_chegada_c: temp } : {}),
        ...(o.turma && o.turma.trim() ? { turma_colheita: o.turma.trim() } : {}),
      };
    });
  }

  async function avaliarGates() {
    if (!unidade || nSel === 0 || avaliando) return;
    setAvaliando(true);
    setGatesErro(null);
    try {
      const r = await veroApi.packingAvaliar({
        unidade_id: unidade.id,
        ...(mercadoId ? { mercado_id: mercadoId } : {}),
        itens: montarItens(),
      });
      setGates(r.data || null);
    } catch (e) {
      setGates(null);
      setGatesErro(e?.codigo === 'sem_conexao'
        ? 'A avaliação dos gates precisa de sinal.'
        : (e?.message || 'Não foi possível avaliar.'));
    } finally {
      setAvaliando(false);
    }
  }

  async function aceitar() {
    if (!unidade || nSel === 0 || enviando || !podeEditar) return;
    setEnviando(true);
    try {
      const payload = {
        client_uuid: novoClientUuid(),
        unidade_id: unidade.id,
        ...(placa.trim() ? { veiculo_placa: placa.trim().toUpperCase() } : {}),
        ...(motorista.trim() ? { motorista: motorista.trim() } : {}),
        ...(transportadora.trim() ? { transportadora: transportadora.trim() } : {}),
        ...(numDec(pesoBruto) ? { peso_bruto_kg: numDec(pesoBruto) } : {}),
        ...(numDec(pesoTara) ? { peso_tara_kg: numDec(pesoTara) } : {}),
        ...(mercadoId ? { mercado_id: mercadoId } : {}),
        ...(observacao.trim() ? { observacao: observacao.trim() } : {}),
        itens: montarItens(),
      };
      try {
        const r = await http.post(rotas.packingRecepcao, payload);
        setResultado(r.data || {});
        sincronizarAgora().catch(() => {});
      } catch (e) {
        if (e?.codigo === 'sem_conexao') {
          await enfileirar({ tipo: 'ph_recepcao', rota: rotas.packingRecepcao, metodo: 'POST', payload });
          setResultado({ fila: true });
        } else {
          setGatesErro(e?.message || 'Não foi possível registrar a recepção.');
        }
      }
    } finally {
      setEnviando(false);
    }
  }

  function novaRecepcao() {
    setSel({});
    setGates(null);
    setGatesErro(null);
    setResultado(null);
    setPlaca(''); setMotorista(''); setTransportadora('');
    setPesoBruto(''); setPesoTara(''); setObservacao('');
    carregarPendentes();
  }

  if (!podeVer) {
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Recepção de cargas" sub="Chegada do campo no packing" />
        <View style={styles.corpo}>
          <Cartao><Text style={styles.sub}>Você não tem acesso à recepção do packing.</Text></Cartao>
        </View>
      </View>
    );
  }

  // ---- tela de resultado ----
  if (resultado) {
    const rejeitada = resultado.status === 'rejeitada';
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Recepção de cargas" sub={unidade?.nome} />
        <View style={styles.corpo}>
          <View style={[styles.resultado, resultado.fila ? styles.resultadoFila : (rejeitada ? styles.resultadoRej : styles.resultadoOk)]}>
            <Text style={styles.resultadoIcone}>{resultado.fila ? '⏳' : (rejeitada ? '✕' : '✓')}</Text>
            {resultado.fila ? (
              <>
                <Text style={styles.resultadoTitulo}>Recepção na fila</Text>
                <Text style={styles.resultadoMsg}>
                  Sem sinal agora — será registrada quando conectar. Acompanhe em Mais → Sincronização.
                </Text>
              </>
            ) : (
              <>
                <Text style={styles.resultadoTitulo}>
                  {rejeitada ? `Recepção ${resultado.numero} REJEITADA` : `Recepção ${resultado.numero} aceita`}
                </Text>
                <Text style={styles.resultadoMsg}>
                  {rejeitada
                    ? 'Um gate bloqueou a carga — ela ficou registrada como rejeitada. Veja o motivo no VERO web (Packing → Recepção).'
                    : `${resultado.itens || nSel} carga${(resultado.itens || nSel) === 1 ? '' : 's'} recebida${(resultado.itens || nSel) === 1 ? '' : 's'} na unidade.`}
                </Text>
              </>
            )}
            <Botao titulo="Nova recepção" onPress={novaRecepcao} style={{ marginTop: 12, alignSelf: 'stretch' }} />
          </View>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.tela}>
      <AppHeader titulo="Recepção de cargas" sub="Chegada do campo no packing" />
      <ScrollView contentContainerStyle={styles.corpo} keyboardShouldPersistTaps="handled">
        {/* ---- passo 0: unidade ---- */}
        {carregandoCtx ? (
          <Cartao><Text style={styles.sub}>Carregando o packing…</Text></Cartao>
        ) : ctxErro ? (
          <Cartao>
            <Text style={styles.sub}>{ctxErro}</Text>
            <Botao titulo="Tentar de novo" variante="secundaria" onPress={carregarContexto} style={{ marginTop: 12 }} />
          </Cartao>
        ) : !unidade ? (
          <>
            <TituloSecao>Escolha a unidade</TituloSecao>
            {(ctx?.unidades || []).length === 0 && (
              <Cartao><Text style={styles.sub}>Nenhuma unidade de packing cadastrada. Cadastre no VERO web (Almoxarifados, tipo packing).</Text></Cartao>
            )}
            {(ctx?.unidades || []).map((u) => (
              <Pressable key={u.id} style={styles.unidade} onPress={() => escolherUnidade(u)} accessibilityLabel={u.nome}>
                <Icone nome="estoque" tam={20} cor={cores.accent} />
                <Text style={styles.unidadeNome}>{u.nome}</Text>
                <Text style={styles.trocar}>escolher</Text>
              </Pressable>
            ))}
          </>
        ) : (
          <>
            {/* unidade escolhida (persistida) */}
            <Pressable style={styles.unidadeAtual} onPress={() => setUnidade(null)} accessibilityLabel="Trocar unidade">
              <Icone nome="estoque" tam={16} cor={cores.accent} />
              <Text style={styles.unidadeAtualTxt} numberOfLines={1}>
                {unidade.nome}{pend?.proximo_numero ? ` · próx. ${pend.proximo_numero}` : ''}
              </Text>
              <Text style={styles.trocar}>trocar</Text>
            </Pressable>

            {/* ---- cargas pendentes ---- */}
            <TituloSecao>Cargas aguardando recepção</TituloSecao>
            {carregandoPend && <Cartao><Text style={styles.sub}>Buscando cargas…</Text></Cartao>}
            {!!pendErro && (
              <Cartao>
                <Text style={styles.sub}>{pendErro}</Text>
                <Botao titulo="Tentar de novo" variante="secundaria" onPress={carregarPendentes} style={{ marginTop: 12 }} />
              </Cartao>
            )}
            {!carregandoPend && !pendErro && (pend?.cargas || []).length === 0 && (
              <Cartao><Text style={styles.sub}>Nenhuma carga pendente nesta unidade.</Text></Cartao>
            )}
            {(pend?.cargas || []).map((c) => {
              const marcada = !!sel[c.carga_id];
              const o = sel[c.carga_id] || {};
              const rel = c.relogio || {};
              return (
                <Pressable
                  key={c.carga_id}
                  onPress={() => alternarCarga(c)}
                  accessibilityLabel={`Carga ${c.romaneio}`}
                  accessibilityState={{ selected: marcada }}
                  style={[styles.carga, marcada && styles.cargaMarcada]}
                >
                  <View style={styles.cargaLinha}>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.cargaRomaneio}>{c.romaneio || `carga ${c.carga_id}`}</Text>
                      <Text style={styles.sub}>
                        {[c.talhao_nome, c.variedade_nome].filter(Boolean).join(' · ') || 'sem válvula'}
                        {c.peso_kg !== null ? ` · ${fmtKg(c.peso_kg)} kg` : ''}
                        {c.data_carga ? ` · ${dataBR(c.data_carga)}` : ''}
                      </Text>
                    </View>
                    {/* relógio de frio: horas desde a colheita */}
                    <View style={styles.relogio}>
                      <View style={[styles.relogioPonto, { backgroundColor: COR_RELOGIO[rel.cor] || cores.faint }]} />
                      <Text style={[styles.relogioTxt, { color: COR_RELOGIO[rel.cor] || cores.muted2 }]}>
                        {rel.horas !== null && rel.horas !== undefined ? `${fmtKg(rel.horas)}h` : 'sem dado'}
                      </Text>
                    </View>
                  </View>

                  {marcada && (
                    <View style={styles.cargaDetalhe}>
                      <Text style={styles.rot}>Rastreabilidade</Text>
                      <View style={styles.chips}>
                        {metodos.map((m) => (
                          <Chip key={m} selecionado={(o.metodo || metodoPadrao) === m} onPress={() => setCampoCarga(c.carga_id, 'metodo', m)}>
                            {METODO_ROTULO[m] || m}
                          </Chip>
                        ))}
                      </View>
                      <View style={styles.detalheLinha}>
                        <View style={{ flex: 1 }}>
                          <Text style={styles.rot}>Contentores</Text>
                          <Input
                            value={o.contentores}
                            onChangeText={(v) => setCampoCarga(c.carga_id, 'contentores', v.replace(/[^\d]/g, ''))}
                            keyboardType="number-pad" placeholder="opcional"
                          />
                        </View>
                        <View style={{ flex: 1 }}>
                          <Text style={styles.rot}>Temp. chegada (°C)</Text>
                          <Input
                            value={o.temperatura}
                            onChangeText={(v) => setCampoCarga(c.carga_id, 'temperatura', v.replace(/[^\d.,-]/g, ''))}
                            keyboardType="numbers-and-punctuation" placeholder="opcional"
                          />
                        </View>
                      </View>
                      <Text style={styles.rot}>Turma de colheita</Text>
                      <Input
                        value={o.turma}
                        onChangeText={(v) => setCampoCarga(c.carga_id, 'turma', v)}
                        placeholder="opcional"
                      />
                    </View>
                  )}
                </Pressable>
              );
            })}

            {/* ---- cabeçalho do caminhão ---- */}
            {nSel > 0 && (
              <Cartao>
                <TituloSecao>Chegada — {nSel} carga{nSel === 1 ? '' : 's'}</TituloSecao>
                <View style={styles.detalheLinha}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.rot}>Placa</Text>
                    <Input value={placa} onChangeText={setPlaca} autoCapitalize="characters" placeholder="AAA-0A00" />
                  </View>
                  <View style={{ flex: 2 }}>
                    <Text style={styles.rot}>Motorista</Text>
                    <Input value={motorista} onChangeText={setMotorista} placeholder="nome" />
                  </View>
                </View>
                <Text style={styles.rot}>Transportadora</Text>
                <Input value={transportadora} onChangeText={setTransportadora} placeholder="opcional" />
                <View style={styles.detalheLinha}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.rot}>Peso bruto (kg)</Text>
                    <Input value={pesoBruto} onChangeText={(v) => setPesoBruto(v.replace(/[^\d.,]/g, ''))} keyboardType="decimal-pad" placeholder="0" />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.rot}>Tara (kg)</Text>
                    <Input value={pesoTara} onChangeText={(v) => setPesoTara(v.replace(/[^\d.,]/g, ''))} keyboardType="decimal-pad" placeholder="0" />
                  </View>
                </View>
                {(ctx?.mercados || []).length > 0 && (
                  <>
                    <Text style={styles.rot}>Mercado de destino</Text>
                    <View style={styles.chips}>
                      {(ctx?.mercados || []).map((m) => (
                        <Chip key={m.id} selecionado={mercadoId === m.id} onPress={() => { setMercadoId(mercadoId === m.id ? null : m.id); setGates(null); }}>
                          {m.nome}
                        </Chip>
                      ))}
                    </View>
                  </>
                )}
                <Text style={styles.rot}>Observação</Text>
                <Input value={observacao} onChangeText={setObservacao} placeholder="opcional" />
              </Cartao>
            )}

            {/* ---- gates ---- */}
            {nSel > 0 && (
              <Cartao>
                <TituloSecao>Gates de qualidade</TituloSecao>
                {!gates && (
                  <Text style={[styles.sub, { marginTop: 6 }]}>
                    Avalie carência, certificação, rastreabilidade, licença e SO₂ antes de aceitar (precisa de sinal).
                  </Text>
                )}
                {!!gates && GATES.map(([k, rotulo]) => {
                  const g = gates[k] || {};
                  const cor = COR_GATE[g.status] || cores.muted2;
                  return (
                    <View key={k} style={styles.gate}>
                      <View style={[styles.gatePonto, { backgroundColor: cor }]} />
                      <Text style={styles.gateNome}>{rotulo}</Text>
                      <Text style={[styles.gateStatus, { color: cor }]} numberOfLines={2}>
                        {g.detalhe || g.status || '—'}
                      </Text>
                    </View>
                  );
                })}
                {!!gates?.bloqueia && (
                  <Text style={styles.avisoBloqueio}>
                    Um gate BLOQUEIA esta seleção — se aceitar, a recepção será registrada como REJEITADA.
                  </Text>
                )}
                {!!gatesErro && <Text style={styles.erro}>{gatesErro}</Text>}
                <Botao
                  titulo={gates ? 'Reavaliar gates' : 'Avaliar gates'}
                  variante="secundaria"
                  carregando={avaliando}
                  onPress={avaliarGates}
                  style={{ marginTop: 12 }}
                />
              </Cartao>
            )}

            {/* ---- aceitar ---- */}
            {nSel > 0 && (podeEditar ? (
              <Botao
                titulo={gates?.bloqueia ? 'Aceitar mesmo assim (registra REJEITADA)' : 'Aceitar recepção'}
                variante={gates?.bloqueia ? 'ambar' : 'primaria'}
                carregando={enviando}
                onPress={aceitar}
              />
            ) : (
              <Cartao>
                <Text style={styles.sub}>Seu perfil só consulta — aceitar a recepção exige permissão de edição.</Text>
              </Cartao>
            ))}
          </>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  rot: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansSemi, marginTop: 12, marginBottom: 6 },
  sub: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed },
  erro: { fontSize: 12, color: cores.danger, fontFamily: fonte.sansSemi, marginTop: 8 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  trocar: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },

  unidade: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: raio.card, paddingHorizontal: espaco.md, minHeight: espaco.toque + 12,
  },
  unidadeNome: { flex: 1, fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansBold },
  unidadeAtual: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: cores.campo, borderRadius: raio.sm, paddingHorizontal: 12, paddingVertical: 11,
  },
  unidadeAtualTxt: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },

  carga: {
    backgroundColor: cores.surface, borderWidth: 1.5, borderColor: cores.border,
    borderRadius: raio.card, padding: espaco.md,
  },
  cargaMarcada: { borderColor: cores.accent, backgroundColor: cores.warm },
  cargaLinha: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 40 },
  cargaRomaneio: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.monoSemi },
  cargaDetalhe: { marginTop: 4, borderTopWidth: 1, borderTopColor: cores.border, paddingTop: 2 },
  detalheLinha: { flexDirection: 'row', gap: 10 },

  relogio: { alignItems: 'center', gap: 3, minWidth: 52 },
  relogioPonto: { width: 14, height: 14, borderRadius: 7 },
  relogioTxt: { fontSize: 11.5, fontFamily: fonte.monoSemi },

  gate: {
    flexDirection: 'row', alignItems: 'center', gap: 10,
    paddingVertical: 9, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: cores.border,
  },
  gatePonto: { width: 12, height: 12, borderRadius: 6 },
  gateNome: { width: 118, fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  gateStatus: { flex: 1, fontSize: 12, fontFamily: fonte.sansMed, textAlign: 'right' },
  avisoBloqueio: {
    fontSize: 12.5, color: cores.danger, fontFamily: fonte.sansBold,
    marginTop: 10, lineHeight: 18,
  },

  resultado: { borderRadius: raio.card, padding: 22, alignItems: 'center', gap: 6 },
  resultadoOk: { backgroundColor: cores.posBg },
  resultadoRej: { backgroundColor: cores.dangerBg },
  resultadoFila: { backgroundColor: cores.amberBg },
  resultadoIcone: { fontSize: 32, color: cores.ink, fontFamily: fonte.sansBold },
  resultadoTitulo: { fontSize: 16, color: cores.ink, fontFamily: fonte.sansBold, textAlign: 'center' },
  resultadoMsg: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 18 },
});
