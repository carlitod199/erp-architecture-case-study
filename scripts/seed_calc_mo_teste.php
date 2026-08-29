<?php
declare(strict_types=1);
/* ============================================================
   VERO — Seed de TESTE da calculadora de MO (destrava o R$ e o auto-preenche)
   Complementa scripts/seed_calc_mo_referencia.php (que, por decisão D5,
   NÃO semeou custo). Aqui semeamos VALORES DE REFERÊNCIA/EDITÁVEIS só para
   o gestor VER a calculadora funcionando de ponta a ponta:
     (1) custo_diaria_propria / custo_diaria_terceirizada por atividade;
     (2) nº de plantas da válvula 5A (estava NULL) — referência pela
         densidade da 06 (2000 plantas / 6 ha ≈ 333/ha → 4 ha ≈ 1333).
   NÃO são medições da fazenda — o RT/gestor DEVE validar/ajustar na tela
   de Parâmetros e no cadastro da válvula (Regra 1: o sistema orienta).
   Idempotente (pula o que já existe). Uso: php scripts/seed_calc_mo_teste.php
   ============================================================ */
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
require_once __DIR__ . '/../includes/db.php';
$pdo = Database::getConnection();
$T = 1;
$hoje = date('Y-m-d');

$OBS = 'REFERÊNCIA DE TESTE (não é medição da fazenda) — VALIDAR/AJUSTAR com o RT/gestor (P-91).';

/* (1) custo da diária (R$) — referência regional editável, mesma faixa p/ todas
   as atividades que já têm rendimento cadastrado. */
$CUSTO = [
    'custo_diaria_propria'      => 100.00,  // CLT/própria (diária) — referência
    'custo_diaria_terceirizada' => 120.00,  // terceirizada (diária) — referência
];

$tiposComRend = $pdo->query(
    "SELECT DISTINCT tipo_atividade_id FROM agro_calc_parametros
      WHERE tenant_id = {$T} AND chave = 'rendimento_por_diaria' AND ativo = 1"
)->fetchAll(PDO::FETCH_COLUMN);

$exists = $pdo->prepare("SELECT id FROM agro_calc_parametros WHERE tenant_id=:t AND tipo_atividade_id=:a AND chave=:c AND ativo=1");
$ins    = $pdo->prepare("INSERT INTO agro_calc_parametros (tenant_id, tipo_atividade_id, chave, valor, vigencia_inicio, observacao, ativo, created_at, updated_at)
                         VALUES (:t,:a,:c,:v,:vi,:o,1,NOW(),NOW())");
$novos = 0; $pulados = 0;
foreach ($tiposComRend as $aid) {
    foreach ($CUSTO as $chave => $valor) {
        $exists->execute([':t' => $T, ':a' => (int)$aid, ':c' => $chave]);
        if ($exists->fetchColumn()) { $pulados++; continue; }
        $ins->execute([':t' => $T, ':a' => (int)$aid, ':c' => $chave, ':v' => $valor, ':vi' => $hoje, ':o' => $OBS]);
        $novos++;
    }
}
echo "custo_diaria (referência): {$novos} novos, {$pulados} já existentes — em " . count($tiposComRend) . " atividade(s)\n";

/* (2) nº de plantas da válvula 5A (referência pela densidade da 06) — só se NULL. */
$upd = $pdo->prepare("UPDATE agro_talhoes SET num_plantas = :n, updated_at = NOW()
                       WHERE tenant_id = :t AND codigo = '5A' AND num_plantas IS NULL");
$upd->execute([':n' => 1333, ':t' => $T]);
echo $upd->rowCount() > 0
    ? "válvula 5A: num_plantas = 1333 (REFERÊNCIA pela densidade da 06 — CONFIRMAR contagem real)\n"
    : "válvula 5A: já tinha num_plantas (não alterado)\n";

echo "seed de teste concluído.\n";
