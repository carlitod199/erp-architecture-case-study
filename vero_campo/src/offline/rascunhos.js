import { abrirDb } from './db';

// Rascunhos de formulário (22/07): o que o operador preencheu sobrevive a
// sair da tela / fechar o app. Chave por contexto (ex.: 'conclusao:64').
// Mesmo SQLite do app, tabela própria — db.js não muda.

let pronta = false;
async function garantirTabela(db) {
  if (pronta) return;
  await db.execAsync(`
    CREATE TABLE IF NOT EXISTS rascunhos (
      chave TEXT PRIMARY KEY,
      valor TEXT NOT NULL,
      atualizado_em TEXT NOT NULL
    );
  `);
  pronta = true;
}

export async function lerRascunho(chave) {
  const db = await abrirDb();
  await garantirTabela(db);
  const r = await db.getFirstAsync('SELECT valor FROM rascunhos WHERE chave = ?', chave);
  if (!r?.valor) return null;
  try { return JSON.parse(r.valor); } catch { return null; }
}

export async function gravarRascunho(chave, obj) {
  const db = await abrirDb();
  await garantirTabela(db);
  await db.runAsync(
    `INSERT INTO rascunhos (chave, valor, atualizado_em) VALUES (?,?,?)
     ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor, atualizado_em = excluded.atualizado_em`,
    chave, JSON.stringify(obj), new Date().toISOString()
  );
}

export async function apagarRascunho(chave) {
  const db = await abrirDb();
  await garantirTabela(db);
  await db.runAsync('DELETE FROM rascunhos WHERE chave = ?', chave);
}

export default { lerRascunho, gravarRascunho, apagarRascunho };
