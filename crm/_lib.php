<?php
declare(strict_types=1);
/* ============================================================
   VERO CRM — biblioteca dos sistemas (fase protótipo)
   Dois SISTEMAS INDEPENDENTES do ERP:
     - VERO CRM · Revenda de Insumos  → /crm/revenda/*
     - VERO CRM · Corretor de Frutas  → /crm/corretor/*

   ESTRUTURA DE MENU = a do VERO (decisão do gestor 13/08 à noite):
   o shell real do ERP (agro_header.php + sidebar.php, coluna de
   módulos + trilho de micros) é reutilizado com uma MATRIZ DE MENU
   PRÓPRIA por sistema (override em bios_menu_macros/secoes). O ERP
   não mostra o CRM e o CRM não mostra os módulos do ERP.

   Visual: tema CLARO padrão do VERO. Mockups de docs/ = só estrutura.

   Uso nas telas:
     require_once dirname(__DIR__) . '/_lib.php';
     $M = crm_mock();
     crm_shell_start([
       'modulo' => 'revenda',           // ou 'corretor'
       'micro'  => 'dashboard',         // slug da tela (ver crm_menu_matriz)
       'titulo' => '...', 'sub' => '...',
       'papel'  => 'vendedor',          // só revenda: vendedor|gestor|revenda
       'acoes'  => '<a class="vbtn">…', // opcional (HTML)
       'demo'   => 'texto do selo',     // opcional: selo DEMONSTRATIVO no título
     ]);
     ... conteúdo ...
     crm_shell_end();

   Autenticação: sessão do VERO; não logado cai no login PRÓPRIO
   do sistema (BIOS_LOGIN_URL → auth.php). Acesso: crm.demo.ver.
   ============================================================ */

require_once __DIR__ . '/../includes/functions.php';   /* bootstrap + BIOS_BASE + h() */
require_once __DIR__ . '/_mock.php';

/* Nome comercial de cada sistema */
function crm_produto(string $modulo): string
{
    if ($modulo === 'corretor')  return 'Corretor de Frutas';
    if ($modulo === 'consultor') return 'Consultor de Frutas';
    return 'Revenda de Insumos';
}

/* URL de uma tela do CRM (rota limpa, sem .php) */
function crm_url(string $modulo, string $rota): string
{
    return BIOS_BASE . '/crm/' . $modulo . '/' . ltrim($rota, '/');
}

/* ── Matriz de menu do sistema (formato de bios_menu_macros) ──
   Menu PLANO: TODAS as telas na coluna principal,
   sem trilho de submenu. Cada tela = 1 macro (com ícone 'glyph'
   específico) contendo 1 micro (a própria tela). Telas de detalhe
   (cliente/oportunidade) são micros ocultos do item pai. Os grupos
   dos mockups viram cabeçalhos de seção da coluna (crm_menu_secoes). */
