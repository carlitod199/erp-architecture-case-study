<?php
declare(strict_types=1);
/* ============================================================
   VERO — Pessoas / helper IFA v6 (A3-T19..T22 — análise A3-06)
   Status SEMPRE DERIVADOS (decisão da auditoria): validade de
   treinamento = data da turma + validade_meses do tema; EPI =
   entrega + vida_util_meses do item; RT = validade do registro.
   Alertas categorias `treinamento`/`epi`/`rt` (dono A3) com
   PRESERVAÇÃO de status do usuário (padrão da correção T13).
   Avisos na DF/validação: AVISAR, nunca travar (P-63/P-31).
   ============================================================ */

/** Situação de treinamento do operador na norma. status: vigente|vencendo|vencido|nunca */
function ifa_treinamento_status(int $operadorId, string $norma = 'NR-31'): array
{
    $r = vero_row(
        "SELECT tu.data, te.validade_meses, te.nome
           FROM rh_treinamento_presencas p
           JOIN rh_treinamento_turmas tu ON tu.id = p.turma_id
           JOIN rh_treinamento_temas te ON te.id = tu.tema_id
          WHERE p.tenant_id = :t AND p.operador_id = :o AND te.norma = :n
          ORDER BY tu.data DESC LIMIT 1",
        [':t' => vero_tenant(), ':o' => $operadorId, ':n' => $norma]);
    if (!$r) return ['status' => 'nunca', 'vence_em' => null, 'tema' => null];
    if ($r['validade_meses'] === null) return ['status' => 'vigente', 'vence_em' => null, 'tema' => $r['nome']];
    $vence = date('Y-m-d', strtotime($r['data'] . ' +' . (int)$r['validade_meses'] . ' months'));
    $status = $vence < date('Y-m-d') ? 'vencido'
        : ($vence <= date('Y-m-d', strtotime('+30 days')) ? 'vencendo' : 'vigente');
    return ['status' => $status, 'vence_em' => $vence, 'tema' => $r['nome']];
}

/** Registro profissional do RT. status: ativo|vencendo|vencido|sem_registro */
function ifa_rt_status(int $operadorId): array
{
    $r = vero_row(
        "SELECT conselho, numero, uf, validade FROM rt_registros
          WHERE tenant_id = :t AND operador_id = :o AND ativo = 1
          ORDER BY (validade IS NULL), validade DESC LIMIT 1",
        [':t' => vero_tenant(), ':o' => $operadorId]);
    if (!$r) return ['status' => 'sem_registro', 'rotulo' => null, 'validade' => null];
    $rotulo = strtoupper((string)$r['conselho']) . ' ' . $r['numero'] . '/' . $r['uf'];
    if ($r['validade'] === null) return ['status' => 'ativo', 'rotulo' => $rotulo, 'validade' => null];
    $status = $r['validade'] < date('Y-m-d') ? 'vencido'
        : ($r['validade'] <= date('Y-m-d', strtotime('+60 days')) ? 'vencendo' : 'ativo');
    return ['status' => $status, 'rotulo' => $rotulo, 'validade' => $r['validade']];
}

/**
 * Reemissão idempotente dos alertas de pessoas (3 categorias, dono A3),
 * preservando status do usuário por origem; escalada p/ crítico reabre.
 * Origens: treinamento → operador_id; epi → entrega_id; rt → registro_id.
 */
