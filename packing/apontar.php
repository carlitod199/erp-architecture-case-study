<?php
declare(strict_types=1);
/* ============================================================
   VERO — Packing House / Posto de Produção (Colheita + Embalamento)
   Rota: /packing/apontar.php · Guard: packing.apontar (view: packing_apontar)
   POSTO UNIFICADO: a leitura de colheita e embalamento é feita EM CONJUNTO na
   mesma tela. Bipa-se a etiqueta da pessoa e o sistema ROTEIA pela "Função"
   dela (agro_operadores/rh_terceirizados.funcao_packing, definida em QR Codes):
     • colhedor  → conta COLHEITA    (atividade de colheita + válvula/safra)
     • embalador → conta EMBALAMENTO (atividade de embalamento, talhao_id NULL → custo de packing)
     • ambos     → segue o "modo p/ ambos" configurado no posto
     • sem função definida → a leitura avisa p/ definir (não conta às cegas)
   Cada leitura = +1 caixa à pessoa (acumula na mesma linha rh_producao_itens,
   premiação da regra vigente + custeio). Modelo AJAX (beep sonoro/visual, debounce).
   Serviços: includes/vero_cracha.php (resolver + vero_srv_producao_incrementar_cracha).
   ============================================================ */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_cracha.php';
require_once __DIR__ . '/_ph_recepcao.php'; /* elo embalamento→recepção (19/08) */

const APU_DEBOUNCE_MS = 400; // ignora leitura idêntica (tiro duplo do leitor)

/* Config do posto (querystring, preservada no beep). */
$data      = vero_date('data') ?? date('Y-m-d');
/* Atividades AUTO-RESOLVIDAS (não escolhidas no posto): colheita = única
   categoria='colheita' com produção; embalamento = única categoria='packing'. */
$atvColh = (int)(vero_val("SELECT id FROM agro_tipos_atividade WHERE tenant_id=:t AND categoria='colheita' AND exige_producao=1 AND ativo=1 ORDER BY id LIMIT 1", [':t' => vero_tenant()]) ?? 0);
$atvEmb  = (int)(vero_val("SELECT id FROM agro_tipos_atividade WHERE tenant_id=:t AND categoria='packing'  AND exige_producao=1 AND ativo=1 ORDER BY id LIMIT 1", [':t' => vero_tenant()]) ?? 0);
$talhaoId  = ((int)($_GET['talhao_id']  ?? $_POST['talhao_id']   ?? 0)) ?: null;
$modoAmbos = (string)($_GET['modo_ambos'] ?? $_POST['modo_ambos'] ?? 'colheita');
if (!in_array($modoAmbos, ['colheita', 'embalamento'], true)) $modoAmbos = 'colheita';
$romaneio  = (string)($_GET['romaneio'] ?? '');
/* Gestor 19/08: romaneio digitado que NÃO existe era aceito em silêncio (o
   campo é só preenchedor, mas parecia validado). Agora rejeita com aviso. */
if ($romaneio !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST'
    && !vero_srv_romaneio_alvo_colheita($romaneio)) {
    vero_flash('erro', 'Romaneio "' . h($romaneio) . '" não encontrado — confira o número (o campo foi limpo).');
    $romaneio = '';
}

/* vínculo safra×válvula ativo (colheita); embalamento não usa. */
function apu_safra_talhao(?int $talhaoId): ?int
{
    if (!$talhaoId) return null;
    return (int)(vero_val(
        "SELECT st.id FROM agro_safra_talhoes st JOIN agro_safras s ON s.id = st.safra_id
          WHERE st.tenant_id = :t AND st.talhao_id = :ta ORDER BY s.data_inicio DESC, st.id DESC LIMIT 1",
        [':t' => vero_tenant(), ':ta' => $talhaoId]) ?? 0) ?: null;
}

/** id do apontamento (0 se não existe) para (data, atividade, válvula). */
function apu_apont_id(string $data, int $atividadeId, ?int $talhaoId): int
{
    if (!$atividadeId) return 0;
    return (int)(vero_val(
        "SELECT id FROM agro_apontamentos
          WHERE tenant_id=:t AND DATE(data_apontamento)=:d AND tipo_atividade_id=:ta AND (talhao_id <=> :tal)
          ORDER BY id DESC LIMIT 1",
        [':t' => vero_tenant(), ':d' => $data, ':ta' => $atividadeId, ':tal' => $talhaoId]) ?? 0);
}

