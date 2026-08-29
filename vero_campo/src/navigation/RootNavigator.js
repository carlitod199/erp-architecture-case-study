import React, { useEffect, useRef, useState } from 'react';
import { View, Animated, StyleSheet } from 'react-native';
import { NavigationContainer, DefaultTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useAuth } from '../context/AuthContext';
import TelaAbertura from '../components/TelaAbertura';
import CodigoEmpresaScreen from '../screens/CodigoEmpresaScreen';
import LoginScreen from '../screens/LoginScreen';
import TabNavigator from './TabNavigator';

const Stack = createNativeStackNavigator();

// Fundo escuro em TODAS as camadas — mata o flash branco que aparecia no
// corte splash → app (o NavigationContainer nasce com fundo claro por padrão).
const FUNDO = '#070907';
const TEMA_ESCURO = {
  ...DefaultTheme,
  colors: { ...DefaultTheme.colors, background: FUNDO, card: FUNDO },
};

export default function RootNavigator({ prontoBase = true }) {
  const { carregando, logado, ambienteDefinido } = useAuth();
  // depois do login, mantém a logo animada por ~2,2s (cobre a carga inicial dos dados)
  const [abertura, setAbertura] = useState(false);
  const jaLogado = useRef(false);

  useEffect(() => {
    if (logado && !jaLogado.current) {
      jaLogado.current = true;
      setAbertura(true);
      const t = setTimeout(() => setAbertura(false), 2200);
      return () => clearTimeout(t);
    }
    if (!logado) {
      jaLogado.current = false;
    }
    return undefined;
  }, [logado]);

  // Splash como OVERLAY com fade-out (não mais troca seca de árvore): a
  // navegação fica montada por baixo o tempo todo, então a tela seguinte já
  // está pronta quando a logo desaparece — sem pisca, sem frame perdido.
  // prontoBase = fontes + banco (App.js): UMA TelaAbertura cobre tudo.
  const splashAtivo = !prontoBase || carregando || (logado && abertura);
  const [splashMontado, setSplashMontado] = useState(true);
  const fade = useRef(new Animated.Value(1)).current;

  useEffect(() => {
    if (splashAtivo) {
      fade.setValue(1);
      setSplashMontado(true);
      return;
    }
    Animated.timing(fade, {
      toValue: 0,
      duration: 380,
      useNativeDriver: true,
    }).start(({ finished }) => {
      if (finished) setSplashMontado(false);
    });
  }, [splashAtivo, fade]);

  return (
    <View style={styles.raiz}>
      <NavigationContainer theme={TEMA_ESCURO}>
        <Stack.Navigator
          screenOptions={{
            headerShown: false,
            animation: 'fade', // Login ↔ Tabs cruzam em fade, nunca em corte
            contentStyle: { backgroundColor: FUNDO },
          }}
        >
          {!ambienteDefinido ? (
            // primeira abertura (ou pós "Trocar empresa"): sem código, sem API
            <Stack.Screen name="CodigoEmpresa" component={CodigoEmpresaScreen} />
          ) : logado ? (
            <Stack.Screen name="Tabs" component={TabNavigator} />
          ) : (
            <Stack.Screen name="Login" component={LoginScreen} />
          )}
        </Stack.Navigator>
      </NavigationContainer>

      {splashMontado && (
        <Animated.View
          style={[StyleSheet.absoluteFill, { opacity: fade }]}
          pointerEvents={splashAtivo ? 'auto' : 'none'}
        >
          <TelaAbertura />
        </Animated.View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  raiz: { flex: 1, backgroundColor: FUNDO },
});
