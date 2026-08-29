<?php
/* ============================================================
   VERO — Custos / Rateios  (CRUD real)
   Substitui o mock. Rota: /custeio/rateios.php
   Guard: custos.rateios
   Regras de rateio de custos indiretos (custeio_rateios):
   base = área, produção, custo direto ou manual. A APLICAÇÃO
   automática do rateio nos lançamentos é etapa posterior —
   hoje a regra documenta o critério acordado com o cliente.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_atribuicao_sem_safra.php'; /* A3-T31 (NEG-01/P-98) */
require_once __DIR__ . '/_rateio_combustivel.php';   /* A3 combustível por horas (P-123/125) */

const BASES_RATEIO = [
    'area'         => 'Área (ha dos talhões)',
    'producao'     => 'Produção (kg colhidos)',
    'custo_direto' => 'Custo direto (proporcional ao já lançado)',
    'manual'       => 'Manual (percentuais definidos)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('custeio.rateios.editar');
        $id   = vero_int('id');
        $nome = vero_str('nome', 120);
        $baseR = (string)($_POST['base'] ?? 'area');
        if (!isset(BASES_RATEIO[$baseR])) $baseR = 'area';
        if ($nome === null) {
            vero_flash('erro', 'Nome da regra é obrigatório.');
            vero_redirect();
        }
        $obs = vero_str('config', 500);
        $data = [
            'nome'   => $nome,
            'base'   => $baseR,
            /* coluna JSON — texto livre vai encapsulado */
            'config' => $obs !== null ? json_encode(['observacao' => $obs], JSON_UNESCAPED_UNICODE) : null,
            'ativo'  => vero_int('ativo') ?? 1,
        ];
        if ($id) { vero_update('custeio_rateios', $id, $data); vero_flash('ok', "Regra \"{$nome}\" atualizada."); }
        else     { vero_insert('custeio_rateios', $data);      vero_flash('ok', "Regra \"{$nome}\" criada."); }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('custeio.rateios.excluir');
        $id = vero_int('id');
        if ($id) vero_delete('custeio_rateios', $id); // soft delete (tem `ativo`)
        vero_redirect();
    }

    if ($acao === 'atribuir_sem_safra') { /* A3-T31 — P-98 */
        vero_require('custeio.rateios.editar');
        try {
            $r = atrib_executar();
            vero_flash('ok', "Atribuição executada: {$r['atribuidos']} lançamento(s) → R$ " . numFmt($r['total'], 2)
                . " distribuídos ({$r['linhas']} linhas com memória de cálculo).");
            foreach ($r['pulados'] as $p) vero_flash('aviso', '⚠ Pulado: ' . $p);
        } catch (Throwable $e) {
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'desfazer_sem_safra') {
        vero_require('custeio.rateios.editar');
        $n = atrib_desfazer();
        vero_flash('ok', "Atribuições desfeitas ({$n} linha(s) de custeio removidas; execuções marcadas)." );
        vero_redirect();
    }

    if ($acao === 'ratear_combustivel') { /* P-123/125 — combustível por horas */
        vero_require('custeio.rateios.editar');
        try {
            $r = comb_executar();
            vero_flash('ok', "Combustível rateado: {$r['rateados']} abastecimento(s) → R$ " . numFmt($r['total'], 2)
                . " distribuídos por horas ({$r['linhas']} linhas com memória de cálculo).");
            foreach ($r['pulados'] as $p) vero_flash('aviso', '⚠ Pulado: ' . $p);
        } catch (Throwable $e) {
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect();
    }

    if ($acao === 'desfazer_combustivel') {
        vero_require('custeio.rateios.editar');
        $n = comb_desfazer();
        vero_flash('ok', "Rateios de combustível desfeitos ({$n} linha(s) removidas; execuções marcadas).");
        vero_redirect();
    }
}

$rows = vero_rows("SELECT * FROM custeio_rateios WHERE tenant_id = :t ORDER BY ativo DESC, nome",
    [':t' => vero_tenant()]);

$configTexto = static function (?string $json): string {
    if ($json === null || $json === '') return '';
    $dec = json_decode($json, true);
    if (is_array($dec)) return (string)($dec['observacao'] ?? json_encode($dec, JSON_UNESCAPED_UNICODE));
    return (string)$json;
};

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM custeio_rateios WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

/* A3-T31: painel dos lançamentos sem safra */
$pendentesSS = atrib_pendentes();
$totalPendSS = array_sum(array_map(static fn($p) => (float)$p['valor'], $pendentesSS));
$atribVigentes = (int)vero_val(
    "SELECT COUNT(*) FROM custeio_rateio_execucoes e
      WHERE e.tenant_id = :t AND e.status = 'aplicada' AND e.rateio_id = :r",
    [':t' => vero_tenant(), ':r' => atrib_regra_id()]);

/* Combustível por horas (P-123/125) */
$pendComb = comb_pendentes();
$totalPendComb = array_sum(array_map(static fn($p) => (float)$p['valor'], $pendComb));
$combVigentes = (int)vero_val(
    "SELECT COUNT(*) FROM custeio_rateio_execucoes e
      WHERE e.tenant_id = :t AND e.status = 'aplicada' AND e.rateio_id = :r",
    [':t' => vero_tenant(), ':r' => comb_regra_id()]);

$GUARD      = ['macro' => 'custos', 'micro' => 'rateios'];
$PAGE_VIEW  = 'custos_rateios';
$PAGE_TITLE = 'Rateios';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('custeio.rateios.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Rateios', 'Regras de distribuição de custos indiretos entre talhões/safras',
        $podeEditar ? '+ Nova regra' : null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div style="padding:15px 16px">
      <p style="margin:0 0 10px;font-size:13.5px;line-height:1.55;color:#2B2018">
        <strong>Ratear</strong> é distribuir os custos que não nasceram amarrados a um talhão ou safra —
        folha de pagamento, depreciação, energia, combustível — entre as safras e válvulas certas,
        para que o custo por hectare fique completo e justo.
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
        <div class="vhint" style="font-size:12px;line-height:1.5;margin:0"><strong style="color:#005059">1 · Lançamentos sem safra</strong><br>custos gerais do período → às safras ativas, por área plantada.</div>
        <div class="vhint" style="font-size:12px;line-height:1.5;margin:0"><strong style="color:#005059">2 · Combustível por horas</strong><br>abastecimentos → às válvulas, pelas horas de máquina.</div>
        <div class="vhint" style="font-size:12px;line-height:1.5;margin:0"><strong style="color:#005059">3 · Regras de rateio</strong><br>os critérios de rateio acordados com o cliente, documentados.</div>
      </div>
      <div class="vhint" style="margin-top:10px;font-size:11.5px">O lançamento original nunca é apagado: cada cota vira uma linha própria com memória de cálculo, e toda distribuição é reversível.</div>
    </div>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>1 · Lançamentos sem safra</strong>
      <span class="vhint">Custos gerais (folha, depreciação) que entraram sem safra: distribua-os às safras ativas do período, proporcional à área plantada.</span>
      <span style="flex:1"></span>
      <?php if ($podeEditar && $pendentesSS): ?>
      <form method="post" data-confirm="Atribuir <?= count($pendentesSS) ?> lançamento(s) (R$ <?= numFmt($totalPendSS, 2) ?>) às safras ativas do período?" data-confirm-ok="Atribuir" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="atribuir_sem_safra">
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Atribuir às safras do período</button>
      </form>
      <?php endif; ?>
      <?php if ($podeEditar && $atribVigentes > 0): ?>
      <form method="post" data-confirm="Desfazer TODAS as atribuições vigentes?" data-confirm-danger data-confirm-ok="Desfazer" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="desfazer_sem_safra">
        <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Desfazer atribuições</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (!$pendentesSS): ?>
      <div class="vempty">Nenhum lançamento sem safra pendente<?= $atribVigentes > 0 ? ' — ' . $atribVigentes . ' execução(ões) vigentes (memória de cálculo preservada)' : '' ?>. ✓</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>#</th><th>Competência</th><th>Origem</th><th>Categoria</th>
        <th style="text-align:right">Valor (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($pendentesSS as $p): ?>
        <tr>
          <td class="vnum"><?= (int)$p['id'] ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$p['data_competencia'])) ?></td>
          <td><?= h((string)$p['origem_tipo']) ?></td>
          <td><?= h((string)$p['categoria']) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$p['valor'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="4"><strong>Total pendente</strong></td>
        <td class="vnum" style="text-align:right"><strong><?= numFmt($totalPendSS, 2) ?></strong></td></tr>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">
      Funciona igual ao rateio por talhão: o lançamento original fica INTACTO, cada cota
      vira uma linha própria com memória de cálculo e um estorno sem safra anula o valor
      original — nenhuma consolidação precisa ser refeita. Aplicar de novo não duplica; desfazer reverte tudo.
    </div>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>2 · Combustível por horas</strong>
      <span class="vhint">Abastecimentos da máquina distribuídos às válvulas pelas horas apontadas no mês; se o abastecimento já está ligado a um apontamento, vai direto para a válvula dele.</span>
      <span style="flex:1"></span>
      <?php if ($podeEditar && $pendComb): ?>
      <form method="post" data-confirm="Ratear <?= count($pendComb) ?> abastecimento(s) (R$ <?= numFmt($totalPendComb, 2) ?>) por horas?" data-confirm-ok="Ratear" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="ratear_combustivel">
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Ratear por horas</button>
      </form>
      <?php endif; ?>
      <?php if ($podeEditar && $combVigentes > 0): ?>
      <form method="post" data-confirm="Desfazer TODOS os rateios de combustível?" data-confirm-danger data-confirm-ok="Desfazer" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="desfazer_combustivel">
        <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Desfazer rateios</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (!$pendComb): ?>
      <div class="vempty">Nenhum abastecimento pendente de rateio<?= $combVigentes > 0 ? ' — ' . $combVigentes . ' execução(ões) vigentes (memória preservada)' : '' ?>. ✓</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr><th>#</th><th>Competência</th><th>Máquina</th><th>Vínculo</th>
        <th style="text-align:right">Valor (R$)</th></tr></thead>
      <tbody>
      <?php foreach ($pendComb as $p): ?>
        <tr>
          <td class="vnum"><?= (int)$p['id'] ?></td>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$p['data_competencia'])) ?></td>
          <td class="vnum"><?= (int)$p['maquina_id'] ?></td>
          <td><?= $p['ab_apontamento_id'] !== null
                ? '<span class="vbadge vb-info">direto (apont. ' . (int)$p['ab_apontamento_id'] . ')</span>'
                : '<span class="vhint">rateio por horas</span>' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$p['valor'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="4"><strong>Total pendente</strong></td>
        <td class="vnum" style="text-align:right"><strong><?= numFmt($totalPendComb, 2) ?></strong></td></tr>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">
      O valor do abastecimento é rateado pelas horas apontadas da máquina no mês; cada apontamento recebe
      a parte proporcional às suas horas, direto na sua válvula. Um estorno sem safra anula o
      lançamento original. Se a máquina não tem horas em safra ativa no mês, o abastecimento é
      PULADO (reportado) em vez de forçar — combustível não é chutado por área. Aplicar de novo não duplica; é reversível.
    </div>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>3 · Regras de rateio (critérios)</strong>
      <span class="vhint">O critério de rateio acordado com o responsável pela gestão — use "+ Nova regra" (no topo) para cadastrar.</span></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma regra de rateio cadastrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Regra</th><th>Base de rateio</th><th>Configuração</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><span class="vbadge vb-info"><?= h(BASES_RATEIO[(string)$r['base']] ?? ucfirst((string)$r['base'])) ?></span></td>
          <td class="vhint"><?= h(mb_substr($configTexto($r['config']), 0, 70)) ?: '—' ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('custeio.rateios.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta regra de rateio?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div class="vhint" style="padding:10px 14px">
      A aplicação automática do rateio nos lançamentos de custeio é etapa posterior (junto ao fechamento
      de safra) — hoje a regra registra o critério validado com o responsável pela gestão.
    </div>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar regra' : 'Nova regra de rateio' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome da regra', $edit['nome'] ?? '', true, 'Ex.: Energia da sede por área') ?></div>
        <?= vero_f_select('base', 'Base de rateio', BASES_RATEIO, $edit['base'] ?? 'area', true, '') ?>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
        <div class="full"><?= vero_f_text('config', 'Configuração / observações', $edit ? $configTexto($edit['config']) : '', false,
            'Ex.: percentuais por talhão quando manual: 5A=60; 5B=40') ?></div>
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
