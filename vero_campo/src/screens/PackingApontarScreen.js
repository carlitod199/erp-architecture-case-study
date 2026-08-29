import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet, Vibration } from 'react-native';
import { useIsFocused } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import Scanner from '../components/Scanner';
import Icone from '../components/Icone';
import { cores, fonte, raio, espaco } from '../theme';
import { useAuth } from '../context/AuthContext';
import { useSync } from '../context/SyncContext';
import { useDadosSync } from '../hooks/useDadosSync';
import http from '../services/http';
import veroApi, { rotas } from '../services/veroApi';
import { enfileirar, todos } from '../offline/fila';
import { novoClientUuid } from '../offline/idempotencia';
import { Cartao, Botao, Input, TituloSecao } from '../components/ui';

// Posto de bipagem do Packing House: cada leitura do crachá (QR) = +1 caixa
// para a pessoa (colheita ou embalamento). O servidor INCREMENTA — por isso
// cada bipe nasce com um client_uuid PRÓPRIO: reenvio da fila offline devolve
// a resposta gravada, nunca conta caixa dobrada. Debounce local de 400ms
// descarta o "tiro duplo" da câmera (mesmo crachá parado na mira renova a
// janela e só conta de novo depois que sai do quadro).

const DEBOUNCE_MS = 400;

