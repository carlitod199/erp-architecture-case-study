/* ============================================================
   VERO — Universidade / Widget de Ajuda (T4)
   Injetado por UMA linha em includes/agro_header.php. Zero markup
   nas telas. Carrega assíncrono e falha em silêncio (Regra de Ouro
   nº 4: a Universidade nunca derruba o ERP).
   - Busca /api/uni/v1/ajuda?rota=<path>. 204 → o "?" nem aparece.
   - Botão "?" fixo (topo-direita) + painel lateral. F1 abre, Esc fecha.
   - Ordem fixa: finalidade → como fazer → vídeo → erros → antes/depois → ações.
   - Primeira visita à tela: o "?" pulsa UMA vez. Nunca reabre sozinho.
   ============================================================ */
(function () {
  'use strict';
  if (window.__uniAjuda) return;
  window.__uniAjuda = true;

  // Base do projeto e rota, derivados do próprio <script> (…/assets/js/uni-ajuda.js)
  var self = document.currentScript;
  if (!self) return;
  var srcPath = (function () { try { return new URL(self.src).pathname; } catch (e) { return ''; } })();
  var base = srcPath.split('/assets/')[0] || '';               // ex.: '/vero' ou ''
  var path = location.pathname;
  var rota = base && path.indexOf(base) === 0 ? path.slice(base.length) : path; // '/agro/apontamentos.php'
  if (!rota || rota.charAt(0) !== '/') rota = '/' + rota;

  var API = base + '/api/uni/v1';
  var esc = window.esc || function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  var dados = null, painel = null, botao = null, aberto = false, carregou = false;

  function req(metodo, caminho, corpo) {
    return fetch(API + caminho, {
      method: metodo,
      credentials: 'same-origin',
      headers: corpo ? { 'Content-Type': 'application/json' } : undefined,
      body: corpo ? JSON.stringify(corpo) : undefined
    });
  }

  function evento(acao) {
    try { req('POST', '/evento', { rota: rota, acao: acao }); } catch (e) { /* silêncio */ }
  }

  // ── Sondagem inicial: só mostra o "?" se a rota tiver conteúdo ──
  req('GET', '/ajuda?rota=' + encodeURIComponent(rota)).then(function (r) {
    if (r.status !== 200) return;               // 204/erro → widget não aparece
    return r.json().then(function (j) { dados = j; carregou = true; montarBotao(); });
  }).catch(function () { /* falha silenciosa */ });

  function montarBotao() {
    botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'uni-fab';
    botao.setAttribute('aria-label', 'Ajuda desta tela');
    botao.title = 'Ajuda desta tela (F1)';
    botao.textContent = '?';
    botao.addEventListener('click', alternar);
    document.body.appendChild(botao);

    // Primeira visita a ESTA rota: pulsa uma vez.
    try {
      var chave = 'uni_visto:' + rota;
      if (!localStorage.getItem(chave)) {
        botao.classList.add('uni-pulse');
        localStorage.setItem(chave, '1');
        setTimeout(function () { botao && botao.classList.remove('uni-pulse'); }, 2600);
      }
    } catch (e) { /* localStorage indisponível: sem pulso */ }
  }

  function alternar() { aberto ? fechar() : abrir(); }

  function abrir() {
    if (!carregou) return;
    if (!painel) montarPainel();
    painel.classList.add('open');
    aberto = true;
    if (botao) botao.classList.remove('uni-pulse');
    evento('abriu');
    var f = painel.querySelector('.uni-fechar'); if (f) f.focus();
  }

  function fechar() { if (painel) painel.classList.remove('open'); aberto = false; }

  function bloco(titulo, htmlInterno) {
    if (!htmlInterno) return '';
    return '<section class="uni-bl"><h3>' + esc(titulo) + '</h3>' + htmlInterno + '</section>';
  }

  function montarPainel() {
    var b = dados.blocos || {};
    var html = '';

    // finalidade
    if (b.finalidade) html += bloco('Para que serve', '<p>' + esc(b.finalidade) + '</p>');

    // como fazer
    if (b.como_fazer && b.como_fazer.length) {
      var li = b.como_fazer.map(function (p) { return '<li>' + realce(p) + '</li>'; }).join('');
      html += bloco('Como fazer', '<ol class="uni-passos">' + li + '</ol>');
    }

    // vídeo
    if (b.video && b.video.url) {
      html += bloco('Vídeo', '<a class="uni-video" href="' + esc(b.video.url) + '" target="_blank" rel="noopener">▶ ' +
        esc(b.video.titulo || 'Assistir') + (b.video.duracao_seg ? ' · ' + Math.round(b.video.duracao_seg / 60) + ' min' : '') + '</a>');
    }

    // erros comuns
    if (b.erros_comuns && b.erros_comuns.length) {
      var er = b.erros_comuns.map(function (e) {
        return '<li>' + (e.titulo ? '<b>' + esc(e.titulo) + '.</b> ' : '') + esc(e.texto) + '</li>';
      }).join('');
      html += bloco('Erros comuns', '<ul class="uni-erros">' + er + '</ul>');
    }

    // antes e depois
    var fx = b.fluxo || { antes: [], depois: [] };
    if ((fx.antes && fx.antes.length) || (fx.depois && fx.depois.length)) {
      html += bloco('Antes e depois no fluxo',
        '<div class="uni-fluxo">' + coluna('Antes', fx.antes) + coluna('Depois', fx.depois) + '</div>');
    }

    // ações
    var ac = dados.acoes || {};
    var acoes = '';
    if (ac.praticar) acoes += '<a class="uni-acao" href="' + esc(base + (ac.ver_tudo || '#')) + '">Praticar</a>';
    if (ac.ver_tudo) acoes += '<a class="uni-acao" href="' + esc(base + ac.ver_tudo) + '">Ver tudo</a>';
    acoes += '<button type="button" class="uni-acao uni-chamado">Abrir chamado</button>';
    html += '<section class="uni-bl uni-acoes">' + acoes + '</section>';

    var rev = dados.atualizado_em ? 'Revisado em ' + esc(dados.atualizado_em) : '';
    painel = document.createElement('aside');
    painel.className = 'uni-painel';
    painel.setAttribute('role', 'complementary');
    painel.setAttribute('aria-label', 'Ajuda: ' + (dados.tela && dados.tela.titulo || 'esta tela'));
    painel.innerHTML =
      '<header class="uni-top">' +
        '<div><div class="uni-kicker">Universidade VERO</div>' +
          '<h2>' + esc(dados.tela && dados.tela.titulo || 'Ajuda') + '</h2></div>' +
        '<button type="button" class="uni-fechar" aria-label="Fechar (Esc)">×</button>' +
      '</header>' +
      '<div class="uni-corpo">' + html + '</div>' +
      '<footer class="uni-rodape">' + rev + (dados.versao ? ' · v' + esc(dados.versao) : '') + '</footer>';
    document.body.appendChild(painel);

    painel.querySelector('.uni-fechar').addEventListener('click', fechar);
    var ch = painel.querySelector('.uni-chamado');
    if (ch) ch.addEventListener('click', function () {
      evento('abriu_chamado');
      ch.textContent = 'Chamado registrado com a tela e o seu perfil';
      ch.disabled = true;
    });
  }

  function coluna(rot, itens) {
    itens = itens || [];
    var li = itens.length
      ? itens.map(function (x) {
          var alvo = x.rota ? base + x.rota : '#';
          return '<li><a href="' + esc(alvo) + '">' + esc(x.titulo) + '</a></li>';
        }).join('')
      : '<li class="uni-vazio">—</li>';
    return '<div class="uni-col"><span>' + esc(rot) + '</span><ul>' + li + '</ul></div>';
  }

  // Negrito **assim** vindo do markdown → <b> (resto é escapado).
  function realce(s) {
    return esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  }

  // Atalhos globais: F1 abre/fecha, Esc fecha.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'F1') { e.preventDefault(); if (carregou) alternar(); }
    else if (e.key === 'Escape' && aberto) fechar();
  });
})();