/** Tally combinado (colheita + embalamento), cada linha marcada com o modo. */
function apu_tally(string $data, int $atvColh, ?int $talhaoId, int $atvEmb): array
{
    $ids = [];
    if ($atvColh && ($i = apu_apont_id($data, $atvColh, $talhaoId))) $ids[$i] = 'Colheita';
    if ($atvEmb  && ($i = apu_apont_id($data, $atvEmb, null)))       $ids[$i] = 'Embalamento';
    if (!$ids) return [];
    $in = implode(',', array_map('intval', array_keys($ids)));
    $rows = vero_rows(
        "SELECT ri.apontamento_id, COALESCE(o.nome, tc.nome) AS pessoa, ri.origem_pessoa,
                ri.quantidade, ri.valor_total
           FROM rh_producao_itens ri
           LEFT JOIN agro_operadores  o  ON o.id  = ri.operador_id
           LEFT JOIN rh_terceirizados tc ON tc.id = ri.terceirizado_id
          WHERE ri.tenant_id = :t AND ri.apontamento_id IN ($in)
          ORDER BY ri.quantidade DESC, ri.id DESC",
        [':t' => vero_tenant()]);
    foreach ($rows as &$r) { $r['modo'] = $ids[(int)$r['apontamento_id']] ?? '—'; }
    return $rows;
}
function apu_tally_json(array $rows): array
{
    return array_map(static fn($p) => [
        'pessoa'  => (string)($p['pessoa'] ?? '—'),
        'modo'    => (string)($p['modo'] ?? '—'),
        'vinculo' => (string)$p['origem_pessoa'],
        'caixas'  => (float)$p['quantidade'],
        'premio'  => (float)$p['valor_total'],
    ], $rows);
}

$isAjax   = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$configOk = $atvColh || $atvEmb;

