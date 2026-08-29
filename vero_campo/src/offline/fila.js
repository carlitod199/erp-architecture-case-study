import { abrirDb } from './db';
import { novoClientUuid } from './idempotencia';

// Retentativa com backoff (Onda 5): 1 → 2 → 4 → 8 → 16 min; na 6ª falha o
// item vira 'falha' (não aceito) e para de retentar — o operador decide.
const TETO_TENTATIVAS = 6;

// Enfileira uma escrita. Devolve o client_uuid gerado.
export async function enfileirar({ tipo, rota, metodo = 'POST', payload, paiUuid = null }) {
  const db = await abrirDb();
  const clientUuid = payload.client_uuid || novoClientUuid();
  const corpo = { ...payload, client_uuid: clientUuid };
  await db.runAsync(
    `INSERT OR REPLACE INTO fila
      (client_uuid, tipo, rota, metodo, payload, estado, criado_em, pai_uuid)
     VALUES (?,?,?,?,?,?,?,?)`,
    clientUuid, tipo, rota, metodo, JSON.stringify(corpo), 'pendente',
    new Date().toISOString(), paiUuid
  );
  return clientUuid;
}

export async function pendentes() {
  const db = await abrirDb();
  const agora = new Date().toISOString();
  // pai antes das fotos (pai_uuid nulo primeiro); respeita o backoff
  return db.getAllAsync(
    `SELECT * FROM fila
      WHERE estado IN ('pendente','erro')
        AND (proximo_envio IS NULL OR proximo_envio <= ?)
      ORDER BY (pai_uuid IS NULL) DESC, criado_em ASC`,
    agora
  );
}

// 5.1: um crash entre enviar e confirmar deixava o item 'enviando' órfão
// para sempre — na abertura do app ele volta a 'pendente' (a idempotência
// por client_uuid garante que reenviar nunca duplica no servidor).
export async function reidratarPresos() {
  const db = await abrirDb();
  const r = await db.runAsync(
    "UPDATE fila SET estado='pendente' WHERE estado='enviando'"
  );
  return r?.changes || 0;
}

// 5.2: registra uma falha de envio. Definitiva (regra de negócio rejeitou)
// vira 'falha' na hora; transitória entra em backoff até o teto.
export async function registrarFalha(clientUuid, mensagem, definitiva = false) {
  const db = await abrirDb();
  const item = await db.getFirstAsync(
    'SELECT tentativas FROM fila WHERE client_uuid = ?', clientUuid
  );
  const tentativas = (item?.tentativas || 0) + 1;
  if (definitiva || tentativas >= TETO_TENTATIVAS) {
    await db.runAsync(
      "UPDATE fila SET estado='falha', erro=?, tentativas=?, proximo_envio=NULL WHERE client_uuid=?",
      definitiva ? mensagem : `${mensagem} — desistiu após ${tentativas} tentativas`,
      tentativas, clientUuid
    );
    return 'falha';
  }
  const esperaMs = 2 ** (tentativas - 1) * 60 * 1000;
  await db.runAsync(
    "UPDATE fila SET estado='erro', erro=?, tentativas=?, proximo_envio=? WHERE client_uuid=?",
    mensagem, tentativas, new Date(Date.now() + esperaMs).toISOString(), clientUuid
  );
  return 'erro';
}

export async function contarPendentes() {
  const db = await abrirDb();
  const r = await db.getFirstAsync(
    "SELECT COUNT(*) AS n FROM fila WHERE estado IN ('pendente','enviando','erro')"
  );
  return r ? r.n : 0;
}

// Registros que o servidor NÃO aceitou (falha definitiva) — surfacer 5.3.
export async function contarFalhas() {
  const db = await abrirDb();
  const r = await db.getFirstAsync(
    "SELECT COUNT(*) AS n FROM fila WHERE estado = 'falha'"
  );
  return r ? r.n : 0;
}

export async function marcar(clientUuid, estado, erro = null) {
  const db = await abrirDb();
  if (estado === 'pendente') {
    // "tentar de novo" zera o histórico de retentativa
    await db.runAsync(
      "UPDATE fila SET estado='pendente', erro=NULL, tentativas=0, proximo_envio=NULL WHERE client_uuid=?",
      clientUuid
    );
    return;
  }
  await db.runAsync('UPDATE fila SET estado = ?, erro = ? WHERE client_uuid = ?',
    estado, erro, clientUuid);
}

export async function todos() {
  const db = await abrirDb();
  return db.getAllAsync('SELECT * FROM fila ORDER BY criado_em DESC');
}

// 22/07: descartar um registro recusado ('falha') — o operador decide que
// aquele envio não vale mais (leva junto os anexos filhos, que sem o pai
// nunca subiriam).
export async function descartar(clientUuid) {
  const db = await abrirDb();
  await db.runAsync('DELETE FROM fila WHERE client_uuid = ? OR pai_uuid = ?', clientUuid, clientUuid);
}

export default { enfileirar, pendentes, reidratarPresos, registrarFalha, contarPendentes, contarFalhas, marcar, todos, descartar };
