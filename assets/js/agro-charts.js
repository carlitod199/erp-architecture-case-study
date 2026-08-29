/* ============================================================
   VERO Agro — assets/js/agro-charts.js
   Tema visual único para Apache ECharts. Garante gráficos
   bonitos e consistentes em todo o sistema.
   Uso:
     BiosCharts.bar(el, {labels, series, unit})
     BiosCharts.stack(el, {labels, series, unit})
     BiosCharts.line(el, {labels, series, unit})
     BiosCharts.area(el, {labels, series, unit})
     BiosCharts.donut(el, {data:[{name,value}], unit, center})
   Requer Apache ECharts 5 carregado antes deste arquivo.
   ============================================================ */
(function (global) {
  var PAL    = ['#005059', '#4E9CA1', '#B57C1A', '#2A767C', '#A7C9CB', '#0E7E72', '#C28A4E'];
  var INK    = '#2B2018', MUT = '#8A7C68', BORDER = '#E3D9C8', GRID = '#EEE6D6';
  var FONT   = "'IBM Plex Sans', system-ui, sans-serif";
  var nf     = new Intl.NumberFormat('pt-BR');
  var brl    = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function fmt(v, unit) {
    if (v == null || isNaN(v)) return '—';
    if (unit === 'R$') return brl.format(v);
    if (unit === '%')  return nf.format(v) + '%';
    if (unit)          return nf.format(v) + ' ' + unit;
    return nf.format(v);
  }
  function grad(hex) { // área: cheio em cima, esmaece embaixo
    return new echarts.graphic.LinearGradient(0, 0, 0, 1, [
      { offset: 0, color: hex }, { offset: 1, color: hex + '22' }
    ]);
  }
  function gradBar(hex) { // barra: claro no topo, cor cheia na base (claro moderado)
    return new echarts.graphic.LinearGradient(0, 0, 0, 1, [
      { offset: 0, color: hex + '99' }, { offset: 1, color: hex }
    ]);
  }
  function base(unit) {
    return {
      color: PAL,
      textStyle: { fontFamily: FONT, color: INK },
      grid: { left: 8, right: 16, top: 40, bottom: 12, containLabel: true },
      tooltip: {
        trigger: 'axis', backgroundColor: '#fff', borderColor: BORDER, borderWidth: 1,
        padding: [8, 12], extraCssText: 'box-shadow:0 6px 24px rgba(0,0,0,.12);border-radius:10px',
        textStyle: { color: INK, fontFamily: FONT, fontSize: 12 },
        axisPointer: { lineStyle: { color: BORDER } },
        valueFormatter: function (v) { return fmt(v, unit); }
      },
      legend: { top: 0, left: 'center', icon: 'roundRect', itemWidth: 11, itemHeight: 11,
                textStyle: { color: MUT, fontFamily: FONT, fontSize: 11 } },
      animationDuration: reduce ? 0 : 850, animationEasing: 'cubicOut'
    };
  }
  function axes(labels, unit) {
    return {
      xAxis: { type: 'category', data: labels, boundaryGap: true,
        axisLine: { lineStyle: { color: BORDER } }, axisTick: { show: false },
        axisLabel: { color: MUT, fontFamily: FONT, fontSize: 11 } },
      yAxis: { type: 'value',
        axisLabel: { color: MUT, fontFamily: FONT, fontSize: 11,
          formatter: function (v) {
            if (unit === 'R$') return v >= 1e6 ? (v / 1e6) + 'mi' : (v >= 1e3 ? (v / 1e3) + 'k' : v);
            return unit === '%' ? v + '%' : nf.format(v);
          } },
        splitLine: { show: false } }
    };
  }
  function mount(el, opt) {
    if (typeof el === 'string') el = document.getElementById(el);
    if (!el || !global.echarts) return null;
    var c = global.echarts.init(el, null, { renderer: 'svg' });
    c.setOption(opt);
    window.addEventListener('resize', function () { c.resize(); });
    return c;
  }

  var BiosCharts = {
    palette: PAL,
    bar: function (el, o) {
      var op = base(o.unit); Object.assign(op, axes(o.labels, o.unit));
      op.series = (o.series || []).map(function (s, i) {
        return { name: s.name, type: 'bar', data: s.data, barMaxWidth: 30,
          itemStyle: { color: gradBar(PAL[i % PAL.length]), borderRadius: [6, 6, 2, 2] } };
      });
      return mount(el, op);
    },
    stack: function (el, o) {
      var op = base(o.unit); Object.assign(op, axes(o.labels, o.unit));
      var n = (o.series || []).length;
      op.series = (o.series || []).map(function (s, i) {
        return { name: s.name, type: 'bar', stack: 'total', data: s.data, barMaxWidth: 32,
          itemStyle: { color: PAL[i % PAL.length], borderRadius: i === n - 1 ? [5, 5, 0, 0] : 0 } };
      });
      return mount(el, op);
    },
    line: function (el, o) {
      var op = base(o.unit); Object.assign(op, axes(o.labels, o.unit));
      op.series = (o.series || []).map(function (s, i) {
        return { name: s.name, type: 'line', smooth: true, data: s.data, symbol: 'circle',
          symbolSize: 6, lineStyle: { width: 3, color: PAL[i % PAL.length] },
          itemStyle: { color: PAL[i % PAL.length] } };
      });
      return mount(el, op);
    },
    area: function (el, o) {
      var op = base(o.unit); Object.assign(op, axes(o.labels, o.unit));
      op.series = (o.series || []).map(function (s, i) {
        return { name: s.name, type: 'line', smooth: true, data: s.data, symbol: 'none',
          lineStyle: { width: 3, color: PAL[i % PAL.length] },
          areaStyle: { color: grad(PAL[i % PAL.length]) } };
      });
      return mount(el, op);
    },
    donut: function (el, o) {
      var op = base(o.unit);
      op.tooltip = { trigger: 'item', backgroundColor: '#fff', borderColor: BORDER, borderWidth: 1,
        extraCssText: 'box-shadow:0 6px 24px rgba(0,0,0,.12);border-radius:10px',
        textStyle: { color: INK, fontFamily: FONT },
        formatter: function (p) { return p.name + ': <b>' + fmt(p.value, o.unit) + '</b> (' + p.percent + '%)'; } };
      op.legend = { bottom: 0, left: 'center', icon: 'circle', itemWidth: 10, itemHeight: 10,
        textStyle: { color: MUT, fontFamily: FONT, fontSize: 11 } };
      op.series = [{
        type: 'pie', radius: ['50%', '72%'], center: ['50%', '42%'], avoidLabelOverlap: true,
        itemStyle: { borderColor: '#fff', borderWidth: 3, borderRadius: 6 },
        label: { show: !!o.center, position: 'center', formatter: o.center || '',
          fontSize: 15, fontWeight: 700, color: INK, fontFamily: FONT },
        emphasis: { scale: true, scaleSize: 6 }, labelLine: { show: false }, data: o.data || []
      }];
      return mount(el, op);
    }
  };
  global.BiosCharts = BiosCharts;
})(window);