const hojeISO = () => {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
const DATA_RE = /^\d{4}-\d{2}-\d{2}$/;
const dataBR = (v) => {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : String(v || '');
};
const fmtCx = (n) => {
  const v = Number(n) || 0;
  return Number.isInteger(v) ? String(v) : v.toLocaleString('pt-BR', { maximumFractionDigits: 2 });
};
const fmtReais = (n) => (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const rotuloModo = (m) => (m === 'embalamento' ? 'Embalamento' : 'Colheita');

export default function PackingApontarScreen() {
  const { pode } = useAuth();
  const { ultimaSync } = useSync();
  const focada = useIsFocused();
  const podeVer = pode('packing.apontar.ver');
  const podeBipar = pode('packing.apontar.editar');
  const { itens: valvulas } = useDadosSync('talhoes');

  // ---- configuração do posto ----
  const [data, setData] = useState(hojeISO());
  const [valvula, setValvula] = useState(null);        // {id(setor), talhao_id, nome…}
  const [talhaoAvulso, setTalhaoAvulso] = useState(null); // {talhao_id, nome} vindo do romaneio sem válvula local
  const [buscaVal, setBuscaVal] = useState('');
  /* Gestor 19/08: a função vem da ETIQUETA (colhedor/embalador do QR Codes),
     sem seletor na tela. Quem estiver com função "ambos" conta como colheita
     (default do servidor) — o certo é definir a função da pessoa no cadastro. */
  const modoAmbos = 'colheita';
  const [romaneio, setRomaneio] = useState('');
  /* gestor 19/08: romaneio preenchido tem que VALIDAR na busca — número
     inexistente não configura o posto (vazio continua opcional). */
  const [romaneioOk, setRomaneioOk] = useState(false);
  const [romaneioInfo, setRomaneioInfo] = useState(null); // texto de resultado da consulta
  const [buscandoRom, setBuscandoRom] = useState(false);
  const [configOk, setConfigOk] = useState(false);

  // ---- bipagem ----
  const [scanner, setScanner] = useState(false);
  const [biparManualTxt, setBiparManualTxt] = useState('');
  const [enviandoManual, setEnviandoManual] = useState(false);
  const [ultimo, setUltimo] = useState(null); // {tipo:'ok'|'fila'|'erro', titulo, sub}
  const [filaBipes, setFilaBipes] = useState(0);
  const ultimaLeituraRef = useRef({ codigo: null, ts: 0 });
  const bipandoRef = useRef(false); // trava reentrância entre quadros da câmera

  // ---- tally ("Apontado hoje") ----
  const [tally, setTally] = useState([]);
  const [tallyNota, setTallyNota] = useState(null);

  const valvulasAchadas = useMemo(() => {
    const q = buscaVal.trim().toLowerCase();
    if (q.length < 1) return [];
    return (valvulas || [])
      .filter((v) => `${v.nome || ''} ${v.codigo || ''} ${v.talhao_nome || ''}`.toLowerCase().includes(q))
      .slice(0, 8);
  }, [buscaVal, valvulas]);

  function rotuloValvula(v) {
    if (!v) return '';
    return v.nome || v.codigo || v.talhao_nome || `válvula ${v.id}`;
  }
  const rotuloAlvo = valvula
    ? rotuloValvula(valvula)
    : (talhaoAvulso ? (talhaoAvulso.nome || `talhão ${talhaoAvulso.talhao_id}`) : 'sem válvula');

  // bipes aguardando na fila offline (indicador do posto)
  const contarBipesFila = useCallback(async () => {
    try {
      const itens = await todos();
      setFilaBipes(itens.filter(
        (i) => i.tipo === 'ph_beep' && ['pendente', 'enviando', 'erro'].includes(i.estado)
      ).length);
    } catch (_) { /* sem SQLite agora — mantém o último valor */ }
  }, []);
  useEffect(() => { if (focada) contarBipesFila(); }, [focada, ultimaSync, contarBipesFila]);

  // tally ao abrir / trocar posto / após sincronizar
  const carregarTally = useCallback(async () => {
    if (!podeVer) return;
    try {
      const setorId = valvula ? valvula.id : null;
      const r = setorId
        ? await veroApi.packingTally(data, setorId)
        : await http.get(`/packing/apontar/tally?data=${encodeURIComponent(data)}${talhaoAvulso ? `&talhao_id=${talhaoAvulso.talhao_id}` : ''}`);
      setTally(r.data?.tally || []);
      setTallyNota(null);
    } catch (e) {
      setTallyNota(e?.codigo === 'sem_conexao'
        ? 'Sem sinal — o apontado do dia aparece quando conectar.'
        : (e?.message || 'Não foi possível carregar o apontado.'));
    }
  }, [podeVer, data, valvula, talhaoAvulso]);
  useEffect(() => { if (focada) carregarTally(); }, [focada, ultimaSync, carregarTally]);

  // consulta do romaneio → pré-preenche data e válvula do posto
  async function buscarRomaneio() {
    const num = romaneio.trim();
    if (!num || buscandoRom) return;
    setBuscandoRom(true);
    setRomaneioInfo(null);
    try {
      const r = await veroApi.packingRomaneio(num);
      const alvo = r.data || {};
      if (DATA_RE.test(String(alvo.data || ''))) setData(alvo.data);
      if (alvo.talhao_id) {
        const v = (valvulas || []).find((x) => (x.talhao_id || x.id) === alvo.talhao_id);
        if (v) {
          setValvula(v);
          setTalhaoAvulso(null);
        } else {
          setValvula(null);
          setTalhaoAvulso({ talhao_id: alvo.talhao_id, nome: alvo.talhao_nome || '' });
        }
      }
      setRomaneioInfo(`Romaneio ${num}: ${dataBR(alvo.data)}${alvo.talhao_nome ? ` · ${alvo.talhao_nome}` : ''}`);
      setRomaneioOk(true); /* gestor 19/08: romaneio digitado só passa validado */
    } catch (e) {
      setRomaneioOk(false);
      setRomaneioInfo(e?.codigo === 'sem_conexao'
        ? 'Sem sinal — a consulta do romaneio precisa de conexão.'
        : (e?.message || 'Romaneio não encontrado.'));
    } finally {
      setBuscandoRom(false);
    }
  }

  // aplica o bipe no tally local (feedback imediato, sem esperar o GET)
  function aplicarNoTally(d) {
    setTally((xs) => {
      const i = xs.findIndex((t) => t.pessoa === d.pessoa && t.modo === d.modo);
      const linha = {
        pessoa: d.pessoa, modo: d.modo, vinculo: d.vinculo,
        caixas: Number(d.caixas_total) || 0, premio: Number(d.premio_total) || 0,
      };
      if (i < 0) return [linha, ...xs];
      const novo = [...xs];
      novo[i] = { ...novo[i], ...linha };
      return novo;
    });
  }

  // um bipe: POST direto quando há sinal (feedback com nome + total);
  // sem sinal, entra na fila offline com o uuid gerado NA LEITURA
  async function bipar(cracha) {
    const clientUuid = novoClientUuid();
    const payload = {
      client_uuid: clientUuid,
      cracha,
      data,
      ...(valvula ? { setor_id: valvula.id } : {}),
      ...(!valvula && talhaoAvulso ? { talhao_id: talhaoAvulso.talhao_id } : {}),
      modo_ambos: modoAmbos,
    };
    try {
      const resp = await http.post(rotas.packingBeep, payload);
      const d = resp.data || {};
      Vibration.vibrate(60);
      setUltimo({
        /* aviso do servidor (ex.: embalando sem recepção aceita no dia) rebaixa
           a faixa para âmbar — contou, mas o gestor precisa ver o alerta */
        tipo: d.aviso ? 'fila' : 'ok',
        titulo: `${d.pessoa} · ${rotuloModo(d.modo)}`,
        sub: `${fmtCx(d.caixas_total)} caixa${Number(d.caixas_total) === 1 ? '' : 's'} hoje · prêmio R$ ${fmtReais(d.premio_total)}`
          + (d.aviso ? ` · ⚠ ${d.aviso}` : ''),
      });
      aplicarNoTally(d);
    } catch (e) {
      if (e?.codigo === 'sem_conexao') {
        await enfileirar({ tipo: 'ph_beep', rota: rotas.packingBeep, metodo: 'POST', payload });
        Vibration.vibrate(60);
        setUltimo({
          tipo: 'fila',
          titulo: 'Caixa guardada na fila',
          sub: 'Sem sinal agora — envia sozinha quando conectar.',
        });
        contarBipesFila();
      } else {
        Vibration.vibrate([0, 260, 120, 260]);
        setUltimo({
          tipo: 'erro',
          titulo: 'Bipe recusado',
          sub: e?.message || 'Não foi possível registrar a caixa.',
        });
      }
    }
  }

  // leitura contínua da câmera: descarta leitura idêntica em <400ms (mesmo
  // crachá parado na mira renova a janela — só conta de novo quando sai do
  // quadro por 400ms); crachás DIFERENTES em sequência contam normalmente.
  // As leituras aceitas entram numa fila local processada EM SÉRIE: se o
  // bipe anterior ainda está postando (rede lenta), a próxima leitura espera
  // a vez em vez de se perder.
  const leiturasRef = useRef([]);
  async function processarLeituras() {
    if (bipandoRef.current) return;
    bipandoRef.current = true;
    try {
      while (leiturasRef.current.length > 0) {
        const codigo = leiturasRef.current.shift();
        await bipar(codigo);
      }
    } finally {
      bipandoRef.current = false;
    }
  }
  function aoLerContinuo(codigo) {
    const agora = Date.now();
    const ult = ultimaLeituraRef.current;
    if (ult.codigo === codigo && agora - ult.ts < DEBOUNCE_MS) {
      ultimaLeituraRef.current = { codigo, ts: agora };
      return;
    }
    ultimaLeituraRef.current = { codigo, ts: agora };
    leiturasRef.current.push(codigo);
    processarLeituras();
  }

  async function biparManual() {
    const c = biparManualTxt.trim();
    if (!c || enviandoManual) return;
    setEnviandoManual(true);
    try {
      await bipar(c);
      setBiparManualTxt('');
    } finally {
      setEnviandoManual(false);
    }
  }

  const dataOk = DATA_RE.test(data.trim());

  // faixa de feedback grande (campo/sol/luva) — reusada na tela e no overlay
  function Faixa({ grande }) {
    if (!ultimo) return null;
    const paleta = {
      ok:   { bg: cores.pos, fg: '#fff' },
      fila: { bg: cores.amber, fg: '#fff' },
      erro: { bg: cores.danger, fg: '#fff' },
    }[ultimo.tipo] || { bg: cores.pos, fg: '#fff' };
    return (
      <View style={[styles.faixa, { backgroundColor: paleta.bg }, grande && styles.faixaGrande]}>
        <Text style={[styles.faixaTitulo, { color: paleta.fg }, grande && styles.faixaTituloGrande]} numberOfLines={2}>
          {ultimo.titulo}
        </Text>
        <Text style={[styles.faixaSub, { color: paleta.fg }]} numberOfLines={2}>{ultimo.sub}</Text>
      </View>
    );
  }

  if (!podeVer && !podeBipar) {
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Posto de caixas" sub="Colheita e embalamento por caixa" />
        <View style={styles.corpo}>
          <Cartao><Text style={styles.sub}>Você não tem acesso ao posto de produção do packing.</Text></Cartao>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.tela}>
      <AppHeader titulo="Posto de caixas" sub="Colheita e embalamento por caixa" />

      <Scanner
        visivel={scanner}
        continuo
        titulo="Aponte para o crachá — cada leitura conta 1 caixa"
        aoLer={aoLerContinuo}
        aoFechar={() => { setScanner(false); carregarTally(); }}
        overlay={(
          <>
            <View style={styles.overlayPosto}>
              <Text style={styles.overlayPostoTxt} numberOfLines={1}>
                {dataBR(data)} · {rotuloAlvo}
              </Text>
              {filaBipes > 0 && (
                <Text style={styles.overlayFila}>{filaBipes} bipe{filaBipes === 1 ? '' : 's'} na fila</Text>
              )}
            </View>
            <Faixa grande />
          </>
        )}
      />

      <ScrollView contentContainerStyle={styles.corpo} keyboardShouldPersistTaps="handled">
        {/* ---- configuração do posto (colapsa depois de definida) ---- */}
        {configOk ? (
          <Pressable style={styles.postoResumo} onPress={() => setConfigOk(false)} accessibilityLabel="Editar o posto">
            <Icone nome="scanner" tam={18} cor={cores.accent} />
            <View style={{ flex: 1 }}>
              <Text style={styles.postoResumoTitulo}>{dataBR(data)} · {rotuloAlvo}</Text>
              <Text style={styles.postoResumoSub}>Colheita ou embalamento: a etiqueta da pessoa decide</Text>
            </View>
            <Text style={styles.trocar}>editar</Text>
          </Pressable>
        ) : (
          <Cartao>
            <TituloSecao>Posto de trabalho</TituloSecao>

            <Text style={styles.rot}>Romaneio (opcional — preenche data e válvula)</Text>
            <View style={styles.linhaRomaneio}>
              <Input
                value={romaneio}
                onChangeText={(v) => { setRomaneio(v); setRomaneioOk(false); setRomaneioInfo(null); }}
                placeholder="número do romaneio"
                autoCapitalize="characters"
                style={{ flex: 1 }}
              />
              <Botao
                titulo="Buscar"
                tamanho="sm"
                variante="secundaria"
                carregando={buscandoRom}
                disabled={!romaneio.trim()}
                onPress={buscarRomaneio}
              />
            </View>
            {!!romaneioInfo && <Text style={styles.hint}>{romaneioInfo}</Text>}

            <Text style={styles.rot}>Data</Text>
            <Input
              value={data}
              onChangeText={setData}
              placeholder="AAAA-MM-DD"
              autoCapitalize="none"
              erro={!dataOk ? 'Use o formato AAAA-MM-DD.' : undefined}
            />

            <Text style={styles.rot}>Válvula (colheita — opcional no embalamento)</Text>
            {valvula || talhaoAvulso ? (
              <Pressable style={styles.selecionado} onPress={() => { setValvula(null); setTalhaoAvulso(null); }}>
                <Icone nome="irrigacao" tam={15} cor={cores.accent} />
                <Text style={styles.selecionadoTxt}>{rotuloAlvo}</Text>
                <Text style={styles.trocar}>trocar</Text>
              </Pressable>
            ) : (
              <>
                <Input value={buscaVal} onChangeText={setBuscaVal} placeholder="Buscar válvula/talhão…" />
                {valvulasAchadas.map((v) => (
                  <Pressable key={v.id} style={styles.achado} onPress={() => { setValvula(v); setTalhaoAvulso(null); setBuscaVal(''); }}>
                    <Text style={styles.achadoNome}>{rotuloValvula(v)}{v.talhao_nome ? ` · ${v.talhao_nome}` : ''}</Text>
                    <Text style={styles.achadoAdd}>selecionar</Text>
                  </Pressable>
                ))}
              </>
            )}

            <Botao
              titulo="Confirmar posto"
              disabled={!dataOk || (romaneio.trim() !== '' && !romaneioOk)}
              onPress={() => setConfigOk(true)}
              style={{ marginTop: 14 }}
            />
          </Cartao>
        )}

        {/* ---- leitura ---- */}
        {podeBipar ? (
          <>
            <Botao
              titulo="Ler crachás com a câmera"
              icone={<Icone nome="scanner" tam={20} cor="#fff" />}
              disabled={!configOk || !dataOk}
              onPress={() => { setUltimo(null); setScanner(true); }}
              estilo={styles.btnLeitor}
            />
            {filaBipes > 0 && (
              <Text style={styles.filaNota}>
                {filaBipes} bipe{filaBipes === 1 ? '' : 's'} na fila — envia sozinho quando houver sinal.
              </Text>
            )}

            <Faixa />

            <Cartao>
              <TituloSecao>Crachá sem leitura? Digite</TituloSecao>
              <View style={styles.linhaRomaneio}>
                <Input
                  value={biparManualTxt}
                  onChangeText={setBiparManualTxt}
                  placeholder="ex.: CRC-00001"
                  autoCapitalize="characters"
                  style={{ flex: 1 }}
                />
                <Botao
                  titulo="Bipar"
                  tamanho="sm"
                  carregando={enviandoManual}
                  disabled={!configOk || !dataOk || !biparManualTxt.trim()}
                  onPress={biparManual}
                />
              </View>
              <Text style={styles.hint}>Cada bipe soma 1 caixa para a pessoa do crachá.</Text>
            </Cartao>
          </>
        ) : (
          <Cartao>
            <Text style={styles.sub}>Seu perfil só consulta o apontado — a bipagem exige permissão de edição do posto.</Text>
          </Cartao>
        )}

        {/* ---- tally do dia ---- */}
        <TituloSecao>Apontado em {dataBR(data)}</TituloSecao>
        {!!tallyNota && <Text style={styles.hint}>{tallyNota}</Text>}
        {tally.length === 0 && !tallyNota && (
          <Cartao><Text style={styles.sub}>Nenhuma caixa apontada ainda neste posto.</Text></Cartao>
        )}
        {tally.map((t, i) => (
          <Cartao key={`${t.pessoa}-${t.modo}-${i}`} style={styles.itemCard}>
            <View style={{ flex: 1 }}>
              <Text style={styles.pessoa}>{t.pessoa}</Text>
              <Text style={styles.sub}>
                {rotuloModo(t.modo)}{t.vinculo === 'terceirizado' ? ' · terceirizado' : ''}
              </Text>
            </View>
            <View style={{ alignItems: 'flex-end' }}>
              <Text style={styles.caixas}>{fmtCx(t.caixas)} cx</Text>
              <Text style={styles.premio}>R$ {fmtReais(t.premio)}</Text>
            </View>
          </Cartao>
        ))}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  rot: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansSemi, marginTop: 12, marginBottom: 6 },
  sub: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed },
  hint: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 6 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  linhaRomaneio: { flexDirection: 'row', alignItems: 'center', gap: 8 },

  selecionado: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: cores.campo, borderRadius: raio.sm, paddingHorizontal: 12, paddingVertical: 11,
  },
  selecionadoTxt: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  trocar: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },
  achado: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    backgroundColor: cores.campo, borderRadius: raio.sm, paddingHorizontal: 12, paddingVertical: 11, marginTop: 6,
  },
  achadoNome: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  achadoAdd: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },

  postoResumo: {
    flexDirection: 'row', alignItems: 'center', gap: 10,
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: raio.card, paddingHorizontal: espaco.md, paddingVertical: 12, minHeight: espaco.toque,
  },
  postoResumoTitulo: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  postoResumoSub: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },

  btnLeitor: { minHeight: 64 },
  filaNota: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansSemi },

  faixa: { borderRadius: raio.r, paddingHorizontal: 16, paddingVertical: 12 },
  faixaGrande: { paddingVertical: 16 },
  faixaTitulo: { fontSize: 17, fontFamily: fonte.sansBold },
  faixaTituloGrande: { fontSize: 20 },
  faixaSub: { fontSize: 13, fontFamily: fonte.sansSemi, marginTop: 3, opacity: 0.95 },

  overlayPosto: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: 'rgba(10,20,17,0.72)', borderRadius: raio.r, paddingHorizontal: 12, paddingVertical: 8,
  },
  overlayPostoTxt: { flex: 1, fontSize: 12.5, color: '#fff', fontFamily: fonte.sansSemi },
  overlayFila: { fontSize: 12.5, color: cores.limeNeon, fontFamily: fonte.sansBold },

  itemCard: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  pessoa: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  caixas: { fontSize: 16, color: cores.ink, fontFamily: fonte.monoSemi },
  premio: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi, marginTop: 2 },
});
