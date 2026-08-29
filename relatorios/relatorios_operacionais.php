<?php
/* VERO — Relatórios / Operacionais (tela real, base compartilhada)
   Guard: relatorios.relatorios_operacionais */
$REL = [
    'micro'  => 'relatorios_operacionais',
    'view'   => 'relatorios_relatorios_operacionais',
    'titulo' => 'Relatórios Operacionais',
    'sub'    => 'Apontamentos, atividades, aplicações e irrigação do período',
    'modal_first' => true, /* pedido 22/07: abre modal de filtros → Consulta rápida (paginada) ou Exportar (CSV/PDF). */
    'tipo_filtro'  => true, /* filtro "Tipo de operação" = tipos de atividade do tenant */
    'tabela_label' => 'Operação', /* rótulo do seletor de dataset neste relatório */
    'datasets' => [
        'apontamentos' => [
            'titulo' => 'Apontamentos de campo',
            'sql' => "SELECT a.data_apontamento, ta.nome AS atividade, tl.codigo AS talhao,
                             (SELECT COUNT(*) FROM rh_producao_itens pi
                               WHERE pi.tenant_id = a.tenant_id AND pi.apontamento_id = a.id) AS pessoas,
                             (SELECT COALESCE(SUM(pi2.valor_total),0) FROM rh_producao_itens pi2
                               WHERE pi2.tenant_id = a.tenant_id AND pi2.apontamento_id = a.id) AS valor
                        FROM agro_apontamentos a
                        LEFT JOIN agro_tipos_atividade ta ON ta.id = a.tipo_atividade_id
                        LEFT JOIN agro_talhoes tl ON tl.id = a.talhao_id
                       WHERE a.tenant_id = :t AND a.data_apontamento BETWEEN :ini AND :fim
                       ORDER BY a.data_apontamento DESC",
            'colunas' => ['data_apontamento' => 'Data', 'atividade' => 'Atividade', 'talhao' => 'Válvula',
                          'pessoas' => 'Pessoas', 'valor' => 'Valor (R$)'],
            'formato' => ['data_apontamento' => 'data', 'pessoas' => 'dec0', 'valor' => 'dec2'],
            'totais'  => ['pessoas', 'valor'],
            'tipo_col' => 'atividade', /* filtro "Tipo de operação" */
        ],
        'atividades' => [
            'titulo' => 'Atividades planejadas',
            'sql' => "SELECT at.data_planejada, at.descricao, at.tipo, tl.codigo AS talhao, at.status
                        FROM agro_atividades at
                        LEFT JOIN agro_talhoes tl ON tl.id = at.talhao_id
                       WHERE at.tenant_id = :t
                       ORDER BY at.data_planejada DESC",
            'colunas' => ['data_planejada' => 'Data planejada', 'descricao' => 'Atividade', 'tipo' => 'Tipo',
                          'talhao' => 'Válvula', 'status' => 'Status'],
            'formato' => ['data_planejada' => 'data'],
            'tipo_col' => 'tipo',
        ],
        'aplicacoes' => [
            'titulo' => 'Aplicações',
            'sql' => "SELECT COALESCE(ap.data, ap.data_prevista) AS data_ap, ap.tipo, tl.codigo AS talhao,
                             ap.custo_total, ap.status
                        FROM agro_aplicacoes ap
                        LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
                       WHERE ap.tenant_id = :t AND COALESCE(ap.data, ap.data_prevista) BETWEEN :ini AND :fim
                       ORDER BY data_ap DESC",
            'colunas' => ['data_ap' => 'Data', 'tipo' => 'Tipo', 'talhao' => 'Válvula',
                          'custo_total' => 'Custo (R$)', 'status' => 'Status'],
            'formato' => ['data_ap' => 'data', 'custo_total' => 'dec2'],
            'totais'  => ['custo_total'],
            'tipo_col' => 'tipo',
        ],
        'irrigacao' => [
            'titulo' => 'Irrigação',
            'sql' => "SELECT ia.data_apontamento, tl.codigo AS talhao, ia.horas, ia.lamina_mm
                        FROM irrigacao_apontamentos ia
                        LEFT JOIN agro_talhoes tl ON tl.id = ia.talhao_id
                       WHERE ia.tenant_id = :t AND ia.data_apontamento BETWEEN :ini AND :fim
                       ORDER BY ia.data_apontamento DESC",
            'colunas' => ['data_apontamento' => 'Data', 'talhao' => 'Válvula', 'horas' => 'Horas', 'lamina_mm' => 'Lâmina (mm)'],
            'formato' => ['data_apontamento' => 'data', 'horas' => 'dec2', 'lamina_mm' => 'dec2'],
            'totais'  => ['horas'],
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
