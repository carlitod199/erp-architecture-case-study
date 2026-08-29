<?php
/* ============================================================
   VERO — Gestão Agrícola / Tipos de Atividade  (CRUD real)
   Tela nova. Rota da matriz: /agro/tipos_atividade.php
   Guard: agricola.tipos_atividade | Escrita: agro.tipos_atividade.editar/excluir
   Tabelas: agro_tipos_atividade + agro_tipo_atividade_culturas (migration 130)
   Convenção: tipo SEM culturas vinculadas vale para TODAS as culturas
   (pendente de validação com o cliente — ajustar filtro do apontamento se mudar).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T  = 'agro_tipos_atividade';
const TN = 'agro_tipo_atividade_culturas';

/* P-15: exclusão (soft-delete) só para a alçada de DONO da
   fazenda — não basta o slug de escrita. Tier de dono/administração. */
const TIPOS_ROLES_EXCLUIR = ['super_admin', 'club_admin', 'administrador', 'dono'];

const CATEGORIAS = [
    'trato_cultural' => 'Trato cultural', 'colheita' => 'Colheita', 'aplicacao' => 'Aplicação',
    'irrigacao' => 'Irrigação', 'packing' => 'Packing', 'outro' => 'Outro',
];
const UNIDADES = [
    'planta' => 'Planta', 'caixa' => 'Caixa', 'kg' => 'kg', 'ha' => 'ha',
    'metro_linear' => 'Metro linear', 'hora' => 'Hora',
    'cacho' => 'Cacho',                       /* existia no ENUM sem opção na tela */
    'fila' => 'Fila',                         /* C-45 (reunião 18/07, mig 174): fileiras roçadas */
    'contentor' => 'Contentor',               /* WP-CALC Z-05: colheita a granel por contentor */
    'outro' => 'Outra',
];

