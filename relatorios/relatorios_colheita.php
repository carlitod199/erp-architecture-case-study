<?php
/* VERO — Relatórios / Colheita (tela real, base compartilhada)
   Guard: relatorios.relatorios_colheita */
$REL = [
    'micro'  => 'relatorios_colheita',
    'view'   => 'relatorios_relatorios_colheita',
    'titulo' => 'Relatórios de Colheita',
    'sub'    => 'Registros, classificações e cargas — com export CSV',
    'modal_first' => true, /* pedido 22/07: abre modal de filtros → Consulta rápida (paginada) ou Exportar (CSV/PDF). */
    'tabela_label' => 'Relatório', /* rótulo do seletor de dataset */
    'datasets' => [
        'registros' => [
            'titulo' => 'Registros de colheita',
            'sql' => "SELECT cr.data_colheita, tl.codigo AS talhao, sa.identificacao AS safra,
                             cu.nome AS cultura, cr.kg_total_previsto, cr.kg_total_realizado,
                             cr.faturamento_realizado
                        FROM colheita_registros cr
                        LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
                        LEFT JOIN agro_safras sa ON sa.id = cr.safra_id
                        LEFT JOIN agro_culturas cu ON cu.id = cr.cultura_id
                       WHERE cr.tenant_id = :t
                       ORDER BY cr.data_colheita DESC",
            'colunas' => ['data_colheita' => 'Data', 'talhao' => 'Válvula', 'safra' => 'Safra', 'cultura' => 'Cultura',
                          'kg_total_previsto' => 'Previsto (kg)', 'kg_total_realizado' => 'Realizado (kg)',
                          'faturamento_realizado' => 'Receita estimada (classificação) (R$)'],
            'formato' => ['data_colheita' => 'data', 'kg_total_previsto' => 'dec0',
                          'kg_total_realizado' => 'dec0', 'faturamento_realizado' => 'dec2'],
            'totais'  => ['kg_total_previsto', 'kg_total_realizado', 'faturamento_realizado'],
            'nota'    => 'Receita estimada pela classificação (preço × kg classificado) — não é a venda realizada (ver Relatórios Comerciais/Financeiros).',
        ],
        'classificacoes' => [
            'titulo' => 'Classificações',
            'sql' => "SELECT cr.data_colheita, tl.codigo AS talhao, cc.momento, cc.categoria,
                             cc.percentual, cc.kg_calculado, cc.preco_kg, cc.faturamento
                        FROM colheita_classificacoes cc
                        JOIN colheita_registros cr ON cr.id = cc.registro_id
                        LEFT JOIN agro_talhoes tl ON tl.id = cr.talhao_id
                       WHERE cc.tenant_id = :t
                       ORDER BY cr.data_colheita DESC, cc.momento, cc.categoria",
            'colunas' => ['data_colheita' => 'Data', 'talhao' => 'Válvula', 'momento' => 'Momento',
                          'categoria' => 'Categoria', 'percentual' => '%', 'kg_calculado' => 'Kg',
                          'preco_kg' => 'R$/kg', 'faturamento' => 'Receita estimada (classificação) (R$)'],
            'formato' => ['data_colheita' => 'data', 'percentual' => 'dec2', 'kg_calculado' => 'dec0',
                          'preco_kg' => 'dec2', 'faturamento' => 'dec2'],
            'totais'  => ['kg_calculado', 'faturamento'],
            'nota'    => 'Receita estimada pela classificação (preço × kg classificado) — não é a venda realizada (ver Relatórios Comerciais/Financeiros).',
        ],
        'cargas' => [
            'titulo' => 'Cargas / romaneios de colheita',
            'sql' => "SELECT c.data_carga, c.romaneio, tl.codigo AS talhao, c.classificacao, c.peso_kg
                        FROM colheita_cargas c
                        LEFT JOIN agro_talhoes tl ON tl.id = c.talhao_id
                       WHERE c.tenant_id = :t AND c.data_carga BETWEEN :ini AND :fim
                       ORDER BY c.data_carga DESC",
            'colunas' => ['data_carga' => 'Data', 'romaneio' => 'Romaneio', 'talhao' => 'Válvula',
                          'classificacao' => 'Classificação', 'peso_kg' => 'Peso (kg)'],
            'formato' => ['data_carga' => 'data', 'peso_kg' => 'dec0'],
            'totais'  => ['peso_kg'],
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
