<?php
/* ============================================================
   VERO — Relatórios / parcial: modal de Exportações (F10)
   Definição CANÔNICA dos datasets de export (extraída de
   exportacoes.php em 19/07 — não duplicar a lista em outro lugar)
   + helpers de render do modal padrão DS (.vmodal/vModalOpen).

   Consumidores:
     - relatorios/_rel_base.php  → botão "Exportações" na toolbar
                                   de toda tela de relatório + modal
     - relatorios/exportacoes.php → casca de fallback com o modal
                                   já aberto (bookmarks/rota)

   Permissão: tudo aqui só renderiza para quem tem
   relatorios.exportacoes.ver (vero_can). Os CSVs individuais
   mantêm os guards próprios: cada botão faz GET ao endpoint de
   stream EXISTENTE (relatorios_*.php?csv=<chave>&ini=&fim=, via
   _rel_base.php, que aplica requirePermission antes de streamar).
   ============================================================ */
declare(strict_types=1);

/** Datasets de exportação agrupados por área: [grupo => [[rótulo, rota, chave], ...]] */
function vero_exportacoes_grupos(): array
{
    return [
        'Operação' => [
            ['Apontamentos de campo', '/relatorios/relatorios_operacionais', 'apontamentos'],
            ['Atividades planejadas', '/relatorios/relatorios_operacionais', 'atividades'],
            ['Aplicações', '/relatorios/relatorios_operacionais', 'aplicacoes'],
            ['Irrigação', '/relatorios/relatorios_operacionais', 'irrigacao'],
        ],
        'Financeiro' => [
            ['Razão financeiro', '/relatorios/relatorios_financeiros', 'razao'],
            ['Custeio por lançamento', '/relatorios/relatorios_financeiros', 'custeio'],
            ['Caixa por mês', '/relatorios/relatorios_financeiros', 'caixa_mensal'],
        ],
        'Safra e colheita' => [
            ['Resultado por safra', '/relatorios/relatorios_safra', 'resultado'],
            ['Custo por talhão/categoria', '/relatorios/relatorios_safra', 'custo_talhao'],
            ['Orçado × realizado', '/relatorios/relatorios_safra', 'orcado_realizado'],
            ['Registros de colheita', '/relatorios/relatorios_colheita', 'registros'],
            ['Classificações', '/relatorios/relatorios_colheita', 'classificacoes'],
            ['Cargas de colheita', '/relatorios/relatorios_colheita', 'cargas'],
        ],
        'Suprimentos' => [
            ['Saldos de estoque', '/relatorios/relatorios_estoque', 'saldos'],
            ['Lotes (FEFO)', '/relatorios/relatorios_estoque', 'lotes'],
            ['Movimentações de estoque', '/relatorios/relatorios_estoque', 'movimentacoes'],
            ['Pedidos de compra', '/relatorios/relatorios_compras', 'pedidos'],
            ['Volume por fornecedor', '/relatorios/relatorios_compras', 'por_fornecedor'],
            ['Recebimentos', '/relatorios/relatorios_compras', 'recebimentos'],
        ],
        'Técnico' => [
            ['Resultados de análise de solo', '/relatorios/relatorios_tecnicos', 'analises_solo'],
            ['Monitoramentos MIP', '/relatorios/relatorios_tecnicos', 'monitoramentos'],
            ['Aplicações validadas (RT)', '/relatorios/relatorios_tecnicos', 'aplicacoes_validadas'],
        ],
    ];
}

/** Botão da toolbar que abre o modal. Vazio p/ quem não tem a permissão. */
function vero_exportacoes_botao_html(): string
{
    if (!vero_can('relatorios.exportacoes.ver')) return '';
    return '<button class="vbtn vbtn-ghost vbtn-sm no-print" type="button" id="vexp-abrir"'
         . ' onclick="vModalOpen(\'vm-exportacoes\')">Exportações</button>';
}

/**
 * Modal completo (padrão DS: .vmodal > .vbox, abre via vModalOpen).
 * $ini/$fim = defaults dos campos de período; $aberto = renderiza já
 * aberto (fallback da rota exportacoes.php). Vazio sem a permissão.
 */
function vero_exportacoes_modal_html(string $ini, string $fim, bool $aberto = false): string
{
    if (!vero_can('relatorios.exportacoes.ver')) return '';
    $base = rtrim(BIOS_BASE, '/');
    ob_start(); ?>
<div class="vmodal<?= $aberto ? ' open' : '' ?>" id="vm-exportacoes">
  <div class="vbox" style="max-width:880px">
    <header>
      <h2>Exportações</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-exportacoes')">&times;</button>
    </header>
    <div class="vform">
      <div class="vgrid">
        <div class="vfield">
          <label>Período — início</label>
          <input type="date" id="vexp-ini" value="<?= h($ini) ?>">
        </div>
        <div class="vfield">
          <label>Período — fim</label>
          <input type="date" id="vexp-fim" value="<?= h($fim) ?>">
        </div>
      </div>
      <div class="vhint" style="margin:6px 0 12px">CSV com BOM UTF-8, separado por ponto e vírgula (Excel).
        Datasets sem recorte de data exportam a base completa.</div>
      <?php foreach (vero_exportacoes_grupos() as $grupo => $itens): ?>
      <div style="margin-bottom:12px">
        <div style="font-size:12px;font-weight:600;color:#4A4034;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px"><?= h($grupo) ?></div>
        <table class="vtable">
          <tbody>
          <?php foreach ($itens as [$rotulo, $rota, $chave]): ?>
            <tr>
              <td><?= h($rotulo) ?></td>
              <td style="text-align:right;width:170px;white-space:nowrap">
                <a class="vbtn vbtn-ghost vbtn-sm" data-rota="<?= h($base . $rota) ?>"
                   href="<?= h($base . $rota) ?>?ini=<?= h($ini) ?>&amp;fim=<?= h($fim) ?>">Ver</a>
                <a class="vbtn vbtn-primary vbtn-sm" data-rota="<?= h($base . $rota) ?>" data-csv="<?= h($chave) ?>"
                   href="<?= h($base . $rota) ?>?ini=<?= h($ini) ?>&amp;fim=<?= h($fim) ?>&amp;csv=<?= h($chave) ?>">CSV</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
/* Período do modal → links: cada mudança nos campos reescreve os hrefs
   (mantém os links "de verdade": middle-click/copiar continuam válidos).
   O CSV navega para o endpoint de stream existente — o download não sai da tela. */
(function () {
  var ini = document.getElementById('vexp-ini'), fim = document.getElementById('vexp-fim');
  if (!ini || !fim) return;
  function vexpSync() {
    document.querySelectorAll('#vm-exportacoes a[data-rota]').forEach(function (a) {
      var u = a.getAttribute('data-rota') + '?ini=' + encodeURIComponent(ini.value)
            + '&fim=' + encodeURIComponent(fim.value);
      var c = a.getAttribute('data-csv');
      if (c) u += '&csv=' + encodeURIComponent(c);
      a.setAttribute('href', u);
    });
  }
  ini.addEventListener('change', vexpSync);
  fim.addEventListener('change', vexpSync);
  vexpSync();
})();
</script>
<?php
    return (string)ob_get_clean();
}
