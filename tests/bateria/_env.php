<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/_env.php
   Configuração da bateria (A5-QA). Ajustável por ambiente.
   NÃO commitar segredos reais além do token local de runner.
   ============================================================ */

return [
    /* Regra inegociável nº 2: abortar se o banco não for o de homologação.
       Hosts permitidos (adicione 'localhost' APENAS numa réplica local). */
    'db_hosts_permitidos' => ['localhost'],

    /* Base HTTP da aplicação (WAMP local por padrão; pode apontar para
       https://servidor01.example.com se o codebase de lá for o mesmo). */
    'base_url' => 'http://localhost/vero',

    /* Binário PHP para os subprocessos do run_all (Windows/WAMP). */
    'php_bin' => 'php',

    /* Identidade do tenant de teste — TUDO da bateria vive nele. */
    'tenant_nome'  => 'QA BATERIA — NÃO USAR',
    'tenant2_nome' => 'QA BATERIA 2 — NÃO USAR',

    /* Usuários de teste (1 por perfil). Senha única da bateria. */
    'senha' => 'change_me',
    'usuarios' => [
        'super'      => ['email' => 'qa.super@vero.test',      'nome' => 'QA Super',      'perfil' => 'super_admin'],
        'gestor'     => ['email' => 'qa.gestor@vero.test',     'nome' => 'QA Gestor',     'perfil' => 'gestor'],
        'operador'   => ['email' => 'qa.operador@vero.test',   'nome' => 'QA Operador',   'perfil' => 'operador'],
        'financeiro' => ['email' => 'qa.financeiro@vero.test', 'nome' => 'QA Financeiro', 'perfil' => 'financeiro'],
        'consulta'   => ['email' => 'qa.consulta@vero.test',   'nome' => 'QA Consulta',   'perfil' => 'consulta'],
    ],
    'usuario_tenant2' => ['email' => 'qa2.super@vero.test', 'nome' => 'QA2 Super', 'perfil' => 'super_admin'],

    /* Token do runner HTTP (_http_runner.php) — só aceita localhost + token. */
    'runner_token' => 'defina-um-token-local',

    /* Datas canônicas (GABARITO.md). */
    'datas' => [
        'poda'          => '2026-06-01',
        'estoque_ini'   => '2026-07-05',
        'recebimento'   => '2026-07-08',
        'monitoramento' => '2026-07-09',
        'analise'       => '2026-07-09',
        'apontamento'   => '2026-07-10',
        'irrigacao'     => '2026-07-10',
        'abastecimento' => '2026-07-10',
        'aplicacao'     => '2026-07-11',
        'colheita'      => '2026-07-15',
        'venda'         => '2026-07-16',
        'vencimento'    => '2026-08-15',
        'baixa_pagar'   => '2026-07-20',
        'baixa_receber' => '2026-07-25',
        'competencia'   => '2026-07-01',
        'aquisicao'     => '2026-06-15',
        'vig_encargos'  => '2026-01-01',
    ],
];
