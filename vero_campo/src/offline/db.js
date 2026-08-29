import * as SQLite from 'expo-sqlite';

// Banco local do app (offline-first). Guarda:
//  - pacotes de leitura por módulo (consulta sem sinal)
//  - fila de escrita (pendente -> enviando -> confirmado | erro)
let _db = null;
let _abrindo = null; // cache da PROMISE: chamadas concorrentes no boot não abrem 2x

export async function abrirDb() {
  if (_db) return _db;
  if (_abrindo) return _abrindo;
  _abrindo = _abrirDb();
  return _abrindo;
}

async function _abrirDb() {
  _db = await SQLite.openDatabaseAsync('vero_campo.db');
  await _db.execAsync(`
    PRAGMA journal_mode = WAL;

    CREATE TABLE IF NOT EXISTS cache (
      modulo     TEXT NOT NULL,
      registro_id TEXT NOT NULL,
      dados      TEXT NOT NULL,
      updated_at TEXT,
      PRIMARY KEY (modulo, registro_id)
    );

    CREATE TABLE IF NOT EXISTS sync_meta (
      modulo TEXT PRIMARY KEY,
      ultimo_delta TEXT
    );

    CREATE TABLE IF NOT EXISTS ronda_progresso (
      valvula_id TEXT NOT NULL,
      dia        TEXT NOT NULL,   -- YYYY-MM-DD
      pontos     INTEGER NOT NULL DEFAULT 0,
      PRIMARY KEY (valvula_id, dia)
    );

    CREATE TABLE IF NOT EXISTS fila (
      client_uuid TEXT PRIMARY KEY,
      tipo   TEXT NOT NULL,          -- apontamento | monitoramento | irrigacao | horimetro | status | anexo ...
      rota   TEXT NOT NULL,
      metodo TEXT NOT NULL,
      payload TEXT NOT NULL,
      estado TEXT NOT NULL DEFAULT 'pendente', -- pendente | enviando | confirmado | erro | falha
      erro   TEXT,
      criado_em TEXT NOT NULL,
      pai_uuid TEXT                  -- foto vinculada ao registro pai
    );
  `);
  // Migração local (Onda 5): colunas de retentativa. SQLite não tem
  // ADD COLUMN IF NOT EXISTS — o erro "duplicate column" é esperado e ignorado.
  for (const sql of [
    "ALTER TABLE fila ADD COLUMN tentativas INTEGER NOT NULL DEFAULT 0",
    'ALTER TABLE fila ADD COLUMN proximo_envio TEXT',
  ]) {
    try { await _db.execAsync(sql); } catch (_) { /* coluna já existe */ }
  }
  return _db;
}

// ---- cache de leitura ----
export async function gravarCache(modulo, itens) {
  const db = await abrirDb();
  await db.withTransactionAsync(async () => {
    for (const it of itens) {
      await db.runAsync(
        'INSERT OR REPLACE INTO cache (modulo, registro_id, dados, updated_at) VALUES (?,?,?,?)',
        modulo, String(it.id), JSON.stringify(it), it.updated_at || null
      );
    }
  });
}

export async function lerCache(modulo) {
  const db = await abrirDb();
  const linhas = await db.getAllAsync('SELECT dados FROM cache WHERE modulo = ?', modulo);
  return linhas.map((l) => JSON.parse(l.dados));
}

// Tombstones (Onda 6): remove do cache os registros que o servidor marcou
// como fora do conjunto (_excluido=1 no delta).
export async function removerDoCache(modulo, ids) {
  if (!ids || ids.length === 0) return;
  const db = await abrirDb();
  const marcadores = ids.map(() => '?').join(',');
  await db.runAsync(
    `DELETE FROM cache WHERE modulo = ? AND registro_id IN (${marcadores})`,
    modulo, ...ids.map(String)
  );
}

// Módulos de "retrato atual" (ex.: fenologia): substituem o cache inteiro.
export async function limparModulo(modulo) {
  const db = await abrirDb();
  await db.runAsync('DELETE FROM cache WHERE modulo = ?', modulo);
}

// ---- progresso da ronda (Onda 7.3: sobrevive a fechar o app) ----
const diaHoje = () => new Date().toISOString().slice(0, 10);

