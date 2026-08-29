import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, Pressable, TextInput, ScrollView, Image, StyleSheet } from 'react-native';
import { useRoute } from '@react-navigation/native';
import * as ImagePicker from 'expo-image-picker';
import AppHeader from '../components/AppHeader';
import ContextoValvula from '../components/apontar/ContextoValvula';
import { cores, fonte, raio, espaco } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSafraAtiva, rotuloSafra } from '../hooks/useSafraAtiva';
import { useParametro } from '../hooks/useParametro';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { obterCoordenada, FAZENDA } from '../services/clima';
import { useSync } from '../context/SyncContext';

// J3 — apontamento unificado em 3 passos: o que foi feito → onde → detalhes.
// 100% offline: cada válvula selecionada vira um item na fila local (SQLite)
// e sobe para POST /apontamentos quando houver sinal.

const TIPOS_SERVICO = [
  { id: 'poda', rotulo: 'Poda', icone: '✂️' },
  { id: 'pulverizacao', rotulo: 'Pulverização', icone: '💨' },
  { id: 'adubacao', rotulo: 'Adubação', icone: '🌱' },
  { id: 'colheita', rotulo: 'Colheita', icone: '🧺' },
  { id: 'manutencao', rotulo: 'Manutenção', icone: '🔧' },
  { id: 'outro', rotulo: 'Outro', icone: '➕' },
];

// Mapa do tipo do wizard → ENUM de agro_apontamentos.tipo no servidor
// ('aplicacao','nutricao','tratos_culturais','colheita','abastecimento','outro').
// Irrigação NÃO passa por aqui: tem tela e rota próprias.
// Qualquer tipo não mapeado cai em 'outro'.
const TIPO_SERVIDOR = {
  pulverizacao: 'aplicacao',
  adubacao: 'nutricao',
  poda: 'tratos_culturais',
  colheita: 'colheita',
  manutencao: 'outro',
  outro: 'outro',
};

const PASSOS = ['O que foi feito', 'Onde', 'Detalhes'];

// 'YYYY-MM-DD HH:MM:SS' — formato que a API aceita em 'data'
const agoraSql = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

// Linha de detalhe da válvula — contexto de vinhedo do sync enriquecido
// (variedade/nº plantas, Onda 2); fallback nos campos antigos.
function detalheDa(v) {
  const partes = [];
  if (v.variedade) partes.push(v.variedade);
  else if (v.cultura) partes.push(v.cultura);
  else if (v.talhao_nome) partes.push(v.talhao_nome);
  if (v.area_ha) partes.push(`${String(v.area_ha).replace('.', ',')} ha`);
  if (v.num_plantas) partes.push(`${v.num_plantas} plantas`);
  if (partes.length === 0 && v.codigo) partes.push(`Código ${v.codigo}`);
  return partes.join(' · ');
}