/* Consulta AJAX do romaneio → data real da colheita + válvula + atividade (editáveis). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'romaneio_lookup') {
    csrfCheck();
    vero_require('packing.apontar.editar');
    $rom  = vero_str('romaneio', 40) ?? '';
    $alvo = $rom !== '' ? vero_srv_romaneio_alvo_colheita($rom) : null;
    header('Content-Type: application/json; charset=utf-8');
    if (!$alvo) { echo json_encode(['ok' => false, 'msg' => 'Romaneio não encontrado.'], JSON_UNESCAPED_UNICODE); exit; }
    $talNome = $alvo['talhao_id']
        ? (string)(vero_val("SELECT codigo FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $alvo['talhao_id'], ':t' => vero_tenant()]) ?? '')
        : '';
    echo json_encode([
        'ok'           => true,
        'data'         => $alvo['data_colheita'],
        'talhao_id'    => $alvo['talhao_id'],
        'atividade_id' => $alvo['tipo_atividade_id'],
        'msg'          => 'Colheita de ' . date('d/m/Y', strtotime($alvo['data_colheita'])) . ($talNome ? ' · válvula ' . $talNome : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'beep') {
    csrfCheck();
    vero_require('packing.apontar.editar');
    $back = $_SERVER['PHP_SELF'] . '?data=' . urlencode($data)
        . ($atvColh ? '&colheita_id=' . $atvColh : '') . ($atvEmb ? '&embal_id=' . $atvEmb : '')
        . ($talhaoId ? '&talhao_id=' . $talhaoId : '') . '&modo_ambos=' . $modoAmbos;
    $cracha = vero_str('cracha', 40) ?? '';
    $res = ['ok' => false, 'status' => 'erro', 'msg' => '', 'tally' => [], 'count' => 0];

    if ($cracha === '') {
        $res['msg'] = 'Leia a etiqueta da pessoa.';
    } else {
        $lb = $_SESSION['apu_last_beep'] ?? null;
        $dupe = is_array($lb) && $lb['cracha'] === $cracha
            && (microtime(true) - (float)$lb['ts']) * 1000 < APU_DEBOUNCE_MS;
        if ($dupe) {
            $res['status'] = 'dupe';
            $res['msg'] = 'Leitura repetida ignorada.';
        } else {
            $pessoa = vero_srv_cracha_resolver($cracha);
            if (!$pessoa) {
                $res['msg'] = 'Etiqueta não reconhecida — gere/atribua o QR em QR Codes.';
            } else {
                $papel = $pessoa['papel'] ?? null;
                $efet  = match ($papel) {
                    'colhedor'  => 'colheita',
                    'embalador' => 'embalamento',
                    'ambos'     => $modoAmbos,
                    default     => null,
                };
                if ($efet === null) {
                    $res['msg'] = 'Defina a função de ' . $pessoa['nome'] . ' em QR Codes (colhedor/embalador).';
                } elseif ($efet === 'colheita' && !$atvColh) {
                    $res['msg'] = 'Configure a atividade de colheita no posto.';
                } elseif ($efet === 'embalamento' && !$atvEmb) {
                    $res['msg'] = 'Configure a atividade de embalamento no posto.';
                } elseif ($efet === 'embalamento' && !($chkRec = ph_embalamento_recepcao_check($data))['ok']) {
                    /* elo recepção→embalamento em modo BLOQUEIA (19/08) */
                    $res['msg'] = (string)$chkRec['msg'];
                } else {
                    $atvId  = $efet === 'colheita' ? $atvColh : $atvEmb;
                    $talho  = $efet === 'colheita' ? $talhaoId : null;
                    $safra  = $efet === 'colheita' ? apu_safra_talhao($talhaoId) : null;
                    $pesoCx = (float)vero_srv_param('colheita.peso_caixa_kg', '0');
                    $avisoRec = $efet === 'embalamento' ? (string)(($chkRec['msg'] ?? '') ?: '') : '';
                    try {
                        $r = vero_srv_producao_incrementar_cracha(
                            $data, $atvId, $talho, $safra, $cracha, 1.0, $pesoCx > 0 ? $pesoCx : null);
                        $_SESSION['apu_last_beep'] = ['cracha' => $cracha, 'ts' => microtime(true)];
                        $res['ok'] = true;
                        $res['status'] = 'ok';
                        $res['msg'] = $r['pessoa']['nome'] . ' · ' . ($efet === 'colheita' ? 'Colheita' : 'Embalamento')
                            . ' — ' . numFmt((float)$r['quantidade_total'], 0) . ' caixa(s)'
                            . ((float)$r['valor_total'] > 0 ? ' · prêmio R$ ' . numFmt((float)$r['valor_total'], 2) : '')
                            . ($avisoRec !== '' ? ' · ⚠ ' . $avisoRec : ''); /* recepção em modo AVISA */
                    } catch (Throwable $e) {
                        $m = $e->getMessage();
                        $res['msg'] = str_starts_with($m, 'CRACHA_INVALIDO:') ? mb_substr($m, 16) : $m;
                    }
                }
            }
        }
    }

    $rows = apu_tally($data, $atvColh, $talhaoId, $atvEmb);
    $res['tally'] = apu_tally_json($rows);
    $res['count'] = count($rows);

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }
    vero_flash($res['status'] === 'ok' ? 'ok' : ($res['status'] === 'dupe' ? 'aviso' : 'erro'), $res['msg']);
    vero_redirect($back);
}

/* Nomes das atividades auto-resolvidas (só para exibir no card de Leitura). */
$valvulas = vero_options('agro_talhoes', 'codigo');
$nomeColh = $atvColh ? (string)(vero_val("SELECT nome FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t", [':i' => $atvColh, ':t' => vero_tenant()]) ?? '') : '';
$nomeEmb  = $atvEmb  ? (string)(vero_val("SELECT nome FROM agro_tipos_atividade WHERE id=:i AND tenant_id=:t", [':i' => $atvEmb, ':t' => vero_tenant()]) ?? '') : '';

$tally = apu_tally($data, $atvColh, $talhaoId, $atvEmb);

