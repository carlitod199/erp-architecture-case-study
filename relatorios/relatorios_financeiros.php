<?php
/* VERO — Relatórios / Financeiros (tela real, base compartilhada)
   Guard: relatorios.relatorios_financeiros */
$REL = [
    'micro'  => 'relatorios_financeiros',
    'view'   => 'relatorios_relatorios_financeiros',
    'titulo' => 'Relatórios Financeiros',
    'sub'    => 'Razão, custeio e caixa do período — com export CSV',
    'modal_first' => true, /* pedido 22/07: abre modal de filtros → Consulta rápida (paginada) ou Exportar (CSV/PDF). */
    'tipo_filtro'  => ['label' => 'Tipo de lançamento',
                       'sql'   => "SELECT DISTINCT tipo FROM movimentacoes_financeiras WHERE tenant_id = :t ORDER BY 1"],
    'tabela_label' => 'Relatório', /* rótulo do seletor de dataset neste relatório */
    'datasets' => [
        'razao' => [
            'titulo' => 'Razão financeiro',
            'sql' => "SELECT COALESCE(m.data_pagamento, m.data_vencimento, m.data_competencia) AS data_ref,
                             m.tipo, m.descricao, COALESCE(m.origem_tipo,'manual') AS origem, m.valor, m.status
                        FROM movimentacoes_financeiras m
                       WHERE m.tenant_id = :t
                         AND COALESCE(m.data_pagamento, m.data_vencimento, m.data_competencia) BETWEEN :ini AND :fim
                       ORDER BY data_ref, m.id",
            'colunas' => ['data_ref' => 'Data', 'tipo' => 'Tipo', 'descricao' => 'Descrição',
                          'origem' => 'Origem', 'valor' => 'Valor (R$)', 'status' => 'Status'],
            'formato' => ['data_ref' => 'data', 'valor' => 'dec2'],
            'totais'  => ['valor'],
            'tipo_col' => 'tipo', /* filtro "Tipo de lançamento" = pagar/receber */
        ],
        'custeio' => [
            'titulo' => 'Custeio por lançamento',
            'sql' => "SELECT cl.data_competencia, COALESCE(cl.categoria,'outros') AS categoria,
                             tl.codigo AS talhao, sa.identificacao AS safra,
                             COALESCE(cl.origem_tipo,'manual') AS origem, cl.valor
                        FROM custeio_lancamentos cl
                        LEFT JOIN agro_talhoes tl ON tl.id = cl.talhao_id
                        LEFT JOIN agro_safras sa ON sa.id = cl.safra_id
                       WHERE cl.tenant_id = :t AND cl.data_competencia BETWEEN :ini AND :fim
                       ORDER BY cl.data_competencia, cl.id",
            'colunas' => ['data_competencia' => 'Competência', 'categoria' => 'Categoria', 'talhao' => 'Válvula',
                          'safra' => 'Safra', 'origem' => 'Origem', 'valor' => 'Valor (R$)'],
            'formato' => ['data_competencia' => 'data', 'valor' => 'dec2'],
            'totais'  => ['valor'],
        ],
        'caixa_mensal' => [
            'titulo' => 'Caixa por mês (pagos)',
            'sql' => "SELECT DATE_FORMAT(m.data_pagamento, '%m/%Y') AS mes,
                             SUM(CASE WHEN m.tipo='receber' THEN m.valor ELSE 0 END) AS entradas,
                             SUM(CASE WHEN m.tipo='pagar' THEN m.valor ELSE 0 END) AS saidas,
                             SUM(CASE WHEN m.tipo='receber' THEN m.valor ELSE -m.valor END) AS saldo
                        FROM movimentacoes_financeiras m
                       WHERE m.tenant_id = :t AND m.status = 'pago' AND m.data_pagamento BETWEEN :ini AND :fim
                       GROUP BY DATE_FORMAT(m.data_pagamento, '%Y-%m'), mes
                       ORDER BY DATE_FORMAT(m.data_pagamento, '%Y-%m')",
            'colunas' => ['mes' => 'Mês', 'entradas' => 'Entradas (R$)', 'saidas' => 'Saídas (R$)', 'saldo' => 'Saldo (R$)'],
            'formato' => ['entradas' => 'dec2', 'saidas' => 'dec2', 'saldo' => 'dec2'],
            'totais'  => ['entradas', 'saidas', 'saldo'],
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
