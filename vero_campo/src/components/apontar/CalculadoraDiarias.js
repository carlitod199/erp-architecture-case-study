import React, { useMemo, useState } from 'react';
import { View, Text, Pressable, TextInput, StyleSheet } from 'react-native';
import { cores, fonte, raio, espaco } from '../../theme';
import { useParametro } from '../../hooks/useParametro';

// F3 (espec 22/07) — Calculadora de diárias: ferramenta de PLANEJAMENTO de
// mão de obra no wizard. NADA daqui entra no payload do apontamento.
// - tipos por PLANTA (poda, desbrota…): diárias/dia = ceil((plantas/dias)/meta)
// - COLHEITA (regra corrigida 22/07 — POR KG, NUNCA por plantas): meta por
//   pessoa em caixas ou kg; diárias/dia = ceil((produção_kg/dias)/meta_kg)

// "40.000" / "22,5" → número (aceita milhar com ponto e decimal com vírgula)
const num = (s) => {
  const n = Number(String(s ?? '').trim().replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) && n > 0 ? n : null;
};
// 40000 → "40.000" (milhar pt-BR, sem depender de Intl)
const fmt = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
// peso pode ter decimal: 22.5 → "22,5"
const fmtPeso = (n) => (Number.isInteger(n) ? fmt(n) : String(n).replace('.', ','));

