<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/30_relatorios.php  (A5-QA)
   Relatórios/dashboards × GABARITO.md + F12 exports CSV:
   BOM EF BB BF, separador ';', decimal vírgula e o MESMO gate
   de permissão da tela. Requer 00 + 20 executados.
   Uso: php 30_relatorios.php
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_boot_app();
$T = qa_tenant_id();
$ini = '2026-07-01';
$fim = '2026-07-31';

qa_section('Pré-condições');
$temVenda = (float)qa_val("SELECT COALESCE(SUM(valor_total),0) FROM comercial_vendas
    WHERE tenant_id=? AND status<>'cancelada'", [$T]);
qa_check('fluxos executados (venda 519.922,50 presente)', abs($temVenda - 519922.50) < 0.01, $temVenda);
if (!qa_http_login('super')) {
    qa_check('login HTTP qa.super', false, 'base_url inacessível');
    qa_finish('30_relatorios');
}
qa_check('login HTTP qa.super', true);

/* helper: baixa um CSV e valida BOM/; e conteúdo */
function qa_csv(string $papel, string $rota, string $contem, string $desc): void
{
    $r = qa_http_get($papel, $rota);
    qa_check("{$desc}: HTTP 200 text/csv", $r['code'] === 200
        && str_contains(strtolower($r['headers']), 'text/csv'), $r['code']);
    qa_check("{$desc}: BOM EF BB BF", str_starts_with($r['body'], "\xEF\xBB\xBF"));
    qa_check("{$desc}: separador ';'", substr_count(strtok($r['body'], "\n") ?: '', ';') >= 1);
    qa_check("{$desc}: contém {$contem} (vírgula decimal)", str_contains($r['body'], $contem));
}

/* ── Resultado da safra + custo por válvula (motor _rel_base) ── */
qa_section('Relatórios da safra (motor _rel_base)');
$q = "?aplicar=1&ini={$ini}&fim={$fim}";
$r = qa_http_get('super', "/relatorios/relatorios_safra.php{$q}");
qa_check('tela renderiza saudável', qa_pagina_saudavel($r) === [], qa_pagina_saudavel($r));
qa_check('resultado: faturamento 519.922,50 na tela', str_contains($r['body'], '519.922,50'));
qa_csv('super', "/relatorios/relatorios_safra.php{$q}&csv=resultado", '519922,50', 'CSV resultado da safra');
qa_csv('super', "/relatorios/relatorios_safra.php{$q}&csv=custo_talhao", '366,00', 'CSV custo por válvula');

/* gate de permissão do CSV: operador NÃO tem relatorios.* */
qa_section('Gate de permissão dos exports');
if (qa_http_login('operador')) {
    $r = qa_http_get('operador', "/relatorios/relatorios_safra.php{$q}&csv=resultado");
    $bloqueado = $r['code'] === 403
        || ($r['code'] === 302)
        || !str_contains(strtolower($r['headers']), 'text/csv');
    qa_check('operador sem relatorios.* NÃO baixa o CSV', $bloqueado,
        ['http' => $r['code'], 'ct' => $r['headers']]);
} else {
    qa_skip('gate CSV operador', 'login operador falhou');
}

/* ── Faturamento por comprador e por cultura ── */
qa_section('Faturamento comercial');
qa_http_login('super');
$r = qa_http_get('super', '/comercial/faturamento_comprador.php');
qa_check('por comprador: QA Comprador com 519.922,50',
    str_contains($r['body'], 'QA Comprador') && str_contains($r['body'], '519.922,50'),
    qa_pagina_saudavel($r));
$r = qa_http_get('super', '/comercial/faturamento_cultura.php');
qa_check('por cultura: QA Uva com 519.922,50',
    str_contains($r['body'], 'QA Uva') && str_contains($r['body'], '519.922,50'),
    qa_pagina_saudavel($r));

/* ── Folha do período (CSV standalone, mesmo padrão) ── */
qa_section('Folha do período');
$per = (int)qa_val("SELECT id FROM rh_folha_periodos WHERE tenant_id=? AND competencia='2026-07-01'", [$T]);
if ($per) {
    qa_csv('super', "/pessoas/folha.php?csv={$per}", '1569,77', 'CSV folha jul/26');
    $r = qa_http_get('super', "/pessoas/folha.php?ver={$per}");
    qa_check('tela da folha: líquido 1.569,77 e INSS teto 951,63',
        str_contains($r['body'], '1.569,77') && str_contains($r['body'], '951,63'), qa_pagina_saudavel($r));
} else {
    qa_skip('folha do período', 'período jul/26 ausente (20_fluxos não rodou?)');
}

/* ── Valor patrimonial ── */
qa_section('Valor patrimonial');
$r = qa_http_get('super', '/patrimonio/valor_patrimonial.php');
qa_check('valor líquido 248.333,33 na tela', str_contains($r['body'], '248.333,33'), qa_pagina_saudavel($r));

/* ── Dashboards ── */
qa_section('Dashboards');
foreach (['/dashboard.php', '/dashboard/dashboard_financeiro.php', '/dashboard/dashboard_operacional.php',
          '/custeio/dashboard_custos.php', '/custeio/resultado_safra.php', '/custeio/custos.php',
          '/custeio/custo_hectare.php', '/financeiro/fluxo_caixa.php', '/financeiro/dre_agro.php'] as $rota) {
    $r = qa_http_get('super', $rota);
    qa_check("dashboard saudável: {$rota}", qa_pagina_saudavel($r) === [], qa_pagina_saudavel($r));
}
$r = qa_http_get('super', '/custeio/resultado_safra.php');
qa_check('resultado da safra na tela (519.295,43)', str_contains($r['body'], '519.295,43'),
    'valor não encontrado no HTML');
$r = qa_http_get('super', '/custeio/custos.php?safra=' . (int)qa_val(
    "SELECT id FROM agro_safras WHERE tenant_id=? AND identificacao='QA 2026/2'", [$T]));
qa_check('matriz de custos exibe QA-1A', str_contains($r['body'], 'QA-1A'), qa_pagina_saudavel($r));

/* ── Verificador do razão (tela) ── */
qa_section('Verificador do razão');
$r = qa_http_get('super', '/financeiro/verificador_razao.php');
$probs = qa_pagina_saudavel($r);
qa_check('verificador renderiza saudável', $probs === [], $probs);
qa_check('verificador não acusa quebra', !preg_match('/quebra|divergên|inválid/i', strip_tags($r['body']))
    || str_contains($r['body'], '0 divergên'), 'ver corpo');

qa_finish('30_relatorios');
