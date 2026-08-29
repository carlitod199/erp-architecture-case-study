import NetInfo from '@react-native-community/netinfo';
import { TIMEOUT } from './config';
import { lerToken } from './authStorage';
import { lerBaseUrl } from './ambiente';

// Erro tipado para a UI tratar por código estável (envelope D3)
export class ErroApi extends Error {
  constructor(codigo, mensagem, status) {
    super(mensagem || codigo);
    this.codigo = codigo;      // ex.: 'token_expirado', 'sem_permissao', 'saldo_insuficiente'
    this.status = status;
  }
}

// Sessão inválida no servidor (token expirado/revogado): quem cuida do estado
// de auth registra aqui o que fazer (limpar SecureStore + voltar ao Login).
const CODIGOS_SESSAO = ['token_ausente', 'token_invalido', 'token_revogado', 'token_expirado'];
let aoSessaoInvalida = null;
export function registrarSessaoInvalida(fn) {
  aoSessaoInvalida = fn;
}

async function comTimeout(promise, ms) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), ms);
  try {
    return await promise(ctrl.signal);
  } finally {
    clearTimeout(t);
  }
}

// Envelope padrão do VERO: { ok, data, message, error, sync:{server_time} }
async function requisicao(metodo, rota, corpo, { autenticado = true } = {}) {
  // URL base resolvida POR REQUISIÇÃO (código da empresa salvo no aparelho).
  // O RootNavigator normalmente impede chegar aqui sem ambiente; isto é
  // cinto de segurança.
  const base = await lerBaseUrl();
  if (!base) {
    throw new ErroApi('sem_ambiente', 'Informe o código da empresa.', 0);
  }

  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (autenticado) {
    const token = await lerToken();
    if (token) headers.Authorization = `Bearer ${token}`;
  }

  let resposta;
  try {
    resposta = await comTimeout(
      (signal) =>
        fetch(`${base}${rota}`, {
          method: metodo,
          headers,
          body: corpo ? JSON.stringify(corpo) : undefined,
          signal,
        }),
      TIMEOUT
    );
  } catch (e) {
    // Distinguir "sem internet" de "código de empresa inválido" é requisito:
    // confundir os dois faz o operador apagar configuração correta no meio
    // do talhão. Timeout (abort) é sempre tratado como sem conexão.
    if (e?.name !== 'AbortError') {
      // TypeError de rede: pode ser aparelho offline OU host que não resolve
      // (sem DNS wildcard, código errado morre aqui, antes de sair credencial).
      let conectado = false;
      try {
        const estado = await NetInfo.fetch();
        conectado = estado?.isConnected === true;
      } catch (_) { /* NetInfo falhou: assume offline (caminho seguro) */ }
      if (conectado) {
        // há internet mas o host não resolveu / TLS falhou → código não
        // existe ou cliente sem certificado
        throw new ErroApi('host_nao_encontrado', 'Código não encontrado. Confira com o suporte.', 0);
      }
    }
    // sem rede / timeout — a UI pode enfileirar (offline-first)
    throw new ErroApi('sem_conexao', 'Sem conexão. O registro fica na fila e envia depois.', 0);
  }

  let json = null;
  try {
    json = await resposta.json();
  } catch (_) {
    throw new ErroApi('resposta_invalida', 'Resposta inesperada do servidor.', resposta.status);
  }

  if (!resposta.ok || json.ok === false) {
    const codigo = json.error || 'erro';
    if (autenticado && CODIGOS_SESSAO.includes(codigo)) {
      // token morreu no servidor (30 dias, revogação): derruba a sessão local
      try { aoSessaoInvalida?.(); } catch (_) {}
      throw new ErroApi('sessao_expirada', 'Sessão expirada. Entre novamente.', resposta.status);
    }
    throw new ErroApi(codigo, json.message || 'Não foi possível concluir.', resposta.status);
  }
  return json; // { ok:true, data, message, sync }
}

export const http = {
  get: (rota, opts) => requisicao('GET', rota, null, opts),
  post: (rota, corpo, opts) => requisicao('POST', rota, corpo, opts),
  put: (rota, corpo, opts) => requisicao('PUT', rota, corpo, opts),
  del: (rota, opts) => requisicao('DELETE', rota, null, opts),
};

export default http;
