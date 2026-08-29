<?php
/* ============================================================
   VERO — Pessoas / Premiação — Templates (padrão sugerido)  (CRUD real)
   Tela nova. Rota da matriz: /pessoas/premiacao.php
   Guard: pessoas.premiacao | Escrita: pessoas.premiacao.editar/excluir
   Tabela: rh_regras_premiacao (migration 130)

   REWORK 5.1/5.3 (reunião 16/07, decisão A0 em DECISIONS): a regra deixa de
   ser VINCULANTE e passa a ser o PADRÃO SUGERIDO ("template"). A meta/valor
   efetivos ficam POR LINHA no apontamento/OS (rh_producao_itens já guarda
   meta_aplicada/valor_unitario por linha, snapshot) e podem ser ajustados na
   hora — aqui só se define o ponto de partida. "Reduzir a 2 templates" (5.3):
   basta 1 template por CATEGORIA (trato_cultural → poda; colheita); as demais
   atividades HERDAM por categoria (motor `premiacao_sugestao`, _premiacao.php).
   meta_qtd/valor NÃO são dropados (decisão A0: aditivo, sem drop). O bloqueio
   de regra ativa sobreposta segue — evita default ambíguo.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'rh_regras_premiacao';

const UNIDADES = [
    'planta' => 'Planta', 'caixa' => 'Caixa', 'kg' => 'kg', 'ha' => 'ha',
    'metro_linear' => 'Metro linear', 'hora' => 'Hora', 'outro' => 'Outra',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('pessoas.premiacao.editar');

        $id        = vero_int('id');
        $tipoId    = vero_int('tipo_atividade_id');
        $culturaId = vero_int('cultura_id'); // null = todas
        $unidade   = vero_str('unidade', 20);
        $vigIni    = vero_date('vigencia_inicio');
        $vigFim    = vero_date('vigencia_fim');

        /* meta/valor VOLTARAM como PADRÃO SUGERIDO (editável): a calculadora de MO usa a
           meta p/ dimensionar a equipe (pessoas = total ÷ prazo ÷ meta); no apontamento
           meta e valor ainda podem ser ajustados por linha (snapshot em rh_producao_itens). */
        $metaQtd   = vero_dec('meta_qtd');
        $valorMeta = vero_dec('valor_acima_meta');
        if (!$tipoId || $unidade === null || !isset(UNIDADES[$unidade])) {
            vero_flash('erro', 'Atividade e unidade são obrigatórias.');
            vero_redirect();
        }
        if (($metaQtd !== null && $metaQtd < 0) || ($valorMeta !== null && $valorMeta < 0)) {
            vero_flash('erro', 'Meta e valor da premiação não podem ser negativos.');
            vero_redirect();
        }
        if ($vigIni !== null && $vigFim !== null && $vigFim < $vigIni) {
            vero_flash('erro', 'O fim da vigência não pode ser anterior ao início.');
            vero_redirect();
        }
        $okTipo = vero_val("SELECT id FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t",
            [':i' => $tipoId, ':t' => vero_tenant()]);
        if (!$okTipo) {
            vero_flash('erro', 'Tipo de atividade inválido.');
            vero_redirect();
        }
        if ($culturaId !== null) {
            $okCult = vero_val("SELECT id FROM agro_culturas WHERE id=:c AND tenant_id=:t",
                [':c' => $culturaId, ':t' => vero_tenant()]);
            if (!$okCult) {
                vero_flash('erro', 'Cultura inválida.');
                vero_redirect();
            }
        }
        /* impede regra ativa concorrente (mesma atividade×cultura, vigência sobreposta;
           vigência aberta conta como infinita) */
        $conflito = vero_val(
            "SELECT id FROM " . T . "
              WHERE tenant_id = :t AND tipo_atividade_id = :a AND ativo = 1 AND id <> :id
                AND ((:c1 IS NULL AND cultura_id IS NULL) OR cultura_id = :c2)
                AND (vigencia_inicio IS NULL OR :fim IS NULL OR vigencia_inicio <= :fim2)
                AND (vigencia_fim    IS NULL OR :ini IS NULL OR vigencia_fim    >= :ini2)",
            [':t' => vero_tenant(), ':a' => $tipoId, ':id' => (int)$id,
             ':c1' => $culturaId, ':c2' => $culturaId,
             ':fim' => $vigFim, ':fim2' => $vigFim, ':ini' => $vigIni, ':ini2' => $vigIni]
        );
        if ($conflito) {
            vero_flash('erro', 'Já existe uma regra ativa para esta atividade e cultura com vigência sobreposta. Encerre a vigência da regra anterior ou inative-a.');
            vero_redirect();
        }

        $data = [
            'tipo_atividade_id' => $tipoId,
            'cultura_id'        => $culturaId,
            'unidade'           => $unidade,
            'meta_qtd'          => $metaQtd,
            'valor_acima_meta'  => $valorMeta,
            'vigencia_inicio'   => $vigIni,
            'vigencia_fim'      => $vigFim,
            'ativo'             => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', 'Regra de premiação atualizada.');
        } else {
            vero_insert(T, $data);
            vero_flash('ok', 'Regra de premiação cadastrada.');
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('pessoas.premiacao.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fTipo   = (int)($_GET['tipo'] ?? 0);
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "r.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fTipo > 0) {
    $where .= " AND r.tipo_atividade_id = :a";
    $params[':a'] = $fTipo;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " r WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT r.*, ta.nome AS atividade_nome, c.nome AS cultura_nome
       FROM " . T . " r
       JOIN agro_tipos_atividade ta ON ta.id = r.tipo_atividade_id
       LEFT JOIN agro_culturas c ON c.id = r.cultura_id
      WHERE {$where}
      ORDER BY r.ativo DESC, ta.nome, c.nome, r.vigencia_inicio DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$tipos    = vero_options('agro_tipos_atividade', 'nome', 'ativo = 1');
$culturas = vero_options('agro_culturas', 'nome', 'ativo = 1');

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'pessoas', 'micro' => 'premiacao'];
$PAGE_VIEW  = 'pessoas_premiacao';
$PAGE_TITLE = 'Premiação — Templates';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('pessoas.premiacao.editar');

$fmtData = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
/* decimal p/ exibição BR sem zeros à toa (evita "100.000" ser relido como 100 mil) */
$fmtNum  = static fn($v): string => ($v === null || $v === '')
    ? '' : rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ',');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Premiação — Parametrização', 'Define a meta (piso por pessoa/dia), o valor R$/unidade acima da meta e a unidade, por atividade/cultura. É o PADRÃO SUGERIDO — no apontamento meta e valor podem ser ajustados por linha; a calculadora de mão de obra usa a meta para dimensionar a equipe.',
        $podeEditar ? '+ Novo template' : null) ?>

