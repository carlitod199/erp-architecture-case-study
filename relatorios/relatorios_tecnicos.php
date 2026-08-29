<?php
/* VERO — Relatórios / Técnicos (tela real, base compartilhada)
   Guard: relatorios.relatorios_tecnicos */
$REL = [
    'micro'  => 'relatorios_tecnicos',
    'view'   => 'relatorios_relatorios_tecnicos',
    'titulo' => 'Relatórios Técnicos',
    'sub'    => 'Análises, monitoramentos MIP e aplicações validadas — com export CSV',
    'modal_first' => true, /* pedido 22/07: modal de filtros → Consulta rápida (paginada) ou Exportar (Excel/PDF). */
    'tabela_label' => 'Relatório', /* datasets são tipos de relatório distintos (análise, MIP, aplicações) */
    /* sem 'tipo_filtro': datasets heterogêneos, sem coluna categórica comum (análise de solo não tem 'tipo';
       'tipo' em MIP = av.tipo e em aplicações = ap.tipo têm significados/tabelas diferentes) */
    'datasets' => [
        'analises_solo' => [
            'titulo' => 'Resultados de análise de solo',
            'sql' => "SELECT a.data_amostra, tl.codigo AS talhao, n.simbolo, n.nome AS nutriente,
                             r.valor, r.unidade, COALESCE(r.classificacao,'sem faixa') AS classificacao
                        FROM analise_solo_resultados r
                        JOIN analise_solo a ON a.id = r.analise_id
                        LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id
                        LEFT JOIN analise_nutrientes n ON n.id = r.nutriente_id
                       WHERE r.tenant_id = :t
                       ORDER BY a.data_amostra DESC, n.ordem",
            'colunas' => ['data_amostra' => 'Amostra', 'talhao' => 'Válvula', 'simbolo' => 'Símbolo',
                          'nutriente' => 'Nutriente', 'valor' => 'Valor', 'unidade' => 'Un.', 'classificacao' => 'Classificação'],
            'formato' => ['data_amostra' => 'data', 'valor' => 'dec2'],
        ],
        'monitoramentos' => [
            'titulo' => 'Monitoramentos MIP',
            'sql' => "SELECT m.data_monitoramento, av.nome AS alvo, av.tipo, tl.codigo AS talhao,
                             m.nivel_infestacao, av.nivel_acao
                        FROM mip_monitoramentos m
                        LEFT JOIN mip_alvos av ON av.id = m.alvo_id
                        LEFT JOIN agro_talhoes tl ON tl.id = m.talhao_id
                       WHERE m.tenant_id = :t AND m.data_monitoramento BETWEEN :ini AND :fim
                       ORDER BY m.data_monitoramento DESC",
            'colunas' => ['data_monitoramento' => 'Data', 'alvo' => 'Alvo', 'tipo' => 'Tipo', 'talhao' => 'Válvula',
                          'nivel_infestacao' => 'Índice', 'nivel_acao' => 'Nível de ação'],
            'formato' => ['data_monitoramento' => 'data', 'nivel_infestacao' => 'dec2', 'nivel_acao' => 'dec2'],
        ],
        'aplicacoes_validadas' => [
            'titulo' => 'Aplicações validadas pelo RT',
            'sql' => "SELECT COALESCE(ap.data, ap.data_prevista) AS data_ap, ap.tipo, tl.codigo AS talhao,
                             u.nome AS validado_por_nome, ap.custo_total
                        FROM agro_aplicacoes ap
                        LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
                        LEFT JOIN usuarios u ON u.id = ap.validado_por
                       WHERE ap.tenant_id = :t AND ap.status = 'validada'
                       ORDER BY data_ap DESC",
            'colunas' => ['data_ap' => 'Data', 'tipo' => 'Tipo', 'talhao' => 'Válvula',
                          'validado_por_nome' => 'Validado por', 'custo_total' => 'Custo (R$)'],
            'formato' => ['data_ap' => 'data', 'custo_total' => 'dec2'],
            'totais'  => ['custo_total'],
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
