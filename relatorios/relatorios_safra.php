<?php
/* VERO — Relatórios / Safra (tela real, base compartilhada)
   Guard: relatorios.relatorios_safra */
$REL = [
    'micro'  => 'relatorios_safra',
    'view'   => 'relatorios_relatorios_safra',
    'titulo' => 'Relatórios de Safra',
    'sub'    => 'Resultado consolidado por safra e por válvula — com export CSV',
    'modal_first' => true, /* pedido 22/07: modal de filtros → Consulta rápida (paginada) ou Exportar (Excel/PDF). */
    /* filtro "Safra" sob medida: todos os datasets expõem a coluna 'safra' (= agro_safras.identificacao) */
    'tipo_filtro'  => ['label' => 'Safra', 'sql' => "SELECT DISTINCT identificacao FROM agro_safras WHERE tenant_id = :t ORDER BY 1"],
    'tabela_label' => 'Relatório', /* rótulo do seletor de dataset neste relatório */
    /* painel READ-ONLY F-05/F-06 (auditoria 19/07) — mesmo guard desta tela */
    'acoes_html' => '<a class="vbtn vbtn-ghost vbtn-sm no-print" href="integridade_producao.php"'
        . ' title="Cross-check colheita × estoque × venda por safra">Integridade produção → estoque</a>',
    'datasets' => [
        'resultado' => [
            'titulo' => 'Resultado por safra',
            'sql' => "SELECT sa.identificacao AS safra,
                             (SELECT COALESCE(SUM(cr.kg_total_realizado),0) FROM colheita_registros cr
                               WHERE cr.tenant_id = sa.tenant_id AND cr.safra_id = sa.id) AS colhido_kg,
                             (SELECT COALESCE(SUM(v.valor_total),0) FROM comercial_vendas v
                               WHERE v.tenant_id = sa.tenant_id AND v.safra_id = sa.id AND v.status <> 'cancelada') AS faturamento,
                             (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
                               WHERE cl.tenant_id = sa.tenant_id AND cl.safra_id = sa.id) AS custo,
                             (SELECT COALESCE(SUM(v2.valor_total),0) FROM comercial_vendas v2
                               WHERE v2.tenant_id = sa.tenant_id AND v2.safra_id = sa.id AND v2.status <> 'cancelada')
                           - (SELECT COALESCE(SUM(cl2.valor),0) FROM custeio_lancamentos cl2
                               WHERE cl2.tenant_id = sa.tenant_id AND cl2.safra_id = sa.id) AS resultado
                        FROM agro_safras sa
                       WHERE sa.tenant_id = :t
                       ORDER BY sa.identificacao DESC",
            'colunas' => ['safra' => 'Safra', 'colhido_kg' => 'Colhido (kg)', 'faturamento' => 'Faturamento (R$)',
                          'custo' => 'Custo (R$)', 'resultado' => 'Resultado (R$)'],
            'formato' => ['colhido_kg' => 'dec0', 'faturamento' => 'dec2', 'custo' => 'dec2', 'resultado' => 'dec2'],
            'totais'  => ['colhido_kg', 'faturamento', 'custo', 'resultado'],
            'tipo_col' => 'safra', /* filtro "Safra" */
        ],
        'custo_talhao' => [
            'titulo' => 'Custo por válvula e categoria',
            'sql' => "SELECT sa.identificacao AS safra, tl.codigo AS talhao,
                             COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total
                        FROM custeio_lancamentos cl
                        LEFT JOIN agro_safras sa ON sa.id = cl.safra_id
                        LEFT JOIN agro_talhoes tl ON tl.id = cl.talhao_id
                       WHERE cl.tenant_id = :t AND cl.data_competencia BETWEEN :ini AND :fim
                       GROUP BY sa.identificacao, tl.codigo, categoria
                       ORDER BY sa.identificacao DESC, tl.codigo, total DESC",
            'colunas' => ['safra' => 'Safra', 'talhao' => 'Válvula', 'categoria' => 'Categoria', 'total' => 'Total (R$)'],
            'formato' => ['total' => 'dec2'],
            'totais'  => ['total'],
            'tipo_col' => 'safra', /* filtro "Safra" */
        ],
        'orcado_realizado' => [
            'titulo' => 'Orçado × realizado por safra',
            'sql' => "SELECT sa.identificacao AS safra, oi.categoria,
                             SUM(oi.valor_previsto) AS previsto,
                             (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
                               WHERE cl.tenant_id = o.tenant_id AND cl.safra_id = o.safra_id
                                 AND COALESCE(cl.categoria,'outros') = oi.categoria) AS realizado
                        FROM custeio_orcamentos o
                        JOIN custeio_orcamento_itens oi ON oi.orcamento_id = o.id AND oi.tenant_id = o.tenant_id
                        LEFT JOIN agro_safras sa ON sa.id = o.safra_id
                       WHERE o.tenant_id = :t AND o.status = 'vigente'
                       GROUP BY sa.identificacao, oi.categoria, o.tenant_id, o.safra_id
                       ORDER BY sa.identificacao DESC, previsto DESC",
            'colunas' => ['safra' => 'Safra', 'categoria' => 'Categoria',
                          'previsto' => 'Previsto (R$)', 'realizado' => 'Realizado (R$)'],
            'formato' => ['previsto' => 'dec2', 'realizado' => 'dec2'],
            'totais'  => ['previsto', 'realizado'],
            'tipo_col' => 'safra', /* filtro "Safra" */
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
