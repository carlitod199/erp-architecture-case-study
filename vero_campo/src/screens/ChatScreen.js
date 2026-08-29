import React, { useRef, useState } from 'react';
import {
  View, Text, TextInput, Pressable, FlatList, ScrollView,
  KeyboardAvoidingView, Platform, StyleSheet, Alert,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation } from '@react-navigation/native';
import {
  useAudioRecorder, RecordingPresets, AudioModule, setAudioModeAsync,
} from 'expo-audio';
import { cores, fonte, raio, espaco } from '../theme';
import Icone from '../components/Icone';
import { perguntar, transcreverAudio } from '../services/ia';

// Assistente de IA do VERO Campo — consultas simples por texto ou voz.
// Demo local hoje; quando a API /ia existir, só o serviço `ia.js` muda.

const SUGESTOES = [
  'Clima na válvula 5A',
  'Minhas tarefas de hoje',
  'Alertas abertos',
  'Estoque de defensivos',
];

const BOAS_VINDAS = {
  id: 'boas-vindas',
  papel: 'assistente',
  texto: 'Olá! Sou o assistente do VERO. Pergunte sobre o clima das válvulas, suas tarefas, alertas, estoque ou irrigação — por texto ou pelo microfone. 🎙',
};

// renderiza **negrito** do assistente como negrito de verdade (único markdown aceito)
function TextoRico({ texto, estilo, corNegrito }) {
  const partes = String(texto).split(/(\*\*[^*]+\*\*)/g);
  return (
    <Text style={estilo}>
      {partes.map((p, i) =>
        p.startsWith('**') && p.endsWith('**') ? (
          <Text key={i} style={{ fontFamily: fonte.sansBold, color: corNegrito }}>
            {p.slice(2, -2)}
          </Text>
        ) : (
          p
        )
      )}
    </Text>
  );
}