<?php /* hint "1 template por categoria" RETIRADO a pedido do gestor (18/08) —
         a herança por categoria continua valendo, só sai o texto da tela. */ ?>
  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Todas as atividades</option>
          <?php foreach ($tipos as $tid => $tn): ?>
            <option value="<?= $tid ?>"<?= $fTipo === $tid ? ' selected' : '' ?>><?= h($tn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">
        Nenhum template de premiação cadastrado.
        Ex.: Poda — meta padrão 100 plantas/diária, R$ 1,20 por planta acima da meta (ajustável por linha no apontamento).
      </div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Atividade</th><th>Cultura</th><th>Unidade</th>
        <th class="vnum">Meta</th><th class="vnum">R$/un. acima</th>
        <th>Vigência</th><th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['atividade_nome']) ?></strong></td>
          <td><?= $r['cultura_nome'] !== null ? h($r['cultura_nome']) : '<span class="vhint">Todas</span>' ?></td>
          <td><?= h(UNIDADES[$r['unidade']] ?? $r['unidade']) ?></td>
          <td class="vnum"><?= $fmtNum($r['meta_qtd']) !== '' ? $fmtNum($r['meta_qtd']) : '<span class="vhint">—</span>' ?></td>
          <td class="vnum"><?= $fmtNum($r['valor_acima_meta']) !== '' ? 'R$ ' . $fmtNum($r['valor_acima_meta']) : '<span class="vhint">—</span>' ?></td>
          <td class="vhint vnum"><?= $fmtData($r['vigencia_inicio']) ?> → <?= $fmtData($r['vigencia_fim']) ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('pessoas.premiacao.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar esta regra de premiação?') ?>
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
      <h2><?= $edit ? 'Editar template de premiação' : 'Novo template de premiação' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('tipo_atividade_id', 'Tipo de atividade', $tipos, $edit['tipo_atividade_id'] ?? ($fTipo ?: null), true) ?>
        <?= vero_f_select('cultura_id', 'Cultura', $culturas, $edit['cultura_id'] ?? null, false, '— Todas as culturas —') ?>
        <?= vero_f_select('unidade', 'Unidade de produção', UNIDADES, $edit['unidade'] ?? 'planta', true, 'Unidade em que a meta e o prêmio são medidos (planta/caixa…).') ?>
        <?= vero_f_text('meta_qtd', 'Meta padrão (por pessoa/dia)', $fmtNum($edit['meta_qtd'] ?? ''), false, 'Piso por diária. A calculadora dimensiona a equipe por este número (pessoas = total ÷ prazo ÷ meta). Ajustável por linha no apontamento.') ?>
        <?= vero_f_text('valor_acima_meta', 'Valor da premiação (R$/un. acima da meta)', $fmtNum($edit['valor_acima_meta'] ?? ''), false, 'R$ por unidade produzida acima da meta. Ex.: R$ 1,20 por planta.') ?>
        <div class="vfield">
          <label>Início da vigência</label>
          <input type="date" name="vigencia_inicio" value="<?= h($edit['vigencia_inicio'] ?? '') ?>">
          <div class="vhint">Vazio = sem limite</div>
        </div>
        <div class="vfield">
          <label>Fim da vigência</label>
          <input type="date" name="vigencia_fim" value="<?= h($edit['vigencia_fim'] ?? '') ?>">
          <div class="vhint">Vazio = sem limite</div>
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativa', 0 => 'Inativa'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vhint" style="margin-top:8px">
        Este é o <strong>padrão sugerido</strong> — no apontamento, meta e valor podem ser ajustados por linha (a premiação é gravada por linha).
        Exemplo: realizado 130, meta 100, R$ 1,20/planta → prêmio (130−100) × 1,20 = R$ 36,00.
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
