import { abrirDb } from './db';
import { novoClientUuid } from './idempotencia';

// Fila de VOZ (Onda 3 — registro por voz offline): o operador grava agora e
// o áudio espera sinal para virar texto. Tabela própria no MESMO banco do
// offline (vero_campo.db, via abrirDb) — db.js não muda.
//
// Estados de um áudio:
//   pendente   → gravado, aguardando transcrição (com ou sem erro transitório)
//   transcrito → texto pronto, aparece na lista para "Usar no apontamento"
//   falha      → definitivo (ex.: arquivo de áudio sumiu do aparelho)
//
// Falha transitória (sem sinal no meio do envio, servidor fora) NÃO muda o
// estado: o item continua 'pendente' com a última mensagem em 'erro' e entra
// na próxima rodada do "Transcrever agora".

let _tabelaPronta = false;

async function db() {
  const d = await abrirDb();
  if (!_tabelaPronta) {
    await d.execAsync(`
      CREATE TABLE IF NOT EXISTS voz_fila (
        uuid      TEXT PRIMARY KEY,
        uri       TEXT NOT NULL,          -- arquivo .m4a gravado pelo expo-audio
        criado_em TEXT NOT NULL,
        estado    TEXT NOT NULL DEFAULT 'pendente', -- pendente | transcrito | falha
        texto     TEXT,                   -- preenchido quando transcrito
        erro      TEXT                    -- última mensagem de erro (informativa)
      );
    `);
    _tabelaPronta = true;
  }
  return d;
}

// Guarda um áudio recém-gravado na fila. Devolve o uuid do item.
export async function enfileirarAudio(uri) {
  const d = await db();
  const uuid = novoClientUuid();
  await d.runAsync(
    'INSERT INTO voz_fila (uuid, uri, criado_em, estado) VALUES (?,?,?,?)',
    uuid, String(uri), new Date().toISOString(), 'pendente'
  );
  return uuid;
}

// Lista completa para a tela (mais recente primeiro).
export async function listarVoz() {
  const d = await db();
  return d.getAllAsync('SELECT * FROM voz_fila ORDER BY criado_em DESC');
}

// Pendentes na ordem de gravação — é o que o "Transcrever agora" percorre.
export async function pendentesVoz() {
  const d = await db();
  return d.getAllAsync(
    "SELECT * FROM voz_fila WHERE estado = 'pendente' ORDER BY criado_em ASC"
  );
}

export async function contarPendentesVoz() {
  const d = await db();
  const r = await d.getFirstAsync(
    "SELECT COUNT(*) AS n FROM voz_fila WHERE estado = 'pendente'"
  );
  return r ? r.n : 0;
}

// Transcrição chegou: guarda o texto e o item vira 'transcrito'.
export async function marcarTranscrito(uuid, texto) {
  const d = await db();
  await d.runAsync(
    "UPDATE voz_fila SET estado='transcrito', texto=?, erro=NULL WHERE uuid=?",
    String(texto), uuid
  );
}

// Erro ao transcrever. definitiva=true (arquivo sumiu) → 'falha' e para;
// caso contrário o item SEGUE pendente e tenta de novo na próxima rodada.
export async function registrarErroVoz(uuid, mensagem, definitiva = false) {
  const d = await db();
  if (definitiva) {
    await d.runAsync(
      "UPDATE voz_fila SET estado='falha', erro=? WHERE uuid=?",
      mensagem || 'Falha ao transcrever.', uuid
    );
    return 'falha';
  }
  await d.runAsync('UPDATE voz_fila SET erro=? WHERE uuid=?', mensagem || null, uuid);
  return 'pendente';
}

// Remove da fila (áudio usado no apontamento ou descartado pelo operador).
export async function removerVoz(uuid) {
  const d = await db();
  await d.runAsync('DELETE FROM voz_fila WHERE uuid=?', uuid);
}

export default {
  enfileirarAudio, listarVoz, pendentesVoz, contarPendentesVoz,
  marcarTranscrito, registrarErroVoz, removerVoz,
};
