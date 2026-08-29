<?php
/* ============================================================
   VERO — Agrícola / Ordens de Serviço  (FILA DE TRABALHO — A1-39)
   Rota: /agro/ordens_servico.php · Guard: agricola.ordens_servico
   Arbitragem A1-33 (P-29): a OS é a projeção NUMERADA 1:1 da
   atividade planejada (entidade-mestre) — criada e sincronizada
   por agro/_os_espelho.php no salvar da atividade. Esta tela é a
   FILA: lista as OS com a riqueza da atividade + execuções
   (apontamentos, que agora gravam ordem_servico_id) e o impresso
   de OS de campo (agro/os_impressao.php — paralelo do DF31 para
   poda/raleio/tratos). Criação/edição/status ACONTECEM na
   atividade; aqui não se escreve (aplicação não tem OS — DF/IF).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* rótulo P-57 */

const T = 'agro_ordens_servico';
const STATUS_OS = ['aberta' => 'Aberta', 'em_execucao' => 'Em execução', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'];

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    /* A1-39: a OS é espelho da atividade — toda escrita acontece lá */
    vero_flash('aviso', 'A OS é a projeção numerada da atividade planejada: crie, edite e mude o status em Agrícola → Planejamento de Atividades — a OS acompanha sozinha.');
    vero_redirect();
}

$fStatus = (string)($_GET['status'] ?? '');
$where  = "os.tenant_id = :t";
$params = [':t' => $t];
if (isset(STATUS_OS[$fStatus])) { $where .= " AND os.status = :st"; $params[':st'] = $fStatus; }

$rows = vero_rows(
    "SELECT os.*, tl.codigo AS talhao, fz.nome AS fazenda,
            at.descricao AS atividade, at.data_planejada, at.area_prevista_ha,
            at.custo_previsto, at.status AS atv_status,
            ta.nome AS tipo_atividade, op.nome AS responsavel,
            (SELECT COUNT(*) FROM agro_apontamentos ap
              WHERE ap.tenant_id = os.tenant_id AND ap.ordem_servico_id = os.id) AS execucoes,
            (SELECT MAX(DATE(ap2.data_apontamento)) FROM agro_apontamentos ap2
              WHERE ap2.tenant_id = os.tenant_id AND ap2.ordem_servico_id = os.id) AS ultima_execucao
       FROM " . T . " os
       LEFT JOIN agro_talhoes tl ON tl.id = os.talhao_id
       LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
       LEFT JOIN agro_atividades at ON at.id = os.atividade_id
       LEFT JOIN agro_tipos_atividade ta ON ta.id = at.tipo_atividade_id
       LEFT JOIN agro_operadores op ON op.id = at.responsavel_id
      WHERE {$where}
      ORDER BY FIELD(os.status,'em_execucao','aberta','concluida','cancelada'), os.id DESC LIMIT 100", $params);

$kpi = vero_row(
    "SELECT SUM(status='aberta') AS abertas, SUM(status='em_execucao') AS execucao,
            SUM(status='concluida' AND data_conclusao >= :mes) AS concluidas_mes
       FROM " . T . " WHERE tenant_id = :t",
    [':t' => $t, ':mes' => date('Y-m-01')]) ?: ['abertas' => 0, 'execucao' => 0, 'concluidas_mes' => 0];

$badgeSt = static fn(string $s): string => match ($s) {
    'concluida'   => '<span class="vbadge vb-ok">Concluída</span>',
    'em_execucao' => '<span class="vbadge vb-warn">Em execução</span>',
    'cancelada'   => '<span class="vbadge vb-off">Cancelada</span>',
    default       => '<span class="vbadge vb-info">Aberta</span>',
};

$GUARD      = ['macro' => 'agricola', 'micro' => 'ordens_servico'];
$PAGE_VIEW  = 'agricola_ordens_servico';
$PAGE_TITLE = 'Ordens de Serviço';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Ordens de Serviço — fila de trabalho',
        'A OS é a projeção numerada da atividade planejada (1:1) — criada e sincronizada pelo Planejamento de Atividades. Aplicações têm a própria OS: a DF/IF.',
        null) ?>


  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1">
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach (STATUS_OS as $k => $rotulo): ?>
            <option value="<?= $k ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= h($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span style="display:flex;gap:8px;align-items:center">
        <span class="vsub"><?= count($rows) ?> OS</span>
      </span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma ordem de serviço — planeje uma atividade e a OS nasce numerada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>OS</th><th>Trabalho</th><th><?= h(vero_a1_rotulo_area()) ?></th>
        <th>Planejada p/</th><th style="text-align:right">Custo previsto</th>
        <th style="text-align:right">Execuções</th><th>Status</th>
        <th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td><strong class="vnum"><?= h($r['numero']) ?></strong></td>
          <td><?= h($r['atividade'] ?? '—') ?>
            <?php if ($r['tipo_atividade']): ?><br><span class="vhint"><?= h($r['tipo_atividade']) ?><?= $r['responsavel'] ? ' · ' . h($r['responsavel']) : '' ?></span><?php endif; ?>
            <?php /* R12-B4: trilha da OS-espelho de apontamento (C-15) cancelada na
                     exclusão do apontamento — cancelada + sem atividade + 0 execuções
                     (o schema da OS não tem coluna observacao; atividade planejada
                     nunca é apagada fisicamente, só cancelada, então a assinatura é
                     exclusiva deste fluxo). */ ?>
            <?php if ((string)$r['status'] === 'cancelada' && empty($r['atividade_id']) && !(int)$r['execucoes']): ?>
              <br><span class="vhint">cancelada: apontamento de origem excluído</span>
            <?php endif; ?></td>
          <td><?= h(trim(($r['fazenda'] ?? '') . ' — ' . ($r['talhao'] ?? ''), ' —') ?: '—') ?>
            <?= $r['area_prevista_ha'] !== null ? '<span class="vhint"> (' . numFmt((float)$r['area_prevista_ha'], 2) . ' ha)</span>' : '' ?></td>
          <td class="vnum"><?= $r['data_planejada'] ? date('d/m/Y', strtotime((string)$r['data_planejada'])) : ($r['data_abertura'] ? date('d/m/Y', strtotime((string)$r['data_abertura'])) : '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $r['custo_previsto'] !== null ? 'R$ ' . numFmt((float)$r['custo_previsto'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['execucoes'] ?><?= $r['ultima_execucao'] ? '<br><span class="vhint">últ. ' . date('d/m', strtotime((string)$r['ultima_execucao'])) . '</span>' : '' ?></td>
          <td><?= $badgeSt((string)$r['status']) ?></td>
          <td><div class="vactions">
            <?= vero_btn_icone(vero_ico_imprimir(), 'Imprimir OS', "window.open('" . BIOS_BASE . "/agro/os_impressao?id=" . (int)$r['id'] . "','_blank')") ?>
            <?php if ($r['atividade_id']): ?>
              <?= vero_btn_icone(vero_ico_lapis(), 'Ver atividade', '', BIOS_BASE . '/agro/atividades?editar=' . (int)$r['atividade_id']) ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vhint" style="padding:6px 4px">
    Status, datas e conteúdo vêm da atividade. Execuções = apontamentos
    vinculados à atividade (gravam a OS automaticamente). OS antigas sem atividade seguem visíveis como histórico.
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
