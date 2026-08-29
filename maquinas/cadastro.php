<?php
/* ============================================================
   VERO — Máquinas / Cadastro  (CRUD real)
   Substitui o mock. Rota: /maquinas/cadastro.php
   Guard: maquinas.maquinas | Escrita: maquinas.maquinas.editar/excluir
   Tabela: maquinas (trator, colheitadeira, pulverizador…)
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'maquinas';

const TIPOS_MAQ = [
    'trator' => 'Trator', 'colheitadeira' => 'Colheitadeira', 'pulverizador' => 'Pulverizador',
    'implemento' => 'Implemento', 'veiculo' => 'Veículo',
    /* C-14 (reunião 18/07, mig 174): bandejão = aplicação de Dormex/cianamida */
    'estercadeira' => 'Estercadeira', 'rocadeira' => 'Roçadeira', 'bandejao' => 'Bandejão',
    'outro' => 'Outro',
];
const STATUS_MAQ = ['ativa' => 'Ativa', 'manutencao' => 'Em manutenção', 'inativa' => 'Inativa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('maquinas.maquinas.editar');

        $id     = vero_int('id');
        $codigo = vero_str('codigo', 30);
        $nome   = vero_str('nome', 150);
        $tipo   = vero_str('tipo', 20);

        if ($codigo === null || $nome === null || $tipo === null || !isset(TIPOS_MAQ[$tipo])) {
            vero_flash('erro', 'Código, nome e tipo são obrigatórios.');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM " . T . " WHERE tenant_id=:t AND codigo=:c AND id<>:id",
            [':t' => vero_tenant(), ':c' => $codigo, ':id' => (int)$id]);
        if ($dup) {
            vero_flash('erro', "Já existe uma máquina com o código \"{$codigo}\".");
            vero_redirect();
        }
        $fazendaId = vero_int('fazenda_id');
        if ($fazendaId) {
            $ok = vero_val("SELECT id FROM agro_fazendas WHERE id=:i AND tenant_id=:t",
                [':i' => $fazendaId, ':t' => vero_tenant()]);
            if (!$ok) $fazendaId = null;
        }
        $statusMaq = vero_str('status', 15);
        if (!isset(STATUS_MAQ[$statusMaq ?? ''])) $statusMaq = 'ativa';

        /* A2-F2-7 (DB-10): operador padrão + depreciação gerencial */
        $operadorId = vero_int('operador_padrao_id');
        if ($operadorId) {
            $ok = vero_val("SELECT id FROM agro_operadores WHERE id=:i AND tenant_id=:t",
                [':i' => $operadorId, ':t' => vero_tenant()]);
            if (!$ok) $operadorId = null;
        }

        /* A11: horímetro, custo/hora, valores e capacidades são sempre >= 0 */
        $horimetroAtual = vero_dec('horimetro_atual') ?? 0;
        $custoHora      = vero_dec('custo_hora') ?? 0;
        $valorAquis     = vero_dec('valor_aquisicao');
        $valorResid     = vero_dec('valor_residual');
        $vidaUtilH      = vero_dec('vida_util_horas');
        $capacTanque    = vero_dec('capacidade_tanque_l');
        if ($horimetroAtual < 0 || $custoHora < 0
            || ($valorAquis !== null && $valorAquis < 0)
            || ($valorResid !== null && $valorResid < 0)
            || ($vidaUtilH !== null && $vidaUtilH < 0)
            || ($capacTanque !== null && $capacTanque < 0)) {
            vero_flash('erro', 'Horímetro, custo/hora, valores e capacidade não podem ser negativos.');
            vero_redirect();
        }

        $data = [
            'codigo'          => $codigo,
            'nome'            => $nome,
            'tipo'            => $tipo,
            'fazenda_id'      => $fazendaId,
            'marca'           => vero_str('marca', 80),
            'modelo'          => vero_str('modelo', 80),
            'ano'             => vero_int('ano'),
            'horimetro_atual' => $horimetroAtual,
            'custo_hora'      => $custoHora,
            'status'          => $statusMaq,
            'operador_padrao_id' => $operadorId,
            'valor_aquisicao' => $valorAquis,
            'valor_residual'  => $valorResid,
            'vida_util_horas' => $vidaUtilH,
            'capacidade_tanque_l' => $capacTanque, /* DB-27/A0-07: cálculo por tanque na DF */
            'ativo'           => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Máquina \"{$nome}\" atualizada.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Máquina \"{$nome}\" cadastrada.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('maquinas.maquinas.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "m.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND (m.codigo LIKE :q1 OR m.nome LIKE :q2 OR m.marca LIKE :q3 OR m.modelo LIKE :q4)";
    foreach ([1, 2, 3, 4] as $qi) $params[":q{$qi}"] = "%{$q}%"; /* QA-011 */
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " m WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT m.*, f.nome AS fazenda,
            (SELECT COALESCE(SUM(a.valor_total),0) FROM maquina_abastecimentos a
              WHERE a.tenant_id = m.tenant_id AND a.maquina_id = m.id) AS custo_combustivel,
            (SELECT COALESCE(SUM(mn.custo),0) FROM maquina_manutencoes mn
              WHERE mn.tenant_id = m.tenant_id AND mn.maquina_id = m.id AND mn.status = 'executada') AS custo_manutencao
       FROM " . T . " m
       LEFT JOIN agro_fazendas f ON f.id = m.fazenda_id
      WHERE {$where}
      ORDER BY m.ativo DESC, m.codigo
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$operadoresOpt = vero_options('agro_operadores', 'nome', 'ativo = 1'); /* A2-F2-7 */

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

/* P-75 (CSO): colunas de custo (R$) da lista só com o proxy financeiro. O
   formulário de edição não é mascarado — editar a máquina exige maquinas.editar. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;

$GUARD      = ['macro' => 'maquinas', 'micro' => 'maquinas'];
$PAGE_VIEW  = 'maquinas_maquinas';
$PAGE_TITLE = 'Máquinas';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.maquinas.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Máquinas e Frota', 'Cadastro da frota — base dos abastecimentos, manutenções e custo operacional',
        $podeEditar ? '+ Nova máquina' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por código, nome, marca ou modelo…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma máquina cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Código</th><th>Máquina</th><th>Tipo</th><th>Fazenda</th>
        <th style="text-align:right">Horímetro (h)</th>
        <th style="text-align:right">Combustível (R$)</th>
        <th style="text-align:right">Manutenções (R$)</th>
        <th>Situação</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong></td>
          <td><strong><?= h($r['nome']) ?></strong>
            <?= $r['marca'] || $r['modelo'] ? '<div class="vhint">' . h(trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? '') . ' ' . ($r['ano'] ?? ''))) . '</div>' : '' ?></td>
          <td><span class="vbadge vb-info"><?= h(TIPOS_MAQ[$r['tipo']] ?? $r['tipo']) ?></span></td>
          <td><?= h($r['fazenda'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['horimetro_atual'], 1) ?></td>
          <td class="vnum" style="text-align:right"><?= $veCusto ? numFmt((float)$r['custo_combustivel'], 2) : '•••' ?></td>
          <td class="vnum" style="text-align:right"><?= $veCusto ? numFmt((float)$r['custo_manutencao'], 2) : '•••' ?></td>
          <td><?= match ($r['status']) {
                'ativa'      => '<span class="vbadge vb-ok">Ativa</span>',
                'manutencao' => '<span class="vbadge vb-warn">Em manutenção</span>',
                default      => '<span class="vbadge vb-off">Inativa</span>',
          } ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_olho(), 'Ficha consolidada da máquina', '', rtrim(BIOS_BASE, '/') . '/maquinas/ficha_maquina?id=' . (int)$r['id']) ?>
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('maquinas.maquinas.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta máquina?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar máquina' : 'Nova máquina' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('codigo', 'Código', $edit['codigo'] ?? '', true, 'Ex.: TR-01') ?>
        <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Trator Massey 4275') ?>
        <?= vero_f_select('tipo', 'Tipo', TIPOS_MAQ, $edit['tipo'] ?? 'trator', true, '') ?>
        <?= vero_f_select('fazenda_id', 'Fazenda', $fazendas, $edit['fazenda_id'] ?? null, false, '— Não vinculada —') ?>
        <?= vero_f_text('marca', 'Marca', $edit['marca'] ?? '') ?>
        <?= vero_f_text('modelo', 'Modelo', $edit['modelo'] ?? '') ?>
        <?= vero_f_text('ano', 'Ano', $edit && $edit['ano'] !== null ? (string)(int)$edit['ano'] : '') ?>
        <?= vero_f_text('horimetro_atual', 'Horímetro atual (h)', $edit ? numFmt((float)$edit['horimetro_atual'], 1) : '', false, 'Atualizado pelos abastecimentos') ?>
        <?= vero_f_text('custo_hora', 'Custo/hora informado (R$)', $edit ? numFmt((float)$edit['custo_hora'], 2) : '', false, 'Referência manual — o painel compara com o custo-hora efetivo') ?>
        <?= vero_f_select('status', 'Situação', STATUS_MAQ, $edit['status'] ?? 'ativa', true, '') ?>
        <?= vero_f_select('operador_padrao_id', 'Operador padrão', $operadoresOpt, $edit['operador_padrao_id'] ?? null, false, '— Nenhum —') ?>
        <?= vero_f_text('valor_aquisicao', 'Valor de aquisição (R$)', $edit && $edit['valor_aquisicao'] !== null ? numFmt((float)$edit['valor_aquisicao'], 2) : '', false, 'depreciação GERENCIAL da frota (contábil fica no Patrimônio)') ?>
        <?= vero_f_text('valor_residual', 'Valor residual (R$)', $edit && $edit['valor_residual'] !== null ? numFmt((float)$edit['valor_residual'], 2) : '') ?>
        <?= vero_f_text('vida_util_horas', 'Vida útil (horas)', $edit && $edit['vida_util_horas'] !== null ? numFmt((float)$edit['vida_util_horas'], 0) : '', false, 'tarifa de depreciação/h = (aquisição − residual) ÷ vida útil') ?>
        <?= vero_f_text('capacidade_tanque_l', 'Capacidade do tanque (L)', $edit && $edit['capacidade_tanque_l'] !== null ? numFmt((float)$edit['capacidade_tanque_l'], 0) : '', false, 'pulverizador/drone — cálculo por tanque na DF (ex.: drone 70 L)') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Cadastro', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
