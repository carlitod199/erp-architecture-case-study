<?php
declare(strict_types=1);

/* ============================================================
   VERO Agro — includes/agro_icons.php
   Biblioteca central de ícones (SVG inline, stroke currentColor).
   - Glyphs: dicionário chave => path
   - Macro: slug do macro módulo => glyph
   - Micro: slug do micro módulo => glyph (1 ícone distinto por item)
   Usado por includes/sidebar.php.
   ============================================================ */

if (!function_exists('bios_icon_glyphs')) {
    function bios_icon_glyphs(): array
    {
        return [
            // genéricos / navegação
            'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1.4"/><rect x="14" y="3" width="7" height="7" rx="1.4"/><rect x="3" y="14" width="7" height="7" rx="1.4"/><rect x="14" y="14" width="7" height="7" rx="1.4"/>',
            'briefcase'   => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
            'activity'    => '<path d="M3 12h4l2 6 4-14 2 8h6"/>',
            'cash'        => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.4"/><path d="M6 9v6M18 9v6"/>',
            'bell'        => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'barn'        => '<path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-7h6v7"/>',
            'parcel'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/>',
            'sprout'      => '<path d="M12 21V11M12 11c-2-1-2.4-3-2.4-4.2C11.6 6.8 12 8.8 12 11Zm0 0c2-1 2.4-3 2.4-4.2C12.4 6.8 12 8.8 12 11Z"/>',
            'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'calendar_check' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4"/>',
            'map'         => '<path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2Z"/><path d="M9 4v14M15 6v14"/>',
            'clipboard'   => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"/>',
            'clipboard_check' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1M9 13l2 2 4-4"/>',
            'pencil'      => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'harvest'     => '<path d="M7 20s-3-2-3-7c0-3 2-5 4-5 1.5 0 2.5 1 3 2M12 20V8M12 8c0-3 2-5 5-5 0 4-2 6-5 6"/>',
            'receipt'     => '<path d="M5 21V4a1 1 0 0 1 1-1l1 1 1.5-1L11 4l1.5-1L14 4l1.5-1L17 4l1-1a1 1 0 0 1 1 1v17l-2-1.3-2 1.3-2-1.3-2 1.3-2-1.3L7 21Z"/><path d="M8 8h8M8 12h6"/>',
            'trending'    => '<path d="M3 17l6-6 4 4 8-8"/><path d="M17 7h4v4"/>',
            'trending_down' => '<path d="M3 7l6 6 4-4 8 8"/><path d="M17 17h4v-4"/>',
            'box'         => '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>',
            'folder'      => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
            'warehouse'   => '<path d="M3 21V8l9-4 9 4v13M3 21h18M7 21v-7h10v7M7 14h10"/>',
            'arrow_in'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12l4 4 4-4"/>',
            'arrow_out'   => '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8 12l4-4 4 4"/>',
            'swap'        => '<path d="M7 4 3 8l4 4"/><path d="M3 8h13M17 20l4-4-4-4"/><path d="M21 16H8"/>',
            'alert'       => '<path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
            'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/>',
            'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
            'id'          => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.4 1.8-2 3-2s2.4.6 3 2M14 9h4M14 13h3"/>',
            'qr'          => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M20.5 14v.01M14 20.5v.01M20.5 20.5v.01M17 17.5v.01"/>',
            'barcode'     => '<path d="M4 6v12M7 6v12M9.5 6v9M12 6v12M14.5 6v12M17 6v9M20 6v12"/>',
            'check'       => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
            'wallet'      => '<path d="M3 7a2 2 0 0 1 2-2h12v4M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6"/><circle cx="16.5" cy="13" r="1.2"/>',
            'coin'        => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/>',
            'ruler'       => '<rect x="2" y="7" width="20" height="10" rx="2" transform="rotate(0 12 12)"/><path d="M6 7v3M10 7v4M14 7v3M18 7v4"/>',
            'split'       => '<path d="M12 4v6M12 10 7 16M12 10l5 6"/><circle cx="12" cy="4" r="1.6"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
            'lock'        => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'layers'      => '<path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="M3 13l9 5 9-5"/>',
            'leaf'        => '<path d="M11 20A7 7 0 0 1 4 13c0-4 3-7 7-9 4 2 7 5 7 9a7 7 0 0 1-7 7Z"/><path d="M11 20v-9"/>',
            'flask'       => '<path d="M9 3h6M10 3v6L5 19a1 1 0 0 0 .9 1.5h12.2A1 1 0 0 0 19 19l-5-10V3"/>',
            'sliders'     => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10M14 4v6M8 14v6"/>',
            'gauge'       => '<path d="M3 18a9 9 0 1 1 18 0"/><path d="M12 14l4-4"/>',
            'spray'       => '<path d="M4 21h7v-7H4zM7.5 14V8M7.5 8l8-3M8 5h.01M12 7h.01M15 5h.01"/>',
            'bug'         => '<path d="M12 2c3 4 5 7 5 11a5 5 0 0 1-10 0c0-4 2-7 5-11Z"/>',
            'inseto'      => '<circle cx="12" cy="5" r="2"/><path d="M10.6 3.6 9 2M13.4 3.6 15 2"/><path d="M12 8a5 6 0 0 1 5 6 5 6 0 0 1-10 0 5 6 0 0 1 5-6Z"/><path d="M12 8v12"/><path d="M7 12H3M7 17l-3 2M17 12h4M17 17l3 2"/>',
            'virus'       => '<circle cx="12" cy="12" r="5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
            'target'      => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
            'pin'         => '<path d="M12 21s-6-5.7-6-10a6 6 0 0 1 12 0c0 4.3-6 10-6 10Z"/><circle cx="12" cy="11" r="2"/>',
            'eye'         => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
            'chart'       => '<path d="M3 3v18h18"/><path d="M7 14l3-3 3 2 5-6"/>',
            'drop'        => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11Z"/>',
            'wave'        => '<path d="M3 9c2-2 4-2 6 0s4 2 6 0 4-2 6 0M3 15c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/>',
            'bolt'        => '<path d="M13 2 3 14h7l-1 8 10-12h-7z"/>',
            'maquinas'    => '<path d="M3 17h3l1-5h6l2 5h3M7 12V7h4l3 5"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
            'wrench'      => '<path d="M14.5 6.5a4 4 0 0 1 4 5.5l-9 9-3-3 9-9a4 4 0 0 1-1-2.5Z"/>',
            'cog'         => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
            'fuel'        => '<path d="M3 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M3 12h10M16 8l3 3v6a2 2 0 0 1-4 0"/>',
            'building'    => '<path d="M3 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M15 21V9h4a2 2 0 0 1 2 2v10M7 7h2M7 11h2M7 15h2"/>',
            'scale'       => '<path d="M12 3v18M7 21h10M5 7l-3 6h6zM5 7l7-2M19 7l-3 6h6zM19 7l-7-2"/>',
            'file'        => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/>',
            'bank'        => '<path d="M3 10l9-6 9 6M5 10v9M19 10v9M9 10v9M15 10v9M3 21h18"/>',
            'list'        => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'truck'       => '<path d="M3 17h11V6H3zM14 9h4l3 3v5h-7"/><circle cx="7.5" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
            'tag'         => '<path d="M20 12l-8 8-9-9V3h8z"/><path d="M7 7h.01"/>',
            'download'    => '<path d="M12 3v12M8 11l4 4 4-4M4 21h16"/>',
            'cloud_down'  => '<path d="M6 16a4 4 0 0 1 0-8 5 5 0 0 1 9.6-1.5A3.5 3.5 0 0 1 18 16Z"/><path d="M12 12v5M10 15l2 2 2-2"/>',
            'code'        => '<path d="M9 7 4 12l5 5M15 7l5 5-5 5M13 4l-2 16"/>',
            'upload'      => '<path d="M12 15V3M8 7l4-4 4 4M4 21h16"/>',
            'book'        => '<path d="M5 4a2 2 0 0 1 2-2h12v18H7a2 2 0 0 0-2 2Z"/><path d="M19 17H7a2 2 0 0 0-2 2"/>',
            'send'        => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4Z"/>',
            'shield'      => '<path d="M12 3l8 3v6c0 4.5-3.4 7.8-8 9-4.6-1.2-8-4.5-8-9V6Z"/><path d="M9 12l2 2 4-4"/>',
            'plug'        => '<path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 0 1-10 0ZM12 16v6"/>',
            'compras'     => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2.5l2.2 12.3a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 7H6"/>',
            'fiscal'      => '<path d="M6 3h12v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L6 21V3Z"/><path d="M9 8h6M9 12h6"/>',
            'configuracoes' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-2.82 1.17V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 8 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 3.6 15H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 16 4.6l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9V9a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        ];
    }
}

