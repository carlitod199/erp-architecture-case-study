import React, { useEffect, useState } from 'react';
import { View, Text, Pressable, TextInput, ScrollView, StyleSheet } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSafraAtiva, rotuloSafra } from '../hooks/useSafraAtiva';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { useSync } from '../context/SyncContext';

// F1 (Onda 2) — EMISSÃO de DF pelo campo: o operador emite a ordem de
// pulverização (válvula → alvo → produtos do Auto de Controle → volume de
// calda → maquinário → data prevista → observação) em POST /aplicacoes.
// A execução/assinatura continua nas telas existentes (fila Aplicações).
// A DOSE NÃO É ENVIADA: o servidor herda do Auto de Controle do RT.

const dec = (v) => {
  const n = parseFloat(String(v || '').trim().replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

// vírgula decimal para exibição (padrão das outras telas)
const virg = (v) => String(v).replace('.', ',');

// carência (dias) da bula do produto no Auto de Controle — null quando o RT
// não registrou (sem dado, sem alerta: o sistema não inventa)
const carenciaDe = (p) => {
  const n = parseInt(p?.carencia_dias, 10);
  return Number.isFinite(n) ? n : null;
};

// 'YYYY-MM-DD' de N dias à frente (0 = hoje, 1 = amanhã), sem libs
function diaFrente(n) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  const p = (x) => String(x).padStart(2, '0');
  return {
    valor: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`,
    rotulo: n === 0 ? 'Hoje' : 'Amanhã',
  };
}
const DIAS = [diaFrente(0), diaFrente(1)];

// Contexto de vinhedo que o sistema já sabe da válvula (sync enriquecido)
function contextoDa(v) {
  const linhas = [];
  const variedade = v.variedade || v.cultura || null;
  if (variedade) linhas.push({ rotulo: 'Variedade', valor: variedade });
  if (v.area_ha) linhas.push({ rotulo: 'Área', valor: `${virg(v.area_ha)} ha` });
  if (v.num_plantas) linhas.push({ rotulo: 'Plantas', valor: String(v.num_plantas) });
  return linhas;
}

export default function PulverizarScreen() {
  const nav = useNavigation();
  const { sincronizarAgora } = useSync();
  const { itens: valvulas, carregado: valvulasCarregadas } = useDadosSync('talhoes');
  const { itens: alvos, carregado: alvosCarregados } = useDadosSync('mip_referencias');
  const { itens: maquinas, carregado: maquinasCarregadas } = useDadosSync('maquinas');
  const { itens: fenologias } = useDadosSync('fenologia');
  const { carregado: safrasCarregadas } = useDadosSync('safras');
  const safra = useSafraAtiva();

  const [valvula, setValvula] = useState(null);
  const [alvo, setAlvo] = useState(null);
  const [produtosSel, setProdutosSel] = useState([]); // ids em ORDEM de seleção

  // gancho Enviar → DF (23/07): o resumo da ronda navega com o alvo em
  // alerta e a válvula da pior leitura já pré-selecionados
  const route = useRoute();
  const [preAplicado, setPreAplicado] = useState(false);
  useEffect(() => {
    if (preAplicado) return;
    const alvoPre = route.params?.alvoPreId != null
      ? (alvos || []).find((a) => String(a.id) === String(route.params.alvoPreId))
      : null;
    const valvPre = route.params?.talhaoPreId != null
      ? (valvulas || []).find((v) => String(v.talhao_id || v.id) === String(route.params.talhaoPreId))
      : null;
    if (alvoPre || valvPre) {
      if (valvPre) setValvula(valvPre);
      if (alvoPre) { setAlvo(alvoPre); setProdutosSel([]); }
      setPreAplicado(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [alvos, valvulas, route.params?.alvoPreId, route.params?.talhaoPreId]);
  const [volume, setVolume] = useState('');           // L/ha (texto, vírgula ok)
  const [volumeEditado, setVolumeEditado] = useState(false);
  const [maquinasSel, setMaquinasSel] = useState([]);
  const [data, setData] = useState(DIAS[0].valor);
  const [observacao, setObservacao] = useState('');
  const [salvando, setSalvando] = useState(false);
  const [sucesso, setSucesso] = useState(false);
  const [dfEmitida, setDfEmitida] = useState(null); // DF de campo p/ emendar na confirmação

  const produtosDoAlvo = alvo?.produtos || [];

  // trocar de alvo zera os produtos (pertencem ao Auto de Controle dele)
  function escolherAlvo(a) {
    if (alvo?.id === a.id) return;
    setAlvo(a);
    setProdutosSel([]);
  }

  function toggleProduto(p) {
    setProdutosSel((prev) => (
      prev.includes(p.produto_id)
        ? prev.filter((id) => id !== p.produto_id)
        : [...prev, p.produto_id]
    ));
  }

  function toggleMaquina(m) {
    setMaquinasSel((prev) => (
      prev.includes(m.id) ? prev.filter((id) => id !== m.id) : [...prev, m.id]
    ));
  }

  // volume de calda sugerido = volume_calda_ha do PRIMEIRO produto escolhido;
  // some se desmarcar tudo — mas nunca sobrescreve o que o operador digitou
  useEffect(() => {
    if (volumeEditado) return;
    const primeiro = produtosDoAlvo.find((p) => p.produto_id === produtosSel[0]);
    setVolume(primeiro?.volume_calda_ha != null ? virg(primeiro.volume_calda_ha) : '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [produtosSel, alvo?.id]);

  const semSafra = safrasCarregadas && !safra;
  const pronto = !!valvula && !!alvo && produtosSel.length > 0;

  // F1 — alerta de LMR/carência (mesma régua do C-36 do web, mip/aplicacoes.php):
  // dias até a colheita = colheita_dia_inicio (fase de colheita da fenologia
  // aprovada da variedade) − dias_desde_poda da válvula; se a carência do
  // produto passa disso, o resíduo pode chegar na colheita. É AVISO (âmbar),
  // nunca bloqueio — o operador decide. Sem fenologia/colheita/carência → nada.
  const feno = valvula
    ? (fenologias || []).find((f) => String(f.talhao_id) === String(valvula.talhao_id || valvula.id)) || null
    : null;
  const diasAteColheita = (feno && feno.colheita_dia_inicio != null && feno.dias_desde_poda != null)
    ? Number(feno.colheita_dia_inicio) - Number(feno.dias_desde_poda)
    : null;
  const avisosLmr = diasAteColheita == null ? [] : produtosDoAlvo
    .filter((p) => produtosSel.includes(p.produto_id))
    .map((p) => ({ p, carencia: carenciaDe(p) }))
    .filter(({ carencia }) => carencia != null && carencia > diasAteColheita);

  async function emitir() {
    if (salvando || !pronto) return;
    setSalvando(true);
    try {
      // captura o client_uuid da emissão — a confirmação/assinatura resolvem
      // a DF por ele (Lacuna 2: emenda emissão→confirmação offline)
      const emissaoUuid = await enfileirar({
        tipo: 'aplicacao_emitir',
        rota: rotas.aplicacaoEmitir,
        metodo: 'POST',
        // SEM dose: o servidor herda do Auto de Controle do RT (contrato F1)
        payload: {
          talhao_id: valvula.talhao_id || valvula.id,
          alvo_id: alvo.id,
          itens: produtosSel.map((produto_id) => ({ produto_id })),
          volume_calda_ha_l: dec(volume) ?? undefined,
          maquinas: maquinasSel,
          observacao: observacao.trim() || undefined,
          data_prevista: data,
          tipo: 'pulverizacao',
        },
      });

      // DF "de campo" p/ emendar na confirmação — itens com os dados que o
      // operador viu (produto/dose/carência/reentrada do Auto de Controle)
      const itensDf = produtosDoAlvo
        .filter((p) => produtosSel.includes(p.produto_id))
        .map((p) => ({
          produto_id: p.produto_id,
          produto: p.nome,
          dose_valor: p.dose,
          dose_unidade: p.dose_unidade,
          carencia_dias: carenciaDe(p),
          intervalo_reentrada_horas: p.reentrada_horas ?? p.intervalo_reentrada_horas ?? null,
        }));
      setDfEmitida({
        emissao_uuid: emissaoUuid,
        talhao_nome: valvula.nome,
        talhao_id: valvula.talhao_id || valvula.id,
        area_aplicada_ha: valvula.area_ha,
        tipo: 'pulverizacao',
        volume_calda_ha_l: dec(volume) ?? undefined,
        data_prevista: data,
        itens: itensDf,
      });
      setSucesso(true);
      // não bloqueia o sucesso: offline, a ordem sobe depois sozinha
      sincronizarAgora().catch(() => {});
    } finally {
      setSalvando(false);
    }
  }

  const contexto = valvula ? contextoDa(valvula) : [];

  // ───────────────────────── sucesso ─────────────────────────
  if (sucesso) {
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Pulverizar" sub={valvula?.nome || 'Emitir DF'} />
        <View style={styles.sucessoWrap}>
          <View style={styles.sucessoCard}>
            <Text style={styles.sucessoIcone}>✓</Text>
            <Text style={styles.sucessoTitulo}>DF emitida</Text>
            <Text style={styles.sucessoMsg}>
              Você acabou de aplicar? Confirme a execução agora — é aí que o
              estoque baixa. Ou deixe na fila Aplicações para confirmar depois.
            </Text>
            {/* Lacuna 2: emenda direto na confirmação (ele acabou de aplicar) */}
            <Pressable
              style={styles.btnPrim}
              onPress={() => nav.navigate('TarefasTab', { screen: 'AplicacaoConfirmar', params: { df: dfEmitida } })}
            >
              <Text style={styles.btnPrimTxt}>Confirmar execução agora</Text>
            </Pressable>
            <Pressable style={styles.btnSec} onPress={() => nav.goBack()}>
              <Text style={styles.btnSecTxt}>Confirmar depois</Text>
            </Pressable>
          </View>
        </View>
      </View>
    );
  }

  // ───────────────────────── formulário ─────────────────────────
  return (
    <View style={styles.tela}>
      <AppHeader
        titulo="Pulverizar"
        sub={rotuloSafra(safra) ? `${rotuloSafra(safra)} · emitir DF` : 'Emitir ordem de pulverização (DF)'}
      />

      <ScrollView contentContainerStyle={styles.corpo} showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
        {/* aviso preventivo: a API recusa com 422 'sem_safra' */}
        {semSafra && (
          <View style={styles.avisoSafra}>
            <Text style={styles.avisoSafraTitulo}>⚠️ Sem safra em andamento</Text>
            <Text style={styles.avisoSafraTxt}>
              O servidor recusa a emissão de DF sem safra aberta. Abra a safra no VERO web (ou sincronize se ela já existe).
            </Text>
          </View>
        )}

        {/* 1. Válvula */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Válvula *</Text>
          {valvulasCarregadas && valvulas.length === 0 ? (
            <Text style={styles.vazio}>
              Nenhuma válvula no aparelho — conecte-se e sincronize para carregar as válvulas da fazenda.
            </Text>
          ) : (
            <View style={styles.chips}>
              {(valvulas || []).map((v) => {
                const ativa = valvula?.id === v.id;
                return (
                  <Pressable key={v.id} style={[styles.chip, ativa && styles.chipAtivo]} onPress={() => setValvula(v)}>
                    <Text style={[styles.chipTxt, ativa && styles.chipTxtAtivo]}>{v.nome}</Text>
                  </Pressable>
                );
              })}
            </View>
          )}

          {/* bloco de contexto — o que o sistema já sabe da válvula */}
          {!!valvula && contexto.length > 0 && (
            <View style={styles.contexto}>
              <Text style={styles.contextoTitulo}>O sistema já sabe</Text>
              {contexto.map((l) => (
                <View key={l.rotulo} style={styles.contextoLinha}>
                  <Text style={styles.contextoRotulo}>{l.rotulo}</Text>
                  <Text style={styles.contextoValor}>{l.valor}</Text>
                </View>
              ))}
            </View>
          )}
        </View>

        {/* 2. Alvo */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Alvo *</Text>
          {alvosCarregados && alvos.length === 0 ? (
            <Text style={styles.vazio}>
              Nenhum alvo do RT no aparelho — sincronize para carregar as referências MIP.
            </Text>
          ) : (
            <View style={styles.chips}>
              {(alvos || []).map((a) => {
                const ativo = alvo?.id === a.id;
                return (
                  <Pressable key={a.id} style={[styles.chip, ativo && styles.chipAtivo]} onPress={() => escolherAlvo(a)}>
                    <Text style={[styles.chipTxt, ativo && styles.chipTxtAtivo]}>{a.nome}</Text>
                    {a.nivel_acao != null && (
                      <Text style={[styles.chipLegenda, ativo && styles.chipTxtAtivo]}>
                        nível de ação: {virg(a.nivel_acao)}
                      </Text>
                    )}
                  </Pressable>
                );
              })}
            </View>
          )}
        </View>

        {/* 3. Produtos do Auto de Controle do alvo (seleção múltipla) */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Produto *</Text>
          {!alvo ? (
            <Text style={styles.vazio}>Escolha o alvo para ver os produtos indicados pelo RT.</Text>
          ) : produtosDoAlvo.length === 0 ? (
            <Text style={[styles.vazio, { color: cores.amberDeep }]}>
              O RT não cadastrou produtos para este alvo no Auto de Controle — sem produto não há DF; fale com o escritório.
            </Text>
          ) : (
            <View style={{ marginTop: 10, gap: 8 }}>
              {produtosDoAlvo.map((p) => {
                const ativo = produtosSel.includes(p.produto_id);
                return (
                  <Pressable
                    key={p.produto_id}
                    style={[styles.produto, ativo && styles.produtoAtivo]}
                    onPress={() => toggleProduto(p)}
                  >
                    <View style={[styles.marca, ativo && styles.marcaAtiva]}>
                      {ativo && <Text style={styles.marcaTxt}>✓</Text>}
                    </View>
                    <Text style={[styles.produtoNome, ativo && { color: cores.accent }]}>{p.nome}</Text>
                    {carenciaDe(p) != null && (
                      <Text style={styles.produtoCarencia}>carência {carenciaDe(p)} d</Text>
                    )}
                    {p.dose != null && (
                      <Text style={styles.produtoDose}>
                        {virg(p.dose)}{p.dose_unidade ? ` ${p.dose_unidade}` : ''}
                      </Text>
                    )}
                  </Pressable>
                );
              })}
              {/* F1: risco de LMR — um aviso âmbar por produto em risco (nunca bloqueia) */}
              {avisosLmr.map(({ p, carencia }) => (
                <View key={`lmr-${p.produto_id}`} style={styles.avisoLmr}>
                  <Text style={styles.avisoLmrTxt}>
                    ⚠️ {p.nome}: carência de {carencia} dias, colheita prevista em ~{Math.max(0, diasAteColheita)} dias — risco de resíduo (LMR)
                  </Text>
                </View>
              ))}
              <Text style={styles.dica}>
                A dose de cada produto é a do Auto de Controle do RT — o servidor herda, aqui você não altera.
              </Text>
            </View>
          )}
        </View>

        {/* 4. Volume de calda */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Volume de calda (L/ha)</Text>
          <TextInput
            style={styles.input}
            value={volume}
            onChangeText={(t) => { setVolumeEditado(true); setVolume(t); }}
            keyboardType="numeric"
            placeholder="0,0"
            placeholderTextColor={cores.faint}
          />
          <Text style={styles.dica}>sugerido pelo primeiro produto escolhido — ajuste se precisar</Text>
        </View>

        {/* 5. Maquinário (opcional, múltiplo) */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Maquinário 🚜</Text>
          {maquinasCarregadas && maquinas.length === 0 ? (
            <Text style={styles.vazio}>Nenhuma máquina no aparelho — opcional, dá para emitir sem maquinário.</Text>
          ) : (
            <View style={styles.chips}>
              {(maquinas || []).map((m) => {
                const ativo = maquinasSel.includes(m.id);
                return (
                  <Pressable key={m.id} style={[styles.chip, ativo && styles.chipAtivo]} onPress={() => toggleMaquina(m)}>
                    <Text style={[styles.chipTxt, ativo && styles.chipTxtAtivo]}>{m.nome}</Text>
                  </Pressable>
                );
              })}
            </View>
          )}
        </View>

        {/* 6. Data prevista */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Data prevista</Text>
          <View style={styles.chips}>
            {DIAS.map((d) => {
              const ativa = data === d.valor;
              return (
                <Pressable key={d.valor} style={[styles.chip, ativa && styles.chipAtivo]} onPress={() => setData(d.valor)}>
                  <Text style={[styles.chipTxt, ativa && styles.chipTxtAtivo]}>{d.rotulo}</Text>
                </Pressable>
              );
            })}
          </View>
        </View>

        {/* 7. Observação */}
        <View style={styles.cardBranco}>
          <Text style={styles.campoRotulo}>Observação</Text>
          <TextInput
            style={styles.inputMulti}
            placeholder="Ex.: focos concentrados na cabeceira norte…"
            placeholderTextColor={cores.faint}
            value={observacao}
            onChangeText={setObservacao}
            multiline
          />
        </View>
      </ScrollView>

      {/* botão primário único — o rótulo diz o que falta */}
      <View style={styles.rodape}>
        <Pressable
          style={[styles.btnPrim, (salvando || !pronto) && { opacity: 0.4 }]}
          disabled={salvando || !pronto}
          onPress={emitir}
        >
          <Text style={styles.btnPrimTxt}>
            {!valvula ? 'Escolha a válvula'
              : !alvo ? 'Escolha o alvo'
              : produtosSel.length === 0 ? 'Escolha ao menos um produto'
              : 'Emitir DF de pulverização'}
          </Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  corpo: { paddingHorizontal: espaco.md, paddingTop: espaco.md, paddingBottom: espaco.xl, gap: espaco.sm },

  avisoSafra: { backgroundColor: cores.amberBg, borderRadius: raio.card, padding: espaco.md },
  avisoSafraTitulo: { fontSize: 13, color: cores.amberDeep, fontFamily: fonte.sansBold },
  avisoSafraTxt: { fontSize: 11.5, color: cores.amberDeep, fontFamily: fonte.sansMed, marginTop: 4, lineHeight: 16 },

  cardBranco: { backgroundColor: cores.surface, borderRadius: raio.card, padding: espaco.md },
  campoRotulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  vazio: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 8, lineHeight: 17 },
  dica: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 6, lineHeight: 15 },

  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  chip: {
    minHeight: espaco.toque, paddingHorizontal: espaco.md, paddingVertical: espaco.xs,
    borderRadius: raio.pill, backgroundColor: cores.campo, justifyContent: 'center',
  },
  chipAtivo: { backgroundColor: cores.accent },
  chipTxt: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  chipTxtAtivo: { color: cores.surface },
  chipLegenda: { fontSize: 10, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },

  // bloco de contexto da válvula — cartão tracejado teal
  contexto: {
    marginTop: espaco.sm, borderWidth: 1, borderStyle: 'dashed', borderColor: cores.accent,
    borderRadius: raio.sm, backgroundColor: cores.warm, padding: espaco.sm, gap: 4,
  },
  contextoTitulo: { fontSize: 10, letterSpacing: 0.8, textTransform: 'uppercase', color: cores.accent, fontFamily: fonte.sansBold, marginBottom: 2 },
  contextoLinha: { flexDirection: 'row', justifyContent: 'space-between' },
  contextoRotulo: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed },
  contextoValor: { fontSize: 11.5, color: cores.ink, fontFamily: fonte.monoSemi },

  // linha de produto (multi-seleção com dose read-only)
  produto: {
    minHeight: espaco.toque, flexDirection: 'row', alignItems: 'center', gap: espaco.sm,
    borderRadius: raio.r, backgroundColor: cores.campo, paddingHorizontal: espaco.sm,
  },
  produtoAtivo: { backgroundColor: cores.posBg },
  marca: {
    width: 22, height: 22, borderRadius: raio.sm, borderWidth: 1.5, borderColor: cores.border2,
    backgroundColor: cores.surface, alignItems: 'center', justifyContent: 'center',
  },
  marcaAtiva: { backgroundColor: cores.accent, borderColor: cores.accent },
  marcaTxt: { fontSize: 13, color: cores.surface, fontFamily: fonte.sansBold },
  produtoNome: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  produtoCarencia: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.monoSemi },
  produtoDose: { fontSize: 12, color: cores.muted, fontFamily: fonte.monoSemi },

  // F1: aviso âmbar de risco de LMR por produto (mesma família do avisoSafra)
  avisoLmr: { backgroundColor: cores.amberBg, borderRadius: raio.sm, padding: espaco.sm },
  avisoLmrTxt: { fontSize: 11.5, color: cores.amberDeep, fontFamily: fonte.sansSemi, lineHeight: 16 },

  input: {
    marginTop: 9, height: espaco.toque, borderRadius: raio.sm, backgroundColor: cores.campo,
    paddingHorizontal: espaco.sm, fontSize: 16, color: cores.ink, fontFamily: fonte.monoSemi,
  },
  inputMulti: {
    marginTop: 9, minHeight: 72, borderRadius: raio.sm, backgroundColor: cores.campo,
    padding: espaco.sm, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed, textAlignVertical: 'top',
  },

  rodape: { padding: espaco.md, paddingBottom: espaco.lg + 4 },
  btnPrim: {
    height: 52, borderRadius: raio.r, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center', alignSelf: 'stretch',
  },
  btnSec: { minHeight: 44, borderRadius: raio.r, alignItems: 'center', justifyContent: 'center', marginTop: 4 },
  btnSecTxt: { fontSize: 13, color: cores.muted, fontFamily: fonte.sansBold },
  btnPrimTxt: { fontSize: 15, color: cores.surface, fontFamily: fonte.sansBold },

  // sucesso — mesmo padrão do MonitoramentoScreen
  sucessoWrap: { flex: 1, justifyContent: 'center', padding: espaco.xl },
  sucessoCard: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 26, alignItems: 'center', gap: espaco.sm },
  sucessoIcone: { fontSize: 40, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 17, color: cores.ink, fontFamily: fonte.sansBold },
  sucessoMsg: { fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 20, marginBottom: 8 },
});
