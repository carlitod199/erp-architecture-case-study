<?php
declare(strict_types=1);

/* ============================================================
   VERO Agro — includes/planos.php
   Controle de PLANO contratado por tenant (multiempresa).
   Decisão de arquitetura (jun/2026): plano em arquivo de
   configuração (sem coluna no banco) — fallback seguro = 'completo'.
   Quando houver necessidade de gerir plano por tela/DB, basta
   trocar bios_plano_tenant() por uma leitura de tabela.
   ============================================================ */

if (!function_exists('bios_planos_catalogo')) {
    /** Rótulos legíveis dos planos comerciais. */
    function bios_planos_catalogo(): array
    {
        return [
            'pequeno_inicial'  => 'Pequeno Produtor — Inicial',
            'pequeno_safra'    => 'Pequeno Produtor — Safra',
            'pequeno_completo' => 'Pequeno Produtor — Completo',
            'essencial'        => 'Essencial',
            'profissional'     => 'Profissional',
            'completo'         => 'Completo',
        ];
    }
}

if (!function_exists('bios_planos_mapa_tenant')) {
    /**
     * Mapa tenant_id => plano contratado.
     * Tenants não listados caem no fallback seguro (completo) — não
     * escondem nada e não quebram clientes existentes.
     */
    function bios_planos_mapa_tenant(): array
    {
        return [
            1 => 'completo',
        ];
    }
}

if (!function_exists('bios_plano_tenant')) {
    /** Plano efetivo do tenant logado (ou informado). Fallback: completo. */
    function bios_plano_tenant(?int $tenantId = null): string
    {
        $tenantId = $tenantId ?? (int)($_SESSION['tenant_id'] ?? 0);
        $mapa = bios_planos_mapa_tenant();
        $plano = $mapa[$tenantId] ?? 'completo';
        return array_key_exists($plano, bios_planos_catalogo()) ? $plano : 'completo';
    }
}

if (!function_exists('bios_plano_label')) {
    function bios_plano_label(?string $plano = null): string
    {
        $plano = $plano ?? bios_plano_tenant();
        return bios_planos_catalogo()[$plano] ?? 'Completo';
    }
}

if (!function_exists('bios_planos_regras')) {
    /**
     * Matriz de cobertura por plano.
     *   'full'    => macros liberados por inteiro (todos os micros).
     *   'parcial' => macro => [micros liberados] (cobertura parcial).
     * O plano 'completo' libera tudo (tratado em bios_plano_libera()).
     * Os subconjuntos abaixo refletem o escopo comercial e são
     * ajustáveis sem tocar no menu nem no RBAC.
     */
    function bios_planos_regras(): array
    {
        // Blocos reaproveitados para compor os planos (DRY).
        $dashIni   = ['visao_geral', 'indicadores_alertas'];
        $dashPro   = array_merge($dashIni, ['dashboard_executivo', 'dashboard_operacional', 'dashboard_financeiro']);
        $agriBas   = ['fazendas', 'areas_produtivas', 'talhoes', 'culturas', 'safras', 'mapa_fazenda'];
        $agriSafra = array_merge($agriBas, ['planejamento_atividades', 'ordens_servico', 'apontamentos_campo', 'colheita', 'romaneios_colheita', 'produtividade']);
        $estSimples = ['produtos_insumos', 'grupos_subgrupos', 'entradas', 'saidas', 'estoque_critico', 'historico_movimentacoes'];
        $comprasBas = ['fornecedores', 'solicitacoes_compra', 'pedidos_compra', 'recebimentos', 'historico_compras'];
        $finBas    = ['contas_pagar', 'contas_receber', 'despesas', 'recebimentos', 'fluxo_caixa'];
        $finEss    = array_merge($finBas, ['plano_contas', 'centros_custo', 'contas_bancarias', 'conciliacao_bancaria', 'dre_agro', 'relatorios_financeiros']);
        $custoSafra = ['orcamento_safra', 'custo_realizado', 'custo_talhao', 'custo_hectare', 'resultado_safra'];
        $fiscalDoc = ['documentos_fiscais', 'importacao_nfe', 'importacao_nfse', 'upload_xml', 'upload_pdf', 'historico_fiscal'];

        return [
            'pequeno_inicial' => [
                'full'    => [],
                'parcial' => [
                    'dashboard'  => ['visao_geral'],
                    'agricola'   => $agriBas,
                    'estoque'    => $estSimples,
                    'compras'    => $comprasBas,
                    'financeiro' => $finBas,
                ],
            ],
            'pequeno_safra' => [
                'full'    => [],
                'parcial' => [
                    'dashboard'  => $dashIni,
                    'agricola'   => $agriSafra,
                    'estoque'    => $estSimples,
                    'compras'    => $comprasBas,
                    'custos'     => $custoSafra,
                    'financeiro' => $finBas,
                ],
            ],
            'pequeno_completo' => [
                'full'    => ['nutricao', 'mip', 'irrigacao', 'comercial'],
                'parcial' => [
                    'dashboard'  => $dashIni,
                    'agricola'   => $agriSafra,
                    'estoque'    => $estSimples,
                    'compras'    => $comprasBas,
                    'custos'     => $custoSafra,
                    'maquinas'   => ['maquinas', 'veiculos', 'implementos', 'abastecimentos', 'manutencao_corretiva'],
                    'financeiro' => $finEss,
                    'fiscal'     => $fiscalDoc,
                ],
            ],
            'essencial' => [
                'full'    => ['estoque', 'compras', 'configuracoes'],
                'parcial' => [
                    'dashboard'  => $dashIni,
                    'agricola'   => $agriBas,
                    'financeiro' => $finEss,
                ],
            ],
            'profissional' => [
                'full'    => ['agricola', 'estoque', 'compras', 'custos', 'configuracoes'],
                'parcial' => [
                    'dashboard'  => $dashPro,
                    'financeiro' => $finEss,
                ],
            ],
            // 'completo' => tudo (ver bios_plano_libera).
        ];
    }
}

if (!function_exists('bios_plano_libera')) {
    /** Verdadeiro se o plano contempla o par macro/micro informado. */
    function bios_plano_libera(string $plano, string $macroSlug, string $microSlug): bool
    {
        if ($plano === 'completo') {
            return true;
        }
        $regras = bios_planos_regras();
        if (!isset($regras[$plano])) {
            return true; // fallback seguro: plano desconhecido não esconde nada
        }
        $r = $regras[$plano];
        if (in_array($macroSlug, $r['full'] ?? [], true)) {
            return true;
        }
        $micros = $r['parcial'][$macroSlug] ?? null;
        return is_array($micros) && in_array($microSlug, $micros, true);
    }
}

if (!function_exists('bios_plano_libera_macro')) {
    /** Verdadeiro se o plano contempla ao menos parte do macro módulo. */
    function bios_plano_libera_macro(string $plano, string $macroSlug): bool
    {
        if ($plano === 'completo') {
            return true;
        }
        $regras = bios_planos_regras();
        if (!isset($regras[$plano])) {
            return true;
        }
        $r = $regras[$plano];
        if (in_array($macroSlug, $r['full'] ?? [], true)) {
            return true;
        }
        return !empty($r['parcial'][$macroSlug] ?? []);
    }
}