if (!function_exists('bios_macro_icon_map')) {
    function bios_macro_icon_map(): array
    {
        return [
            'dashboard' => 'grid', 'agricola' => 'barn', 'estoque' => 'box', 'compras' => 'compras',
            'custos' => 'coin', 'nutricao' => 'leaf', 'mip' => 'inseto', 'irrigacao' => 'drop',
            'maquinas' => 'maquinas', 'pessoas' => 'users', 'comercial' => 'warehouse',
            'financeiro' => 'chart', 'fiscal' => 'fiscal', 'patrimonio' => 'bank',
            'relatorios' => 'file', 'configuracoes' => 'configuracoes',
            'planejamento' => 'calendar_check', // C-15 (18/07): calendário com check — planejar ≠ apontar
            'cadastros' => 'folder', 'apontamentos' => 'clipboard', // macros de agregação (correção UX 08/07) — prancheta
            'packing' => 'box', // Onda 1: Packing House (reusa o glyph de estoque)
        ];
    }
}

if (!function_exists('bios_micro_icon_map')) {
    /** 1 ícone distinto por micro módulo (slug => glyph). */
    function bios_micro_icon_map(): array
    {
        return [
            // Dashboard
            'visao_geral' => 'grid', 'dashboard_executivo' => 'briefcase', 'dashboard_operacional' => 'activity',
            'dashboard_financeiro' => 'cash', 'indicadores_alertas' => 'bell',
            // Gestão Agrícola
            'fazendas' => 'barn', 'areas_produtivas' => 'grid', 'talhoes' => 'parcel', 'culturas' => 'sprout',
            'safras' => 'calendar', 'mapa_fazenda' => 'map', 'planejamento_atividades' => 'calendar_check',
            'ordens_servico' => 'clipboard', 'apontamentos_campo' => 'pencil', 'colheita' => 'harvest',
            'romaneios_colheita' => 'receipt', 'produtividade' => 'trending',
            // Estoque
            'produtos_insumos' => 'box', 'grupos_subgrupos' => 'folder', 'almoxarifados' => 'warehouse',
            'entradas' => 'arrow_in', 'saidas' => 'arrow_out', 'transferencias' => 'swap',
            'lotes_validade' => 'calendar', 'inventario' => 'clipboard_check', 'estoque_critico' => 'alert',
            'historico_movimentacoes' => 'clock',
            // Compras
            'fornecedores' => 'user', 'solicitacoes_compra' => 'clipboard', 'pedidos_compra' => 'compras',
            'aprovacoes' => 'check', 'recebimentos' => 'arrow_in', 'historico_compras' => 'clock',
            'compras_fora_orcamento' => 'alert',
            // Custos e Safra
            'orcamento_safra' => 'wallet', 'custo_realizado' => 'coin', 'realizado_planejado' => 'swap',
            'custo_fazenda' => 'barn', 'custo_talhao' => 'parcel', 'custo_cultura' => 'sprout',
            'custo_hectare' => 'ruler', 'custo_categoria' => 'folder', 'rateios' => 'split',
            'fechamento_safra' => 'lock', 'resultado_safra' => 'trending',
            // Nutrição
            'analise_solo' => 'layers', 'analise_foliar' => 'leaf', 'nutrientes' => 'flask',
            'faixas_nutricionais' => 'sliders', 'historico_nutricional' => 'clock', 'comparativo_safra' => 'swap',
            'painel_nutrientes' => 'gauge', 'aplicacoes_nutricionais' => 'spray',
            // MIP
            'pragas' => 'bug', 'doencas' => 'virus', 'alvos_controle' => 'target', 'pontos_amostragem' => 'pin',
            'monitoramentos' => 'eye', 'nivel_infestacao' => 'trending', 'aplicacoes_defensivos' => 'spray',
            'alertas_fitossanitarios' => 'bell', 'historico_talhao' => 'clock', 'relatorios_mip' => 'chart',
            // Irrigação
            'pivos' => 'drop', 'setores_irrigacao' => 'grid', 'planejamento_irrigacao' => 'calendar_check',
            'apontamentos_irrigacao' => 'pencil', 'planejado_realizado' => 'swap', 'consumo_agua' => 'wave',
            'consumo_energia' => 'bolt', 'fertirrigacao' => 'flask', 'custo_irrigacao' => 'coin',
            // Máquinas
            'maquinas' => 'maquinas', 'veiculos' => 'truck', 'implementos' => 'cog', 'horimetro' => 'clock',
            'odometro' => 'gauge', 'abastecimentos' => 'fuel', 'manutencao_preventiva' => 'calendar_check',
            'manutencao_corretiva' => 'wrench', 'pecas_servicos' => 'box', 'custo_operacional' => 'coin',
            'disponibilidade_frota' => 'check',
            // Pessoas
            'equipes' => 'users', 'operadores' => 'user', 'responsaveis_tecnicos' => 'id',
            'apontamento_mao_obra' => 'pencil', 'custo_mao_obra' => 'coin', 'historico_colaborador' => 'clock',
            // Comercial
            'estoque_producao' => 'box', 'armazenagem_propria' => 'warehouse', 'armazenagem_terceiros' => 'building',
            'classificacao_producao' => 'scale', 'compradores' => 'user', 'vendas' => 'compras',
            'contratos_venda' => 'file', 'romaneios_saida' => 'receipt', 'logistica_frete' => 'truck',
            'faturamento_comprador' => 'cash', 'faturamento_cultura' => 'tag',
            // Financeiro
            'contas_pagar' => 'arrow_out', 'contas_receber' => 'arrow_in', 'despesas' => 'wallet',
            'recebimentos' => 'cash', 'plano_contas' => 'list', 'centros_custo' => 'split',
            'contas_bancarias' => 'bank', 'conciliacao_bancaria' => 'swap', 'fluxo_caixa' => 'trending',
            'dre_agro' => 'clipboard', 'relatorios_financeiros' => 'file',
            // Fiscal
            'documentos_fiscais' => 'file', 'importacao_nfe' => 'download', 'importacao_nfse' => 'cloud_down',
            'upload_xml' => 'code', 'upload_pdf' => 'upload', 'conciliacao_fiscal' => 'swap',
            'livro_caixa_produtor' => 'book', 'acesso_contador' => 'user', 'emissao_nfe' => 'send',
            'emissao_mdfe' => 'truck', 'historico_fiscal' => 'clock', 'relatorios_fiscais' => 'chart',
            // Patrimônio
            'terras' => 'map', 'benfeitorias' => 'building', 'maquinas_ativos' => 'maquinas',
            'veiculos_ativos' => 'truck', 'equipamentos' => 'wrench', 'valor_patrimonial' => 'coin',
            'localizacao_ativos' => 'pin', 'depreciacao_gerencial' => 'trending_down', 'relatorios_patrimoniais' => 'chart',
            // Relatórios
            /* padronizado (22/07): todos os "Relatórios X" usam o mesmo ícone de relatório (file) */
            'relatorios_operacionais' => 'file', 'relatorios_safra' => 'file',
            'relatorios_estoque' => 'file', 'relatorios_compras' => 'file', 'relatorios_colheita' => 'file',
            'relatorios_tecnicos' => 'file', 'indicadores_estrategicos' => 'gauge', 'exportacoes' => 'download',
            // Configurações
            'empresa_fazenda' => 'barn', 'usuarios' => 'users', 'perfis_acesso' => 'id', 'permissoes' => 'shield',
            'parametros_sistema' => 'sliders', 'unidades_medida' => 'ruler', 'categorias' => 'folder',
            'integracoes' => 'plug', 'auditoria' => 'clock', 'logs_sistema' => 'list',
            // Packing House
            'painel' => 'grid', 'recepcao' => 'arrow_in', 'relogio_frio' => 'clock', 'apontar' => 'parcel',
            'apontar_colheita' => 'harvest',
            'unidade' => 'building', 'crachas' => 'qr', 'embalagens' => 'parcel',
            'skus' => 'tag', 'etiqueta_caixa' => 'barcode', 'mercados' => 'send', 'certificacoes' => 'shield',
            'licencas_varietais' => 'leaf',
        ];
    }
}

if (!function_exists('bios_icon')) {
    function bios_icon(string $glyphKey, int $size = 18): string
    {
        $g = bios_icon_glyphs();
        $p = $g[$glyphKey] ?? '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>';
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
    }
}

if (!function_exists('bios_macro_icon')) {
    function bios_macro_icon(string $slug, int $size = 18): string
    {
        return bios_icon(bios_macro_icon_map()[$slug] ?? 'grid', $size);
    }
}

if (!function_exists('bios_micro_icon')) {
    function bios_micro_icon(string $slug, int $size = 22): string
    {
        // micro específico → senão usa o ícone do macro homônimo → senão 'list'
        $map = bios_micro_icon_map();
        $key = $map[$slug] ?? (bios_macro_icon_map()[$slug] ?? 'list');
        return bios_icon($key, $size);
    }
}