export async function lerRondaHoje() {
  const db = await abrirDb();
  const linhas = await db.getAllAsync(
    'SELECT valvula_id, pontos FROM ronda_progresso WHERE dia = ?', diaHoje()
  );
  const mapa = {};
  for (const l of linhas) mapa[l.valvula_id] = l.pontos;
  return mapa;
}

export async function marcarPontoRonda(valvulaId) {
  const db = await abrirDb();
  await db.runAsync(
    `INSERT INTO ronda_progresso (valvula_id, dia, pontos) VALUES (?,?,1)
     ON CONFLICT(valvula_id, dia) DO UPDATE SET pontos = pontos + 1`,
    String(valvulaId), diaHoje()
  );
}

// 21/07: a meta de pontos é OPCIONAL — "Concluir válvula" salta o progresso
// direto para a meta (a válvula fica Completa na ronda com as leituras que tiver).
export async function concluirRondaValvula(valvulaId, meta) {
  const db = await abrirDb();
  await db.runAsync(
    `INSERT INTO ronda_progresso (valvula_id, dia, pontos) VALUES (?,?,?)
     ON CONFLICT(valvula_id, dia) DO UPDATE SET pontos = MAX(pontos, excluded.pontos)`,
    String(valvulaId), diaHoje(), Number(meta) || 1
  );
}

export async function lerDelta(modulo) {
  const db = await abrirDb();
  const r = await db.getFirstAsync('SELECT ultimo_delta FROM sync_meta WHERE modulo = ?', modulo);
  return r ? r.ultimo_delta : null;
}

// 22/07: zera os cursores de delta (exceto marcadores '__') — usado na
// ressincronização única após a correção do relógio do server_time.
export async function zerarDeltas() {
  const db = await abrirDb();
  await db.runAsync("DELETE FROM sync_meta WHERE modulo NOT LIKE '\\_\\_%' ESCAPE '\\'");
}

export async function gravarDelta(modulo, deltaIso) {
  const db = await abrirDb();
  await db.runAsync(
    'INSERT OR REPLACE INTO sync_meta (modulo, ultimo_delta) VALUES (?,?)',
    modulo, deltaIso
  );
}

// ---- dono dos dados locais (multi-cliente) ------------------------------
// TODO o banco local pertence a UM servidor (https://<codigo>.example.com).
// O marcador '__host' registra qual. Sem esse vínculo, trocar de empresa (ou
// atualizar uma instalação da era servidor01) MISTURA caches de bancos
// diferentes — ids colidem e o app exibe dados de duas fazendas.

export async function lerHostDados() {
  const db = await abrirDb();
  const r = await db.getFirstAsync("SELECT ultimo_delta FROM sync_meta WHERE modulo = '__host'");
  return r ? r.ultimo_delta : null;
}

async function temDadosLocais() {
  const db = await abrirDb();
  const r = await db.getFirstAsync(
    'SELECT (SELECT COUNT(*) FROM cache) + (SELECT COUNT(*) FROM fila) AS n'
  );
  return (r?.n ?? 0) > 0;
}

// Expurgo total: usado na troca de empresa. Derruba também a fila pendente —
// uma escrita destinada ao servidor antigo NUNCA pode ir para o novo.
export async function zerarBancoLocal() {
  const db = await abrirDb();
  // rascunhos/voz_fila são criadas sob demanda pelos próprios módulos —
  // podem ainda não existir neste banco (por isso o try por tabela)
  for (const t of ['cache', 'sync_meta', 'ronda_progresso', 'fila', 'rascunhos', 'voz_fila']) {
    try { await db.runAsync(`DELETE FROM ${t}`); } catch (_) { /* tabela ainda não criada */ }
  }
}

/** Garante que os dados locais pertencem ao host atual. Se pertencem a outro
 *  (ou a uma instalação antiga sem marcador, mas com dados), expurga tudo e
 *  assume o host. Devolve true quando limpou. */
export async function garantirDonoDosDados(host) {
  if (!host) return false;
  const salvo = await lerHostDados();
  if (salvo === host) return false;
  if (salvo !== null || (await temDadosLocais())) {
    await zerarBancoLocal();
  }
  await gravarDelta('__host', host);
  return true;
}

export default { abrirDb, gravarCache, lerCache, removerDoCache, limparModulo, lerDelta, gravarDelta };
