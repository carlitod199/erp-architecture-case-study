import { Platform } from 'react-native';
import http from './http';

// 7.5 — registro do aparelho para notificações push (Expo Push).
// IMPORTANTE: push REMOTO não funciona no Expo Go (SDK 53+) — este registro
// só produz token num build EAS instalado. Aqui é 100% fail-safe: qualquer
// erro (Expo Go, sem permissão, offline) é silencioso e não afeta o login.
export async function registrarPush() {
  try {
    const Notifications = await import('expo-notifications');
    const { status } = await Notifications.requestPermissionsAsync();
    if (status !== 'granted') return;
    const { data: token } = await Notifications.getExpoPushTokenAsync();
    if (token) {
      await http.post('/push/registrar', { expo_token: token, plataforma: Platform.OS });
    }
  } catch (_) {
    // Expo Go / sem projeto EAS / offline — o registro fica p/ o build
  }
}

export default { registrarPush };