function crm_definicao_menu(string $modulo): array
{
    /* grupo => [slug => [label, glyph, rota, ocultos?]] */
    if ($modulo === 'consultor') {
        return [
            'Meu dia' => [
                'dashboard'     => ['Painel',                  'activity',        'dashboard',
                                    /* telas fora do menu (gestor 25/08) — seguem
                                       acessíveis por URL/links internos */
                                    ['indicadores'  => ['Indicadores',             'indicadores'],
                                     'ind_campo'    => ['Indicadores · Campo',     'ind-campo'],
                                     'ind_carteira' => ['Indicadores · Carteira',  'ind-carteira'],
                                     'radar'        => ['Radar & Automações',      'radar'],
                                     'automacoes'   => ['Automações',              'automacoes'],
                                     'mobile'       => ['App de Campo',            'mobile'],
                                     'config'       => ['Configurações',           'config']]],
                'meu_dia'       => ['Meu Dia',                 'calendar_check',  'meu-dia'],
                'acoes'         => ['Próximas Ações',          'clipboard_check', 'acoes'],
            ],
            'Carteira' => [
                'produtores'    => ['Produtores',              'users',  'produtores',
                                    ['produtor' => ['Produtor 360', 'produtor']]],
                'propriedades'  => ['Propriedades',            'barn',   'propriedades',
                                    ['propriedade' => ['Propriedade', 'propriedade']]],
                'talhoes'       => ['Talhões & Ciclos',        'parcel', 'talhoes'],
            ],
            'Campo' => [
                'visitas'       => ['Visitas',                 'pin',      'visitas',
                                    ['visita' => ['Registro de Visita', 'visita']]],
                'agenda'        => ['Agenda',                  'calendar', 'agenda'],
                'recomendacoes' => ['Recomendações',           'leaf',     'recomendacoes'],
                'analises'      => ['Análises · Solo e Foliar', 'flask',   'analises',
                                    ['analise' => ['Laudo', 'analise']]],
                'rota'          => ['Rota & Mapa',             'map',      'rota'],
            ],
            'Comercial' => [
                'oportunidades' => ['Oportunidades',           'target',  'oportunidades'],
                'pipeline'      => ['Pipeline',                'layers',  'pipeline',
                                    ['oportunidade' => ['Oportunidade', 'oportunidade']]],
                'propostas'     => ['Propostas & Pedidos',     'receipt', 'propostas'],
                'dre'           => ['Rentabilidade · DRE',     'cash',    'dre',
                                    ['dre_cliente' => ['DRE do Cliente', 'dre-cliente']]],
            ],
        ];
    }
    if ($modulo === 'corretor') {
        return [
            'Operação · Frutas' => [
                'dashboard'     => ['Dashboard',              'activity',  'dashboard'],
                'ceasa'         => ['Preços CEASA',           'trending',  'ceasa'],
                'carregamentos' => ['Carregamentos',          'truck',     'carregamentos'],
                'bipagem'       => ['Bipagem de cargas',      'barcode',   'bipagem'],
                'producao'      => ['Produção & Estoque',     'warehouse', 'producao'],
            ],
            'Gestão' => [
                'financeiro'    => ['Financeiro operacional', 'wallet',   'financeiro'],
                'mao_obra'      => ['Eficiência da operação', 'users',    'mao-de-obra'],
            ],
            'Relacionamento' => [
                'mercados'      => ['Clientes & Mercados',    'building', 'mercados'],
            ],
        ];
    }
    return [
        'Comercial' => [
            'dashboard'     => ['Dashboard',               'activity',        'dashboard'],
            'meu_dia'       => ['Meu dia',                 'calendar_check',  'meu-dia'],
        ],
        'Relacionamento' => [
            'clientes'      => ['Clientes',                'users',           'clientes',
                                ['cliente' => ['Cliente 360', 'cliente']]],
            'pipeline'      => ['Pipeline',                'layers',          'pipeline',
                                ['oportunidade' => ['Oportunidade', 'oportunidade']]],
            'oportunidades' => ['Oportunidades',           'target',          'oportunidades'],
        ],
        'Campo' => [
            'agenda'        => ['Agenda & Visitas',        'calendar',        'agenda'],
            'mapa'          => ['Mapa da carteira',        'map',             'mapa'],
        ],
        'Inteligência' => [
            'roi'           => ['Calculadora ROI',         'coin',            'roi'],
            'comparativo'   => ['Comparativo',             'scale',           'comparativo'],
            'concorrencia'  => ['Concorrência',            'eye',             'concorrencia'],
        ],
        'Comercial ' => [ /* espaço no fim: chave única p/ 2ª seção Comercial */
            'produtos'      => ['Produtos & Preços',       'box',             'produtos'],
            'pedidos'       => ['Pedidos & Vendas',        'receipt',         'pedidos'],
        ],
    ];
}

function crm_menu_matriz(string $modulo): array
{
    $p = 'crm.demo.ver';
    $macros = [];
    foreach (crm_definicao_menu($modulo) as $itens) {
        foreach ($itens as $slug => $def) {
            [$label, $glyph, $rota] = $def;
            $micros = [[
                'slug' => $slug, 'label' => $label,
                'rota' => "/crm/{$modulo}/{$rota}", 'perm' => $p,
            ]];
            foreach (($def[3] ?? []) as $oSlug => [$oLabel, $oRota]) {
                $micros[] = [
                    'slug' => $oSlug, 'label' => $oLabel,
                    'rota' => "/crm/{$modulo}/{$oRota}", 'perm' => $p, 'oculto' => true,
                ];
            }
            $macros[] = [
                'slug' => $slug, 'label' => $label, 'glyph' => $glyph,
                'permbase' => 'crm', 'perm' => $p, 'micros' => $micros,
            ];
        }
    }
    return $macros;
}

function crm_menu_secoes(string $modulo): array
{
    $secoes = [];
    foreach (crm_definicao_menu($modulo) as $grupo => $itens) {
        /* chave SEM trim: o espaço final diferencia a 2ª seção "Comercial "
           (invisível na renderização) — trim aqui fundiria as duas seções */
        $secoes[$grupo] = array_keys($itens);
    }
    return $secoes;
}

/* Localiza a tela na matriz: [macroSlug, microDaView]. Telas de
   detalhe realçam o item PAI no menu (cliente→clientes etc.). */
