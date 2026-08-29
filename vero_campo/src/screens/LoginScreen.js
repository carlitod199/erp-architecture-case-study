import React, { useEffect, useRef, useState } from 'react';
import {
  View, Text, TextInput, Pressable, Image, Animated, Easing, StyleSheet, Alert,
} from 'react-native';
import CarregandoVero from '../components/CarregandoVero';
import { LinearGradient } from 'expo-linear-gradient';
import Svg, { Path, Defs, LinearGradient as SvgGrad, Stop } from 'react-native-svg';
import { cores, fonte, raio, espaco } from '../theme';
import { useAuth } from '../context/AuthContext';
import biometria from '../services/biometria';

// erros de rede no login: distinguir "sem internet" de "código de empresa
// errado" evita o operador apagar configuração correta achando que é o código.
// Retorna { titulo, msg } para erro de rede/host; null → segue a mensagem da
// API (ex.: credenciais_invalidas).
function mapearErroLogin(e) {
  if (e?.codigo === 'sem_conexao') {
    // NÃO usar a mensagem genérica do http (fala de fila offline, não serve aqui)
    return { titulo: 'Sem conexão', msg: 'Verifique sua internet e tente de novo.' };
  }
  if (e?.codigo === 'host_nao_encontrado') {
    return { titulo: 'Código não encontrado', msg: 'Confira o código da empresa com o suporte.' };
  }
  return null;
}

