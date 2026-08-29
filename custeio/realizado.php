<?php
/* ============================================================
   VERO — Custos / Realizado vs Planejado  (tela real, leitura)
   Substitui o mock. Rota: /custeio/realizado.php
   Guard: custos.realizado_planejado
   Orçamento da safra (vigente, ou o mais recente) × custeio
   realizado por categoria, com desvio e % consumido.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const CATEGORIAS_ORC = [
    'mao_de_obra' => 'Mão de obra',
    'insumos'     => 'Insumos',
    'maquinas'    => 'Máquinas',
    'irrigacao'   => 'Irrigação',
    'outros'      => 'Outros',
];

$t = vero_tenant();
$fSafra = (int)($_GET['safra'] ?? 0);
$safras = vero_options('agro_safras', 'identificacao');
if ($fSafra === 0 && $safras) $fSafra = (int)array_key_first($safras);

$orc = null;
$previsto = [];
$realizado = [];
if ($fSafra > 0) {
    $orc = vero_row(
        "SELECT * FROM custeio_orcamentos WHERE tenant_id = :t AND safra_id = :s
          ORDER BY FIELD(status,'vigente','rascunho','encerrado'), id DESC LIMIT 1",
        [':t' => $t, ':s' => $fSafra]);
    if ($orc) {
        foreach (vero_rows("SELECT categoria, SUM(valor_previsto) AS v FROM custeio_orcamento_itens
                             WHERE tenant_id = :t AND orcamento_id = :o GROUP BY categoria",
            [':t' => $t, ':o' => (int)$orc['id']]) as $r) {
            $previsto[(string)$r['categoria']] = (float)$r['v'];
        }
    }
    foreach (vero_rows("SELECT COALESCE(categoria,'outros') AS categoria, SUM(valor) AS v
                          FROM custeio_lancamentos WHERE tenant_id = :t AND safra_id = :s GROUP BY categoria",
        [':t' => $t, ':s' => $fSafra]) as $r) {
        $realizado[(string)$r['categoria']] = (float)$r['v'];
    }
}

$categorias = array_unique(array_merge(array_keys(CATEGORIAS_ORC), array_keys($previsto), array_keys($realizado)));
$totPrev = array_sum($previsto);
$totReal = array_sum($realizado);

/* Rótulos de categorias que aparecem no REALIZADO mas não estão no orçamento
   (ex.: depreciação vinda do patrimônio). Só nomeia slugs reais — não inventa
   nenhum valor. Fallback humaniza o slug. */
$rotuloExtra = [
    'depreciacao'  => 'Depreciação',
    'fertilizantes'=> 'Fertilizantes',
    'servicos'     => 'Serviços',
    'terceiros'    => 'Terceiros',
    'combustivel'  => 'Combustível',
    'manutencao'   => 'Manutenção',
    'outros'       => 'Outros',
];
$rotuloCat = static fn(string $c): string =>
    CATEGORIAS_ORC[$c] ?? $rotuloExtra[$c] ?? ucfirst(str_replace('_', ' ', $c));

/* ── Monta as linhas (previsto × realizado × desvio × % consumido) uma única
   vez; alimenta KPIs (server-side), a lista de consumo (server-side) e o
   payload dos gráficos ECharts. Tudo derivado das mesmas queries reais acima. */
$linhas = [];
foreach ($categorias as $cat) {
    $p = $previsto[(string)$cat] ?? 0.0;
    $r = $realizado[(string)$cat] ?? 0.0;
    if ($p == 0.0 && $r == 0.0) continue;
    $pct = $p > 0 ? $r / $p * 100 : null;               // null = gasto sem previsão
    $linhas[] = [
        'cat'    => (string)$cat,
        'label'  => $rotuloCat((string)$cat),
        'prev'   => round($p, 2),
        'real'   => round($r, 2),
        'desvio' => round($r - $p, 2),
        'pct'    => $pct !== null ? round($pct, 1) : null,
    ];
}
$estouros = 0;
foreach ($linhas as $l) {
    if ($l['pct'] === null ? $l['real'] > 0 : $l['real'] > $l['prev']) $estouros++;
}
$desvioTot = $totReal - $totPrev;
$pctTot    = $totPrev > 0 ? $totReal / $totPrev * 100 : null;