function crm_localiza(string $modulo, string $micro): array
{
    foreach (crm_menu_matriz($modulo) as $macro) {
        foreach ($macro['micros'] as $mi) {
            if ($mi['slug'] === $micro) {
                /* view do 1º micro (o visível) → macro fica ativo no menu */
                return [$macro['slug'], $macro['micros'][0]['slug']];
            }
        }
    }
    return [crm_menu_matriz($modulo)[0]['slug'], $micro];
}

/* ── Shell (o MESMO do VERO: agro_header + sidebar, matriz própria) ── */
function crm_shell_start(array $cfg): void
{
    $modulo = (string)$cfg['modulo'];                    /* revenda|corretor */
    $micro  = (string)$cfg['micro'];

    /* login próprio do sistema (auth.php honra BIOS_LOGIN_URL) */
    if (!defined('BIOS_LOGIN_URL')) {
        define('BIOS_LOGIN_URL', crm_url($modulo, 'login'));
    }

    /* matriz de menu do sistema — precisa existir ANTES do menu carregar.
       Menu PLANO (sem trilho) e logo SEM descrição. */
    $GLOBALS['BIOS_MENU_OVERRIDE']        = crm_menu_matriz($modulo);
    $GLOBALS['BIOS_MENU_SECOES_OVERRIDE'] = crm_menu_secoes($modulo);
    $GLOBALS['BIOS_BRAND_HREF']           = crm_url($modulo, 'dashboard');
    $GLOBALS['BIOS_MENU_FLAT']            = 1;

    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/vero_crud.php';

    if (!hasPermission('crm.demo.ver')) {
        http_response_code(403);
        exit('Acesso não autorizado ao VERO CRM (permissão crm.demo.ver).');
    }

    [$macroSlug, $viewMicro] = crm_localiza($modulo, $micro);

    $GUARD      = ['macro' => $macroSlug, 'micro' => $micro];
    $PAGE_VIEW  = $macroSlug . '.' . $viewMicro;         /* realce no trilho */
    $PAGE_TITLE = (string)$cfg['titulo'];
    $EXTRA_HEAD = vero_assets()
        . "\n<link rel=\"stylesheet\" href=\"" . BIOS_BASE . '/assets/css/crm.css?v=' . @filemtime(__DIR__ . '/../assets/css/crm.css') . "\">"
        . "\n<script defer src=\"" . BIOS_BASE . '/assets/js/crm.js?v=' . @filemtime(__DIR__ . '/../assets/js/crm.js') . "\"></script>\n";
    require __DIR__ . '/../includes/agro_header.php';

    /* Topbar CANÔNICO do VERO (.vero-topbar, vero-ui.css): título + no máx.
       um botão primário. Sem subtítulo (padrão do cliente) e sem alternador
       de papel — os três dashboards já são itens do menu. O 'sub' informado
       pelas telas é ignorado aqui de propósito. */
    $demo  = isset($cfg['demo']) ? ' ' . crm_demo((string)$cfg['demo']) : '';
    $acoes = (string)($cfg['acoes'] ?? '');

    echo '<div class="vwrap crm-app">';
    echo vero_flash_html();
    echo '<header class="vero-topbar">'
       . '<h1 class="vero-topbar__title">' . h($cfg['titulo']) . $demo . '</h1>'
       . '<div class="vero-topbar__actions">' . $acoes . '</div>'
       . '</header>';
}

function crm_shell_end(): void
{
    echo '</div><!-- /.vwrap.crm-app -->';
    require __DIR__ . '/../includes/agro_footer_simple.php';
}

/* ── Componentes (mesma API desde a 1ª fase — telas não mudam) ── */

/* Selo de conteúdo fictício/integração futura (sempre visível na demo) */
function crm_demo(string $txt = 'DEMONSTRATIVO'): string
{
    return '<span class="crm-demo">' . h($txt) . '</span>';
}

/* Pílula de status. Cores: teal green amber red blue violet grey */
function crm_pill(string $txt, string $cor = 'grey'): string
{
    return '<span class="crm-pill p-' . h($cor) . '">' . h($txt) . '</span>';
}

/* Selo compacto no formato do crm_demo (mono, caps, cantos 4px) com cor
   semântica — pedido do gestor 25/08 ("no estilo do DRE · ERP"). Mesmas
   cores p-* das pílulas. */
function crm_tag(string $txt, string $cor = 'grey'): string
{
    return '<span class="crm-tag p-' . h($cor) . '">' . h($txt) . '</span>';
}

function crm_status_pill(string $status): string
{
    $cor = ['Ativo' => 'green', 'Atenção' => 'amber', 'Prospect' => 'blue'][$status] ?? 'grey';
    return crm_pill($status, $cor);
}

