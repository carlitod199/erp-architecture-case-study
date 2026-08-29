<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Agro / camada de FIXTURES (mock central)
   Protótipo navegável (fase demo): TODOS os dados das telas dos
   módulos Revenda e Corretor saem daqui. Na próxima fase esta
   camada é trocada por chamadas de API sem tocar nas telas.
   Cenário: revenda Agrovale Insumos (Vale do São Francisco),
   vendedor Carlos Andrade; corretor Vale Frutas Comercial.
   ============================================================ */

function crm_mock(): array
{
    static $M = null;
    if ($M !== null) return $M;

    $M = [];

    /* ── Clientes da carteira (Revenda) ── */
    $M['clientes'] = [
        'c1' => [
            'id' => 'c1', 'nome' => 'Fazenda Santa Helena', 'tipo' => 'Produtor · PJ',
            'cidade' => 'Petrolina/PE', 'cultura' => 'Uva', 'area' => 180,
            'pot' => 'Alto', 'status' => 'Ativo', 'seg' => 'A · Alto valor',
            'fat12' => 1240000, 'margem' => 22, 'risco' => 'Baixo',
            'ult_visita' => 12, 'var_consumo' => -4, 'cor' => 'green',
            'lat' => 32, 'lng' => 38, 'geo' => [-9.322, -40.612],
            'contatos' => [
                ['nome' => 'Ricardo Menezes', 'cargo' => 'Proprietário', 'tel' => '(87) 99988-1122'],
                ['nome' => 'Ana Paula', 'cargo' => 'Compras', 'tel' => '(87) 99912-3344'],
            ],
            'props' => [
                ['nome' => 'Santa Helena I', 'municipio' => 'Petrolina/PE', 'area' => 120, 'cultura' => 'Uva Crimson'],
                ['nome' => 'Santa Helena II', 'municipio' => 'Petrolina/PE', 'area' => 60, 'cultura' => 'Uva Thompson'],
            ],
            'produtos' => ['NitroMax', 'ProtectSC', 'VigorPlus'],
        ],
        'c2' => [
            'id' => 'c2', 'nome' => 'Fazenda Boa Esperança', 'tipo' => 'Produtor · PJ',
            'cidade' => 'Juazeiro/BA', 'cultura' => 'Manga', 'area' => 240,
            'pot' => 'Alto', 'status' => 'Atenção', 'seg' => 'C · Risco de perda',
            'fat12' => 890000, 'margem' => 18, 'risco' => 'Alto',
            'ult_visita' => 42, 'var_consumo' => -28, 'cor' => 'red',
            'lat' => 58, 'lng' => 55, 'geo' => [-9.523, -40.401],
            'contatos' => [['nome' => 'José Aparecido', 'cargo' => 'Proprietário', 'tel' => '(74) 99877-5566']],
            'props' => [['nome' => 'Boa Esperança', 'municipio' => 'Juazeiro/BA', 'area' => 240, 'cultura' => 'Manga Tommy/Palmer']],
            'produtos' => ['NitroMax', 'BioRoot'],
        ],
        'c3' => [
            'id' => 'c3', 'nome' => 'Grupo São José', 'tipo' => 'Produtor · PJ',
            'cidade' => 'Lagoa Grande/PE', 'cultura' => 'Manga · Uva', 'area' => 520,
            'pot' => 'Muito alto', 'status' => 'Ativo', 'seg' => 'A · Alto valor',
            'fat12' => 2380000, 'margem' => 25, 'risco' => 'Baixo',
            'ult_visita' => 6, 'var_consumo' => 12, 'cor' => 'teal',
            'lat' => 20, 'lng' => 66, 'geo' => [-8.998, -40.277],
            'contatos' => [
                ['nome' => 'Marcos São José', 'cargo' => 'Diretor', 'tel' => '(87) 99900-1010'],
                ['nome' => 'Fernanda Lima', 'cargo' => 'Gerente Agrícola', 'tel' => '(87) 99900-2020'],
            ],
            'props' => [
                ['nome' => 'São José Matriz', 'municipio' => 'Lagoa Grande/PE', 'area' => 300, 'cultura' => 'Manga Kent/Palmer'],
                ['nome' => 'São José Norte', 'municipio' => 'Lagoa Grande/PE', 'area' => 220, 'cultura' => 'Uva Crimson/Vitória'],
            ],
            'produtos' => ['NitroMax', 'ProtectSC', 'BioRoot', 'SpreadFix', 'VigorPlus'],
        ],
        'c4' => [
            'id' => 'c4', 'nome' => 'AgroVale Frutas', 'tipo' => 'Produtor · PJ',
            'cidade' => 'Casa Nova/BA', 'cultura' => 'Uva', 'area' => 95,
            'pot' => 'Médio', 'status' => 'Ativo', 'seg' => 'B · Alto potencial',
            'fat12' => 410000, 'margem' => 20, 'risco' => 'Médio',
            'ult_visita' => 21, 'var_consumo' => 5, 'cor' => 'amber',
            'lat' => 74, 'lng' => 30, 'geo' => [-9.142, -40.931],
            'contatos' => [['nome' => 'Paulo Vale', 'cargo' => 'Proprietário', 'tel' => '(74) 99855-7788']],
            'props' => [['nome' => 'AgroVale', 'municipio' => 'Casa Nova/BA', 'area' => 95, 'cultura' => 'Uva Sugraone']],
            'produtos' => ['ProtectSC', 'SpreadFix'],
        ],
        'c5' => [
            'id' => 'c5', 'nome' => 'Fruticultura Nova Era', 'tipo' => 'Produtor · PJ',
            'cidade' => 'Petrolina/PE', 'cultura' => 'Manga', 'area' => 310,
            'pot' => 'Alto', 'status' => 'Prospect', 'seg' => 'B · Alto potencial',
            'fat12' => 0, 'margem' => 0, 'risco' => '—',
            'ult_visita' => 0, 'var_consumo' => 0, 'cor' => 'blue',
            'lat' => 44, 'lng' => 18, 'geo' => [-9.268, -40.442],
            'contatos' => [['nome' => 'Cláudia Nova', 'cargo' => 'Sócia', 'tel' => '(87) 99811-4455']],
            'props' => [['nome' => 'Nova Era', 'municipio' => 'Petrolina/PE', 'area' => 310, 'cultura' => 'Manga Palmer/Keitt']],
            'produtos' => [],
        ],
    ];

    /* ── Catálogo de produtos (viria do ERP VERO — DEMONSTRATIVO) ── */
    $M['produtos'] = [
        ['nome' => 'NitroMax',  'cat' => 'Fertilizante',    'alvo' => 'Nutrição foliar (N-Ca)',  'dose' => '2,5 L/ha', 'preco' => 48.00,  'estoque' => 1240, 'un' => 'L'],
        ['nome' => 'ProtectSC', 'cat' => 'Defensivo',       'alvo' => 'Oídio / Míldio (uva)',    'dose' => '0,8 L/ha', 'preco' => 145.00, 'estoque' => 380,  'un' => 'L'],
        ['nome' => 'BioRoot',   'cat' => 'Biológico',       'alvo' => 'Trichoderma · raiz',      'dose' => '1,5 kg/ha', 'preco' => 96.00, 'estoque' => 210,  'un' => 'kg'],
        ['nome' => 'SpreadFix', 'cat' => 'Adjuvante',       'alvo' => 'Espalhante adesivo',      'dose' => '0,3 L/ha', 'preco' => 32.00,  'estoque' => 900,  'un' => 'L'],
        ['nome' => 'VigorPlus', 'cat' => 'Bioestimulante',  'alvo' => 'Pegamento / floração',    'dose' => '1,0 L/ha', 'preco' => 118.00, 'estoque' => 145,  'un' => 'L'],
    ];

    /* ── Funil (4 etapas — decisão do gestor 13/08: sem Diagnóstico/Negociação) ── */
    $M['etapas'] = ['Lead', 'Qualificação', 'Proposta', 'Pedido'];

    $M['opps'] = [
        'o1' => ['id' => 'o1', 'cliente' => 'c3', 'nome' => 'Programa nutricional safra 26/27', 'valor' => 184000, 'etapa' => 0, 'prod' => 'NitroMax + VigorPlus', 'prob' => 20, 'dias' => 3],
        'o2' => ['id' => 'o2', 'cliente' => 'c1', 'nome' => 'Manejo de oídio · 120 ha',         'valor' => 52200,  'etapa' => 1, 'prod' => 'ProtectSC',            'prob' => 55, 'dias' => 8],
        'o3' => ['id' => 'o3', 'cliente' => 'c2', 'nome' => 'Recuperação de carteira',          'valor' => 96000,  'etapa' => 1, 'prod' => 'BioRoot + NitroMax',   'prob' => 30, 'dias' => 14],
        'o4' => ['id' => 'o4', 'cliente' => 'c4', 'nome' => 'Adjuvante + defensivo',            'valor' => 28400,  'etapa' => 2, 'prod' => 'ProtectSC + SpreadFix', 'prob' => 65, 'dias' => 5],
        'o5' => ['id' => 'o5', 'cliente' => 'c5', 'nome' => 'Entrada Nova Era (prospect)',      'valor' => 140000, 'etapa' => 0, 'prod' => 'Programa completo',    'prob' => 15, 'dias' => 2],
        'o6' => ['id' => 'o6', 'cliente' => 'c3', 'nome' => 'Bioestimulante floração uva',      'valor' => 66000,  'etapa' => 2, 'prod' => 'VigorPlus',            'prob' => 80, 'dias' => 2],
        'o7' => ['id' => 'o7', 'cliente' => 'c1', 'nome' => 'Reposição NitroMax',               'valor' => 31000,  'etapa' => 3, 'prod' => 'NitroMax',             'prob' => 100, 'dias' => 0],
    ];

    /* ── Agenda do dia (13/08) ── */
    $M['agenda'] = [
        ['h' => '07:30', 't' => 'Visita · Fazenda Santa Helena',      'sub' => 'Petrolina/PE · manejo de oídio',   'tipo' => 'visita',   'cor' => 'teal',   'cliente' => 'c1'],
        ['h' => '09:30', 't' => 'Follow-up · Fazenda Boa Esperança',  'sub' => 'Recuperação de carteira',          'tipo' => 'call',     'cor' => 'amber',  'cliente' => 'c2'],
        ['h' => '11:00', 't' => 'Proposta · Grupo São José',          'sub' => 'Programa nutricional 26/27',       'tipo' => 'proposta', 'cor' => 'blue',   'cliente' => 'c3'],
        ['h' => '14:00', 't' => 'Visita · AgroVale Frutas',           'sub' => 'Casa Nova/BA · adjuvante',         'tipo' => 'visita',   'cor' => 'teal',   'cliente' => 'c4'],
        ['h' => '16:30', 't' => 'Prospecção · Fruticultura Nova Era', 'sub' => '1ª reunião técnica',               'tipo' => 'reuniao',  'cor' => 'violet', 'cliente' => 'c5'],
    ];

    /* ── Inteligência competitiva ── */
    $M['concorrencia'] = [
        ['prod' => 'ProtectSC (equiv.)', 'fab' => 'AgriChem',  'conc' => 'Concorrente A', 'vero' => 145, 'cp' => 152, 'reg' => 'Petrolina',    'dt' => '11/08'],
        ['prod' => 'ProtectSC (equiv.)', 'fab' => 'RuralMax',  'conc' => 'Concorrente B', 'vero' => 145, 'cp' => 139, 'reg' => 'Juazeiro',     'dt' => '10/08'],
        ['prod' => 'NitroMax (equiv.)',  'fab' => 'NutriAgro', 'conc' => 'Concorrente A', 'vero' => 48,  'cp' => 51,  'reg' => 'Lagoa Grande', 'dt' => '09/08'],
        ['prod' => 'BioRoot (equiv.)',   'fab' => 'BioVale',   'conc' => 'Concorrente C', 'vero' => 96,  'cp' => 102, 'reg' => 'Casa Nova',    'dt' => '08/08'],
    ];

    /* ── Tendência de preço · ProtectSC × concorrência (8 semanas) ── */
    $M['conc_tendencia'] = [
        'semanas' => ['24/06', '01/07', '08/07', '15/07', '22/07', '29/07', '05/08', '12/08'],
        'vero'    => [145, 145, 145, 145, 145, 145, 145, 145],
        'conc'    => [156, 154, 153, 151, 150, 148, 147, 146],
    ];

    /* ── Clima (DEMONSTRATIVO — integração meteorológica) ── */
    /* hoje (quinta 13/08) + próximos 7 dias */
    $M['clima'] = [
        ['d' => 'Hoje', 'ic' => '☀', 'max' => 33, 'min' => 24, 'ch' => 0,  'al' => ''],
        ['d' => 'Sex',  'ic' => '⛅', 'max' => 31, 'min' => 23, 'ch' => 12, 'al' => 'Risco: chuva na colheita da uva (rachadura)'],
        ['d' => 'Sáb',  'ic' => '🌧', 'max' => 30, 'min' => 22, 'ch' => 5,  'al' => 'Atenção packing'],
        ['d' => 'Dom',  'ic' => '⛅', 'max' => 32, 'min' => 24, 'ch' => 0,  'al' => ''],
        ['d' => 'Seg',  'ic' => '☀', 'max' => 33, 'min' => 24, 'ch' => 0,  'al' => ''],
        ['d' => 'Ter',  'ic' => '☀', 'max' => 34, 'min' => 25, 'ch' => 0,  'al' => ''],
        ['d' => 'Qua',  'ic' => '⛅', 'max' => 32, 'min' => 23, 'ch' => 2,  'al' => ''],
        ['d' => 'Qui',  'ic' => '☀', 'max' => 33, 'min' => 24, 'ch' => 0,  'al' => ''],
    ];

    /* ── Pedidos (Revenda) ── */
    $M['pedidos'] = [
        ['num' => '4821', 'cliente' => 'Grupo São José',        'prod' => 'NitroMax + VigorPlus',  'valor' => 184000, 'status' => 'Aprovado',          'cor' => 'teal'],
        ['num' => '4820', 'cliente' => 'Fazenda Santa Helena',  'prod' => 'ProtectSC',             'valor' => 52200,  'status' => 'Em aberto',         'cor' => 'blue'],
        ['num' => '4818', 'cliente' => 'AgroVale Frutas',       'prod' => 'ProtectSC + SpreadFix', 'valor' => 28400,  'status' => 'Faturado',          'cor' => 'green'],
        ['num' => '4815', 'cliente' => 'Fazenda Boa Esperança', 'prod' => 'BioRoot',               'valor' => 96000,  'status' => 'Pendente · crédito', 'cor' => 'amber'],
    ];

    /* ── Equipe (Dashboard do Gestor) ── */
    $M['vendedores'] = [
        ['nome' => 'Carlos Andrade', 'vendas' => 342000, 'meta' => 500000, 'ticket' => 28500],
        ['nome' => 'Marina Costa',   'vendas' => 288000, 'meta' => 400000, 'ticket' => 24000],
        ['nome' => 'Rafael Dias',    'vendas' => 215000, 'meta' => 350000, 'ticket' => 19500],
        ['nome' => 'Juliana Reis',   'vendas' => 335000, 'meta' => 400000, 'ticket' => 27900],
    ];

    /* ── Vendas mensais (gráfico do dashboard, R$ mil) ── */
    $M['vendas_meses'] = [
        ['jan', 180], ['fev', 210], ['mar', 240], ['abr', 225],
        ['mai', 280], ['jun', 305], ['jul', 330], ['ago', 342],
    ];

    /* ── Priorização do mapa ("quem visitar hoje") ── */
    $M['visitar_hoje'] = [
        ['cliente' => 'c2', 'score' => 98, 'motivo' => 'Sem visita há 42d · consumo -28%', 'cor' => 'red'],
        ['cliente' => 'c1', 'score' => 82, 'motivo' => 'Oportunidade aberta · rota curta',  'cor' => 'amber'],
        ['cliente' => 'c5', 'score' => 70, 'motivo' => 'Prospect quente · 1ª visita',       'cor' => 'teal'],
    ];

    /* ── Calculadora ROI: programas/produtos [preço R$, dose /ha] ── */
    $M['roi_produtos'] = [
        ['nome' => 'BioRoot · biológico',        'preco' => 96,  'dose' => 1.5],
        ['nome' => 'VigorPlus · bioestimulante', 'preco' => 118, 'dose' => 1.0],
        ['nome' => 'ProtectSC · defensivo',      'preco' => 145, 'dose' => 0.8],
    ];

    /* ── Perfis & permissões (tela config) ── */
    $M['perfis'] = [
        ['perfil' => 'Administrador',      've' => 'Tudo (todos os módulos)',              'faz' => 'Configura perfis, pipelines e integração', 'escopo' => 'Global'],
        ['perfil' => 'Gestor comercial',   've' => 'Toda a operação comercial',            'faz' => 'Metas, equipes, forecast, redistribuição',  'escopo' => 'Revenda'],
        ['perfil' => 'Vendedor',           've' => 'Sua carteira e agenda',                'faz' => 'Visitas, oportunidades, pedidos, ROI',      'escopo' => 'Carteira própria'],
        ['perfil' => 'Corretor',           've' => 'Operação de frutas',                   'faz' => 'Cargas, preços, financeiro operacional',    'escopo' => 'Corretagem'],
        ['perfil' => 'Consultor técnico',  've' => 'Clientes atribuídos',                  'faz' => 'Relatórios técnicos e recomendações',       'escopo' => 'Atribuição'],
        ['perfil' => 'Financeiro',         've' => 'Títulos, margens e crédito',           'faz' => 'Aprova crédito, concilia recebimentos',     'escopo' => 'Financeiro'],
        ['perfil' => 'Visualização',       've' => 'Dashboards e relatórios',              'faz' => 'Somente leitura',                           'escopo' => 'Global'],
    ];

    /* ══════════════ CORRETOR DE FRUTAS ══════════════ */

    /* ── Preços CEASA (DEMONSTRATIVO — PROHORT/CEPEA) ── */
    $M['ceasa'] = [
        ['p' => 'Manga', 'ic' => '', 'v' => 'Tommy Atkins', 'cl' => 'Primeira',   'cal' => '9-10', 'min' => 1.70, 'com' => 2.10, 'max' => 2.40, 't' => 9],
        ['p' => 'Manga', 'ic' => '', 'v' => 'Tommy Atkins', 'cl' => 'Segunda',    'cal' => '12',   'min' => 1.10, 'com' => 1.45, 'max' => 1.70, 't' => 0],
        ['p' => 'Manga', 'ic' => '', 'v' => 'Palmer',       'cl' => 'Primeira',   'cal' => '8-9',  'min' => 2.10, 'com' => 2.35, 'max' => 2.60, 't' => -2],
        ['p' => 'Manga', 'ic' => '', 'v' => 'Kent',         'cl' => 'Exportação', 'cal' => '8-9',  'min' => 3.00, 'com' => 3.55, 'max' => 4.50, 't' => -12],
        ['p' => 'Uva',   'ic' => '', 'v' => 'Crimson',      'cl' => 'Extra',      'cal' => '—',    'min' => 6.50, 'com' => 8.00, 'max' => 9.50, 't' => 6],
        ['p' => 'Uva',   'ic' => '', 'v' => 'Thompson',     'cl' => 'Extra',      'cal' => '—',    'min' => 6.00, 'com' => 7.50, 'max' => 9.00, 't' => 0],
        ['p' => 'Uva',   'ic' => '', 'v' => 'Vitória',      'cl' => 'Primeira',   'cal' => '—',    'min' => 4.50, 'com' => 5.80, 'max' => 7.00, 't' => 3],
        ['p' => 'Uva',   'ic' => '', 'v' => 'Itália',       'cl' => 'Primeira',   'cal' => '—',    'min' => 2.00, 'com' => 2.80, 'max' => 3.50, 't' => 11],
    ];

    /* ── Carregamentos do dia ── */
    $M['cargas'] = [
        [
            'id' => '0813-07', 'cam' => 'PEX-4B21', 'mot' => 'José Aparecido',
            'dest' => 'CEAGESP · São Paulo', 'status' => 'Em trânsito', 'cor' => 'blue',
            'itens' => [
                ['v' => 'Palmer', 'cl' => 'Primeira', 'cal' => 'cal. 8',  'cx' => 420, 'peso' => 1806],
                ['v' => 'Tommy',  'cl' => 'Primeira', 'cal' => 'cal. 10', 'cx' => 260, 'peso' => 1040],
                ['v' => 'Tommy',  'cl' => 'Segunda',  'cal' => 'cal. 12', 'cx' => 180, 'peso' => 720],
            ],
            'venda' => 18900, 'frete' => 2100, 'com' => 1512,
        ],
        [
            'id' => '0813-08', 'cam' => 'PBR-2A55', 'mot' => 'Antônio Silva',
            'dest' => 'CEASA · Recife', 'status' => 'Carregando', 'cor' => 'amber',
            'itens' => [
                ['v' => 'Crimson',  'cl' => 'Extra', 'cal' => '—', 'cx' => 300, 'peso' => 1350],
                ['v' => 'Thompson', 'cl' => 'Extra', 'cal' => '—', 'cx' => 150, 'peso' => 675],
            ],
            'venda' => 16200, 'frete' => 1400, 'com' => 1296,
        ],
        [
            'id' => '0813-09', 'cam' => 'PGO-7C18', 'mot' => 'Marcos Pereira',
            'dest' => 'CEASA · Belo Horizonte', 'status' => 'Programado', 'cor' => 'grey',
            'itens' => [
                ['v' => 'Kent', 'cl' => 'Exportação', 'cal' => 'cal. 8', 'cx' => 380, 'peso' => 1634],
            ],
            'venda' => 22400, 'frete' => 2600, 'com' => 1792,
        ],
    ];

    /* ── Financeiro operacional (DEMONSTRATIVO — ERP) ── */
    $M['fin_receber'] = [
        ['quem' => 'CEASA-MG · Belo Horizonte', 'valor' => 14200, 'venc' => 'Vencido', 'cor' => 'red'],
        ['quem' => 'Atacadista Silva · SP',     'valor' => 18000, 'venc' => 'Hoje',    'cor' => 'amber'],
        ['quem' => 'CEAGESP · São Paulo',       'valor' => 31200, 'venc' => '14/08',   'cor' => 'grey'],
        ['quem' => 'Mercado Central · Fortaleza','valor' => 22000, 'venc' => '18/08',  'cor' => 'grey'],
        ['quem' => 'Distribuidora VF',          'valor' => 11000, 'venc' => '20/08',   'cor' => 'grey'],
    ];
    $M['fin_pagar'] = [
        ['quem' => 'Fazenda Boa Vista · repasse', 'valor' => 28000, 'venc' => 'Hoje',  'cor' => 'amber'],
        ['quem' => 'Translog VSF · frete',        'valor' => 6100,  'venc' => '14/08', 'cor' => 'grey'],
        ['quem' => 'Turma A · mão de obra',       'valor' => 9400,  'venc' => '15/08', 'cor' => 'grey'],
        ['quem' => 'Embalagens Vale',             'valor' => 7200,  'venc' => '16/08', 'cor' => 'grey'],
        ['quem' => 'Sítio Riacho · repasse',      'valor' => 20500, 'venc' => '18/08', 'cor' => 'grey'],
    ];

    /* ── Bipagem de cargas (leitor simulado — códigos padrão GS1 da demo) ──
       Últimas leituras do dia; a tela adiciona novas em estado local (JS). */
    $M['bipagens'] = [
        ['hora' => '06:52', 'codigo' => '7898357410125', 'carga' => '0813-07', 'v' => 'Palmer',  'cl' => 'Primeira', 'peso' => 4.3],
        ['hora' => '06:51', 'codigo' => '7898357410118', 'carga' => '0813-07', 'v' => 'Palmer',  'cl' => 'Primeira', 'peso' => 4.3],
        ['hora' => '06:49', 'codigo' => '7898357410095', 'carga' => '0813-07', 'v' => 'Tommy',   'cl' => 'Primeira', 'peso' => 4.0],
        ['hora' => '06:47', 'codigo' => '7898357410088', 'carga' => '0813-08', 'v' => 'Crimson', 'cl' => 'Extra',    'peso' => 4.5],
        ['hora' => '06:44', 'codigo' => '7898357410071', 'carga' => '0813-08', 'v' => 'Crimson', 'cl' => 'Extra',    'peso' => 4.5],
    ];

    /* ── Produção & estoque do packing (caixas · 12/08) ── */
    $M['producao_estoque'] = [
        ['v' => 'Palmer',   'cl' => 'Primeira',   'prod' => 1240, 'exp' => 980, 'est' => 260],
        ['v' => 'Tommy',    'cl' => 'Primeira',   'prod' => 860,  'exp' => 700, 'est' => 160],
        ['v' => 'Tommy',    'cl' => 'Segunda',    'prod' => 420,  'exp' => 180, 'est' => 240],
        ['v' => 'Kent',     'cl' => 'Exportação', 'prod' => 610,  'exp' => 380, 'est' => 230],
        ['v' => 'Crimson',  'cl' => 'Extra',      'prod' => 780,  'exp' => 450, 'est' => 330],
        ['v' => 'Thompson', 'cl' => 'Extra',      'prod' => 540,  'exp' => 150, 'est' => 390],
    ];

    /* ── Perdas do dia (demonstrativo do dashboard) ── */
    $M['perdas'] = [
        ['motivo' => 'Refugo de campo',         'kg' => 52, 'valor' => 180],
        ['motivo' => 'Dano no transporte',      'kg' => 34, 'valor' => 210],
        ['motivo' => 'Descarte no packing',     'kg' => 28, 'valor' => 160],
        ['motivo' => 'Calibre fora do padrão',  'kg' => 14, 'valor' => 90],
    ];

    /* ── Mão de obra / eficiência ── */
    /* ritmo de produção do packing (caixas fechadas por hora · 12/08) */
    $M['producao_horas'] = [
        ['7h', 320], ['8h', 420], ['9h', 480], ['10h', 460], ['11h', 410],
        ['12h', 220], ['13h', 380], ['14h', 450], ['15h', 470], ['16h', 440], ['17h', 400],
    ];

    $M['turmas'] = [
        ['t' => 'Turma A · Embalagem', 'pes' => 18, 'cx' => 1180, 'prod' => 65,  'ap' => 74, 'custo' => 0.22],
        ['t' => 'Turma B · Embalagem', 'pes' => 16, 'cx' => 960,  'prod' => 60,  'ap' => 71, 'custo' => 0.25],
        ['t' => 'Turma C · Colheita',  'pes' => 22, 'cx' => 2310, 'prod' => 105, 'ap' => 0,  'custo' => 0.18],
    ];

    /* ── Clientes & Mercados (Corretor) ── */
    $M['mercados'] = [
        ['nome' => 'CEAGESP · São Paulo',        'uf' => 'SP', 'vol' => 42.0, 'fat' => 118000, 'freq' => 'Semanal',   'margem' => 6.4, 'prods' => 'Palmer, Tommy 1ª',    'cor' => 'teal',   'rank' => 1],
        ['nome' => 'CEASA · Recife',             'uf' => 'PE', 'vol' => 28.0, 'fat' => 74000,  'freq' => 'Semanal',   'margem' => 8.1, 'prods' => 'Crimson, Thompson',   'cor' => 'green',  'rank' => 2],
        ['nome' => 'CEASA · Belo Horizonte',     'uf' => 'MG', 'vol' => 19.0, 'fat' => 61000,  'freq' => 'Quinzenal', 'margem' => 7.2, 'prods' => 'Kent export, Palmer', 'cor' => 'blue',   'rank' => 3],
        ['nome' => 'Atacadista Silva · SP',      'uf' => 'SP', 'vol' => 12.0, 'fat' => 33000,  'freq' => 'Semanal',   'margem' => 5.5, 'prods' => 'Tommy 2ª',            'cor' => 'amber',  'rank' => 4],
        ['nome' => 'Mercado Central · Fortaleza','uf' => 'CE', 'vol' => 9.0,  'fat' => 26000,  'freq' => 'Mensal',    'margem' => 6.8, 'prods' => 'Vitória, Itália',     'cor' => 'violet', 'rank' => 5],
    ];

    return $M;
}

/* ── Formatadores (padrão pt-BR do VERO) ── */
function crm_brl(float $v, int $dec = 0): string
{
    return 'R$ ' . number_format($v, $dec, ',', '.');
}
function crm_num(float $v, int $dec = 0): string
{
    return number_format($v, $dec, ',', '.');
}
