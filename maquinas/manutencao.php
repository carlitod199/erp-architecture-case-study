<?php
/* ============================================================
   VERO — Máquinas / Manutenções (OS)  (tela real)
   Rota: /maquinas/manutencao.php
   Guard: maquinas.manutencao_preventiva (tela única cobre
   preventiva e corretiva — o tipo é campo do lançamento)
   A2-F2-4 (DB-11): a manutenção vira ORDEM DE SERVIÇO com itens
   (peça do estoque OU serviço externo). Na transição para
   EXECUTADA as peças baixam do estoque ao custo médio (origem
   maquina_manutencao), o custo da OS é recalculado com o custo
   real e o plano preventivo vinculado é reprogramado (alertas
   categoria `maquinas` reemitidos). Cancelar OS executada
   estorna as baixas. OS executada não é editável — só cancelável.
   Custeio idempotente (origem maquina_manutencao) preservado,
   incluindo o plano_conta_id do A3-T10.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../custeio/_plano_map.php'; /* A3-T10: plano de contas no custeio */

const T = 'maquina_manutencoes';

const TIPOS_MAN = ['preventiva' => 'Preventiva', 'corretiva' => 'Corretiva'];
const STATUS_MAN = ['aberta' => 'Aberta', 'executada' => 'Executada', 'cancelada' => 'Cancelada'];

