<?php
/* ============================================================
   VERO — Nutrição / Painel de Nutrientes  (dashboard real)
   Rota: /nutricao/painel_nutrientes.php   Guard: nutricao.painel_nutrientes
   Redesenho A4 alinhado ao protótipo docs/vero_nutricao_painel.html,
   dentro da casca VERO (agro_header + vero_page_header + .vcard),
   ECharts vendor LOCAL, CSS escopado em .np.

   TODOS os números vêm do banco (tenant atual) — nada é inventado:
     • faixas    → analise_faixas (ativas) — o RT/laboratório define a
                   referência; sem faixa cadastrada não há classificação (D5).
     • valores   → analise_solo/foliar + *_resultados (valor por
                   talhão × nutriente × safra × matriz; último laudo vence).
     • aplicações→ agro_aplicacoes (tipos nutricionais) — receita do RT.
   Filtros, KPIs e gráficos são calculados no cliente sobre esse recorte
   real; onde falta dado a UI mostra "—" / "sem faixa" / "sem dados".
   O VERO nunca recomenda produto ou dose (regra 1).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

/* ── Catálogo de nutrientes (chave = nutriente_id p/ evitar colisão de
      símbolo — ex.: dois "pH" no catálogo) ─────────────────────────── */
$catRows = vero_rows(
    "SELECT id, simbolo, nome, unidade_padrao, ordem, aplicacao
       FROM analise_nutrientes WHERE tenant_id = :t AND ativo = 1
      ORDER BY ordem, nome", [':t' => $t]);
$nutrientes = [];
foreach ($catRows as $n) {
    $nutrientes[(int)$n['id']] = [
        'sym'   => (string)($n['simbolo'] ?: $n['nome']),
        'nome'  => (string)$n['nome'],
        'ordem' => (int)$n['ordem'],
        'un'    => (string)($n['unidade_padrao'] ?? ''),
    ];
}

/* ── Faixas ativas → [tipo][nutId] = {min,iMin,iMax,max,un,nome,sym}.
      Preferimos a faixa genérica (sem variedade/fenologia) como referência
      do painel; se só houver faixa contextual, usa a primeira. ───────── */
$faixaRows = vero_rows(
    "SELECT f.tipo, f.nutriente_id, n.simbolo, n.nome,
            COALESCE(NULLIF(f.unidade,''), n.unidade_padrao) AS unidade,
            f.minimo, f.ideal_min, f.ideal_max, f.maximo,
            (f.variedade_id IS NULL AND f.fenologia_id IS NULL AND f.variedade_fase_id IS NULL) AS generica
       FROM analise_faixas f
       JOIN analise_nutrientes n ON n.id = f.nutriente_id
      WHERE f.tenant_id = :t AND f.ativo = 1
      ORDER BY f.tipo, n.ordem, generica DESC, f.id", [':t' => $t]);
$faixas = ['solo' => [], 'foliar' => []];
foreach ($faixaRows as $f) {
    $tp = (string)$f['tipo'];
    $nid = (int)$f['nutriente_id'];
    if (!isset($faixas[$tp]) || isset($faixas[$tp][$nid])) continue; // 1ª (genérica) vence
    $faixas[$tp][$nid] = [
        'min'  => $f['minimo']    !== null ? (float)$f['minimo']    : null,
        'iMin' => (float)$f['ideal_min'],
        'iMax' => (float)$f['ideal_max'],
        'max'  => $f['maximo']    !== null ? (float)$f['maximo']    : null,
        'un'   => (string)($f['unidade'] ?? ''),
        'nome' => (string)$f['nome'],
        'sym'  => (string)($f['simbolo'] ?: $f['nome']),
    ];
}
$qtdFaixas = count($faixas['solo']) + count($faixas['foliar']);

/* ── Valores reais por safra × matriz × talhão × nutriente.
      Só análises com talhão (o mapa é talhão × nutriente); o laudo mais
      recente por combinação vence. Resultados sem talhão são contados à
      parte para a nota de cobertura. ─────────────────────────────────── */
$valores = [];              // [safra][tipo][talhaoId][nutId] = valor
$talhoes = [];              // talhaoId => [id, code, fazenda]
$safrasSet = [];            // safra => true
$semTalhao = ['solo' => 0, 'foliar' => 0];
$qtdAnalises = ['solo' => 0, 'foliar' => 0];

foreach (['solo' => ['analise_solo', 'analise_solo_resultados'],
          'foliar' => ['analise_foliar', 'analise_foliar_resultados']] as $tipo => [$ta, $tr]) {

    $qtdAnalises[$tipo] = (int)vero_val("SELECT COUNT(*) FROM {$ta} WHERE tenant_id = :t", [':t' => $t]);
    $semTalhao[$tipo]   = (int)vero_val(
        "SELECT COUNT(*) FROM {$tr} r JOIN {$ta} a ON a.id = r.analise_id
          WHERE r.tenant_id = :t AND a.talhao_id IS NULL", [':t' => $t]);

    /* ordena ascendente por data/id → o último gravado sobrescreve (mais recente vence) */
    $rows = vero_rows(
        "SELECT a.talhao_id, tl.codigo AS talhao_cod, fz.nome AS fazenda,
                COALESCE(sa.identificacao, '— Sem safra —') AS safra,
                r.nutriente_id, r.valor
           FROM {$tr} r
           JOIN {$ta} a         ON a.id = r.analise_id AND a.tenant_id = r.tenant_id
           JOIN agro_talhoes tl ON tl.id = a.talhao_id
           LEFT JOIN agro_fazendas fz ON fz.id = COALESCE(a.fazenda_id, tl.fazenda_id)
           LEFT JOIN agro_safras sa   ON sa.id = a.safra_id
          WHERE r.tenant_id = :t AND a.talhao_id IS NOT NULL
          ORDER BY a.data_amostra, a.id", [':t' => $t]);

    foreach ($rows as $r) {
        $tid = (int)$r['talhao_id'];
        $saf = (string)$r['safra'];
        $nid = (int)$r['nutriente_id'];
        $safrasSet[$saf] = true;
        if (!isset($talhoes[$tid])) {
            $talhoes[$tid] = ['id' => $tid, 'code' => (string)$r['talhao_cod'], 'fazenda' => (string)($r['fazenda'] ?? '')];
        }
        $valores[$saf][$tipo][$tid][$nid] = (float)$r['valor'];
    }
}

/* safras: reais em ordem desc, "— Sem safra —" por último */
$safras = array_keys($safrasSet);
usort($safras, static function ($a, $b) {
    if ($a === '— Sem safra —') return 1;
    if ($b === '— Sem safra —') return -1;
    return strcmp($b, $a);
});

/* fazendas presentes (para o filtro) */
$fazendasSet = [];
foreach ($talhoes as $tl) if ($tl['fazenda'] !== '') $fazendasSet[$tl['fazenda']] = true;
$fazendas = array_keys($fazendasSet);
sort($fazendas);

/* ── Aplicações nutricionais reais (recorte de agro_aplicacoes) ─────── */
$TIPOS_APLIC = ['fertirrigacao' => 'Fertirrigação', 'foliar' => 'Adubação foliar', 'indutor_brotacao' => 'Indutor de brotação'];
$aplicRows = vero_rows(
    "SELECT ap.tipo, COUNT(*) AS n, COALESCE(SUM(ap.custo_total), 0) AS custo
       FROM agro_aplicacoes ap
      WHERE ap.tenant_id = :t AND ap.tipo IN ('fertirrigacao','foliar','indutor_brotacao')
        AND ap.status <> 'cancelada'
      GROUP BY ap.tipo", [':t' => $t]);
$aplicacoes = [];
foreach ($aplicRows as $a) {
    $aplicacoes[] = ['tipo' => $TIPOS_APLIC[(string)$a['tipo']] ?? ucfirst((string)$a['tipo']),
                     'n' => (int)$a['n'], 'custo' => (float)$a['custo']];
}

/* paleta VERO (light) — passada ao JS e usada no CSS escopado */
$C = ['accent' => '#005059', 'deep' => '#00363D', 'a3' => '#2A767C',
      'pos' => '#0E7E72', 'posbg' => '#DDEDEB', 'amber' => '#B57C1A', 'amberd' => '#7A5410',
      'amberbg' => '#F3E7C8', 'danger' => '#B23A2E', 'dangerbg' => '#F0DAD5',
      'track' => '#EEE6D6', 'border' => '#E3D9C8', 'muted' => '#8A7C68', 'ink' => '#241B14'];

$VERO_DATA = [
    'nutrientes' => $nutrientes,
    'faixas'     => $faixas,
    'valores'    => $valores,
    'talhoes'    => array_values($talhoes),
    'safras'     => $safras,
    'fazendas'   => $fazendas,
    'aplicacoes' => $aplicacoes,
    'C'          => $C,
];

$temDados = !empty($talhoes) || !empty($aplicacoes);

$GUARD      = ['macro' => 'nutricao', 'micro' => 'painel_nutrientes'];
$PAGE_VIEW  = 'nutricao_painel_nutrientes';
$PAGE_TITLE = 'Painel de Nutrientes';
$EXTRA_HEAD = vero_assets() . <<<CSS
<style>
.np-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;background:#fff;
  border:1px solid {$C['border']};border-radius:14px;padding:14px 16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(43,32,24,.05)}
.np-fgrp{display:flex;flex-direction:column;gap:5px}
.np-fgrp label{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:{$C['muted']};font-weight:700}
.np-fgrp select{font:600 13px 'IBM Plex Sans',sans-serif;color:{$C['ink']};background:#FBF8F2;
  border:1px solid #DDD2BF;border-radius:8px;padding:8px 12px;cursor:pointer;min-width:150px}
.np-seg{display:inline-flex;background:{$C['track']};border-radius:8px;padding:3px;gap:2px}
.np-seg button{border:0;background:transparent;color:{$C['muted']};font:700 12.5px 'IBM Plex Sans';
  padding:6px 16px;border-radius:6px;cursor:pointer;transition:.15s}
.np-seg button.on{background:#fff;color:{$C['accent']};box-shadow:0 1px 3px rgba(0,0,0,.1)}
.np-reset{margin-left:auto;align-self:center;color:{$C['muted']};font-size:12.5px;font-weight:600;cursor:pointer;
  border:0;background:none;text-decoration:underline;text-underline-offset:2px}
.np-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.np-kpi{position:relative;overflow:hidden;padding:16px 18px}
.np-kpi .strip{position:absolute;left:0;top:0;bottom:0;width:4px}
.np-kpi .lab{font-size:10.5px;text-transform:uppercase;letter-spacing:.8px;color:{$C['muted']};font-weight:700;margin-bottom:8px}
.np-kpi .val{font-family:'IBM Plex Mono',monospace;font-size:34px;font-weight:600;line-height:1;letter-spacing:-1px;color:{$C['ink']}}
.np-kpi .sub{font-size:12px;color:{$C['muted']};margin-top:7px;font-weight:500}
.np-kpi.pos .strip{background:{$C['pos']}} .np-kpi.pos .val{color:{$C['pos']}}
.np-kpi.amber .strip{background:{$C['amber']}} .np-kpi.amber .val{color:{$C['amber']}}
.np-kpi.danger .strip{background:{$C['danger']}} .np-kpi.danger .val{color:{$C['danger']}}
.np-kpi.neutral .strip{background:{$C['a3']}}
.np-grid{display:grid;gap:16px;align-items:start}
.np-g2{grid-template-columns:1.55fr 1fr}
.np-g2b{grid-template-columns:1fr 1fr}
.np-cardhd{padding:14px 18px 8px}
.np-cardhd h3{font-size:14.5px;font-weight:700;color:{$C['ink']};margin:0}
.np-cardhd .desc{font-size:11.5px;color:{$C['muted']};font-weight:500;margin-top:2px}
.np-body{padding:2px 18px 14px}
.np-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:{$C['muted']};font-weight:600;padding:0 18px 6px}
.np-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.np-chart{width:100%}
.np-empty{padding:34px 18px;text-align:center;color:{$C['muted']};font-size:12.5px}
.np-brow{display:grid;grid-template-columns:120px 1fr 96px;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid #EEE8DB}
.np-brow:last-child{border-bottom:0}
.np-brow .nm{font-weight:700;font-size:13px;color:{$C['ink']}}
.np-brow .nm small{display:block;color:{$C['muted']};font-weight:500;font-size:10.5px}
.np-track{position:relative;height:22px;border-radius:6px;background:{$C['track']};overflow:hidden}
.np-band{position:absolute;top:0;bottom:0}
.np-band.lo{background:{$C['dangerbg']}} .np-band.ok{background:{$C['posbg']}} .np-band.hi{background:{$C['amberbg']}}
.np-mark{position:absolute;top:-3px;bottom:-3px;width:3px;background:{$C['ink']};border-radius:2px;z-index:3}
.np-bval{text-align:right;font-family:'IBM Plex Mono',monospace;font-size:13px;font-weight:600;color:{$C['ink']}}
.np-bstat{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;display:block;margin-top:2px}
.np-bstat.lo{color:{$C['danger']}} .np-bstat.ok{color:{$C['pos']}} .np-bstat.hi{color:{$C['amberd']}}
.np-aitem{display:flex;gap:11px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #EEE8DB}
.np-aitem:last-child{border-bottom:0}
.np-adot{width:9px;height:9px;border-radius:50%;margin-top:5px;flex-shrink:0}
.np-aitem .at{font-size:13px;font-weight:600;color:{$C['ink']}}
.np-aitem .as{font-size:11.5px;color:{$C['muted']};margin-top:1px}
.np-aitem .av{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-weight:600;font-size:12.5px;white-space:nowrap;padding-left:8px}
.np-foot{margin-top:20px;color:#9A8C78;font-size:11.5px;font-weight:500;line-height:1.5}
@media(max-width:1080px){.np-kpis{grid-template-columns:repeat(2,1fr)}.np-g2,.np-g2b{grid-template-columns:1fr}}
</style>
CSS;
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap np">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Painel de Nutrientes',
        'Situação nutricional por talhão frente às faixas do RT', null) ?>

<?php if (!$temDados): ?>
  <div class="vcard"><div class="np-empty">
    Ainda não há análises de solo/foliar com talhão nem aplicações nutricionais registradas neste tenant.<br>
    Cadastre em <a href="<?= $base ?>/nutricao/analise_solo.php">Análise de Solo</a>,
    <a href="<?= $base ?>/nutricao/analise_foliar.php">Análise Foliar</a> e as faixas em
    <a href="<?= $base ?>/nutricao/faixas_nutricionais.php">Faixas Nutricionais</a>.
  </div></div>
<?php else: ?>

  <!-- Filtros -->
  <div class="np-filters">
    <?php if (count($fazendas) > 1): ?>
    <div class="np-fgrp"><label>Fazenda</label>
      <select id="fFazenda"><option value="all">Todas as fazendas</option>
        <?php foreach ($fazendas as $fz): ?><option value="<?= h($fz) ?>"><?= h($fz) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div class="np-fgrp"><label>Safra</label>
      <select id="fSafra">
        <?php foreach ($safras as $sf): ?><option value="<?= h($sf) ?>"><?= h($sf) ?></option><?php endforeach; ?>
      </select></div>
    <div class="np-fgrp"><label>Talhão / Válvula</label>
      <select id="fTalhao"><option value="all">Todos os talhões</option></select></div>
    <div class="np-fgrp"><label>Matriz de análise</label>
      <div class="np-seg" id="segTipo">
        <button data-v="solo" class="on">Solo</button>
        <button data-v="foliar">Foliar</button>
      </div>
    </div>
    <button class="np-reset" id="btnReset">Limpar filtros</button>
  </div>

  <!-- KPIs -->
  <div class="np-kpis" id="kpiRow"></div>

  <!-- Linha 1: estado por nutriente + alertas -->
  <div class="np-grid np-g2">
    <div class="vcard">
      <div class="np-cardhd">
        <h3 id="bulletTitle">Estado nutricional vs faixa de suficiência</h3>
        <div class="desc" id="bulletDesc">Valor médio dos talhões frente à faixa cadastrada pelo RT — vermelho = deficiente, verde = adequado, âmbar = excessivo</div>
      </div>
      <div class="np-body" id="bulletList"></div>
    </div>
    <div class="vcard">
      <div class="np-cardhd"><h3>Alertas nutricionais</h3>
        <div class="desc">Desvios frente à faixa — ordenados por severidade</div></div>
      <div class="np-body" id="alertList"></div>
    </div>
  </div>

  <!-- Linha 2: mapa de suficiência + evolução -->
  <div class="np-grid np-g2b" style="margin-top:16px">
    <div class="vcard">
      <div class="np-cardhd"><h3>Mapa de suficiência · talhão × nutriente</h3>
        <div class="desc">Intensidade do desvio por talhão — clique para filtrar o painel</div></div>
      <div class="np-legend">
        <span><i style="background:<?= $C['danger'] ?>"></i>Deficiente</span>
        <span><i style="background:<?= $C['amber'] ?>"></i>Baixo/Alto</span>
        <span><i style="background:<?= $C['pos'] ?>"></i>Adequado</span>
        <span><i style="background:<?= $C['track'] ?>"></i>Sem faixa</span>
      </div>
      <div class="np-body" style="padding-top:0"><div class="np-chart" id="heatmap" style="height:320px"></div>
        <div class="np-empty" id="heatmapEmpty" style="display:none">Sem valores para esta safra/matriz.</div></div>
    </div>
    <div class="vcard">
      <div class="np-cardhd" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div><h3>Evolução por nutriente</h3>
          <div class="desc" id="evoDesc">Uma linha por talhão, ao longo das safras, com banda ideal de referência</div></div>
        <div class="np-fgrp"><select id="fEvoNut"></select></div>
      </div>
      <div class="np-body" style="padding-top:0"><div class="np-chart" id="evolution" style="height:330px"></div>
        <div class="np-empty" id="evoEmpty" style="display:none">Sem série suficiente para este nutriente.</div></div>
    </div>
  </div>

  <!-- Linha 3: foliar × solo + aplicações -->
  <div class="np-grid np-g2b" style="margin-top:16px">
    <div class="vcard">
      <div class="np-cardhd"><h3>Comparação Foliar × Solo</h3>
        <div class="desc">Índice de suficiência (100 = centro da faixa ideal) por matriz</div></div>
      <div class="np-body" style="padding-top:0"><div class="np-chart" id="compare" style="height:320px"></div>
        <div class="np-empty" id="compareEmpty" style="display:none">Requer faixas de solo e foliar cadastradas para os mesmos nutrientes.</div></div>
    </div>
    <div class="vcard">
      <div class="np-cardhd"><h3>Aplicações nutricionais &amp; custo</h3>
        <div class="desc">Fertirrigação, foliar e indutores — custo acumulado (receita do RT)</div></div>
      <div class="np-body" style="padding-top:0"><div class="np-chart" id="apps" style="height:320px"></div>
        <div class="np-empty" id="appsEmpty" style="display:none">Nenhuma aplicação nutricional registrada.</div></div>
    </div>
  </div>

  <div class="np-foot" id="foot"></div>
<?php endif; ?>
</div>

<?php if ($temDados): ?>
<script defer src="<?= $base ?>/assets/vendor/echarts/echarts.min.js"></script>
<script>
(function(){
  var VD = <?= jsvar($VERO_DATA) ?>;
  var META = <?= jsvar(['qtdSolo'=>$qtdAnalises['solo'],'qtdFoliar'=>$qtdAnalises['foliar'],
      'qtdFaixas'=>$qtdFaixas,'semSolo'=>$semTalhao['solo'],'semFoliar'=>$semTalhao['foliar']]) ?>;
  var C = VD.C, MONO = "'IBM Plex Mono',monospace";
  var talById = {}; VD.talhoes.forEach(function(t){ talById[t.id] = t; });

  var S = { fazenda:'all', safra: VD.safras[0]||null, tipo:'solo', talhao:'all', evoNut:null };

  /* ---------- helpers de dado ---------- */
  function faixasTipo(){ return VD.faixas[S.tipo] || {}; }
  function fmt(v){ if(v==null||isNaN(v)) return '—';
    return (Math.abs(v)>=100? Math.round(v) : Math.round(v*100)/100).toString().replace('.',','); }
  function avg(a){ var f=a.filter(function(x){return x!=null&&!isNaN(x);});
    return f.length? f.reduce(function(s,x){return s+x;},0)/f.length : null; }

  function classify(v,f){
    if(v==null||!f) return {k:'na', lab:'Sem faixa'};
    var lo = f.min!=null? f.min : f.iMin;
    var hi = f.max!=null? f.max : f.iMax;
    if(v < lo) return {k:'lo',  lab:'Muito baixo'};
    if(v < f.iMin) return {k:'lo2', lab:'Baixo'};
    if(v <= f.iMax) return {k:'ok', lab:'Adequado'};
    if(v <= hi) return {k:'hi2', lab:'Alto'};
    return {k:'hi', lab:'Excessivo'};
  }
  function sufIndex(v,f){ if(v==null||!f) return null;
    var c=(f.iMin+f.iMax)/2, half=(f.iMax-f.iMin)/2||1; return Math.round(100+(v-c)/half*20); }

  /* talhões que passam no filtro fazenda + seleção de talhão */
  function currentTalhoes(){
    return VD.talhoes.filter(function(t){
      if(S.fazenda!=='all' && t.fazenda!==S.fazenda) return false;
      if(S.talhao!=='all' && String(t.id)!==String(S.talhao)) return false;
      return true;
    }).map(function(t){ return t.id; });
  }
  function valOf(safra,tipo,tid,nid){
    var v = VD.valores[safra]; if(!v) return null; v=v[tipo]; if(!v) return null;
    v=v[tid]; if(!v) return null; v=v[nid]; return v==null?null:v;
  }
  function meanNut(nid){ return avg(currentTalhoes().map(function(tid){ return valOf(S.safra,S.tipo,tid,nid); })); }

  /* nutrientes-coluna do mapa: com faixa OU com valor nesta matriz, ordenados */
  function colNutrients(){
    var set={}, fx=faixasTipo();
    Object.keys(fx).forEach(function(nid){ set[nid]=1; });
    var vs = (VD.valores[S.safra]&&VD.valores[S.safra][S.tipo])||{};
    Object.keys(vs).forEach(function(tid){ Object.keys(vs[tid]).forEach(function(nid){ set[nid]=1; }); });
    return Object.keys(set).sort(function(a,b){
      var oa=(VD.nutrientes[a]||{}).ordem||999, ob=(VD.nutrientes[b]||{}).ordem||999; return oa-ob;
    });
  }
  function symOf(nid){ return (VD.nutrientes[nid]||{}).sym || nid; }
  function nomeOf(nid){ return (VD.nutrientes[nid]||{}).nome || ''; }

  /* ---------- KPIs ---------- */
  function renderKPIs(){
    var fx=faixasTipo(), def=0, att=0, ok=0, na=0;
    currentTalhoes().forEach(function(tid){
      var vs=(VD.valores[S.safra]&&VD.valores[S.safra][S.tipo]&&VD.valores[S.safra][S.tipo][tid])||{};
      Object.keys(vs).forEach(function(nid){
        var c=classify(vs[nid], fx[nid]);
        if(c.k==='na'){ na++; return; }
        if(c.k==='lo'||c.k==='hi') def++;
        else if(c.k==='lo2'||c.k==='hi2') att++;
        else ok++;
      });
    });
    var aval=ok+att+def, pctOk=aval? Math.round(ok/aval*100):0;
    var custo=VD.aplicacoes.reduce(function(s,a){return s+a.custo;},0);
    var nAplic=VD.aplicacoes.reduce(function(s,a){return s+a.n;},0);
    var kpis=[
      {c:'danger', lab:'Desvios críticos', val:def, sub:'nutrientes deficientes ou excessivos'},
      {c:'amber',  lab:'Em atenção', val:att, sub:'abaixo/acima do ideal, dentro do limite'},
      {c:'pos',    lab:'Dentro da faixa', val:aval? pctOk+'%':'—', sub:aval? ok+' de '+aval+' leituras adequadas':na+' leitura(s) sem faixa'},
      {c:'neutral',lab:'Custo nutricional', val:'R$ '+(custo/1000).toFixed(1).replace('.',',')+'k', sub:nAplic+' aplicação(ões) registrada(s)'}
    ];
    document.getElementById('kpiRow').innerHTML = kpis.map(function(k){
      return '<div class="vcard np-kpi '+k.c+'"><div class="strip"></div>'+
        '<div class="lab">'+k.lab+'</div><div class="val">'+k.val+'</div><div class="sub">'+k.sub+'</div></div>';
    }).join('');
  }

  /* ---------- bullets ---------- */
  function renderBullets(){
    var fx=faixasTipo(), nids=Object.keys(fx);
    document.getElementById('bulletTitle').textContent =
      'Estado nutricional vs faixa · '+(S.tipo==='solo'?'Análise de Solo':'Análise Foliar');
    if(!nids.length){
      document.getElementById('bulletList').innerHTML =
        '<div class="np-empty">Nenhuma faixa de '+(S.tipo==='solo'?'solo':'foliar')+' cadastrada — cadastre em Faixas Nutricionais para classificar.</div>';
      return;
    }
    var rows = nids.map(function(nid){
      var f=fx[nid], v=meanNut(nid), cl=classify(v,f);
      var lo=(f.min!=null?f.min:f.iMin), hi=(f.max!=null?f.max:f.iMax);
      var lo2=lo-(hi-lo)*0.12, hi2=hi+(hi-lo)*0.12, span=(hi2-lo2)||1;
      var pct=function(x){ return Math.max(0,Math.min(100,(x-lo2)/span*100)); };
      var okL=pct(f.iMin), okW=pct(f.iMax)-pct(f.iMin), hiL=pct(f.iMax);
      var mk = v==null? null : pct(v);
      var kcls = cl.k==='ok'?'ok':(cl.k==='lo'||cl.k==='lo2')?'lo':(cl.k==='na'?'':'hi');
      return '<div class="np-brow"><div class="nm">'+esc(symOf(nid))+'<small>'+esc(f.nome)+(f.un?' · '+esc(f.un):'')+'</small></div>'+
        '<div class="np-track">'+
          '<div class="np-band lo" style="left:0;width:'+okL+'%"></div>'+
          '<div class="np-band ok" style="left:'+okL+'%;width:'+okW+'%"></div>'+
          '<div class="np-band hi" style="left:'+hiL+'%;width:'+(100-hiL)+'%"></div>'+
          (mk!=null?'<div class="np-mark" style="left:'+mk+'%"></div>':'')+
        '</div>'+
        '<div class="np-bval">'+fmt(v)+'<span class="np-bstat '+kcls+'">'+cl.lab+'</span></div></div>';
    }).join('');
    document.getElementById('bulletList').innerHTML=rows;
  }

  /* ---------- alertas (desvios derivados dos valores reais) ---------- */
  function renderAlerts(){
    var fx=faixasTipo(), items=[];
    currentTalhoes().forEach(function(tid){
      var vs=(VD.valores[S.safra]&&VD.valores[S.safra][S.tipo]&&VD.valores[S.safra][S.tipo][tid])||{};
      Object.keys(vs).forEach(function(nid){
        var f=fx[nid], c=classify(vs[nid],f);
        if(c.k==='lo'||c.k==='hi'||c.k==='lo2'||c.k==='hi2'){
          items.push({tid:tid, nid:nid, v:vs[nid], sev:(c.k==='lo'||c.k==='hi')?2:1, lab:c.lab, un:f.un, f:f});
        }
      });
    });
    items.sort(function(a,b){ return b.sev-a.sev || symOf(a.nid).localeCompare(symOf(b.nid)); });
    var html=items.slice(0,12).map(function(it){
      var col=it.sev===2?C.danger:C.amber, tl=talById[it.tid]||{};
      return '<div class="np-aitem"><div class="np-adot" style="background:'+col+'"></div><div>'+
        '<div class="at">'+esc(symOf(it.nid))+' <span style="color:'+C.muted+';font-weight:600">'+esc(nomeOf(it.nid))+'</span> · Talhão '+esc(tl.code||'')+'</div>'+
        '<div class="as">'+(tl.fazenda||'')+' · <b style="color:'+col+'">'+it.lab+'</b> — faixa ideal '+fmt(it.f.iMin)+'–'+fmt(it.f.iMax)+' '+it.un+'</div>'+
        '</div><div class="av" style="color:'+col+'">'+fmt(it.v)+' '+it.un+'</div></div>';
    }).join('');
    document.getElementById('alertList').innerHTML = html ||
      '<div class="np-empty">Nenhum desvio nas leituras classificadas — dentro das faixas cadastradas.</div>';
  }

  /* ---------- ECharts ---------- */
  var chHeat, chEvo, chCmp, chApp;
  function axis(){ return {
    axisLine:{lineStyle:{color:C.border}}, axisTick:{show:false},
    axisLabel:{color:C.muted, fontSize:11}, splitLine:{lineStyle:{color:'#EEE8DB'}} }; }
  function tt(){ return {backgroundColor:'#fff', borderColor:C.border,
    textStyle:{color:C.ink, fontSize:12}, extraCssText:'box-shadow:0 8px 24px -12px rgba(8,38,42,.35);border-radius:9px'}; }
  function toggle(id,empty,show){ document.getElementById(id).style.display=show?'none':'block';
    document.getElementById(empty).style.display=show?'block':'none'; }

  function renderHeat(){
    var fx=faixasTipo(), nids=colNutrients(), tids=currentTalhoes();
    var has = tids.some(function(tid){ var vs=(VD.valores[S.safra]&&VD.valores[S.safra][S.tipo]&&VD.valores[S.safra][S.tipo][tid])||{}; return Object.keys(vs).length; });
    toggle('heatmap','heatmapEmpty', has && nids.length && tids.length);
    if(!has || !nids.length || !tids.length){ return; }
    var data=[]; var talLabels=tids.map(function(tid){ return (talById[tid]||{}).code || tid; });
    tids.forEach(function(tid,yi){ nids.forEach(function(nid,xi){
      var v=valOf(S.safra,S.tipo,tid,nid), c=classify(v,fx[nid]), val;
      if(v==null) val='-';
      else if(c.k==='ok') val=0; else if(c.k==='lo2') val=-1; else if(c.k==='hi2') val=1;
      else if(c.k==='lo') val=-2; else if(c.k==='hi') val=2; else val='-';
      data.push({value:[xi,yi,val], raw:{v:v,lab:c.lab,sym:symOf(nid),code:(talById[tid]||{}).code,un:(fx[nid]||{}).un||(VD.nutrientes[nid]||{}).un||''}});
    });});
    chHeat.setOption({
      tooltip:Object.assign(tt(),{formatter:function(p){ var d=p.data.raw;
        if(d.v==null) return 'Talhão '+d.code+' · '+d.sym+'<br/>Sem faixa cadastrada';
        return '<b>Talhão '+d.code+'</b> · '+d.sym+'<br/>Valor: <b>'+fmt(d.v)+' '+d.un+'</b><br/>Situação: <b>'+d.lab+'</b>'; }}),
      grid:{left:52,right:12,top:8,bottom:26},
      xAxis:Object.assign({type:'category',data:nids.map(symOf)},axis(),{axisLabel:{color:C.muted,fontWeight:700,fontSize:11.5}}),
      yAxis:Object.assign({type:'category',data:talLabels},axis(),{axisLabel:{color:C.ink,fontWeight:600,fontSize:11.5,formatter:function(v){return 'T '+v;}}}),
      visualMap:{show:false,min:-2,max:2,inRange:{color:[C.danger,C.amber,C.pos,C.amber,C.danger]}},
      series:[{type:'heatmap',data:data,
        itemStyle:{borderColor:'#fff',borderWidth:3,borderRadius:5},
        emphasis:{itemStyle:{borderColor:C.ink,borderWidth:2}}}]
    });
    chHeat.off('click'); chHeat.on('click',function(p){
      var tid=tids[p.data.value[1]];
      S.talhao = String(S.talhao)===String(tid)? 'all' : tid;
      document.getElementById('fTalhao').value=S.talhao; renderAll();
    });
  }

  function fillEvoOptions(){
    var sel=document.getElementById('fEvoNut'), nids=colNutrients();
    sel.innerHTML = nids.map(function(nid){ return '<option value="'+nid+'">'+esc(symOf(nid))+' · '+esc(nomeOf(nid))+'</option>'; }).join('');
    if(nids.indexOf(String(S.evoNut))<0) S.evoNut = nids[0]||null;
    if(S.evoNut!=null) sel.value=S.evoNut;
  }
  function renderEvo(){
    var fx=faixasTipo(), nid=S.evoNut, f=fx[nid];
    /* tempo crescente: mais antiga → mais recente (VD.safras vem em ordem desc) */
    var order=VD.safras.slice().reverse();
    var tids=currentTalhoes().slice(0,6);          // uma linha por talhão (limite p/ leitura)
    var ser=tids.map(function(tid){
      return {name:'T '+((talById[tid]||{}).code||tid), type:'line', smooth:true, symbolSize:7,
        connectNulls:true, lineStyle:{width:2},
        data:order.map(function(s){ return valOf(s,S.tipo,tid,nid); })};
    });
    var has=ser.some(function(s){ return s.data.some(function(x){ return x!=null; }); });
    toggle('evolution','evoEmpty', has && tids.length>0);
    document.getElementById('evoDesc').textContent = f
      ? 'Uma linha por talhão · banda verde = faixa ideal ('+fmt(f.iMin)+'–'+fmt(f.iMax)+' '+f.un+')'
      : 'Uma linha por talhão (sem faixa cadastrada para este nutriente)';
    if(!has || !tids.length) return;
    if(f){ ser.push({type:'line',name:'Faixa ideal',data:order.map(function(){return f.iMin;}),symbol:'none',silent:true,
      lineStyle:{color:C.pos,type:'dashed',width:1},
      markArea:{silent:true,itemStyle:{color:C.posbg,opacity:.5},data:[[{yAxis:f.iMin},{yAxis:f.iMax}]]},z:0}); }
    chEvo.setOption({replaceMerge:['series'],
      color:[C.accent,C.pos,C.amber,C.a3,C.deep,C.danger],
      tooltip:Object.assign(tt(),{trigger:'axis',valueFormatter:function(v){return v==null?'—':fmt(v)+(f?' '+f.un:'');}}),
      legend:{type:'scroll',top:0,textStyle:{color:C.muted,fontSize:11},itemWidth:16,itemHeight:8},
      grid:{left:46,right:16,top:34,bottom:26},
      xAxis:Object.assign({type:'category',data:order,boundaryGap:false},axis(),{axisLabel:{color:C.ink,fontWeight:700}}),
      yAxis:Object.assign({type:'value',name:f?f.un:'',nameTextStyle:{color:C.muted,fontSize:10}},axis()),
      series:ser});
  }

  function renderCompare(){
    var common=Object.keys(VD.faixas.solo||{}).filter(function(nid){ return (VD.faixas.foliar||{})[nid]; });
    toggle('compare','compareEmpty', common.length>0);
    if(!common.length) return;
    var tids=currentTalhoes(), soloIdx=[], foliarIdx=[];
    common.forEach(function(nid){
      var fs=VD.faixas.solo[nid], ff=VD.faixas.foliar[nid];
      var sv=avg(tids.map(function(t){ return valOf(S.safra,'solo',t,nid); }));
      var fv=avg(tids.map(function(t){ return valOf(S.safra,'foliar',t,nid); }));
      soloIdx.push(sv!=null? sufIndex(sv,fs):null);
      foliarIdx.push(fv!=null? sufIndex(fv,ff):null);
    });
    chCmp.setOption({replaceMerge:['series'],
      tooltip:Object.assign(tt(),{trigger:'axis',valueFormatter:function(v){return v==null?'s/ dado':v;}}),
      legend:{top:0,textStyle:{color:C.muted,fontSize:11.5},itemWidth:16,itemHeight:8},
      grid:{left:40,right:14,top:34,bottom:24},
      xAxis:Object.assign({type:'category',data:common.map(symOf)},axis(),{axisLabel:{color:C.ink,fontWeight:700}}),
      yAxis:Object.assign({type:'value'},axis()),
      series:[
        {name:'Solo',type:'bar',data:soloIdx,itemStyle:{color:C.a3,borderRadius:[4,4,0,0]},barMaxWidth:26},
        {name:'Foliar',type:'bar',data:foliarIdx,itemStyle:{color:C.pos,borderRadius:[4,4,0,0]},barMaxWidth:26},
        {type:'line',name:'Ideal (100)',data:common.map(function(){return 100;}),symbol:'none',lineStyle:{color:C.amber,type:'dashed',width:1.5},z:5}
      ]});
  }

  function renderApps(){
    var a=VD.aplicacoes;
    toggle('apps','appsEmpty', a.length>0);
    if(!a.length) return;
    chApp.setOption({
      tooltip:Object.assign(tt(),{trigger:'item',formatter:function(p){
        return p.name+'<br/>Custo: <b>R$ '+p.value.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'</b><br/>'+p.data.n+' aplicação(ões)'; }}),
      grid:{left:8,right:26,top:8,bottom:8,containLabel:true},
      xAxis:Object.assign({type:'value'},axis(),{axisLabel:{color:C.muted,formatter:function(v){return 'R$'+(v/1000)+'k';}}}),
      yAxis:Object.assign({type:'category',data:a.map(function(x){return x.tipo;}).reverse()},axis(),{axisLabel:{color:C.ink,fontWeight:600,fontSize:11.5}}),
      series:[{type:'bar',data:a.map(function(x){return {value:x.custo,n:x.n};}).reverse(),
        itemStyle:{color:C.accent,borderRadius:[0,5,5,0]},barMaxWidth:26,
        label:{show:true,position:'right',formatter:function(p){return 'R$ '+(p.value/1000).toFixed(1).replace('.',',')+'k';},color:C.muted,fontFamily:MONO,fontSize:11}}]
    });
  }

  function renderFoot(){
    document.getElementById('foot').innerHTML =
      'Fonte real (tenant): '+META.qtdSolo+' análise(s) de solo · '+META.qtdFoliar+' foliar · '+META.qtdFaixas+' faixa(s) ativa(s). '+
      ((META.semSolo+META.semFoliar)>0? 'Há '+(META.semSolo+META.semFoliar)+' resultado(s) sem talhão vinculado — fora do mapa. ':'')+
      'Classificações seguem as faixas do RT; sem faixa não há classificação.';
  }

  /* ---------- init / render ---------- */
  function renderAll(){
    renderKPIs(); renderBullets(); renderAlerts();
    if(typeof echarts!=='undefined'){ renderHeat(); renderEvo(); renderCompare(); renderApps(); }
  }
  function fillTalhoes(){
    var sel=document.getElementById('fTalhao');
    var opts=['<option value="all">Todos os talhões</option>'];
    VD.talhoes.filter(function(t){ return S.fazenda==='all'||t.fazenda===S.fazenda; })
      .forEach(function(t){ opts.push('<option value="'+t.id+'">Talhão '+t.code+(t.fazenda?' · '+t.fazenda:'')+'</option>'); });
    sel.innerHTML=opts.join(''); sel.value=S.talhao;
  }

  function boot(){
    var chartsOk = (typeof echarts!=='undefined');
    if(chartsOk){
      chHeat=echarts.init(document.getElementById('heatmap'),null,{renderer:'canvas'});
      chEvo =echarts.init(document.getElementById('evolution'),null,{renderer:'canvas'});
      chCmp =echarts.init(document.getElementById('compare'),null,{renderer:'canvas'});
      chApp =echarts.init(document.getElementById('apps'),null,{renderer:'canvas'});
      window.addEventListener('resize',function(){ chHeat.resize();chEvo.resize();chCmp.resize();chApp.resize(); });
    }
    fillTalhoes(); fillEvoOptions(); renderFoot(); renderAll();

    var ff=document.getElementById('fFazenda');
    if(ff) ff.addEventListener('change',function(e){ S.fazenda=e.target.value; S.talhao='all'; fillTalhoes(); renderAll(); });
    document.getElementById('fSafra').addEventListener('change',function(e){ S.safra=e.target.value; renderAll(); });
    document.getElementById('fTalhao').addEventListener('change',function(e){ S.talhao=e.target.value; renderAll(); });
    document.getElementById('fEvoNut').addEventListener('change',function(e){ S.evoNut=e.target.value; renderEvo(); });
    document.getElementById('segTipo').addEventListener('click',function(e){
      var b=e.target.closest('button'); if(!b) return;
      [].slice.call(this.children).forEach(function(x){ x.classList.remove('on'); }); b.classList.add('on');
      S.tipo=b.dataset.v; S.evoNut=null; fillEvoOptions(); renderAll();
    });
    document.getElementById('btnReset').addEventListener('click',function(){
      S.fazenda='all'; S.safra=VD.safras[0]||null; S.tipo='solo'; S.talhao='all'; S.evoNut=null;
      if(ff) ff.value='all';
      document.getElementById('fSafra').value=S.safra;
      [].slice.call(document.getElementById('segTipo').children).forEach(function(x,i){ x.classList.toggle('on',i===0); });
      fillTalhoes(); fillEvoOptions(); renderAll();
    });
  }

  if(document.readyState!=='loading') setTimeout(boot,0); else document.addEventListener('DOMContentLoaded',boot);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
