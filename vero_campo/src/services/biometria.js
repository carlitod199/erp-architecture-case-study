// Biometria no login (spec item 0) — wrapper de expo-local-authentication +
// persistência da preferência no MESMO storage do authStorage (SecureStore).
//
// Ciclo real de sessão (AuthContext/RootNavigator): com token válido o app
// abre DIRETO logado — o Login só aparece quando o token não existe mais
// (logout ou derrubado pelo servidor). Ou seja: na tela de Login nunca há
// token reutilizável. Por isso a reentrada biométrica reaproveita a
// CREDENCIAL (e-mail + senha) guardada aqui — só é aceitável porque o
// storage é SecureStore (Keychain/Keystore), NUNCA AsyncStorage puro, e a
// senha só é lida depois de o sensor aprovar (lerSenha é chamada apenas
// após autenticar() ok).

import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';

// SecureStore aceita [A-Za-z0-9._-] nas chaves — pontos ok.
const CHAVE_ATIVA = 'biometria.ativa';
const CHAVE_EMAIL = 'biometria.ultimo_email';
const CHAVE_SENHA = 'biometria.credencial';

/** Sensor presente E pelo menos uma digital/rosto cadastrado no aparelho. */
export async function disponivelAsync() {
  try {
    const temHardware = await LocalAuthentication.hasHardwareAsync();
    if (!temHardware) return false;
    return await LocalAuthentication.isEnrolledAsync();
  } catch (_) {
    return false;
  }
}

/**
 * Abre o prompt do sensor. Nunca lança: devolve { ok, erro }.
 * erro === 'user_cancel' quando a pessoa desistiu (cai no form sem alarde).
 */
export async function autenticar(motivo) {
  try {
    const r = await LocalAuthentication.authenticateAsync({
      promptMessage: motivo,
      cancelLabel: 'Usar senha',
      disableDeviceFallback: false,
    });
    if (r.success) return { ok: true, erro: null };
    return { ok: false, erro: r.error || 'nao_autenticado' };
  } catch (e) {
    return { ok: false, erro: e?.message || 'falha_sensor' };
  }
}

/**
 * Liga a biometria para o próximo login: guarda preferência + e-mail + senha.
 * A senha vai para o SecureStore (cifrado pelo SO); a leitura só acontece
 * via lerSenha(), que o LoginScreen chama apenas depois do sensor aprovar.
 */
export async function ativar(email, senha) {
  await SecureStore.setItemAsync(CHAVE_ATIVA, '1');
  await SecureStore.setItemAsync(CHAVE_EMAIL, email);
  await SecureStore.setItemAsync(CHAVE_SENHA, senha);
}

/** Desliga e APAGA tudo (inclusive a credencial guardada). */
export async function desativar() {
  await SecureStore.deleteItemAsync(CHAVE_ATIVA);
  await SecureStore.deleteItemAsync(CHAVE_EMAIL);
  await SecureStore.deleteItemAsync(CHAVE_SENHA);
}

/** Preferência p/ montar a tela: { ativa, email } — sem expor a senha. */
export async function lerPreferencia() {
  try {
    const ativa = (await SecureStore.getItemAsync(CHAVE_ATIVA)) === '1';
    const email = await SecureStore.getItemAsync(CHAVE_EMAIL);
    return { ativa, email: email || null };
  } catch (_) {
    return { ativa: false, email: null };
  }
}

/** Chamar SOMENTE depois de autenticar() devolver ok. */
export async function lerSenha() {
  try {
    return await SecureStore.getItemAsync(CHAVE_SENHA);
  } catch (_) {
    return null;
  }
}

export default { disponivelAsync, autenticar, ativar, desativar, lerPreferencia, lerSenha };
