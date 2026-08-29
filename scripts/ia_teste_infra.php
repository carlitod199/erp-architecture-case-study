<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/ia_teste_infra.php
   Aplica as migrations ia_acoes/ia_preferencias no banco dev,
   inclui a infra do agente de IA e verifica:
     1) hash-chain fecha (hash_anterior da 2ª == hash da 1ª);
     2) preferência grava e relê (upsert).
   Ao final REMOVE as linhas de teste (deixa as tabelas limpas).
   Uso: php scripts/ia_teste_infra.php
   ============================================================ */

require_once __DIR__ . '/../includes/db.php';                 // Database + PDO ($pdo)
require_once __DIR__ . '/../api/v1/nucleo/ia_agente_infra.php';

$pdo = Database::getConnection();
$out = static fn(string $s) => print($s . PHP_EOL);

/* ---- 1. Aplica as migrations (CREATE TABLE IF NOT EXISTS) ---- */
foreach ([
    __DIR__ . '/../database/migrations/2026-07-30_ia_acoes.sql',
    __DIR__ . '/../database/migrations/2026-07-30_ia_preferencias.sql',
] as $arq) {
    $sql = file_get_contents($arq);
    $pdo->exec($sql);
    $out('[migration] aplicada: ' . basename($arq));
}

/* ---- contexto de teste (tenant/usuário sintéticos, isolados) ---- */
$TENANT = 999001;
$UID    = 999002;
$SESSION = 'sess-teste-' . bin2hex(random_bytes(6));
$usuario = ['id' => $UID, 'tenant_id' => $TENANT];

$out('');
$out('== Auditoria (hash-chain) ==');

/* ---- 2. Grava 2 ações auditadas ---- */
$id1 = ia_auditar_acao(
    $usuario, $SESSION, 'financeiro.lancar_despesa',
    ['valor' => 1500.50, 'descricao' => 'Diesel', 'plano_conta_id' => 12],
    'Despesa lançada com sucesso (#123).',
    'movimentacao', 123
);
$id2 = ia_auditar_acao(
    $usuario, $SESSION, 'agro.abrir_safra',
    ['talhao_id' => 7, 'variedade' => 'Niágara'],
    'Safra 2026/27 aberta no talhão 7.',
    'safra', 456
);
$out("acao 1 id=$id1  |  acao 2 id=$id2");

/* ---- verifica o encadeamento ---- */
$a1 = $pdo->query("SELECT * FROM ia_acoes WHERE id = $id1")->fetch(PDO::FETCH_ASSOC);
$a2 = $pdo->query("SELECT * FROM ia_acoes WHERE id = $id2")->fetch(PDO::FETCH_ASSOC);

$out('acao1.hash          = ' . $a1['hash']);
$out('acao1.hash_anterior = ' . var_export($a1['hash_anterior'], true));
$out('acao2.hash          = ' . $a2['hash']);
$out('acao2.hash_anterior = ' . $a2['hash_anterior']);

$chainOk = ($a2['hash_anterior'] === $a1['hash']);

/* recomputa o hash da acao1 a partir dos campos gravados (verificador).
   O params_json é RE-CANONIZADO ao ler: a coluna JSON do MySQL reformata o
   texto (reordena chaves, adiciona espaços), então o hash é definido sobre a
   forma canônica — decode + ia_infra_params_canon reproduz o texto hasheado. */
$payload1 = implode('|', [
    (string)$a1['tenant_id'], (string)$a1['usuario_id'], $a1['session_id'],
    $a1['capability'], ia_infra_params_canon(json_decode($a1['params_json'], true)),
    $a1['resultado'],
    (string)($a1['recurso_tipo'] ?? ''), (string)($a1['recurso_id'] ?? ''),
]);
$recomputado1 = hash('sha256', (string)$a1['hash_anterior'] . '|' . $payload1);
$hashReproduzOk = ($recomputado1 === $a1['hash']);

$out('recomputa acao1.hash= ' . $recomputado1);
$out('');
$out('hash-chain (2ª.anterior == 1ª.hash) ? ' . ($chainOk ? 'OK' : 'FALHOU'));
$out('hash reproduzível (verificador)     ? ' . ($hashReproduzOk ? 'OK' : 'FALHOU'));

/* ---- 3. Preferência: grava e relê (upsert) ---- */
$out('');
$out('== Preferência (upsert) ==');
ia_pref_gravar($TENANT, $UID, 'safra_padrao', '2026/27');
$lido = ia_pref_ler($TENANT, $UID, 'safra_padrao');
$out("gravou 'safra_padrao' = '2026/27'  =>  releu = " . var_export($lido, true));

ia_pref_gravar($TENANT, $UID, 'safra_padrao', '2027/28'); // sobrescreve
$lido2 = ia_pref_ler($TENANT, $UID, 'safra_padrao');
$out("upsert 'safra_padrao' = '2027/28'  =>  releu = " . var_export($lido2, true));

$prefOk = ($lido === '2026/27' && $lido2 === '2027/28');
$out('preferência (grava/relê/upsert)     ? ' . ($prefOk ? 'OK' : 'FALHOU'));

/* ---- 4. Limpeza: remove SOMENTE as linhas de teste ---- */
$out('');
$del1 = $pdo->prepare('DELETE FROM ia_acoes WHERE tenant_id = ? AND session_id = ?');
$del1->execute([$TENANT, $SESSION]);
$del2 = $pdo->prepare('DELETE FROM ia_preferencias WHERE tenant_id = ? AND usuario_id = ?');
$del2->execute([$TENANT, $UID]);
$out('[limpeza] ia_acoes removidas: ' . $del1->rowCount()
    . '  |  ia_preferencias removidas: ' . $del2->rowCount());

$restam = (int)$pdo->query("SELECT COUNT(*) FROM ia_acoes WHERE tenant_id = $TENANT")->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM ia_preferencias WHERE tenant_id = $TENANT")->fetchColumn();
$out('[limpeza] linhas de teste restantes: ' . $restam);

$out('');
$out('RESULTADO FINAL: ' . (($chainOk && $hashReproduzOk && $prefOk && $restam === 0) ? 'TUDO OK' : 'REVISAR'));
