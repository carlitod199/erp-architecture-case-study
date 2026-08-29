import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, Text, TextInput, Pressable, StyleSheet, Alert } from 'react-native';
import { useNavigation, useIsFocused } from '@react-navigation/native';
import {
  useAudioRecorder, RecordingPresets, AudioModule, setAudioModeAsync,
} from 'expo-audio';
import AppHeader from '../components/AppHeader';
import { Tela } from '../components/ui';
import BotaoGravar from '../components/voz/BotaoGravar';
import ChipsInterpretacao from '../components/voz/ChipsInterpretacao';
import FilaVoz from '../components/voz/FilaVoz';
import { detectarTipo, detectarValvula, detectarClassificacoes } from '../components/voz/interpretar';
import { transcreverAudio } from '../services/ia';
import {
  enfileirarAudio, listarVoz, pendentesVoz, marcarTranscrito,
  registrarErroVoz, removerVoz,
} from '../offline/voz';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { servicoDe } from './TarefasScreen';
import { cores, fonte, raio } from '../theme';

// Onda 3 — Registro por voz. O operador fala o que fez ("poda na válvula 5,
// terminei a fileira 12") e o app vira texto para pré-preencher o apontamento.
// ONLINE: grava → transcreve na hora (mesmo endpoint do Chat) → revisa o
//   texto + chips do que o app entendeu → "Usar na conclusão do serviço".
// OFFLINE: o áudio entra na fila local (voz_fila) e transcreve quando o sinal
//   volta — automático ao abrir esta tela online, ou pelo "Transcrever agora".

