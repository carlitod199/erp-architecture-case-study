/* ============================================================
   VERO — assets/js/vero-ui.js
   Correções de comportamento do Agente 4 (A4-02, Lote 0).
   UI-001: cria o botão hambúrguer + backdrop e alterna a
   navegação off-canvas no mobile/tablet. Progressive
   enhancement puro (sem dependências); no-op onde não há shell
   (ex.: tela de login). Carregado com `defer` pelo shell.
   ============================================================ */
(function () {
  'use strict';

  function init() {
    var shell = document.querySelector('.bios-shell');
    var macro = document.querySelector('.macromenu');
    var micro = document.querySelector('.microrail');
    // Sem shell de navegação (login, páginas de erro): nada a fazer.
    if (!shell || !macro) return;
    // Evita duplicação se o script rodar duas vezes.
    if (document.querySelector('.vero-navbtn')) return;

    document.body.classList.add('vero-has-navbtn');

    // Botão hambúrguer.
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'vero-navbtn';
    btn.setAttribute('aria-label', 'Abrir menu de navegação');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>';

    // Backdrop.
    var scrim = document.createElement('div');
    scrim.className = 'vero-navscrim';

    function isOpen() { return macro.classList.contains('open'); }

    function open() {
      macro.classList.add('open');
      if (micro) micro.classList.add('open');
      scrim.classList.add('show');
      btn.setAttribute('aria-expanded', 'true');
      btn.setAttribute('aria-label', 'Fechar menu de navegação');
    }
    function close() {
      macro.classList.remove('open');
      if (micro) micro.classList.remove('open');
      scrim.classList.remove('show');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-label', 'Abrir menu de navegação');
    }
    function toggle() { isOpen() ? close() : open(); }

    btn.addEventListener('click', toggle);
    scrim.addEventListener('click', close);
    // Fecha ao navegar (clicar num link do menu).
    macro.addEventListener('click', function (e) {
      if (e.target.closest('a')) close();
    });
    if (micro) micro.addEventListener('click', function (e) {
      if (e.target.closest('a')) close();
    });
    // ESC fecha.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen()) close();
    });
    // Ao voltar para desktop, garante estado limpo.
    window.addEventListener('resize', function () {
      if (window.innerWidth > 1080 && isOpen()) close();
    });

    document.body.appendChild(btn);
    document.body.appendChild(scrim);
  }

  /* UX-02 (global) — botão "Limpar filtros" em toda toolbar de filtro.
     Aparece só quando HÁ filtro ativo, e a decisão é precisa: olha apenas
     os NOMES dos campos do próprio formulário (não dispara por ?p= de
     paginação nem ?ver= de detalhe). Limpa apontando para a rota sem query.
     Idempotente: pula formulários que já têm um "Limpar". */
  function initClearFilters() {
    var params = new URLSearchParams(location.search);
    var forms = document.querySelectorAll('.vtoolbar form[method="get" i], .vtoolbar form:not([method])');
    forms.forEach(function (form) {
      if (form.querySelector('[data-vero-clear], a[href="?"]')) return; // já tem
      var campos = form.querySelectorAll('input[name], select[name]');
      var ativo = false;
      campos.forEach(function (el) {
        if (el.type === 'submit' || el.type === 'button') return;
        if ((params.get(el.name) || '').trim() !== '') ativo = true;
      });
      if (!ativo) return;
      var a = document.createElement('a');
      a.className = 'vbtn vbtn-ghost';
      a.textContent = 'Limpar';
      a.href = location.pathname;
      a.title = 'Limpar filtros';
      a.setAttribute('data-vero-clear', '');
      form.appendChild(a);
    });
  }

  /* Toast — transforma os .vflash renderizados pelo servidor
     em avisos flutuantes discretos. Move-os para um host fixo (saem do fluxo),
     adiciona botão fechar e anima. Sucesso/info somem sozinhos (pausa ao passar o
     mouse); ERRO PERSISTE até o usuário dispensar (UX-04). Sem JS, fica inline. */
  function toastHost() {
    var host = document.querySelector('.vflash-host');
    if (!host) {
      host = document.createElement('div');
      host.className = 'vflash-host';
      host.setAttribute('role', 'status');
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    return host;
  }
  function pushToast(f) {
    var host = toastHost();
    var isErr = f.classList.contains('vflash-erro');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'vflash-close';
    btn.setAttribute('aria-label', 'Fechar aviso');
    btn.innerHTML = '&times;';
    f.appendChild(btn);
    host.appendChild(f);
    var timer;
    function dismiss() {
      clearTimeout(timer);
      f.classList.add('vflash--out');
      setTimeout(function () {
        if (f.parentNode) f.parentNode.removeChild(f);
        if (host && !host.children.length && host.parentNode) host.parentNode.removeChild(host);
      }, 260);
    }
    btn.addEventListener('click', dismiss);
    if (isErr) {
      // erro fica na tela até ser dispensado (o usuário precisa ler/reagir)
      f.classList.add('vflash--sticky');
    } else {
      timer = setTimeout(dismiss, 5000);
      f.addEventListener('mouseenter', function () { clearTimeout(timer); });
      f.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 2500); });
    }
  }
  function initToasts() {
    Array.prototype.forEach.call(document.querySelectorAll('.vwrap > .vflash'), function (f) { pushToast(f); });
  }

  /* ---- Página-em-modal ----
     Telas auxiliares (Grupos/Subgrupos, Almoxarifados) abrem do MENU como modal,
     sem navegar. Busca a página, extrai o `.vwrap` e injeta NATIVO no modal; os
     forms (POST/GET) são reenviados por fetch e re-renderizam; o flash vira toast.
     Sem editar menu/header canônicos: interceptamos o link pelo href. */
  var PM_ALVOS = ['grupos_subgrupos.php', 'almoxarifados.php', 'entradas.php', 'saidas.php'];

  function pmToast(text, cls) {
    var d = document.createElement('div');
    d.className = (cls && cls.indexOf('vflash') >= 0) ? cls : 'vflash vflash-ok';
    d.textContent = text;
    pushToast(d);
  }
  function pmAbs(base, href) { try { return new URL(href, new URL(base, location.href)).href; } catch (e) { return href; } }

  function pmOpen(url) {
    var ov = document.createElement('div');
    ov.className = 'vmodal open vero-pagemodal';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.innerHTML = '<div class="vbox"><button class="vclose vpm-close" type="button" aria-label="Fechar">&times;</button><div class="vpm-body"><div class="vpm-load">Carregando…</div></div></div>';
    document.body.appendChild(ov);
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); document.removeEventListener('keydown', onEsc); }
    function onEsc(e) { if (e.key === 'Escape') close(); }
    ov.querySelector('.vpm-close').addEventListener('click', close);
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', onEsc);
    pmLoad(ov, url, null);
  }
  function pmLoad(ov, url, opts) {
    var body = ov.querySelector('.vpm-body');
    body.innerHTML = '<div class="vpm-load">Carregando…</div>';
    fetch(url, Object.assign({ credentials: 'same-origin', redirect: 'follow' }, opts || {}))
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var wrap = doc.querySelector('.vwrap');
        if (!wrap) { body.innerHTML = '<div class="vpm-load">Não foi possível carregar o conteúdo.</div>'; return; }
        Array.prototype.forEach.call(wrap.querySelectorAll('.vflash'), function (f) { pmToast(f.textContent, f.className); if (f.parentNode) f.parentNode.removeChild(f); });
        body.innerHTML = '';
        // carrega os <style> da página para o modal (senão a tela abre SEM estilização):
        // o <style> inline costuma ficar aninhado no shell (fora do .vwrap) — pega TODOS
        Array.prototype.forEach.call(doc.querySelectorAll('style'), function (st) {
          wrap.insertBefore(st.cloneNode(true), wrap.firstChild);
        });
        body.appendChild(wrap);
        pmBind(ov, wrap, url);
      })
      .catch(function () { body.innerHTML = '<div class="vpm-load">Erro ao carregar. Tente novamente.</div>'; });
  }
  function pmBind(ov, wrap, baseUrl) {
    Array.prototype.forEach.call(wrap.querySelectorAll('form'), function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        var action = pmAbs(baseUrl, form.getAttribute('action') || baseUrl);
        if (method === 'post') {
          pmLoad(ov, action, { method: 'POST', body: new FormData(form) });
        } else {
          var qs = new URLSearchParams(new FormData(form)).toString();
          pmLoad(ov, action.split('?')[0] + (qs ? '?' + qs : ''), null);
        }
      });
    });
    Array.prototype.forEach.call(wrap.querySelectorAll('a[href]'), function (a) {
      var href = a.getAttribute('href');
      if (!href || /^(https?:|mailto:|tel:|#|javascript:)/i.test(href)) return;
      a.addEventListener('click', function (e) { e.preventDefault(); pmLoad(ov, pmAbs(baseUrl, href), null); });
    });
  }
  function pmInit() {
    Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (a) {
      var href = a.getAttribute('href') || '';
      for (var i = 0; i < PM_ALVOS.length; i++) {
        if (href.indexOf(PM_ALVOS[i]) >= 0) {
          a.addEventListener('click', function (e) { e.preventDefault(); pmOpen(a.href); });
          break;
        }
      }
    });
  }

  /* ---- Confirmação estilizada (UX-01 — substitui o confirm() nativo) ----
     window.veroConfirm(msg, opts) -> Promise<bool>. Adoção declarativa: troque
     `onsubmit="return confirm('X')"` por `data-confirm="X"` no <form>, ou
     `onclick="return confirm('X')"` por `data-confirm="X"` no <button>/<a>.
     O componente intercepta e só prossegue quando o usuário confirma. */
  function veroConfirm(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var ov = document.createElement('div');
      ov.className = 'vconfirm-ov';
      ov.innerHTML =
        '<div class="vconfirm" role="alertdialog" aria-modal="true">' +
          '<div class="vconfirm__msg"></div>' +
          '<div class="vconfirm__actions">' +
            '<button type="button" class="vbtn vbtn-ghost vbtn-sm vconfirm__cancel"></button>' +
            '<button type="button" class="vbtn vbtn-primary vbtn-sm vconfirm__ok"></button>' +
          '</div>' +
        '</div>';
      var box = ov.firstChild;
      box.querySelector('.vconfirm__msg').textContent = message || 'Confirmar esta ação?';
      var okBtn = box.querySelector('.vconfirm__ok');
      var cancelBtn = box.querySelector('.vconfirm__cancel');
      okBtn.textContent = opts.okLabel || 'Confirmar';
      cancelBtn.textContent = opts.cancelLabel || 'Cancelar';
      if (opts.danger) okBtn.classList.add('vconfirm__ok--danger');
      var prevFocus = document.activeElement;
      function done(val) {
        document.removeEventListener('keydown', onKey, true);
        if (ov.parentNode) ov.parentNode.removeChild(ov);
        try { if (prevFocus && prevFocus.focus) prevFocus.focus(); } catch (e) {}
        resolve(val);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); done(false); }
        else if (e.key === 'Enter') { e.preventDefault(); done(true); }
        else if (e.key === 'Tab') { // mantém o foco dentro do modal
          var f = [cancelBtn, okBtn], i = f.indexOf(document.activeElement);
          e.preventDefault(); f[(i + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
        }
      }
      okBtn.addEventListener('click', function () { done(true); });
      cancelBtn.addEventListener('click', function () { done(false); });
      ov.addEventListener('mousedown', function (e) { if (e.target === ov) done(false); });
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(ov);
      okBtn.focus();
    });
  }
  window.veroConfirm = veroConfirm;

  function initConfirms() {
    // <form data-confirm> — captura o submit antes de enviar
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.getAttribute || !form.hasAttribute('data-confirm')) return;
      if (form.getAttribute('data-vconfirmed') === '1') { form.removeAttribute('data-vconfirmed'); return; }
      e.preventDefault();
      veroConfirm(form.getAttribute('data-confirm'), { danger: form.hasAttribute('data-confirm-danger'), okLabel: form.getAttribute('data-confirm-ok') || undefined })
        .then(function (ok) {
          if (!ok) return;
          form.setAttribute('data-vconfirmed', '1');
          if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    }, true);

    // <a data-confirm> e <button data-confirm> (quando o form NÃO tem o atributo)
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
      if (!el || el.tagName === 'FORM') return;
      var danger = el.hasAttribute('data-confirm-danger');
      var okLbl = el.getAttribute('data-confirm-ok') || undefined;
      if (el.tagName === 'A' && el.getAttribute('href')) {
        e.preventDefault();
        veroConfirm(el.getAttribute('data-confirm'), { danger: danger, okLabel: okLbl })
          .then(function (ok) { if (ok) window.location.href = el.href; });
        return;
      }
      if (el.form && !el.form.hasAttribute('data-confirm')) { // botão de submit com confirm próprio
        e.preventDefault();
        var f = el.form;
        veroConfirm(el.getAttribute('data-confirm'), { danger: danger, okLabel: okLbl }).then(function (ok) {
          if (!ok) return;
          f.setAttribute('data-vconfirmed', '1');
          if (f.requestSubmit) f.requestSubmit(el); else f.submit();
        });
      }
    }, true);
  }

  /* ---- Estado "Salvando…" no submit (UX-04) ----
     Auto-aplica a forms de ESCRITA (method=post) que NAVEGAM: desabilita o
     botão + spinner + rótulo, evitando duplo-POST e dando feedback. Roda em
     BUBBLE com guarda `defaultPrevented`, então NÃO interfere no veroConfirm
     (captura) nem no page-modal/validação (que dão preventDefault + AJAX).
     Opt-out por `data-no-loading` no <form>; rótulo custom por
     `data-loading-label` no botão. Desabilita async (setTimeout 0) p/ não
     tirar o name/value do submitter do POST. */
  function initSubmitLoading() {
    document.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      if (!form || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
      if (form.hasAttribute('data-no-loading')) return;
      var btn = e.submitter || form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
      if (!btn || btn.disabled || btn.getAttribute('data-loading-on') === '1') return;
      btn.setAttribute('data-loading-on', '1');
      btn.setAttribute('aria-busy', 'true');
      btn.classList.add('is-loading');
      var label = btn.getAttribute('data-loading-label') || 'Salvando…';
      if (btn.tagName === 'INPUT') {
        btn.setAttribute('data-loading-prev', btn.value);
        btn.value = label;
      } else {
        btn.setAttribute('data-loading-prev', btn.innerHTML);
        btn.innerHTML = '<span class="vspin" aria-hidden="true"></span>' + label;
      }
      window.setTimeout(function () { btn.disabled = true; }, 0);
    });
  }

  /* ---- Foco e focus-trap nos modais (UX-09) ----
     Aditivo ao vModalOpen do vero_crud (que foca o 1º input — muitas vezes um
     hidden). Aqui: ao abrir um .vmodal, foca o 1º campo VISÍVEL e prende o TAB
     dentro do modal (acessibilidade + cadastro em série pelo teclado). */
  var VF_SEL = 'a[href],button:not([disabled]),input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  function vfFocusables(m) {
    return Array.prototype.filter.call(m.querySelectorAll(VF_SEL), function (el) {
      return el.offsetParent !== null && !el.closest('[hidden]');
    });
  }
  function initModalFocus() {
    if (typeof MutationObserver === 'function') {
      var obs = new MutationObserver(function (muts) {
        muts.forEach(function (mu) {
          var m = mu.target;
          if (!m.classList || !m.classList.contains('vmodal')) return;
          if (m.classList.contains('open')) {
            if (m.getAttribute('data-vfocused') === '1') return;
            m.setAttribute('data-vfocused', '1');
            var f = vfFocusables(m);
            // foca o 1º CAMPO de formulário (não o botão × do cabeçalho); senão o 1º focável
            var campo = f.filter(function (el) { return /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName); })[0] || f[0];
            if (campo) { try { campo.focus(); if (campo.select) campo.select(); } catch (e) {} }
          } else {
            m.removeAttribute('data-vfocused');
          }
        });
      });
      Array.prototype.forEach.call(document.querySelectorAll('.vmodal'), function (m) {
        obs.observe(m, { attributes: true, attributeFilter: ['class'] });
      });
    }
    // A4-06/AUD-011: modal server-rendered já ABERTO no load (?editar=ID/?novo=1)
    // não passa pelo observer — foca o 1º campo visível aqui.
    var aberto = document.querySelector('.vmodal.open');
    if (aberto && aberto.getAttribute('data-vfocused') !== '1') {
      aberto.setAttribute('data-vfocused', '1');
      var fa = vfFocusables(aberto);
      var campoA = fa.filter(function (el) { return /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName); })[0] || fa[0];
      if (campoA) { try { campoA.focus(); if (campoA.select) campoA.select(); } catch (e) {} }
    }
    // focus-trap: TAB circula dentro do modal aberto
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var open = document.querySelector('.vmodal.open, .vero-pagemodal');
      if (!open) return;
      var f = vfFocusables(open);
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1], a = document.activeElement;
      if (!open.contains(a)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && a === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && a === last) { e.preventDefault(); first.focus(); }
    });
  }

  /* ---- Abrir uma rota em MODAL (iframe) ----
     Links com data-vmodal, ou para rotas em VMODAL_ROUTES, abrem num overlay
     com iframe em vez de navegar (a página fica no contexto; impressão dentro
     do iframe funciona normal). Reutilizável: window.veroIframeModal(url, título). */
  var VMODAL_ROUTES = ['/packing/etiqueta_caixa', '/packing/etiquetas'];
  function ensureVioStyle() {
    if (document.getElementById('vio-style')) return;
    var s = document.createElement('style'); s.id = 'vio-style';
    s.textContent =
      '.vio-ov{position:fixed;inset:0;background:rgba(16,24,40,.55);z-index:9999;display:flex;'
      + 'align-items:center;justify-content:center;padding:2vh 2vw}'
      + '.vio-box{width:min(1120px,96vw);height:92vh;background:#fff;border-radius:12px;overflow:hidden;'
      + 'display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.35)}'
      + '.vio-hd{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid #e5e7eb;'
      + 'font-weight:700;color:#101828}'
      + '.vio-hd .vio-x{margin-left:auto;border:0;background:transparent;font-size:22px;line-height:1;'
      + 'cursor:pointer;color:#475467;padding:2px 8px;border-radius:6px}'
      + '.vio-hd .vio-x:hover{background:#f1f5f9}'
      + '.vio-fr{flex:1;border:0;width:100%}';
    document.head.appendChild(s);
  }
  function veroIframeModal(url, titulo) {
    ensureVioStyle();
    var ov = document.createElement('div'); ov.className = 'vio-ov';
    var box = document.createElement('div'); box.className = 'vio-box';
    var hd = document.createElement('div'); hd.className = 'vio-hd';
    var t = document.createElement('span'); t.textContent = titulo || '';
    var x = document.createElement('button'); x.type = 'button'; x.className = 'vio-x';
    x.setAttribute('aria-label', 'Fechar'); x.innerHTML = '&times;';
    var fr = document.createElement('iframe'); fr.className = 'vio-fr'; fr.src = url;
    hd.appendChild(t); hd.appendChild(x); box.appendChild(hd); box.appendChild(fr);
    ov.appendChild(box); document.body.appendChild(ov);
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    x.addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', onKey);
    return ov;
  }
  window.veroIframeModal = veroIframeModal;
  function initIframeModals() {
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || a.target === '_blank') return;
      var href = a.getAttribute('href') || '';
      var route = VMODAL_ROUTES.some(function (r) { return href.indexOf(r) !== -1; });
      if (!a.hasAttribute('data-vmodal') && !route) return;
      e.preventDefault();
      var u = href + (href.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
      var titulo = (a.textContent || '').trim() || a.getAttribute('title') || a.getAttribute('aria-label') || 'Etiqueta';
      veroIframeModal(u, titulo);
    }, true);
  }

  function boot() { init(); initClearFilters(); initToasts(); initConfirms(); initSubmitLoading(); initModalFocus(); initIframeModals(); pmInit(); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
