import React, { useEffect, useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import * as SplashScreen from 'expo-splash-screen';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import {
  useFonts,
  IBMPlexSans_400Regular,
  IBMPlexSans_500Medium,
  IBMPlexSans_600SemiBold,
  IBMPlexSans_700Bold,
} from '@expo-google-fonts/ibm-plex-sans';
import { IBMPlexMono_500Medium, IBMPlexMono_600SemiBold } from '@expo-google-fonts/ibm-plex-mono';

import { AuthProvider } from './src/context/AuthContext';
import { SyncProvider } from './src/context/SyncContext';
import RootNavigator from './src/navigation/RootNavigator';
import { abrirDb } from './src/offline/db';

// Segura o splash NATIVO até a TelaAbertura (JS) estar desenhada — a troca
// nativo→JS acontece entre dois quadros idênticos, sem pisca. (fail-safe)
SplashScreen.preventAutoHideAsync().catch(() => {});

export default function App() {
  const [dbPronto, setDbPronto] = useState(false);
  const [fontesOk] = useFonts({
    IBMPlexSans_400Regular,
    IBMPlexSans_500Medium,
    IBMPlexSans_600SemiBold,
    IBMPlexSans_700Bold,
    IBMPlexMono_500Medium,
    IBMPlexMono_600SemiBold,
  });

  useEffect(() => {
    abrirDb().then(() => setDbPronto(true)).catch(() => setDbPronto(true));
  }, []);

  // A árvore monta INTEIRA desde o 1º frame — quem cobre a carga é o overlay
  // único do RootNavigator (uma só TelaAbertura, a logo nunca reinicia).
  // Fontes/DB pendentes ficam invisíveis embaixo do splash opaco.
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <SyncProvider>
          <StatusBar style="light" />
          <RootNavigator prontoBase={fontesOk && dbPronto} />
        </SyncProvider>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
