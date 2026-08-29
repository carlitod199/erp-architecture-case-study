import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, Pressable, Modal, FlatList, ScrollView, StyleSheet } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { Tela, Cartao, Botao, Chip, Input, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';
import { useDadosSync } from '../hooks/useDadosSync';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { buscarClima, obterCoordenada } from '../services/clima';
import { useSync } from '../context/SyncContext';
import { cores, fonte, raio, espaco } from '../theme';

// Confirmar execução — FORMULÁRIO COMPLETO espelhando o sistema (gestor 23/07,
// mip/aplicacoes.php acao 'confirmar'): data/horas reais, céu/vento/pluviosidade,
// destino da sobra + tríplice lavagem (certificação), quantidades REAIS por
// produto (baixa FEFO no servidor) e Operadores/EPI (mínimo 1). É na confirmação
// que o estoque baixa — nunca na emissão.

const dec = (v) => {
  const n = parseFloat(String(v ?? '').trim().replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) && n > 0 ? n : 0;
};
const dataCurta = (v) => { const m = String(v || '').match(/^\d{4}-(\d{2})-(\d{2})/); return m ? `${m[2]}/${m[1]}` : ''; };
const emDias = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`; };
function diaAtras(n) {
  const d = new Date(); d.setDate(d.getDate() - n);
  const p = (x) => String(x).padStart(2, '0');
  return { valor: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`, rotulo: n === 0 ? 'Hoje' : n === 1 ? 'Ontem' : `${p(d.getDate())}/${p(d.getMonth() + 1)}` };
}
const DIAS_EXEC = [diaAtras(0), diaAtras(1), diaAtras(2)];
const CEUS = [{ id: 'noite', r: 'Noite' }, { id: 'sol', r: 'Sol' }, { id: 'nublado', r: 'Nublado' }, { id: 'chuva', r: 'Chuva' }];
const VENTOS = [{ id: 'brisa', r: 'Brisa' }, { id: 'moderado', r: 'Moderado' }, { id: 'forte', r: 'Forte' }];
const HORAS = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
const MINUTOS = Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0')); // 00,05,…,55

// clima atual (Open-Meteo) → condições de campo do formulário
function condicoesDoClima(d) {
  const cur = d?.current || {};
  const code = Number(cur.weather_code);
  const chuva = Number(cur.precipitation) > 0 || (code >= 51 && code <= 99);
  const h = new Date().getHours();
  const noite = h < 6 || h >= 19;
  const ceu = chuva ? 'chuva' : noite ? 'noite' : (code <= 1 ? 'sol' : 'nublado');
  const vk = Number(cur.wind_speed_10m);
  const ventoCl = Number.isFinite(vk) ? (vk >= 15 ? 'forte' : vk >= 8 ? 'moderado' : 'brisa') : null;
  return {
    ceu,
    ventoCl,
    ventoKmh: Number.isFinite(vk) ? String(Math.round(vk)) : '',
    pluvi: Number(cur.precipitation) > 0 ? String(cur.precipitation).replace('.', ',') : '',
    clima: cur.temperature_2m != null
      ? { temperatura: cur.temperature_2m, umidade: cur.relative_humidity_2m, vento_kmh: cur.wind_speed_10m }
      : null,
  };
}

