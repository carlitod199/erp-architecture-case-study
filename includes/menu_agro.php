<?php
declare(strict_types=1);

/* ============================================================
   VERO Agro — includes/menu_agro.php
   Configuração CENTRAL de navegação (macro × micro módulos).
   Fonte única de verdade para sidebar, breadcrumb, roteador de
   placeholders e guards de acesso (permissão + plano).

   Cada MACRO módulo:
     slug, label, icone, permbase (prefixo das permissões),
     perm (permissão da área), micros[]

   Cada MICRO módulo:
     slug, label, perm (permissão específica),
     rota   (caminho real; ausente/null => placeholder genérico),
     view   (chave de $PAGE_VIEW para telas reais já existentes)

   Pecuária, viticultura e itens fora de escopo (GIS, telemetria,
   folha de pagamento, balança etc.) NÃO entram nesta matriz.
   ============================================================ */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/planos.php';

if (!function_exists('bios_menu_macros')) {
    /** Matriz completa dos macro módulos e seus micro módulos. */
    function bios_menu_macros(): array
    {
        /* Sistemas satélite (VERO CRM): mesma ESTRUTURA de menu do VERO
           (sidebar macro+micro), mas com matriz própria — o shell inteiro é
           reutilizado; só a matriz muda. Definida por crm/_lib.php ANTES do
           agro_header. Sem override, comportamento idêntico ao de sempre. */
        if (!empty($GLOBALS['BIOS_MENU_OVERRIDE'])) {
            return $GLOBALS['BIOS_MENU_OVERRIDE'];
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // Helper local para micro placeholder (rota gerada pelo roteador).
        $m = static fn(string $slug, string $label): array => ['slug' => $slug, 'label' => $label];

        $cache = [
            [
                /* C-24 (Ivanildo 19/07): label "Relatórios" — slug/permissões intactos */
                'slug' => 'dashboard', 'label' => 'Relatórios', 'icone' => 'dashboard',
                'permbase' => 'dashboard', 'perm' => 'dashboard.ver',
                'micros' => [
                    $m('dashboard_executivo', 'Dashboard Executivo'),
                    $m('dashboard_financeiro', 'Dashboard Financeiro'),
                    $m('dashboard_operacional', 'Dashboard Operacional'),
                    /* indicadores_alertas FORA do menu a pedido do usuário (20/07)
                       — oculto p/ guard/rota da URL continuarem válidos (a fila de
                       alertas segue alcançável por link e pelos dashboards). */
                    ['slug' => 'indicadores_alertas', 'label' => 'Indicadores e Alertas', 'oculto' => true],
                    /* C-24 (fusão, decisão A0 19/07): micros do macro 'relatorios'
                       exibidos AQUI (launchers cross-módulo, padrão Planejamento):
                       perm/rota/view explícitos = slugs e permissões intactos
                       (relatorios.*). O macro 'relatorios' segue na matriz com os
                       micros 'oculto' para o guard/plano/rotas continuarem valendo. */
                    ['slug' => 'relatorios_operacionais',    'label' => 'Relatórios Operacionais',    'rota' => '/relatorios/relatorios_operacionais',    'view' => 'relatorios_relatorios_operacionais',    'perm' => 'relatorios.relatorios_operacionais.ver'],
                    ['slug' => 'relatorios_financeiros',     'label' => 'Relatórios Financeiros',     'rota' => '/relatorios/relatorios_financeiros',     'view' => 'relatorios_relatorios_financeiros',     'perm' => 'relatorios.relatorios_financeiros.ver'],
                    ['slug' => 'relatorios_safra',           'label' => 'Relatórios de Safra',        'rota' => '/relatorios/relatorios_safra',           'view' => 'relatorios_relatorios_safra',           'perm' => 'relatorios.relatorios_safra.ver'],
                    /* F-05/F-06: painel de integridade FORA do menu a pedido do
                       usuário (19/07) — acesso pelo botão em Relatórios de Safra;
                       micro oculto no macro relatorios segue p/ guard/rotas. */
                    ['slug' => 'relatorios_estoque',         'label' => 'Relatórios de Estoque',      'rota' => '/relatorios/relatorios_estoque',         'view' => 'relatorios_relatorios_estoque',         'perm' => 'relatorios.relatorios_estoque.ver'],
                    ['slug' => 'relatorios_compras',         'label' => 'Relatórios de Compras',      'rota' => '/relatorios/relatorios_compras',         'view' => 'relatorios_relatorios_compras',         'perm' => 'relatorios.relatorios_compras.ver'],
                    ['slug' => 'relatorios_colheita',        'label' => 'Relatórios de Colheita',     'rota' => '/relatorios/relatorios_colheita',        'view' => 'relatorios_relatorios_colheita',        'perm' => 'relatorios.relatorios_colheita.ver'],
                    ['slug' => 'relatorios_tecnicos',        'label' => 'Relatórios Técnicos',        'rota' => '/relatorios/relatorios_tecnicos',        'view' => 'relatorios_relatorios_tecnicos',        'perm' => 'relatorios.relatorios_tecnicos.ver'],
                    /* #42 (re-home 21/07): Resultado da Safra e Classificação da Produção
                       exibidos AQUI (launchers cross-módulo — mesmo padrão C-24). Removidos
                       dos macros de origem (Custos e Safra / Faturamento) para não colidir
                       no resolve_view. perm/rota/view explícitos = slug e permissão intactos
                       ('custos.*'/'comercial.*'), sem re-seed. */
                    ['slug' => 'resultado_safra',            'label' => 'Resultado da Safra',         'rota' => '/custeio/resultado_safra',               'view' => 'custos_resultado_safra',                'perm' => 'custos.resultado_safra.ver'],
                    ['slug' => 'classificacao_producao',     'label' => 'Classificação da Produção',  'rota' => '/comercial/classificacao_producao',      'view' => 'comercial_classificacao_producao',      'perm' => 'comercial.classificacao_producao.ver'],
                    /* indicadores_estrategicos e exportacoes FORA do menu a pedido do
                       usuário (19/07) — exportações virou MODAL nas telas de relatório
                       (_exportacoes_modal.php); micros ocultos do macro relatorios
                       seguem p/ guard/rota das URLs diretas. */
                    /* Cadastros/Apontamentos NÃO ficam aqui como itens de submenu:
                       viraram MACROS de topo (agregação abaixo, gerada da matriz).
                       PORÉM os micros abaixo PRECISAM existir na matriz para que o
                       bios_guard das telas de dashboard resolva a permissão real
                       (dashboard.{slug}.ver) em vez de cair no '__inexistente__' que
                       negava 403 a todo perfil sem wildcard (bug B1 da auditoria
                       Go-Live 17/07). São 'oculto' => somem da sidebar, mas a rota
                       fica acessível e a permissão vira grantável no catálogo.
                       Grants já existentes em role_permissions (verificado no banco). */
                    ['slug' => 'visao_geral',     'label' => 'Visão Geral',      'rota' => '/dashboard',                  'oculto' => true],
                    ['slug' => 'cadastros',       'label' => 'Cadastros',        'rota' => '/dashboard/cadastros',        'oculto' => true],
                    ['slug' => 'apontamentos',    'label' => 'Apontamentos',     'rota' => '/dashboard/apontamentos',     'oculto' => true],
                    ['slug' => 'comercializacao', 'label' => 'Comercialização',  'rota' => '/dashboard/comercializacao',  'oculto' => true],
                ],
            ],
            /* C-15: macro PLANEJAMENTO — quem planeja (RT/gestor) é
               diferente de quem aponta (encarregado/mão de obra). Launchers para as
               TELAS EXISTENTES (rota+perm de origem, sem tela nova); o macro em si
               gate por 'planejamento.ver' (slug novo — conceder só a dono/RT na
               matriz de perfis C-25; quem só aponta NÃO recebe e não vê o menu).
               A atividade planejada continua chegando ao apontamento apenas para
               finalização (fluxo OS→apontamento já existente). */
            [
                'slug' => 'planejamento', 'label' => 'Planejamento', 'icone' => 'planejamento',
                'permbase' => 'planejamento', 'perm' => 'planejamento.ver',
                /* R12-B2 (19/07): gate da ÁREA — os micros são launchers cross-módulo
                   (perms de agro/mip/irrigacao/maquinas que o encarregado tem para
                   APONTAR), então "1 micro visível" vazava o macro. Com 'gate_perm',
                   o macro só aparece p/ quem tem planejamento.ver (dono/rt_gerente,
                   seed C-25). Acesso direto às telas segue pelo bios_guard (perm real
                   de cada tela) — apontamento não regride. */
                'gate_perm' => true,
                'micros' => [
                    ['slug' => 'planejar_atividades', 'label' => 'Tratos Culturais (planejar)', 'rota' => '/agro/atividades?_nav=planejamento', 'perm' => 'agro.planejamento_atividades.ver', 'oculto' => true], // 20/07: gestor — o fluxo de campo parte do APONTAMENTO INICIADO, não do planejamento (tela/rota preservadas)
                    ['slug' => 'ordens_servico', 'label' => 'Ordens de Serviço', 'rota' => '/agro/ordens_servico?_nav=planejamento', 'perm' => 'agro.ordens_servico.ver'],
                    ['slug' => 'apontamentos_campo', 'label' => 'Apontamentos de Campo', 'rota' => '/agro/apontamentos', 'view' => 'agricola_apontamentos_campo', 'perm' => 'agro.apontamentos_campo.ver'], // V-08: movido de Gestão Agrícola; perm fixada p/ preservar a visibilidade (permbase deste macro é 'planejamento'); view mantida p/ realce correto
                    ['slug' => 'pulverizacao_df', 'label' => 'Pulverização / Fertirrigação (DF/IF)', 'rota' => '/mip/aplicacoes?_nav=planejamento', 'perm' => 'mip.aplicacoes_defensivos.ver'], // W-06 (Wallace 21/07): visibilidade da fertirrigação no Planejamento
                    ['slug' => 'planejamento_irrigacao', 'label' => 'Irrigação (planejamento)', 'rota' => '/irrigacao/planejamento_irrigacao?_nav=planejamento', 'perm' => 'irrigacao.planejamento_irrigacao.ver'],
                    ['slug' => 'manutencao_preventiva', 'label' => 'Máquinas (preventivas)', 'rota' => '/maquinas/manutencao?_nav=planejamento', 'perm' => 'maquinas.manutencao_preventiva.ver'],
                ],
            ],
            [
                'slug' => 'agricola', 'label' => 'Gestão Agrícola', 'icone' => 'agricola',
                'permbase' => 'agro', 'perm' => 'agro.ver',
                'micros' => [
                    /* A4-11: operação no topo → análise → cadastro */
                    /* V-08: os itens de PLANEJAMENTO saíram de Gestão Agrícola
                       para o macro Planejamento. 'apontamentos_campo' foi MOVIDO para lá (slug/
                       rota/view preservados, perm fixada). 'planejamento_atividades' e
                       'ordens_servico' já existiam no Planejamento (planejar_atividades /
                       ordens_servico) e eram apenas stubs ocultos aqui → removidos p/ não
                       duplicar. Gestão Agrícola mantém mapa/colheita/romaneio/produtividade/
                       safra + cadastros. */
                    /* Pulverização (aplicacoes_defensivos) retirada de Gestão Agrícola:
                       fica SÓ em MIP e MID. Isso tambem corrige o realce do menu — o atalho
                       aqui carregava o mesmo view 'mip_aplicacoes_defensivos' do item do MIP e,
                       vindo antes no cache, fazia a tela do MIP destacar Gestão Agrícola. */
                    $m('mapa_fazenda', 'Mapa da Fazenda'),
                    ['slug' => 'colheita', 'label' => 'Colheita', 'rota' => '/colheita/index', 'view' => 'colheita'],
                    $m('romaneios_colheita', 'Romaneio de Campo (colheita)'), // NAV-1 cluster 3: mata homônimo
                    ['slug' => 'clima', 'label' => 'Clima e Chuvas', 'oculto' => true], // ONDA1/P-? oculto do menu; tela acessível
                    ['slug' => 'calculadora', 'label' => 'Calculadora de Diárias', 'rota' => '/agro/calculadora', 'view' => 'agricola_calculadora', 'oculto' => true],
                    $m('produtividade', 'Produtividade'),
                    /* cadastro/estrutura */
                    ['slug' => 'safras', 'label' => 'Safras', 'rota' => '/safras/index', 'view' => 'safras'],
                    /* Onda 5 (poda→safra): abertura formal de safra + confirmação de poda por válvula
                       (dia 0 = último apontamento de poda). Perm = agro.safra.abrir (gestor/admin por wildcard). */
                    ['slug' => 'abertura_safra', 'label' => 'Abertura de Safra', 'rota' => '/agro/abertura_safra', 'view' => 'agricola_abertura_safra', 'perm' => 'agro.safra.abrir', 'oculto' => true], // retirado do menu 22/07: o fluxo agora e' poda->safra (o modal abre a safra e leva p/ Safras); tela/rota/handler preservados
                    ['slug' => 'fazendas', 'label' => 'Fazendas', 'rota' => '/fazendas/index', 'view' => 'fazendas'],
                    ['slug' => 'areas_produtivas', 'label' => 'Áreas Produtivas', 'oculto' => true], // ONDA0/C6: cliente pediu retirar
                    ['slug' => 'talhoes', 'label' => 'Talhões', 'oculto' => true], // ONDA1/P-120 duplicação: unificado em "Válvulas" (abaixo)
                    ['slug' => 'valvulas', 'label' => 'Válvulas', 'rota' => '/agro/valvulas', 'view' => 'agricola_valvulas'],
                    $m('culturas', 'Culturas'),
                    ['slug' => 'variedades', 'label' => 'Variedades', 'rota' => '/agro/variedades', 'view' => 'agricola_variedades'],
                    /* A0: reusa permissão de variedades por ora; slug próprio = decisão futura */
                    ['slug' => 'porta_enxertos', 'label' => 'Porta-enxertos', 'rota' => '/agro/porta_enxertos', 'view' => 'agricola_porta_enxertos', 'perm' => 'agro.variedades.ver'],
                    ['slug' => 'tipos_atividade', 'label' => 'Tipos de Atividade', 'rota' => '/agro/tipos_atividade', 'view' => 'agricola_tipos_atividade'],
                    /* C-01/A-06: tela dos parâmetros da calculadora de MO
                       (P-91). Reusa a perm de tipos_atividade (parâmetro é atributo do
                       tipo — precedente porta_enxertos; sem re-seed). */
                    ['slug' => 'parametros_rendimento', 'label' => 'Parâmetros de Rendimento (MO)', 'rota' => '/agro/parametros_rendimento', 'view' => 'agricola_parametros_rendimento', 'perm' => 'agro.tipos_atividade.ver'],
                ],
            ],
            [
                'slug' => 'estoque', 'label' => 'Estoque e Insumos', 'icone' => 'estoque',
                'permbase' => 'estoque', 'perm' => 'estoque.ver',
                'micros' => [
                    ['slug' => 'produtos_insumos', 'label' => 'Produtos e Insumos', 'rota' => '/estoque/index', 'view' => 'estoque'],
                    /* C-07: carga inicial em massa (qtd+custo → entradas
                       via service). Reusa a perm de produtos_insumos — sem re-seed. */
                    /* C-07 v2: Saldo Inicial abre o modal na tela de Produtos (?saldo=1). Volta
                       como 2º item (Produtos e Insumos é o 1º/landing), então NÃO abre sozinho. */
                    ['slug' => 'implantacao_saldo', 'label' => 'Saldo Inicial', 'rota' => '/estoque/produtos?saldo=1', 'view' => 'estoque', 'perm' => 'estoque.produtos_insumos.ver'],
                    $m('grupos_subgrupos', 'Grupos e Subgrupos'),
                    $m('almoxarifados', 'Almoxarifados'),
                    /* NAV-1 cluster 7: Entradas/Saídas são filtros (?tipo=) da tela de Movimentações → ocultas do menu */
                    ['slug' => 'entradas', 'label' => 'Entradas', 'oculto' => true],
                    ['slug' => 'saidas', 'label' => 'Saídas', 'oculto' => true],
                    $m('transferencias', 'Transferências'),
                    $m('lotes_validade', 'Lotes e Rastreabilidade'), // NAV-1 cluster 8 (slug/rota intactos)
                    ['slug' => 'inventario', 'label' => 'Inventário', 'oculto' => true], // pedido usuário 09/07: retirar do menu (ocultar ≠ excluir; tela/rota preservadas)
                    $m('estoque_critico', 'Alertas de Estoque'), // NAV-1 cluster 9: nome × conteúdo (slug estoque_critico mantido)
                    $m('historico_movimentacoes', 'Movimentações de Estoque'), // NAV-1 cluster 7 (slug mantido = alçada do estorno)
                    $m('agrofit', 'Catálogo Agrofit'), // A0 (DB-36) — tela A2-F2-13
                    $m('auditoria', 'Auditoria de Estoque'), // A0-16 (EST-024) — tela A2-F2-17
                ],
            ],
            [
                'slug' => 'compras', 'label' => 'Compras', 'icone' => 'compras',
                'permbase' => 'compras', 'perm' => 'compras.ver',
                'micros' => [
                    $m('fornecedores', 'Fornecedores'),
                    $m('solicitacoes_compra', 'Solicitações de Compra'),
                    $m('cotacoes', 'Cotações'), // micro próprio desde o A0-04 (P-26)
                    ['slug' => 'pedidos_compra', 'label' => 'Pedidos de Compra', 'rota' => '/compras/pedidos', 'view' => 'compras_pedidos_compra'], // B5: era /compras/index.php (protótipo mock) → tela real
                    $m('aprovacoes', 'Aprovações'),
                    $m('recebimentos', 'Recebimentos'),
                    $m('historico_compras', 'Histórico de Compras'),
                    $m('compras_fora_orcamento', 'Compras Fora do Orçamento'),
                ],
            ],
            [
                'slug' => 'custos', 'label' => 'Custos e Safra', 'icone' => 'custos',
                'permbase' => 'custeio', 'perm' => 'custeio.ver',
                'micros' => [
                    $m('orcamento_safra', 'Orçamento de Safra'),
                    $m('custo_realizado', 'Custo Realizado'),
                    $m('realizado_planejado', 'Realizado vs Planejado'),
                    /* NAV-1 cluster 2 (P-119): os 5 recortes viram 1 dashboard "Custos da Produção" com abas.
                       Reusa o slug custo_talhao (guard/perm intactos — sem re-seed); recortes ocultos. */
                    ['slug' => 'custo_talhao', 'label' => 'Custos da Produção', 'rota' => '/custeio/dashboard_custos', 'view' => 'custos_dashboard'],
                    ['slug' => 'custo_fazenda', 'label' => 'Custo por Fazenda', 'oculto' => true],   // aba do dashboard
                    ['slug' => 'custo_cultura', 'label' => 'Custo por Cultura', 'oculto' => true],   // aba do dashboard
                    ['slug' => 'custo_hectare', 'label' => 'Custo por Hectare', 'oculto' => true],   // aba do dashboard
                    ['slug' => 'custo_categoria', 'label' => 'Custo por Categoria', 'oculto' => true], // aba do dashboard
                    $m('rateios', 'Rateios'),
                    $m('fechamento_safra', 'Fechamento de Safra'),
                    /* resultado_safra movido para o menu Relatorios (#42) */
                    $m('metas_safra', 'Metas da Safra'), // A0-10 — tela A3-T16
                    ['slug' => 'comparativo_safras', 'label' => 'Comparativo entre Safras', 'oculto' => true], // retirar (pedido 21/07 noite) — tela/rota preservadas
                    $m('metodologias', 'Metodologias de Custo'), // A0-13 motor de custo — tela A3-T24
                    $m('parametros_cultura', 'Parâmetros de Cultura'), // A0-13 — tela A3-T25
                    ['slug' => 'orcamento_producao', 'label' => 'Orçamento de Produção', 'oculto' => true], // ocultado 21/07: coexistia com "Orçamento de Safra" (legado que alimenta dashboards) e o realizado derivado do custeio (F2) ainda nao esta conectado — rota/perm preservadas
                ],
            ],
            [
                'slug' => 'nutricao', 'label' => 'Nutrição Agrícola', 'icone' => 'nutricao',
                'permbase' => 'nutricao', 'perm' => 'nutricao.ver',
                'micros' => [
                    /* A4-11: operação no topo → análise → cadastro */
                    $m('aplicacoes_nutricionais', 'Aplicação de Insumos'), // C-18: nutricional = pulverização de fertilizante (tela lê o núcleo agro_aplicacoes)
                    $m('analise_solo', 'Análise de Solo'),
                    $m('analise_foliar', 'Análise Foliar'),
                    ['slug' => 'importar_laudo', 'label' => 'Importar Laudo (IA)', 'rota' => '/nutricao/importar_laudo', 'view' => 'nutricao_importar_laudo', 'oculto' => true], // ONDA1: some do menu; passa a ser botão dentro de Análise de Solo/Foliar (ONDA6)
                    $m('painel_nutrientes', 'Painel de Nutrientes'),
                    $m('historico_nutricional', 'Histórico Nutricional'),
                    $m('comparativo_safra', 'Comparativo por Safra'),
                    /* cadastro */
                    $m('nutrientes', 'Nutrientes'),
                    $m('faixas_nutricionais', 'Faixas Nutricionais'),
                ],
            ],
            [
                'slug' => 'mip', 'label' => 'MIP e MID', 'icone' => 'mip',
                'permbase' => 'mip', 'perm' => 'mip.ver',
                'micros' => [
                    /* A4-11: operação no topo → análise → cadastro */
                    ['slug' => 'monitoramentos', 'label' => 'Monitoramentos', 'rota' => '/mip/monitoramento', 'view' => 'mip_monitoramentos'], // B5: era /mip/index.php (protótipo mock) → tela real
                    /* X-07: Pulverização e Preparo de Calda SAEM da visão
                       do MIP (o monitor não precisa) — rota/slug/permissão preservados; a
                       Pulverização/Fertirrigação segue acessível pelo menu Planejamento. */
                    ['slug' => 'aplicacoes_defensivos', 'label' => 'Pulverização', 'oculto' => true],
                    ['slug' => 'preparo_calda', 'label' => 'Preparo de Calda', 'rota' => '/mip/preparo_calda', 'view' => 'mip_preparo_calda', 'perm' => 'mip.aplicacoes_defensivos.ver', 'oculto' => true],
                    /* C-30/31/33/40: ocultos — tela/rota/slug preservados.
                       Receituários = sem função no fluxo atual; Alertas Fitoss. volta
                       como dashboard no futuro; Histórico por Talhão = consulta pela
                       válvula atende; Pontos de Amostragem = removido. */
                    ['slug' => 'receituarios', 'label' => 'Receituários', 'oculto' => true],
                    ['slug' => 'alertas_fitossanitarios', 'label' => 'Alertas Fitossanitários', 'oculto' => true],
                    /* nivel_infestacao FORA do menu (20/07): conteudo migrado p/ a
                       tabela Nivel de Infestacao dentro de Relatorios MIP; rota
                       oculta segue valida por URL/guard. */
                    ['slug' => 'nivel_infestacao', 'label' => 'Situação de Infestação', 'oculto' => true],
                    ['slug' => 'historico_talhao', 'label' => 'Histórico por Talhão', 'oculto' => true],
                    $m('auditoria_aplicacoes', 'Auditoria de Aplicações (DF/IF)'), // C-34: mantém (fazendas grandes)
                    $m('relatorios_mip', 'Relatórios MIP'),
                    /* cadastro */
                    /* NAV-1 cluster 5: Pragas/Doenças = recortes por tipo da MESMA base (mip_alvos);
                       "Alvos de Controle" é a tela completa (filtro por tipo). Recortes ocultos (slugs/rotas intactos). */
                    ['slug' => 'pragas', 'label' => 'Pragas', 'oculto' => true],
                    ['slug' => 'doencas', 'label' => 'Doenças', 'oculto' => true],
                    $m('alvos_controle', 'Alvos de Controle'), // C-39: só aqui no MIP (saiu de Cadastros)
                    ['slug' => 'pontos_amostragem', 'label' => 'Pontos de Amostragem', 'oculto' => true],
                ],
            ],
            [
                'slug' => 'irrigacao', 'label' => 'Irrigação', 'icone' => 'irrigacao',
                'permbase' => 'irrigacao', 'perm' => 'irrigacao.ver',
                'micros' => [
                    /* A4-11: operação no topo → análise → cadastro */
                    $m('apontamentos_irrigacao', 'Apontamentos de Irrigação'),
                    $m('planejamento_irrigacao', 'Planejamento de Irrigação'),
                    $m('planejado_realizado', 'Planejado vs Realizado'),
                    $m('fertirrigacao', 'Fertirrigações (IF) — consulta'), // NAV-1 cluster 6: recorte de leitura; "+ Nova IF" → mip/aplicacoes (A1 prefill)
                    /* C-42: 3 telas viram UM item — a tela abre com
                       abas Água|Energia|Custo (telas/slugs preservados, ocultos) */
                    $m('consumo_agua', 'Consumos e Custo de Irrigação'),
                    ['slug' => 'consumo_energia', 'label' => 'Consumo de Energia', 'oculto' => true],
                    ['slug' => 'custo_irrigacao', 'label' => 'Custo de Irrigação', 'oculto' => true],
                    /* cadastro — C-42: Válvulas (Irrigação) e Pivôs saem
                       do menu (tela/rota/slug preservados); Bombas fica (vazão + tarifas C-21) */
                    ['slug' => 'setores_irrigacao', 'label' => 'Válvulas (Irrigação)', 'oculto' => true],
                    ['slug' => 'pivos', 'label' => 'Pivôs', 'oculto' => true],
                    $m('bombas', 'Bombas'), // A0-07 (DB-31/IF) — tela real na tarefa A1-28
                ],
            ],
            [
                'slug' => 'maquinas', 'label' => 'Máquinas e Frota', 'icone' => 'maquinas',
                'permbase' => 'maquinas', 'perm' => 'maquinas.ver',
                'micros' => [
                    ['slug' => 'maquinas', 'label' => 'Máquinas', 'rota' => '/maquinas/index', 'view' => 'maquinas'],
                    $m('veiculos', 'Veículos'),
                    $m('implementos', 'Implementos'),
                    ['slug' => 'horimetro', 'label' => 'Horímetro', 'oculto' => true], // retirado do menu (pedido 22/07; ocultar ≠ excluir — rota/guard preservados)
                    ['slug' => 'odometro', 'label' => 'Odômetro', 'oculto' => true],   // retirado do menu
                    $m('abastecimentos', 'Abastecimentos'),
                    $m('manutencao_preventiva', 'Manutenções (OS)'), // NAV-1 cluster 10: faz prev+corr (slug mantido; cobre planos)
                    $m('manutencao_corretiva', 'Manutenção Corretiva'),
                    $m('pecas_servicos', 'Peças e Serviços'),
                    $m('custo_operacional', 'Custo Operacional'),
                    $m('disponibilidade_frota', 'Disponibilidade da Frota'),
                ],
            ],
            [
                'slug' => 'pessoas', 'label' => 'Pessoas e Equipes', 'icone' => 'pessoas',
                'permbase' => 'pessoas', 'perm' => 'pessoas.ver',
                'micros' => [
                    $m('equipes', 'Equipes'),
                    $m('operadores', 'Colaboradores'),
                    $m('treinamentos', 'Treinamentos (NR-31)'), // A0-11 (DB-37) — tela A3-T19
                    $m('epis', 'EPIs'), // A0-11 (DB-38) — tela A3-T20
                    ['slug' => 'terceirizados', 'label' => 'Terceirizados', 'rota' => '/pessoas/terceirizados', 'view' => 'pessoas_terceirizados'],
                    ['slug' => 'premiacao', 'label' => 'Regras de Premiação', 'rota' => '/pessoas/premiacao', 'view' => 'pessoas_premiacao'],
                    ['slug' => 'encargos', 'label' => 'Encargos CLT', 'rota' => '/pessoas/encargos', 'view' => 'pessoas_encargos'],
                    ['slug' => 'folha', 'label' => 'Folha Simplificada', 'rota' => '/pessoas/folha', 'view' => 'pessoas_folha'],
                    $m('responsaveis_tecnicos', 'Responsáveis Técnicos'),
                    /* C-22: duplicava Apontamentos de Campo — virou redirect; o recorte
                       por pessoa é o filtro "pessoa" da tela unificada */
                    ['slug' => 'apontamento_mao_obra', 'label' => 'Apontamento de Mão de Obra', 'oculto' => true],
                    $m('custo_mao_obra', 'Custo de Mão de Obra'),
                    $m('historico_colaborador', 'Histórico por Colaborador'),
                ],
            ],
            [
                'slug' => 'comercial', 'label' => 'Faturamento', 'icone' => 'comercial',
                'permbase' => 'comercial', 'perm' => 'comercial.ver',
                'micros' => [
                    $m('estoque_producao', 'Estoque de Produção'),
                    $m('armazenagem_propria', 'Armazenagem Própria'),
                    $m('armazenagem_terceiros', 'Armazenagem de Terceiros'),
                    /* classificacao_producao movido para o menu Relatorios (#42) */
                    $m('compradores', 'Clientes'),
                    $m('vendas', 'Vendas'),
                    ['slug' => 'contratos_venda', 'label' => 'Contratos de Venda', 'oculto' => true], // retirar (21/07 noite) — tela/rota preservadas
                    $m('romaneios_saida', 'Romaneio de Embarque (venda)'), // NAV-1 cluster 3: mata homônimo
                    ['slug' => 'logistica_frete', 'label' => 'Logística e Frete', 'oculto' => true], // retirar (21/07 noite)
                    ['slug' => 'faturamento_comprador', 'label' => 'Faturamento por Cliente', 'oculto' => true], // retirar (21/07 noite)
                    ['slug' => 'faturamento_cultura', 'label' => 'Faturamento por Cultura', 'oculto' => true], // retirar (21/07 noite)
                ],
            ],
            [
                'slug' => 'financeiro', 'label' => 'Financeiro', 'icone' => 'financeiro',
                'permbase' => 'financeiro', 'perm' => 'financeiro.ver',
                'micros' => [
                    ['slug' => 'contas_pagar', 'label' => 'Contas a Pagar', 'rota' => '/financeiro/index', 'view' => 'financeiro'],
                    $m('contas_receber', 'Contas a Receber'),
                    $m('despesas', 'Despesas (pagas)'),        // NAV-1 cluster 12: recorte pago do razão (leitura)
                    $m('recebimentos', 'Recebimentos (recebidos)'), // NAV-1 cluster 12: recorte recebido do razão
                    $m('plano_contas', 'Plano de Contas'),
                    $m('centros_custo', 'Centros de Custo'),
                    $m('contas_bancarias', 'Contas Bancárias'),
                    $m('conciliacao_bancaria', 'Conciliação Bancária'),
                    $m('fluxo_caixa', 'Fluxo de Caixa'),
                    $m('dre_agro', 'DRE Agro'),
                    $m('relatorios_financeiros', 'Relatórios Financeiros'),
                    /* Discoverability (auditoria 19/07 2x "não localizado"): o
                       verificador de hash-chain (T32) existia só como botão dentro
                       de Rel. Financeiros — agora é item de menu (perm reusada). */
                    ['slug' => 'verificador_razao', 'label' => 'Verificador do Razão',
                     'rota' => '/financeiro/verificador_razao',
                     'perm' => 'financeiro.relatorios_financeiros.ver'],
                ],
            ],
            [
                'slug' => 'fiscal', 'label' => 'Fiscal', 'icone' => 'fiscal',
                'permbase' => 'fiscal', 'perm' => 'fiscal.ver',
                'micros' => [
                    ['slug' => 'documentos_fiscais', 'label' => 'Documentos Fiscais', 'rota' => '/fiscal/documentos', 'view' => 'fiscal_documentos_fiscais'], // B5: era /fiscal/index.php (protótipo mock c/ NF-e falsa) → tela real
                    $m('importacao_nfe', 'Importação de NF-e'),
                    $m('importacao_nfse', 'Importação de NFS-e'),
                    $m('upload_xml', 'Upload de XML'),
                    ['slug' => 'upload_pdf', 'label' => 'Upload de PDF', 'oculto' => true], // retirar (21/07 noite)
                    $m('conciliacao_fiscal', 'Conciliação Fiscal'),
                    $m('livro_caixa_produtor', 'Livro Caixa Produtor Rural'),
                    $m('acesso_contador', 'Acesso do Contador'),
                    $m('emissao_nfe', 'Emissão de NF-e'),
                    ['slug' => 'emissao_mdfe', 'label' => 'Emissão de MDF-e', 'oculto' => true], // retirar (21/07 noite)
                    ['slug' => 'historico_fiscal', 'label' => 'Histórico Fiscal', 'oculto' => true], // retirar (21/07 noite)
                    ['slug' => 'relatorios_fiscais', 'label' => 'Relatórios Fiscais', 'oculto' => true], // retirar (21/07 noite)
                ],
            ],
            [
                'slug' => 'patrimonio', 'label' => 'Patrimônio e Ativos', 'icone' => 'patrimonio',
                'permbase' => 'patrimonio', 'perm' => 'patrimonio.ver',
                'micros' => [
                    $m('terras', 'Terras'),
                    ['slug' => 'benfeitorias', 'label' => 'Benfeitorias', 'oculto' => true], // retirar (21/07 noite)
                    ['slug' => 'maquinas_ativos', 'label' => 'Máquinas como Ativos', 'rota' => '/patrimonio/ativos', 'view' => 'patrimonio_maquinas_ativos'], // B5: era /patrimonio/index.php (protótipo mock) → tela real
                    $m('veiculos_ativos', 'Veículos como Ativos'),
                    $m('equipamentos', 'Equipamentos'),
                    ['slug' => 'valor_patrimonial', 'label' => 'Valor Patrimonial', 'oculto' => true], // retirar (21/07 noite)
                    ['slug' => 'localizacao_ativos', 'label' => 'Localização dos Ativos', 'oculto' => true], // retirar (21/07 noite)
                    $m('depreciacao_gerencial', 'Depreciação Gerencial'),
                    $m('relatorios_patrimoniais', 'Relatórios Patrimoniais'),
                ],
            ],
            [
                /* C-24 (fusão 19/07): macro some da sidebar (micros 'oculto') —
                   exibição migrou para o macro 'dashboard' (label "Relatórios").
                   Fica na matriz para guard/plano/rotas/catálogo de permissões. */
                'slug' => 'relatorios', 'label' => 'Relatórios', 'icone' => 'relatorios',
                'permbase' => 'relatorios', 'perm' => 'relatorios.ver',
                'micros' => [
                    ['slug' => 'relatorios_operacionais',  'label' => 'Relatórios Operacionais',  'oculto' => true],
                    ['slug' => 'relatorios_financeiros',   'label' => 'Relatórios Financeiros',   'oculto' => true],
                    ['slug' => 'relatorios_safra',         'label' => 'Relatórios de Safra',      'oculto' => true],
                    ['slug' => 'integridade_producao',     'label' => 'Integridade Produção→Estoque', 'oculto' => true], /* slug próprio 19/07 */
                    ['slug' => 'relatorios_estoque',       'label' => 'Relatórios de Estoque',    'oculto' => true],
                    ['slug' => 'relatorios_compras',       'label' => 'Relatórios de Compras',    'oculto' => true],
                    ['slug' => 'relatorios_colheita',      'label' => 'Relatórios de Colheita',   'oculto' => true],
                    ['slug' => 'relatorios_tecnicos',      'label' => 'Relatórios Técnicos',      'oculto' => true],
                    ['slug' => 'indicadores_estrategicos', 'label' => 'Indicadores Estratégicos', 'oculto' => true],
                    ['slug' => 'exportacoes',              'label' => 'Exportações',              'oculto' => true],
                ],
            ],
            [
                'slug' => 'configuracoes', 'label' => 'Configurações', 'icone' => 'configuracoes',
                'permbase' => 'configuracoes', 'perm' => 'configuracoes.ver',
                'micros' => [
                    $m('empresa_fazenda', 'Empresa / Fazenda'),
                    $m('usuarios', 'Usuários'),
                    $m('perfis_acesso', 'Perfis de Acesso'),
                    $m('permissoes', 'Permissões'),
                    $m('parametros_sistema', 'Parâmetros do Sistema'),
                    $m('unidades_medida', 'Unidades de Medida'),
                    ['slug' => 'categorias', 'label' => 'Categorias', 'oculto' => true], // retirar do menu (pedido 22/07; ocultar ≠ excluir)
                    $m('integracoes', 'Integrações'),
                    $m('auditoria', 'Auditoria'),
                    ['slug' => 'logs_sistema', 'label' => 'Logs do Sistema', 'oculto' => true], // retirar/ocultar (21/07 noite)
                ],
            ],
        ];

        /* Rotas reais dos micro modulos mockados. Mantem o menu sem depender
           do roteador generico agro.php para telas de prototipo. */
        $rotasReais = array (
  'agricola.apontamentos_campo' => 
  array (
    'rota' => '/agro/apontamentos',
    'view' => 'agricola_apontamentos_campo',
  ),
  'agricola.areas_produtivas' => 
  array (
    'rota' => '/agro/areas_produtivas',
    'view' => 'agricola_areas_produtivas',
  ),
  'agricola.culturas' => 
  array (
    'rota' => '/agro/culturas',
    'view' => 'agricola_culturas',
  ),
  'agricola.fazendas' =>
  array (
    'rota' => '/fazendas/index',
    'view' => 'fazendas',
  ),
  'agricola.mapa_fazenda' => 
  array (
    'rota' => '/agro/mapa',
    'view' => 'agricola_mapa_fazenda',
  ),
  'agricola.ordens_servico' => 
  array (
    'rota' => '/agro/ordens_servico',
    'view' => 'agricola_ordens_servico',
  ),
  'agricola.planejamento_atividades' => 
  array (
    'rota' => '/agro/atividades',
    'view' => 'agricola_planejamento_atividades',
  ),
  'agricola.produtividade' => 
  array (
    'rota' => '/agro/produtividade',
    'view' => 'agricola_produtividade',
  ),
  'agricola.romaneios_colheita' => 
  array (
    'rota' => '/agro/romaneios_colheita',
    'view' => 'agricola_romaneios_colheita',
  ),
  'agricola.safras' =>
  array (
    'rota' => '/safras/index',
    'view' => 'safras',
  ),
  'agricola.talhoes' => 
  array (
    'rota' => '/agro/talhoes',
    'view' => 'agricola_talhoes',
  ),
  'comercial.armazenagem_propria' => 
  array (
    'rota' => '/comercial/armazenagem_propria',
    'view' => 'comercial_armazenagem_propria',
  ),
  'comercial.armazenagem_terceiros' => 
  array (
    'rota' => '/comercial/armazenagem_terceiros',
    'view' => 'comercial_armazenagem_terceiros',
  ),
  'comercial.classificacao_producao' => 
  array (
    'rota' => '/comercial/classificacao_producao',
    'view' => 'comercial_classificacao_producao',
  ),
  'comercial.compradores' => 
  array (
    'rota' => '/comercial/compradores',
    'view' => 'comercial_compradores',
  ),
  'comercial.contratos_venda' => 
  array (
    'rota' => '/comercial/contratos_venda',
    'view' => 'comercial_contratos_venda',
  ),
  'comercial.estoque_producao' => 
  array (
    'rota' => '/comercial/estoque',
    'view' => 'comercial_estoque_producao',
  ),
  'comercial.faturamento_comprador' => 
  array (
    'rota' => '/comercial/faturamento_comprador',
    'view' => 'comercial_faturamento_comprador',
  ),
  'comercial.faturamento_cultura' => 
  array (
    'rota' => '/comercial/faturamento_cultura',
    'view' => 'comercial_faturamento_cultura',
  ),
  'comercial.logistica_frete' => 
  array (
    'rota' => '/comercial/logistica_frete',
    'view' => 'comercial_logistica_frete',
  ),
  'comercial.romaneios_saida' => 
  array (
    'rota' => '/comercial/romaneios',
    'view' => 'comercial_romaneios_saida',
  ),
  'comercial.vendas' => 
  array (
    'rota' => '/comercial/vendas',
    'view' => 'comercial_vendas',
  ),
  'compras.aprovacoes' => 
  array (
    'rota' => '/compras/aprovacoes',
    'view' => 'compras_aprovacoes',
  ),
  'compras.compras_fora_orcamento' => 
  array (
    'rota' => '/compras/fora_orcamento',
    'view' => 'compras_compras_fora_orcamento',
  ),
  'compras.fornecedores' => 
  array (
    'rota' => '/compras/fornecedores',
    'view' => 'compras_fornecedores',
  ),
  'compras.historico_compras' => 
  array (
    'rota' => '/compras/historico_compras',
    'view' => 'compras_historico_compras',
  ),
  'compras.pedidos_compra' => 
  array (
    'rota' => '/compras/pedidos',
    'view' => 'compras_pedidos_compra',
  ),
  'compras.recebimentos' => 
  array (
    'rota' => '/compras/recebimentos',
    'view' => 'compras_recebimentos',
  ),
  'compras.solicitacoes_compra' => 
  array (
    'rota' => '/compras/solicitacoes',
    'view' => 'compras_solicitacoes_compra',
  ),
  'configuracoes.auditoria' => 
  array (
    'rota' => '/configuracoes/auditoria',
    'view' => 'configuracoes_auditoria',
  ),
  'configuracoes.categorias' => 
  array (
    'rota' => '/configuracoes/categorias',
    'view' => 'configuracoes_categorias',
  ),
  'configuracoes.empresa_fazenda' => 
  array (
    'rota' => '/configuracoes/empresa_fazenda',
    'view' => 'configuracoes_empresa_fazenda',
  ),
  'configuracoes.integracoes' => 
  array (
    'rota' => '/configuracoes/integracoes',
    'view' => 'configuracoes_integracoes',
  ),
  'configuracoes.logs_sistema' => 
  array (
    'rota' => '/configuracoes/logs_sistema',
    'view' => 'configuracoes_logs_sistema',
  ),
  'configuracoes.parametros_sistema' => 
  array (
    'rota' => '/configuracoes/parametros_sistema',
    'view' => 'configuracoes_parametros_sistema',
  ),
  'configuracoes.perfis_acesso' => 
  array (
    'rota' => '/configuracoes/perfis_acesso',
    'view' => 'configuracoes_perfis_acesso',
  ),
  'configuracoes.permissoes' => 
  array (
    'rota' => '/configuracoes/permissoes',
    'view' => 'configuracoes_permissoes',
  ),
  'configuracoes.unidades_medida' => 
  array (
    'rota' => '/configuracoes/unidades_medida',
    'view' => 'configuracoes_unidades_medida',
  ),
  'configuracoes.usuarios' => 
  array (
    'rota' => '/configuracoes/usuarios',
    'view' => 'configuracoes_usuarios',
  ),
  'custos.custo_categoria' => 
  array (
    'rota' => '/custeio/custo_categoria',
    'view' => 'custos_custo_categoria',
  ),
  'custos.custo_cultura' => 
  array (
    'rota' => '/custeio/custo_cultura',
    'view' => 'custos_custo_cultura',
  ),
  'custos.custo_fazenda' => 
  array (
    'rota' => '/custeio/custo_fazenda',
    'view' => 'custos_custo_fazenda',
  ),
  'custos.custo_hectare' => 
  array (
    'rota' => '/custeio/custo_hectare',
    'view' => 'custos_custo_hectare',
  ),
  'custos.custo_realizado' => 
  array (
    'rota' => '/custeio/custo_realizado',
    'view' => 'custos_custo_realizado',
  ),
  'custos.custo_talhao' => 
  array (
    'rota' => '/custeio/custos',
    'view' => 'custos_custo_talhao',
  ),
  'custos.fechamento_safra' => 
  array (
    'rota' => '/custeio/fechamento',
    'view' => 'custos_fechamento_safra',
  ),
  'custos.orcamento_safra' => 
  array (
    'rota' => '/custeio/orcamento',
    'view' => 'custos_orcamento_safra',
  ),
  'custos.rateios' => 
  array (
    'rota' => '/custeio/rateios',
    'view' => 'custos_rateios',
  ),
  'custos.realizado_planejado' => 
  array (
    'rota' => '/custeio/realizado',
    'view' => 'custos_realizado_planejado',
  ),
  'custos.resultado_safra' => 
  array (
    'rota' => '/custeio/resultado_safra',
    'view' => 'custos_resultado_safra',
  ),
  'dashboard.dashboard_executivo' => 
  array (
    'rota' => '/dashboard/dashboard_executivo',
    'view' => 'dashboard_dashboard_executivo',
  ),
  'dashboard.dashboard_financeiro' => 
  array (
    'rota' => '/dashboard/dashboard_financeiro',
    'view' => 'dashboard_dashboard_financeiro',
  ),
  'dashboard.dashboard_operacional' => 
  array (
    'rota' => '/dashboard/dashboard_operacional',
    'view' => 'dashboard_dashboard_operacional',
  ),
  'dashboard.indicadores_alertas' => 
  array (
    'rota' => '/dashboard/indicadores_alertas',
    'view' => 'dashboard_indicadores_alertas',
  ),
  'compras.cotacoes' =>
  array (
    'rota' => '/compras/cotacoes',
    'view' => 'compras_cotacoes',
  ),
  'pessoas.treinamentos' =>
  array (
    'rota' => '/pessoas/treinamentos',
    'view' => 'pessoas_treinamentos',
  ),
  'pessoas.epis' =>
  array (
    'rota' => '/pessoas/epis',
    'view' => 'pessoas_epis',
  ),
  'estoque.agrofit' =>
  array (
    'rota' => '/estoque/agrofit',
    'view' => 'estoque_agrofit',
  ),
  'custos.metas_safra' =>
  array (
    'rota' => '/custeio/metas',
    'view' => 'custos_metas_safra',
  ),  'custos.comparativo_safras' =>
  array (
    'rota' => '/custeio/comparativo_safras',
    'view' => 'custos_comparativo_safras',
  ),
  'custos.metodologias' =>
  array (
    'rota' => '/custeio/metodologias',
    'view' => 'custos_metodologias',
  ),
  'estoque.auditoria' =>
  array (
    'rota' => '/estoque/auditoria',
    'view' => 'estoque_auditoria',
  ),
  'custos.parametros_cultura' =>
  array (
    'rota' => '/custeio/parametros_cultura',
    'view' => 'custos_parametros_cultura',
  ),
  'custos.orcamento_producao' =>
  array (
    'rota' => '/custeio/orcamento_producao',
    'view' => 'custos_orcamento_producao',
  ),
  'irrigacao.bombas' =>
  array (
    'rota' => '/agro/bombas',
    'view' => 'irrigacao_bombas',
  ),
  'agricola.clima' =>
  array (
    'rota' => '/agro/clima',
    'view' => 'agricola_clima',
  ),
  'mip.auditoria_aplicacoes' =>
  array (
    'rota' => '/mip/auditoria_aplicacoes',
    'view' => 'mip_auditoria_aplicacoes',
  ),
  'mip.receituarios' =>
  array (
    'rota' => '/mip/receituarios',
    'view' => 'mip_receituarios',
  ),
  'estoque.almoxarifados' =>
  array (
    'rota' => '/estoque/almoxarifados',
    'view' => 'estoque_almoxarifados',
  ),
  'estoque.entradas' => 
  array (
    'rota' => '/estoque/entradas',
    'view' => 'estoque_entradas',
  ),
  'estoque.estoque_critico' => 
  array (
    'rota' => '/estoque/alertas',
    'view' => 'estoque_estoque_critico',
  ),
  'estoque.grupos_subgrupos' => 
  array (
    'rota' => '/estoque/grupos_subgrupos',
    'view' => 'estoque_grupos_subgrupos',
  ),
  'estoque.historico_movimentacoes' => 
  array (
    'rota' => '/estoque/movimentacoes',
    'view' => 'estoque_historico_movimentacoes',
  ),
  'estoque.inventario' => 
  array (
    'rota' => '/estoque/inventario',
    'view' => 'estoque_inventario',
  ),
  'estoque.lotes_validade' => 
  array (
    'rota' => '/estoque/lotes',
    'view' => 'estoque_lotes_validade',
  ),
  'estoque.produtos_insumos' => 
  array (
    'rota' => '/estoque/produtos',
    'view' => 'estoque_produtos_insumos',
  ),
  'estoque.saidas' => 
  array (
    'rota' => '/estoque/saidas',
    'view' => 'estoque_saidas',
  ),
  'estoque.transferencias' => 
  array (
    'rota' => '/estoque/transferencias',
    'view' => 'estoque_transferencias',
  ),
  'financeiro.centros_custo' => 
  array (
    'rota' => '/financeiro/centros_custo',
    'view' => 'financeiro_centros_custo',
  ),
  'financeiro.conciliacao_bancaria' => 
  array (
    'rota' => '/financeiro/conciliacao_bancaria',
    'view' => 'financeiro_conciliacao_bancaria',
  ),
  'financeiro.contas_bancarias' => 
  array (
    'rota' => '/financeiro/contas_bancarias',
    'view' => 'financeiro_contas_bancarias',
  ),
  'financeiro.contas_pagar' => 
  array (
    'rota' => '/financeiro/contas_pagar',
    'view' => 'financeiro_contas_pagar',
  ),
  'financeiro.contas_receber' => 
  array (
    'rota' => '/financeiro/contas_receber',
    'view' => 'financeiro_contas_receber',
  ),
  'financeiro.despesas' => 
  array (
    'rota' => '/financeiro/despesas',
    'view' => 'financeiro_despesas',
  ),
  'financeiro.dre_agro' => 
  array (
    'rota' => '/financeiro/dre_agro',
    'view' => 'financeiro_dre_agro',
  ),
  'financeiro.fluxo_caixa' => 
  array (
    'rota' => '/financeiro/fluxo_caixa',
    'view' => 'financeiro_fluxo_caixa',
  ),
  'financeiro.plano_contas' => 
  array (
    'rota' => '/financeiro/plano_contas',
    'view' => 'financeiro_plano_contas',
  ),
  'financeiro.recebimentos' => 
  array (
    'rota' => '/financeiro/recebimentos',
    'view' => 'financeiro_recebimentos',
  ),
  'financeiro.relatorios_financeiros' => 
  array (
    'rota' => '/financeiro/relatorios_financeiros',
    'view' => 'financeiro_relatorios_financeiros',
  ),
  'fiscal.acesso_contador' => 
  array (
    'rota' => '/fiscal/acesso_contador',
    'view' => 'fiscal_acesso_contador',
  ),
  'fiscal.conciliacao_fiscal' => 
  array (
    'rota' => '/fiscal/conciliacao_fiscal',
    'view' => 'fiscal_conciliacao_fiscal',
  ),
  'fiscal.documentos_fiscais' => 
  array (
    'rota' => '/fiscal/documentos',
    'view' => 'fiscal_documentos_fiscais',
  ),
  'fiscal.emissao_mdfe' => 
  array (
    'rota' => '/fiscal/emissao_mdfe',
    'view' => 'fiscal_emissao_mdfe',
  ),
  'fiscal.emissao_nfe' => 
  array (
    'rota' => '/fiscal/emissao_nfe',
    'view' => 'fiscal_emissao_nfe',
  ),
  'fiscal.historico_fiscal' => 
  array (
    'rota' => '/fiscal/historico_fiscal',
    'view' => 'fiscal_historico_fiscal',
  ),
  'fiscal.importacao_nfe' => 
  array (
    'rota' => '/fiscal/importacao_nfe',
    'view' => 'fiscal_importacao_nfe',
  ),
  'fiscal.importacao_nfse' => 
  array (
    'rota' => '/fiscal/importacao_nfse',
    'view' => 'fiscal_importacao_nfse',
  ),
  'fiscal.livro_caixa_produtor' => 
  array (
    'rota' => '/fiscal/livro',
    'view' => 'fiscal_livro_caixa_produtor',
  ),
  'fiscal.relatorios_fiscais' => 
  array (
    'rota' => '/fiscal/relatorios_fiscais',
    'view' => 'fiscal_relatorios_fiscais',
  ),
  'fiscal.upload_pdf' => 
  array (
    'rota' => '/fiscal/upload_pdf',
    'view' => 'fiscal_upload_pdf',
  ),
  'fiscal.upload_xml' => 
  array (
    'rota' => '/fiscal/upload_xml',
    'view' => 'fiscal_upload_xml',
  ),
  'irrigacao.apontamentos_irrigacao' => 
  array (
    'rota' => '/irrigacao/apontamentos_irrigacao',
    'view' => 'irrigacao_apontamentos_irrigacao',
  ),
  'irrigacao.consumo_agua' => 
  array (
    'rota' => '/irrigacao/consumo_agua',
    'view' => 'irrigacao_consumo_agua',
  ),
  'irrigacao.consumo_energia' => 
  array (
    'rota' => '/irrigacao/consumo_energia',
    'view' => 'irrigacao_consumo_energia',
  ),
  'irrigacao.custo_irrigacao' => 
  array (
    'rota' => '/irrigacao/custo_irrigacao',
    'view' => 'irrigacao_custo_irrigacao',
  ),
  'irrigacao.fertirrigacao' => 
  array (
    'rota' => '/irrigacao/fertirrigacao',
    'view' => 'irrigacao_fertirrigacao',
  ),
  'irrigacao.pivos' => 
  array (
    'rota' => '/irrigacao/painel',
    'view' => 'irrigacao_pivos',
  ),
  'irrigacao.planejado_realizado' => 
  array (
    'rota' => '/irrigacao/planejado_realizado',
    'view' => 'irrigacao_planejado_realizado',
  ),
  'irrigacao.planejamento_irrigacao' => 
  array (
    'rota' => '/irrigacao/planejamento_irrigacao',
    'view' => 'irrigacao_planejamento_irrigacao',
  ),
  'irrigacao.setores_irrigacao' => 
  array (
    'rota' => '/irrigacao/setores_irrigacao',
    'view' => 'irrigacao_setores_irrigacao',
  ),
  'maquinas.abastecimentos' => 
  array (
    'rota' => '/maquinas/abastecimento',
    'view' => 'maquinas_abastecimentos',
  ),
  'maquinas.custo_operacional' => 
  array (
    'rota' => '/maquinas/custo',
    'view' => 'maquinas_custo_operacional',
  ),
  'maquinas.disponibilidade_frota' => 
  array (
    'rota' => '/maquinas/disponibilidade_frota',
    'view' => 'maquinas_disponibilidade_frota',
  ),
  'maquinas.horimetro' => 
  array (
    'rota' => '/maquinas/horimetro',
    'view' => 'maquinas_horimetro',
  ),
  'maquinas.implementos' => 
  array (
    'rota' => '/maquinas/implementos',
    'view' => 'maquinas_implementos',
  ),
  'maquinas.manutencao_corretiva' => 
  array (
    'rota' => '/maquinas/manutencao_corretiva',
    'view' => 'maquinas_manutencao_corretiva',
  ),
  'maquinas.manutencao_preventiva' => 
  array (
    'rota' => '/maquinas/manutencao',
    'view' => 'maquinas_manutencao_preventiva',
  ),
  'maquinas.maquinas' => 
  array (
    'rota' => '/maquinas/cadastro',
    'view' => 'maquinas_maquinas',
  ),
  'maquinas.odometro' => 
  array (
    'rota' => '/maquinas/odometro',
    'view' => 'maquinas_odometro',
  ),
  'maquinas.pecas_servicos' => 
  array (
    'rota' => '/maquinas/pecas_servicos',
    'view' => 'maquinas_pecas_servicos',
  ),
  'maquinas.veiculos' => 
  array (
    'rota' => '/maquinas/veiculos',
    'view' => 'maquinas_veiculos',
  ),
  'mip.alertas_fitossanitarios' => 
  array (
    'rota' => '/mip/alertas_fitossanitarios',
    'view' => 'mip_alertas_fitossanitarios',
  ),
  'mip.alvos_controle' => 
  array (
    'rota' => '/mip/alvos_controle',
    'view' => 'mip_alvos_controle',
  ),
  'mip.aplicacoes_defensivos' => 
  array (
    'rota' => '/mip/aplicacoes',
    'view' => 'mip_aplicacoes_defensivos',
  ),
  'mip.doencas' => 
  array (
    'rota' => '/mip/doencas',
    'view' => 'mip_doencas',
  ),
  'mip.historico_talhao' => 
  array (
    'rota' => '/mip/historico_talhao',
    'view' => 'mip_historico_talhao',
  ),
  'mip.monitoramentos' => 
  array (
    'rota' => '/mip/monitoramento',
    'view' => 'mip_monitoramentos',
  ),
  'mip.nivel_infestacao' => 
  array (
    'rota' => '/mip/nivel_infestacao',
    'view' => 'mip_nivel_infestacao',
  ),
  'mip.pontos_amostragem' => 
  array (
    'rota' => '/mip/pontos_amostragem',
    'view' => 'mip_pontos_amostragem',
  ),
  'mip.pragas' => 
  array (
    'rota' => '/mip/pragas',
    'view' => 'mip_pragas',
  ),
  'mip.relatorios_mip' => 
  array (
    'rota' => '/mip/relatorios_mip',
    'view' => 'mip_relatorios_mip',
  ),
  'nutricao.analise_foliar' => 
  array (
    'rota' => '/nutricao/analise_foliar',
    'view' => 'nutricao_analise_foliar',
  ),
  'nutricao.analise_solo' => 
  array (
    'rota' => '/nutricao/analise_solo',
    'view' => 'nutricao_analise_solo',
  ),
  'nutricao.aplicacoes_nutricionais' => 
  array (
    'rota' => '/nutricao/aplicacoes_nutricionais',
    'view' => 'nutricao_aplicacoes_nutricionais',
  ),
  'nutricao.comparativo_safra' => 
  array (
    'rota' => '/nutricao/comparativo_safra',
    'view' => 'nutricao_comparativo_safra',
  ),
  'nutricao.faixas_nutricionais' => 
  array (
    'rota' => '/nutricao/faixas_nutricionais',
    'view' => 'nutricao_faixas_nutricionais',
  ),
  'nutricao.historico_nutricional' => 
  array (
    'rota' => '/nutricao/historico_nutricional',
    'view' => 'nutricao_historico_nutricional',
  ),
  'nutricao.nutrientes' => 
  array (
    'rota' => '/nutricao/nutrientes',
    'view' => 'nutricao_nutrientes',
  ),
  'nutricao.painel_nutrientes' => 
  array (
    'rota' => '/nutricao/painel_nutrientes',
    'view' => 'nutricao_painel_nutrientes',
  ),
  'patrimonio.benfeitorias' => 
  array (
    'rota' => '/patrimonio/benfeitorias',
    'view' => 'patrimonio_benfeitorias',
  ),
  'patrimonio.depreciacao_gerencial' => 
  array (
    'rota' => '/patrimonio/depreciacao_gerencial',
    'view' => 'patrimonio_depreciacao_gerencial',
  ),
  'patrimonio.equipamentos' => 
  array (
    'rota' => '/patrimonio/equipamentos',
    'view' => 'patrimonio_equipamentos',
  ),
  'patrimonio.localizacao_ativos' => 
  array (
    'rota' => '/patrimonio/localizacao_ativos',
    'view' => 'patrimonio_localizacao_ativos',
  ),
  'patrimonio.maquinas_ativos' => 
  array (
    'rota' => '/patrimonio/ativos',
    'view' => 'patrimonio_maquinas_ativos',
  ),
  'patrimonio.relatorios_patrimoniais' => 
  array (
    'rota' => '/patrimonio/relatorios_patrimoniais',
    'view' => 'patrimonio_relatorios_patrimoniais',
  ),
  'patrimonio.terras' => 
  array (
    'rota' => '/patrimonio/terras',
    'view' => 'patrimonio_terras',
  ),
  'patrimonio.valor_patrimonial' => 
  array (
    'rota' => '/patrimonio/valor_patrimonial',
    'view' => 'patrimonio_valor_patrimonial',
  ),
  'patrimonio.veiculos_ativos' => 
  array (
    'rota' => '/patrimonio/veiculos_ativos',
    'view' => 'patrimonio_veiculos_ativos',
  ),
  'pessoas.apontamento_mao_obra' => 
  array (
    'rota' => '/pessoas/apontamento_mao_obra',
    'view' => 'pessoas_apontamento_mao_obra',
  ),
  'pessoas.custo_mao_obra' => 
  array (
    'rota' => '/pessoas/custo_mao_obra',
    'view' => 'pessoas_custo_mao_obra',
  ),
  'pessoas.equipes' => 
  array (
    'rota' => '/pessoas/equipes',
    'view' => 'pessoas_equipes',
  ),
  'pessoas.historico_colaborador' => 
  array (
    'rota' => '/pessoas/historico_colaborador',
    'view' => 'pessoas_historico_colaborador',
  ),
  'pessoas.operadores' =>
  array (
    'rota' => '/pessoas/colaboradores',
    'view' => 'pessoas_colaboradores',
  ),
  'pessoas.responsaveis_tecnicos' => 
  array (
    'rota' => '/pessoas/responsaveis_tecnicos',
    'view' => 'pessoas_responsaveis_tecnicos',
  ),
  'relatorios.exportacoes' => 
  array (
    'rota' => '/relatorios/exportacoes',
    'view' => 'relatorios_exportacoes',
  ),
  'relatorios.indicadores_estrategicos' => 
  array (
    'rota' => '/relatorios/indicadores_estrategicos',
    'view' => 'relatorios_indicadores_estrategicos',
  ),
  'relatorios.relatorios_colheita' => 
  array (
    'rota' => '/relatorios/relatorios_colheita',
    'view' => 'relatorios_relatorios_colheita',
  ),
  'relatorios.relatorios_compras' => 
  array (
    'rota' => '/relatorios/relatorios_compras',
    'view' => 'relatorios_relatorios_compras',
  ),
  'relatorios.integridade_producao' =>
  array (
    'rota' => '/relatorios/integridade_producao',
    'view' => 'relatorios_integridade_producao',
  ),
  'relatorios.relatorios_estoque' =>
  array (
    'rota' => '/relatorios/relatorios_estoque',
    'view' => 'relatorios_relatorios_estoque',
  ),
  'relatorios.relatorios_financeiros' => 
  array (
    'rota' => '/relatorios/relatorios_financeiros',
    'view' => 'relatorios_relatorios_financeiros',
  ),
  'relatorios.relatorios_operacionais' => 
  array (
    'rota' => '/relatorios/relatorios_operacionais',
    'view' => 'relatorios_relatorios_operacionais',
  ),
  'relatorios.relatorios_safra' => 
  array (
    'rota' => '/relatorios/relatorios_safra',
    'view' => 'relatorios_relatorios_safra',
  ),
  'relatorios.relatorios_tecnicos' => 
  array (
    'rota' => '/relatorios/relatorios_tecnicos',
    'view' => 'relatorios_relatorios_tecnicos',
  ),
);
        foreach ($cache as &$macroItem) {
            foreach ($macroItem['micros'] as &$microItem) {
                if (empty($microItem['slug'])) continue;   /* cabeçalho de seção (sep) não tem slug */
                $routeKey = $macroItem['slug'] . '.' . $microItem['slug'];
                if (isset($rotasReais[$routeKey])) {
                    $microItem['rota'] = $rotasReais[$routeKey]['rota'];
                    $microItem['view'] = $rotasReais[$routeKey]['view'];
                }
            }
        }
        unset($macroItem, $microItem);

        /* ── Macros de AGREGAÇÃO: "Cadastros" e "Apontamentos" (correção UX 08/07) ──
           NÃO são hub/página: são macros de topo cujo SUBMENU são ATALHOS DIRETOS às
           telas reais (mesma rota/perm da origem; sem redirect, sem hub). Geradas da
           própria matriz → sempre em sync; a tela permanece no módulo de origem e
           TAMBÉM aparece agrupada aqui. Só entram itens com ROTA real (placeholder
           não vira atalho). O clone NÃO leva 'view' (launcher puro: não colide no
           resolve_view; a tela real destaca seu módulo de origem). */
        $bios_agrupar = static function (array $cache, string $slug, string $label, array $slugs): array {
            $micros = [];
            $vistos = [];
            foreach ($cache as $macro) {
                foreach ($macro['micros'] as $mi) {
                    if (empty($mi['slug'])) continue;                 // cabeçalho de seção (sep) não tem slug
                    if (!in_array($mi['slug'], $slugs, true)) continue;
                    if (empty($mi['rota'])) continue;                 // só telas reais
                    if (!empty($mi['oculto'])) continue;              // pula consolidados/unificados (talhoes, pragas, doencas…)
                    if (isset($vistos[$mi['slug']])) continue;         // dedup entre módulos
                    $vistos[$mi['slug']] = true;
                    $micros[] = [
                        'slug'  => $mi['slug'],
                        'label' => $mi['label'],
                        /* rota real + hint _nav: ancora a sidebar NESTE macro (nao pula
                           pro módulo de origem). O destino ignora o param; só a sidebar o lê. */
                        'rota'  => $mi['rota'] . (str_contains($mi['rota'], '?') ? '&' : '?') . '_nav=' . $slug,
                        'perm'  => $mi['perm'] ?? ($macro['permbase'] . '.' . $mi['slug'] . '.ver'),
                        /* CARREGA o view de origem: como o micro foi movido (removido do
                           módulo de origem no $cache), não há mais colisão — e assim a
                           página aberta DIRETO (sem ?_nav) resolve para ESTE macro em vez
                           de cair no fallback e destacar o módulo errado. */
                        'view'  => $mi['view'] ?? ($macro['slug'] . '.' . $mi['slug']),
                    ];
                }
            }
            return ['slug' => $slug, 'label' => $label, 'icone' => $slug,
                    'permbase' => $slug, 'perm' => 'dashboard.' . $slug . '.ver', 'micros' => $micros];
        };
        /* C-39/C-40: alvos_controle e pontos_amostragem saíram de
           Cadastros (sanidade fica só no MIP). C-23: o macro de atalhos
           "Apontamentos" foi REMOVIDO (duplicava informação — ex.: Apontamento de
           Campo 2×); Cadastros permanece (decisão da reunião). O micro oculto
           dashboard.apontamentos segue na matriz (guard do B1 intacto). */
        $CAD_SLUGS = ['safras', 'fazendas', 'areas_produtivas', 'talhoes', 'valvulas', 'culturas',
            'variedades', 'porta_enxertos', 'fenologia', 'tipos_atividade', 'parametros_rendimento', 'produtos_insumos', 'grupos_subgrupos',
            'almoxarifados', 'fornecedores', 'metodologias', 'parametros_cultura', 'nutrientes',
            'faixas_nutricionais', 'pragas', 'doencas',
            'setores_irrigacao', 'pivos', 'bombas', 'maquinas', 'veiculos', 'implementos', 'equipes',
            'operadores', 'terceirizados', 'responsaveis_tecnicos', 'premiacao', 'encargos',
            'compradores', 'armazenagem_propria', 'armazenagem_terceiros', 'plano_contas',
            'centros_custo', 'contas_bancarias', 'empresa_fazenda', 'usuarios', 'perfis_acesso',
            'unidades_medida', 'categorias'];
        /* Centralização de cadastros (pedido 21/07 noite): os cadastros de CAMPO
           saem dos módulos de origem e ficam SÓ no macro "Cadastros". Exceções
           mantidas na origem (NÃO centralizadas):
           - Config/Financeiro: usuarios, perfis_acesso, empresa_fazenda,
             plano_contas, centros_custo, contas_bancarias;
           - MIP sanidade (C-39/C-40): pragas, doencas, metodologias. */
        $CAD_MANTER = ['usuarios', 'perfis_acesso', 'empresa_fazenda',
            'plano_contas', 'centros_custo', 'contas_bancarias',
            'pragas', 'doencas', 'metodologias',
            'produtos_insumos',  /* home operacional do Estoque (saldo/mov) — fica no modulo */
            /* pedido do usuário 22/07: devolvidos aos módulos de origem (NÃO centralizar) */
            'safras',                                                                       /* → Gestão Agrícola */
            'equipes', 'operadores', 'terceirizados', 'responsaveis_tecnicos', 'premiacao', 'encargos', /* → Pessoas e Equipes */
            'grupos_subgrupos', 'almoxarifados',                                            /* → Estoque e Insumos */
            'bombas', 'setores_irrigacao', 'pivos',                                         /* → Irrigação */
            'maquinas', 'veiculos', 'implementos',                                          /* → Máquinas e Frota */
            'fornecedores', 'compradores', 'armazenagem_propria', 'armazenagem_terceiros']; /* → Compras/Faturamento (origem) */
        $CAD_MOVER  = array_values(array_diff($CAD_SLUGS, $CAD_MANTER));
        $macroCad = $bios_agrupar($cache, 'cadastros', 'Cadastros', $CAD_MOVER);
        /* Organização do submenu Cadastros em SEÇÕES com título (pedido do usuário):
           o macro nasce na ordem em que os itens aparecem nos módulos de origem —
           reordena por domínio e insere cabeçalhos ['sep'=>Título] entre os grupos.
           Itens ausentes/ocultos são pulados; qualquer micro não mapeado cai em
           "Outros" (nada some). Os sep são rótulos: sidebar os renderiza como
           divisória e bios_menu_micros_visiveis limpa cabeçalho órfão. */
        $CAD_GRUPOS = [
            'Estrutura' => ['fazendas', 'areas_produtivas', 'talhoes', 'valvulas', 'culturas', 'variedades', 'porta_enxertos', 'fenologia'],
            'Operação'  => ['tipos_atividade', 'parametros_rendimento'],
            'Nutrição'  => ['nutrientes', 'faixas_nutricionais', 'parametros_cultura'],
            'Gerais'    => ['unidades_medida'],
        ];
        $porSlug = [];
        foreach ($macroCad['micros'] as $mi) { $porSlug[$mi['slug']] = $mi; }
        $sep = static fn(string $t): array => ['sep' => $t, 'slug' => '', 'label' => $t, 'rota' => ''];
        $ordenado = []; $usados = [];
        foreach ($CAD_GRUPOS as $titulo => $slugs) {
            $doGrupo = [];
            foreach ($slugs as $s) { if (isset($porSlug[$s])) { $doGrupo[] = $porSlug[$s]; $usados[$s] = true; } }
            if (!$doGrupo) { continue; }
            $ordenado[] = $sep($titulo);
            foreach ($doGrupo as $mi) { $ordenado[] = $mi; }
        }
        $sobra = array_values(array_filter($macroCad['micros'], static fn($mi) => empty($usados[$mi['slug']])));
        if ($sobra) {
            $ordenado[] = $sep('Outros');
            foreach ($sobra as $mi) { $ordenado[] = $mi; }
        }
        $macroCad['micros'] = $ordenado;
        /* remove os cadastros de campo dos módulos de origem (ficam só em Cadastros);
           rota/slug/perm preservados — é só o menu. Exceções seguem na origem. */
        foreach ($cache as &$macroRef) {
            $macroRef['micros'] = array_values(array_filter($macroRef['micros'],
                static fn($mi) => !in_array($mi['slug'], $CAD_MOVER, true)));
        }
        unset($macroRef);
        /* logo após o Dashboard (índice 0) */
        array_splice($cache, 1, 0, [$macroCad]);

        /* Onda 1 (Packing House): módulo pós-colheita, anexado ao fim. Micro
           'painel' = ponto de entrada + seletor de contexto ativo (ph_ctx).
           Rota/view inline (não usa $rotasReais); view prefixada com o slug. */
        $cache[] = [
            'slug' => 'packing', 'label' => 'Packing House', 'icone' => 'packing',
            'permbase' => 'packing', 'perm' => 'packing.ver',
            'micros' => [
                ['slug' => 'recepcao', 'label' => 'Recepção', 'rota' => '/packing/recepcao', 'view' => 'packing_recepcao'],
                ['slug' => 'relogio_frio', 'label' => 'Relógio de Frio', 'rota' => '/packing/relogio_frio', 'view' => 'packing_relogio_frio'],
                ['slug' => 'apontar', 'label' => 'Colheita e Embalamento', 'rota' => '/packing/apontar', 'view' => 'packing_apontar'],
                ['slug' => 'unidade', 'label' => 'Unidade', 'rota' => '/packing/unidade', 'view' => 'packing_unidade'],
                ['slug' => 'crachas', 'label' => 'QR Codes', 'rota' => '/packing/crachas', 'view' => 'packing_crachas'],
                ['slug' => 'embalagens', 'label' => 'Embalagens', 'rota' => '/packing/embalagens', 'view' => 'packing_embalagens'],
                ['slug' => 'skus', 'label' => 'SKUs (produto acabado)', 'rota' => '/packing/skus', 'view' => 'packing_skus'],
                ['slug' => 'etiqueta_caixa', 'label' => 'Etiqueta de Caixa', 'rota' => '/packing/etiqueta_caixa', 'view' => 'packing_etiqueta_caixa'],
                ['slug' => 'mercados', 'label' => 'Mercados', 'rota' => '/packing/mercados', 'view' => 'packing_mercados'],
                ['slug' => 'certificacoes', 'label' => 'Certificações', 'rota' => '/packing/certificacoes', 'view' => 'packing_certificacoes'],
                /* 'licencas_varietais' escondido do menu (2026-07-31) — tabela ph_licencas_varietais
                   e /packing/licencas_varietais.php preservados; o gate de licença degrada com
                   elegância (variedade sem registro = não restringe). Reversível: reinserir a linha. */
            ],
        ];

        return $cache;
    }
}

if (!function_exists('bios_menu_secoes')) {
    /**
     * Seções (grupos) do menu macro. Título => lista ordenada de slugs de
     * macro módulos. Define a ORDEM de exibição no menu macro.
     * Uma seção só é renderizada se tiver ao menos 1 macro visível.
     */
    function bios_menu_secoes(): array
    {
        /* Override dos sistemas satélite (VERO CRM) — ver bios_menu_macros(). */
        if (!empty($GLOBALS['BIOS_MENU_SECOES_OVERRIDE'])) {
            return $GLOBALS['BIOS_MENU_SECOES_OVERRIDE'];
        }
        return [
            /* C-23: atalhos "Apontamentos" removidos; C-15: Planejamento entra (RT/gestor) */
            'Geral'                     => ['dashboard', 'planejamento'],
            'Produção Agrícola'         => ['agricola', 'mip', 'nutricao', 'irrigacao', 'packing'],
            'Suprimentos & Frota'       => ['estoque', 'compras', 'maquinas', 'pessoas'],
            'Financeiro & Comercial'    => ['custos', 'comercial', 'financeiro', 'fiscal', 'patrimonio'],
            'Gestão'                    => ['cadastros', 'relatorios', 'configuracoes'],
        ];
    }
}

if (!function_exists('bios_menu_macro')) {
    /** Retorna a config de um macro pelo slug, ou null. */
    function bios_menu_macro(string $macroSlug): ?array
    {
        foreach (bios_menu_macros() as $macro) {
            if ($macro['slug'] === $macroSlug) {
                return $macro;
            }
        }
        return null;
    }
}

if (!function_exists('bios_menu_micro')) {
    /** Retorna a config de um micro (com slug do macro), ou null. */
    function bios_menu_micro(string $macroSlug, string $microSlug): ?array
    {
        $macro = bios_menu_macro($macroSlug);
        if (!$macro) {
            return null;
        }
        foreach ($macro['micros'] as $micro) {
            if ($micro['slug'] === $microSlug) {
                return $micro;
            }
        }
        return null;
    }
}

if (!function_exists('bios_menu_micro_perm')) {
    /** Permissão específica de um micro: explícita ou derivada ({permbase}.{slug}.ver). */
    function bios_menu_micro_perm(array $macro, array $micro): string
    {
        return $micro['perm'] ?? ($macro['permbase'] . '.' . $micro['slug'] . '.ver');
    }
}

if (!function_exists('bios_menu_micro_view')) {
    /** Chave de view efetiva de um micro (real ou sintética para placeholder). */
    function bios_menu_micro_view(array $macro, array $micro): string
    {
        return $micro['view'] ?? ($macro['slug'] . '.' . $micro['slug']);
    }
}

if (!function_exists('bios_menu_micro_href')) {
    /** URL de um micro: rota real ou roteador de placeholder. */
    function bios_menu_micro_href(string $base, array $macro, array $micro): string
    {
        if (!empty($micro['rota'])) {
            return $base . $micro['rota'];
        }
        return $base . '/404';
    }
}

if (!function_exists('bios_menu_ctx')) {
    /** Contexto de acesso do usuário atual (plano, role, permissões). */
    function bios_menu_ctx(): array
    {
        return [
            'plano' => bios_plano_tenant(),
            'role'  => (string)($_SESSION['user_role'] ?? ''),
            'perms' => (array)($_SESSION['permissions'] ?? []),
        ];
    }
}

if (!function_exists('bios_menu_micro_visivel')) {
    /** Micro visível = plano contempla E usuário tem a permissão. */
    function bios_menu_micro_visivel(array $macro, array $micro, ?array $ctx = null): bool
    {
        $ctx = $ctx ?? bios_menu_ctx();
        // Cabeçalho de seção (rótulo, não item): sempre visível; cabeçalho órfão
        // é removido depois em bios_menu_micros_visiveis.
        if (!empty($micro['sep'])) {
            return true;
        }
        // Item oculto: some da sidebar, mas a tela/rota continua acessível
        // (bios_guard protege a rota por permissão+plano, independente disto).
        if (!empty($micro['oculto'])) {
            return false;
        }
        if (!bios_plano_libera($ctx['plano'], $macro['slug'], $micro['slug'])) {
            return false;
        }
        return vero_dbn_perm(bios_menu_micro_perm($macro, $micro), $ctx['role'], $ctx['perms']);
    }
}

if (!function_exists('bios_menu_micros_visiveis')) {
    /** Lista de micros visíveis de um macro, na ordem de exibição. */
    function bios_menu_micros_visiveis(array $macro, ?array $ctx = null): array
    {
        $ctx = $ctx ?? bios_menu_ctx();
        $out = [];
        foreach ($macro['micros'] as $micro) {
            if (bios_menu_micro_visivel($macro, $micro, $ctx)) {
                $out[] = $micro;
            }
        }
        // Remove cabeçalhos de seção órfãos (sem nenhum item real até o próximo sep
        // ou o fim) — evita título de grupo vazio quando a permissão esconde os itens.
        $n = count($out); $limpo = [];
        for ($i = 0; $i < $n; $i++) {
            if (!empty($out[$i]['sep'])) {
                $temItem = ($i + 1 < $n) && empty($out[$i + 1]['sep']);
                if (!$temItem) { continue; }
            }
            $limpo[] = $out[$i];
        }
        return $limpo;
    }
}

if (!function_exists('bios_menu_macro_visivel')) {
    /** Macro só aparece se tiver ao menos 1 micro visível.
     *  Macros com 'gate_perm' => true exigem TAMBÉM a permissão da área
     *  ($macro['perm']) — usado quando os micros são launchers cross-módulo
     *  cuja perm o usuário tem por outro motivo (R12-B2: encarregado tinha
     *  agro.*.ver p/ apontar e o macro Planejamento vazava no menu). */
    function bios_menu_macro_visivel(array $macro, ?array $ctx = null): bool
    {
        $ctx = $ctx ?? bios_menu_ctx();
        if (!bios_plano_libera_macro($ctx['plano'], $macro['slug'])) {
            return false;
        }
        if (!empty($macro['gate_perm'])
            && !vero_dbn_perm((string)($macro['perm'] ?? ''), $ctx['role'], $ctx['perms'])) {
            return false;
        }
        return bios_menu_micros_visiveis($macro, $ctx) !== [];
    }
}

if (!function_exists('bios_menu_primeira_rota')) {
    /**
     * R12B1: URL (sem BIOS_BASE) do PRIMEIRO micro VISÍVEL com rota real
     * para o usuário do contexto, na ordem do menu. É o destino pós-login
     * de perfis sem acesso ao dashboard e do "Voltar ao início" do 403.
     * Micros ocultos ou placeholder (sem 'rota') não contam.
     * null = perfil sem NENHUMA tela visível.
     */
    function bios_menu_primeira_rota(?array $ctx = null): ?string
    {
        $ctx = $ctx ?? bios_menu_ctx();
        foreach (bios_menu_macros() as $macro) {
            if (!bios_plano_libera_macro($ctx['plano'], $macro['slug'])) {
                continue;
            }
            foreach (bios_menu_micros_visiveis($macro, $ctx) as $micro) {
                if (!empty($micro['rota'])) {
                    return $micro['rota'];
                }
            }
        }
        return null;
    }
}

if (!function_exists('bios_menu_resolve_view')) {
    /**
     * Descobre o macro/micro ativos a partir de $PAGE_VIEW.
     * Retorna ['macro'=>slug|null, 'micro'=>slug|null].
     */
    function bios_menu_resolve_view(string $pageView): array
    {
        foreach (bios_menu_macros() as $macro) {
            foreach ($macro['micros'] as $micro) {
                if (bios_menu_micro_view($macro, $micro) === $pageView) {
                    return ['macro' => $macro['slug'], 'micro' => $micro['slug']];
                }
            }
        }
        // Fallback: $PAGE_VIEW pode ser o próprio slug do macro.
        if (bios_menu_macro($pageView)) {
            return ['macro' => $pageView, 'micro' => null];
        }
        return ['macro' => null, 'micro' => null];
    }
}

/* ── Guard de acesso por URL (permissão + plano) ──────────────
   Chamado pelo agro_header (via $GUARD) e pelo roteador agro.php.
   Não confia no menu escondido: bloqueia acesso direto.            */
if (!function_exists('bios_guard')) {
    function bios_guard(string $macroSlug, string $microSlug): void
    {
        $macro = bios_menu_macro($macroSlug);
        $micro = $macro ? bios_menu_micro($macroSlug, $microSlug) : null;

        /* ── PATCH LOCAL 2026-08-11 — reagrupamento do menu vs. $GUARD ──────
           O menu MOVE micros em tempo de execução (centralização de cadastros,
           21/07): 'fazendas' sai de 'agricola' e vai para 'cadastros',
           'ordens_servico' vai para 'planejamento', etc. As páginas continuam
           declarando o macro de ORIGEM no $GUARD, então o lookup acima devolve
           null e o guard nega ANTES de olhar permissão — 302 para /agro/mapa
           com o usuário tendo a permissão concedida. Atingia 16 telas, entre
           elas Válvulas, Culturas, Variedades, Tipos de Atividade e Fazendas.

           Correção: se o micro não estiver no macro informado, procurá-lo nos
           demais e seguir com o macro REAL. A permissão exigida passa a ser a
           do item onde ele de fato está — que é a mesma que o menu usa para
           decidir se desenha o link, então não afrouxa nada.

           NÃO cobre micro que não existe em macro nenhum (talhoes, fenologia,
           areas_produtivas, categorias, licencas_varietais): esses são telas
           CONSOLIDADAS de propósito (ver o 'continue' em $bios_agrupar) e
           seguem negados, como antes deste patch.

           ISTO É PATCH DE VOLUME, NÃO DE IMAGEM: some no próximo deploy, que
           cria volume novo a partir da imagem. Levar para o desenvolvimento —
           ver /root/rollback/BUG-MENU-GUARD-MACRO-2026-08-11.md            */
        if ($micro === null) {
            foreach (bios_menu_macros() as $mmCand) {
                $cand = bios_menu_micro($mmCand['slug'] ?? '', $microSlug);
                if ($cand) {
                    $macro     = $mmCand;
                    $micro     = $cand;
                    $macroSlug = $mmCand['slug'];
                    break;
                }
            }
        }
        /* ── fim do patch local ─────────────────────────────────────────── */

        if (!$macro || !$micro) {
            // Item inexistente na matriz: trata como não autorizado.
            if (function_exists('requirePermission')) {
                requirePermission('__inexistente__');
            }
            return;
        }

        $perm = bios_menu_micro_perm($macro, $micro);

        // 1) Permissão (reaproveita o fluxo de negação/auditoria do auth.php).
        if (function_exists('requirePermission')) {
            requirePermission($perm);
        }

        // 2) Plano contratado.
        $plano = bios_plano_tenant();
        if (!bios_plano_libera($plano, $macroSlug, $microSlug)) {
            bios_deny_plano($macroSlug, $microSlug, $plano);
        }
    }
}

if (!function_exists('bios_deny_plano')) {
    /** Bloqueio por plano: audita (se possível) e leva ao 403 amigável. */
    function bios_deny_plano(string $macroSlug, string $microSlug, string $plano): never
    {
        if (function_exists('_auth_tryAuditLog')) {
            _auth_tryAuditLog([
                'tenant_id'  => $_SESSION['tenant_id'] ?? null,
                'user_id'    => $_SESSION['user_id'] ?? null,
                'email'      => $_SESSION['user_email'] ?? null,
                'acao'       => 'acesso_negado_plano',
                'ip'         => null,
                'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                'status'     => 'falha',
                'detalhes'   => "Plano '{$plano}' não contempla {$macroSlug}/{$microSlug} | URL: " .
                                substr($_SERVER['REQUEST_URI'] ?? '', 0, 100),
            ]);
        }
        $base = defined('BIOS_BASE') ? BIOS_BASE : '';
        if (!headers_sent()) {
            header('Location: ' . $base . '/403?motivo=plano');
        }
        exit;
    }
}
