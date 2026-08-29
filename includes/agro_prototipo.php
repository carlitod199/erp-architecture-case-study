<?php
declare(strict_types=1);

/* ============================================================
   VERO Agro — includes/agro_prototipo.php
   Motor de PROTOTIPAÇÃO das telas ainda não implementadas.
   Renderiza layouts profissionais (KPIs, tabelas, timelines,
   dashboards) com DADOS MOCK ilustrativos coerentes com o
   agronegócio. Os dados são fictícios (protótipo), não reais.
   Usado por agro.php para os micro módulos pendentes.
   ============================================================ */

if (!function_exists('bios_prototipo_tipo')) {
    function bios_prototipo_tipo(string $macroSlug, string $microSlug): string
    {
        $s = $macroSlug . '.' . $microSlug;
        $has = static function (array $kws) use ($s): bool {
            foreach ($kws as $k) if (str_contains($s, $k)) return true;
            return false;
        };
        if ($has(['dashboard', 'painel', 'indicadores', 'visao_geral'])) return 'dashboard';
        if ($has(['relatorio', 'exportac', 'historico', 'comparativo', 'faturamento',
                  'consumo', 'disponibilidade', 'realizado', 'planejado', 'custo_',
                  'resultado', 'depreciacao', 'valor_patrim', 'fluxo', 'dre',
                  'nivel_infestacao', 'produtividade', 'rateios'])) return 'relatorio';
        if ($has(['entrada', 'saida', 'transfer', 'apontamento', 'aplicac', 'recebimento',
                  'pedido', 'solicitac', 'abastec', 'monitor', 'vendas', 'romaneio',
                  'movimentac', 'aprovac', 'manutenc', 'conciliac', 'colheita',
                  'auditoria', 'logs'])) return 'movimentacao';
        return 'lista';
    }
}
if (!function_exists('bios_prototipo_tipo_label')) {
    function bios_prototipo_tipo_label(string $tipo): string
    {
        return ['dashboard' => 'Painel de indicadores', 'relatorio' => 'Relatório / análise',
                'movimentacao' => 'Lançamentos / linha do tempo', 'lista' => 'Cadastro / listagem'][$tipo] ?? 'Tela';
    }
}

/* ── Pools de dados mock (ilustrativos) ───────────────────── */
if (!function_exists('bios_mock_pool')) {
    function bios_mock_pool(string $k): array
    {
        static $p = null;
        if ($p === null) {
            $p = [
                'faz'     => ['Boa Vista', 'Santa Helena', 'São Jorge', 'Riacho Fundo'],
                'cult'    => ['Soja', 'Milho', 'Algodão', 'Feijão', 'Sorgo'],
                'talhao'  => ['Válvula 1A', 'Válvula 2B', 'Válvula 3C', 'Válvula 4C', 'Válvula SH-1', 'Válvula SH-2'],
                'status'  => ['ativo', 'pendente', 'concluído', 'aprovado', 'em análise', 'ativo'],
                'parte'   => ['AgroInsumos MOC', 'Sementes Vale', 'Cargill', 'Bunge', 'Vet Distribuidora', 'Frigorífico Vale'],
                'produto' => ['Glifosato 480', 'Ureia 45%', 'MAP', 'Semente Soja', 'Sivanto Prime', 'KCl', 'Diesel S10'],
                'maq'     => ['Trator JD 6110J', 'Colheitadeira CR 5.85', 'Pulverizador', 'Plantadeira 36L', 'Caminhão graneleiro'],
                'pessoa'  => ['João Silva', 'Carlos Souza', 'Pedro Lima', 'Ana Rocha', 'Marcos Dias', 'Lucas Reis'],
                'doc'     => ['NF-e 001234', 'NF-e 001235', 'Pedido PC-2026-051', 'Boleto 8841', 'Duplicata 220', 'NF-e 002010'],
                'ativo'   => ['Trator Massey 4292', 'Armazém de grãos', 'Caminhonete Hilux', 'Pivô central 1', 'Galpão sede'],
                'rel'     => ['Produção por válvula', 'Custo por cultura', 'Posição de estoque', 'Compras por fornecedor', 'Resultado da safra'],
                'cfg'     => ['Empresa Boa Vista', 'Perfil Gestor', 'Unidade hectare', 'Integração Sicoob', 'Categoria insumos'],
                'ind'     => ['Faturamento', 'Custo realizado', 'Produtividade', 'Margem', 'Inadimplência'],
                'cat'     => ['Defensivo', 'Fertilizante', 'Semente', 'Combustível', 'Serviço'],
                'data'    => ['24/06', '21/06', '18/06', '15/06', '12/06', '08/06', '03/06', '28/05'],
                'money'   => ['1.240', '3.880', '12.500', '860', '24.300', '5.420', '9.150', '740', '18.600', '2.310', '41.200', '6.700'],
                'pct'     => ['38,1', '62,4', '91,0', '12,5', '27,8', '45,3', '8,4', '73,6'],
                'ha'      => ['260', '380', '420', '310', '290', '240', '180', '520'],
                'saldo'   => ['1.250 L', '42.000 kg', '320 sc', '180 L', '28.000 kg', '140 sc', '620 L', '98 sc'],
                'horas'   => ['84 h', '120 h', '45 h', '312 h', '18 h', '206 h'],
                'consumo' => ['38.500 m³', '4,2 mm', '42,3 MWh', '1.240 m³', '12.800 m³'],
                'num'     => ['14', '5', '248', '37', '64', '9', '132', '21', '480'],
            ];
        }
        return $p[$k] ?? ['—'];
    }
}
if (!function_exists('bios_mock_pick')) {
    function bios_mock_pick(string $k, int $i): string
    {
        $a = bios_mock_pool($k);
        return $a[$i % count($a)];
    }
}