export default function LoginScreen() {
  const { entrar, codigoEmpresa, trocarEmpresa } = useAuth();
  const [email, setEmail] = useState('');
  const [senha, setSenha] = useState('');
  const [verSenha, setVerSenha] = useState(false);
  const [lembrar, setLembrar] = useState(true);
  const [enviando, setEnviando] = useState(false);

  // biometria (spec item 0): sensor disponível no aparelho? preferência ligada?
  // bioPronta = preferência ativa + e-mail salvo + sensor com cadastro → mostra
  // o botão "Entrar como {email}". bioDisponivel && !ativa → oferece o toggle.
  const [bioDisponivel, setBioDisponivel] = useState(false);
  const [bioAtiva, setBioAtiva] = useState(false);
  const [bioEmail, setBioEmail] = useState(null);
  const [ofertaBio, setOfertaBio] = useState(false); // toggle "da próxima vez"
  const [bioEntrando, setBioEntrando] = useState(false);
  const bioPronta = bioAtiva && !!bioEmail && bioDisponivel;

  useEffect(() => {
    let vivo = true;
    (async () => {
      const [disp, pref] = await Promise.all([
        biometria.disponivelAsync(),
        biometria.lerPreferencia(),
      ]);
      if (!vivo) return;
      setBioDisponivel(disp);
      setBioAtiva(pref.ativa);
      setBioEmail(pref.email);
      // reentrada rápida: e-mail conhecido já vem preenchido no form
      if (pref.email) setEmail((atual) => atual || pref.email);
    })();
    return () => { vivo = false; };
  }, []);

  // animação de entrada: UM único valor 0->1 dirige logo e form por interpolação
  // (menos timers no JS na abertura = sem engasgo) e só começa depois que o
  // primeiro frame assenta (rAF), evitando o pisca no boot
  const entrada = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    const raf = requestAnimationFrame(() => {
      Animated.timing(entrada, {
        toValue: 1,
        duration: 900,
        // o Login monta POR BAIXO do splash (overlay do RootNavigator); o
        // delay faz a subida do logo/form acontecer à vista, cruzando com o
        // fade-out do splash em vez de terminar escondida
        delay: 300,
        easing: Easing.out(Easing.cubic),
        useNativeDriver: true,
      }).start();
    });
    return () => cancelAnimationFrame(raf);
  }, []);

  const logoOp = entrada.interpolate({ inputRange: [0, 0.55], outputRange: [0, 1], extrapolate: 'clamp' });
  const logoY = entrada.interpolate({ inputRange: [0, 0.55], outputRange: [16, 0], extrapolate: 'clamp' });
  const formOp = entrada.interpolate({ inputRange: [0.3, 1], outputRange: [0, 1], extrapolate: 'clamp' });
  const formY = entrada.interpolate({ inputRange: [0.3, 1], outputRange: [28, 0], extrapolate: 'clamp' });

  // código errado na primeira abertura passava na regex, era salvo e o
  // operador ficava PRESO no Login ("Trocar empresa" morava só em Ajustes,
  // atrás do login). Este é o caminho de volta pré-autenticação. A troca
  // expurga banco local e biometria — por isso a confirmação.
  function confirmarTrocaEmpresa() {
    Alert.alert(
      'Trocar código da empresa',
      `O código atual é "${codigoEmpresa}". Registros offline ainda não enviados serão apagados.`,
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Trocar', style: 'destructive', onPress: () => { trocarEmpresa().catch(() => {}); } },
      ]
    );
  }

  async function onEntrar() {
    if (enviando || bioEntrando) return;
    setEnviando(true);
    try {
      const mail = email.trim();
      await entrar(mail, senha, { lembrar });
      // biometria: toggle ligado agora OU já ativa (login manual renova a
      // credencial guardada — cobre troca de senha/conta). Fail-safe: um erro
      // aqui não pode derrubar o login que já deu certo.
      if (ofertaBio || bioAtiva) {
        try { await biometria.ativar(mail, senha); } catch (_) {}
      }
      // navegação acontece sozinha (RootNavigator observa o AuthContext)
    } catch (e) {
      const rede = mapearErroLogin(e);
      if (rede) {
        // host não achado = código provavelmente errado: oferece a correção
        // aqui mesmo (ainda via confirmação — pode ser só DNS/rede ruim)
        const botoes = e?.codigo === 'host_nao_encontrado'
          ? [
              { text: 'Agora não', style: 'cancel' },
              { text: 'Trocar código', onPress: confirmarTrocaEmpresa },
            ]
          : undefined;
        Alert.alert(rede.titulo, rede.msg, botoes);
      } else {
        Alert.alert('Não foi possível entrar', e?.message || 'Verifique e-mail e senha.');
      }
    } finally {
      setEnviando(false);
    }
  }

  // botão "🔒 Entrar como {email}": sensor aprova → reusa a credencial do
  // SecureStore SEM redigitar. Falha/cancelamento → segue no form, sem loop
  // (o prompt só abre por toque, nunca sozinho na montagem).
  async function onEntrarBio() {
    if (enviando || bioEntrando) return;
    setBioEntrando(true);
    try {
      const r = await biometria.autenticar('Entrar no VERO Campo');
      if (!r.ok) {
        const silencioso = ['user_cancel', 'system_cancel', 'app_cancel'].includes(r.erro);
        if (!silencioso) {
          Alert.alert('Biometria indisponível', 'Entre com sua senha.');
        }
        return;
      }
      const senhaGuardada = await biometria.lerSenha();
      if (!senhaGuardada) {
        await biometria.desativar();
        setBioAtiva(false);
        Alert.alert('Sessão expirada', 'Entre com sua senha para reativar a biometria.');
        return;
      }
      try {
        await entrar(bioEmail, senhaGuardada, { lembrar: true });
      } catch (e) {
        // erro de rede NÃO é senha trocada: mantém a biometria ativa e só
        // avisa — desativar aqui faria o operador perder o atalho sem motivo
        const rede = mapearErroLogin(e);
        if (rede) {
          Alert.alert(rede.titulo, rede.msg);
          return;
        }
        // credencial guardada não vale mais (senha trocada no servidor):
        // apaga para não insistir e cai no form com o e-mail preenchido
        await biometria.desativar();
        setBioAtiva(false);
        Alert.alert('Sua senha mudou', 'Entre com a nova senha para reativar a biometria.');
      }
    } finally {
      setBioEntrando(false);
    }
  }

  return (
    <LinearGradient
      colors={['#0a5058', '#00464e', '#00363d', '#08262a']}
      locations={[0, 0.34, 0.66, 1]}
      start={{ x: 0.1, y: 0 }}
      end={{ x: 0.9, y: 1 }}
      style={styles.tela}
    >
      {/* sulcos do talhão na base */}
      <Svg style={styles.furrows} viewBox="0 0 390 230" preserveAspectRatio="none">
        <Defs>
          <SvgGrad id="fg" x1="0" y1="0" x2="0" y2="1">
            <Stop offset="0" stopColor="#A2CC4E" stopOpacity="0" />
            <Stop offset="1" stopColor="#A2CC4E" stopOpacity="0.16" />
          </SvgGrad>
        </Defs>
        <Path d="M-20 150 Q195 90 410 150 L410 230 L-20 230 Z" fill="url(#fg)" />
        <Path d="M-20 168 Q195 112 410 168" fill="none" stroke="#A2CC4E" strokeOpacity="0.28" strokeWidth="2" />
        <Path d="M-20 188 Q195 136 410 188" fill="none" stroke="#A2CC4E" strokeOpacity="0.2" strokeWidth="2" />
        <Path d="M-20 208 Q195 160 410 208" fill="none" stroke="#A2CC4E" strokeOpacity="0.13" strokeWidth="2" />
      </Svg>

      <View style={styles.centro}>
        <Animated.Image
          source={require('../../assets/logo_vero.png')}
          style={[styles.logo, { opacity: logoOp, transform: [{ translateY: logoY }] }]}
          resizeMode="contain"
        />

        <Animated.View style={{ opacity: formOp, transform: [{ translateY: formY }], width: '100%' }}>
          {bioPronta && (
            <>
              <Pressable style={styles.bioBtn} onPress={onEntrarBio} disabled={bioEntrando || enviando}>
                {bioEntrando ? (
                  <CarregandoVero tamanho={28} cor={cores.lime} />
                ) : (
                  <Text style={styles.bioBtnTxt} numberOfLines={1}>
                    🔒 Entrar como {bioEmail}
                  </Text>
                )}
              </Pressable>
              <View style={styles.bioSep}>
                <View style={styles.bioSepLinha} />
                <Text style={styles.bioSepTxt}>ou com e-mail e senha</Text>
                <View style={styles.bioSepLinha} />
              </View>
            </>
          )}

          <View style={styles.campo}>
            <Text style={styles.ic}>✉</Text>
            <TextInput
              style={styles.input}
              value={email}
              onChangeText={setEmail}
              placeholder="E-mail"
              placeholderTextColor="#cfe0de"
              autoCapitalize="none"
              keyboardType="email-address"
            />
          </View>

          <View style={styles.campo}>
            <Text style={styles.ic}>🔒</Text>
            <TextInput
              style={styles.input}
              value={senha}
              onChangeText={setSenha}
              placeholder="Senha"
              placeholderTextColor="#cfe0de"
              secureTextEntry={!verSenha}
            />
            <Pressable onPress={() => setVerSenha((v) => !v)} hitSlop={10}>
              <Text style={styles.olho}>{verSenha ? '🙈' : '👁'}</Text>
            </Pressable>
          </View>

          <Pressable style={styles.lembrar} onPress={() => setLembrar((v) => !v)}>
            <View style={[styles.switch, !lembrar && styles.switchOff]}>
              <View style={[styles.knob, lembrar && styles.knobOn]} />
            </View>
            <Text style={styles.lembrarTxt}>Lembrar este aparelho por 30 dias</Text>
          </Pressable>

          {bioDisponivel && !bioAtiva && (
            <Pressable style={styles.lembrarBio} onPress={() => setOfertaBio((v) => !v)}>
              <View style={[styles.switch, !ofertaBio && styles.switchOff]}>
                <View style={[styles.knob, ofertaBio && styles.knobOn]} />
              </View>
              <Text style={styles.lembrarTxt}>Entrar com biometria da próxima vez</Text>
            </Pressable>
          )}

          <Pressable style={styles.entrar} onPress={onEntrar}>
            {enviando ? (
              <CarregandoVero tamanho={28} cor={cores.limeInk} />
            ) : (
              <Text style={styles.entrarTxt}>Entrar</Text>
            )}
          </Pressable>

          <View style={styles.foot}>
            <View style={styles.dot} />
            <Text style={styles.footTxt}>Modo offline pronto</Text>
            {!!codigoEmpresa && (
              <Text style={styles.footTxt} numberOfLines={1}>· empresa: {codigoEmpresa}</Text>
            )}
          </View>

          {/* saída pré-login para código digitado errado na primeira abertura */}
          {!!codigoEmpresa && (
            <Pressable style={styles.trocarEmpresa} onPress={confirmarTrocaEmpresa} hitSlop={8}>
              <Text style={styles.trocarEmpresaTxt}>Trocar código da empresa</Text>
            </Pressable>
          )}
        </Animated.View>
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1 },
  furrows: { position: 'absolute', left: 0, right: 0, bottom: 0, height: 230 },
  centro: { flex: 1, justifyContent: 'center', paddingHorizontal: 26 },
  logo: { width: 196, height: 60, alignSelf: 'center', marginBottom: 34 },
  campo: {
    flexDirection: 'row', alignItems: 'center', gap: 12, height: 56, paddingHorizontal: 15,
    borderRadius: 15, marginBottom: 13,
    backgroundColor: 'rgba(255,255,255,0.07)', borderWidth: 1.5, borderColor: 'rgba(255,255,255,0.16)',
  },
  ic: { fontSize: 16, color: 'rgba(255,255,255,0.6)' },
  input: { flex: 1, color: '#fff', fontSize: 14.5, fontFamily: fonte.sansMed },
  olho: { fontSize: 16, color: 'rgba(255,255,255,0.6)' },
  lembrar: { flexDirection: 'row', alignItems: 'center', gap: 11, marginTop: 6, marginBottom: 22 },
  // toggle de oferta da biometria: mesmo padrão visual do "lembrar", alvo ≥ toque
  lembrarBio: {
    flexDirection: 'row', alignItems: 'center', gap: 11,
    minHeight: espaco.toque, marginTop: -14, marginBottom: 22,
  },
  // botão "Entrar como {email}": mesmo porte do CTA (56 ≥ espaco.toque)
  bioBtn: {
    height: 56, borderRadius: 16, alignItems: 'center', justifyContent: 'center',
    paddingHorizontal: 15, backgroundColor: 'rgba(45,212,191,0.12)',
    borderWidth: 1.5, borderColor: 'rgba(45,212,191,0.55)',
  },
  bioBtnTxt: { color: cores.limeNeon, fontSize: 14.5, fontFamily: fonte.sansBold },
  bioSep: { flexDirection: 'row', alignItems: 'center', gap: 10, marginVertical: 16 },
  bioSepLinha: { flex: 1, height: 1, backgroundColor: 'rgba(255,255,255,0.14)' },
  bioSepTxt: { color: 'rgba(255,255,255,0.5)', fontSize: 11.5, fontFamily: fonte.sansMed },
  switch: { width: 42, height: 25, borderRadius: 20, backgroundColor: cores.lime, justifyContent: 'center' },
  switchOff: { backgroundColor: 'rgba(255,255,255,0.25)' },
  knob: { width: 19, height: 19, borderRadius: 10, backgroundColor: '#08262a', marginLeft: 3 },
  knobOn: { marginLeft: 20 },
  lembrarTxt: { color: 'rgba(255,255,255,0.72)', fontSize: 12.5, fontFamily: fonte.sansMed, flex: 1 },
  entrar: {
    height: 56, borderRadius: 16, backgroundColor: cores.lime, alignItems: 'center', justifyContent: 'center',
    shadowColor: '#000', shadowOpacity: 0.28, shadowRadius: 20, shadowOffset: { width: 0, height: 10 }, elevation: 6,
  },
  entrarTxt: { color: cores.limeInk, fontSize: 15.5, fontFamily: fonte.sansBold },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, marginTop: 20 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: cores.lime },
  footTxt: { color: 'rgba(255,255,255,0.5)', fontSize: 11, fontFamily: fonte.sansMed },
  trocarEmpresa: { alignSelf: 'center', marginTop: 12, minHeight: espaco.toque, justifyContent: 'center' },
  trocarEmpresaTxt: {
    color: 'rgba(255,255,255,0.6)', fontSize: 12, fontFamily: fonte.sansMed,
    textDecorationLine: 'underline',
  },
});
