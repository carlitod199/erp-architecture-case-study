import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, Pressable, Modal, FlatList, StyleSheet } from 'react-native';
import AppHeader from '../components/AppHeader';
import { Tela, Cartao, Chip, Input, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';
import { useDadosSync } from '../hooks/useDadosSync';
import { useParametro } from '../hooks/useParametro';
import { cores, fonte, raio, espaco } from '../theme';

// Calculadora de mão de obra — ESPELHO do painel do apontamento do sistema
// (agro/_calc_mo_painel.php, pedido 23/07). Sugestão de planejamento, nada é
// gravado. Estrutura idêntica ao web:
//   contexto (tipo de atividade + válvula) → Planejamento (total a fazer c/
//   unidade, colheita por kg→caixas, meta e premiação semeadas da regra
//   vigente, base dias→pessoas ou pessoas→dias) → card de RESULTADO
//   (diárias · pessoas/dias · custo própria/terceirizada · premiação est.)
// Cadeia do rendimento: OBSERVADO dos apontamentos reais > referência do RT.

const UNIDADES = ['planta', 'ha', 'cacho', 'caixa', 'kg', 'contentor', 'hora'];
const PESO_CONTENTOR_PADRAO = 20; // WP-CALC Z-05: default do contentor quando a cultura não define

const dec = (v) => {
  const n = Number(String(v ?? '').trim().replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) && n > 0 ? n : 0;
};
const fmt2 = (n) => n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const int0 = (n) => n.toLocaleString('pt-BR', { maximumFractionDigits: 0 });
const numBR = (n) => n.toLocaleString('pt-BR', { maximumFractionDigits: 3 });

export default function CalculadoraScreen() {
  const { itens: tiposCrus, carregado: tiposOk } = useDadosSync('calc_parametros');
  // o cache devolve na ordem do id (texto) — a lista do select é ALFABÉTICA
  const tipos = useMemo(
    () => [...(tiposCrus || [])].sort((a, b) => String(a.nome).localeCompare(String(b.nome), 'pt-BR')),
    [tiposCrus]
  );
  const { itens: valvulas } = useDadosSync('talhoes');
  const pesoCxTenant = Number(useParametro('colheita.peso_caixa_kg', 0)) || 0;

  const [tipoId, setTipoId] = useState(null);
  const [seletorAberto, setSeletorAberto] = useState(false);
  const [valvulaId, setValvulaId] = useState(null);
  const [unidade, setUnidade] = useState('');
  const [trab, setTrab] = useState('');
  const [prodKg, setProdKg] = useState('');
  const [pesoCx, setPesoCx] = useState('');
  const [meta, setMeta] = useState('');
  const [premio, setPremio] = useState('');
  const [base, setBase] = useState('prazo'); // 'prazo' (dias→pessoas) | 'pessoas'
  const [dias, setDias] = useState('');
  const [pessoas, setPessoas] = useState('');

  const tipo = useMemo(() => (tipos || []).find((t) => t.id === tipoId) || null, [tipos, tipoId]);
  const valvula = useMemo(() => (valvulas || []).find((v) => v.id === valvulaId) || null, [valvulas, valvulaId]);
  // WP-CALC Z-05: contentor é colheita por peso; usaPeso = converte kg→unidade pelo peso
  const ehColheita = unidade === 'caixa' || unidade === 'kg' || unidade === 'contentor';
  const usaPeso = unidade === 'caixa' || unidade === 'contentor';

  // trocar o TIPO: pré-seleciona a unidade padrão e semeia meta/premiação da
  // regra vigente (editáveis — o template só semeia, igual ao web)
  useEffect(() => {
    if (!tipo) return;
    if (tipo.unidade_padrao && UNIDADES.includes(tipo.unidade_padrao)) setUnidade(tipo.unidade_padrao);
    setMeta(tipo.premio_meta > 0 ? numBR(tipo.premio_meta) : '');
    setPremio(tipo.premio_valor > 0 ? numBR(tipo.premio_valor) : '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tipoId]);

  // auto-preenchimento pela VÁLVULA conforme a unidade (espelho do autoFill)
  useEffect(() => {
    if (!valvula) return;
    if (unidade === 'planta') {
      setTrab(valvula.num_plantas > 0 ? int0(Number(valvula.num_plantas)) : '');
    } else if (unidade === 'ha') {
      setTrab(valvula.area_ha > 0 ? String(valvula.area_ha).replace('.', ',') : '');
    } else if (unidade === 'cacho') {
      // RALEIO (WP-CALC Z-06): cachos = nº de plantas × cachos_por_planta da variedade
      const plantas = Number(valvula.num_plantas) || 0;
      const cpp = Number(valvula.cachos_por_planta) || 0;
      if (plantas > 0 && cpp > 0) setTrab(int0(plantas * cpp));
    } else if (ehColheita) {
      // colheita: a válvula só SUGERE produção prevista e peso da caixa/contentor
      const prod = Number(valvula.prod_kg_ha) || 0;
      const area = Number(valvula.area_ha) || 0;
      if (prod > 0 && area > 0) setProdKg(int0(Math.round(prod * area)));
      if (usaPeso && !dec(pesoCx)) {
        const peso = unidade === 'contentor'
          ? (Number(valvula.peso_contentor_cultura) || PESO_CONTENTOR_PADRAO)
          : (pesoCxTenant > 0 ? pesoCxTenant : (Number(valvula.peso_caixa_cultura) || 0));
        if (peso > 0) setPesoCx(fmt2(peso));
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [valvulaId, unidade]);

  // colheita: kg previstos → total a fazer (caixas/contentores = kg ÷ peso, ceil; kg direto)
  let notaColheita = null;
  let trabEfetivo = dec(trab);
  if (ehColheita) {
    const kg = dec(prodKg);
    if (kg <= 0) {
      notaColheita = 'Colheita: informe a produção prevista da área (kg) — o cálculo é por quilo, não por planta.';
      trabEfetivo = 0;
    } else if (unidade === 'kg') {
      trabEfetivo = kg;
      notaColheita = `${int0(kg)} kg previstos — total a fazer em kg (previsão, ajustável).`;
    } else {
      // caixa e contentor: total = kg ÷ peso (ceil) — só muda o rótulo/fonte do peso
      const un = unidade === 'contentor' ? 'contentor' : 'caixa';
      const peso = dec(pesoCx);
      if (peso <= 0) {
        notaColheita = `Informe o peso do ${un} (kg) para converter os ${int0(kg)} kg previstos em ${un}s.`;
        trabEfetivo = 0;
      } else {
        trabEfetivo = Math.ceil(kg / peso);
        notaColheita = `≈ ${int0(trabEfetivo)} ${un}s ← ${int0(kg)} kg ÷ ${fmt2(peso)} kg/${un} (previsão — ajustável).`;
      }
    }
  }

  // RALEIO (WP-CALC Z-06): nota de origem do total de cachos (plantas × cachos/planta)
  let notaCacho = null;
  if (unidade === 'cacho' && valvula) {
    const plantas = Number(valvula.num_plantas) || 0;
    const cpp = Number(valvula.cachos_por_planta) || 0;
    if (plantas > 0 && cpp > 0) {
      notaCacho = `${int0(plantas)} plantas × ${numBR(cpp)} cachos = ${int0(plantas * cpp)} cachos (ajustável).`;
    } else if (plantas > 0) {
      notaCacho = 'Cadastre "cachos por planta" na variedade desta válvula para estimar o total de cachos.';
    }
  }

  // cadeia do rendimento: OBSERVADO (apontamentos reais) > referência do RT
  const rend = tipo
    ? (tipo.rend_observado > 0
        ? { valor: tipo.rend_observado, fonte: 'observado', n: tipo.obs_diarias }
        : tipo.rendimento > 0 ? { valor: tipo.rendimento, fonte: 'referencia', n: 0 } : null)
    : null;
  const fator = tipo && tipo.fator > 0 ? tipo.fator : 1;

  // resultado (fórmula do painel): diárias = total ÷ (meta || rendimento) × fator
  let resultado = null;
  if (tipo && rend && trabEfetivo > 0) {
    const metaVal = dec(meta);
    const baseDim = metaVal > 0 ? metaVal : rend.valor;
    const diarias = (trabEfetivo / baseDim) * fator;
    const nDias = dec(dias);
    const nPessoas = dec(pessoas);
    const dimensao = base === 'prazo'
      ? { rotulo: 'Pessoas', valor: nDias > 0 ? Math.ceil(diarias / nDias) : null, sub: nDias > 0 ? `em ${int0(nDias)} dia(s)` : 'informe os dias' }
      : { rotulo: 'Dias', valor: nPessoas > 0 ? Math.ceil(diarias / nPessoas) : null, sub: nPessoas > 0 ? `com ${int0(nPessoas)} pessoa(s)` : 'informe as pessoas' };

    // premiação estimada: excedente acima da meta × diárias reais × tarifa
    const tarifa = dec(premio);
    let premiacao = 0;
    if (metaVal > 0 && tarifa > 0 && rend.valor > metaVal) {
      const diariasReais = (trabEfetivo / rend.valor) * fator;
      premiacao = (rend.valor - metaVal) * diariasReais * tarifa;
    }

    resultado = {
      diarias,
      dimensao,
      custoPropria: tipo.custo_propria > 0 ? diarias * tipo.custo_propria : null,
      custoTerceirizada: tipo.custo_terceirizada > 0 ? diarias * tipo.custo_terceirizada : null,
      premiacao: premiacao > 0 ? premiacao : null,
      rodape: (dec(meta) > 0
        ? `equipe dimensionada pela meta de ${fmt2(dec(meta))} ${unidade || 'un'}/pessoa/dia`
        : rend.fonte === 'observado'
          ? `rendimento observado: ${fmt2(rend.valor)}/dia · base ${int0(rend.n)} diárias`
          : 'rendimento de referência (ajustável pelo RT)')
        + (fator !== 1 ? ` · fator ${fmt2(fator)}` : '') + '.',
    };
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Calculadora de mão de obra" sub="sugestão de planejamento — não grava nada" />
      <Tela>
        {/* Contexto: tipo de atividade em SELECT (pedido 23/07 — todas as
            atividades do sistema, ordenadas) + válvula */}
        <Cartao>
          <Eyebrow>Tipo de atividade *</Eyebrow>
          {tiposOk && (tipos || []).length === 0 ? (
            <Text style={styles.dica}>Sincronize para carregar os tipos de atividade.</Text>
          ) : (
            <Pressable style={styles.select} onPress={() => setSeletorAberto(true)}>
              <Text style={[styles.selectTxt, !tipo && { color: cores.faint }]}>
                {tipo ? tipo.nome : '— Selecione a atividade —'}
              </Text>
              <Text style={styles.selectSeta}>▾</Text>
            </Pressable>
          )}

          {/* lista completa das atividades do sistema */}
          <Modal visible={seletorAberto} transparent animationType="fade" onRequestClose={() => setSeletorAberto(false)}>
            <Pressable style={styles.veu} onPress={() => setSeletorAberto(false)}>
              <View style={styles.folha}>
                <Text style={styles.folhaTitulo}>Tipo de atividade</Text>
                <FlatList
                  data={tipos || []}
                  keyExtractor={(t) => String(t.id)}
                  style={{ maxHeight: 420 }}
                  renderItem={({ item: t }) => (
                    <Pressable
                      style={[styles.opcao, tipoId === t.id && styles.opcaoAtiva]}
                      onPress={() => { setTipoId(t.id); setSeletorAberto(false); }}
                    >
                      <Text style={[styles.opcaoTxt, tipoId === t.id && { color: cores.accent, fontFamily: fonte.sansBold }]}>
                        {t.nome}
                      </Text>
                      {!!t.unidade_padrao && <Text style={styles.opcaoSub}>{t.unidade_padrao}</Text>}
                    </Pressable>
                  )}
                />
              </View>
            </Pressable>
          </Modal>
          <View style={{ marginTop: 13 }}>
            <Eyebrow>Válvula (auto-preenche o total)</Eyebrow>
          </View>
          <View style={styles.chips}>
            {(valvulas || []).map((v) => {
              const ativo = valvulaId === v.id;
              return (
                <Chip key={v.id} selecionado={ativo} onPress={() => setValvulaId(ativo ? null : v.id)}>{v.nome}</Chip>
              );
            })}
          </View>
        </Cartao>

        {/* Planejamento — grid do painel */}
        <Cartao>
          <Eyebrow>Planejamento</Eyebrow>

          <View style={styles.linhaDupla}>
            <Input
              rotulo="Total a fazer"
              style={styles.campo}
              estiloCampo={styles.inputNum}
              value={ehColheita ? (trabEfetivo > 0 ? int0(trabEfetivo) : '') : trab}
              onChangeText={setTrab}
              editable={!ehColheita}
              keyboardType="numeric"
              placeholder="0"
            />
            <View style={styles.campo}>
              <Text style={styles.rotulo}>Unidade</Text>
              <View style={[styles.chips, { marginTop: 0 }]}>
                {UNIDADES.map((u) => (
                  <Chip key={u} selecionado={unidade === u} onPress={() => setUnidade(u)}>{u}</Chip>
                ))}
              </View>
            </View>
          </View>

          {/* colheita por QUILO (regra 22/07) */}
          {ehColheita && (
            <View style={styles.linhaDupla}>
              <Input rotulo="Produção prevista (kg na área)" style={styles.campo} estiloCampo={styles.inputNum} value={prodKg} onChangeText={setProdKg} keyboardType="numeric" placeholder="ex.: 40.000" />
              {usaPeso && (
                <Input rotulo={unidade === 'contentor' ? 'Peso do contentor (kg)' : 'Peso da caixa (kg)'} style={styles.campo} estiloCampo={styles.inputNum} value={pesoCx} onChangeText={setPesoCx} keyboardType="numeric" placeholder="ex.: 22,00" />
              )}
            </View>
          )}
          {!!notaColheita && <Text style={styles.dica}>{notaColheita}</Text>}
          {!!notaCacho && <Text style={styles.dica}>{notaCacho}</Text>}

          <View style={styles.linhaDupla}>
            <Input rotulo="Meta (por pessoa/dia)" style={styles.campo} estiloCampo={styles.inputNum} value={meta} onChangeText={setMeta} keyboardType="numeric" placeholder="—" />
            <Input rotulo="Premiação (R$/un. acima)" style={styles.campo} estiloCampo={styles.inputNum} value={premio} onChangeText={setPremio} keyboardType="numeric" placeholder="—" />
          </View>

          <Text style={styles.rotulo}>Base do cálculo</Text>
          <View style={styles.chips}>
            {[{ id: 'prazo', r: 'Dias → nº de pessoas' }, { id: 'pessoas', r: 'Pessoas → nº de dias' }].map((b) => (
              <Chip key={b.id} selecionado={base === b.id} onPress={() => setBase(b.id)}>{b.r}</Chip>
            ))}
          </View>
          <View style={[styles.linhaDupla, { marginTop: 10 }]}>
            {base === 'prazo' ? (
              <Input rotulo="Dias para executar a atividade" style={styles.campo} estiloCampo={styles.inputNum} value={dias} onChangeText={setDias} keyboardType="number-pad" placeholder="0" />
            ) : (
              <Input rotulo="Pessoas na equipe" style={styles.campo} estiloCampo={styles.inputNum} value={pessoas} onChangeText={setPessoas} keyboardType="number-pad" placeholder="0" />
            )}
          </View>
        </Cartao>

        {/* Resultado — card proeminente do painel */}
        <Cartao style={resultado && styles.cardResultado}>
          <Eyebrow>Resultado (estimativa)</Eyebrow>
          {!tipo ? (
            <Text style={styles.dica}>Escolha o tipo de atividade para estimar a mão de obra.</Text>
          ) : !rend ? (
            <View style={styles.aviso}>
              <View style={styles.avisoLinha}>
                <Icone nome="aviso" tam={16} cor={cores.amberDeep} />
                <Text style={[styles.avisoTxt, { flex: 1 }]}>
                  Parâmetros ainda não cadastrados para esta atividade (rendimento por diária).
                  Cadastre no VERO web em Gestão Agrícola → Parâmetros de Rendimento (MO).
                </Text>
              </View>
            </View>
          ) : trabEfetivo <= 0 ? (
            <Text style={styles.dica}>Informe o total a fazer ({unidade || 'unidade'}).</Text>
          ) : (
            <>
              <View style={styles.resLinha}>
                <View>
                  <Text style={styles.resBig}>{fmt2(resultado.diarias)}</Text>
                  <Text style={styles.resBigSub}>diárias</Text>
                </View>
                <View style={styles.resSep} />
                <View>
                  <Text style={styles.resK}>{resultado.dimensao.rotulo}</Text>
                  <Text style={styles.resV}>{resultado.dimensao.valor !== null ? int0(resultado.dimensao.valor) : '—'}</Text>
                  <Text style={styles.resSub}>{resultado.dimensao.sub}</Text>
                </View>
              </View>
              <View style={styles.resLinha2}>
                <View style={styles.resKpi}>
                  <Text style={styles.resK}>Custo própria</Text>
                  <Text style={styles.resCash}>{resultado.custoPropria !== null ? `R$ ${fmt2(resultado.custoPropria)}` : '—'}</Text>
                </View>
                <View style={styles.resKpi}>
                  <Text style={styles.resK}>Custo terceir.</Text>
                  <Text style={styles.resCash}>{resultado.custoTerceirizada !== null ? `R$ ${fmt2(resultado.custoTerceirizada)}` : '—'}</Text>
                </View>
                {resultado.premiacao !== null && (
                  <View style={styles.resKpi}>
                    <Text style={styles.resK}>Premiação (est.)</Text>
                    <Text style={styles.resCash}>R$ {fmt2(resultado.premiacao)}</Text>
                  </View>
                )}
              </View>
              <Text style={styles.rodape}>{resultado.rodape}</Text>
            </>
          )}
        </Cartao>
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  cardResultado: { borderWidth: 1, borderColor: cores.accent3, backgroundColor: cores.posBg },
  dica: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 16, marginTop: 6 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 7, marginTop: 2 },
  linhaDupla: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 10, marginBottom: 4 },
  rotulo: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansSemi, marginBottom: 5, marginTop: 6 },
  campo: { flex: 1, minWidth: 130 },
  inputNum: { textAlign: 'center', fontFamily: fonte.monoSemi },
  avisoLinha: { flexDirection: 'row', gap: 8, alignItems: 'flex-start' },
  select: {
    minHeight: espaco.toque, borderRadius: raio.sm, backgroundColor: cores.campo,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    paddingHorizontal: 13,
  },
  selectTxt: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansSemi },
  selectSeta: { fontSize: 14, color: cores.muted, fontFamily: fonte.sansBold },
  veu: { flex: 1, backgroundColor: 'rgba(10,20,17,0.45)', justifyContent: 'center', padding: 24 },
  folha: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14, maxHeight: 500 },
  folhaTitulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold, marginBottom: 8 },
  opcao: {
    minHeight: espaco.toque, justifyContent: 'center', paddingHorizontal: 12,
    borderRadius: raio.sm, marginBottom: 4,
  },
  opcaoAtiva: { backgroundColor: cores.posBg },
  opcaoTxt: { fontSize: 14, color: cores.ink2, fontFamily: fonte.sansSemi },
  opcaoSub: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  aviso: { backgroundColor: cores.amberBg, borderRadius: raio.sm, padding: 11 },
  avisoTxt: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansSemi, lineHeight: 17 },
  resLinha: { flexDirection: 'row', alignItems: 'center', gap: 16, marginTop: 4 },
  resSep: { width: 1, alignSelf: 'stretch', backgroundColor: cores.border2 },
  resBig: { fontSize: 30, color: cores.accentDeep, fontFamily: fonte.monoSemi },
  resBigSub: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansSemi },
  resK: { fontSize: 10, letterSpacing: 0.5, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold },
  resV: { fontSize: 20, color: cores.ink, fontFamily: fonte.monoSemi, marginTop: 1 },
  resSub: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed },
  resLinha2: { flexDirection: 'row', flexWrap: 'wrap', gap: 18, marginTop: 13, paddingTop: 11, borderTopWidth: 1, borderTopColor: cores.border2 },
  resKpi: { minWidth: 96 },
  resCash: { fontSize: 15, color: cores.accentDeep, fontFamily: fonte.monoSemi, marginTop: 2 },
  rodape: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 15, marginTop: 11 },
});