export default function CalculadoraDiarias({ tipo, valvulas = [], inicialAberta = false }) {
  const ehColheita = tipo === 'colheita';
  const [aberta, setAberta] = useState(inicialAberta);

  // defaults "vivos": seguem o cache até o operador editar (estado null)
  const pesoPadraoParam = useParametro('colheita.peso_caixa_kg', 20);
  const plantasPadrao = useMemo(
    () => valvulas.reduce((s, v) => s + (Number(v?.num_plantas) || 0), 0),
    [valvulas]
  );

  const [plantasTxt, setPlantasTxt] = useState(null); // por planta: total
  const [producaoTxt, setProducaoTxt] = useState(''); // colheita: SEMPRE digitada
  const [metaTxt, setMetaTxt] = useState('');
  const [metaUnid, setMetaUnid] = useState('caixa'); // colheita: 'caixa' | 'kg'
  const [pesoTxt, setPesoTxt] = useState(null);      // colheita: kg por caixa
  const [diasTxt, setDiasTxt] = useState('');

  const plantasVal = plantasTxt === null ? (plantasPadrao || null) : num(plantasTxt);
  const pesoVal = pesoTxt === null ? (num(String(pesoPadraoParam)) || 20) : num(pesoTxt);
  const producaoVal = num(producaoTxt);
  const metaVal = num(metaTxt);
  const diasVal = num(diasTxt);

  const r = useMemo(() => {
    if (!metaVal || !diasVal) return null;
    if (ehColheita) {
      if (!producaoVal || !pesoVal) return null;
      const metaKg = metaUnid === 'caixa' ? metaVal * pesoVal : metaVal;
      const porDia = producaoVal / diasVal;
      const diarias = Math.ceil(porDia / metaKg);
      return { porDia, metaKg, diarias, total: diarias * diasVal };
    }
    if (!plantasVal) return null;
    const porDia = plantasVal / diasVal;
    const diarias = Math.ceil(porDia / metaVal);
    return { porDia, diarias, total: diarias * diasVal };
  }, [ehColheita, producaoVal, plantasVal, metaVal, metaUnid, pesoVal, diasVal]);

  // memória de cálculo — o operador confere a conta, não confia no escuro
  const memoria = useMemo(() => {
    if (!r) return '';
    if (ehColheita) {
      const metaParte = metaUnid === 'caixa'
        ? `meta ${fmt(metaVal)} cx × ${fmtPeso(pesoVal)} kg = ${fmt(r.metaKg)} kg/pessoa`
        : `meta ${fmt(r.metaKg)} kg/pessoa`;
      return `${fmt(producaoVal)} kg ÷ ${fmt(diasVal)} dias = ${fmt(r.porDia)} kg/dia · ${metaParte} → ${r.diarias} diárias/dia · ${fmt(r.total)} no total`;
    }
    return `${fmt(plantasVal)} plantas ÷ ${fmt(diasVal)} dias = ${fmt(r.porDia)} plantas/dia · meta ${fmt(metaVal)} plantas/pessoa → ${r.diarias} diárias/dia · ${fmt(r.total)} no total`;
  }, [r, ehColheita, producaoVal, plantasVal, metaVal, metaUnid, pesoVal, diasVal]);

  return (
    <View style={styles.card}>
      <Pressable style={styles.cabec} onPress={() => setAberta((v) => !v)}>
        <Text style={styles.cabecTxt}>🧮 Calculadora de diárias (opcional)</Text>
        <Text style={styles.seta}>{aberta ? '▾' : '▸'}</Text>
      </Pressable>

      {aberta && (
        <View style={{ gap: 10, marginTop: 4 }}>
          <Text style={styles.dica}>
            Só para planejar a equipe — não entra no apontamento.
          </Text>

          {ehColheita ? (
            <>
              <View style={styles.campo}>
                <Text style={styles.rotulo}>Produção prevista total (kg)</Text>
                <TextInput
                  style={styles.input}
                  value={producaoTxt}
                  onChangeText={setProducaoTxt}
                  keyboardType="numeric"
                  placeholder="ex.: 40.000"
                  placeholderTextColor={cores.faint}
                />
              </View>
              <View style={styles.campo}>
                <Text style={styles.rotulo}>Meta por pessoa/dia</Text>
                <View style={styles.linhaMeta}>
                  <TextInput
                    style={[styles.input, { flex: 1 }]}
                    value={metaTxt}
                    onChangeText={setMetaTxt}
                    keyboardType="numeric"
                    placeholder="0"
                    placeholderTextColor={cores.faint}
                  />
                  {['caixa', 'kg'].map((u) => {
                    const ativa = metaUnid === u;
                    return (
                      <Pressable key={u} style={[styles.chip, ativa && styles.chipAtivo]} onPress={() => setMetaUnid(u)}>
                        <Text style={[styles.chipTxt, ativa && { color: cores.surface }]}>
                          {u === 'caixa' ? 'caixas' : 'kg'}
                        </Text>
                      </Pressable>
                    );
                  })}
                </View>
              </View>
              {metaUnid === 'caixa' && (
                <View style={styles.campo}>
                  <Text style={styles.rotulo}>Peso da caixa (kg)</Text>
                  <TextInput
                    style={styles.input}
                    value={pesoTxt === null ? String(pesoVal).replace('.', ',') : pesoTxt}
                    onChangeText={setPesoTxt}
                    keyboardType="numeric"
                    placeholderTextColor={cores.faint}
                  />
                </View>
              )}
            </>
          ) : (
            <>
              <View style={styles.campo}>
                <Text style={styles.rotulo}>Total de plantas</Text>
                <TextInput
                  style={styles.input}
                  value={plantasTxt === null ? (plantasPadrao ? String(plantasPadrao) : '') : plantasTxt}
                  onChangeText={setPlantasTxt}
                  keyboardType="numeric"
                  placeholder="0"
                  placeholderTextColor={cores.faint}
                />
                {plantasTxt === null && plantasPadrao > 0 && (
                  <Text style={styles.dica}>soma das válvulas escolhidas — pode ajustar</Text>
                )}
              </View>
              <View style={styles.campo}>
                <Text style={styles.rotulo}>Meta por pessoa/dia (plantas)</Text>
                <TextInput
                  style={styles.input}
                  value={metaTxt}
                  onChangeText={setMetaTxt}
                  keyboardType="numeric"
                  placeholder="0"
                  placeholderTextColor={cores.faint}
                />
              </View>
            </>
          )}

          <View style={styles.campo}>
            <Text style={styles.rotulo}>Dias de trabalho</Text>
            <TextInput
              style={styles.input}
              value={diasTxt}
              onChangeText={setDiasTxt}
              keyboardType="numeric"
              placeholder="0"
              placeholderTextColor={cores.faint}
            />
          </View>

          {r ? (
            <View style={styles.resultado}>
              <Text style={styles.resultadoNum}>
                {r.diarias} {r.diarias === 1 ? 'diária' : 'diárias'}/dia
              </Text>
              <Text style={styles.resultadoSub}>
                {fmt(r.total)} {r.total === 1 ? 'diária' : 'diárias'} no total
              </Text>
              <Text style={styles.memoria}>{memoria}</Text>
            </View>
          ) : (
            <Text style={styles.dica}>
              Preencha {ehColheita ? 'produção, meta e dias' : 'plantas, meta e dias'} para calcular.
            </Text>
          )}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: espaco.md },
  cabec: {
    minHeight: espaco.toque, flexDirection: 'row', alignItems: 'center',
    justifyContent: 'space-between',
  },
  cabecTxt: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  seta: { fontSize: 15, color: cores.accent, fontFamily: fonte.sansBold },
  dica: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 16 },
  campo: { gap: 6 },
  rotulo: { fontSize: 12, color: cores.ink2, fontFamily: fonte.sansSemi },
  input: {
    height: espaco.toque, borderRadius: raio.sm, backgroundColor: cores.campo,
    paddingHorizontal: 12, fontSize: 16, color: cores.ink, fontFamily: fonte.monoSemi,
  },
  linhaMeta: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  chip: {
    minHeight: espaco.toque, paddingHorizontal: 14, borderRadius: raio.r,
    backgroundColor: cores.campo, alignItems: 'center', justifyContent: 'center',
  },
  chipAtivo: { backgroundColor: cores.accent },
  chipTxt: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  resultado: { backgroundColor: cores.posBg, borderRadius: raio.r, padding: 12, gap: 2 },
  resultadoNum: { fontSize: 20, color: cores.pos, fontFamily: fonte.sansBold },
  resultadoSub: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  memoria: {
    fontSize: 11, color: cores.muted, fontFamily: fonte.mono,
    lineHeight: 16, marginTop: 6,
  },
});