$GUARD      = ['macro' => 'custos', 'micro' => 'realizado_planejado'];
$PAGE_VIEW  = 'custos_realizado_planejado';
$PAGE_TITLE = 'Realizado vs Planejado';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<style>
/* ===== Realizado vs Planejado A4 — escopado em .crp (tokens VERO, casca clara) ===== */
.crp{--ac:#005059;--acd:#00363D;--ac3:#2A767C;--warm:#FBF8F2;--track:#EEE6D6;
  --ink:#241B14;--ink2:#2B2018;--mut:#8A7C68;--mut2:#9A8C78;--bd:#E3D9C8;--bd2:#DDD2BF;
  --pos:#0E7E72;--pos-bg:#DDEDEB;--amber:#B57C1A;--amber-d:#7A5410;--amber-bg:#F3E7C8;
  --danger:#B23A2E;--danger-bg:#F2DCD8;--num:'IBM Plex Mono',ui-monospace,monospace}
.crp .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:16px}
.crp .kpi{background:#fff;border:1px solid var(--bd);border-radius:13px;padding:15px 16px;box-shadow:0 1px 2px rgba(36,27,20,.05);position:relative;overflow:hidden}
.crp .kpi .strip{position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--ac3)}
.crp .kpi .lab{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);font-weight:700;margin-bottom:7px}
.crp .kpi .val{font-family:var(--num);font-size:22px;font-weight:600;line-height:1.05;letter-spacing:-1px;white-space:nowrap;color:var(--ink)}
.crp .kpi .sub{font-size:11px;color:var(--mut);margin-top:6px;font-weight:500}
.crp .kpi.pos .strip{background:var(--pos)}.crp .kpi.pos .val{color:var(--pos)}
.crp .kpi.danger .strip{background:var(--danger)}.crp .kpi.danger .val{color:var(--danger)}
.crp .kpi.amber .strip{background:var(--amber)}.crp .kpi.amber .val{color:var(--amber-d)}
.crp .fgrid{display:grid;gap:16px}
.crp .g2{grid-template-columns:1.5fr 1fr}
.crp .fcard{background:#fff;border:1px solid var(--bd);border-radius:13px;box-shadow:0 1px 2px rgba(36,27,20,.05);padding:16px 18px 10px;display:flex;flex-direction:column;min-width:0}
.crp .fcard h3{font-size:14.5px;font-weight:700;letter-spacing:-.2px;color:var(--ink2)}
.crp .fcard .desc{font-size:11.5px;color:var(--mut);font-weight:500;margin-top:2px}
.crp .legend-inline{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:var(--mut);font-weight:600;padding:6px 2px 10px}
.crp .legend-inline i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.crp .fchart{width:100%}
.crp .mt16{margin-top:16px}
/* barras de % consumido (server-side, degrada sem JS) */
.crp .cons{padding:4px 2px 8px}
.crp .crow{display:grid;grid-template-columns:130px 1fr 112px;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--bd)}
.crp .crow:last-child{border-bottom:0}
.crp .crow .nm{font-weight:700;font-size:12.5px;color:var(--ink)}
.crp .crow .nm small{display:block;color:var(--mut);font-weight:500;font-size:10.5px;font-family:var(--num)}
.crp .ctr{position:relative;height:20px;border-radius:6px;background:var(--track);overflow:hidden}
.crp .cfill{position:absolute;left:0;top:0;bottom:0;border-radius:6px}
.crp .c100{position:absolute;top:-2px;bottom:-2px;width:2px;background:var(--ink);opacity:.55;z-index:3}
.crp .cval{text-align:right;font-family:var(--num);font-size:13px;font-weight:600}
.crp .cvsub{font-size:9px;font-weight:800;text-transform:uppercase;display:block;letter-spacing:.3px}
.crp .fnote{padding:10px 2px;font-size:11.5px;color:var(--mut2)}
.crp .fempty{padding:40px 14px;text-align:center;color:var(--mut2);font-size:13px}
@media(max-width:1080px){.crp .kpis{grid-template-columns:repeat(2,1fr)}.crp .g2{grid-template-columns:1fr}}
@media(max-width:520px){.crp .kpis{grid-template-columns:1fr}}
</style>

