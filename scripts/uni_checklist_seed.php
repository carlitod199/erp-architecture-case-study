<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_checklist_seed.php
   Semeadura idempotente do CHECKLIST DE IMPLANTAÇÃO da
   Universidade VERO no banco SEPARADO (config/database_uni.php).

   São os itens da "Ordem Mestra de Implantação" que o Painel do
   Gestor (universidade/equipe.php) mostra com ✓/pendente. Cada item
   guarda uma `verificacao_sql` — um SELECT no banco do SISTEMA com o
   placeholder :tenant. Se a query devolve ≥1 linha para o tenant, o
   item conta como concluído (avaliado automaticamente por
   uni_gestor_checklist()). `capsula_slug` aponta a cápsula que ensina.

   - Itens GLOBAIS (tenant_id = NULL): valem para todos os tenants.
   - Upsert por slug => rodável N vezes sem duplicar (idempotente).

   Rodar:
     php scripts/uni_checklist_seed.php
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$pdo = uni_pdo();

/* ------------------------------------------------------------
   Itens do checklist, na ordem canônica da implantação.
   As verificacao_sql são SUBqueries: uni_gestor_checklist() as
   embrulha em `SELECT COUNT(*) FROM (<sql>) AS _chk` e faz o bind
   de :tenant. Por isso cada uma é um `SELECT 1 FROM ... LIMIT 1`.
   Toda query filtra tenant_id = :tenant (isolamento do tenant).
   ------------------------------------------------------------ */
$ITENS = [
    [
        'slug'        => 'chk-fazenda-talhoes',
        'titulo'      => 'Cadastrar fazenda e talhões',
        'descricao'   => "Cadastre a fazenda e desenhe os talhões (as válvulas) no mapa. É a base de tudo: sem talhão, não há onde apontar, colher ou custear.",
        'capsula'     => null,
        'perfil'      => 'gestor',
        'sql'         => 'SELECT 1 FROM agro_talhoes WHERE tenant_id = :tenant AND ativo = 1 LIMIT 1',
    ],
    [
        'slug'        => 'chk-variedade-valvula',
        'titulo'      => 'Definir a variedade em cada válvula',
        'descricao'   => "Informe qual variedade está plantada em cada talhão. A variedade guia a fenologia (o dia 0 da poda) e as recomendações — sem ela, a safra não calcula direito.",
        'capsula'     => null,
        'perfil'      => 'gestor',
        'sql'         => 'SELECT 1 FROM agro_talhoes WHERE tenant_id = :tenant AND ativo = 1 AND variedade_id IS NOT NULL LIMIT 1',
    ],
    [
        'slug'        => 'chk-abrir-safra',
        'titulo'      => 'Abrir a safra',
        'descricao'   => "Abra a safra e confirme a poda (o dia 0). É o que liga o relógio da fenologia e permite emitir ordens, apontar e acumular custo no ciclo.",
        'capsula'     => 'abrir-safra-e-confirmar-poda',
        'perfil'      => 'gestor',
        'sql'         => "SELECT 1 FROM agro_safras WHERE tenant_id = :tenant AND status IN ('ativa','encerrada') LIMIT 1",
    ],
    [
        'slug'        => 'chk-saldo-estoque',
        'titulo'      => 'Fazer o saldo inicial do estoque',
        'descricao'   => "Lance o saldo inicial dos produtos no almoxarifado. Sem saldo, as aplicações não têm de onde baixar insumo e o custo dos produtos não aparece.",
        'capsula'     => 'fazer-saldo-inicial',
        'perfil'      => 'almoxarifado',
        'sql'         => 'SELECT 1 FROM estoque_saldos WHERE tenant_id = :tenant AND quantidade > 0 LIMIT 1',
    ],
    [
        'slug'        => 'chk-colaboradores',
        'titulo'      => 'Cadastrar colaboradores',
        'descricao'   => "Cadastre os operadores/colaboradores (com custo por hora). Sem eles não há quem executar a ordem de serviço nem como custear a mão de obra.",
        'capsula'     => null,
        'perfil'      => 'gestor',
        'sql'         => 'SELECT 1 FROM agro_operadores WHERE tenant_id = :tenant AND ativo = 1 LIMIT 1',
    ],
    [
        'slug'        => 'chk-primeira-os',
        'titulo'      => 'Emitir a primeira Ordem de Serviço',
        'descricao'   => "Emita a primeira Ordem de Serviço para o campo executar. É o comando que sai do escritório e vira apontamento no talhão.",
        'capsula'     => 'emitir-ordem-de-servico',
        'perfil'      => 'encarregado',
        'sql'         => 'SELECT 1 FROM agro_ordens_servico WHERE tenant_id = :tenant LIMIT 1',
    ],
    [
        'slug'        => 'chk-primeiro-apontamento',
        'titulo'      => 'Fazer e finalizar o primeiro apontamento',
        'descricao'   => "Faça e finalize o primeiro apontamento (status validado). É quando o trabalho no campo vira custo real na safra — o ciclo fecha aqui.",
        'capsula'     => 'finalizar-apontamento',
        'perfil'      => 'encarregado',
        'sql'         => "SELECT 1 FROM agro_apontamentos WHERE tenant_id = :tenant AND status = 'validado' LIMIT 1",
    ],
];

/* ------------------------------------------------------------
   Upsert por slug (idempotente). Itens globais => tenant_id = NULL.
   ------------------------------------------------------------ */
$up = $pdo->prepare(
    "INSERT INTO uni_checklist_item
        (tenant_id, slug, titulo, descricao_md, capsula_slug, verificacao_sql, perfil, ordem, ativo)
     VALUES (NULL, :slug, :titulo, :descricao, :capsula, :sql, :perfil, :ordem, 1)
     ON DUPLICATE KEY UPDATE
        titulo          = VALUES(titulo),
        descricao_md    = VALUES(descricao_md),
        capsula_slug    = VALUES(capsula_slug),
        verificacao_sql = VALUES(verificacao_sql),
        perfil          = VALUES(perfil),
        ordem           = VALUES(ordem),
        ativo           = 1"
);

$pdo->beginTransaction();
$ordem = 0;
foreach ($ITENS as $it) {
    $up->execute([
        ':slug'      => $it['slug'],
        ':titulo'    => $it['titulo'],
        ':descricao' => $it['descricao'],
        ':capsula'   => $it['capsula'],
        ':sql'       => $it['sql'],
        ':perfil'    => $it['perfil'],
        ':ordem'     => ++$ordem,
    ]);
}
$pdo->commit();

/* ------------------------------------------------------------
   Relatório
   ------------------------------------------------------------ */
$total = (int)$pdo->query("SELECT COUNT(*) FROM uni_checklist_item")->fetchColumn();
echo "== Semeadura Universidade VERO — checklist de implantação ==\n";
echo "  Itens nesta execução (upsert) .... " . count($ITENS) . "\n";
echo "  Itens no banco (total) ........... {$total}\n\n";

echo "== Itens ==\n";
$rows = $pdo->query(
    "SELECT ordem, slug, titulo, capsula_slug FROM uni_checklist_item
      WHERE slug LIKE 'chk-%' ORDER BY ordem, id"
)->fetchAll();
foreach ($rows as $r) {
    $cap = $r['capsula_slug'] ? " -> capsula: {$r['capsula_slug']}" : " (sem capsula)";
    echo "  {$r['ordem']}. [{$r['slug']}] {$r['titulo']}{$cap}\n";
}