function crm_risco_pill(string $risco): string
{
    $cor = ['Baixo' => 'green', 'Médio' => 'amber', 'Alto' => 'red'][$risco] ?? 'grey';
    return crm_pill($risco === '—' ? '—' : 'Risco ' . strtolower($risco), $cor);
}

/* Tendência: ▲ verde / ▼ vermelho / — cinza */
function crm_trend(float $pct, string $sufixo = '%'): string
{
    if ($pct == 0.0) return '<span class="crm-trend t-flat">—</span>';
    $up = $pct > 0;
    return '<span class="crm-trend ' . ($up ? 't-up' : 't-down') . '">'
         . ($up ? '▲' : '▼') . ' ' . crm_num(abs($pct)) . $sufixo . '</span>';
}

/* KPI card. Cores da faixa: teal green amber red blue violet */
function crm_kpi(string $rotulo, string $valor, string $rodape = '', string $cor = 'teal', string $icone = ''): string
{
    return '<div class="crm-kpi kpi-' . h($cor) . '">'
         . ($icone !== '' ? '<span class="crm-kpi__ic">' . $icone . '</span>' : '')
         . '<div class="crm-kpi__k">' . h($rotulo) . '</div>'
         . '<div class="crm-kpi__v">' . $valor . '</div>'
         . ($rodape !== '' ? '<div class="crm-kpi__f">' . $rodape . '</div>' : '')
         . '</div>';
}

/* Callout contextual. Cores: teal amber red green */
function crm_callout(string $html, string $cor = 'teal', string $icone = ''): string
{
    return '<div class="crm-callout co-' . h($cor) . '">'
         . ($icone !== '' ? '<span class="crm-callout__ic">' . $icone . '</span>' : '')
         . '<div>' . $html . '</div></div>';
}

/* Avatar de iniciais (clientes/mercados) */
function crm_avatar(string $nome, string $cor = 'teal', string $tam = ''): string
{
    $p = preg_split('/\s+/', trim($nome)) ?: [];
    $ini = strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? ($p[0] ?? ''), 0, 1));
    return '<span class="crm-avatar av-' . h($cor) . ($tam !== '' ? ' crm-avatar--' . $tam : '') . '">' . h($ini) . '</span>';
}

/* Barra de proporção (composição/atingimento) — 0..100 */
function crm_bar(float $pct, string $cor = 'teal'): string
{
    $pct = max(0, min(100, $pct));
    /* width em CSS exige PONTO decimal — crm_num pt-BR (vírgula) invalidava a
       declaração e a barra sumia em percentuais quebrados (achado 25/08) */
    return '<span class="crm-track"><span class="crm-fill f-' . h($cor) . '" style="width:' . number_format($pct, 1, '.', '') . '%"></span></span>';
}

/* Linha rótulo→valor (blocos "KV" dos mockups) */
function crm_kv(string $k, string $v): string
{
    return '<div class="crm-kv"><span>' . h($k) . '</span><strong>' . $v . '</strong></div>';
}

/* Ícone de clima em SVG (sol / sol entre nuvens / chuva) — sem emoji.
   O campo 'ic' do mock ('☀'|'⛅'|'🌧') é só a CHAVE do mapeamento. */
function crm_icone_clima(string $ic, int $tam = 24): string
{
    $sol = '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.4M12 19.1v2.4M2.5 12h2.4M19.1 12h2.4M4.9 4.9l1.7 1.7M17.4 17.4l1.7 1.7M19.1 4.9l-1.7 1.7M6.6 17.4l-1.7 1.7"/>';
    $nuvemSol = '<circle cx="8" cy="8.2" r="3"/><path d="M8 2.8v1.6M2.6 8.2h1.6M4.2 4.4l1.1 1.1M13.4 5.6l-1.1 1.1"/>'
              . '<path d="M8.5 19.5h9.3a3.6 3.6 0 0 0 .6-7.1 5 5 0 0 0-9.8 1.2 3 3 0 0 0-.1 5.9Z"/>';
    $chuva = '<path d="M7.5 15.5h9.3a3.6 3.6 0 0 0 .6-7.1 5 5 0 0 0-9.8 1.2 3 3 0 0 0-.1 5.9Z"/>'
           . '<path d="M9 18.5l-.9 2.4M13 18.5l-.9 2.4M17 18.5l-.9 2.4"/>';
    $cores = ['☀' => '#B57C1A', '⛅' => '#8A7C68', '🌧' => '#2A6F97'];
    $path  = ['☀' => $sol, '⛅' => $nuvemSol, '🌧' => $chuva][$ic] ?? $sol;
    return '<svg width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" stroke="'
         . ($cores[$ic] ?? '#B57C1A') . '" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
         . $path . '</svg>';
}