function ifa_reemitir_alertas_pessoas(): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    $vigentes = []; /* chave "categoria|origem_tipo|origem_id" => dados */
    /* treinamento: operadores ativos com NR-31 vencendo/vencida (só quem já treinou) */
    foreach (vero_rows("SELECT id, nome FROM agro_operadores WHERE tenant_id=:t AND ativo=1", [':t' => $t]) as $op) {
        $st = ifa_treinamento_status((int)$op['id']);
        if (!in_array($st['status'], ['vencendo', 'vencido'], true)) continue;
        $vigentes["treinamento|operador|{$op['id']}"] = [
            'categoria' => 'treinamento', 'origem_tipo' => 'operador', 'origem_id' => (int)$op['id'],
            'severidade' => $st['status'] === 'vencido' ? 'critico' : 'atencao',
            'titulo' => 'NR-31 de ' . $op['nome'] . ($st['status'] === 'vencido' ? ' VENCIDA' : ' vence em breve'),
            'mensagem' => ($st['tema'] ?? 'Treinamento') . ' — validade ' . date('d/m/Y', strtotime((string)$st['vence_em']))
                . '. Programe reciclagem (NR-31/IFA v6).',
        ];
    }
    /* epi: entregas vigentes com vida útil estourando */
    foreach (vero_rows(
        "SELECT e.id, e.data_entrega, i.nome AS item, i.vida_util_meses, o.nome AS operador
           FROM rh_epi_entregas e
           JOIN rh_epi_itens i ON i.id = e.item_id
           JOIN agro_operadores o ON o.id = e.operador_id
          WHERE e.tenant_id = :t AND e.devolvido_em IS NULL AND i.vida_util_meses IS NOT NULL", [':t' => $t]) as $e) {
        $vence = date('Y-m-d', strtotime($e['data_entrega'] . ' +' . (int)$e['vida_util_meses'] . ' months'));
        if ($vence > date('Y-m-d', strtotime('+30 days'))) continue;
        $vencido = $vence < date('Y-m-d');
        $vigentes["epi|rh_epi_entrega|{$e['id']}"] = [
            'categoria' => 'epi', 'origem_tipo' => 'rh_epi_entrega', 'origem_id' => (int)$e['id'],
            'severidade' => $vencido ? 'critico' : 'atencao',
            'titulo' => 'EPI ' . $e['item'] . ' de ' . $e['operador'] . ($vencido ? ' com vida útil VENCIDA' : ' a vencer'),
            'mensagem' => 'Entregue em ' . date('d/m/Y', strtotime((string)$e['data_entrega']))
                . ', vida útil até ' . date('d/m/Y', strtotime($vence)) . '. Programe a troca (NR-31).',
        ];
    }
    /* rt: registros ativos vencendo (≤60d) ou vencidos */
    foreach (vero_rows(
        "SELECT r.id, r.conselho, r.numero, r.validade, o.nome
           FROM rt_registros r JOIN agro_operadores o ON o.id = r.operador_id
          WHERE r.tenant_id = :t AND r.ativo = 1 AND r.validade IS NOT NULL
            AND r.validade <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)", [':t' => $t]) as $r) {
        $vencido = $r['validade'] < date('Y-m-d');
        $vigentes["rt|rt_registro|{$r['id']}"] = [
            'categoria' => 'rt', 'origem_tipo' => 'rt_registro', 'origem_id' => (int)$r['id'],
            'severidade' => $vencido ? 'critico' : 'atencao',
            'titulo' => 'Registro ' . strtoupper((string)$r['conselho']) . ' ' . $r['numero'] . ' de ' . $r['nome']
                . ($vencido ? ' VENCIDO' : ' vence em breve'),
            'mensagem' => 'Validade ' . date('d/m/Y', strtotime((string)$r['validade']))
                . '. Renove para manter a competência formal (IFA v6).',
        ];
    }

    /* estado atual + preservação (padrão T13) */
    $existentes = [];
    foreach (vero_rows(
        "SELECT id, categoria, origem_tipo, origem_id, status, severidade FROM agro_alertas
          WHERE tenant_id = :t AND categoria IN ('treinamento','epi','rt')", [':t' => $t]) as $a) {
        $existentes["{$a['categoria']}|{$a['origem_tipo']}|{$a['origem_id']}"] = $a;
    }
    foreach ($existentes as $k => $a) {
        if (!isset($vigentes[$k])) {
            $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id = ? AND id = ?")->execute([$t, (int)$a['id']]);
        }
    }
    foreach ($vigentes as $k => $novo) {
        $atual = $existentes[$k] ?? null;
        if ($atual !== null) {
            $dados = ['severidade' => $novo['severidade'], 'titulo' => $novo['titulo'],
                      'mensagem' => $novo['mensagem'], 'data' => date('Y-m-d')];
            if ($novo['severidade'] === 'critico' && (string)$atual['severidade'] !== 'critico'
                && (string)$atual['status'] !== 'aberto') {
                $dados['status'] = 'aberto';
            }
            vero_update('agro_alertas', (int)$atual['id'], $dados);
        } else {
            vero_insert('agro_alertas', [
                'categoria' => $novo['categoria'], 'origem_tipo' => $novo['origem_tipo'],
                'origem_id' => $novo['origem_id'], 'severidade' => $novo['severidade'],
                'titulo' => $novo['titulo'], 'mensagem' => $novo['mensagem'],
                'requer_validacao_tecnica' => 0, 'status' => 'aberto', 'data' => date('Y-m-d'),
            ]);
        }
    }
}
