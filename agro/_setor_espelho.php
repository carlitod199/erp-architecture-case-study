<?php
/* ============================================================
   VERO — Helper válvula-espelho 1:1 (domínio A1 — A1-35/A1-32)
   Arbitragem A0 (04/07): estratégia B — talhão é a entidade-MESTRE;
   no modo UNIFICADO (parâmetro `agro.valvula_igual_talhao` = '1',
   decisão do cliente: válvula e talhão são a mesma coisa) cada
   talhão tem UMA válvula-espelho em agro_setores, criada e
   sincronizada pelo cadastro do talhão. No modo HIERÁRQUICO
   (parâmetro ausente/'0') nada muda — N válvulas por talhão.
   Tolerante à ausência da coluna `is_espelho` (DB-34, migration
   do pacote A0): identifica o espelho pela coluna quando existe,
   senão pela convenção "setor ativo vinculado ao talhão".
   Rótulo (P-57, cliente): modo unificado exibe "Válvula".
   ============================================================ */
declare(strict_types=1);

/** Modo unificado ligado para o tenant atual? */
function vero_a1_valvula_unificada(): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = vero_srv_param('agro.valvula_igual_talhao', '0') === '1';
    }
    return $cache;
}

/** Rótulo da unidade produtiva nas telas A1 (P-57: cliente fala "válvula"). */
function vero_a1_rotulo_area(bool $plural = false): string
{
    if (vero_a1_valvula_unificada()) {
        return $plural ? 'Válvulas' : 'Válvula';
    }
    return $plural ? 'Talhões' : 'Talhão';
}

/** A coluna is_espelho (DB-34) já existe? (migration entra no pacote A0) */
function vero_a1_tem_coluna_espelho(): bool
{
    static $tem = null;
    if ($tem === null) $tem = vero_has_column('agro_setores', 'is_espelho');
    return $tem;
}

/**
 * Válvula-espelho do talhão (id do agro_setores) ou NULL.
 * Preferência: setor marcado is_espelho=1; fallback: setor ativo do talhão.
 */
function vero_a1_setor_espelho_id(int $talhaoId): ?int
{
    $t = vero_tenant();
    if (vero_a1_tem_coluna_espelho()) {
        $id = vero_val(
            "SELECT id FROM agro_setores
              WHERE tenant_id = :t AND talhao_id = :ta AND is_espelho = 1
              ORDER BY ativo DESC, id LIMIT 1",
            [':t' => $t, ':ta' => $talhaoId]);
        if ($id) return (int)$id;
    }
    $id = vero_val(
        "SELECT id FROM agro_setores
          WHERE tenant_id = :t AND talhao_id = :ta AND ativo = 1
          ORDER BY id LIMIT 1",
        [':t' => $t, ':ta' => $talhaoId]);
    return $id ? (int)$id : null;
}

/**
 * Garante e SINCRONIZA a válvula-espelho de um talhão (get-or-create).
 * Chamar após salvar o talhão, SÓ no modo unificado. Sincroniza
 * código/nome/área/fazenda/ativo — o talhão é a fonte da verdade.
 * @param array $talhao linha de agro_talhoes (id, codigo, nome, area_ha, fazenda_id, ativo)
 * @return int id do setor-espelho
 */
function vero_a1_sync_espelho(array $talhao): int
{
    $t = vero_tenant();
    $dados = [
        'talhao_id'  => (int)$talhao['id'],
        'fazenda_id' => (int)$talhao['fazenda_id'],
        'codigo'     => (string)$talhao['codigo'],
        'nome'       => (string)($talhao['nome'] ?: $talhao['codigo']),
        'tipo'       => 'valvula',
        'area_ha'    => $talhao['area_ha'] !== null ? (float)$talhao['area_ha'] : null,
        'ativo'      => (int)$talhao['ativo'],
    ];
    if (vero_a1_tem_coluna_espelho()) $dados['is_espelho'] = 1;

    $espelhoId = vero_a1_setor_espelho_id((int)$talhao['id']);
    if ($espelhoId) {
        vero_update('agro_setores', $espelhoId, $dados);
        return $espelhoId;
    }
    return vero_insert('agro_setores', $dados);
}