const horaAgora = () => {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

export default function ChatScreen() {
  const [msgs, setMsgs] = useState([BOAS_VINDAS]);
  const [txt, setTxt] = useState('');
  const [pendente, setPendente] = useState(null); // { resumo } — escrita aguardando confirmação
  const [pensando, setPensando] = useState(false);
  const [gravando, setGravando] = useState(false);
  const [transcrevendo, setTranscrevendo] = useState(false);
  const lista = useRef(null);
  const gravador = useAudioRecorder(RecordingPresets.HIGH_QUALITY);
  const navigation = useNavigation();

  // abre o modo mãos-livres (Fase 6) — a rota "Agente" vive na RegistrarStack
  const irMaosLivres = () =>
    navigation.navigate('RegistrarTab', { screen: 'Agente', params: { _novo: Date.now() } });

  const rolarFim = () => requestAnimationFrame(() => lista.current?.scrollToEnd({ animated: true }));

  async function enviar(textoLivre) {
    const corpo = (textoLivre ?? txt).trim();
    if (!corpo || pensando) return;
    setTxt('');
    setPendente(null); // qualquer nova mensagem descarta a confirmação anterior
    const minha = { id: `u${Date.now()}`, papel: 'usuario', texto: corpo, h: horaAgora() };
    const historico = [...msgs, minha];
    setMsgs(historico);
    setPensando(true);
    rolarFim();
    try {
      const r = await perguntar(
        historico.filter((m) => m.id !== 'boas-vindas').map((m) => ({ papel: m.papel, texto: m.texto }))
      );
      setMsgs((m) => [...m, { id: `a${Date.now()}`, papel: 'assistente', texto: r.resposta, h: horaAgora() }]);
      setPendente(r.pendente || null); // agente pediu confirmação de uma escrita?
    } catch (e) {
      setMsgs((m) => [...m, {
        id: `e${Date.now()}`, papel: 'assistente',
        texto: `Não consegui responder agora (${e?.message || 'erro'}). Tente de novo.`, h: horaAgora(),
      }]);
    } finally {
      setPensando(false);
      rolarFim();
    }
  }

  async function alternarGravacao() {
    if (transcrevendo) return;
    if (gravando) {
      // parar e transcrever
      setGravando(false);
      setTranscrevendo(true);
      try {
        await gravador.stop();
        const texto = await transcreverAudio(gravador.uri);
        setTxt(texto); // vai para o campo — o usuário revisa e envia
      } catch (e) {
        Alert.alert('Comando de voz', e?.message || 'Não consegui transcrever o áudio.');
      } finally {
        setTranscrevendo(false);
      }
      return;
    }
    // iniciar gravação
    const perm = await AudioModule.requestRecordingPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Comando de voz', 'Permita o acesso ao microfone para usar comandos de voz.');
      return;
    }
    await setAudioModeAsync({ allowsRecording: true, playsInSilentMode: true });
    await gravador.prepareToRecordAsync();
    gravador.record();
    setGravando(true);
  }

  return (
    <SafeAreaView style={styles.tela} edges={['top']}>
      <View style={styles.topbar}>
        <View style={styles.avatarIa}><Text style={styles.avatarIaTxt}>✦</Text></View>
        <View style={{ flex: 1 }}>
          <Text style={styles.titulo}>Assistente VERO</Text>
          <Text style={styles.sub}>
            {gravando ? '🔴 gravando… toque no microfone para parar'
              : transcrevendo ? 'transcrevendo áudio…'
              : pensando ? 'digitando…'
              : 'consultas por texto ou voz'}
          </Text>
        </View>
        <Pressable
          style={styles.maosLivres}
          onPress={irMaosLivres}
          accessibilityLabel="Modo mãos-livres"
        >
          <Icone nome="microfone" tam={20} cor={cores.lime} />
        </Pressable>
      </View>

      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <FlatList
          ref={lista}
          data={pensando ? [...msgs, { id: '__digitando', papel: 'assistente', digitando: true }] : msgs}
          keyExtractor={(m) => m.id}
          contentContainerStyle={styles.corpo}
          onContentSizeChange={rolarFim}
          renderItem={({ item }) => (
            <View style={[styles.balao, item.papel === 'usuario' ? styles.meu : styles.dele]}>
              {item.digitando ? (
                <Text style={styles.digitando}>● ● ●</Text>
              ) : (
                <>
                  <TextoRico
                    texto={item.texto}
                    estilo={[styles.msg, item.papel === 'usuario' && { color: '#fff' }]}
                    corNegrito={item.papel === 'usuario' ? '#fff' : cores.accent}
                  />
                  {!!item.h && (
                    <Text style={[styles.hora, item.papel === 'usuario' && { color: 'rgba(255,255,255,0.65)' }]}>
                      {item.h}
                    </Text>
                  )}
                </>
              )}
            </View>
          )}
        />

        {/* cartão de confirmação: aparece quando o agente tem uma ESCRITA
            aguardando o "sim". Confirmar reenvia o aceite ao agente. */}
        {pendente && !pensando ? (
          <View style={styles.confirmar}>
            <Text style={styles.confirmarRot}>Confirmar ação</Text>
            <Text style={styles.confirmarTxt} numberOfLines={3}>{pendente.resumo}</Text>
            <View style={styles.confirmarBtns}>
              <Pressable style={[styles.cBtn, styles.cCancelar]} onPress={() => setPendente(null)}>
                <Text style={styles.cCancelarTxt}>Cancelar</Text>
              </Pressable>
              <Pressable style={[styles.cBtn, styles.cOk]} onPress={() => enviar('Sim, confirmo. Pode executar.')}>
                <Text style={styles.cOkTxt}>Confirmar ✓</Text>
              </Pressable>
            </View>
          </View>
        ) : (
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            style={styles.chipsWrap}
            contentContainerStyle={styles.chips}
            keyboardShouldPersistTaps="handled"
          >
            {SUGESTOES.map((s) => (
              <Pressable key={s} style={styles.chip} onPress={() => enviar(s)}>
                <Text style={styles.chipTxt}>{s}</Text>
              </Pressable>
            ))}
          </ScrollView>
        )}

        <View style={styles.rodape}>
          <TextInput
            style={styles.input}
            value={txt}
            onChangeText={setTxt}
            placeholder={gravando ? 'Gravando comando de voz…' : 'Pergunte algo…'}
            placeholderTextColor={cores.muted2}
            multiline
            editable={!gravando}
          />
          <Pressable
            style={[styles.mic, gravando && styles.micGravando]}
            onPress={alternarGravacao}
            accessibilityLabel={gravando ? 'Parar gravação' : 'Comando de voz'}
          >
            <Text style={styles.micTxt}>{gravando ? '■' : '🎙'}</Text>
          </Pressable>
          <Pressable style={styles.enviar} onPress={() => enviar()} accessibilityLabel="Enviar">
            <Text style={styles.enviarTxt}>➤</Text>
          </Pressable>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.sidebar },
  topbar: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    paddingHorizontal: espaco.md, paddingVertical: 12, backgroundColor: cores.sidebar,
  },
  avatarIa: {
    width: 40, height: 40, borderRadius: 20, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center',
  },
  avatarIaTxt: { fontSize: 18, color: cores.limeInk },
  maosLivres: {
    width: 40, height: 40, borderRadius: 20, alignItems: 'center', justifyContent: 'center',
    borderWidth: 1.5, borderColor: 'rgba(45,212,191,0.5)',
  },
  titulo: { fontSize: 17, color: '#fff', fontFamily: fonte.sansBold },
  sub: { fontSize: 11.5, color: 'rgba(255,255,255,0.6)', fontFamily: fonte.sansMed, marginTop: 1 },
  corpo: { padding: espaco.md, gap: 9, backgroundColor: cores.page, flexGrow: 1 },
  balao: { maxWidth: '84%', borderRadius: raio.card, padding: 11 },
  dele: { alignSelf: 'flex-start', backgroundColor: cores.surface, borderTopLeftRadius: 4 },
  meu: { alignSelf: 'flex-end', backgroundColor: cores.accent, borderBottomRightRadius: 4 },
  msg: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed, lineHeight: 19 },
  hora: { fontSize: 9.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 4, alignSelf: 'flex-end' },
  digitando: { fontSize: 11, color: cores.muted, letterSpacing: 2 },
  // flexShrink 0 + altura mínima: sem isso o layout espreme a faixa e corta o texto
  chipsWrap: { backgroundColor: cores.page, flexGrow: 0, flexShrink: 0, minHeight: 48 },
  chips: { gap: 7, paddingHorizontal: espaco.md, paddingBottom: 8, alignItems: 'center' },
  chip: {
    paddingHorizontal: 13, height: 36, borderRadius: 20, backgroundColor: cores.surface,
    alignItems: 'center', justifyContent: 'center',
  },
  chipTxt: {
    fontSize: 12, lineHeight: 16, color: cores.accent, fontFamily: fonte.sansSemi,
    includeFontPadding: false,
  },
  confirmar: {
    backgroundColor: cores.surface, marginHorizontal: espaco.md, marginBottom: 8,
    borderRadius: raio.card, padding: 13, borderWidth: 1.5, borderColor: cores.accent,
  },
  confirmarRot: { fontSize: 10.5, letterSpacing: 1, textTransform: 'uppercase', color: cores.accent, fontFamily: fonte.sansBold },
  confirmarTxt: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansSemi, marginTop: 5, lineHeight: 19 },
  confirmarBtns: { flexDirection: 'row', gap: 9, marginTop: 12 },
  cBtn: { flex: 1, height: 44, borderRadius: raio.r, alignItems: 'center', justifyContent: 'center' },
  cCancelar: { backgroundColor: cores.campo },
  cCancelarTxt: { fontSize: 13.5, color: cores.muted, fontFamily: fonte.sansBold },
  cOk: { backgroundColor: cores.accent },
  cOkTxt: { fontSize: 13.5, color: '#fff', fontFamily: fonte.sansBold },
  rodape: {
    flexDirection: 'row', alignItems: 'flex-end', gap: 8,
    padding: 10, backgroundColor: cores.page,
  },
  input: {
    flex: 1, minHeight: 46, maxHeight: 110, borderRadius: 23,
    paddingHorizontal: 16, paddingVertical: 12,
    backgroundColor: cores.surface, color: cores.ink, fontSize: 13.5, fontFamily: fonte.sansMed,
  },
  mic: {
    width: 46, height: 46, borderRadius: 23, backgroundColor: cores.surface,
    alignItems: 'center', justifyContent: 'center',
  },
  micGravando: { backgroundColor: cores.danger },
  micTxt: { fontSize: 18 },
  enviar: {
    width: 46, height: 46, borderRadius: 23, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center',
  },
  enviarTxt: { color: '#fff', fontSize: 18, marginLeft: 2 },
});
