import * as SecureStore from 'expo-secure-store';

const CHAVE_TOKEN = 'vero_token';
const CHAVE_USUARIO = 'vero_usuario';

export async function salvarToken(token) {
  await SecureStore.setItemAsync(CHAVE_TOKEN, token);
}

export async function lerToken() {
  return SecureStore.getItemAsync(CHAVE_TOKEN);
}

export async function apagarToken() {
  await SecureStore.deleteItemAsync(CHAVE_TOKEN);
  await SecureStore.deleteItemAsync(CHAVE_USUARIO);
}

export async function salvarUsuario(usuario) {
  await SecureStore.setItemAsync(CHAVE_USUARIO, JSON.stringify(usuario));
}

export async function lerUsuario() {
  const raw = await SecureStore.getItemAsync(CHAVE_USUARIO);
  return raw ? JSON.parse(raw) : null;
}

export default { salvarToken, lerToken, apagarToken, salvarUsuario, lerUsuario };