<div class="vwrap crp">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Realizado vs Planejado', 'Custeio realizado contra o orçamento da safra, categoria a categoria', null) ?>

  <div class="vcard" style="margin-bottom:16px">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <label class="vhint">Safra</label>
        <select name="safra" onchange="this.form.submit()">
          <?php foreach ($safras as $sid => $sn): ?>
            <option value="<?= $sid ?>"<?= $fSafra === (int)$sid ? ' selected' : '' ?>><?= h($sn) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="vsub" style="margin-left:auto">
        <?php if ($orc): ?>
          orçamento <?= h((string)$orc['status']) ?> ·
          previsto <strong class="vnum">R$ <?= numFmt($totPrev, 2) ?></strong> ·
          realizado <strong class="vnum">R$ <?= numFmt($totReal, 2) ?></strong>
        <?php else: ?>
          sem orçamento para esta safra — <a href="<?= $base ?>/custeio/orcamento.php">criar orçamento</a>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <?php if (!$linhas): ?>
    <div class="vcard"><div class="fempty">
      Nenhum orçamento nem custeio para esta safra.<br>
      Planeje em <a href="<?= $base ?>/custeio/orcamento.php">Orçamento de Safra</a> e lance os custos em
      <a href="<?= $base ?>/custeio/custos.php">Custeio</a>.
    </div></div>
  <?php else: ?>

  <!-- KPIs (render server-side; degrada sem JS) -->
  <div class="kpis">
    <div class="kpi"><div class="strip"></div>
      <div class="lab">Orçamento previsto</div>
      <div class="val">R$ <?= numFmt($totPrev, 2) ?></div>
      <div class="sub">custo planejado da safra</div></div>
    <div class="kpi <?= $totReal > $totPrev ? 'danger' : 'pos' ?>"><div class="strip"></div>
      <div class="lab">Custo realizado</div>
      <div class="val">R$ <?= numFmt($totReal, 2) ?></div>
      <div class="sub">lançamentos amarrados à safra</div></div>
    <div class="kpi <?= $desvioTot > 0 ? 'danger' : 'pos' ?>"><div class="strip"></div>
      <div class="lab">Desvio total</div>
      <div class="val"><?= $desvioTot > 0 ? '+' : '' ?>R$ <?= numFmt($desvioTot, 2) ?></div>
      <div class="sub"><?= $pctTot !== null ? numFmt($pctTot, 1) . '% do previsto consumido' : 'sem orçamento p/ comparar' ?></div></div>
    <div class="kpi <?= $estouros ? 'amber' : 'pos' ?>"><div class="strip"></div>
      <div class="lab">Categorias estouradas</div>
      <div class="val"><?= (int)$estouros ?></div>
      <div class="sub">acima do orçamento previsto</div></div>
  </div>

  <?php if ($totReal == 0.0): ?>
    <div class="vcard" style="margin-bottom:16px"><div class="fnote" style="padding:14px">
      Ainda não há <strong>custo realizado</strong> lançado para esta safra — os gráficos de comparação
      mostram apenas o previsto. Lance os custos em <a href="<?= $base ?>/custeio/custos.php">Custeio</a>
      para acompanhar o realizado.
    </div></div>
  <?php endif; ?>

  <!-- Linha 1: desvio por categoria + composição do realizado -->
  <div class="fgrid g2">
    <div class="fcard">
      <div><h3>Desvio orçamentário por categoria</h3>
        <div class="desc">Realizado − previsto · verde = economia, vermelho = estouro</div></div>
      <div class="fchart" id="rpVar" style="height:320px"></div>
    </div>
    <div class="fcard">
      <div><h3>Composição do realizado</h3>
        <div class="desc">Onde o custo foi efetivamente gasto</div></div>
      <div class="fchart" id="rpDonut" style="height:320px"></div>
    </div>
  </div>

  <!-- Linha 2: previsto × realizado agrupado + % consumido (bullets) -->
  <div class="fgrid g2 mt16">
    <div class="fcard">
      <div><h3>Previsto × Realizado por categoria</h3>
        <div class="desc">Comparação direta do orçamento com o executado</div></div>
      <div class="legend-inline">
        <span><i style="background:var(--track);border:1px solid var(--bd2)"></i>Previsto</span>
        <span><i style="background:var(--ac)"></i>Realizado</span>
        <span><i style="background:var(--danger)"></i>Realizado (estouro)</span>
      </div>
      <div class="fchart" id="rpGroup" style="height:320px"></div>
    </div>
    <div class="fcard">
      <div><h3>% consumido do orçamento</h3>
        <div class="desc">Barra até 100% do previsto — acima = estouro</div></div>
      <div class="cons">
        <?php
          $ord = $linhas;
          usort($ord, static fn($a, $b) => ($b['pct'] ?? 999) <=> ($a['pct'] ?? 999));
          $maxP = 120.0;
          foreach ($ord as $l) if ($l['pct'] !== null) $maxP = max($maxP, min($l['pct'], 300.0));
          foreach ($ord as $l):
            $c100 = 100 / $maxP * 100;
            if ($l['pct'] === null):
              // gasto sem previsão = estouro
        ?>
          <div class="crow">
            <div class="nm"><?= h($l['label']) ?><small>sem previsão · R$ <?= numFmt($l['real'], 2) ?></small></div>
            <div class="ctr"><div class="cfill" style="width:100%;background:var(--danger)"></div>
              <div class="c100" style="left:<?= number_format($c100, 2, '.', '') ?>%"></div></div>
            <div class="cval" style="color:var(--danger)">s/ orç.<span class="cvsub">estouro</span></div>
          </div>
        <?php else:
              $w   = min($l['pct'], $maxP) / $maxP * 100;
              $col = $l['pct'] > 100 ? 'var(--danger)' : ($l['pct'] > 85 ? 'var(--amber)' : 'var(--pos)');
              $lab = $l['pct'] > 100 ? 'Estouro' : ($l['pct'] > 85 ? 'No limite' : 'Sob controle');
        ?>
          <div class="crow">
            <div class="nm"><?= h($l['label']) ?><small>R$ <?= numFmt($l['real'], 0) ?> / <?= numFmt($l['prev'], 0) ?></small></div>
            <div class="ctr"><div class="cfill" style="width:<?= number_format($w, 2, '.', '') ?>%;background:<?= $col ?>"></div>
              <div class="c100" style="left:<?= number_format($c100, 2, '.', '') ?>%"></div></div>
            <div class="cval" style="color:<?= $col ?>"><?= numFmt($l['pct'], 0) ?>%<span class="cvsub"><?= $lab ?></span></div>
          </div>
        <?php endif; endforeach; ?>
      </div>
    </div>
  </div>

  <div class="fnote" style="text-align:center;margin-top:18px">
    Realizado = lançamentos de custeio amarrados à safra. Categorias com gasto e sem previsão contam como
    estouro — ajuste o orçamento em <a href="<?= $base ?>/custeio/orcamento.php">Orçamento de Safra</a>.
  </div>
  <?php endif; ?>
