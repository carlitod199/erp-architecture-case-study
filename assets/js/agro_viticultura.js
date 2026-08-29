/* ============================================================
   VERO Agro — Viticultura (interações leves, multi-página)
   Sem dependências externas. Navegação real (sem SPA).
   ============================================================ */
(function () {
  'use strict';

  // Linhas clicáveis: <tr class="click" data-href="...">
  document.querySelectorAll('tr.click[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function () {
      window.location.href = tr.getAttribute('data-href');
    });
  });

  // Seletores de fazenda/safra: recarrega preservando na querystring (preparado p/ filtro server-side).
  ['vselFaz', 'vselSaf'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', function () {
      var u = new URL(window.location.href);
      u.searchParams.set(id === 'vselFaz' ? 'faz' : 'safra', el.value);
      window.location.href = u.toString();
    });
  });

  // Pré-seleciona os selects a partir da querystring (mantém escolha após reload).
  var params = new URLSearchParams(window.location.search);
  if (params.get('faz')) { var f = document.getElementById('vselFaz'); if (f) f.value = params.get('faz'); }
  if (params.get('safra')) { var s = document.getElementById('vselSaf'); if (s) s.value = params.get('safra'); }
})();
