import React, { useEffect, useRef } from 'react';
import { Animated, Easing, StyleSheet, View } from 'react-native';
import * as SplashScreen from 'expo-splash-screen';
import CarregandoVero from './CarregandoVero';

// Tela de abertura — identidade ATUAL da marca: o logotipo VERO surge em fade
// sobre o fundo escuro e o anel padrão do sistema (mesmo .vspin da web) gira
// abaixo. Substitui o emblema serrilhado com trator da identidade antiga.

const FUNDO = '#070907'; // preto do splash nativo (troca sem pisca)

export default function TelaAbertura() {
  const surgir = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    // primeiro quadro JS desenhado → solta o splash NATIVO (a troca acontece
    // entre dois fundos idênticos #070907; a logo entra em fade logo depois)
    const raf = requestAnimationFrame(() => {
      SplashScreen.hideAsync().catch(() => {});
    });
    const entrada = Animated.timing(surgir, {
      toValue: 1,
      duration: 450,
      easing: Easing.out(Easing.quad),
      useNativeDriver: true,
    });
    entrada.start();
    return () => {
      cancelAnimationFrame(raf);
      entrada.stop();
    };
  }, []);

  const subida = surgir.interpolate({ inputRange: [0, 1], outputRange: [10, 0] });

  return (
    <View style={styles.tela}>
      <Animated.View style={[styles.conteudo, { opacity: surgir, transform: [{ translateY: subida }] }]}>
        <Animated.Image
          source={require('../../assets/logo_vero.png')}
          style={styles.logo}
          resizeMode="contain"
        />
        <CarregandoVero tamanho={30} tom="escuro" style={styles.spinner} />
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: FUNDO },
  conteudo: { alignItems: 'center', justifyContent: 'center' },
  logo: { width: 228, height: 44 },
  spinner: { marginTop: 34 },
});
