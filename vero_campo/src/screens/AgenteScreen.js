import React, { useEffect, useRef, useState } from 'react';
import {
  View, Text, Pressable, ScrollView, StyleSheet, Alert, ActivityIndicator,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  useAudioRecorder, RecordingPresets, AudioModule, setAudioModeAsync,
} from 'expo-audio';
import { cores, fonte, raio, espaco } from '../theme';
import Icone from '../components/Icone';
import { perguntar, transcreverAudio } from '../services/ia';
import fala from '../services/fala';

// =====================================================================
// Modo MÃOS-LIVRES (Fase 6) — o operador FALA e o assistente EXECUTA, lendo
// a resposta em voz alta. UI minimalista de ALTO CONTRASTE para uso ao sol,
// com luva: status grande, um botão de microfone gigante, poucos elementos.
//
// Fluxo: toca no mic -> grava -> transcreve (STT/Whisper) -> mostra o que
// entendeu -> manda ao agente -> mostra E FALA a resposta (TTS). Se o agente
// pedir confirmação de uma ESCRITA (pendente), fala "Confirma?" e mostra
// botões grandes; um "sim" por voz também confirma.
//
// SEGURANÇA: o "sim" por voz só confirma escritas COMUNS. Ações destrutivas
// continuam exigindo TOQUE no botão — nunca são disparadas por voz (o gate
// estrutural do servidor já bloqueia a escrita até o aceite explícito; aqui
// reforçamos exigindo o toque para o caminho destrutivo). Ver botão Confirmar.
// =====================================================================

// estados do ciclo mãos-livres
const OCIOSO = 'ocioso';        // aguardando toque no mic
const GRAVANDO = 'gravando';    // captando voz
const TRANSCREVENDO = 'transcrevendo';
const PENSANDO = 'pensando';    // agente processando
const FALANDO = 'falando';      // TTS lendo a resposta

// "sim" falado que confirma uma escrita pendente (não-destrutiva)
const CONFIRMA_VOZ = /\b(sim|confirmo|confirmar|pode|pode executar|isso|correto|ok)\b/i;
const semAcento = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

const ROTULO_ESTADO = {
  [OCIOSO]: 'Toque e fale',
  [GRAVANDO]: 'Ouvindo… toque para parar',
  [TRANSCREVENDO]: 'Entendendo o que você disse…',
  [PENSANDO]: 'Consultando…',
  [FALANDO]: 'Respondendo…',
};

