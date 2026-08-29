<?php
/* VERO — Relatórios / Estoque (tela real, base compartilhada)
   Guard: relatorios.relatorios_estoque */
$REL = [
    'micro'  => 'relatorios_estoque',
    'view'   => 'relatorios_relatorios_estoque',
    'titulo' => 'Relatórios de Estoque',
    'sub'    => 'Saldos, lotes e movimentações — com export CSV',
    'modal_first' => true, /* pedido 22/07: abre modal de filtros → Consulta rápida (paginada) ou Exportar (CSV/PDF). */
    'tipo_filtro'  => ['label' => 'Tipo de movimento',
                       'sql'   => "SELECT DISTINCT tipo FROM estoque_movimentacoes WHERE tenant_id = :t ORDER BY 1"],
    'tabela_label' => 'Relatório', /* rótulo do seletor de dataset neste relatório */
    'datasets' => [
        'saldos' => [
            'titulo' => 'Saldos atuais por almoxarifado',
            'sql' => "SELECT p.codigo, p.nome AS produto, a.nome AS almoxarifado, p.unidade,
                             s.quantidade, s.custo_medio, s.valor_total
                        FROM estoque_saldos s
                        JOIN estoque_produtos p ON p.id = s.produto_id
                        LEFT JOIN almoxarifados a ON a.id = s.almoxarifado_id
                       WHERE s.tenant_id = :t AND s.quantidade > 0
                       ORDER BY p.nome, a.nome",
            'colunas' => ['codigo' => 'Código', 'produto' => 'Produto', 'almoxarifado' => 'Almoxarifado',
                          'unidade' => 'Un.', 'quantidade' => 'Saldo', 'custo_medio' => 'Custo médio (R$)',
                          'valor_total' => 'Valor (R$)'],
            'formato' => ['quantidade' => 'dec2', 'custo_medio' => 'dec4', 'valor_total' => 'dec2'],
            'totais'  => ['valor_total'],
        ],
        'lotes' => [
            'titulo' => 'Lotes com saldo (FEFO)',
            'sql' => "SELECT l.codigo_lote, p.codigo, p.nome AS produto, l.quantidade, l.validade,
                             DATEDIFF(l.validade, CURDATE()) AS dias_para_vencer
                        FROM estoque_lotes l
                        JOIN estoque_produtos p ON p.id = l.produto_id
                       WHERE l.tenant_id = :t AND l.quantidade > 0
                       ORDER BY (l.validade IS NULL), l.validade",
            'colunas' => ['codigo_lote' => 'Lote', 'codigo' => 'Código', 'produto' => 'Produto',
                          'quantidade' => 'Saldo', 'validade' => 'Validade', 'dias_para_vencer' => 'Dias p/ vencer'],
            'formato' => ['quantidade' => 'dec2', 'validade' => 'data', 'dias_para_vencer' => 'dec0'],
        ],
        'movimentacoes' => [
            'titulo' => 'Movimentações do período',
            'sql' => "SELECT mv.data_movimento, mv.tipo, p.codigo, p.nome AS produto,
                             mv.quantidade, mv.custo_unitario, mv.valor_total,
                             COALESCE(mv.origem_tipo,'manual') AS origem
                        FROM estoque_movimentacoes mv
                        JOIN estoque_produtos p ON p.id = mv.produto_id
                       WHERE mv.tenant_id = :t AND mv.data_movimento BETWEEN :ini AND :fim
                       ORDER BY mv.data_movimento DESC, mv.id DESC",
            'colunas' => ['data_movimento' => 'Data', 'tipo' => 'Tipo', 'codigo' => 'Código', 'produto' => 'Produto',
                          'quantidade' => 'Qtd', 'custo_unitario' => 'Custo unit. (R$)',
                          'valor_total' => 'Valor (R$)', 'origem' => 'Origem'],
            'formato' => ['data_movimento' => 'data', 'quantidade' => 'dec2',
                          'custo_unitario' => 'dec4', 'valor_total' => 'dec2'],
            'totais'  => ['valor_total'],
            'tipo_col' => 'tipo', /* filtro "Tipo de movimento" = entrada/saida */
        ],
        /* histórico de transferências (movido da tela de Transferências — sweep A0):
           1 linha por transferência (a SAÍDA é o registro primário; origem→destino) */
        'transferencias' => [
            'titulo' => 'Transferências entre almoxarifados',
            'sql' => "SELECT mv.data_movimento, p.codigo, p.nome AS produto,
                             ao.nome AS almox_origem, ad.nome AS almox_destino,
                             mv.quantidade, mv.custo_unitario,
                             CASE WHEN mv.estornado_em IS NULL THEN 'Concluída' ELSE 'Estornada' END AS situacao
                        FROM estoque_movimentacoes mv
                        JOIN estoque_produtos p ON p.id = mv.produto_id
                        LEFT JOIN almoxarifados ao ON ao.id = mv.almoxarifado_id
                        LEFT JOIN almoxarifados ad ON ad.id = mv.almoxarifado_destino_id
                       WHERE mv.tenant_id = :t AND mv.origem_tipo = 'transferencia' AND mv.tipo = 'saida'
                         AND mv.data_movimento BETWEEN :ini AND :fim
                       ORDER BY mv.data_movimento DESC, mv.id DESC",
            'colunas' => ['data_movimento' => 'Data', 'codigo' => 'Código', 'produto' => 'Produto',
                          'almox_origem' => 'Origem', 'almox_destino' => 'Destino',
                          'quantidade' => 'Qtd', 'custo_unitario' => 'Custo unit. (R$)', 'situacao' => 'Situação'],
            'formato' => ['data_movimento' => 'data', 'quantidade' => 'dec2', 'custo_unitario' => 'dec4'],
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
