/* ============================================================
   VERO — assets/js/vero-dash.js
   Helper único dos dashboards (A4-04). Requer Apache ECharts
   (assets/vendor/echarts/echarts.min.js) carregado ANTES.
   Contrato: cada gráfico é um <div data-vdash="ID"> cujo ID
   aponta um <script type="application/json" id="ID"> com a config.
   - registra o tema 'vero' pelos tokens do DESIGN_SYSTEM
   - instancia por tipo (hbar / donut / bullet / gauge)
   - echarts.connect → tooltip/zoom sincronizados
   - linked highlight por categoria (os gráficos "conversam entre si")
   - resize responsivo
   Regra 1/D5: só PLOTA dados vindos do PHP; nenhuma recomendação.
   ============================================================ */
(function () {
  'use strict';
  if (!window.echarts) { console.warn('[vero-dash] ECharts ausente'); return; }

  var TK = {
    accent: '#005059', deep: '#00363D', olive: '#4E9CA1', accent3: '#2A767C', mist: '#A7C9CB',
    ink: '#241B14', ink2: '#2B2018', muted: '#8A7C68', muted2: '#9A8C78',
    border: '#E3D9C8', track: '#EEE6D6', surface: '#fff',
    pos: '#0E7E72', amber: '#B57C1A', danger: '#B23A2E'
  };
  var PALETTE = [TK.accent, TK.olive, TK.amber, TK.accent3, TK.mist, TK.danger];

  echarts.registerTheme('vero', {
    color: PALETTE,
    textStyle: { fontFamily: "'IBM Plex Sans',ui-sans-serif,sans-serif", color: TK.ink2 },
    title: { textStyle: { color: TK.ink, fontWeight: 600, fontSize: 13 } },
    grid: { borderColor: TK.border },
    categoryAxis: {
      axisLine: { lineStyle: { color: TK.border } },
      axisTick: { show: false },
      axisLabel: { color: TK.muted },
      splitLine: { show: false }
    },
    valueAxis: {
      axisLine: { show: false }, axisTick: { show: false },
      axisLabel: { color: TK.muted2 },
      splitLine: { show: false }
    },
    tooltip: {
      backgroundColor: TK.surface, borderColor: TK.border, borderWidth: 1,
      textStyle: { color: TK.ink2, fontSize: 12 }, extraCssText: 'box-shadow:0 6px 20px rgba(43,32,24,.12);border-radius:8px'
    },
    legend: { textStyle: { color: TK.muted } }
  });

  var BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
  var NUM = new Intl.NumberFormat('pt-BR');
  function money(v) { return BRL.format(Math.round(+v || 0)); }
  function compact(v) {
    v = +v || 0; var a = Math.abs(v);
    if (a >= 1e6) return (v / 1e6).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mi';
    if (a >= 1e3) return (v / 1e3).toLocaleString('pt-BR', { maximumFractionDigits: 0 }) + ' mil';
    return NUM.format(v);
  }
  function unitFmt(cfg) {
    return cfg.unit === 'kg' ? function (v) { return NUM.format(Math.round(v)) + ' kg'; }
      : cfg.unit === 'num' ? function (v) { return NUM.format(v); }
      : function (v) { return 'R$ ' + compact(v); };
  }
  // gradiente linear (h=horizontal) entre duas cores da paleta
  function grad(c0, c1, h) {
    return new echarts.graphic.LinearGradient(0, 0, h ? 1 : 0, h ? 0 : 1,
      [{ offset: 0, color: c0 }, { offset: 1, color: c1 }]);
  }
  var TRACKBG = 'rgba(138,124,104,.12)';

  function optHbar(cfg) {
    var f = unitFmt(cfg);
    var maxv = Math.max.apply(null, cfg.values.concat([0]));
    return {
      grid: { left: 6, right: 30, top: 6, bottom: 2, containLabel: true },
      tooltip: {
        trigger: 'axis', axisPointer: { type: 'none' },
        valueFormatter: function (v) { return cfg.unit === 'kg' ? NUM.format(v) + ' kg' : money(v); }
      },
      xAxis: {
        type: 'value', max: maxv * 1.14,
        axisLabel: { formatter: function (v) { return f(v); }, color: TK.muted2, fontSize: 10 },
        splitLine: { show: false }
      },
      yAxis: {
        type: 'category', data: cfg.categories, inverse: true,
        axisLabel: { color: TK.ink2, fontWeight: 500 }, axisLine: { show: false }, axisTick: { show: false }
      },
      series: [{
        type: 'bar', name: cfg.serieName || 'Valor', data: cfg.values, barMaxWidth: 17,
        showBackground: true, backgroundStyle: { color: TRACKBG, borderRadius: 9 },
        itemStyle: { borderRadius: 9, color: grad(TK.deep, TK.accent3, true) },
        emphasis: { itemStyle: { color: grad(TK.deep, TK.olive, true), shadowBlur: 8, shadowColor: 'rgba(0,54,61,.28)' } },
        label: { show: true, position: 'right', color: TK.ink2, fontSize: 11, fontWeight: 600, formatter: function (p) { return f(p.value); } },
        animationDelay: function (i) { return i * 60; }
      }]
    };
  }

  function optDonut(cfg) {
    var data = cfg.categories.map(function (c, i) {
      var d = { name: c, value: cfg.values[i] };
      if (cfg.colors && cfg.colors[i]) d.itemStyle = { color: cfg.colors[i] };
      return d;
    });
    var total = cfg.values.reduce(function (a, b) { return a + (+b || 0); }, 0);
    var unit = cfg.unit || 'brl';
    var dfmt = function (v) { return unit === 'kg' ? NUM.format(Math.round(v)) + ' kg' : unit === 'num' ? NUM.format(v) : 'R$ ' + compact(v); };
    var centerTxt = unit === 'kg' ? compact(total) + ' kg' : unit === 'num' ? NUM.format(total) : 'R$ ' + compact(total);
    var centerLabel = cfg.centerLabel || (unit === 'brl' ? 'custo total' : 'total');
    return {
      tooltip: { trigger: 'item', formatter: function (p) { return p.name + '<br><b>' + dfmt(p.value) + '</b> · ' + p.percent + '%'; } },
      legend: { type: 'scroll', bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: TK.muted, fontSize: 11 } },
      graphic: [
        { type: 'text', left: 'center', top: '39%', style: { text: centerTxt, fill: TK.ink, font: "700 18px 'IBM Plex Mono',monospace" } },
        { type: 'text', left: 'center', top: '51%', style: { text: centerLabel, fill: TK.muted, font: "11px 'IBM Plex Sans'" } }
      ],
      series: [{
        type: 'pie', radius: ['60%', '82%'], center: ['50%', '45%'], avoidLabelOverlap: true,
        padAngle: 3, itemStyle: { borderRadius: 6, borderColor: TK.surface, borderWidth: 2 },
        label: { show: false }, labelLine: { show: false },
        emphasis: { scale: true, scaleSize: 7, itemStyle: { shadowBlur: 14, shadowColor: 'rgba(43,32,24,.18)' }, label: { show: false } },
        data: data
      }]
    };
  }

  function optBullet(cfg) {
    // bullet graph: trilha (fundo) + medida (realizado, fina) + marcador de meta.
    // cfg.metaLine (opcional, R2.4): 2ª referência tracejada = meta cadastrada
    // em gestao_metas, distinta da previsão operacional.
    var real = +cfg.value || 0, meta = +cfg.meta || 0, metaLine = +cfg.metaLine || 0;
    var max = Math.max(real, meta, metaLine) * 1.15 || 1;
    var atinge = meta && real >= meta;
    var c0 = atinge ? TK.pos : TK.deep, c1 = atinge ? '#14A08F' : TK.accent3;
    var f = unitFmt(cfg);
    var mlData = [{ xAxis: meta, lineStyle: { color: TK.ink, type: 'solid', width: 2 }, label: { show: true, formatter: metaLine ? 'previsto' : 'meta', color: TK.muted, position: 'insideEndTop', fontSize: 10 } }];
    if (metaLine) mlData.push({ xAxis: metaLine, lineStyle: { color: TK.amber, type: 'dashed', width: 2 }, label: { show: true, formatter: 'meta', color: TK.amber, position: 'insideEndBottom', fontSize: 10 } });
    return {
      grid: { left: 6, right: 26, top: 16, bottom: 2, containLabel: true },
      tooltip: { trigger: 'item', formatter: function () { return (cfg.serieName || 'Realizado') + ': <b>' + f(real) + '</b><br>' + (metaLine ? 'Previsto' : 'Meta') + ': ' + f(meta) + ' · ' + (meta > 0 ? Math.round(real / meta * 100) : 0) + '%' + (metaLine ? '<br>Meta cadastrada: ' + f(metaLine) : ''); } },
      xAxis: { type: 'value', max: max, axisLabel: { formatter: function (v) { return f(v); }, color: TK.muted2, fontSize: 10 }, splitLine: { show: false } },
      yAxis: { type: 'category', data: [cfg.label || ''], axisLabel: { color: TK.ink2 }, axisLine: { show: false }, axisTick: { show: false } },
      series: [
        { type: 'bar', data: [max], barWidth: 24, barGap: '-100%', silent: true, z: 1, tooltip: { show: false }, itemStyle: { color: TRACKBG, borderRadius: 6 } },
        {
          type: 'bar', data: [real], barWidth: 12, barGap: '-100%', z: 3,
          itemStyle: { color: grad(c0, c1, true), borderRadius: 6 },
          label: { show: true, position: 'right', color: TK.ink2, fontSize: 12, fontWeight: 700, formatter: function () { return f(real); } },
          markLine: {
            symbol: ['none', 'none'], silent: true, data: mlData,
            lineStyle: { color: TK.ink, type: 'solid', width: 2 },
            label: { show: true, color: TK.muted, position: 'insideEndTop', fontSize: 10 }
          }
        }
      ]
    };
  }

  function optGauge(cfg) {
    var pct = Math.max(0, Math.min(150, +cfg.value || 0));
    var c0 = pct <= 90 ? TK.pos : pct <= 100 ? TK.amber : TK.danger;
    var c1 = pct <= 90 ? '#14A08F' : pct <= 100 ? '#D0982F' : '#CE5647';
    var niceMax = Math.max(100, Math.ceil(pct / 10) * 10);
    return {
      series: [{
        type: 'gauge', min: 0, max: niceMax, radius: '98%', center: ['50%', '60%'],
        startAngle: 210, endAngle: -30, pointer: { show: false }, clockwise: true,
        progress: { show: true, width: 16, roundCap: true, itemStyle: { color: grad(c0, c1, true) } },
        axisLine: { roundCap: true, lineStyle: { width: 16, color: [[1, TK.track]] } },
        axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
        anchor: { show: false }, title: { show: false },
        detail: { valueAnimation: false, offsetCenter: [0, '-6%'], fontSize: 26, fontWeight: 700, color: TK.ink, formatter: '{value}%' },
        data: [{ value: Math.round(pct) }]
      }]
    };
  }

  // barras versáteis: N séries, agrupadas ou empilhadas, horizontal/vertical.
  // cfg = { categories, series:[{name,data,color?}], stack?, horizontal?(default true), unit?, labels? }
  function optBars(cfg) {
    var horizontal = cfg.horizontal !== false;
    var stacked = !!cfg.stack;
    var defColors = [TK.accent, TK.olive, TK.amber, TK.accent3];
    function fmt(v) { return cfg.unit === 'kg' ? NUM.format(Math.round(v)) + ' kg' : cfg.unit === 'num' ? NUM.format(v) : 'R$ ' + compact(v); }
    var catAxis = { type: 'category', data: cfg.categories, axisLine: { show: false }, axisTick: { show: false }, axisLabel: { color: TK.ink2, fontWeight: 500 } };
    if (horizontal) catAxis.inverse = true;
    var valAxis = { type: 'value', axisLine: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { color: TK.muted2, fontSize: 10, formatter: function (v) { return fmt(v); } } };
    var nser = cfg.series.length;
    var series = cfg.series.map(function (s, i) {
      var last = i === nser - 1;
      var r = stacked ? (last ? (horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]) : 0) : (horizontal ? [0, 5, 5, 0] : [5, 5, 0, 0]);
      return {
        name: s.name, type: 'bar', data: s.data, barMaxWidth: 16,
        stack: stacked ? 'g' : undefined,
        itemStyle: { color: s.color || defColors[i % 4], borderRadius: r },
        emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,54,61,.22)' } },
        label: (cfg.labels === false || stacked) ? { show: false }
          : { show: true, position: horizontal ? 'right' : 'top', color: TK.muted, fontSize: 10, formatter: function (p) { return p.value ? fmt(p.value) : ''; } }
      };
    });
    return {
      grid: { left: 6, right: 22, top: nser > 1 ? 26 : 8, bottom: 4, containLabel: true },
      legend: nser > 1 ? { top: 0, right: 4, icon: 'roundRect', itemWidth: 10, itemHeight: 8, textStyle: { color: TK.muted, fontSize: 11 } } : { show: false },
      tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: function (v) { return fmt(v); } },
      xAxis: horizontal ? valAxis : catAxis,
      yAxis: horizontal ? catAxis : valAxis,
      series: series
    };
  }

  // série temporal (linha + área). cfg = { labels, series:[{name,data,color?}], unit? }
  // Regra R1/D2: com <2 pontos a "área" vira COLUNA única (evita mancha de 1 ponto).
  function optArea(cfg) {
    if (cfg.labels && cfg.labels.length < 2) {
      return optBars({ type: 'bars', horizontal: false, unit: cfg.unit, categories: cfg.labels,
        series: cfg.series.map(function (s) { return { name: s.name, data: s.data, color: s.color }; }) });
    }
    function fmt(v) { return cfg.unit === 'kg' ? NUM.format(Math.round(v)) + ' kg' : cfg.unit === 'h' ? NUM.format(v) + ' h' : cfg.unit === 'num' ? NUM.format(v) : 'R$ ' + compact(v); }
    var defColors = [TK.accent, TK.amber, TK.olive];
    return {
      grid: { left: 6, right: 14, top: 14, bottom: 4, containLabel: true },
      legend: cfg.series.length > 1 ? { top: 0, right: 4, icon: 'roundRect', itemWidth: 10, itemHeight: 8, textStyle: { color: TK.muted, fontSize: 11 } } : { show: false },
      tooltip: { trigger: 'axis', valueFormatter: function (v) { return fmt(v); } },
      xAxis: { type: 'category', data: cfg.labels, boundaryGap: false, axisLine: { lineStyle: { color: TK.border } }, axisTick: { show: false }, axisLabel: { color: TK.muted2, fontSize: 10 } },
      yAxis: { type: 'value', axisLine: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { color: TK.muted2, fontSize: 10, formatter: function (v) { return fmt(v); } } },
      series: cfg.series.map(function (s, i) {
        var c = s.color || defColors[i % 3];
        return {
          name: s.name, type: 'line', data: s.data, smooth: 0.35, showSymbol: false, symbol: 'circle', symbolSize: 6,
          lineStyle: { width: 2.5, color: c }, itemStyle: { color: c },
          areaStyle: i === 0 ? { color: grad(hexA(c, .22), hexA(c, .01), false) } : { opacity: 0 },
          emphasis: { focus: 'series' }
        };
      })
    };
  }
  // hex → rgba com alpha (para os gradientes de área)
  function hexA(hex, a) {
    var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    if (!m) return hex;
    return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')';
  }

  var BUILDERS = { hbar: optHbar, donut: optDonut, bullet: optBullet, gauge: optGauge, bars: optBars, area: optArea };
  var GROUP = 'vero';
  var charts = [];
  var xfilter = []; // {chart, cats}

  document.querySelectorAll('[data-vdash]').forEach(function (el) {
    var src = document.getElementById(el.getAttribute('data-vdash'));
    if (!src) return;
    var cfg;
    try { cfg = JSON.parse(src.textContent); } catch (e) { console.warn('[vero-dash] JSON inválido', el.id); return; }
    var build = BUILDERS[cfg.type];
    if (!build) return;
    var ch = echarts.init(el, 'vero', { renderer: 'canvas' });
    ch.setOption(build(cfg));
    ch.group = GROUP;
    charts.push(ch);
    if (cfg.xfilter && cfg.categories) xfilter.push({ chart: ch, cats: cfg.categories });
  });

  echarts.connect(GROUP);

  // Linked highlight por categoria: passar o mouse numa categoria realça a
  // mesma nas demais visões que compartilham a dimensão (custo × composição).
  xfilter.forEach(function (entry) {
    entry.chart.on('mouseover', function (p) {
      if (p.name == null) return;
      xfilter.forEach(function (other) {
        if (other.chart === entry.chart) return;
        var idx = other.cats.indexOf(p.name);
        if (idx >= 0) other.chart.dispatchAction({ type: 'highlight', dataIndex: idx });
      });
    });
    entry.chart.on('mouseout', function () {
      xfilter.forEach(function (other) {
        if (other.chart === entry.chart) return;
        other.chart.dispatchAction({ type: 'downplay' });
      });
    });
  });

  var rt;
  window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () { charts.forEach(function (c) { c.resize(); }); }, 120);
  });
})();