/** Reemite (ou remove) o lançamento de custeio da manutenção. */
function manut_reemitir_custeio(int $manId): void
{
    $t = vero_tenant();
    vero_pdo()->prepare("DELETE FROM custeio_lancamentos
                          WHERE tenant_id=? AND origem_tipo='maquina_manutencao' AND origem_id=?")
        ->execute([$t, $manId]);
    $man = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t", [':i' => $manId, ':t' => $t]);
    if ($man && $man['status'] === 'executada' && (float)$man['custo'] > 0) {
        vero_insert('custeio_lancamentos', [
            'centro_custo_id' => vero_srv_centro_custo('MAQ', 'Máquinas'),
            'plano_conta_id'  => custeio_plano_conta_id('maquina_manutencao'),
            'categoria'       => 'maquinas',
            'origem_tipo'     => 'maquina_manutencao',
            'origem_id'       => $manId,
            'valor'           => round((float)$man['custo'], 2),
            'data_competencia'=> (string)$man['data_manutencao'],
        ]);
    }
}

/** Baixa as peças da OS no estoque (transição → executada). Retorna custo total real dos itens. */
function manut_baixar_pecas(int $manId, string $data, bool $permitirVencido = false): float
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $itens = vero_rows("SELECT * FROM maquina_manutencao_itens WHERE tenant_id=:t AND manutencao_id=:m",
        [':t' => $t, ':m' => $manId]);
    $total = 0.0;
    foreach ($itens as $it) {
        if ($it['tipo'] === 'peca' && $it['produto_id'] !== null) {
            /* P-23/A0-10: lote vencido no FEFO exige confirmação explícita */
            $s = vero_srv_estoque_saida((int)$it['produto_id'], vero_srv_almox_padrao(),
                (float)$it['quantidade'], $data, 'maquina_manutencao', $manId,
                'Peça da OS de manutenção #' . $manId, null, null, $permitirVencido);
            $pdo->prepare("UPDATE maquina_manutencao_itens
                              SET mov_estoque_id=?, custo_unitario=?, valor_total=?, updated_at=NOW()
                            WHERE tenant_id=? AND id=?")
                ->execute([(int)$s['mov_id'], $s['custo_unitario'], $s['custo_total'], $t, (int)$it['id']]);
            $total += (float)$s['custo_total'];
        } else {
            $total += (float)$it['valor_total'];
        }
    }
    return round($total, 2);
}

/** Estorna as baixas de estoque da OS (cancelamento de OS executada). */
function manut_estornar_pecas(int $manId): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();
    $itens = vero_rows("SELECT * FROM maquina_manutencao_itens
                         WHERE tenant_id=:t AND manutencao_id=:m AND mov_estoque_id IS NOT NULL",
        [':t' => $t, ':m' => $manId]);
    foreach ($itens as $it) {
        $mov = vero_row("SELECT * FROM estoque_movimentacoes WHERE id=:i AND tenant_id=:t",
            [':i' => (int)$it['mov_estoque_id'], ':t' => $t]);
        if ($mov) vero_srv_estoque_estornar_mov($mov);
        $pdo->prepare("UPDATE maquina_manutencao_itens SET mov_estoque_id=NULL, updated_at=NOW()
                        WHERE tenant_id=? AND id=?")->execute([$t, (int)$it['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('maquinas.manutencao_preventiva.editar');

        $id        = vero_int('id');
        $maquinaId = vero_int('maquina_id');
        $tipo      = vero_str('tipo', 15);
        $data      = vero_date('data_manutencao');
        $statusMan = vero_str('status', 15) ?? 'aberta';
        $horimetro = vero_dec('horimetro');
        $planoId   = vero_int('plano_id');
        $fornId    = vero_int('fornecedor_id');

        $okMaq = $maquinaId ? vero_val("SELECT id FROM maquinas WHERE id=:i AND tenant_id=:t",
            [':i' => $maquinaId, ':t' => vero_tenant()]) : null;
        if (!$okMaq || $tipo === null || !isset(TIPOS_MAN[$tipo]) || $data === null || !isset(STATUS_MAN[$statusMan])) {
            vero_flash('erro', 'Máquina, tipo, data e status válidos são obrigatórios.');
            vero_redirect();
        }
        if ($planoId) {
            $ok = vero_val("SELECT id FROM maquina_planos_manutencao WHERE id=:i AND tenant_id=:t AND maquina_id=:m",
                [':i' => $planoId, ':t' => vero_tenant(), ':m' => $maquinaId]);
            if (!$ok) $planoId = null;
        }
        if ($fornId) {
            $ok = vero_val("SELECT id FROM fornecedores WHERE id=:i AND tenant_id=:t",
                [':i' => $fornId, ':t' => vero_tenant()]);
            if (!$ok) $fornId = null;
        }

        /* itens da OS: peça (produto+qtd) ou serviço (descrição+valor) */
        $iTipo = (array)($_POST['i_tipo'] ?? []);
        $iProd = (array)($_POST['i_produto'] ?? []);
        $iDesc = (array)($_POST['i_descricao'] ?? []);
        $iQtd  = (array)($_POST['i_qtd'] ?? []);
        $iVal  = (array)($_POST['i_valor'] ?? []);
        $parseDec = static function ($v): float {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };
        $itens = [];
        foreach ($iTipo as $ix => $tp) {
            $tp = $tp === 'servico' ? 'servico' : 'peca';
            if ($tp === 'peca') {
                $prodId = (int)($iProd[$ix] ?? 0);
                $qtd = $parseDec($iQtd[$ix] ?? '');
                if (!$prodId || $qtd <= 0) continue;
                $prod = vero_row("SELECT id, nome FROM estoque_produtos WHERE id=:i AND tenant_id=:t",
                    [':i' => $prodId, ':t' => vero_tenant()]);
                if (!$prod) continue;
                $itens[] = ['tipo' => 'peca', 'produto_id' => $prodId, 'descricao' => mb_substr((string)$prod['nome'], 0, 180),
                            'quantidade' => $qtd, 'custo_unitario' => 0, 'valor_total' => 0];
            } else {
                $desc = trim((string)($iDesc[$ix] ?? ''));
                $val = $parseDec($iVal[$ix] ?? '');
                if ($desc === '' || $val <= 0) continue;
                $itens[] = ['tipo' => 'servico', 'produto_id' => null, 'descricao' => mb_substr($desc, 0, 180),
                            'quantidade' => 1, 'custo_unitario' => $val, 'valor_total' => $val];
            }
        }

        $custoManut = vero_dec('custo') ?? 0;
        if ($custoManut < 0) { /* A11: custo de manutenção nunca é negativo */
            vero_flash('erro', 'O custo da manutenção não pode ser negativo.');
            vero_redirect();
        }
        $dados = [
            'maquina_id'      => $maquinaId,
            'tipo'            => $tipo,
            'descricao'       => vero_str('descricao', 255),
            'custo'           => $custoManut,
            'data_manutencao' => $data,
            'status'          => $statusMan,
            'horimetro'       => $horimetro,
            'plano_id'        => $planoId,
            'fornecedor_id'   => $fornId,
        ];

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $atual = vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$atual) throw new RuntimeException('Manutenção inválida.');
                if ($atual['status'] !== 'aberta') {
                    throw new RuntimeException('OS ' . $atual['status'] . ' não pode ser editada — cancele-a para estornar (executada) ou crie outra.');
                }
                vero_update(T, $id, $dados);
                $pdo->prepare("DELETE FROM maquina_manutencao_itens WHERE tenant_id=? AND manutencao_id=?")
                    ->execute([vero_tenant(), $id]);
                $manId = $id;
            } else {
                $manId = vero_insert(T, $dados);
            }
            foreach ($itens as $it) {
                $pdo->prepare("INSERT INTO maquina_manutencao_itens
                               (tenant_id, manutencao_id, tipo, produto_id, descricao, quantidade, custo_unitario, valor_total)
                               VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([vero_tenant(), $manId, $it['tipo'], $it['produto_id'], $it['descricao'],
                               $it['quantidade'], $it['custo_unitario'], $it['valor_total']]);
            }

            if ($statusMan === 'executada') {
                /* baixa peças ao custo médio real e consolida o custo da OS */
                $custoItens = manut_baixar_pecas((int)$manId, $data, vero_int('permitir_vencido') === 1);
                if ($itens) {
                    vero_update(T, (int)$manId, ['custo' => $custoItens]);
                } /* sem itens: vale o custo manual informado */

                /* horímetro da OS atualiza a máquina (não-regressão) + histórico */
                if ($horimetro !== null && $horimetro > 0) {
                    $maq = vero_row("SELECT * FROM maquinas WHERE id=:i AND tenant_id=:t",
                        [':i' => $maquinaId, ':t' => vero_tenant()]);
                    if ($maq && $horimetro >= (float)$maq['horimetro_atual']) {
                        vero_update('maquinas', $maquinaId, ['horimetro_atual' => $horimetro]);
                        vero_insert('maquina_horimetros', [
                            'maquina_id' => $maquinaId, 'data_leitura' => $data, 'horimetro' => $horimetro,
                        ]);
                    }
                }
                /* reprograma o plano vinculado */
                if ($planoId) {
                    vero_update('maquina_planos_manutencao', (int)$planoId, [
                        'horimetro_ultima' => $horimetro,
                        'data_ultima'      => $data,
                    ]);
                }
            }
            manut_reemitir_custeio((int)$manId);
            vero_srv_maquina_reemitir_alertas($maquinaId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (str_starts_with($e->getMessage(), 'LOTE_VENCIDO:')) {
                vero_flash('aviso', mb_substr($e->getMessage(), 13)
                    . ' Marque "Confirmo usar peça de lote vencido" na OS e reenvie.');
            } else {
                vero_flash('erro', 'Erro ao salvar: ' . h($e->getMessage()));
            }
            vero_redirect();
        }
        $custoFinal = (float)vero_val("SELECT custo FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => (int)$manId, ':t' => vero_tenant()]);
        vero_flash('ok', 'OS salva.' . ($statusMan === 'executada'
            ? ' Peças baixadas do estoque e custo de R$ ' . numFmt($custoFinal, 2) . ' lançado no custeio.' : ''));
        vero_redirect();
    }

    if ($acao === 'cancelar') {
        vero_require('maquinas.manutencao_preventiva.editar');
        $id = vero_int('id');
        $man = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if (!$man || $man['status'] === 'cancelada') {
            vero_flash('erro', 'OS inválida ou já cancelada.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($man['status'] === 'executada') manut_estornar_pecas((int)$id);
            vero_update(T, (int)$id, ['status' => 'cancelada']);
            manut_reemitir_custeio((int)$id);
            vero_srv_maquina_reemitir_alertas((int)$man['maquina_id']);
            $pdo->commit();
            vero_flash('ok', 'OS cancelada' . ($man['status'] === 'executada'
                ? ' — peças devolvidas ao estoque e custeio removido.' : '.')
                . ($man['plano_id'] !== null && $man['status'] === 'executada'
                    ? ' Atenção: a referência do plano NÃO foi revertida — ajuste manualmente se preciso.' : ''));
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao cancelar: ' . h($e->getMessage()));
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('maquinas.manutencao_preventiva.excluir');
        $id = vero_int('id');
        $man = $id ? vero_row("SELECT * FROM " . T . " WHERE id=:i AND tenant_id=:t",
            [':i' => $id, ':t' => vero_tenant()]) : null;
        if ($man) {
            if ($man['status'] === 'executada') {
                vero_flash('erro', 'OS executada não pode ser excluída — cancele primeiro (estorna as peças).');
                vero_redirect();
            }
            $pdo = vero_pdo();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM custeio_lancamentos
                                WHERE tenant_id=? AND origem_tipo='maquina_manutencao' AND origem_id=?")
                    ->execute([vero_tenant(), (int)$id]);
                $pdo->prepare("DELETE FROM maquina_manutencao_itens WHERE tenant_id=? AND manutencao_id=?")
                    ->execute([vero_tenant(), (int)$id]);
                $pdo->prepare("DELETE FROM " . T . " WHERE tenant_id=? AND id=?")
                    ->execute([vero_tenant(), (int)$id]);
                $pdo->commit();
                vero_flash('ok', 'OS excluída.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                vero_flash('erro', 'Erro ao excluir: ' . h($e->getMessage()));
            }
        }
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$fMaquina = (int)($_GET['maquina'] ?? 0);
$fStatus  = (string)($_GET['status'] ?? '');
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 20;

$where  = "mn.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($fMaquina > 0) { $where .= " AND mn.maquina_id = :m"; $params[':m'] = $fMaquina; }
if (isset(STATUS_MAN[$fStatus])) { $where .= " AND mn.status = :s"; $params[':s'] = $fStatus; }

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " mn WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT mn.*, m.codigo AS maq_codigo, m.nome AS maq_nome,
            pl.descricao AS plano_desc, f.nome AS oficina,
            (SELECT COUNT(*) FROM maquina_manutencao_itens mi
              WHERE mi.tenant_id = mn.tenant_id AND mi.manutencao_id = mn.id) AS n_itens
       FROM " . T . " mn
       JOIN maquinas m ON m.id = mn.maquina_id
       LEFT JOIN maquina_planos_manutencao pl ON pl.id = mn.plano_id
       LEFT JOIN fornecedores f ON f.id = mn.fornecedor_id
      WHERE {$where}
      ORDER BY mn.data_manutencao DESC, mn.id DESC
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}", $params);

$maquinas = vero_options('maquinas', 'nome', 'ativo = 1');
$fornecedoresOpt = vero_options('fornecedores', 'nome', 'ativo = 1');
$planosRows = vero_rows(
    "SELECT pl.id, pl.maquina_id, CONCAT(m.codigo, ' — ', pl.descricao) AS rotulo
       FROM maquina_planos_manutencao pl JOIN maquinas m ON m.id = pl.maquina_id
      WHERE pl.tenant_id = :t AND pl.ativo = 1 ORDER BY m.codigo, pl.descricao", [':t' => vero_tenant()]);
$produtos = vero_rows("SELECT id, codigo, nome, unidade FROM estoque_produtos
                        WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => vero_tenant()]);

$edit = null;
$editItens = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        $editItens = vero_rows("SELECT * FROM maquina_manutencao_itens WHERE tenant_id=:t AND manutencao_id=:m ORDER BY id",
            [':t' => vero_tenant(), ':m' => (int)$edit['id']]);
    }
}

$GUARD      = ['macro' => 'maquinas', 'micro' => 'manutencao_preventiva'];
$PAGE_VIEW  = 'maquinas_manutencao_preventiva';
$PAGE_TITLE = 'Manutenções';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('maquinas.manutencao_preventiva.editar');
/* P-75 (CSO): custo (R$) das OS só com o proxy financeiro. Form de edição intacto. */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Manutenções (OS)', 'OS com peças do estoque e serviços — executada baixa estoque e lança custo; planos preventivos disparam alertas',
        $podeEditar ? '+ Nova OS' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="maquina" onchange="this.form.submit()">
          <option value="">Todas as máquinas</option>
          <?php foreach ($maquinas as $mid => $mn): ?>
            <option value="<?= $mid ?>"<?= $fMaquina === $mid ? ' selected' : '' ?>><?= h($mn) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
          <option value="">Todos os status</option>
          <?php foreach (STATUS_MAN as $sk => $sl): ?>
            <option value="<?= $sk ?>"<?= $fStatus === $sk ? ' selected' : '' ?>><?= $sl ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/planos_manutencao.php">Planos preventivos</a>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma manutenção registrada.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Data</th><th>Máquina</th><th>Tipo</th><th>Descrição</th>
        <th style="text-align:right">Itens</th>
        <th style="text-align:right">Custo (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_manutencao'])) ?>
            <?= $r['horimetro'] !== null ? '<div class="vhint">' . numFmt((float)$r['horimetro'], 1) . ' h</div>' : '' ?></td>
          <td><strong><?= h($r['maq_codigo'] . ' — ' . $r['maq_nome']) ?></strong>
            <?= $r['plano_desc'] ? '<div class="vhint">plano: ' . h($r['plano_desc']) . '</div>' : '' ?>
            <?= $r['oficina'] ? '<div class="vhint">oficina: ' . h($r['oficina']) . '</div>' : '' ?></td>
          <td><span class="vbadge <?= $r['tipo'] === 'preventiva' ? 'vb-info' : 'vb-warn' ?>">
            <?= h(TIPOS_MAN[$r['tipo']] ?? $r['tipo']) ?></span></td>
          <td class="vhint"><?= h(mb_substr((string)($r['descricao'] ?? ''), 0, 60)) ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['n_itens'] ?></td>
          <td class="vnum" style="text-align:right"><strong><?= $veCusto ? numFmt((float)$r['custo'], 2) : '•••' ?></strong></td>
          <td><?= match ($r['status']) {
                'aberta'    => '<span class="vbadge vb-warn">Aberta</span>',
                'executada' => '<span class="vbadge vb-ok">Executada</span>',
                default     => '<span class="vbadge vb-off">Cancelada</span>',
          } ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar && $r['status'] === 'aberta'): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if ($podeEditar && $r['status'] !== 'cancelada'): ?>
              <form method="post" data-confirm="Cancelar esta OS?<?= $r['status'] === 'executada' ? ' As peças serão devolvidas ao estoque e o custeio removido.' : '' ?>" data-confirm-danger data-confirm-ok="Cancelar OS" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
                <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
                <input type="hidden" name="acao" value="cancelar">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="vicon vicon-del" type="submit" title="Cancelar OS" aria-label="Cancelar OS"><?= vero_ico_x() ?></button>
              </form>
            <?php endif; ?>
            <?php if (vero_can('maquinas.manutencao_preventiva.excluir') && $r['status'] !== 'executada'): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta OS?') ?>
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
  <div class="vbox" style="max-width:820px">
    <header>
      <h2><?= $edit ? 'Editar OS (aberta)' : 'Nova OS de manutenção' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <?= vero_f_select('maquina_id', 'Máquina', $maquinas, $edit['maquina_id'] ?? null, true) ?>
        <?= vero_f_select('tipo', 'Tipo', TIPOS_MAN, $edit['tipo'] ?? 'corretiva', true, '') ?>
        <div class="vfield">
          <label>Data *</label>
          <input type="date" name="data_manutencao" required
                 value="<?= h($edit ? (string)$edit['data_manutencao'] : date('Y-m-d')) ?>">
        </div>
        <?= vero_f_text('horimetro', 'Horímetro na OS',
              $edit && $edit['horimetro'] !== null ? numFmt((float)$edit['horimetro'], 1) : '', false,
              'atualiza a máquina e reprograma o plano ao executar') ?>
        <div class="vfield">
          <label>Plano preventivo (opcional)</label>
          <select name="plano_id" id="f-plano">
            <option value="">— Nenhum —</option>
            <?php foreach ($planosRows as $pl): ?>
              <option value="<?= (int)$pl['id'] ?>" data-maq="<?= (int)$pl['maquina_id'] ?>"
                <?= ($edit['plano_id'] ?? null) == $pl['id'] ? ' selected' : '' ?>><?= h($pl['rotulo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= vero_f_select('fornecedor_id', 'Oficina/fornecedor', $fornecedoresOpt, $edit['fornecedor_id'] ?? null, false, '— Interna —') ?>
        <?= vero_f_select('status', 'Status', STATUS_MAN, $edit['status'] ?? 'aberta', true, '') ?>
        <?= vero_f_text('custo', 'Custo manual (R$)', $edit ? numFmt((float)$edit['custo'], 2) : '', false,
              'usado só se a OS não tiver itens — com itens o custo é a soma real') ?>
        <div class="full"><?= vero_f_text('descricao', 'Descrição', $edit['descricao'] ?? '', false, 'Ex.: revisão 250h — óleo e filtros') ?></div>
      </div>

      <div style="margin-top:10px;padding:10px 12px;border:1px solid #E4D9C8;border-radius:8px">
        <div style="display:flex;align-items:center;gap:8px">
          <strong style="font-size:13px;flex:1">Itens da OS (peças do estoque · serviços externos)</strong>
          <button class="vbtn vbtn-ghost vbtn-sm" type="button" onclick="addItemOS()">+ Item</button>
        </div>
        <table class="vtable" style="margin-top:6px">
          <thead><tr><th style="width:110px">Tipo</th><th>Peça (estoque) ou serviço</th>
            <th style="width:100px;text-align:right">Qtd</th>
            <th style="width:120px;text-align:right">Valor (serviço)</th><th style="width:36px"></th></tr></thead>
          <tbody id="os-itens"></tbody>
        </table>
        <div class="vhint">Peça baixa do estoque AO CUSTO MÉDIO quando a OS for executada (movimento rastreável); cancelar a OS estorna. Serviço externo entra pelo valor informado.</div>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#6B5F53;margin-top:6px">
          <input type="checkbox" name="permitir_vencido" value="1" style="width:auto">
          Confirmo usar peça de lote VENCIDO se o FEFO alcançar um (P-23 — decisão do RT)
        </label>
      </div>

      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar OS</button>
      </div>
    </form>
  </div>
</div>
<script>
const OS_PRODUTOS = <?= jsvar(array_map(static fn($p) => [
    'id' => (int)$p['id'], 'nome' => $p['codigo'] . ' — ' . $p['nome'] . ' (' . $p['unidade'] . ')',
], $produtos)) ?>;
const OS_EDIT_ITENS = <?= jsvar(array_map(static fn($i) => [
    'tipo' => $i['tipo'], 'produto' => $i['produto_id'] !== null ? (int)$i['produto_id'] : null,
    'descricao' => $i['descricao'], 'qtd' => (float)$i['quantidade'], 'valor' => (float)$i['valor_total'],
], $editItens)) ?>;

function addItemOS(preset) {
  const tb = document.getElementById('os-itens');
  const tr = document.createElement('tr');
  const opts = ['<option value="">— peça… —</option>']
    .concat(OS_PRODUTOS.map(p => `<option value="${p.id}">${esc(p.nome)}</option>`)).join('');
  tr.innerHTML = `
    <td><select name="i_tipo[]" onchange="osTipoLinha(this)">
        <option value="peca">Peça</option><option value="servico">Serviço</option></select></td>
    <td><select name="i_produto[]" class="os-prod">${opts}</select>
        <input type="text" name="i_descricao[]" class="os-desc" placeholder="Descrição do serviço" style="display:none"></td>
    <td><input type="text" name="i_qtd[]" class="os-qtd" style="text-align:right" placeholder="1"></td>
    <td><input type="text" name="i_valor[]" class="os-val" style="text-align:right" placeholder="0,00" disabled></td>
    <td><button type="button" class="vclose" title="Remover" onclick="this.closest('tr').remove()">×</button></td>`;
  tb.appendChild(tr);
  if (preset) {
    tr.querySelector('[name="i_tipo[]"]').value = preset.tipo;
    osTipoLinha(tr.querySelector('[name="i_tipo[]"]'));
    if (preset.tipo === 'peca') {
      if (preset.produto) tr.querySelector('.os-prod').value = String(preset.produto);
      tr.querySelector('.os-qtd').value = String(preset.qtd).replace('.', ',');
    } else {
      tr.querySelector('.os-desc').value = preset.descricao || '';
      tr.querySelector('.os-val').value = preset.valor ? preset.valor.toLocaleString('pt-BR', {minimumFractionDigits: 2}) : '';
    }
  }
}
function osTipoLinha(sel) {
  const tr = sel.closest('tr');
  const peca = sel.value === 'peca';
  tr.querySelector('.os-prod').style.display = peca ? '' : 'none';
  tr.querySelector('.os-desc').style.display = peca ? 'none' : '';
  tr.querySelector('.os-qtd').disabled = !peca;
  tr.querySelector('.os-val').disabled = peca;
}
OS_EDIT_ITENS.forEach(i => addItemOS(i));
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
