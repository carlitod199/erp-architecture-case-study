import * as SecureStore from 'expo-secure-store';
import { apagarToken } from './authStorage';

// Ambiente por-aparelho (multi-cliente): um único binário atende vários
// clientes, cada um num servidor próprio em https://<codigo>.example.com.
// O operador digita o código da empresa na primeira abertura; o sufixo do
// domínio é fixo e compilado — nunca aceitamos URL completa nem http://.
// Não existe DNS wildcard: código errado falha na resolução de nome (o fetch
// lança TypeError) ANTES de qualquer credencial sair do aparelho.
//
// Dependência unidirecional: este módulo importa authStorage (nunca o
// contrário) — a regra "trocou de empresa → token apagado" mora aqui.

export const SUFIXO_DOMINIO = 'example.com';

// Mesma regra do provisionamento no servidor: minúscula, começa com letra,
// 3 a 21 caracteres alfanuméricos. A validação forte é do servidor; esta é
// só conforto local (evita ida à rede com algo obviamente errado).
export const CODIGO_REGEX = /^[a-z][a-z0-9]{2,20}$/;

const CHAVE_CODIGO = 'vero_codigo_empresa';

// Cache em memória: o código é lido a cada requisição HTTP — sem o cache
// seria uma ida ao SecureStore por chamada. Invalida em salvar/apagar.
// undefined = ainda não lido; null = lido e ausente.
let _codigoCache;

export function normalizarCodigo(texto) {
  if (!texto) return '';
  return texto
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // remove acentos (ex.: "São João" → "sao joao")
    .replace(/\s/g, '');
}

export function validarCodigo(codigo) {
  return CODIGO_REGEX.test(codigo || '');
}

export function montarBaseUrl(codigo) {
  return `https://${codigo}.${SUFIXO_DOMINIO}/api/v1`;
}

export async function salvarCodigo(codigo) {
  const limpo = normalizarCodigo(codigo);
  if (!validarCodigo(limpo)) {
    throw new Error('Código inválido. Use só letras e números, começando por letra (ex.: minhafazenda).');
  }
  const anterior = await lerCodigo();
  if (anterior && anterior !== limpo) {
    // Trocou de empresa: o token do servidor antigo NUNCA pode viajar ao host
    // novo (bancos separados) — apaga antes de persistir o código novo.
    await apagarToken();
  }
  await SecureStore.setItemAsync(CHAVE_CODIGO, limpo);
  _codigoCache = limpo;
}

export async function lerCodigo() {
  if (_codigoCache === undefined) {
    _codigoCache = (await SecureStore.getItemAsync(CHAVE_CODIGO)) || null;
  }
  return _codigoCache;
}

// Precedência: EXPO_PUBLIC_API_URL (override de dev/build, inlined pelo Expo
// no bundle — permite Expo Go apontando pra LAN) → código salvo → null.
export async function lerBaseUrl() {
  if (process.env.EXPO_PUBLIC_API_URL) return process.env.EXPO_PUBLIC_API_URL;
  const codigo = await lerCodigo();
  return codigo ? montarBaseUrl(codigo) : null;
}

export async function temAmbiente() {
  return (await lerBaseUrl()) !== null;
}

// "Trocar empresa": apaga código E token juntos — sessão não sobrevive
// à troca de servidor.
export async function apagarCodigo() {
  await apagarToken();
  await SecureStore.deleteItemAsync(CHAVE_CODIGO);
  _codigoCache = null;
}

export default {
  SUFIXO_DOMINIO,
  CODIGO_REGEX,
  normalizarCodigo,
  validarCodigo,
  montarBaseUrl,
  salvarCodigo,
  lerCodigo,
  lerBaseUrl,
  temAmbiente,
  apagarCodigo,
};