export default function AplicacaoConfirmarScreen() {
  const nav = useNavigation();
  const route = useRoute();
  const { sincronizarAgora } = useSync();
  const df = route.params?.df || null;
  const { itens: equipe } = useDadosSync('colaboradores'); // operadores da execução

  // colaboradores próprios (agro_operadores) — terceirizados não entram no EPI
  const operadoresDisp = useMemo(
    () => (equipe || []).filter((c) => c.origem !== 'terceirizado'),
    [equipe]
  );

  const [dataExec, setDataExec] = useState(DIAS_EXEC[0].valor);
  const [horaIni, setHoraIni] = useState('');
  const [horaFim, setHoraFim] = useState('');
  const [ceu, setCeu] = useState(null);
  const [ventoCl, setVentoCl] = useState(null);
  const [ventoKmh, setVentoKmh] = useState('');
  const [pluvi, setPluvi] = useState('');
  const [destinoSobra, setDestinoSobra] = useState('');
  const [triplice, setTriplice] = useState(false);
  const [obs, setObs] = useState('');
  // quantidades reais por produto_id (default = dose/prevista)
  const [reais, setReais] = useState({});
  // operadores escolhidos: [{ opId, epiCod, lavado(bool|null), cond }]
  const [ops, setOps] = useState([]);
  const [seletorOp, setSeletorOp] = useState(false);
  const [seletorHora, setSeletorHora] = useState(null); // 'ini' | 'fim' | null
  const [enviando, setEnviando] = useState(false);
  const [climaAuto, setClimaAuto] = useState(null);   // {temperatura,umidade,vento_kmh} p/ enviar
  const [climaStatus, setClimaStatus] = useState('carregando'); // carregando|ok|indisponivel
  const [climaEditado, setClimaEditado] = useState(false);      // operador mexeu → não sobrescreve

  // CONDIÇÕES DE CAMPO AUTOMÁTICAS: puxa do clima (Open-Meteo)
  // ao abrir e pré-preenche céu/vento/pluviosidade — editáveis (o operador
  // ajusta se a medição real diferir).
  useEffect(() => {
    let ativo = true;
    (async () => {
      try {
        const pos = await obterCoordenada();
        const d = await buscarClima({ latitude: pos.latitude, longitude: pos.longitude });
        if (!ativo) return;
        const c = condicoesDoClima(d);
        setClimaAuto(c.clima);
        setClimaStatus(c.clima ? 'ok' : 'indisponivel');
        if (!climaEditado) {
          if (c.ceu) setCeu(c.ceu);
          if (c.ventoCl) setVentoCl(c.ventoCl);
          if (c.ventoKmh) setVentoKmh(c.ventoKmh);
          if (c.pluvi) setPluvi(c.pluvi);
        }
      } catch (_) {
        if (ativo) setClimaStatus('indisponivel');
      }
    })();
    return () => { ativo = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // seletor de hora (sem campo aberto): abre o modal com horas × minutos
  const horaAtual = seletorHora === 'ini' ? horaIni : horaFim;
  const [hSel, mSel] = (horaAtual || ':').split(':');
  function aplicarHora(h, m) {
    const val = `${h}:${m}`;
    if (seletorHora === 'ini') setHoraIni(val); else setHoraFim(val);
    setSeletorHora(null);
  }

  const rotuloDf = df?.id ? `DF #${df.id}` : df?.doc || 'Nova aplicação (campo)';
  const carencia = Math.max(0, ...((df?.itens || []).map((i) => Number(i.carencia_dias) || 0)));
  const reentrada = Math.max(0, ...((df?.itens || []).map((i) => Number(i.intervalo_reentrada_horas) || 0)));
  const temJanela = carencia > 0 || reentrada > 0;

  const itensDf = df?.itens || [];
  const valorReal = (i) => (reais[i.produto_id] !== undefined ? reais[i.produto_id] : (i.dose_valor != null ? String(i.dose_valor).replace('.', ',') : ''));
  const qtdsOk = itensDf.every((i) => dec(valorReal(i)) > 0);
  const opsValidos = ops.filter((o) => o.opId);
  const horaOk = (!horaIni || /^\d{2}:\d{2}$/.test(horaIni)) && (!horaFim || /^\d{2}:\d{2}$/.test(horaFim));
  const completo = opsValidos.length >= 1 && qtdsOk && horaOk;

  const faltando = opsValidos.length < 1 ? 'Informe ao menos 1 operador (EPI)'
    : !qtdsOk ? 'Informe a quantidade real de cada produto'
    : !horaOk ? 'Hora inválida (use HH:MM)'
    : null;

  const nomeOp = (opId) => (operadoresDisp.find((c) => (c.pessoa_id ?? c.id) === opId || c.id === opId)?.nome) || `Operador ${opId}`;

  function addOperador(c) {
    const opId = c.pessoa_id ?? c.id;
    if (ops.some((o) => o.opId === opId)) { setSeletorOp(false); return; }
    setOps((l) => [...l, { opId, epiCod: '', lavado: null, cond: '' }]);
    setSeletorOp(false);
  }
  function mudarOp(idx, patch) { setOps((l) => l.map((o, i) => (i === idx ? { ...o, ...patch } : o))); }
  function removerOp(idx) { setOps((l) => l.filter((_, i) => i !== idx)); }

  async function confirmar() {
    if (!completo || enviando) return;
    setEnviando(true);
    try {
      // clima já foi buscado ao abrir a tela (condições automáticas)
      const clima = climaAuto || undefined;
      const doCampo = !!df.emissao_uuid;
      await enfileirar({
        tipo: 'aplicacao_confirmar',
        rota: doCampo ? rotas.aplicacaoConfirmarCampo : rotas.aplicacaoConfirmar(df.id),
        metodo: 'POST',
        paiUuid: doCampo ? df.emissao_uuid : null,
        payload: {
          ...(doCampo ? { emissao_uuid: df.emissao_uuid } : {}),
          data_execucao: dataExec,
          hora_inicio: horaIni || undefined,
          hora_fim: horaFim || undefined,
          ceu: ceu || undefined,
          vento_class: ventoCl || undefined,
          vento_kmh: dec(ventoKmh) || undefined,
          pluviosidade_mm: dec(pluvi) || undefined,
          destino_sobra: destinoSobra.trim() || undefined,
          triplice_lavagem: triplice,
          observacao: obs.trim() || undefined,
          clima,
          itens_reais: itensDf.map((i) => ({ produto_id: i.produto_id, quantidade_real: dec(valorReal(i)) })),
          operadores: opsValidos.map((o) => ({
            operador_id: o.opId,
            epi_codigo: o.epiCod.trim() || undefined,
            epi_lavagem: o.lavado,
            epi_condicao: o.cond.trim() || undefined,
          })),
        },
      });
      sincronizarAgora().catch(() => {});
      nav.navigate('AplicacaoAssinatura', { df });
    } finally {
      setEnviando(false);
    }
  }

  if (!df) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Confirmar execução" />
        <Tela><Cartao><Text style={styles.texto}>DF não encontrado. Volte à fila de aplicações.</Text></Cartao></Tela>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Confirmar execução" sub={`${rotuloDf} · ${df.talhao_nome || 'válvula'}`} />
      <Tela>
        {/* Resumo (fixo da receita) */}
        <Cartao>
          <Eyebrow>Resumo da aplicação</Eyebrow>
          <Linha rot="Data prevista" val={dataCurta(df.data_prevista || df.data) || '—'} />
          <Linha rot="Local" val={[df.talhao_nome, df.area_aplicada_ha ? `${String(df.area_aplicada_ha).replace('.', ',')} ha` : null].filter(Boolean).join(' · ') || '—'} />
          {itensDf.map((i, idx) => (
            <Linha key={idx} rot={idx === 0 ? 'Receita' : ' '} val={[i.produto, i.dose_valor ? `${String(i.dose_valor).replace('.', ',')} ${i.dose_unidade || ''}`.trim() : null].filter(Boolean).join(' · ')} ultima={idx === itensDf.length - 1} />
          ))}
        </Cartao>

        {temJanela && (
          <View style={styles.janela}>
            <Text style={styles.janelaTitulo}>⏳ Janela após esta aplicação</Text>
            {carencia > 0 && <Text style={styles.janelaTxt}>Carência: <Text style={styles.janelaForte}>não colher antes de {emDias(carencia)}</Text> ({carencia} dias).</Text>}
            {reentrada > 0 && <Text style={styles.janelaTxt}>Reentrada liberada <Text style={styles.janelaForte}>após {reentrada}h</Text> da aplicação.</Text>}
          </View>
        )}

        {/* Data + horas */}
        <Cartao>
          <Eyebrow>Data da execução *</Eyebrow>
          <View style={styles.chips}>
            {DIAS_EXEC.map((d) => (
              <Chip key={d.valor} selecionado={dataExec === d.valor} onPress={() => setDataExec(d.valor)}>{d.rotulo}</Chip>
            ))}
          </View>
          <View style={styles.duplo}>
            <View style={{ flex: 1 }}>
              <Text style={styles.rotulo}>Hora início</Text>
              <Pressable style={styles.horaBtn} onPress={() => setSeletorHora('ini')}>
                <Text style={[styles.horaTxt, !horaIni && { color: cores.faint }]}>{horaIni || '--:--'}</Text>
                <Icone nome="relogio" tam={16} cor={cores.muted} />
              </Pressable>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.rotulo}>Hora término</Text>
              <Pressable style={styles.horaBtn} onPress={() => setSeletorHora('fim')}>
                <Text style={[styles.horaTxt, !horaFim && { color: cores.faint }]}>{horaFim || '--:--'}</Text>
                <Icone nome="relogio" tam={16} cor={cores.muted} />
              </Pressable>
            </View>
          </View>
        </Cartao>

        {/* Condições — puxadas do clima automaticamente (editáveis) */}
        <Cartao>
          <Eyebrow>Condições no campo</Eyebrow>
          <Text style={[styles.dica, climaStatus === 'ok' && { color: cores.accent }]}>
            {climaStatus === 'carregando' ? '🌤️ puxando do clima…'
              : climaStatus === 'ok' ? '🌤️ preenchido pelo clima do momento — ajuste se a medição real diferir'
              : '⚠️ clima indisponível — informe as condições manualmente'}
          </Text>
          <Text style={styles.rotulo}>Céu</Text>
          <View style={styles.chips}>
            {CEUS.map((c) => (
              <Chip key={c.id} selecionado={ceu === c.id} onPress={() => { setClimaEditado(true); setCeu(ceu === c.id ? null : c.id); }}>{c.r}</Chip>
            ))}
          </View>
          <Text style={[styles.rotulo, { marginTop: 10 }]}>Vento</Text>
          <View style={styles.chips}>
            {VENTOS.map((v) => (
              <Chip key={v.id} selecionado={ventoCl === v.id} onPress={() => { setClimaEditado(true); setVentoCl(ventoCl === v.id ? null : v.id); }}>{v.r}</Chip>
            ))}
          </View>
          <View style={styles.duplo}>
            <Input style={{ flex: 1 }} rotulo="Vento (km/h)" estiloCampo={styles.campoMono} value={ventoKmh} onChangeText={(t) => { setClimaEditado(true); setVentoKmh(t); }} placeholder="0" keyboardType="numeric" />
            <Input style={{ flex: 1 }} rotulo="Pluviosidade (mm)" estiloCampo={styles.campoMono} value={pluvi} onChangeText={(t) => { setClimaEditado(true); setPluvi(t); }} placeholder="0" keyboardType="numeric" />
          </View>
        </Cartao>

        {/* Quantidades REAIS por produto (baixa FEFO no servidor) */}
        <Cartao>
          <Eyebrow>Quantidade real consumida *</Eyebrow>
          <Text style={styles.dica}>Pré-preenchida com a prevista — ajuste o que realmente saiu. É por ela que o estoque baixa.</Text>
          {itensDf.map((i, idx) => (
            <View key={idx} style={styles.qtdLinha}>
              <View style={{ flex: 1 }}>
                <Text style={styles.qtdNome} numberOfLines={1}>{i.produto || `Produto ${i.produto_id}`}</Text>
                {i.dose_valor != null && <Text style={styles.qtdPrev}>prevista {String(i.dose_valor).replace('.', ',')} {i.dose_unidade || ''}</Text>}
              </View>
              <Input
                style={styles.qtdWrap}
                estiloCampo={styles.qtdCampo}
                value={valorReal(i)}
                onChangeText={(t) => setReais((m) => ({ ...m, [i.produto_id]: t }))}
                keyboardType="numeric"
                placeholder="0"
              />
              <Text style={styles.qtdUn}>{i.dose_unidade || ''}</Text>
            </View>
          ))}
        </Cartao>

        {/* Operadores / EPI (mínimo 1 — certificação) */}
        <Cartao>
          <Eyebrow>Operadores / EPI · mínimo 1 *</Eyebrow>
          {ops.map((o, idx) => (
            <View key={idx} style={styles.opBloco}>
              <View style={styles.opTopo}>
                <Text style={styles.opNome} numberOfLines={1}>{nomeOp(o.opId)}</Text>
                <Pressable onPress={() => removerOp(idx)} style={styles.opRem}><Text style={styles.opRemTxt}>✕</Text></Pressable>
              </View>
              <View style={styles.duplo}>
                <Input style={{ flex: 1 }} rotulo="Código EPI" estiloCampo={styles.campoMono} value={o.epiCod} onChangeText={(t) => mudarOp(idx, { epiCod: t })} placeholder="ex.: EPI-07" autoCapitalize="characters" />
                <View style={{ flex: 1 }}>
                  <Text style={styles.rotulo}>EPI lavado?</Text>
                  <View style={styles.chips}>
                    <Chip selecionado={o.lavado === true} onPress={() => mudarOp(idx, { lavado: o.lavado === true ? null : true })}>Sim</Chip>
                    <Chip selecionado={o.lavado === false} onPress={() => mudarOp(idx, { lavado: o.lavado === false ? null : false })}>Não</Chip>
                  </View>
                </View>
              </View>
              <Input rotulo="Condição do EPI" estiloCampo={styles.campoMono} value={o.cond} onChangeText={(t) => mudarOp(idx, { cond: t })} placeholder="bom / desgastado…" />
            </View>
          ))}
          <Botao titulo="＋ Adicionar operador" variante="secundaria" onPress={() => setSeletorOp(true)} style={styles.btnAdd} />
        </Cartao>

        {/* Certificação */}
        <Cartao>
          <Eyebrow>Certificação</Eyebrow>
          <Input rotulo="Destino da sobra de calda / água de lavagem" estiloCampo={styles.campoMono} value={destinoSobra} onChangeText={setDestinoSobra} placeholder="ex.: tanque lavado na área tratada" />
          <Pressable style={styles.toggleLinha} onPress={() => setTriplice((v) => !v)}>
            <View style={[styles.toggle, triplice && styles.toggleOn]}>
              <View style={[styles.toggleBola, triplice && styles.toggleBolaOn]} />
            </View>
            <Text style={styles.toggleTxt}>Tríplice lavagem das embalagens realizada</Text>
          </Pressable>
        </Cartao>

        {/* Observações */}
        <Cartao>
          <Eyebrow>Observações da execução</Eyebrow>
          <Input multiline estiloCampo={styles.obsCampo} value={obs} onChangeText={setObs} placeholder="Ex.: sobrou ~10 L de calda no tanque…" />
        </Cartao>

        <Botao titulo={faltando || 'Confirmar e colher assinaturas'} disabled={!completo || enviando} onPress={confirmar} />
        <Text style={styles.aviso}>Ao confirmar: baixa FEFO do estoque pelas quantidades reais + custeio. A validação do RT é a etapa seguinte.</Text>
      </Tela>

      {/* seletor de HORA — sem campo aberto (horas × minutos) */}
      <Modal visible={seletorHora !== null} transparent animationType="fade" onRequestClose={() => setSeletorHora(null)}>
        <Pressable style={styles.veu} onPress={() => setSeletorHora(null)}>
          <View style={styles.folha}>
            <Text style={styles.folhaTitulo}>{seletorHora === 'ini' ? 'Hora de início' : 'Hora de término'}</Text>
            <View style={styles.horaGrade}>
              <View style={styles.horaCol}>
                <Text style={styles.horaColTit}>Hora</Text>
                <ScrollView style={{ maxHeight: 300 }} showsVerticalScrollIndicator={false}>
                  {HORAS.map((h) => (
                    <Pressable key={h} style={[styles.horaOp, hSel === h && styles.horaOpAtiva]} onPress={() => aplicarHora(h, mSel && MINUTOS.includes(mSel) ? mSel : '00')}>
                      <Text style={[styles.horaOpTxt, hSel === h && { color: cores.surface }]}>{h}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              </View>
              <View style={styles.horaCol}>
                <Text style={styles.horaColTit}>Min</Text>
                <ScrollView style={{ maxHeight: 300 }} showsVerticalScrollIndicator={false}>
                  {MINUTOS.map((m) => (
                    <Pressable key={m} style={[styles.horaOp, mSel === m && styles.horaOpAtiva]} onPress={() => aplicarHora(hSel && HORAS.includes(hSel) ? hSel : '06', m)}>
                      <Text style={[styles.horaOpTxt, mSel === m && { color: cores.surface }]}>{m}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              </View>
            </View>
            {!!horaAtual && (
              <Pressable style={styles.horaLimpar} onPress={() => { if (seletorHora === 'ini') setHoraIni(''); else setHoraFim(''); setSeletorHora(null); }}>
                <Text style={styles.horaLimparTxt}>Limpar hora</Text>
              </Pressable>
            )}
          </View>
        </Pressable>
      </Modal>

      {/* seletor de operador */}
      <Modal visible={seletorOp} transparent animationType="fade" onRequestClose={() => setSeletorOp(false)}>
        <Pressable style={styles.veu} onPress={() => setSeletorOp(false)}>
          <View style={styles.folha}>
            <Text style={styles.folhaTitulo}>Operador da execução</Text>
            {operadoresDisp.length === 0 ? (
              <Text style={styles.dica}>Sincronize para carregar a equipe.</Text>
            ) : (
              <FlatList
                data={operadoresDisp}
                keyExtractor={(c) => String(c.id)}
                style={{ maxHeight: 380 }}
                renderItem={({ item: c }) => (
                  <Pressable style={styles.opcao} onPress={() => addOperador(c)}>
                    <Text style={styles.opcaoTxt}>{c.nome}</Text>
                    {!!c.funcao && <Text style={styles.opcaoSub}>{c.funcao}</Text>}
                  </Pressable>
                )}
              />
            )}
          </View>
        </Pressable>
      </Modal>
    </View>
  );
}

function Linha({ rot, val, ultima }) {
  return (
    <View style={[styles.linhaResumo, ultima && { borderBottomWidth: 0 }]}>
      <Text style={styles.rotulo}>{rot}</Text>
      <Text style={styles.valor}>{val}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  texto: { fontSize: 13, color: cores.ink2, fontFamily: fonte.sansMed },
  campoMono: { fontFamily: fonte.monoSemi },
  dica: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 15, marginBottom: 4 },
  linhaResumo: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 12, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: cores.track },
  rotulo: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansSemi, marginBottom: 5, marginTop: 6 },
  valor: { fontSize: 12.5, color: cores.ink, fontFamily: fonte.sansSemi, flexShrink: 1, textAlign: 'right' },
  janela: { backgroundColor: cores.amberBg, borderRadius: raio.card, padding: 13, gap: 4 },
  janelaTitulo: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansBold },
  janelaTxt: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansMed, lineHeight: 17 },
  janelaForte: { fontFamily: fonte.sansBold },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 2 },
  horaBtn: { height: 42, borderRadius: raio.sm, backgroundColor: cores.campo, paddingHorizontal: 12, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  horaTxt: { fontSize: 16, color: cores.ink, fontFamily: fonte.monoSemi },
  horaGrade: { flexDirection: 'row', gap: 12 },
  horaCol: { flex: 1 },
  horaColTit: { fontSize: 10.5, letterSpacing: 0.5, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold, textAlign: 'center', marginBottom: 6 },
  horaOp: { minHeight: 40, alignItems: 'center', justifyContent: 'center', borderRadius: raio.sm, marginBottom: 3 },
  horaOpAtiva: { backgroundColor: cores.accent },
  horaOpTxt: { fontSize: 16, color: cores.ink2, fontFamily: fonte.monoSemi },
  horaLimpar: { minHeight: 40, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
  horaLimparTxt: { fontSize: 12.5, color: cores.danger, fontFamily: fonte.sansBold },
  duplo: { flexDirection: 'row', gap: 10, marginTop: 2 },
  qtdLinha: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 9 },
  qtdNome: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  qtdPrev: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  qtdWrap: { width: 84 },
  qtdCampo: { textAlign: 'center', fontFamily: fonte.monoSemi },
  qtdUn: { width: 34, fontSize: 11, color: cores.muted, fontFamily: fonte.sansSemi },
  opBloco: { backgroundColor: cores.campo, borderRadius: raio.sm, padding: 11, marginBottom: 8 },
  opTopo: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  opNome: { flex: 1, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  opRem: { width: 30, height: 30, alignItems: 'center', justifyContent: 'center' },
  opRemTxt: { fontSize: 13, color: cores.danger, fontFamily: fonte.sansBold },
  btnAdd: { marginTop: 4 },
  toggleLinha: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 12 },
  toggle: { width: 44, height: 26, borderRadius: 13, backgroundColor: cores.border2, padding: 3, justifyContent: 'center' },
  toggleOn: { backgroundColor: cores.accent },
  toggleBola: { width: 20, height: 20, borderRadius: 10, backgroundColor: cores.surface },
  toggleBolaOn: { alignSelf: 'flex-end' },
  toggleTxt: { flex: 1, fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  obsCampo: { minHeight: 72, fontFamily: fonte.sansMed, textAlignVertical: 'top' },
  aviso: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, lineHeight: 15, textAlign: 'center' },
  veu: { flex: 1, backgroundColor: 'rgba(10,20,17,0.45)', justifyContent: 'center', padding: 24 },
  folha: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14, maxHeight: 460 },
  folhaTitulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold, marginBottom: 8 },
  opcao: { minHeight: espaco.toque, justifyContent: 'center', paddingHorizontal: 12, borderRadius: raio.sm, marginBottom: 4, backgroundColor: cores.campo },
  opcaoTxt: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansSemi },
  opcaoSub: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
});