</div>

<?php if ($linhas): ?>
<script defer src="<?= $base ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
(function(){
  var D = <?= jsvar($linhas) ?>;
  var C = {pos:'#0E7E72',danger:'#B23A2E',ac:'#005059',ac3:'#2A767C',amber:'#B57C1A',
           track:'#EEE6D6',bd:'#E3D9C8',bd2:'#DDD2BF',mut:'#8A7C68',ink:'#241B14',surface:'#fff'};
  var MONO = "'IBM Plex Mono',ui-monospace,monospace";
  var PAL = [C.ac, C.ac3, C.pos, C.amber, '#1E7FA8', C.danger, '#C3B49E'];

  var brl  = function(v){ return v==null ? '—' : 'R$ ' + Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
  var brl0 = function(v){ return Number(v).toLocaleString('pt-BR',{maximumFractionDigits:0}); };
  var kAxis= function(v){ return Math.abs(v)>=1000 ? (v/1000).toLocaleString('pt-BR',{maximumFractionDigits:1})+'k' : String(v); };
  var tip  = { backgroundColor:C.surface, borderColor:C.bd, textStyle:{color:C.ink,fontFamily:"'IBM Plex Sans',sans-serif",fontSize:12},
    extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px' };
  var axC  = { axisLine:{lineStyle:{color:C.bd2}}, axisTick:{show:false}, axisLabel:{color:C.mut,fontSize:11}, splitLine:{lineStyle:{color:C.bd}} };
  var axV  = function(){ return { type:'value', axisLine:{lineStyle:{color:C.bd2}}, axisTick:{show:false},
    axisLabel:{color:C.mut,fontSize:11,fontFamily:MONO,formatter:kAxis}, splitLine:{lineStyle:{color:C.bd}} }; };

  var chVar, chDonut, chGroup;

  function renderVar(){
    var rs = D.slice().sort(function(a,b){ return a.desvio - b.desvio; });
    chVar.setOption({
      tooltip:Object.assign({trigger:'item',formatter:function(p){ var r=rs[p.dataIndex];
        return '<b>'+r.label+'</b><br/>Previsto: '+brl(r.prev)+'<br/>Realizado: '+brl(r.real)+
               '<br/>Desvio: <b>'+(r.desvio>0?'+':'')+brl(r.desvio)+'</b>'; }}, tip),
      grid:{left:120,right:64,top:10,bottom:24},
      xAxis:Object.assign(axV(),{}),
      yAxis:Object.assign({type:'category',data:rs.map(function(r){return r.label;})}, axC,
        {axisLabel:{color:C.ink,fontWeight:600,fontSize:11.5}}),
      series:[{type:'bar',barMaxWidth:20,
        data:rs.map(function(r){ return {value:r.desvio,
          itemStyle:{color:r.desvio>0?C.danger:C.pos,borderRadius:r.desvio>0?[0,5,5,0]:[5,0,0,5]}}; }),
        label:{show:true,position:function(p){return p.value>0?'right':'left';},
          formatter:function(p){return (p.value>0?'+':'')+brl0(p.value);},color:C.mut,fontFamily:MONO,fontSize:10},
        markLine:{silent:true,symbol:'none',data:[{xAxis:0}],lineStyle:{color:C.ink,width:1}}}]
    });
  }

  function renderDonut(){
    var rs = D.filter(function(r){return r.real>0;}).sort(function(a,b){return b.real-a.real;});
    var total = rs.reduce(function(s,r){return s+r.real;},0);
    if(!rs.length){ document.getElementById('rpDonut').innerHTML='<div class="fempty">Sem custo realizado nesta safra.</div>'; return; }
    chDonut.setOption({
      tooltip:Object.assign({trigger:'item',formatter:function(p){return p.name+'<br/>'+brl(p.value)+' · '+p.percent+'%';}}, tip),
      legend:{orient:'vertical',right:2,top:'center',icon:'circle',textStyle:{color:C.mut,fontSize:11},itemGap:9},
      series:[{type:'pie',radius:['52%','76%'],center:['32%','50%'],avoidLabelOverlap:true,
        itemStyle:{borderColor:C.surface,borderWidth:3,borderRadius:4},
        label:{show:true,position:'center',formatter:function(){return '{v|R$ '+brl0(Math.round(total/1000))+'k}\n{l|realizado}';},
          rich:{v:{fontSize:22,fontWeight:700,fontFamily:MONO,color:C.ink},l:{fontSize:11,color:C.mut,padding:[4,0,0,0]}}},
        labelLine:{show:false},
        data:rs.map(function(r,i){ return {name:r.label,value:r.real,itemStyle:{color:PAL[i%PAL.length]}}; })}]
    });
  }

  function renderGroup(){
    chGroup.setOption({
      tooltip:Object.assign({trigger:'axis',axisPointer:{type:'shadow'},valueFormatter:brl}, tip),
      grid:{left:56,right:16,top:14,bottom:70},
      xAxis:Object.assign({type:'category',data:D.map(function(r){return r.label;})}, axC,
        {axisLabel:{color:C.ink,fontWeight:600,fontSize:10,interval:0,rotate:22}}),
      yAxis:axV(),
      series:[
        {type:'bar',name:'Previsto',data:D.map(function(r){return r.prev;}),barMaxWidth:18,
          itemStyle:{color:C.track,borderColor:C.bd2,borderWidth:1,borderRadius:[4,4,0,0]}},
        {type:'bar',name:'Realizado',barMaxWidth:18,
          data:D.map(function(r){ return {value:r.real,
            itemStyle:{color:r.real>r.prev?C.danger:C.ac,borderRadius:[4,4,0,0]}}; })}
      ]
    });
  }

  function boot(){
    if(typeof echarts==='undefined') return;
    var eV=document.getElementById('rpVar'), eD=document.getElementById('rpDonut'), eG=document.getElementById('rpGroup');
    if(!eV) return;
    chVar=echarts.init(eV); chDonut=echarts.init(eD); chGroup=echarts.init(eG);
    renderVar(); renderDonut(); renderGroup();
    window.addEventListener('resize',function(){ [chVar,chDonut,chGroup].forEach(function(c){ if(c) c.resize(); }); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot); else boot();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