$GUARD      = ['macro' => 'packing', 'micro' => 'apontar'];
$PAGE_VIEW  = 'packing_apontar';
$PAGE_TITLE = 'Colheita e Embalamento por Caixa';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('packing.apontar.editar');
?>
<style>
  #apu-feed{margin:0 16px 12px;padding:10px 14px;border-radius:8px;font-weight:600;display:none}
  #apu-feed.ok{display:block;background:#e6f4ea;color:#1e7d43;border:1px solid #b7e0c4}
  #apu-feed.dupe{display:block;background:#fff7e6;color:#8a6100;border:1px solid #f2d9a0}
  #apu-feed.erro{display:block;background:#fdecec;color:#b0281a;border:1px solid #f2b8b1}
  @media (prefers-color-scheme:dark){
    #apu-feed.ok{background:#12331f;color:#7fd6a0;border-color:#1f5030}
    #apu-feed.dupe{background:#33270f;color:#e6b95a;border-color:#5a4620}
    #apu-feed.erro{background:#3a1714;color:#e6897c;border-color:#5e2620}
  }
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Colheita e Embalamento por Caixa', 'A leitura é feita em conjunto: bipe a etiqueta da pessoa e o sistema conta colheita ou embalamento pela Função dela.', null) ?>

  <div class="vcard">
    <div class="vtoolbar"><strong>Posto</strong>
      <span class="vhint">Escaneie o romaneio (puxa data e válvula da colheita); a atividade é automática.</span></div>
    <form method="get" class="vgrid" style="padding:14px 16px;grid-template-columns:repeat(4,1fr)">
      <div class="vfield"><label>Data</label><input type="date" name="data" value="<?= h($data) ?>"></div>
      <div class="vfield"><label>Romaneio (colheita)</label>
        <input type="text" id="apu-romaneio" name="romaneio" value="<?= h($romaneio) ?>" autocomplete="off" placeholder="Escaneie/digite">
        <div class="vhint" id="apu-rom-hint"></div></div>
      <?= vero_f_select('talhao_id', 'Válvula (colheita)', $valvulas, $talhaoId, false, '— Sem válvula —') ?>
      <?= vero_f_select('modo_ambos', 'Modo p/ "Ambos"', ['colheita' => 'Colheita', 'embalamento' => 'Embalamento'], $modoAmbos, true, '') ?>
      <div class="full"><button type="submit" class="vbtn vbtn-ghost">Configurar posto</button></div>
    </form>
  </div>

  <script>
  (function(){
    var rom = document.getElementById('apu-romaneio');
    if (!rom) return;
    var form = rom.closest('form');
    var hint = document.getElementById('apu-rom-hint');
    function lookup(){
      var v = rom.value.trim();
      if (!v){ hint.textContent = ''; return; }
      var fd = new FormData();
      fd.append('csrf_token', '<?= h(csrf()) ?>');
      fd.append('acao', 'romaneio_lookup');
      fd.append('romaneio', v);
      fetch(location.pathname, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'})
        .then(function(r){ return r.json(); }).then(function(d){
          if (!d.ok){ hint.textContent = d.msg || 'Romaneio não encontrado.'; hint.style.color = '#b0281a'; return; }
          hint.textContent = d.msg + ' — pré-preenchido abaixo (editável)'; hint.style.color = '';
          var di = form.querySelector('[name="data"]');        if (di && d.data) di.value = d.data;
          var ti = form.querySelector('[name="talhao_id"]');   if (ti && d.talhao_id) ti.value = String(d.talhao_id);
          var ci = form.querySelector('[name="colheita_id"]'); if (ci && d.atividade_id) ci.value = String(d.atividade_id);
        }).catch(function(){ hint.textContent = 'Falha ao consultar o romaneio.'; hint.style.color = '#b0281a'; });
    }
    rom.addEventListener('change', lookup);
    rom.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); lookup(); } });
  })();
  </script>

  <?php if ($configOk): ?>
    <div class="vcard" style="margin-top:14px">
      <div class="vtoolbar"><strong>Leitura</strong>
        <span class="vhint">
          <?= $atvColh ? 'Colheita: ' . h($nomeColh) . ($talhaoId ? ' · válvula ' . h((string)($valvulas[$talhaoId] ?? '')) : '') : '' ?>
          <?= ($atvColh && $atvEmb) ? ' · ' : '' ?>
          <?= $atvEmb ? 'Embalamento: ' . h($nomeEmb) : '' ?>
        </span></div>
      <?php if ($podeEditar): ?>
      <div id="apu-feed"></div>
      <form method="post" class="vgrid" style="padding:14px 16px" id="apu-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="beep">
        <input type="hidden" name="data" value="<?= h($data) ?>">
        <input type="hidden" name="modo_ambos" value="<?= h($modoAmbos) ?>">
        <?php if ($talhaoId): ?><input type="hidden" name="talhao_id" value="<?= $talhaoId ?>"><?php endif; ?>
        <div class="vfield"><label>Caixa · etiqueta da pessoa</label>
          <input type="text" name="cracha" id="apu-cracha" autofocus autocomplete="off" placeholder="Bipe a etiqueta (QR/barras) — colhedor ou embalador"></div>
        <div class="full"><button type="submit" class="vbtn vbtn-primary">Registrar caixa (+1)</button></div>
      </form>
      <?php else: ?>
        <div class="vempty">Sem permissão para registrar produção.</div>
      <?php endif; ?>
    </div>

    <div class="vcard" style="margin-top:14px">
      <div class="vtoolbar"><strong>Apontado hoje</strong>
        <span class="vhint"><span id="apu-count"><?= count($tally) ?></span> pessoa(s)</span></div>
      <table class="vtable" id="apu-tally">
        <thead><tr><th>Pessoa</th><th>Modo</th><th>Vínculo</th><th style="text-align:right">Caixas</th><th style="text-align:right">Prêmio (R$)</th></tr></thead>
        <tbody id="apu-tally-body">
          <?php foreach ($tally as $p): ?>
            <tr>
              <td><strong><?= h((string)$p['pessoa']) ?: '—' ?></strong></td>
              <td><span class="vbadge <?= $p['modo'] === 'Colheita' ? 'vb-ok' : 'vb-info' ?>"><?= h((string)$p['modo']) ?></span></td>
              <td><span class="vbadge <?= $p['origem_pessoa'] === 'terceirizado' ? 'vb-warn' : 'vb-info' ?>"><?= $p['origem_pessoa'] === 'terceirizado' ? 'Terceirizado' : 'Colaborador' ?></span></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$p['quantidade'], 0) ?></td>
              <td class="vnum" style="text-align:right"><?= numFmt((float)$p['valor_total'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="vempty" id="apu-tally-vazio"<?= $tally ? ' style="display:none"' : '' ?>>Nada apontado ainda neste posto.</div>
    </div>

    <?php if ($podeEditar): ?>
    <script>
    (function(){
      var form = document.getElementById('apu-form');
      if (!form) return;
      var input = document.getElementById('apu-cracha');
      var feed  = document.getElementById('apu-feed');
      var body  = document.getElementById('apu-tally-body');
      var vazio = document.getElementById('apu-tally-vazio');
      var count = document.getElementById('apu-count');
      var busy = false, lastCode = '', lastTs = 0;

      function beepSound(ok){
        try{
          var ac = window.__apuAC || (window.__apuAC = new (window.AudioContext||window.webkitAudioContext)());
          var o = ac.createOscillator(), g = ac.createGain();
          o.type = 'sine'; o.frequency.value = ok ? 880 : 220; g.gain.value = 0.07;
          o.connect(g); g.connect(ac.destination);
          o.start(); o.stop(ac.currentTime + (ok ? 0.09 : 0.22));
        }catch(e){}
      }
      function numFmt(n, d){ return Number(n).toLocaleString('pt-BR', {minimumFractionDigits:d, maximumFractionDigits:d}); }
      function esc(s){ var el=document.createElement('div'); el.textContent=s; return el.innerHTML; }
      function render(tally, c){
        count.textContent = c;
        if (!tally.length){ body.innerHTML=''; vazio.style.display=''; return; }
        vazio.style.display='none';
        body.innerHTML = tally.map(function(p){
          var terc = p.vinculo === 'terceirizado';
          var colh = p.modo === 'Colheita';
          return '<tr><td><strong>'+esc(p.pessoa||'—')+'</strong></td>'
            + '<td><span class="vbadge '+(colh?'vb-ok':'vb-info')+'">'+esc(p.modo)+'</span></td>'
            + '<td><span class="vbadge '+(terc?'vb-warn':'vb-info')+'">'+(terc?'Terceirizado':'Colaborador')+'</span></td>'
            + '<td class="vnum" style="text-align:right">'+numFmt(p.caixas,0)+'</td>'
            + '<td class="vnum" style="text-align:right">'+numFmt(p.premio,2)+'</td></tr>';
        }).join('');
      }
      function flash(status, msg){ feed.className = status; feed.textContent = msg; }

      form.addEventListener('submit', function(e){
        e.preventDefault();
        var code = input.value.trim();
        if (!code || busy) { input.focus(); return; }
        var now = Date.now();
        if (code === lastCode && (now - lastTs) < <?= APU_DEBOUNCE_MS ?>){ input.value=''; input.focus(); return; }
        lastCode = code; lastTs = now; busy = true;
        var fd = new FormData(form);
        fetch(form.action || location.href, {
          method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'
        }).then(function(r){ return r.json(); }).then(function(d){
          flash(d.status || 'erro', d.msg || 'Erro na leitura.');
          beepSound(d.status === 'ok');
          if (d.tally) render(d.tally, d.count);
        }).catch(function(){
          flash('erro','Falha de rede — tente de novo.'); beepSound(false);
        }).finally(function(){
          busy = false; input.value=''; input.focus();
        });
      });
      input.focus();
    })();
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php'; ?>