/* ── KPIs por macro (label, valor, sub) ───────────────────── */
if (!function_exists('bios_mock_kpis_spec')) {
    function bios_mock_kpis_spec(string $macro): array
    {
        $m = [
            'dashboard'  => [['Faturamento', 'R$ 12,7 mi', '+8,4% vs safra ant.'], ['Custo realizado', 'R$ 7,9 mi', '62% do orçado'], ['Margem', '38,1%', 'meta 35%'], ['Alertas', '7', '3 críticos']],
            'agricola'   => [['Válvulas', '14', '3.210 ha'], ['Área plantada', '2.980 ha', '93% do total'], ['Safras ativas', '5', '2 culturas'], ['Produtividade', '68 sc/ha', '+4% vs média']],
            'estoque'    => [['Itens', '248', '12 categorias'], ['Valor em estoque', 'R$ 1,82 mi', 'custo médio'], ['Itens críticos', '9', 'abaixo do mínimo'], ['Movimentações', '132', 'últimos 30 dias']],
            'compras'    => [['Pedidos abertos', '11', 'R$ 486 mil'], ['Aguard. aprovação', '4', 'R$ 132 mil'], ['Recebido no mês', 'R$ 312 mil', '18 notas'], ['Fornecedores', '37', 'ativos']],
            'custos'     => [['Custo total', 'R$ 7,9 mi', 'safra 25/26'], ['Custo / ha', 'R$ 2.648', '-3% vs orçado'], ['Realizado', '86%', 'do orçamento'], ['Margem prevista', '38,1%', 'soja 25/26']],
            'nutricao'   => [['Análises', '42', 'solo e foliar'], ['Válvulas avaliados', '12', '86% da área'], ['Aplicações', '28', 'no período'], ['Custo nutrição', 'R$ 1,1 mi', 'R$ 372/ha']],
            'mip'        => [['Monitoramentos', '64', 'últimos 30 dias'], ['Alvos ativos', '9', '3 acima do nível'], ['Aplicações', '21', 'defensivos'], ['Área monitorada', '2.740 ha', '92%']],
            'irrigacao'  => [['Pivôs ativos', '6', '2.140 ha'], ['Consumo de água', '38.500 m³', 'no mês'], ['Consumo energia', '42,3 MWh', 'R$ 38 mil'], ['Lâmina média', '4,2 mm/dia', 'plan. 4,5']],
            'maquinas'   => [['Frota', '23', 'máq. e veículos'], ['Disponibilidade', '91%', 'meta 90%'], ['Em manutenção', '3', '1 corretiva'], ['Custo operacional', 'R$ 268 mil', 'no mês']],
            'pessoas'    => [['Colaboradores', '48', '6 equipes'], ['Horas apontadas', '3.420 h', 'no mês'], ['Custo mão de obra', 'R$ 214 mil', 'no mês'], ['Operadores', '19', 'ativos']],
            'comercial'  => [['Estoque produção', '18.400 sc', 'soja + milho'], ['Vendas no mês', 'R$ 4,2 mi', '3 contratos'], ['Contratos ativos', '5', '12.800 sc'], ['Preço médio', 'R$ 132/sc', 'soja']],
            'financeiro' => [['A pagar', 'R$ 612 mil', 'vence em 30d'], ['A receber', 'R$ 1,38 mi', 'vence em 30d'], ['Saldo projetado', 'R$ 768 mil', 'positivo'], ['Resultado', 'R$ 2,7 mi', 'margem 38%']],
            'fiscal'     => [['Notas no mês', '86', 'entrada + saída'], ['XML importados', '74', '86%'], ['Pendentes', '12', 'a conciliar'], ['Crédito ICMS', 'R$ 142 mil', 'apurado']],
            'patrimonio' => [['Valor patrimonial', 'R$ 18,4 mi', 'atualizado'], ['Imobilizado', 'R$ 12,1 mi', 'máq + benf.'], ['Veículos', 'R$ 1,9 mi', '9 itens'], ['Depreciação', 'R$ 1,4 mi', 'no ano']],
            'relatorios' => [['Relatórios', '32', 'disponíveis'], ['Exportações', '18', 'no mês'], ['Indicadores', '24', 'monitorados'], ['Atualização', 'hoje', '08:12']],
            'configuracoes' => [['Usuários', '19', 'ativos'], ['Perfis', '6', 'configurados'], ['Integrações', '4', 'ativas'], ['Auditoria', '1.284', 'eventos · 30d']],
        ];
        return $m[$macro] ?? [['Registros', '248', 'total'], ['Ativos', '231', 'no período'], ['Pendentes', '17', 'a tratar'], ['Valor', 'R$ 1,2 mi', 'estimado']];
    }
}
if (!function_exists('bios_mock_kpis')) {
    function bios_mock_kpis(string $macro): string
    {
        $cores = ['var(--accent)', 'var(--olive)', 'var(--accent-3)', 'var(--amber)'];
        $out = '<div class="kpis">';
        foreach (bios_mock_kpis_spec($macro) as $i => $k) {
            $out .= '<div class="kpi"><div class="bar" style="background:' . $cores[$i % 4] . '"></div>'
                 . '<div class="h"><span>' . htmlspecialchars($k[0]) . '</span></div>'
                 . '<div class="v">' . htmlspecialchars($k[1]) . '</div>'
                 . '<div class="sub"><span style="font-weight:600">' . htmlspecialchars($k[2]) . '</span></div></div>';
        }
        return $out . '</div>';
    }
}

