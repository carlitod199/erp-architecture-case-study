<?php
/* ============================================================
   VERO — Relatórios / base compartilhada
   Cada tela define $REL = [
     'micro','view','titulo','sub',
     'datasets' => [chave => ['titulo','sql','params',
                              'colunas' => [campo => rótulo],
                              'formato' => [campo => 'dec2'|'dec0'|'data'|'texto']]],
   ] e inclui esta base. Filtros de período via ?ini/?fim entram nos
   params como :ini/:fim quando o SQL os menciona.
   Export CSV: ?csv=<chave> baixa o dataset (UTF-8 BOM, ; separado).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/_exportacoes_modal.php'; /* botão + modal de Exportações (F10) */

$REL_INI = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-01-01');
$REL_FIM = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');

/* ONDA1/#39 — filtro OBRIGATÓRIO: o relatório NUNCA carrega dados no load.
   Só renderiza os datasets quando o usuário aplica o filtro (?aplicar=1),
   via botão "Gerar relatório". Assim a tela abre só com as opções + filtro,
   e nunca despeja a tabela inteira. (O export CSV abaixo carrega uma chave
   específica e já carrega o período aplicado no link.) */
$REL_APLICADO = (($_GET['aplicar'] ?? '') === '1');

/* P04 (auditoria 20/07) — filtro OPCIONAL de EXIBIÇÃO "ocultar registros de teste".
   Default DESLIGADO (não esconde dado real sem intenção). NÃO apaga nada no banco;
   só oculta, no render e no CSV, linhas cujo texto casa com a massa de teste
   conhecida (ver REL_TESTE_REGEX). */
$REL_OCULTAR_TESTE = (($_GET['ocultar_teste'] ?? '') === '1');

/* Padrão de detecção de registro de teste (case-insensitive), aplicado a QUALQUER
   valor textual da linha (descrição, número, romaneio, identificação, etc.):
     QA5   — prefixo dos tenants/rótulos da bateria QA (QA5-*)
     TESTE — massa manual de teste
     PROBE — sondas de verificação
     AUDIT — cobre A0AUDIT e similares
     PROVA — cobre PROVA e PROVA-UXO1B
   Só casa em colunas de texto; valores numéricos/datas não disparam. */
const REL_TESTE_REGEX = '/QA5|TESTE|PROBE|AUDIT|PROVA/i';

function rel_eh_teste(array $row): bool
{
    foreach ($row as $v) {
        if (is_string($v) && $v !== '' && preg_match(REL_TESTE_REGEX, $v)) return true;
    }
    return false;
}

/** Remove linhas de teste quando o filtro está ligado (filtro de exibição). */
function rel_filtrar_teste(array $rows, bool $ocultar): array
{
    if (!$ocultar) return $rows;
    return array_values(array_filter($rows, static fn(array $r): bool => !rel_eh_teste($r)));
}

/** P08 (auditoria 20/07) — soma das colunas declaradas em $ds['totais'].
   Retorna [campo => soma] a partir das linhas JÁ exibidas (respeita o filtro de
   teste). Se a coluna for mascarada por permissão, o dataset simplesmente não a
   declara em 'totais' — logo o total herda o mesmo gating (P-75). */
function rel_totais(array $ds, array $rows): array
{
    $campos = $ds['totais'] ?? [];
    if (!$campos) return [];
    $tot = array_fill_keys($campos, 0.0);
    foreach ($rows as $r) {
        foreach ($campos as $c) $tot[$c] += (float)($r[$c] ?? 0);
    }
    return $tot;
}

/** Casas decimais de uma coluna a partir do seu formato (dec0/dec4/dec2). */
function rel_casas(array $ds, string $campo): int
{
    return match ($ds['formato'][$campo] ?? '') { 'dec0' => 0, 'dec4' => 4, default => 2 };
}

