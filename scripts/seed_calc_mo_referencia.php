<?php
declare(strict_types=1);
/* ============================================================
   VERO — Seed REFERÊNCIA da calculadora de MO (P-91, tenant 1)
   Valores-REFERÊNCIA INICIAIS de rendimento por diária (faixas da
   pesquisa Embrapa/regional) como PONTO DE PARTIDA EDITÁVEL — NÃO são
   medições da fazenda. O RT/gestor DEVE validar/ajustar (Regra 1: o
   sistema orienta, não decide). custo_diaria NÃO é semeado (100%
   específico da fazenda — o RT preenche na tela de parâmetros).
   Idempotente (pula chave já vigente). Uso: php scripts/seed_calc_mo_referencia.php
   ============================================================ */
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");
require_once __DIR__ . '/../includes/db.php';
$pdo = Database::getConnection();
$T = 1;

$OBS = 'Referência inicial (pesquisa Embrapa/regional) — VALIDAR/AJUSTAR com o RT/gestor (P-91). Não é medição da fazenda.';
$rendimento = [
    'Poda' => 250, 'Desbrota' => 220, 'Amarrio' => 180, 'Repasse de amarrio' => 220,
    'Pré-desfolha' => 200, 'Desfolha' => 180, 'Limpeza' => 220,
    'Livramento' => 350, 'Seleção' => 400, 'Dedinho' => 300, 'Repasse de dedinho' => 350,
    'Raleio' => 250, 'Repasse de raleio' => 300,
    'Colheita' => 100,
];
$hoje = date('Y-m-d');
$getTipo = $pdo->prepare("SELECT id FROM agro_tipos_atividade WHERE tenant_id=:t AND nome=:n");
$exists  = $pdo->prepare("SELECT id FROM agro_calc_parametros WHERE tenant_id=:t AND tipo_atividade_id=:a AND chave=:c AND ativo=1");
$ins     = $pdo->prepare("INSERT INTO agro_calc_parametros (tenant_id, tipo_atividade_id, chave, valor, vigencia_inicio, observacao, ativo, created_at, updated_at)
                          VALUES (:t,:a,:c,:v,:vi,:o,1,NOW(),NOW())");
$novos = 0; $pulados = 0;
foreach ($rendimento as $nome => $rend) {
    $getTipo->execute([':t' => $T, ':n' => $nome]);
    $aid = (int)$getTipo->fetchColumn();
    if (!$aid) { echo "  ! não encontrado: {$nome}\n"; continue; }
    foreach (['rendimento_por_diaria' => $rend, 'fator_ajuste' => 1.0, 'jornada_horas' => 8] as $chave => $valor) {
        $exists->execute([':t' => $T, ':a' => $aid, ':c' => $chave]);
        if ($exists->fetchColumn()) { $pulados++; continue; }
        $ins->execute([':t' => $T, ':a' => $aid, ':c' => $chave, ':v' => $valor, ':vi' => $hoje,
                       ':o' => $chave === 'rendimento_por_diaria' ? $OBS : 'padrão (editável)']);
        $novos++;
    }
}
echo "seed calc MO: {$novos} novos, {$pulados} já existentes (idempotente)\n";