/* ── Tabela mock ──────────────────────────────────────────── */
if (!function_exists('bios_mock_cols')) {
    function bios_mock_cols(string $macro): array
    {
        $m = [
            'agricola'   => ['Válvula', 'Fazenda', 'Cultura', 'Área', 'Status'],
            'estoque'    => ['Produto', 'Categoria', 'Saldo', 'Custo unit.', 'Valor'],
            'compras'    => ['Pedido', 'Fornecedor', 'Categoria', 'Valor', 'Status'],
            'custos'     => ['Centro / cultura', 'Fazenda', 'Área', 'Custo/ha', 'Total'],
            'nutricao'   => ['Amostra', 'Válvula', 'Tipo', 'Resultado', 'Status'],
            'mip'        => ['Alvo', 'Válvula', 'Cultura', 'Nível', 'Status'],
            'irrigacao'  => ['Pivô / setor', 'Fazenda', 'Área', 'Consumo', 'Status'],
            'maquinas'   => ['Equipamento', 'Categoria', 'Horas', 'Status', 'Custo'],
            'pessoas'    => ['Colaborador', 'Equipe', 'Função', 'Horas', 'Custo'],
            'comercial'  => ['Contrato / lote', 'Comprador', 'Cultura', 'Quantidade', 'Valor'],
            'financeiro' => ['Documento', 'Parte', 'Vencimento', 'Valor', 'Status'],
            'fiscal'     => ['Documento', 'Emitente', 'Categoria', 'Valor', 'Status'],
            'patrimonio' => ['Ativo', 'Categoria', 'Aquisição', 'Valor', 'Deprec.'],
            'relatorios' => ['Relatório', 'Categoria', 'Período', 'Formato', 'Atualizado'],
            'configuracoes' => ['Item', 'Categoria', 'Responsável', 'Atualizado', 'Status'],
            'dashboard'  => ['Indicador', 'Referência', 'Atual', 'Meta', 'Variação'],
        ];
        return $m[$macro] ?? ['Descrição', 'Fazenda', 'Categoria', 'Quantidade', 'Valor'];
    }
}
if (!function_exists('bios_mock_main_pool')) {
    function bios_mock_main_pool(string $macro): string
    {
        return ['agricola' => 'talhao', 'estoque' => 'produto', 'compras' => 'doc', 'custos' => 'cult',
                'nutricao' => 'talhao', 'mip' => 'cult', 'irrigacao' => 'talhao', 'maquinas' => 'maq',
                'pessoas' => 'pessoa', 'comercial' => 'doc', 'financeiro' => 'doc', 'fiscal' => 'doc',
                'patrimonio' => 'ativo', 'relatorios' => 'rel', 'configuracoes' => 'cfg', 'dashboard' => 'ind'][$macro] ?? 'produto';
    }
}
if (!function_exists('bios_mock_cell')) {
    /** Renderiza uma célula mock conforme o cabeçalho da coluna. */
    function bios_mock_cell(string $header, string $macro, int $r, int $c): string
    {
        $h = strtr(mb_strtolower($header), ['á'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        $seed = $r * 7 + $c * 3;

        if ($c === 0) {
            $dotc = ['var(--accent)', 'var(--olive)', 'var(--accent-3)', 'var(--amber)', '#A7C9CB'][$r % 5];
            return '<div class="pc-main"><span class="pc-dot" style="background:' . $dotc . '"></span>' . htmlspecialchars(bios_mock_pick(bios_mock_main_pool($macro), $r)) . '</div>';
        }
        if (str_contains($h, '/ha'))                                   return '<div class="pc-num">R$ ' . bios_mock_pick('money', $seed) . '</div>';
        if (str_contains($h, 'valor') || str_contains($h, 'total') || str_contains($h, 'custo') || str_contains($h, 'receber') || str_contains($h, 'pagar'))
                                                                        return '<div class="pc-num">R$ ' . bios_mock_pick('money', $seed) . '</div>';
        if (str_contains($h, 'area'))                                  return '<div class="pc-num">' . bios_mock_pick('ha', $seed) . ' ha</div>';
        if (str_contains($h, 'deprec') || str_contains($h, 'variacao') || str_contains($h, 'margem') || str_contains($h, 'nivel') || str_contains($h, 'resultado'))
                                                                        return '<div class="pc-num">' . bios_mock_pick('pct', $seed) . '%</div>';
        if (str_contains($h, 'saldo') || str_contains($h, 'quantidade'))return '<div class="pc-num">' . bios_mock_pick('saldo', $seed) . '</div>';
        if (str_contains($h, 'consumo'))                               return '<div class="pc-num">' . bios_mock_pick('consumo', $seed) . '</div>';
        if (str_contains($h, 'hora'))                                  return '<div class="pc-num">' . bios_mock_pick('horas', $seed) . '</div>';
        if (str_contains($h, 'atual') || str_contains($h, 'meta'))     return '<div class="pc-num">' . bios_mock_pick('num', $seed) . '</div>';
        if (str_contains($h, 'status')) {
            $st = bios_mock_pick('status', $r);
            $cls = in_array($st, ['ativo', 'aprovado', 'concluído'], true) ? 'ok' : (in_array($st, ['pendente', 'em análise'], true) ? 'warn' : 'neu');
            return '<div><span class="pc-tag ' . $cls . '">' . htmlspecialchars($st) . '</span></div>';
        }
        if (str_contains($h, 'venc') || str_contains($h, 'periodo') || str_contains($h, 'aquisic') || str_contains($h, 'atualizado') || str_contains($h, 'data'))
                                                                        return '<div class="pc-mut">' . bios_mock_pick('data', $seed) . '</div>';
        if (str_contains($h, 'formato'))                               return '<div class="pc-mut">' . (($r % 2) ? 'PDF' : 'XLSX') . '</div>';
        if (str_contains($h, 'cultura'))                               return '<div class="pc-mut">' . htmlspecialchars(bios_mock_pick('cult', $seed)) . '</div>';
        if (str_contains($h, 'fazenda'))                               return '<div class="pc-mut">' . htmlspecialchars(bios_mock_pick('faz', $seed)) . '</div>';
        if (str_contains($h, 'fornecedor') || str_contains($h, 'comprador') || str_contains($h, 'emitente') || str_contains($h, 'parte') || str_contains($h, 'responsavel'))
                                                                        return '<div class="pc-mut">' . htmlspecialchars(bios_mock_pick('parte', $seed)) . '</div>';
        if (str_contains($h, 'equipe') || str_contains($h, 'funcao'))  return '<div class="pc-mut">' . htmlspecialchars(bios_mock_pick(($r % 2) ? 'cat' : 'faz', $seed)) . '</div>';
        return '<div class="pc-mut">' . htmlspecialchars(bios_mock_pick('cat', $seed)) . '</div>';
    }
}
if (!function_exists('bios_mock_table')) {
    function bios_mock_table(string $titulo, string $macro, int $rows = 8): string
    {
        $cols = bios_mock_cols($macro);
        $n = count($cols);
        $tpl = 'grid-template-columns:1.6fr ' . str_repeat('1fr ', max(0, $n - 1)) . ';gap:12px';
        $head = '<div class="proto-row proto-head" style="' . $tpl . '">';
        foreach ($cols as $col) $head .= '<div>' . htmlspecialchars($col) . '</div>';
        $head .= '</div>';
        $body = '';
        for ($r = 0; $r < $rows; $r++) {
            $body .= '<div class="proto-row" style="' . $tpl . '">';
            foreach ($cols as $c => $col) $body .= bios_mock_cell($col, $macro, $r, $c);
            $body .= '</div>';
        }
        return '<div class="card"><div class="card-h"><h2>' . htmlspecialchars($titulo) . '</h2>'
             . '<span class="ey">dados ilustrativos</span></div>'
             . '<div class="card-b tight">' . $head . $body . '</div></div>';
    }
}

/* ── Timeline mock ────────────────────────────────────────── */
if (!function_exists('bios_mock_timeline')) {
    function bios_mock_timeline(string $titulo, string $macro, int $rows = 7): string
    {
        $tit = [
            'estoque'    => ['Entrada — Ureia 45%', 'Saída — Sivanto Prime', 'Transferência — Glifosato', 'Saída — Semente Soja', 'Entrada — MAP', 'Inventário parcial', 'Saída — Diesel S10'],
            'compras'    => ['Pedido PC-2026-051 recebido', 'Solicitação aprovada', 'Pedido PC-2026-052 emitido', 'Recebimento parcial', 'Cotação registrada', 'Aprovação pendente', 'Nota conferida'],
            'maquinas'   => ['Abastecimento — Trator JD', 'Manutenção preventiva', 'Troca de óleo', 'Reparo corretivo', 'Pneu substituído', 'Revisão programada', 'Lubrificação'],
            'mip'        => ['Monitoramento — Válvula 3C', 'Aplicação de defensivo', 'Amostragem registrada', 'Nível acima do limite', 'Vistoria concluída', 'Alerta emitido', 'Revisita à válvula'],
            'financeiro' => ['Título a pagar lançado', 'Recebimento confirmado', 'Conciliação bancária', 'Despesa registrada', 'Boleto emitido', 'Estorno aplicado', 'Baixa de duplicata'],
            'comercial'  => ['Venda registrada', 'Romaneio de saída', 'Contrato firmado', 'Carga expedida', 'Classificação concluída', 'Faturamento emitido', 'Frete contratado'],
            'agricola'   => ['Pulverização — Válvula 3C', 'Adubação de cobertura', 'Plantio — Válvula 4C', 'Apontamento de hora-máquina', 'Colheita — Válvula 2B', 'Preparo de solo', 'Romaneio de colheita'],
        ];
        $titulos = $tit[$macro] ?? ['Lançamento registrado', 'Movimentação confirmada', 'Apontamento de campo', 'Operação concluída', 'Item conferido', 'Registro aprovado', 'Evento lançado'];
        $body = '';
        for ($r = 0; $r < $rows; $r++) {
            $kinds = ['#0E7E72', '#B57C1A', '#005059', '#4E9CA1', '#B23A2E'];
            $body .= '<div class="proto-le">'
                  . '<span class="proto-dot" style="background:' . $kinds[$r % 5] . '"></span>'
                  . '<div style="flex:1"><div class="pc-main" style="font-size:13px">' . htmlspecialchars($titulos[$r % count($titulos)]) . '</div>'
                  . '<div class="pc-mut" style="margin-top:2px">' . htmlspecialchars(bios_mock_pick('faz', $r)) . ' · ' . htmlspecialchars(bios_mock_pick('cult', $r + 2)) . '</div></div>'
                  . '<div style="text-align:right"><div class="pc-num">R$ ' . bios_mock_pick('money', $r * 3) . '</div>'
                  . '<div class="pc-mut" style="font-size:10.5px">' . bios_mock_pick('data', $r) . '</div></div></div>';
        }
        return '<div class="card"><div class="card-h"><h2>' . htmlspecialchars($titulo) . '</h2>'
             . '<span class="ey">dados ilustrativos</span></div><div class="card-b">' . $body . '</div></div>';
    }
}

/* ── Gráfico mock ─────────────────────────────────────────── */
if (!function_exists('bios_mock_chart')) {
    function bios_mock_chart(string $titulo): string
    {
        $labels = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];
        $vals   = [62, 48, 80, 55, 70, 40, 90, 58, 66, 50, 76, 60];
        $bars = '';
        foreach ($labels as $i => $lb) {
            $bars .= '<div class="proto-bar"><i style="height:' . $vals[$i] . '%;background:var(--accent);opacity:' . (0.55 + ($vals[$i] / 250)) . '"></i><small>' . $lb . '</small></div>';
        }
        return '<div class="card"><div class="card-h"><h2>' . htmlspecialchars($titulo) . '</h2>'
             . '<span class="ey">dados ilustrativos</span></div><div class="card-b"><div class="proto-chart">' . $bars . '</div></div></div>';
    }
}

/* ── Painel lateral (composição) mock ─────────────────────── */
if (!function_exists('bios_mock_side')) {
    function bios_mock_side(string $titulo, string $macro, int $rows = 5): string
    {
        $pool = in_array($macro, ['estoque', 'compras', 'custos'], true) ? 'cat'
              : (in_array($macro, ['comercial', 'agricola', 'mip', 'nutricao'], true) ? 'cult' : 'faz');
        $cores = ['var(--accent)', 'var(--olive)', 'var(--accent-3)', 'var(--amber)', '#A7C9CB'];
        $body = '';
        for ($r = 0; $r < $rows; $r++) {
            $body .= '<div class="proto-side-r">'
                  . '<span style="display:flex;align-items:center;gap:8px;color:var(--ink2)"><span class="pc-dot" style="background:' . $cores[$r % 5] . '"></span>' . htmlspecialchars(bios_mock_pick($pool, $r)) . '</span>'
                  . '<span class="pc-num">R$ ' . bios_mock_pick('money', $r * 5) . '</span></div>';
        }
        return '<div class="card"><div class="card-h"><h2>' . htmlspecialchars($titulo) . '</h2>'
             . '<span class="ey">composição</span></div><div class="card-b">' . $body . '</div></div>';
    }
}

/* ── Gráficos (Apache ECharts via assets/js/agro-charts.js) ── */
if (!function_exists('bios_echart_seq')) {
    function bios_echart_seq(): int { static $n = 0; return ++$n; }
}
if (!function_exists('bios_chart_vals')) {
    /** Série pseudo-aleatória determinística (mock). */
    function bios_chart_vals(int $n, int $min, int $max, int $seed): array
    {
        $a = []; $range = max(1, $max - $min);
        for ($i = 0; $i < $n; $i++) $a[] = $min + (($seed * 37 + $i * $i * 13 + $i * 29) % $range);
        return $a;
    }
}
if (!function_exists('bios_echart_box')) {
    /** Card + container + script de init (executa no DOMContentLoaded). */
    function bios_echart_box(string $titulo, string $ey, string $height, string $initExpr): string
    {
        $id = 'ch' . bios_echart_seq();
        $js = str_replace('__ID__', $id, $initExpr);
        return '<div class="card"><div class="card-h"><h2>' . htmlspecialchars($titulo) . '</h2>'
             . '<span class="ey">' . htmlspecialchars($ey) . '</span></div>'
             . '<div class="card-b"><div id="' . $id . '" style="height:' . $height . '"></div></div></div>'
             . '<script>document.addEventListener("DOMContentLoaded",function(){if(window.BiosCharts){' . $js . '}});</script>';
    }
}
if (!function_exists('bios_echart_evolucao')) {
    function bios_echart_evolucao(string $titulo, string $macro): string
    {
        $seed = crc32($macro) % 97 + 3;
        $labels = json_encode(['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'], JSON_UNESCAPED_UNICODE);
        $vals = array_map(fn($v) => 3500000 + $v * 1300, bios_chart_vals(12, 0, 900, $seed));
        $data = json_encode($vals);
        $expr = "BiosCharts.area('__ID__',{unit:'R\$',labels:" . $labels . ",series:[{name:'Realizado',data:" . $data . "}]});";
        return bios_echart_box($titulo, 'dados ilustrativos', '280px', $expr);
    }
}
if (!function_exists('bios_echart_barras')) {
    function bios_echart_barras(string $titulo, string $macro): string
    {
        $seed = crc32($macro . 'b') % 89 + 5;
        $pool = in_array($macro, ['agricola', 'custos', 'nutricao', 'mip', 'irrigacao', 'comercial'], true) ? 'talhao'
              : (in_array($macro, ['maquinas', 'patrimonio'], true) ? 'maq' : 'cult');
        $catsArr = array_slice(bios_mock_pool($pool), 0, 6);
        $labels = json_encode(array_values($catsArr), JSON_UNESCAPED_UNICODE);
        $vals = array_map(fn($v) => 180000 + $v * 1700, bios_chart_vals(count($catsArr), 0, 600, $seed));
        $data = json_encode($vals);
        $expr = "BiosCharts.bar('__ID__',{unit:'R\$',labels:" . $labels . ",series:[{name:'Valor',data:" . $data . "}]});";
        return bios_echart_box($titulo, 'dados ilustrativos', '280px', $expr);
    }
}
if (!function_exists('bios_echart_donut')) {
    function bios_echart_donut(string $titulo, string $macro): string
    {
        $seed = crc32($macro . 'd') % 83 + 7;
        $pool = in_array($macro, ['estoque', 'compras', 'custos', 'financeiro', 'maquinas'], true) ? 'cat'
              : (in_array($macro, ['agricola', 'comercial', 'mip', 'nutricao'], true) ? 'cult' : 'faz');
        $names = array_slice(bios_mock_pool($pool), 0, 5);
        $vals = array_map(fn($v) => 400000 + $v * 3200, bios_chart_vals(count($names), 0, 600, $seed));
        $sum = array_sum($vals);
        $items = [];
        foreach ($names as $i => $nm) $items[] = ['name' => $nm, 'value' => $vals[$i]];
        $data = json_encode($items, JSON_UNESCAPED_UNICODE);
        $center = 'R$ ' . number_format($sum / 1e6, 1, ',', '.') . ' mi';
        $expr = "BiosCharts.donut('__ID__',{unit:'R\$',center:'" . $center . "',data:" . $data . "});";
        return bios_echart_box($titulo, 'composição', '300px', $expr);
    }
}

/* ── Renderizador principal ───────────────────────────────── */
if (!function_exists('bios_prototipo_render')) {
    function bios_prototipo_render(array $macro, array $micro): string
    {
        $tipo  = bios_prototipo_tipo($macro['slug'], $micro['slug']);
        $label = $micro['label'];
        $ms    = $macro['slug'];

        switch ($tipo) {
            case 'dashboard':
                return bios_mock_kpis($ms)
                    . '<div class="row2">' . bios_echart_evolucao($label . ' · evolução', $ms) . bios_echart_donut('Composição', $ms) . '</div>'
                    . '<div class="row2">' . bios_echart_barras('Comparativo', $ms) . bios_mock_side('Maiores valores', $ms) . '</div>';
            case 'relatorio':
                return bios_mock_kpis($ms)
                    . bios_mock_table($label, $ms, 8)
                    . '<div class="row2">' . bios_echart_barras('Visão analítica', $ms) . bios_echart_donut('Resumo', $ms) . '</div>';
            case 'movimentacao':
                return bios_mock_kpis($ms)
                    . '<div class="row2">' . bios_mock_timeline($label, $ms) . bios_echart_donut('Resumo do período', $ms) . '</div>'
                    . bios_echart_evolucao($label . ' · no período', $ms);
            case 'lista':
            default:
                return bios_mock_kpis($ms)
                    . bios_mock_table($label, $ms, 8)
                    . '<div class="row2">' . bios_echart_donut('Distribuição', $ms) . bios_mock_side('Detalhes', $ms) . '</div>';
        }
    }
}
