/* ============================================================
   VERO — CRM Agro (protótipo) · interações client-side da demo
   Estado é LOCAL (nada persiste). Reusa o modal do VERO
   (vModalOpen/vModalClose, .vmodal.open) e o visual .vflash.
   ============================================================ */
(function () {
  'use strict';

  /* ── Toast client-side (visual do .vflash do VERO) ── */
  window.crmToast = function (msg, tipo) {
    tipo = tipo || 'ok';
    var host = document.querySelector('.vflash-host');
    if (!host) {
      host = document.createElement('div');
      host.className = 'vflash-host';
      document.body.appendChild(host);
    }
    var el = document.createElement('div');
    el.className = 'vflash vflash-' + tipo;
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .4s'; }, 2400);
    setTimeout(function () { el.remove(); }, 2900);
  };

  /* ── Delegação global: chips, tabs e botões data-toast ── */
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null;
    if (!t) return;

    var chip = e.target.closest('.crm-chip');
    if (chip && !chip.closest('a')) { chip.classList.toggle('on'); return; }

    var tab = e.target.closest('.crm-tab');
    if (tab) {
      var grupo = tab.closest('.crm-tabs');
      if (grupo) grupo.querySelectorAll('.crm-tab').forEach(function (x) { x.classList.remove('on'); });
      tab.classList.add('on');
      return;
    }

    /* Qualquer elemento com data-toast dispara feedback mock; se estiver
       num modal, fecha o modal antes (fluxo "salvar" da demo). */
    var bt = e.target.closest('[data-toast]');
    if (bt) {
      var modal = bt.closest('.vmodal');
      if (modal) modal.classList.remove('open');
      window.crmToast(bt.getAttribute('data-toast'));
    }
  });

  /* ── Linhas/cards clicáveis (data-href) ── */
  document.addEventListener('click', function (e) {
    var alvo = e.target.closest('[data-href]');
    if (!alvo) return;
    if (e.target.closest('a,button,[data-toast],.crm-deal__mv')) return; /* ações internas têm prioridade */
    window.location.href = alvo.getAttribute('data-href');
  });

  /* ── Kanban do pipeline ─────────────────────────────────────
     crmKanbanInit('idContainer', OPPS, ETAPAS, CLIENTES, urlDetalhe)
     OPPS: [{id, cliente, nome, valor, etapa, prob, dias}] — estado local. */
  window.crmKanbanInit = function (elId, opps, etapas, clientes, urlDetalhe) {
    var box = document.getElementById(elId);
    if (!box) return;

    var brl = function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR'); };

    function render() {
      var html = '';
      etapas.forEach(function (nome, ei) {
        var doGrupo = opps.filter(function (o) { return o.etapa === ei; });
        var total = doGrupo.reduce(function (s, o) { return s + o.valor; }, 0);
        html += '<div class="crm-col"><div class="crm-col__head">'
              + '<span class="crm-col__nome">' + nome + ' · ' + doGrupo.length + '</span>'
              + '<span class="crm-col__total">' + (total ? brl(total) : '—') + '</span></div>';
        doGrupo.forEach(function (o) {
          var urgente = o.dias > 0 && o.dias <= 3;
          html += '<div class="crm-deal" data-opp="' + o.id + '">'
                + '<div class="crm-deal__cli">' + (clientes[o.cliente] || '') + '</div>'
                + '<div class="crm-deal__nome">' + o.nome + '</div>'
                + '<div class="crm-deal__valor">' + brl(o.valor) + '</div>'
                + '<div class="crm-deal__foot">'
                +   '<span><span class="crm-pill p-teal">' + o.prob + '%</span> '
                +   (urgente ? '<span class="crm-pill p-amber">' + o.dias + 'd</span>' : '')
                +   '</span>'
                +   '<span class="crm-deal__mv">'
                +     '<button type="button" data-mv="-1" title="Voltar etapa">‹</button>'
                +     '<button type="button" data-mv="1" title="Avançar etapa">›</button>'
                +   '</span>'
                + '</div></div>';
        });
        html += '</div>';
      });
      box.innerHTML = html;
    }

    box.addEventListener('click', function (e) {
      var card = e.target.closest('.crm-deal');
      if (!card) return;
      var opp = null;
      opps.forEach(function (o) { if (o.id === card.getAttribute('data-opp')) opp = o; });
      if (!opp) return;

      var mv = e.target.closest('[data-mv]');
      if (mv) {
        e.stopPropagation();
        var nova = Math.max(0, Math.min(etapas.length - 1, opp.etapa + parseInt(mv.getAttribute('data-mv'), 10)));
        if (nova !== opp.etapa) {
          opp.etapa = nova;
          render();
          window.crmToast('Oportunidade movida para "' + etapas[nova] + '"');
        }
        return;
      }
      window.location.href = urlDetalhe + '?id=' + opp.id;
    });

    render();
  };

  /* ── Detalhe da oportunidade: avançar/voltar etapa (estado local) ──
     crmOppInit({etapa, etapas}) — atualiza barras #crm-funil e rótulos. */
  window.crmOppInit = function (cfg) {
    var etapa = cfg.etapa, etapas = cfg.etapas;

    function render() {
      var funil = document.getElementById('crm-funil');
      if (funil) {
        funil.querySelectorAll('[data-etapa]').forEach(function (seg) {
          var i = parseInt(seg.getAttribute('data-etapa'), 10);
          seg.querySelector('.crm-fill').style.width = i <= etapa ? '100%' : '0%';
        });
      }
      var lbl = document.getElementById('crm-etapa-atual');
      if (lbl) lbl.textContent = etapas[etapa];
      var prox = document.getElementById('crm-btn-avancar');
      if (prox) {
        var fim = etapa >= etapas.length - 1;
        prox.style.display = fim ? 'none' : '';
        if (!fim) prox.textContent = 'Avançar para ' + etapas[etapa + 1] + ' ›';
      }
      var ganha = document.getElementById('crm-opp-ganha');
      if (ganha) ganha.style.display = etapa >= etapas.length - 1 ? '' : 'none';
    }

    window.crmOppMove = function (dir) {
      var nova = Math.max(0, Math.min(etapas.length - 1, etapa + dir));
      if (nova === etapa) return;
      etapa = nova;
      render();
      window.crmToast('Oportunidade movida para "' + etapas[etapa] + '"');
    };

    render();
  };

  /* ── Calculadora de ROI (ids fixos da tela roi.php) ── */
  window.crmRoiCalc = function () {
    var g = function (id) { return document.getElementById(id); };
    if (!g('roiArea')) return;

    var area  = parseFloat(g('roiArea').value)  || 0;
    var prod  = parseFloat(g('roiProd').value)  || 0;   /* t/ha atual */
    var preco = parseFloat(g('roiPreco').value) || 0;   /* R$/kg */
    var incr  = parseFloat(g('roiIncr').value)  || 0;   /* % */
    var apl   = parseFloat(g('roiApl').value)   || 0;   /* R$/ha */
    var sel   = (g('roiProdSel').value || '0|0').split('|');
    var pPreco = parseFloat(sel[0]) || 0, pDose = parseFloat(sel[1]) || 0;

    var custoHa = pPreco * pDose + apl;
    var inv     = custoHa * area;
    var prodAdT = prod * (incr / 100);                  /* t/ha adicionais */
    var rec     = prodAdT * 1000 * preco * area;
    var ret     = rec - inv;
    var roi     = inv > 0 ? ret / inv * 100 : 0;

    var brl  = function (v) { return 'R$ ' + Math.round(v).toLocaleString('pt-BR'); };
    var num  = function (v, d) { return Number(v).toLocaleString('pt-BR', { maximumFractionDigits: d, minimumFractionDigits: d }); };
    var set  = function (id, txt) { var el = g(id); if (el) el.textContent = txt; };

    set('rInv', brl(inv));
    set('rRec', brl(rec));
    set('rRoi', num(roi, 0) + '%');
    set('rQtd', num(pDose * area, 1) + ' un');
    set('rCustoHa', brl(custoHa));
    set('rInv2', brl(inv));
    set('rProdAd', num(prodAdT, 2) + ' t/ha (' + num(prodAdT * area, 1) + ' t no total)');
    set('rRec2', brl(rec));
    set('rRet', brl(ret));
    set('rRoiBig', num(roi, 0) + '%');
    set('rFrase', 'Investindo ' + brl(inv) + ' o produtor tem retorno líquido de ' + brl(ret)
      + ' — cada R$ 1 aplicado retorna R$ ' + num(inv > 0 ? 1 + ret / inv : 0, 2) + '.');
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('roiArea')) {
      window.crmRoiCalc();
      ['roiArea', 'roiProd', 'roiPreco', 'roiIncr', 'roiApl', 'roiProdSel'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', window.crmRoiCalc); el.addEventListener('change', window.crmRoiCalc); }
      });
    }
  });
})();