export default function VozScreen() {
  const nav = useNavigation();
  const focada = useIsFocused();
  const { online } = useSync();
  const { itens: valvulas } = useDadosSync('talhoes');
  const { itens: servicosAbertos } = useDadosSync('apontamentos_abertos');
  const gravador = useAudioRecorder(RecordingPresets.HIGH_QUALITY);

  const [gravando, setGravando] = useState(false);
  const [enviando, setEnviando] = useState(false); // transcrevendo o áudio recém-gravado
  const [duracao, setDuracao] = useState(0);
  const [permNegada, setPermNegada] = useState(false);

  // editor do resultado (gravação online transcrita na hora)
  const [texto, setTexto] = useState(null); // null = sem resultado aberto
  const [tipoSel, setTipoSel] = useState(null); // id do tipo detectado/corrigido
  const [valvSel, setValvSel] = useState(null); // ITEM da válvula detectada/corrigida

  // fila offline
  const [fila, setFila] = useState([]);
  const [processando, setProcessando] = useState(false);
  const processandoRef = useRef(false);

  // cronômetro da gravação (duração ao vivo)
  useEffect(() => {
    if (!gravando) return undefined;
    const t = setInterval(() => setDuracao((d) => d + 1), 1000);
    return () => clearInterval(t);
  }, [gravando]);

  const recarregarFila = useCallback(async () => {
    try { setFila(await listarVoz()); } catch (_) { /* banco indisponível: lista fica como está */ }
  }, []);

  // percorre os pendentes chamando o MESMO endpoint de transcrição do Chat;
  // sucesso → 'transcrito' com o texto; falha transitória → segue pendente
  const processarPendentes = useCallback(async () => {
    if (processandoRef.current) return;
    const pend = await pendentesVoz();
    if (pend.length === 0) return;
    processandoRef.current = true;
    setProcessando(true);
    try {
      for (const p of pend) {
        try {
          const t = await transcreverAudio(p.uri);
          await marcarTranscrito(p.uuid, t);
        } catch (e) {
          await registrarErroVoz(p.uuid, e?.message || 'sem resposta do servidor');
        }
      }
    } finally {
      processandoRef.current = false;
      setProcessando(false);
      recarregarFila();
    }
  }, [recarregarFila]);

  // ao abrir a tela: recarrega a fila; se estiver online, já tenta transcrever
  // os pendentes (sem transcrição automática em background — decisão da Onda 3:
  // o botão "Transcrever agora" + esta passada ao abrir a tela bastam)
  useEffect(() => { if (focada) recarregarFila(); }, [focada, recarregarFila]);
  useEffect(() => {
    if (focada && online) processarPendentes();
  }, [focada, online, processarPendentes]);

  async function alternarGravacao() {
    if (enviando) return;

    if (gravando) {
      setGravando(false);
      try { await gravador.stop(); } catch (_) { /* stop duplo não derruba a tela */ }
      const uri = gravador.uri;
      if (!uri || duracao < 1) {
        Alert.alert('Registro por voz', 'Gravação muito curta — segure o ritmo: toque, fale e toque para parar.');
        return;
      }
      if (online) {
        setEnviando(true);
        try {
          const t = await transcreverAudio(uri);
          setTexto(t);
          setTipoSel(detectarTipo(t)?.id || null);
          setValvSel(detectarValvula(t, valvulas));
        } catch (e) {
          // online mas o servidor não respondeu: o áudio NÃO se perde — vai pra fila
          await enfileirarAudio(uri);
          await recarregarFila();
          Alert.alert(
            'Registro por voz',
            `Não consegui transcrever agora (${e?.message || 'erro'}). O áudio ficou na fila — toque em "Transcrever agora" quando der.`
          );
        } finally {
          setEnviando(false);
        }
      } else {
        // sem sinal: guarda o áudio; transcreve quando reconectar
        await enfileirarAudio(uri);
        await recarregarFila();
      }
      return;
    }

    // iniciar gravação
    const perm = await AudioModule.requestRecordingPermissionsAsync();
    if (!perm.granted) { setPermNegada(true); return; }
    setPermNegada(false);
    await setAudioModeAsync({ allowsRecording: true, playsInSilentMode: true });
    await gravador.prepareToRecordAsync();
    gravador.record();
    setDuracao(0);
    setGravando(true);
  }

  function limparEditor() {
    setTexto(null); setTipoSel(null); setValvSel(null);
  }

  // Inversão do gestor (22/07): apontamento NUNCA nasce no app. O texto da
  // voz alimenta a CONCLUSÃO de um serviço que o escritório já iniciou —
  // acha o serviço em aberto da válvula detectada (ou por tipo) e abre a
  // tela de conclusão com a observação pré-preenchida.
  function usarNoApontamento(textoUsar, tipoId, valv) {
    const corpo = String(textoUsar || '').trim();
    if (!corpo) return false;

    // COLHEITA por voz (23/07, produção-só): "válvula 2D, 40 caixas premium,
    // 3 perdidos" → abre o registro de produção com tudo pré-preenchido
    if (tipoId === 'colheita') {
      const { classificacoes, unidade } = detectarClassificacoes(corpo);
      nav.navigate('Apontamento', {
        soTipo: 'colheita',
        valvula: valv || undefined,
        vozClassificacoes: Object.keys(classificacoes).length ? classificacoes : undefined,
        vozUnidade: unidade || undefined,
        vozTexto: corpo,
      });
      limparEditor();
      return true;
    }

    const abertos = (servicosAbertos || []).filter((a) => (
      !valv || String(a.talhao_id) === String(valv.talhao_id || valv.id)
    ));
    if (abertos.length === 0) {
      Alert.alert(
        'Nenhum serviço em aberto',
        valv
          ? `Não há serviço em aberto na ${valv.nome}. Os apontamentos são iniciados pelo escritório no VERO.`
          : 'Não há serviços em aberto no momento. Os apontamentos são iniciados pelo escritório no VERO.'
      );
      return false;
    }
    // válvula com mais de um serviço: prefere o do tipo detectado
    const alvo = abertos.find((a) => tipoId && a.tipo === tipoId) || abertos[0];
    nav.navigate('TarefasTab', {
      screen: 'TarefaDetalhe',
      params: { servico: servicoDe(alvo), vozTexto: corpo },
    });
    limparEditor();
    return true;
  }

  // item transcrito da fila → interpreta e navega; só sai da fila se a
  // conclusão abriu (sem serviço aberto, o texto NÃO se perde)
  async function usarDaFila(item) {
    const tp = detectarTipo(item.texto)?.id || null;
    const vv = detectarValvula(item.texto, valvulas);
    if (usarNoApontamento(item.texto, tp, vv)) {
      await removerVoz(item.uuid);
      await recarregarFila();
    }
  }

  function descartarDaFila(item) {
    Alert.alert('Descartar áudio', 'Este registro de voz será apagado do aparelho.', [
      { text: 'Manter', style: 'cancel' },
      {
        text: 'Descartar',
        style: 'destructive',
        onPress: async () => { await removerVoz(item.uuid); recarregarFila(); },
      },
    ]);
  }

  const sub = gravando ? '🔴 gravando…'
    : enviando ? 'transcrevendo…'
    : online ? 'fale o que fez no campo'
    : 'sem sinal — grava e transcreve depois';

  return (
    <View style={styles.tela}>
      <AppHeader titulo="Registrar por voz" sub={sub} />
      <Tela refrescar={false}>
        {/* estado offline explícito */}
        {!online && (
          <View style={styles.avisoOffline}>
            <Text style={styles.avisoOfflineTxt}>
              📡 Sem sinal. Grave normal: o áudio fica na fila e vira texto quando a conexão voltar.
            </Text>
          </View>
        )}

        {/* microfone negado → orientação + tentar de novo */}
        {permNegada && (
          <View style={styles.card}>
            <Text style={styles.cardTitulo}>Microfone bloqueado</Text>
            <Text style={styles.dica}>
              Para registrar por voz, permita o microfone: Configurações do aparelho → Apps → VERO Campo → Permissões → Microfone.
            </Text>
            <Pressable style={styles.btnPrim} onPress={alternarGravacao}>
              <Text style={styles.btnPrimTxt}>Tentar de novo</Text>
            </Pressable>
          </View>
        )}

        {texto === null ? (
          <>
            <BotaoGravar
              gravando={gravando}
              enviando={enviando}
              duracao={duracao}
              onPress={alternarGravacao}
            />
            {!gravando && !enviando && fila.length === 0 && (
              <Text style={styles.exemplo}>
                Fale o que fez — ex.: “poda na válvula 5, terminei a fileira 12”.
                {'\n'}O texto vem pronto para o apontamento.
              </Text>
            )}
            <FilaVoz
              itens={fila}
              online={online}
              processando={processando}
              aoTranscrever={processarPendentes}
              aoUsar={usarDaFila}
              aoDescartar={descartarDaFila}
            />
          </>
        ) : (
          /* resultado da transcrição — texto editável + chips corrigíveis */
          <View style={styles.card}>
            <Text style={styles.cardTitulo}>Confira o que você falou</Text>
            <TextInput
              style={styles.input}
              value={texto}
              onChangeText={setTexto}
              multiline
              placeholder="O texto transcrito aparece aqui…"
              placeholderTextColor={cores.faint}
            />
            <ChipsInterpretacao
              tipo={tipoSel}
              aoTrocarTipo={setTipoSel}
              valvula={valvSel}
              aoTrocarValvula={setValvSel}
              valvulas={valvulas}
            />
            <View style={styles.acoes}>
              <Pressable style={styles.btnSec} onPress={limparEditor}>
                <Text style={styles.btnSecTxt}>Gravar de novo</Text>
              </Pressable>
              <Pressable
                style={[styles.btnPrim, { flex: 1.6 }, !String(texto).trim() && { opacity: 0.4 }]}
                disabled={!String(texto).trim()}
                onPress={() => usarNoApontamento(texto, tipoSel, valvSel)}
              >
                <Text style={styles.btnPrimTxt}>Usar na conclusão do serviço</Text>
              </Pressable>
            </View>
          </View>
        )}
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  avisoOffline: {
    backgroundColor: cores.amberBg, borderRadius: raio.r, padding: 12,
  },
  avisoOfflineTxt: { fontSize: 12.5, color: cores.amberDeep, fontFamily: fonte.sansSemi, lineHeight: 18 },
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 15, gap: 12 },
  cardTitulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  dica: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 18 },
  exemplo: {
    fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed,
    textAlign: 'center', lineHeight: 19,
  },
  input: {
    minHeight: 96, backgroundColor: cores.campo, borderRadius: raio.sm,
    padding: 12, fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansMed,
    lineHeight: 21, textAlignVertical: 'top',
  },
  acoes: { flexDirection: 'row', gap: 10 },
  btnPrim: {
    flex: 1, minHeight: 52, borderRadius: raio.r, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center',
  },
  btnPrimTxt: { fontSize: 15, color: cores.surface, fontFamily: fonte.sansBold },
  btnSec: {
    flex: 1, minHeight: 52, borderRadius: raio.r, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  btnSecTxt: { fontSize: 15, color: cores.accent, fontFamily: fonte.sansBold },
});
