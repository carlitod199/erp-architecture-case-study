<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Relógio de Frio  (PAINEL)
   Rota: /packing/relogio_frio.php · Guard: packing.relogio_frio
   View: packing_relogio_frio
   ------------------------------------------------------------
   Fila de recepção da unidade ATIVA (ph_ctx), ordenada por MAIOR
   tempo desde a colheita (mais urgente primeiro). Cada item ganha
   um selo de cor (verde/amarelo/vermelho) segundo ph_relogio_status:
   verde <8h · amarelo 8-12h · vermelho >12h; janela de SO2 = 12h.
   Tela SOMENTE de leitura — nenhum POST, nenhuma escrita.
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_ph_services.php';   /* ph_ctx_* (contexto ativo do packing) */
require_once __DIR__ . '/_ph_recepcao.php';   /* ph_relogio_fila / ph_relogio_status  */

/* Contexto ativo (unidade = almoxarifado tipo='packing' em sessão). */
$ctxUni   = ph_ctx_get()['unidade_id'];
$uniAtual = ph_ctx_unidade_atual();

/* Fila só quando há unidade ativa; o serviço já escopa ao tenant. */
$fila = $ctxUni ? ph_relogio_fila((int)$ctxUni) : [];

/* Selo → classe do DS (vbadge = ponto+texto, sem pílula) + rótulo. */
$seloDe = static function (string $cor): array {
    return match ($cor) {
        'verde'    => ['cls' => 'vb-ok',   'rotulo' => 'No prazo'],
        'amarelo'  => ['cls' => 'vb-warn', 'rotulo' => 'Atenção'],
        'vermelho' => ['cls' => 'vb-off',  'rotulo' => 'Crítico'],
        default    => ['cls' => '',        'rotulo' => 'Sem dado'],
    };
};

$GUARD      = ['macro' => 'packing', 'micro' => 'relogio_frio'];
$PAGE_VIEW  = 'packing_relogio_frio';
$PAGE_TITLE = 'Relógio de Frio';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header(
        'Relógio de Frio',
        'Fila de recepção ordenada pela MAIOR espera desde a colheita — o mais urgente primeiro. Janela de aplicação de SO₂ = 12h; o selo indica o risco (verde &lt;8h · amarelo 8-12h · vermelho &gt;12h).',
        null) ?>

  <?php if (!$ctxUni): ?>
    <div class="vempty">Nenhuma unidade de packing ativa.
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= h(BIOS_BASE) ?>/packing/recepcao">Definir contexto</a></div>
  <?php elseif (!$fila): ?>
    <div class="vhint" style="margin-bottom:12px">Unidade ativa:
      <strong><?= $uniAtual ? h((string)$uniAtual['nome']) : '—' ?></strong></div>
    <div class="vempty">Nenhum item recebido na fila desta unidade.</div>
  <?php else: ?>
    <div class="vhint" style="margin-bottom:12px">Unidade ativa:
      <strong><?= $uniAtual ? h((string)$uniAtual['nome']) : '—' ?></strong>
      · <strong><?= count($fila) ?></strong> item(ns) na fila</div>

    <div class="vcard">
    <table class="vtable">
      <thead><tr>
        <th>Selo</th>
        <th>Romaneio</th>
        <th>Válvula</th>
        <th>Variedade</th>
        <th style="text-align:right">Peso (kg)</th>
        <th>Colhido em</th>
        <th style="text-align:right">Horas decorridas</th>
        <th style="text-align:right">Restante SO₂ (12h)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($fila as $linha):
          /* Recalcula o status pelo contrato (robusto a como a fila o mescla). */
          $colhidoEm = isset($linha['colhido_em']) ? (string)$linha['colhido_em'] : null;
          $status    = ph_relogio_status($colhidoEm !== '' ? $colhidoEm : null);
          $cor       = (string)($status['cor'] ?? 'sem_dado');
          $selo      = $seloDe($cor);
          $horas     = $status['horas'] ?? null;
          $restante  = $status['restante_so2_h'] ?? null;
      ?>
        <tr>
          <td><span class="vbadge <?= h($selo['cls']) ?>"><?= h($selo['rotulo']) ?></span></td>
          <td><strong><?= h((string)($linha['romaneio'] ?? '')) ?: '—' ?></strong></td>
          <td><?= h((string)($linha['talhao_nome'] ?? '')) ?: '—' ?></td>
          <td><?= h((string)($linha['variedade_nome'] ?? '')) ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= isset($linha['peso_kg']) ? numFmt((float)$linha['peso_kg'], 2) : '—' ?></td>
          <td><?= $colhidoEm ? h($colhidoEm) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $horas === null ? '—' : numFmt((float)$horas, 1) . 'h' ?></td>
          <td class="vnum" style="text-align:right">
            <?php if ($restante === null): ?>—
            <?php elseif ((float)$restante <= 0): ?>
              <span class="vbadge vb-off">Expirada</span>
            <?php else: ?>
              <strong><?= numFmt((float)$restante, 1) ?>h</strong>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php'; ?>
