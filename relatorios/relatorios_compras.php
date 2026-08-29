<?php
/* VERO — Relatórios / Compras (tela real, base compartilhada)
   Guard: relatorios.relatorios_compras */
$REL = [
    'micro'  => 'relatorios_compras',
    'view'   => 'relatorios_relatorios_compras',
    'titulo' => 'Relatórios de Compras',
    'sub'    => 'Pedidos, recebimentos e volume por fornecedor — com export CSV',
    'modal_first' => true, /* pedido 22/07: abre modal de filtros → Consulta rápida (paginada) ou Exportar (CSV/PDF). */
    'tipo_filtro'  => ['label' => 'Situação', 'sql' => "SELECT DISTINCT status FROM compras_pedidos WHERE tenant_id = :t AND status IS NOT NULL AND status <> '' ORDER BY 1"], /* situação do pedido de compra */
    'tabela_label' => 'Relatório', /* rótulo do seletor de dataset */
    'datasets' => [
        'pedidos' => [
            'titulo' => 'Pedidos de compra',
            'sql' => "SELECT p.numero, p.data_pedido, f.nome AS fornecedor, p.valor_total, p.status
                        FROM compras_pedidos p
                        LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                       WHERE p.tenant_id = :t
                       ORDER BY p.id DESC",
            'colunas' => ['numero' => 'Pedido', 'data_pedido' => 'Data', 'fornecedor' => 'Fornecedor',
                          'valor_total' => 'Valor (R$)', 'status' => 'Status'],
            'formato' => ['data_pedido' => 'data', 'valor_total' => 'dec2'],
            'totais'  => ['valor_total'],
            'tipo_col' => 'status', /* filtro "Situação" */
        ],
        'por_fornecedor' => [
            'titulo' => 'Volume por fornecedor',
            'sql' => "SELECT f.nome AS fornecedor, COUNT(*) AS pedidos, SUM(p.valor_total) AS total
                        FROM compras_pedidos p
                        LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                       WHERE p.tenant_id = :t AND p.status <> 'cancelado'
                       GROUP BY f.nome ORDER BY total DESC",
            'colunas' => ['fornecedor' => 'Fornecedor', 'pedidos' => 'Pedidos', 'total' => 'Total (R$)'],
            'formato' => ['pedidos' => 'dec0', 'total' => 'dec2'],
            'totais'  => ['pedidos', 'total'],
        ],
        'recebimentos' => [
            'titulo' => 'Recebimentos do período',
            'sql' => "SELECT r.numero, r.data_recebimento, p.numero AS pedido, f.nome AS fornecedor, r.status
                        FROM compras_recebimentos r
                        LEFT JOIN compras_pedidos p ON p.id = r.pedido_id
                        LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                       WHERE r.tenant_id = :t AND r.data_recebimento BETWEEN :ini AND :fim
                       ORDER BY r.id DESC",
            'colunas' => ['numero' => 'Recebimento', 'data_recebimento' => 'Data', 'pedido' => 'Pedido',
                          'fornecedor' => 'Fornecedor', 'status' => 'Status'],
            'formato' => ['data_recebimento' => 'data'],
        ],
    ],
];
require __DIR__ . '/_rel_base.php';
