<?php
/* ============================================================
   VERO — Estoque / Transferências  (tela real)
   Substitui o mock. Rota: /estoque/transferencias.php
   Guard: estoque.transferencias
   Transferência entre almoxarifados: saída na origem (FEFO) e
   entrada no destino ao MESMO custo unitário, atômicas — o custo
   médio global do produto não muda.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_audit.php'; /* A2-F2-19: ações críticas → auth_audit_logs */

$t = vero_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'transferir') {
        vero_require('estoque.transferencias.editar');
        $produtoId = vero_int('produto_id');
        $origemId  = vero_int('almox_origem');
        $destinoId = vero_int('almox_destino');
        $qtd       = vero_dec('quantidade');
        $data      = vero_date('data') ?? date('Y-m-d');
        if (!$produtoId || !$origemId || !$destinoId || $qtd === null || $qtd <= 0) {
            vero_flash('erro', 'Produto, origem, destino e quantidade (maior que zero) são obrigatórios.');
            vero_redirect();
        }
        if ($origemId === $destinoId) {
            vero_flash('erro', 'Origem e destino não podem ser o mesmo almoxarifado.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* Sprint Zero packing #2: transferência PRESERVA o lote (código, validade
               e colheita de origem viajam para o destino), em vez de perder o lote no
               destino como antes. P-23/A0-10: lote vencido no FEFO exige confirmação. */
            $res = vero_srv_estoque_transferir($produtoId, $origemId, $destinoId, (float)$qtd, $data,
                vero_int('permitir_vencido') === 1);
            $pdo->commit();
            vero_flash('ok', 'Transferência concluída — ' . numFmt((float)$qtd, 2) .
                ' un. ao custo de R$ ' . numFmt((float)$res['custo_unitario'], 4) . '/un. (lote preservado).');
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (str_starts_with($e->getMessage(), 'LOTE_VENCIDO:')) {
                vero_flash('aviso', mb_substr($e->getMessage(), 13)
                    . ' Marque "Confirmo que estou transferindo um lote vencido." e reenvie.');
            } elseif (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', $e->getMessage());
            }
        }
        vero_redirect();
    }

    /* A2-F2-16 (EST-003): estorno da TRANSFERÊNCIA estorna o PAR (saída origem +
       entrada destino) em UMA transação, com motivo obrigatório. Orquestrado na
       tela com 2 chamadas do service existente (sem tocar includes — C-07;
       service dedicado de par pode vir em pacote futuro do A0). Ordem: ENTRADA
       do destino primeiro (falha limpa se o destino já consumiu o saldo). */
    if ((string)($_POST['acao'] ?? '') === 'estornar_par') {
        /* ação destrutiva → alçada .excluir (ajuste A0 05/07; sem slug próprio de estorno) */
        if (!vero_can('estoque.transferencias.excluir')) {
            vero_flash('erro', 'Sem permissão para estornar transferências.');
            vero_redirect();
        }
        $movId  = vero_int('mov_id'); /* id da SAÍDA (registro primário do par) */
        $motivo = vero_str('motivo', 100);
        if (!$movId || $motivo === null) {
            vero_flash('erro', 'Transferência e MOTIVO do estorno são obrigatórios.');
            vero_redirect();
        }
        $saida = vero_row("SELECT * FROM estoque_movimentacoes
                            WHERE tenant_id=:t AND id=:i AND origem_tipo='transferencia' AND tipo='saida'",
            [':t' => $t, ':i' => $movId]);
        if (!$saida || $saida['estornado_em'] !== null) {
            vero_flash('erro', 'Transferência inválida ou já estornada.');
            vero_redirect();
        }
        $entrada = $saida['mov_ref_id'] !== null
            ? vero_row("SELECT * FROM estoque_movimentacoes WHERE tenant_id=:t AND id=:i AND tipo='entrada'",
                [':t' => $t, ':i' => (int)$saida['mov_ref_id']])
            : null;
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        $parQuebrado = !$entrada;
        try {
            if ($entrada && $entrada['estornado_em'] === null) {
                vero_srv_estoque_estornar_mov($entrada); /* tira do destino — valida saldo lá */
            }
            vero_srv_estoque_estornar_mov($saida);       /* devolve à origem */
            /* motivo anotado nos contras (mov_ref_id dos originais apontam p/ os contras) */
            $up = $pdo->prepare("UPDATE estoque_movimentacoes
                                    SET observacao = CONCAT(COALESCE(observacao,''), ' | Motivo: ', ?)
                                  WHERE tenant_id = ? AND id = (SELECT mov_ref_id FROM (SELECT mov_ref_id FROM estoque_movimentacoes WHERE tenant_id = ? AND id = ?) x)");
            $up->execute([$motivo, $t, $t, (int)$saida['id']]);
            if ($entrada) $up->execute([$motivo, $t, $t, (int)$entrada['id']]);
            $pdo->commit();
            estoque_audit('estoque_estorno_transferencia', "Estorno do PAR da transferência (saída #{$saida['id']}"
                . ($entrada ? " + entrada #{$entrada['id']}" : ', par legado ausente') . ") — motivo: {$motivo}");
            vero_flash('ok', 'Transferência estornada — saída e entrada revertidas em uma transação (motivo: '
                . h($motivo) . ').');
            if ($parQuebrado) {
                vero_flash('aviso', 'Par da transferência não encontrado (registro legado sem vínculo) — apenas a SAÍDA foi estornada; confira o destino manualmente.');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            if (!estoque_flash_guarda($e)) { /* PERIODO_FECHADO: orientado (EST-018) */
                vero_flash('erro', 'Estorno da transferência não realizado: ' . $e->getMessage()
                    . ' (nada foi revertido — a transação foi desfeita por inteiro).');
            }
        }
        vero_redirect();
    }
}

/* Estorno enxuto (A0): a tela mantém só as transferências RECENTES para permitir
   estornar o par (saída+entrada). A consulta/histórico completo foi para o
   relatório (Relatórios → Estoque → "Transferências entre almoxarifados").
   A SAÍDA é o registro primário (origem/destino/qtd/custo). */
$recentes = vero_rows(
    "SELECT mv.id, mv.data_movimento, mv.quantidade, mv.custo_unitario, mv.estornado_em,
            p.codigo AS prod_codigo, p.nome AS prod_nome, p.unidade,
            ao.nome AS almox_origem, ad.nome AS almox_destino
       FROM estoque_movimentacoes mv
       JOIN estoque_produtos p ON p.id = mv.produto_id
       LEFT JOIN almoxarifados ao ON ao.id = mv.almoxarifado_id
       LEFT JOIN almoxarifados ad ON ad.id = mv.almoxarifado_destino_id
      WHERE mv.tenant_id = :t AND mv.origem_tipo = 'transferencia' AND mv.tipo = 'saida'
      ORDER BY mv.data_movimento DESC, mv.id DESC LIMIT 10", [':t' => $t]);

$produtos = vero_rows(
    "SELECT p.id, p.codigo, p.nome, p.unidade FROM estoque_produtos p
      WHERE p.tenant_id = :t AND p.ativo = 1 ORDER BY p.nome", [':t' => $t]);
$almoxes = vero_rows("SELECT id, nome FROM almoxarifados WHERE tenant_id = :t AND ativo = 1 ORDER BY nome", [':t' => $t]);

/* saldos por produto×almox para orientar */
$saldos = vero_rows(
    "SELECT s.produto_id, s.almoxarifado_id, s.quantidade, p.codigo, p.nome AS produto, p.unidade, a.nome AS almox
       FROM estoque_saldos s
       JOIN estoque_produtos p ON p.id = s.produto_id
       LEFT JOIN almoxarifados a ON a.id = s.almoxarifado_id
      WHERE s.tenant_id = :t AND s.quantidade > 0
      ORDER BY p.nome, a.nome", [':t' => $t]);

$GUARD      = ['macro' => 'estoque', 'micro' => 'transferencias'];
$PAGE_VIEW  = 'estoque_transferencias';
$PAGE_TITLE = 'Transferências de Estoque';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.transferencias.editar');
?>
<?php
$saldoMap = [];
foreach ($saldos as $s) { $saldoMap[(int)$s['produto_id']][(int)$s['almoxarifado_id']] = [round((float)$s['quantidade'], 4), (string)$s['unidade']]; }
$base = rtrim(BIOS_BASE, '/');
?>
<style>
.tr-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px;padding:4px 16px 14px;align-items:end}
.tr-form .full{grid-column:1/-1}
.tr-saldo{font:600 12.5px 'IBM Plex Sans';color:#1E6B34;min-height:16px}
.tr-saldo.tr-sem{color:#9A3B2A}
.tr-venc{display:flex;gap:9px;align-items:flex-start;background:#FBF3E4;border:1px solid #E8D9A8;border-radius:10px;padding:10px 12px}
.tr-venc .aux{font-size:11.5px;color:#8A6D1A;margin-top:2px}
.tr-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tr-table{width:100%;border-collapse:collapse;min-width:720px}
.tr-table thead th{background:#F5F1E8;font:600 11px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.03em;color:#6B5F53;border-bottom:2px solid #E1D9C7;padding:10px 12px;text-align:left;white-space:nowrap}
.tr-table td{padding:9px 12px;border-bottom:1px solid #F0EBDF;vertical-align:middle}
.tr-table tbody tr:nth-child(4n-1){background:#FBFAF6}
.tr-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.tr-rota{display:inline-flex;align-items:center;gap:7px;font-size:12.5px}
.tr-rota .seta{color:#8A7D6E}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Transferências de Estoque', 'Move saldo entre almoxarifados sem alterar o custo médio do produto — a saída na origem sai por FEFO', null) ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Nova transferência</strong>
      <?php if (count($almoxes) < 2): ?>
        <span class="vsub">é preciso ter 2+ almoxarifados ativos — <a href="<?= $base ?>/estoque/almoxarifados.php">cadastrar</a></span>
      <?php endif; ?></div>
    <?php if (count($almoxes) >= 2 && $produtos): ?>
    <form class="vform tr-form" id="tr-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="transferir">
      <div class="vfield full"><label>Produto *</label>
        <select name="produto_id" required onchange="trSaldo()">
          <option value="">Selecione…</option>
          <?php foreach ($produtos as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= h($p['codigo'] . ' — ' . $p['nome'] . ' (' . $p['unidade'] . ')') ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Almoxarifado origem *</label>
        <select name="almox_origem" required onchange="trSaldo()">
          <option value="">Origem…</option>
          <?php foreach ($almoxes as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Almoxarifado destino *</label>
        <select name="almox_destino" required>
          <option value="">Destino…</option>
          <?php foreach ($almoxes as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['nome']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="vfield"><label>Quantidade *</label>
        <input type="text" name="quantidade" id="tr-qtd" placeholder="0,00" required inputmode="decimal" style="text-align:right"></div>
      <div class="vfield"><label>Data</label>
        <input type="date" name="data" value="<?= date('Y-m-d') ?>"></div>
      <div class="full"><div class="tr-saldo" id="tr-saldo"></div></div>
      <div class="full"><span class="vhint">O lote é escolhido automaticamente por <strong>FEFO</strong> (vence primeiro, sai primeiro) — não é preciso selecionar lote.</span></div>
      <label class="tr-venc full">
        <input type="checkbox" name="permitir_vencido" value="1" style="width:auto;margin-top:2px">
        <span>Confirmo que estou transferindo um lote vencido.<div class="aux">Essa ação ficará registrada para auditoria.</div></span>
      </label>
      <div class="full"><button class="vbtn vbtn-primary" type="submit">Transferir</button></div>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar">
      <strong style="white-space:nowrap;margin-right:4px">Transferências recentes</strong>
      <span class="vsub">últimas <?= count($recentes) ?> — para estornar</span>
      <div style="flex:1"></div>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/relatorios/relatorios_estoque.php">Histórico completo (relatório) →</a>
    </div>
    <?php if (!$recentes): ?>
      <div class="vempty">Nenhuma transferência registrada ainda.</div>
    <?php else: ?>
    <div class="tr-wrap">
    <table class="tr-table">
      <thead><tr>
        <th>Data</th><th>Produto</th><th>Rota (origem → destino)</th>
        <th class="tr-num">Qtd</th><th class="tr-num">Custo unit. (R$)</th>
        <th>Status</th><th class="tr-num">Estornar</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recentes as $m): $mid = (int)$m['id']; $estorn = $m['estornado_em'] !== null; ?>
        <tr<?= $estorn ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum" style="white-space:nowrap"><?= date('d/m/Y', strtotime((string)$m['data_movimento'])) ?></td>
          <td><strong class="vnum"><?= h($m['prod_codigo']) ?></strong><br><span style="font-size:12px"><?= h($m['prod_nome']) ?></span></td>
          <td><span class="tr-rota"><?= h($m['almox_origem'] ?? '—') ?> <span class="seta">→</span> <strong><?= h($m['almox_destino'] ?? '—') ?></strong></span></td>
          <td class="tr-num"><strong><?= numFmt((float)$m['quantidade'], 2) ?></strong> <span class="vhint"><?= h($m['unidade']) ?></span></td>
          <td class="tr-num"><?= numFmt((float)$m['custo_unitario'], 4) ?></td>
          <td><?= $estorn ? '<span class="vbadge vb-off">Estornada</span>' : '<span class="vbadge vb-ok">Concluída</span>' ?></td>
          <td class="tr-num"><div class="vactions" style="justify-content:flex-end">
            <?php if (!$estorn && vero_can('estoque.transferencias.excluir')): /* A2-F2-16: estorno do PAR (alçada .excluir) */ ?>
              <?= vero_btn_icone(vero_ico_voltar(), 'Estornar transferência',
                    "trEstornar(" . $mid . ", '" . h(addslashes($m['prod_codigo'] . ' · ' . numFmt((float)$m['quantidade'], 2) . ' ' . $m['unidade'])) . "')") ?>
            <?php else: ?>
              <span class="vhint">—</span>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<form method="post" id="tr-est-form" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
  <input type="hidden" name="acao" value="estornar_par">
  <input type="hidden" name="mov_id" id="tr-est-mov">
  <input type="hidden" name="motivo" id="tr-est-motivo">
</form>
<script>
function trEstornar(movId, resumo) {
  var motivo = prompt('Estornar a transferência (' + resumo + ')?\nSaída e entrada serão revertidas JUNTAS, em uma transação.\n\nMotivo do estorno (obrigatório):');
  if (motivo === null) return;
  motivo = motivo.trim();
  if (!motivo) { alert('O motivo é obrigatório.'); return; }
  document.getElementById('tr-est-mov').value = movId;
  document.getElementById('tr-est-motivo').value = motivo;
  document.getElementById('tr-est-form').submit();
}
var SALDOS = <?= jsvar($saldoMap) ?>;
function trSaldo(){
  var f = document.getElementById('tr-form'); if(!f) return;
  var pid = f.produto_id.value, oid = f.almox_origem.value;
  var el = document.getElementById('tr-saldo'), q = document.getElementById('tr-qtd');
  var info = (SALDOS[pid] || {})[oid];
  if(info){ el.textContent = 'Saldo disponível: ' + info[0].toLocaleString('pt-BR',{minimumFractionDigits:2}) + ' ' + info[1]; el.classList.remove('tr-sem'); q.dataset.max = info[0]; }
  else { el.textContent = (pid && oid) ? 'Sem saldo neste almoxarifado para o produto.' : ''; el.classList.add('tr-sem'); q.dataset.max = ''; }
}
(function(){
  var f = document.getElementById('tr-form'); if(!f) return;
  f.addEventListener('submit', function(e){
    var q = document.getElementById('tr-qtd');
    var v = parseFloat((q.value||'').replace(/\./g,'').replace(',','.'));
    if(!(v > 0)){ e.preventDefault(); alert('Informe uma quantidade maior que zero.'); return; }
    var max = parseFloat(q.dataset.max || '');
    if(max && v > max + 1e-9){ e.preventDefault(); alert('Quantidade (' + v + ') maior que o saldo disponível (' + max + ') no almoxarifado de origem.'); return; }
    if(f.almox_origem.value && f.almox_origem.value === f.almox_destino.value){ e.preventDefault(); alert('Origem e destino não podem ser o mesmo almoxarifado.'); }
  });
})();
</script>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
