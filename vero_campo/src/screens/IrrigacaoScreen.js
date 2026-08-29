import React, { useEffect, useState } from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { useRoute } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSafraAtiva, rotuloSafra } from '../hooks/useSafraAtiva';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { useSync } from '../context/SyncContext';
import { Cartao, Botao, Input, Chip, Badge, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';

// Apontamento de irrigação = MESMO formulário do VERO web:
// Válvula → Data → Horas de irrigação → Lâmina (mm). A safra vem do vínculo
// vigente da válvula (o servidor resolve) e os CONSUMOS DO PERÍODO nem
// aparecem — água/energia = bomba × horas e custo = tarifas do tenant, tudo
// calculado no servidor ao receber o setor_id (mig 160 + C-21).

const dec = (v) => {
  const n = parseFloat(String(v || '').trim().replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

// 'YYYY-MM-DD' de N dias atrás (0 = hoje) e rótulo "dd/mm"
function diaAtras(n) {
  const d = new Date();
  d.setDate(d.getDate() - n);
  const p = (x) => String(x).padStart(2, '0');
  return {
    valor: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`,
    rotulo: n === 0 ? 'Hoje' : n === 1 ? 'Ontem' : `${p(d.getDate())}/${p(d.getMonth() + 1)}`,
  };
}
const DIAS = [diaAtras(0), diaAtras(1), diaAtras(2)];

// Linha de detalhe da válvula — contexto de vinhedo do sync enriquecido
function detalheDa(v) {
  const partes = [];
  if (v.variedade) partes.push(v.variedade);
  else if (v.cultura) partes.push(v.cultura);
  else if (v.talhao_nome) partes.push(v.talhao_nome);
  if (v.area_ha) partes.push(`${String(v.area_ha).replace('.', ',')} ha`);
  return partes.join(' · ');
}

export default function IrrigacaoScreen() {
  const { itens: valvulas, carregado } = useDadosSync('talhoes');
  const { itens: fenologias } = useDadosSync('fenologia');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { sincronizarAgora } = useSync();
  const safra = useSafraAtiva();

  const route = useRoute();
  const [aberta, setAberta] = useState(null);       // id da válvula com o form aberto
  const [form, setForm] = useState({ data: DIAS[0].valor, horas: '', lamina: '' });
  const [registradas, setRegistradas] = useState({}); // { [id]: true }

  // abertura NOVA pelos atalhos (_novo): zera a tela montada
  useEffect(() => {
    if (!route.params?._novo) return;
    setAberta(null);
    setForm({ data: DIAS[0].valor, horas: '', lamina: '' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?._novo]);

  // ficha 360° navega com { valvula } → abre direto o form daquela válvula
  useEffect(() => {
    const v = route.params?.valvula;
    if (v?.id) {
      setAberta(v.id);
      setForm({ data: DIAS[0].valor, horas: '', lamina: '' });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?.valvula?.id]);

  // lâmina sugerida (mm/dia) da fase atual da variedade — igual à dica do web
  const laminaDa = (v) => {
    const f = (fenologias || []).find((x) => String(x.talhao_id) === String(v.talhao_id || v.id));
    return f?.volume_mm_dia != null
      ? { valor: String(f.volume_mm_dia).replace('.', ','), fase: f.fase_nome || f.fase || null }
      : null;
  };

  function abrirForm(v) {
    if (registradas[v.id]) return;
    if (aberta === v.id) { setAberta(null); return; }
    setAberta(v.id);
    setForm({ data: DIAS[0].valor, horas: '', lamina: '' });
  }

  async function registrar(v) {
    const horas = dec(form.horas);
    if (horas === null || horas <= 0) return;
    await enfileirar({
      tipo: 'irrigacao',
      rota: rotas.irrigacao,
      metodo: 'POST',
      // setor_id = a válvula: o servidor resolve a bomba e calcula os consumos
      // (água = vazão×horas, energia = potência×horas) + custo pelas tarifas.
      payload: {
        talhao_id: v.talhao_id || v.id,
        setor_id: v.id,
        horas,
        lamina_mm: dec(form.lamina) ?? 0,
        data: `${form.data} 00:00:00`,
      },
    });
    setRegistradas((m) => ({ ...m, [v.id]: true }));
    setAberta(null);
    // não bloqueia o ✓: offline, o registro sobe depois sozinho
    sincronizarAgora().catch(() => {});
  }

  const feitas = valvulas.filter((v) => registradas[v.id]).length;

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader
        titulo="Irrigação"
        sub={`${rotuloSafra(safra) || 'Apontamento'} · ${feitas}/${valvulas.length} registradas`}
      />

      <FlatList
        data={valvulas}
        keyExtractor={(v) => String(v.id)}
        contentContainerStyle={styles.corpo}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor="#00464e" colors={["#00464e"]} />}
        ListEmptyComponent={
          carregado ? (
            <Cartao>
              <Text style={styles.nome}>Nenhuma válvula no aparelho</Text>
              <Text style={[styles.cultura, { marginTop: 6 }]}>
                Conecte-se à internet e sincronize para carregar as válvulas da fazenda.
              </Text>
            </Cartao>
          ) : null
        }
        renderItem={({ item: v }) => {
          const detalhe = detalheDa(v);
          const feita = !!registradas[v.id];
          const formAberto = aberta === v.id;
          const sugestao = formAberto ? laminaDa(v) : null;
          const horasOk = (dec(form.horas) || 0) > 0;
          return (
            <Cartao style={formAberto && styles.cardAberto}>
              {/* Cabeçalho da válvula — toca para abrir o formulário */}
              <Pressable style={styles.linha1} onPress={() => abrirForm(v)}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.nome}>{v.nome}</Text>
                  {!!detalhe && <Text style={styles.cultura}>{detalhe}</Text>}
                  {!!v.bomba_nome && (
                    <Text style={styles.cultura}>
                      ⚙️ {v.bomba_nome}
                      {Number(v.bomba_vazao_m3h) ? ` · ${String(v.bomba_vazao_m3h).replace('.', ',')} m³/h` : ''}
                    </Text>
                  )}
                </View>
                {feita ? (
                  <Badge tipo="ok">✓ registrada</Badge>
                ) : (
                  <Text style={styles.seta}>{formAberto ? '▴' : '＋'}</Text>
                )}
              </Pressable>

              {/* Formulário — espelho do web (consumos ficam por conta do servidor) */}
              {formAberto && (
                <View style={styles.form}>
                  {/* Data */}
                  <Eyebrow>Data</Eyebrow>
                  <View style={styles.chips}>
                    {DIAS.map((d) => {
                      const ativa = form.data === d.valor;
                      return (
                        <Chip
                          key={d.valor}
                          selecionado={ativa}
                          onPress={() => setForm((f) => ({ ...f, data: d.valor }))}
                        >
                          {d.rotulo}
                        </Chip>
                      );
                    })}
                  </View>

                  {/* Horas + Lâmina lado a lado, como no web */}
                  <View style={styles.duplo}>
                    <Input
                      style={{ flex: 1 }}
                      rotulo="Horas de irrigação *"
                      value={form.horas}
                      onChangeText={(t) => setForm((f) => ({ ...f, horas: t }))}
                      keyboardType="numeric"
                      placeholder="0,0"
                      estiloCampo={styles.inputCampo}
                    />
                    <Input
                      style={{ flex: 1 }}
                      rotulo="Lâmina (mm)"
                      value={form.lamina}
                      onChangeText={(t) => setForm((f) => ({ ...f, lamina: t }))}
                      keyboardType="numeric"
                      placeholder="0,0"
                      estiloCampo={styles.inputCampo}
                    />
                  </View>

                  {/* sugestão da fase fenológica — orienta, não trava (igual ao web) */}
                  {!!sugestao && (
                    <View style={styles.sugestao}>
                      <View style={styles.sugestaoLinha}>
                        <Icone nome="irrigacao" tam={14} cor={cores.accent} />
                        <Text style={styles.sugestaoTxt}>
                          {sugestao.fase ? `Fase ${sugestao.fase}: ` : 'Referência da fase: '}
                          <Text style={styles.sugestaoValor}>{sugestao.valor} mm/dia</Text>
                        </Text>
                      </View>
                      <Text style={styles.sugestaoNota}>o sistema sugere, não trava</Text>
                    </View>
                  )}

                  <Text style={styles.dica}>
                    Água, energia e custo do período são lançados automaticamente pela bomba e tarifas da fazenda.
                  </Text>

                  <Botao
                    titulo="Registrar irrigação"
                    disabled={!horasOk}
                    onPress={() => registrar(v)}
                  />
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
  cardAberto: { borderWidth: 1, borderColor: cores.accent },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  nome: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansBold },
  cultura: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  seta: { fontSize: 18, color: cores.accent, fontFamily: fonte.sansBold, paddingHorizontal: 4 },
  form: { marginTop: 13, borderTopWidth: 1, borderTopColor: cores.border, paddingTop: 12, gap: 10 },
  chips: { flexDirection: 'row', gap: 8 },
  duplo: { flexDirection: 'row', gap: 10 },
  inputCampo: { fontSize: 15, color: cores.ink, fontFamily: fonte.monoSemi, textAlign: 'center' },
  sugestao: { backgroundColor: cores.campo, borderRadius: raio.sm, padding: 10 },
  sugestaoLinha: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  sugestaoTxt: { fontSize: 12, color: cores.ink2, fontFamily: fonte.sansMed },
  sugestaoValor: { fontFamily: fonte.monoSemi, color: cores.accent },
  sugestaoNota: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 2, fontStyle: 'italic' },
  dica: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, lineHeight: 15 },
});