export default function AgenteScreen() {
  const [estado, setEstado] = useState(OCIOSO);
  const [msgs, setMsgs] = useState([]); // { papel, texto } — histórico da conversa
  const [pendente, setPendente] = useState(null); // { resumo } — escrita aguardando confirmação
  const rolar = useRef(null);
  const gravador = useAudioRecorder(RecordingPresets.HIGH_QUALITY);

  // refs p/ ler o estado atual dentro de callbacks assíncronos
  const estadoRef = useRef(estado);
  useEffect(() => { estadoRef.current = estado; }, [estado]);
  const pendenteRef = useRef(pendente);
  useEffect(() => { pendenteRef.current = pendente; }, [pendente]);

  const rolarFim = () => requestAnimationFrame(() => rolar.current?.scrollToEnd({ animated: true }));

  // ao sair da tela, corta qualquer fala em andamento
  useEffect(() => () => fala.parar(), []);

  function adicionar(papel, texto) {
    setMsgs((m) => [...m, { id: `${papel[0]}${Date.now()}${Math.random()}`, papel, texto }]);
    rolarFim();
  }

  // manda o texto ao agente, mostra E fala a resposta, trata confirmação pendente
  async function processar(texto) {
    adicionar('usuario', texto);
    setPendente(null);
    setEstado(PENSANDO);
    try {
      const historico = [...msgs, { papel: 'usuario', texto }].map((m) => ({ papel: m.papel, texto: m.texto }));
      const r = await perguntar(historico);
      adicionar('assistente', r.resposta);
      if (r.pendente) {
        // ESCRITA aguardando confirmação: fala o pedido e mostra botões grandes
        setPendente(r.pendente);
        setEstado(FALANDO);
        fala.falar(
          `${r.resposta}. Confirma? Diga sim, ou toque em confirmar.`,
          () => setEstado(OCIOSO),
        );
      } else {
        setEstado(FALANDO);
        fala.falar(r.resposta, () => setEstado(OCIOSO));
      }
    } catch (e) {
      const msg = `Não consegui responder agora. ${e?.message || 'Tente de novo.'}`;
      adicionar('assistente', msg);
      setEstado(FALANDO);
      fala.falar(msg, () => setEstado(OCIOSO));
    }
  }

  // Toque no microfone. Barge-in: se o TTS está falando, primeiro CALA a fala.
  async function tocarMic() {
    if (estado === TRANSCREVENDO || estado === PENSANDO) return;

    // barge-in — corta a fala do assistente antes de ouvir o novo comando
    if (estado === FALANDO) fala.parar();

    if (estado === GRAVANDO) {
      // parar e transcrever
      setEstado(TRANSCREVENDO);
      try {
        await gravador.stop();
        const texto = (await transcreverAudio(gravador.uri) || '').trim();
        if (!texto) {
          setEstado(OCIOSO);
          return;
        }
        // "sim" falado confirma uma escrita pendente (não-destrutiva)
        if (pendenteRef.current && CONFIRMA_VOZ.test(semAcento(texto))) {
          confirmar();
          return;
        }
        await processar(texto);
      } catch (e) {
        Alert.alert('Comando de voz', e?.message || 'Não consegui entender o áudio.');
        setEstado(OCIOSO);
      }
      return;
    }

    // iniciar gravação
    const perm = await AudioModule.requestRecordingPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Comando de voz', 'Permita o acesso ao microfone para usar o modo mãos-livres.');
      return;
    }
    await setAudioModeAsync({ allowsRecording: true, playsInSilentMode: true });
    await gravador.prepareToRecordAsync();
    gravador.record();
    setEstado(GRAVANDO);
  }

  // Confirmar a escrita pendente — reenvia o aceite explícito ao agente (executa).
  // Chamado tanto pelo toque no botão quanto pelo "sim" de voz (escrita comum).
  function confirmar() {
    fala.parar();
    setPendente(null);
    processar('Sim, confirmo. Pode executar.');
  }

  function cancelar() {
    fala.parar();
    setPendente(null);
    setEstado(OCIOSO);
  }

  const ocupado = estado === TRANSCREVENDO || estado === PENSANDO;
  const gravando = estado === GRAVANDO;

  return (
    <SafeAreaView style={styles.tela} edges={['top', 'bottom']}>
      <View style={styles.topbar}>
        <View style={styles.avatar}><Icone nome="microfone" tam={22} cor={cores.limeInk} /></View>
        <View style={{ flex: 1 }}>
          <Text style={styles.titulo}>Modo mãos-livres</Text>
          <Text style={styles.sub}>Fale e o assistente executa</Text>
        </View>
      </View>

      {/* histórico curto da conversa (contexto visual; o áudio é o canal principal) */}
      <ScrollView
        ref={rolar}
        style={{ flex: 1 }}
        contentContainerStyle={styles.corpo}
        onContentSizeChange={rolarFim}
      >
        {msgs.length === 0 ? (
          <View style={styles.vazio}>
            <Text style={styles.vazioTxt}>
              Toque no microfone e diga um comando.{'\n'}
              Ex.: “registrar irrigação de 2 horas na válvula 5A”.
            </Text>
          </View>
        ) : (
          msgs.map((m) => (
            <View key={m.id} style={[styles.linha, m.papel === 'usuario' ? styles.linhaU : styles.linhaA]}>
              <Text style={styles.quem}>{m.papel === 'usuario' ? 'VOCÊ' : 'ASSISTENTE'}</Text>
              <Text style={styles.fala}>{m.texto.replace(/\*\*(.*?)\*\*/g, '$1')}</Text>
            </View>
          ))
        )}
      </ScrollView>

      {/* status grande sempre visível */}
      <Text style={[styles.estado, gravando && styles.estadoGrav]}>
        {ROTULO_ESTADO[estado]}
      </Text>

      {/* confirmação de ESCRITA — botões grandes; caminho destrutivo só por toque */}
      {pendente ? (
        <View style={styles.confirmar}>
          {!!pendente.resumo && <Text style={styles.confirmarTxt}>{pendente.resumo}</Text>}
          <View style={styles.confirmarBtns}>
            <Pressable style={[styles.cBtn, styles.cCancelar]} onPress={cancelar} accessibilityLabel="Cancelar">
              <Text style={styles.cCancelarTxt}>Cancelar</Text>
            </Pressable>
            <Pressable style={[styles.cBtn, styles.cOk]} onPress={confirmar} accessibilityLabel="Confirmar">
              <Text style={styles.cOkTxt}>Confirmar</Text>
            </Pressable>
          </View>
        </View>
      ) : null}

      {/* microfone GIGANTE (≥72px) — alvo de toque fácil com luva */}
      <View style={styles.micWrap}>
        <Pressable
          style={[styles.mic, gravando && styles.micGrav, ocupado && styles.micOcupado]}
          onPress={tocarMic}
          disabled={ocupado}
          accessibilityLabel={gravando ? 'Parar e enviar' : 'Falar comando'}
        >
          {ocupado
            ? <ActivityIndicator color="#fff" size="large" />
            : <Icone nome="microfone" tam={44} cor={gravando ? '#fff' : cores.limeInk} />}
        </Pressable>
        <Text style={styles.dica}>
          {gravando ? 'Toque para enviar' : ocupado ? 'Aguarde…' : 'Toque para falar'}
        </Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.sidebar },
  topbar: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    paddingHorizontal: espaco.md, paddingVertical: 12,
  },
  avatar: {
    width: 44, height: 44, borderRadius: 22, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center',
  },
  titulo: { fontSize: 19, color: '#fff', fontFamily: fonte.sansBold },
  sub: { fontSize: 13, color: 'rgba(255,255,255,0.65)', fontFamily: fonte.sansMed, marginTop: 1 },

  corpo: { padding: espaco.md, gap: 12, flexGrow: 1, backgroundColor: cores.page },
  vazio: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: 48 },
  vazioTxt: { fontSize: 16, color: cores.muted, fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 24 },
  linha: { borderRadius: raio.card, padding: 14 },
  linhaU: { backgroundColor: cores.accent, alignSelf: 'flex-end', maxWidth: '92%' },
  linhaA: { backgroundColor: cores.surface, alignSelf: 'flex-start', maxWidth: '92%', borderWidth: 1, borderColor: cores.border },
  quem: { fontSize: 11, letterSpacing: 1, fontFamily: fonte.sansBold, color: cores.muted2, marginBottom: 4 },
  // texto GRANDE p/ leitura ao sol; contraste alto em ambos os baloes
  fala: { fontSize: 18, lineHeight: 25, fontFamily: fonte.sansSemi },

  estado: {
    fontSize: 18, color: '#fff', fontFamily: fonte.sansBold, textAlign: 'center',
    paddingVertical: 12, backgroundColor: cores.sidebar,
  },
  estadoGrav: { color: cores.lime },

  confirmar: {
    backgroundColor: cores.surface, marginHorizontal: espaco.md, marginBottom: 8,
    borderRadius: raio.card, padding: 16, borderWidth: 2, borderColor: cores.lime,
  },
  confirmarTxt: { fontSize: 17, color: cores.ink, fontFamily: fonte.sansSemi, lineHeight: 24, marginBottom: 12 },
  confirmarBtns: { flexDirection: 'row', gap: 12 },
  cBtn: { flex: 1, minHeight: 60, borderRadius: raio.r, alignItems: 'center', justifyContent: 'center' },
  cCancelar: { backgroundColor: cores.campo },
  cCancelarTxt: { fontSize: 17, color: cores.muted, fontFamily: fonte.sansBold },
  cOk: { backgroundColor: cores.pos },
  cOkTxt: { fontSize: 17, color: '#fff', fontFamily: fonte.sansBold },

  micWrap: { alignItems: 'center', paddingBottom: 20, paddingTop: 6, backgroundColor: cores.sidebar },
  mic: {
    width: 108, height: 108, borderRadius: 54, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center',
    shadowColor: cores.shadowInk, shadowOpacity: 0.4, shadowRadius: 14, shadowOffset: { width: 0, height: 6 }, elevation: 8,
  },
  micGrav: { backgroundColor: cores.danger },
  micOcupado: { backgroundColor: cores.accentDeep },
  dica: { fontSize: 14, color: 'rgba(255,255,255,0.7)', fontFamily: fonte.sansSemi, marginTop: 10 },
});