/** Sincroniza o N:N (tabela sem colunas de auditoria — PDO direto). */
function vero_sync_tipo_culturas(int $tipoId, array $culturaIds): void
{
    $pdo = vero_pdo();
    $t   = vero_tenant();
    $atuais = array_map('intval', array_column(vero_rows(
        "SELECT cultura_id FROM " . TN . " WHERE tenant_id=:t AND tipo_atividade_id=:a",
        [':t' => $t, ':a' => $tipoId]
    ), 'cultura_id'));

    foreach (array_diff($atuais, $culturaIds) as $rm) {
        $pdo->prepare("DELETE FROM " . TN . " WHERE tenant_id=? AND tipo_atividade_id=? AND cultura_id=?")
            ->execute([$t, $tipoId, $rm]);
    }
    foreach (array_diff($culturaIds, $atuais) as $add) {
        $pdo->prepare("INSERT INTO " . TN . " (tenant_id, tipo_atividade_id, cultura_id) VALUES (?,?,?)")
            ->execute([$t, $tipoId, $add]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('agro.tipos_atividade.editar');

        $id        = vero_int('id');
        $nome      = vero_str('nome', 120);
        $categoria = vero_str('categoria', 20);

        if ($nome === null || $categoria === null || !isset(CATEGORIAS[$categoria])) {
            vero_flash('erro', 'Nome e categoria são obrigatórios.');
            vero_redirect();
        }
        /* nome único por tenant (constraint inclui inativos) */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe o tipo de atividade \"{$nome}\" (mesmo que inativo).");
            vero_redirect();
        }

        $unidade = vero_str('unidade_padrao', 20);
        if ($unidade !== null && !isset(UNIDADES[$unidade])) $unidade = null;

        /* culturas marcadas — valida cada uma no tenant */
        $culturasValidas = array_map('intval', array_keys(vero_options('agro_culturas', 'nome')));
        $culturasSel = array_values(array_intersect(
            array_map('intval', (array)($_POST['culturas'] ?? [])),
            $culturasValidas
        ));

        $data = [
            'nome'           => $nome,
            'categoria'      => $categoria,
            'unidade_padrao' => $unidade,
            'exige_producao' => vero_int('exige_producao') ? 1 : 0,
            'ativo'          => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_sync_tipo_culturas($id, $culturasSel);
            vero_flash('ok', "Tipo de atividade \"{$nome}\" atualizado.");
        } else {
            $novoId = vero_insert(T, $data);
            vero_sync_tipo_culturas($novoId, $culturasSel);
            vero_flash('ok', "Tipo de atividade \"{$nome}\" cadastrado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('agro.tipos_atividade.excluir');
        /* P-15: excluir (inativar) restrito ao DONO da fazenda —
           slug de escrita não basta; exige role de dono/administração. */
        if (!in_array((string)($_SESSION['user_role'] ?? ''), TIPOS_ROLES_EXCLUIR, true)) {
            vero_flash('erro', 'Apenas o dono da fazenda pode excluir tipos de atividade.');
            vero_redirect();
        }
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "a.tenant_id = :t";
$params = [':t' => vero_tenant()];
/* P-15/P-14: por padrão a lista OCULTA os inativos (soft-delete). Quem pode
   editar tem um toggle "Mostrar inativos" (?inativos=1). A atividade
   "aplicação de defensiva" inativada some daqui sem reaparecer. */
$verInativos = ($_GET['inativos'] ?? '') === '1' && vero_can('agro.tipos_atividade.editar');
if (!$verInativos) {
    $where .= " AND a.ativo = 1";
}
if ($q !== '') {
    $where .= " AND a.nome LIKE :q";
    $params[':q'] = "%{$q}%";
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " a WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT a.*,
            (SELECT GROUP_CONCAT(c.nome ORDER BY c.nome SEPARATOR ', ')
               FROM " . TN . " tc JOIN agro_culturas c ON c.id = tc.cultura_id
              WHERE tc.tenant_id = a.tenant_id AND tc.tipo_atividade_id = a.id) AS culturas_nomes
       FROM " . T . " a
      WHERE {$where}
      ORDER BY a.ativo DESC, a.categoria, a.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$culturas = vero_options('agro_culturas', 'nome', 'ativo = 1');

$edit = null;
$editCulturas = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editCulturas = array_map('intval', array_column(vero_rows(
            "SELECT cultura_id FROM " . TN . " WHERE tenant_id=:t AND tipo_atividade_id=:a",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]
        ), 'cultura_id'));
    }
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'tipos_atividade'];
$PAGE_VIEW  = 'agricola_tipos_atividade';
$PAGE_TITLE = 'Tipos de Atividade';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('agro.tipos_atividade.editar');
/* P-15: botão de excluir só para quem tem o slug E a alçada de dono */
$podeExcluir = vero_can('agro.tipos_atividade.excluir')
    && in_array((string)($_SESSION['user_role'] ?? ''), TIPOS_ROLES_EXCLUIR, true);
/* toggle "Mostrar inativos" preservando a busca atual */
$tglQs = $_GET; unset($tglQs['pg']);
if ($verInativos) unset($tglQs['inativos']); else $tglQs['inativos'] = '1';
$tglUrl = strtok((string)$_SERVER['REQUEST_URI'], '?') . ($tglQs ? '?' . http_build_query($tglQs) : '');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Tipos de Atividade', 'Catálogo de operações (Poda, Colheita, Desbrota…) por cultura — base do apontamento e da premiação',
        $podeEditar ? '+ Novo tipo' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <?php if ($podeEditar): ?>
        <a class="vbtn vbtn-ghost" href="<?= h($tglUrl) ?>"><?= $verInativos ? 'Ocultar inativos' : 'Mostrar inativos' ?></a>
      <?php endif; ?>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty"><?= $q !== '' ? 'Nenhum tipo de atividade para a busca.' : 'Nenhum tipo de atividade cadastrado ainda.' ?></div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Nome</th><th>Categoria</th><th>Unidade padrão</th>
        <th>Exige produção</th><th>Culturas</th>
        <th>Status</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int)$r['ativo'] === 0 ? ' class="is-off"' : '' ?>>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h(CATEGORIAS[$r['categoria']] ?? $r['categoria']) ?></td>
          <td><?= $r['unidade_padrao'] !== null ? h(UNIDADES[$r['unidade_padrao']] ?? $r['unidade_padrao']) : '—' ?></td>
          <td><?= (int)$r['exige_producao'] === 1
                ? '<span style="color:#1E6B34;font-weight:600">Sim</span>'
                : '<span class="vhint">Não</span>' ?></td>
          <td><?= $r['culturas_nomes'] !== null ? h($r['culturas_nomes']) : '<span class="vhint" style="font-style:italic">Todas as culturas</span>' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td class="num"><div class="vactions" style="justify-content:flex-end">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if ($podeExcluir && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este tipo de atividade?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="vhint" style="padding:10px 14px">
      Atividades que <strong>exigem produção</strong> (Sim) alimentam colheita e metas; as demais alimentam apenas operação e custo.
      <strong>Todas as culturas</strong> = o tipo vale para qualquer cultura.
    </div>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar tipo de atividade' : 'Novo tipo de atividade' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_text('nome', 'Nome', $edit['nome'] ?? '', true, 'Ex.: Poda, Raleio, Embalamento') ?>
        <?= vero_f_select('categoria', 'Categoria', CATEGORIAS, $edit['categoria'] ?? 'trato_cultural', true, '') ?>
        <?= vero_f_select('unidade_padrao', 'Unidade padrão de produção', UNIDADES, $edit['unidade_padrao'] ?? null, false, '— Não se aplica —') ?>
        <?= vero_f_select('exige_producao', 'Exige quantidade produzida?', [1 => 'Sim', 0 => 'Não'], $edit ? (int)$edit['exige_producao'] : 0, true, '') ?>
        <div class="full vfield">
          <label>Culturas em que se aplica</label>
          <?php if ($culturas): ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px 18px;padding:4px 0">
              <?php foreach ($culturas as $cid => $cn): ?>
                <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;font-size:13px;margin:0">
                  <input type="checkbox" name="culturas[]" value="<?= $cid ?>" style="width:auto"
                         <?= in_array($cid, $editCulturas, true) ? 'checked' : '' ?>>
                  <?= h($cn) ?>
                </label>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="vhint">Nenhuma cultura ativa cadastrada.</div>
          <?php endif; ?>
          <div class="vhint">Sem nenhuma cultura marcada, o tipo vale para todas as culturas.</div>
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
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