function rel_rows(array $ds, string $ini, string $fim): array
{
    $params = $ds['params'] ?? [];
    if (str_contains($ds['sql'], ':ini')) $params[':ini'] = $ini;
    if (str_contains($ds['sql'], ':fim')) $params[':fim'] = $fim;
    if (str_contains($ds['sql'], ':t')) $params[':t'] = vero_tenant();
    return vero_rows($ds['sql'], $params);
}

function rel_formatar(array $ds, array $row, string $campo): string
{
    $v = $row[$campo] ?? null;
    if ($v === null) return '—';
    return match ($ds['formato'][$campo] ?? 'texto') {
        'dec2'  => numFmt((float)$v, 2),
        'dec4'  => numFmt((float)$v, 4),
        'dec0'  => numFmt((float)$v, 0),
        'data'  => date('d/m/Y', strtotime((string)$v)),
        default => (string)$v,
    };
}

/* ── Export CSV (antes de qualquer HTML) ───────────────────── */
$csvKey = (string)($_GET['csv'] ?? '');
if ($csvKey !== '' && isset($REL['datasets'][$csvKey])) {
    /* respeita o guard sem renderizar o layout */
    if (function_exists('requirePermission')) requirePermission('relatorios.' . $REL['micro'] . '.ver');
    $ds = $REL['datasets'][$csvKey];
    $rows = rel_filtrar_teste(rel_rows($ds, $REL_INI, $REL_FIM), $REL_OCULTAR_TESTE);
    /* respeita o filtro "Tipo de operação" no export (mesma regra da consulta) */
    $csvTipo = trim((string)($_GET['tipo'] ?? ''));
    if ($csvTipo !== '' && !empty($ds['tipo_col'])) {
        $col = $ds['tipo_col'];
        $rows = array_values(array_filter($rows, static fn($r) => (string)($r[$col] ?? '') === $csvTipo));
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vero_' . $REL['micro'] . '_' . $csvKey . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
    fputcsv($out, array_values($ds['colunas']), ';');
    foreach ($rows as $r) {
        $linha = [];
        foreach (array_keys($ds['colunas']) as $campo) {
            $v = $r[$campo] ?? '';
            if (isset($ds['formato'][$campo]) && in_array($ds['formato'][$campo], ['dec2', 'dec4', 'dec0'], true) && $v !== '') {
                $v = number_format((float)$v, $ds['formato'][$campo] === 'dec0' ? 0 : ($ds['formato'][$campo] === 'dec4' ? 4 : 2), ',', '');
            }
            $linha[] = $v;
        }
        fputcsv($out, $linha, ';');
    }
    /* P08 — linha de TOTAL no CSV (Σ das colunas declaradas em 'totais') */
    $tot = rel_totais($ds, $rows);
    if ($tot) {
        $labelCol = null;
        foreach (array_keys($ds['colunas']) as $campo) {
            if (!isset($tot[$campo])) { $labelCol = $campo; break; }
        }
        $linha = [];
        foreach (array_keys($ds['colunas']) as $campo) {
            if (isset($tot[$campo])) {
                $linha[] = number_format($tot[$campo], rel_casas($ds, $campo), ',', '');
            } else {
                $linha[] = ($campo === $labelCol) ? 'TOTAL' : '';
            }
        }
        fputcsv($out, $linha, ';');
    }
    fclose($out);
    exit;
}

/* ── Render ─────────────────────────────────────────────────── */
$GUARD      = ['macro' => 'relatorios', 'micro' => $REL['micro']];
$PAGE_VIEW  = $REL['view'];
$PAGE_TITLE = $REL['titulo'];
$EXTRA_HEAD = vero_assets() . '<style media="print">.vsidebar,.no-print{display:none !important}</style>';
require __DIR__ . '/../includes/agro_header.php';

$empresa = (string)vero_val("SELECT nome FROM tenants WHERE id = :t", [':t' => vero_tenant()]);
$RELMF   = !empty($REL['modal_first']); /* #relatorios (22/07): fluxo modal-first (piloto operacionais) */
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header($REL['titulo'], $REL['sub'], null) ?>

<?php if (!$RELMF): /* ═══ fluxo inline clássico (8 relatórios) ═══ */ ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center" class="no-print">
        <label class="vhint">Período</label>
        <input type="date" name="ini" value="<?= h($REL_INI) ?>" required>
        <input type="date" name="fim" value="<?= h($REL_FIM) ?>" required>
        <input type="hidden" name="aplicar" value="1">
        <label class="vhint" style="display:inline-flex;align-items:center;gap:5px;cursor:pointer"
               title="Oculta linhas de massa de teste (QA5, TESTE, PROBE, AUDIT, PROVA). Não apaga nada.">
          <input type="checkbox" name="ocultar_teste" value="1"<?= $REL_OCULTAR_TESTE ? ' checked' : '' ?>>
          Ocultar registros de teste
        </label>
        <button class="vbtn vbtn-primary vbtn-sm" type="submit">Gerar relatório</button>
      </form>
      <span class="vsub"><strong><?= h($empresa) ?></strong><?php if ($REL_APLICADO): ?> ·
        <?= date('d/m/Y', strtotime($REL_INI)) ?> – <?= date('d/m/Y', strtotime($REL_FIM)) ?><?php endif; ?></span>
      <?= $REL['acoes_html'] ?? '' /* ações extras da tela (links prontos, já escapados) */ ?>
      <?= vero_exportacoes_botao_html() /* abre o modal de exportações (só c/ relatorios.exportacoes.ver) */ ?>
      <?php if ($REL_APLICADO): ?><button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir</button><?php endif; ?>
    </div>
  </div>

  <?php if (!$REL_APLICADO): ?>
  <div class="vcard">
    <div class="vempty" style="text-align:center;padding:28px 16px">
      <div style="font-size:15px;font-weight:600;margin-bottom:4px">Defina o período e clique em <em>Gerar relatório</em></div>
      <div class="vhint">Os relatórios são sempre carregados sob um filtro — nada é exibido antes de você aplicá-lo.
        Disponíveis nesta tela: <?= h(implode(' · ', array_map(static fn($d) => $d['titulo'], $REL['datasets']))) ?>.</div>
    </div>
  </div>
  <?php else: ?>
  <?php foreach ($REL['datasets'] as $chave => $ds):
      $rows = rel_filtrar_teste(rel_rows($ds, $REL_INI, $REL_FIM), $REL_OCULTAR_TESTE); ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong><?= h($ds['titulo']) ?></strong>
      <span class="vsub"><?= count($rows) ?> linha(s)<?= $REL_OCULTAR_TESTE ? ' · <em>teste oculto</em>' : '' ?> ·
        <a class="no-print" href="?ini=<?= h($REL_INI) ?>&fim=<?= h($REL_FIM) ?>&csv=<?= h($chave) ?><?= $REL_OCULTAR_TESTE ? '&ocultar_teste=1' : '' ?>">Exportar CSV</a></span></div>
    <?php if (!empty($ds['nota'])): ?><div class="vhint" style="margin:-4px 0 8px"><?= h($ds['nota']) ?></div><?php endif; ?>
    <?php if (!$rows): ?>
      <div class="vempty">Sem dados no período.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr>
        <?php foreach ($ds['colunas'] as $campo => $rotulo):
            $num = in_array($ds['formato'][$campo] ?? '', ['dec2', 'dec4', 'dec0'], true); ?>
          <th<?= $num ? ' style="text-align:right"' : '' ?>><?= h($rotulo) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach (array_keys($ds['colunas']) as $campo):
              $num = in_array($ds['formato'][$campo] ?? '', ['dec2', 'dec4', 'dec0'], true); ?>
            <td class="<?= $num ? 'vnum' : '' ?>"<?= $num ? ' style="text-align:right"' : '' ?>><?= h(rel_formatar($ds, $r, $campo)) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php $tot = rel_totais($ds, $rows); if ($tot):
          $labelCol = null;
          foreach (array_keys($ds['colunas']) as $c) { if (!isset($tot[$c])) { $labelCol = $c; break; } } ?>
      <tfoot>
        <tr>
          <?php foreach ($ds['colunas'] as $campo => $rotulo):
              $num = in_array($ds['formato'][$campo] ?? '', ['dec2', 'dec4', 'dec0'], true); ?>
            <th class="<?= $num ? 'vnum' : '' ?>"<?= $num ? ' style="text-align:right"' : '' ?>><?php
              if (isset($tot[$campo])) echo h(numFmt($tot[$campo], rel_casas($ds, $campo)));
              elseif ($campo === $labelCol) echo 'TOTAL';
            ?></th>
          <?php endforeach; ?>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; /* $REL_APLICADO */ ?>

<?php else: /* ═══ fluxo modal-first (piloto: Relatórios Operacionais, 22/07) ═══ */
    $modo  = (string)($_GET['modo'] ?? '');
    $abrir = (!$REL_APLICADO) || ($modo === 'consulta'); /* abre ao carregar e após Consulta rápida */
    /* filtro "Tipo …" OPCIONAL por relatório. Ative em $REL:
         'tipo_filtro' => true
             → usa os tipos de atividade do tenant (label "Tipo de operação").
         'tipo_filtro' => ['label' => 'Tipo de movimento',
                           'sql'   => "SELECT DISTINCT origem FROM ... WHERE tenant_id = :t ORDER BY 1"]
             → filtro sob medida. O SQL retorna UMA coluna (a 1ª é usada) e pode
               referenciar :t (tenant). Os datasets ligam a coluna filtrada por
               'tipo_col'. Sem esse flag o relatório não exibe filtro de tipo.
       'tabela_label' => rótulo do seletor de dataset (default "Tabela"). */
    $tipoCfg        = $REL['tipo_filtro'] ?? null;
    $REL_TIPO_ATIVO = !empty($tipoCfg);
    $REL_TIPO       = $REL_TIPO_ATIVO ? trim((string)($_GET['tipo'] ?? '')) : '';
    $tipoLabel      = (is_array($tipoCfg) && !empty($tipoCfg['label'])) ? (string)$tipoCfg['label'] : 'Tipo de operação';
    $tipoSql        = (is_array($tipoCfg) && !empty($tipoCfg['sql'])) ? (string)$tipoCfg['sql']
                    : "SELECT DISTINCT nome FROM agro_tipos_atividade WHERE tenant_id = :t ORDER BY nome";
    $tipoOpts = [];
    if ($REL_TIPO_ATIVO) {
        $tp = str_contains($tipoSql, ':t') ? [':t' => vero_tenant()] : [];
        foreach (vero_rows($tipoSql, $tp) as $row) { $v = reset($row); if ($v !== null && $v !== '') $tipoOpts[] = (string)$v; }
    }
    $tabelaLabel = (string)($REL['tabela_label'] ?? 'Tabela'); /* rótulo do seletor de dataset */
?>
  <div class="vcard" style="margin-bottom:14px"><div class="vtoolbar">
    <button class="vbtn vbtn-primary vbtn-sm no-print" type="button" onclick="relModal(true)">Filtros e consulta</button>
    <span class="vsub"><strong><?= h($empresa) ?></strong><?php if ($REL_APLICADO): ?> ·
      <?= date('d/m/Y', strtotime($REL_INI)) ?> – <?= date('d/m/Y', strtotime($REL_FIM)) ?><?php endif; ?></span>
    <?php if ($REL_APLICADO): ?><button class="vbtn vbtn-sm no-print" type="button" onclick="window.print()">Imprimir / PDF</button><?php endif; ?>
  </div>
  <?php if (!$REL_APLICADO): ?><div class="vhint" style="padding:10px 14px">Clique em <em>Filtros e consulta</em> para escolher o período e ver (consulta rápida, paginada) ou exportar o relatório.</div><?php endif; ?>
  </div>

  <div class="vmodal<?= $abrir ? ' open' : '' ?>" id="rel-modal">
    <div class="vbox" style="max-width:1080px;width:96%;min-height:560px"><!-- tamanho padronizado -->
      <header><h2><?= h($REL['titulo']) ?></h2><a class="vclose no-print" href="javascript:void(0)" onclick="relModal(false)">×</a></header>

      <div style="padding:6px 22px 22px"><!-- corpo com espaçamento das bordas -->
      <?php
      /* pré-computa os blocos ANTES do form para que o seletor "Operação"
         possa ficar na MESMA linha dos filtros. */
      $blocos = [];
      if ($REL_APLICADO) {
          foreach ($REL['datasets'] as $chave => $ds) {
              if ($REL_TIPO !== '' && empty($ds['tipo_col'])) continue; /* dataset sem tipo sai ao filtrar por operação */
              $rows = rel_filtrar_teste(rel_rows($ds, $REL_INI, $REL_FIM), $REL_OCULTAR_TESTE);
              if ($REL_TIPO !== '' && !empty($ds['tipo_col'])) {
                  $col = $ds['tipo_col'];
                  $rows = array_values(array_filter($rows, static fn($r) => (string)($r[$col] ?? '') === $REL_TIPO));
              }
              $blocos[$chave] = ['ds' => $ds, 'rows' => $rows];
          }
      }
      ?>
      <form method="get" class="no-print" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
        <div class="vfield" style="margin:0"><label>Data inicial</label>
          <input type="date" name="ini" value="<?= h($REL_INI) ?>" required></div>
        <div class="vfield" style="margin:0"><label>Data final</label>
          <input type="date" name="fim" value="<?= h($REL_FIM) ?>" required></div>
        <?php if ($REL_TIPO_ATIVO): ?>
        <div class="vfield" style="margin:0"><label><?= h($tipoLabel) ?></label>
          <select name="tipo">
            <option value="">— Todos —</option>
            <?php foreach ($tipoOpts as $to): ?><option value="<?= h($to) ?>"<?= $REL_TIPO === $to ? ' selected' : '' ?>><?= h($to) ?></option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <input type="hidden" name="aplicar" value="1">
        <button class="vbtn vbtn-primary" type="submit" name="modo" value="consulta" style="height:38px;margin:0">Consulta rápida</button>
        <?php if ($REL_APLICADO && count($blocos) > 1): ?>
        <div class="vfield" style="margin:0"><label><?= h($tabelaLabel) ?></label>
          <select id="rel-opsel" onchange="relTabSel(this.value)">
            <?php $i = 0; foreach ($blocos as $ck => $b): ?><option value="<?= h($ck) ?>"<?= $i === 0 ? ' selected' : '' ?>><?= h($b['ds']['titulo']) ?> (<?= count($b['rows']) ?>)</option><?php $i++; endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="vfield" style="margin:0"><label>Exportar</label>
          <select id="rel-exp-fmt" onchange="relExportFmt(this.value)">
            <option value="">— formato —</option>
            <option value="excel">Excel</option>
            <option value="pdf">PDF</option>
          </select></div>
      </form>

      <?php if ($REL_APLICADO): ?>
        <?php if (!$blocos): ?>
          <div class="vempty" style="padding:22px">Nenhuma tabela para esse tipo de operação.</div>
        <?php else: ?>
        <?php $i = 0; foreach ($blocos as $ck => $b): $ds = $b['ds']; $rows = $b['rows']; ?>
          <div class="relpane" data-pane="<?= h($ck) ?>"<?= $i > 0 ? ' style="display:none"' : '' ?>>
            <?php if (!$rows): ?><div class="vempty">Sem dados para esse filtro.</div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="vtable relpag" data-pp="8">
              <thead><tr><?php foreach ($ds['colunas'] as $campo => $rotulo): $num = in_array($ds['formato'][$campo] ?? '', ['dec2','dec4','dec0'], true); ?><th<?= $num ? ' style="text-align:right"' : '' ?>><?= h($rotulo) ?></th><?php endforeach; ?></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): ?><tr><?php foreach (array_keys($ds['colunas']) as $campo): $num = in_array($ds['formato'][$campo] ?? '', ['dec2','dec4','dec0'], true); ?><td<?= $num ? ' class="vnum" style="text-align:right"' : '' ?>><?= h(rel_formatar($ds, $r, $campo)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <?php endif; ?>
          </div>
        <?php $i++; endforeach; ?>
        <?php endif; ?>
      <?php else: ?>
        <div class="vempty" style="padding:22px">Defina o período e clique em <em>Consulta rápida</em> — os resultados aparecem aqui, paginados. Ou use <em>Exportar</em>.</div>
      <?php endif; ?>
      </div><!-- /corpo com padding -->
    </div>
  </div>

  <script>
  function relModal(o){ var m=document.getElementById('rel-modal'); if(m) m.classList.toggle('open', !!o); }
  /* Exportar = dropdown de formato; exporta o conteúdo dos filtros aplicados.
     Excel → CSV (abre no Excel); PDF → janela de impressão (Salvar como PDF). */
  function relExportFmt(fmt){
    if(!fmt) return;
    if(fmt==='pdf'){ window.print(); }
    else if(fmt==='excel'){
      var op=document.getElementById('rel-opsel');
      var ds = op ? op.value : '<?= h((string)array_key_first($REL['datasets'])) ?>';
      window.location='?ini=<?= h($REL_INI) ?>&fim=<?= h($REL_FIM) ?>&csv='+encodeURIComponent(ds)+'<?= $REL_TIPO !== '' ? '&tipo=' . rawurlencode($REL_TIPO) : '' ?>';
    }
    var s=document.getElementById('rel-exp-fmt'); if(s) s.value='';
  }
  /* seletor de operação: uma tabela por vez (paginação em vez de rolagem) */
  function relTabSel(k){ document.querySelectorAll('.relpane').forEach(function(p){ p.style.display = (p.getAttribute('data-pane')===k)?'':'none'; }); }
  /* paginação por tabela */
  document.querySelectorAll('table.relpag').forEach(function(t){
    var pp=parseInt(t.getAttribute('data-pp')||'8',10), rows=[].slice.call(t.tBodies[0].rows);
    var np=Math.ceil(rows.length/pp), pg=0;
    function draw(){ rows.forEach(function(r,i){ r.style.display=(i>=pg*pp && i<(pg+1)*pp)?'':'none'; }); if(lbl) lbl.textContent=(pg+1)+' / '+Math.max(1,np); if(prev) prev.disabled=pg===0; if(next) next.disabled=pg>=np-1; }
    t._redraw=draw;
    var prev,lbl,next;
    if(rows.length>pp){
      var nav=document.createElement('div'); nav.className='no-print'; nav.style.cssText='display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:6px;font-size:12px';
      prev=document.createElement('button'); lbl=document.createElement('span'); next=document.createElement('button');
      prev.type=next.type='button'; prev.className=next.className='vbtn vbtn-ghost vbtn-sm'; prev.textContent='‹'; next.textContent='›';
      nav.appendChild(prev); nav.appendChild(lbl); nav.appendChild(next); t.parentNode.appendChild(nav);
      prev.onclick=function(){ if(pg>0){pg--;draw();} }; next.onclick=function(){ if(pg<np-1){pg++;draw();} };
    }
    draw();
  });
  /* impressão / PDF: mostra TODAS as abas e TODAS as linhas; restaura depois */
  window.addEventListener('beforeprint',function(){
    document.querySelectorAll('.relpane').forEach(function(p){ p.style.display=''; });
    document.querySelectorAll('table.relpag tbody tr').forEach(function(r){ r.style.display=''; });
  });
  window.addEventListener('afterprint',function(){
    var s=document.getElementById('rel-opsel'); if(s) relTabSel(s.value);
    document.querySelectorAll('table.relpag').forEach(function(t){ if(t._redraw) t._redraw(); });
  });
  </script>
<?php endif; /* $RELMF */ ?>
</div>

<?= vero_exportacoes_modal_html($REL_INI, $REL_FIM) ?>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
