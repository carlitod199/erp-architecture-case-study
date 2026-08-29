import React, { useEffect, useRef, useState } from 'react';
import {
  View, Text, TextInput, Pressable, Animated, Easing, StyleSheet,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import Svg, { Path, Defs, LinearGradient as SvgGrad, Stop } from 'react-native-svg';
import CarregandoVero from '../components/CarregandoVero';
import { cores, fonte } from '../theme';
import { useAuth } from '../context/AuthContext';
import { normalizarCodigo, SUFIXO_DOMINIO } from '../services/ambiente';

// Primeira abertura do app: um binário atende vários clientes, e cada cliente
// vive em https://<codigo>.example.com/api/v1. O operador digita o código UMA
// vez; o AuthContext salva e o RootNavigator segue sozinho para o Login.
// Visual irmão do LoginScreen (mesmo gradiente, sulcos e porte de campo/botão).
export default function CodigoEmpresaScreen() {
  const { definirEmpresa } = useAuth();
  const [codigo, setCodigo] = useState('');
  const [erro, setErro] = useState(null);
  const [enviando, setEnviando] = useState(false);

  // animação de entrada: mesmo padrão do Login — um valor 0->1 dirige logo e
  // form por interpolação, começando depois do primeiro frame (rAF)
  const entrada = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    const raf = requestAnimationFrame(() => {
      Animated.timing(entrada, {
        toValue: 1,
        duration: 900,
        delay: 300, // cruza com o fade-out do splash (overlay do RootNavigator)
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

  // prévia da URL enquanto digita: o operador confere ANTES de confirmar
  const previa = normalizarCodigo(codigo) || 'codigo';

  async function onConfirmar() {
    if (enviando) return;
    setErro(null);
    setEnviando(true);
    try {
      // validação é toda local (regex) — erro aqui NUNCA foi à rede
      await definirEmpresa(codigo);
      // navegação acontece sozinha (RootNavigator observa o AuthContext)
    } catch (e) {
      setErro(e?.message || 'Não foi possível salvar o código.');
    } finally {
      setEnviando(false);
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
          <Text style={styles.titulo}>Código da empresa</Text>
          <Text style={styles.apoio}>Peça ao responsável da fazenda, ex.: minhafazenda</Text>

          <View style={[styles.campo, !!erro && styles.campoErro]}>
            <Text style={styles.ic}>🏷</Text>
            <TextInput
              style={styles.input}
              value={codigo}
              onChangeText={(t) => { setCodigo(t); if (erro) setErro(null); }}
              placeholder="codigo"
              placeholderTextColor="#cfe0de"
              autoCapitalize="none"
              autoCorrect={false}
              spellCheck={false}
              keyboardType="default"
              returnKeyType="done"
              onSubmitEditing={onConfirmar}
            />
          </View>

          {!!erro && <Text style={styles.erroTxt}>{erro}</Text>}

          <Pressable style={styles.confirmar} onPress={onConfirmar}>
            {enviando ? (
              <CarregandoVero tamanho={28} cor={cores.limeInk} />
            ) : (
              <Text style={styles.confirmarTxt}>Continuar</Text>
            )}
          </Pressable>

          {/* rodapé discreto: URL que será usada, para o operador conferir */}
          <View style={styles.foot}>
            <View style={styles.dot} />
            <Text style={styles.footTxt}>{previa}.{SUFIXO_DOMINIO}</Text>
          </View>
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
  titulo: { color: '#fff', fontSize: 19, fontFamily: fonte.sansBold, marginBottom: 6 },
  apoio: { color: 'rgba(255,255,255,0.6)', fontSize: 12.5, fontFamily: fonte.sansMed, marginBottom: 18 },
  campo: {
    flexDirection: 'row', alignItems: 'center', gap: 12, height: 56, paddingHorizontal: 15,
    borderRadius: 15, marginBottom: 13,
    backgroundColor: 'rgba(255,255,255,0.07)', borderWidth: 1.5, borderColor: 'rgba(255,255,255,0.16)',
  },
  campoErro: { borderColor: 'rgba(255,120,110,0.65)' },
  ic: { fontSize: 16, color: 'rgba(255,255,255,0.6)' },
  input: { flex: 1, color: '#fff', fontSize: 14.5, fontFamily: fonte.sansMed },
  erroTxt: { color: '#ffb4ab', fontSize: 12.5, fontFamily: fonte.sansMed, marginTop: -4, marginBottom: 13 },
  confirmar: {
    height: 56, borderRadius: 16, backgroundColor: cores.lime, alignItems: 'center', justifyContent: 'center',
    marginTop: 6,
    shadowColor: '#000', shadowOpacity: 0.28, shadowRadius: 20, shadowOffset: { width: 0, height: 10 }, elevation: 6,
  },
  confirmarTxt: { color: cores.limeInk, fontSize: 15.5, fontFamily: fonte.sansBold },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, marginTop: 20 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: cores.lime },
  footTxt: { color: 'rgba(255,255,255,0.5)', fontSize: 11, fontFamily: fonte.sansMed },
});