export default function ApontamentoScreen() {
  // válvulas/setores reais do cache offline (módulo 'talhoes' do sync)
  const { itens: valvulas, carregado: valvulasCarregadas } = useDadosSync('talhoes');
  // máquinas reais do cache offline (módulo 'maquinas' do sync)
  const { itens: maquinas, carregado: maquinasCarregadas } = useDadosSync('maquinas');
  const { sincronizarAgora } = useSync();
  const safraLabel = rotuloSafra(useSafraAtiva());

  const [passo, setPasso] = useState(0);
  const [tipo, setTipo] = useState(null);
  const [locais, setLocais] = useState([]); // ids das válvulas selecionadas

  // ficha 360° navega com { valvula } → pré-seleciona a válvula no wizard
  const route = useRoute();
  useEffect(() => {
    const v = route.params?.valvula;
    if (v?.id) setLocais((ls) => (ls.includes(v.id) ? ls : [...ls, v.id]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?.valvula?.id]);
  const [observacao, setObservacao] = useState('');

  // registro por VOZ (Onda 3): texto transcrito pré-preenche a observação
  // (sem sobrescrever o que já foi digitado) e o tipo detectado entra se
  // for um card válido do wizard ('irrigacao'/'desbrota' não são — ignora)
  useEffect(() => {
    const t = route.params?.vozTexto;
    if (t) setObservacao((o) => (o.trim() ? o : String(t)));
    const tp = route.params?.vozTipo;
    if (tp && ['poda', 'pulverizacao', 'adubacao', 'colheita', 'manutencao'].includes(tp)) {
      setTipo((atual) => atual || tp);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?.vozTexto, route.params?.vozTipo]);

  // Exceção do gestor (23/07): só a COLHEITA pode INICIAR pelo app. Com
  // soTipo o wizard trava naquele tipo e pula direto para as válvulas.
  // abertura NOVA pelos atalhos (_novo): zera a tela montada
  useEffect(() => {
    if (route.params?._novo) resetar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?._novo]);

  const soTipo = route.params?.soTipo === 'colheita' ? 'colheita' : null;
  // colheita PENDENTE (lançada no escritório): preenche o registro existente
  const registroId = route.params?.registroId || null;
  useEffect(() => {
    if (soTipo) {
      setTipo(soTipo);
      // com registro amarrado, a válvula já veio — direto pras quantidades
      setPasso((p) => (p === 0 ? (registroId ? 2 : 1) : p));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [soTipo, registroId]);

  // colheita por VOZ: classificações e unidade detectadas na fala entram
  // pré-preenchidas (o operador confere nos steppers antes de salvar)
  useEffect(() => {
    const cls = route.params?.vozClassificacoes;
    if (cls && typeof cls === 'object') {
      setQtdsClass((m) => {
        const novo = { ...m };
        for (const [k, v] of Object.entries(cls)) {
          if (!novo[k] && Number(v) > 0) novo[k] = String(v);
        }
        return novo;
      });
      const u = route.params?.vozUnidade;
      if (u === 'kg' || u === 'caixa') setUnidadeQtd(u);
      // com válvula detectada, já cai direto no passo das quantidades
      if (route.params?.valvula?.id) setPasso(2);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?.vozClassificacoes]);
  const [usouMaquina, setUsouMaquina] = useState(false);
  const [trator, setTrator] = useState(null); // id da máquina selecionada
  const [quantidade, setQuantidade] = useState('');   // C-09: plantas/peso
  // 23/07 (espelho da colheita do sistema): variedade primeiro + quantidade
  // colhida POR CLASSIFICAÇÃO (mesmas categorias de colheita/index.php)
  const [qtdsClass, setQtdsClass] = useState({}); // { premium|cat1|cat2|cat3|perdidos: texto }
  const [unidadeQtd, setUnidadeQtd] = useState(null); // 'kg' | 'caixa' (colheita)
  const [foto, setFoto] = useState(null);             // uri da foto (opcional)
  // 23/07: o toggle saiu — iniciar pelo app SEMPRE nasce em aberto ('iniciado')
  const emAberto = true;
  const [salvo, setSalvo] = useState(false);
  const [salvando, setSalvando] = useState(false);

  // T-13: unidade de colheita do tenant (kg | caixa | ambos) + kg por caixa
  const colheitaUnidade = String(useParametro('colheita.unidade', 'kg'));
  const unidadesColheita = colheitaUnidade === 'ambos' ? ['kg', 'caixa'] : [colheitaUnidade];
  const unidadeAtiva = unidadeQtd || unidadesColheita[0];

  // F3: válvulas escolhidas no passo "Onde" — alimentam o bloco de contexto
  const valvulasSelecionadas = useMemo(
    () => locais.map((id) => valvulas.find((v) => v.id === id)).filter(Boolean),
    [locais, valvulas]
  );

  // produção-só: a VARIEDADE sai da válvula escolhida (auto, como a safra)
  const variedadeSel = valvulasSelecionadas[0]?.variedade || valvulasSelecionadas[0]?.cultura || null;

  // categorias de classificação — espelho de colheita/index.php (CATEGORIAS)
  const CLASSIFICACOES = [
    { id: 'premium', rotulo: 'Premium' },
    { id: 'cat1', rotulo: 'CAT 1' },
    { id: 'cat2', rotulo: 'CAT 2' },
    { id: 'cat3', rotulo: 'CAT 3' },
    { id: 'perdidos', rotulo: 'Perdidos' },
  ];
  const decQ = (v) => {
    const n = Number(String(v || '').trim().replace(/\./g, '').replace(',', '.'));
    return Number.isFinite(n) && n > 0 ? n : 0;
  };
  const totalClassificado = CLASSIFICACOES.reduce((s, c) => s + decQ(qtdsClass[c.id]), 0);

  // peso da caixa do tenant, VISÍVEL no form (total em kg sai certo na hora)
  const pesoCaixaKg = Number(useParametro('colheita.peso_caixa_kg', 0)) || 0;
  const totalKg = unidadeAtiva === 'caixa'
    ? (pesoCaixaKg > 0 ? totalClassificado * pesoCaixaKg : null)
    : totalClassificado;
  const fmtBr = (n) => Number(n.toFixed(2)).toLocaleString('pt-BR');

  // stepper +/- por classificação (dedo grande, sol, luva)
  function ajustarClass(id, delta) {
    setQtdsClass((m) => {
      const atual = decQ(m[id]);
      const novo = Math.max(0, atual + delta);
      return { ...m, [id]: novo > 0 ? String(novo) : '' };
    });
  }

  async function tirarFoto() {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) return;
    const r = await ImagePicker.launchCameraAsync({ quality: 0.5, allowsEditing: false });
    if (!r.canceled && r.assets?.[0]?.uri) setFoto(r.assets[0].uri);
  }

  const podeAvancar = passo === 0 ? !!tipo
    : passo === 1 ? locais.length > 0
    : tipo === 'colheita' ? totalClassificado > 0 : true;

  function alternarLocal(id) {
    setLocais((ls) => (ls.includes(id) ? ls.filter((l) => l !== id) : [...ls, id]));
  }

  function resetar() {
    setPasso(registroId ? 2 : soTipo ? 1 : 0); setTipo(soTipo || null); setLocais([]); setObservacao('');
    setUsouMaquina(false); setTrator(null); setSalvo(false);
    setQuantidade(''); setQtdsClass({}); setUnidadeQtd(null); setFoto(null);
  }

  // Enfileira um apontamento por válvula selecionada; o sincronizador envia
  // depois (idempotente por client_uuid — reenvio nunca duplica).
  async function salvar() {
    if (salvando) return;
    setSalvando(true);
    try {
      const data = agoraSql();
      const nQtd = Number(quantidade.replace(/\./g, '').replace(',', '.'));
      // 7.1: geotag da operação — GPS do aparelho com teto de 4s; a coordenada
      // da fazenda (fallback do obterCoordenada) NÃO é enviada como se fosse GPS
      let gps = null;
      try {
        const c = await Promise.race([
          obterCoordenada(),
          new Promise((res) => setTimeout(() => res(null), 4000)),
        ]);
        if (c && !(c.latitude === FAZENDA.latitude && c.longitude === FAZENDA.longitude)) {
          gps = { latitude: c.latitude, longitude: c.longitude };
        }
      } catch (_) { /* sem GPS, segue sem geotag */ }
      // COLHEITA (gestor 23/07): vai DIRETO para a tela Colheita do sistema
      // (colheita_registros + classificações) — não passa por apontamento.
      // Com registroId (colheita PENDENTE lançada no escritório), PREENCHE o
      // realizado do registro existente em vez de criar um novo.
      if (tipo === 'colheita') {
        const v = valvulasSelecionadas[0];
        if ((v || registroId) && totalClassificado > 0) {
          const classificacoes = {};
          for (const c of CLASSIFICACOES) {
            const q = decQ(qtdsClass[c.id]);
            if (q > 0) classificacoes[c.id] = q;
          }
          if (registroId) {
            await enfileirar({
              tipo: 'colheita_realizado',
              rota: rotas.colheitaRealizadoId(registroId),
              metodo: 'POST',
              payload: {
                classificacoes,
                unidade: unidadeAtiva,
                observacao: observacao.trim() || undefined,
              },
            });
          } else {
            await enfileirar({
              tipo: 'colheita_registro',
              rota: rotas.colheitas,
              metodo: 'POST',
              payload: {
                setor_id: v.id,
                variedade_id: v.variedade_id || undefined,
                classificacoes,
                unidade: unidadeAtiva,
                observacao: observacao.trim() || undefined,
                data,
              },
            });
          }
        }
        setSalvo(true);
        sincronizarAgora().catch(() => {});
        return;
      }

      let primeiroUuid = null;
      for (const idSel of locais) {
        const v = valvulas.find((x) => x.id === idSel);
        if (!v) continue;
        const payload = {
          tipo: TIPO_SERVIDOR[tipo] || 'outro',
          // o setor/válvula aponta o talhão-pai; sem pai, usa o próprio id
          talhao_id: v.talhao_id || v.id,
          observacao: observacao.trim() || undefined,
          data,
        };
        if (gps) {
          payload.latitude = gps.latitude;
          payload.longitude = gps.longitude;
        }
        // 8.2: poda identificável no servidor → conta na Abertura de Safra
        if (tipo === 'poda') payload.atividade_rotulo = 'poda';
        // dois estágios: em aberto = status 'iniciado' (aparece na aba Tarefas
        // e no escritório em tempo real; conclui depois)
        if (emAberto) payload.estagio = 'iniciado';
        if (usouMaquina && trator) payload.maquina_id = trator;
        if (locais.length === 1 && Number.isFinite(nQtd) && nQtd > 0 && tipo === 'poda') {
          payload.quantidade = nQtd;
          payload.quantidade_unidade = 'plantas';
        }
        const uuid = await enfileirar({ tipo: 'apontamento', rota: rotas.apontamentos, metodo: 'POST', payload });
        if (!primeiroUuid) primeiroUuid = uuid;
      }
      // Foto (opcional): entra na fila DEPOIS do apontamento-pai (pai_uuid
      // garante a ordem) — o servidor resolve origem_uuid → id.
      if (foto && primeiroUuid) {
        await enfileirar({
          tipo: 'anexo',
          rota: rotas.anexos,
          metodo: 'POST',
          paiUuid: primeiroUuid,
          payload: { uri: foto, nome: 'apontamento.jpg', mime: 'image/jpeg', origem_uuid: primeiroUuid },
        });
      }
      setSalvo(true);
      // não bloqueia a tela de sucesso: se estiver offline, sobe depois sozinho
      sincronizarAgora().catch(() => {});
    } finally {
      setSalvando(false);
    }
  }

  if (salvo) {
    const tipoSel = TIPOS_SERVICO.find((t) => t.id === tipo);
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Registrar" sub={safraLabel || 'Apontamento de campo'} />
        <View style={styles.sucessoWrap}>
          <View style={styles.sucessoCard}>
            <Text style={styles.sucessoIcone}>{tipo === 'colheita' ? '🧺' : emAberto ? '▶' : '✓'}</Text>
            <Text style={styles.sucessoTitulo}>
              {tipo === 'colheita' ? 'Colheita registrada' : emAberto ? 'Serviço iniciado' : 'Apontamento salvo'}
            </Text>
            <Text style={styles.sucessoMsg}>
              {tipo === 'colheita'
                ? `${variedadeSel || 'Colheita'} · ${String(totalClassificado).replace('.', ',')} ${unidadeAtiva === 'caixa' ? 'caixas' : 'kg'}\n${registroId ? 'Produção preenchida no registro do escritório' : 'Já está na tela Colheita do sistema'} — o escritório confirma a entrada no estoque.`
                : `${tipoSel?.icone} ${tipoSel?.rotulo} · ${locais.length} ${locais.length === 1 ? 'local' : 'locais'}\n${emAberto
                  ? 'Está em aberto na aba Tarefas — conclua quando terminar.'
                  : 'Será enviado quando houver sinal.'}`}
            </Text>
            <Pressable style={[styles.btnPrim, { flex: 0, alignSelf: 'stretch' }]} onPress={resetar}>
              <Text style={styles.btnPrimTxt}>{tipo === 'colheita' ? 'Registrar outra variedade' : 'Registrar outro'}</Text>
            </Pressable>
          </View>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.tela}>
      <AppHeader titulo="Registrar" sub={safraLabel ? `Passo a passo · ${safraLabel}` : 'Passo a passo'} />

      {/* indicador de progresso */}
      <View style={styles.progresso}>
        {PASSOS.map((rotulo, i) => (
          <View key={rotulo} style={styles.progItem}>
            <View style={[styles.progBolinha, i === passo && styles.progAtiva, i < passo && styles.progFeita]}>
              <Text style={[styles.progNum, (i === passo || i < passo) && { color: cores.surface }]}>
                {i < passo ? '✓' : i + 1}
              </Text>
            </View>
            <Text style={[styles.progRotulo, i === passo && styles.progRotuloAtivo]}>{rotulo}</Text>
          </View>
        ))}
      </View>

      <ScrollView contentContainerStyle={styles.corpo} showsVerticalScrollIndicator={false}>
        {passo === 0 && (
          <View style={styles.grade}>
            {TIPOS_SERVICO.map((t) => {
              const ativo = tipo === t.id;
              return (
                <Pressable key={t.id} style={[styles.tipoCard, ativo && styles.tipoAtivo]} onPress={() => setTipo(t.id)}>
                  <Text style={styles.tipoIcone}>{t.icone}</Text>
                  <Text style={[styles.tipoRotulo, ativo && { color: cores.surface }]}>{t.rotulo}</Text>
                </Pressable>
              );
            })}
          </View>
        )}

        {passo === 1 && (
          <View style={{ gap: 10 }}>
            {valvulasCarregadas && valvulas.length === 0 ? (
              /* cache vazio: orienta a sincronizar em vez de quebrar */
              <View style={styles.cardBranco}>
                <Text style={styles.campoRotulo}>Nenhum local no aparelho</Text>
                <Text style={[styles.dica, { marginTop: 6 }]}>
                  Conecte-se à internet e sincronize para carregar as válvulas da fazenda.
                </Text>
              </View>
            ) : tipo === 'colheita' ? (
              /* produção-só (gestor 23/07): VÁLVULA primeiro — a variedade e a
                 safra vigente saem dela automaticamente (um registro por válvula) */
              <>
                <Text style={styles.dica}>De qual válvula saiu a fruta? A variedade e a safra entram sozinhas.</Text>
                {valvulas.map((l) => {
                  const ativo = locais.includes(l.id);
                  const detalhe = detalheDa(l);
                  return (
                    <Pressable key={l.id} style={[styles.localCard, ativo && styles.localAtivo]} onPress={() => setLocais([l.id])}>
                      <View style={[styles.check, ativo && styles.checkAtivo]}>
                        {ativo && <Text style={styles.checkTxt}>✓</Text>}
                      </View>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.localRotulo}>{l.nome}</Text>
                        {!!detalhe && <Text style={styles.localDetalhe}>{detalhe}</Text>}
                      </View>
                    </Pressable>
                  );
                })}
              </>
            ) : (
              <>
                <Text style={styles.dica}>Toque para marcar — pode escolher mais de um local.</Text>
                {valvulas.map((l) => {
                  const ativo = locais.includes(l.id);
                  const detalhe = detalheDa(l);
                  return (
                    <Pressable key={l.id} style={[styles.localCard, ativo && styles.localAtivo]} onPress={() => alternarLocal(l.id)}>
                      <View style={[styles.check, ativo && styles.checkAtivo]}>
                        {ativo && <Text style={styles.checkTxt}>✓</Text>}
                      </View>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.localRotulo}>{l.nome}</Text>
                        {!!detalhe && <Text style={styles.localDetalhe}>{detalhe}</Text>}
                      </View>
                    </Pressable>
                  );
                })}
              </>
            )}
          </View>
        )}

        {passo === 2 && (
          <View style={{ gap: 12 }}>
            {/* F3: contexto tracejado — o sistema já sabe variedade/área/plantas/fase */}
            <ContextoValvula valvulas={valvulasSelecionadas} />

            {/* espelho da colheita do sistema (23/07): quantidade colhida POR
                CLASSIFICAÇÃO (Premium/CAT 1/CAT 2/CAT 3/Perdidos) da variedade
                escolhida — mesmas categorias de colheita/index.php */}
            {tipo === 'colheita' && (
              <View style={styles.cardBranco}>
                <View style={styles.qtdCabecalho}>
                  <Text style={styles.campoRotulo}>
                    Quantidade colhida{variedadeSel ? ` — ${variedadeSel}` : ''}
                  </Text>
                  {unidadesColheita.length > 1 ? (
                    <View style={{ flexDirection: 'row', gap: 7 }}>
                      {unidadesColheita.map((u) => {
                        const ativa = unidadeAtiva === u;
                        return (
                          <Pressable key={u} style={[styles.chip, ativa && styles.chipAtivo]} onPress={() => setUnidadeQtd(u)}>
                            <Text style={[styles.chipTxt, ativa && { color: cores.surface }]}>{u === 'caixa' ? 'caixas' : 'kg'}</Text>
                          </Pressable>
                        );
                      })}
                    </View>
                  ) : (
                    <Text style={styles.qtdUnidade}>{unidadeAtiva === 'caixa' ? 'caixas' : 'kg'}</Text>
                  )}
                </View>
                {/* peso da caixa do tenant sempre à vista quando aponta em caixas */}
                {unidadeAtiva === 'caixa' && (
                  <Text style={[styles.dica, { marginTop: 6 }]}>
                    {pesoCaixaKg > 0
                      ? `1 caixa = ${fmtBr(pesoCaixaKg)} kg (config da fazenda)`
                      : '⚠️ Peso da caixa não configurado — grave colheita.peso_caixa_kg nos Parâmetros do Sistema.'}
                  </Text>
                )}
                <View style={{ gap: 9, marginTop: 11 }}>
                  {CLASSIFICACOES.map((c) => (
                    <View key={c.id} style={styles.qtdVarLinha}>
                      <Text style={[styles.qtdVarNome, c.id === 'perdidos' && { color: cores.danger }]}>
                        {c.rotulo}
                      </Text>
                      <Pressable style={styles.stepBtn} onPress={() => ajustarClass(c.id, -1)}>
                        <Text style={styles.stepBtnTxt}>−</Text>
                      </Pressable>
                      <TextInput
                        style={styles.qtdInput}
                        value={qtdsClass[c.id] || ''}
                        onChangeText={(t) => setQtdsClass((m) => ({ ...m, [c.id]: t }))}
                        keyboardType="numeric"
                        placeholder="0"
                        placeholderTextColor={cores.faint}
                      />
                      <Pressable style={[styles.stepBtn, styles.stepBtnMais]} onPress={() => ajustarClass(c.id, 1)}>
                        <Text style={[styles.stepBtnTxt, { color: cores.surface }]}>＋</Text>
                      </Pressable>
                    </View>
                  ))}
                </View>
                {/* total AO VIVO — caixas e kg juntos quando aponta em caixa */}
                <View style={styles.totalLinha}>
                  <Text style={styles.totalRotulo}>Total classificado</Text>
                  <Text style={styles.totalValor}>
                    {fmtBr(totalClassificado)} {unidadeAtiva === 'caixa' ? 'caixas' : 'kg'}
                    {unidadeAtiva === 'caixa' && totalKg !== null ? `  ·  ${fmtBr(totalKg)} kg` : ''}
                  </Text>
                </View>
              </View>
            )}

            <View style={styles.cardBranco}>
              <Text style={styles.campoRotulo}>Observação</Text>
              <TextInput
                style={styles.input}
                placeholder="Ex.: poda leve, ramos com boa brotação…"
                placeholderTextColor={cores.faint}
                value={observacao}
                onChangeText={setObservacao}
                multiline
              />
            </View>

            {tipo !== 'colheita' && (
            <Pressable style={styles.cardBranco} onPress={() => setUsouMaquina((v) => !v)}>
              <View style={styles.toggleLinha}>
                <Text style={styles.campoRotulo}>Usou máquina? 🚜</Text>
                <View style={[styles.toggle, usouMaquina && styles.toggleOn]}>
                  <View style={[styles.toggleBola, usouMaquina && styles.toggleBolaOn]} />
                </View>
              </View>
              {usouMaquina && (
                maquinasCarregadas && maquinas.length === 0 ? (
                  /* cache vazio: orienta a sincronizar */
                  <Text style={[styles.dica, { marginTop: 12 }]}>
                    Nenhuma máquina no aparelho — sincronize para carregar a frota.
                  </Text>
                ) : (
                  <View style={styles.tratores}>
                    {maquinas.map((m) => {
                      const ativo = trator === m.id;
                      return (
                        <Pressable key={m.id} style={[styles.chip, ativo && styles.chipAtivo]} onPress={() => setTrator(m.id)}>
                          <Text style={[styles.chipTxt, ativo && { color: cores.surface }]}>{m.nome}</Text>
                        </Pressable>
                      );
                    })}
                  </View>
                )
              )}
            </Pressable>
            )}

            {/* Foto do serviço (opcional) — sobe na fila depois do apontamento.
                Colheita não tem: o registro oficial do sistema não anexa foto. */}
            {tipo !== 'colheita' && (
            <View style={styles.cardBranco}>
              <Text style={styles.campoRotulo}>Foto (opcional) 📷</Text>
              {foto ? (
                <View style={styles.fotoLinha}>
                  <Image source={{ uri: foto }} style={styles.fotoPreview} />
                  <View style={{ flex: 1, gap: 8 }}>
                    <Pressable style={styles.fotoBtn} onPress={tirarFoto}>
                      <Text style={styles.fotoBtnTxt}>Tirar outra</Text>
                    </Pressable>
                    <Pressable style={styles.fotoBtn} onPress={() => setFoto(null)}>
                      <Text style={[styles.fotoBtnTxt, { color: cores.danger }]}>Remover</Text>
                    </Pressable>
                  </View>
                </View>
              ) : (
                <Pressable style={[styles.fotoBtn, { marginTop: 10 }]} onPress={tirarFoto}>
                  <Text style={styles.fotoBtnTxt}>📷 Tirar foto do serviço</Text>
                </Pressable>
              )}
            </View>
            )}
          </View>
        )}
      </ScrollView>

      {/* barra de navegação do wizard */}
      <View style={styles.rodape}>
        {passo > (registroId ? 2 : soTipo ? 1 : 0) && (
          <Pressable style={styles.btnSec} onPress={() => setPasso((p) => p - 1)}>
            <Text style={styles.btnSecTxt}>Voltar</Text>
          </Pressable>
        )}
        <Pressable
          style={[styles.btnPrim, { flex: 1.6 }, (!podeAvancar || salvando) && { opacity: 0.4 }]}
          disabled={!podeAvancar || salvando}
          onPress={() => (passo < 2 ? setPasso((p) => p + 1) : salvar())}
        >
          <Text style={styles.btnPrimTxt}>{passo < 2 ? 'Avançar' : 'Salvar apontamento'}</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  progresso: { flexDirection: 'row', paddingHorizontal: espaco.md, paddingVertical: 12, gap: 6 },
  progItem: { flex: 1, alignItems: 'center', gap: 5 },
  progBolinha: { width: 28, height: 28, borderRadius: 14, backgroundColor: cores.track, alignItems: 'center', justifyContent: 'center' },
  progAtiva: { backgroundColor: cores.accent },
  progFeita: { backgroundColor: cores.pos },
  progNum: { fontSize: 12, color: cores.muted2, fontFamily: fonte.sansBold },
  progRotulo: { fontSize: 10, color: cores.muted2, fontFamily: fonte.sansSemi },
  progRotuloAtivo: { color: cores.accent },
  corpo: { paddingHorizontal: espaco.md, paddingBottom: 24, gap: 10 },
  dica: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed },
  grade: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  tipoCard: {
    width: '48%', flexGrow: 1, backgroundColor: cores.surface, borderRadius: raio.card,
    paddingVertical: 20, alignItems: 'center', gap: 7,
  },
  tipoAtivo: { backgroundColor: cores.accent },
  tipoIcone: { fontSize: 26 },
  tipoRotulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  localCard: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    backgroundColor: cores.surface, borderRadius: raio.card, padding: 14,
  },
  localAtivo: { backgroundColor: cores.posBg },
  check: { width: 24, height: 24, borderRadius: 7, backgroundColor: cores.track, alignItems: 'center', justifyContent: 'center' },
  checkAtivo: { backgroundColor: cores.pos },
  checkTxt: { fontSize: 13, color: cores.surface, fontFamily: fonte.sansBold },
  localRotulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  localDetalhe: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  cardBranco: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14 },
  campoRotulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  qtdLinha: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 10 },
  variedades: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  totalLinha: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 12, paddingTop: 10, borderTopWidth: 1, borderTopColor: cores.border },
  totalRotulo: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansSemi },
  totalValor: { fontSize: 15, color: cores.ink, fontFamily: fonte.monoSemi },
  stepBtn: {
    width: 42, height: 42, borderRadius: raio.sm, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  stepBtnMais: { backgroundColor: cores.accent },
  stepBtnTxt: { fontSize: 19, color: cores.accent, fontFamily: fonte.sansBold, marginTop: -1 },
  qtdCabecalho: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  qtdVarLinha: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  qtdVarNome: { flex: 1, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  qtdVarSub: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  // 23/07: campos de quantidade padronizados e compactos
  qtdInput: {
    width: 88, height: 42, borderRadius: raio.sm, backgroundColor: cores.campo,
    paddingHorizontal: 10, fontSize: 15, color: cores.ink, fontFamily: fonte.monoSemi,
    textAlign: 'center',
  },
  qtdUnidade: { fontSize: 13.5, color: cores.muted, fontFamily: fonte.sansSemi },
  fotoLinha: { flexDirection: 'row', gap: 12, marginTop: 10, alignItems: 'center' },
  fotoPreview: { width: 96, height: 96, borderRadius: raio.sm, backgroundColor: cores.track },
  fotoBtn: {
    height: espaco.toque, borderRadius: raio.sm, backgroundColor: cores.posBg,
    alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14,
  },
  fotoBtnTxt: { fontSize: 12.5, color: cores.accent, fontFamily: fonte.sansBold },
  input: {
    marginTop: 9, minHeight: 88, backgroundColor: cores.campo, borderRadius: raio.sm,
    padding: 11, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed, textAlignVertical: 'top',
  },
  toggleLinha: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  toggle: { width: 46, height: 26, borderRadius: 13, backgroundColor: cores.track, padding: 3 },
  toggleOn: { backgroundColor: cores.pos },
  toggleBola: { width: 20, height: 20, borderRadius: 10, backgroundColor: cores.surface },
  toggleBolaOn: { alignSelf: 'flex-end' },
  tratores: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 },
  chip: {
    flexGrow: 1, minHeight: espaco.toque, paddingVertical: 11, paddingHorizontal: 12,
    borderRadius: raio.r, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  chipAtivo: { backgroundColor: cores.accent },
  chipTxt: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  rodape: { flexDirection: 'row', gap: 10, padding: espaco.md, paddingBottom: 22 },
  btnPrim: { flex: 1, height: 52, borderRadius: raio.r, backgroundColor: cores.accent, alignItems: 'center', justifyContent: 'center' },
  btnPrimTxt: { fontSize: 15, color: cores.surface, fontFamily: fonte.sansBold },
  btnSec: { flex: 1, height: 52, borderRadius: raio.r, backgroundColor: cores.surface, alignItems: 'center', justifyContent: 'center' },
  btnSecTxt: { fontSize: 15, color: cores.accent, fontFamily: fonte.sansBold },
  sucessoWrap: { flex: 1, justifyContent: 'center', padding: espaco.xl },
  sucessoCard: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 26, alignItems: 'center', gap: 10 },
  sucessoIcone: { fontSize: 40, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 17, color: cores.ink, fontFamily: fonte.sansBold },
  sucessoMsg: { fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 20, marginBottom: 8 },
});
