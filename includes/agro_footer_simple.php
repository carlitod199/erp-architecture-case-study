<?php
/* VERO Agro — footer enxuto (sem dados mockados).
   Usado por telas placeholder e páginas que não precisam do
   protótipo do dashboard (includes/agro_footer.php). */
?>
</div><!-- /.bios-shell -->
<?php if (!empty($_SESSION['user_id'])): /* A0-21 (SESS-01): aviso de expiração + continuar conectado */ ?>
<script>
(function () {
  var TIMEOUT = <?= (int)(defined('SESSION_INACTIVITY_TIMEOUT') ? SESSION_INACTIVITY_TIMEOUT : 1800) ?> * 1000;
  var AVISO_ANTES = 120 * 1000; /* 2 min antes */
  var base = <?= jsvar(defined('BIOS_BASE') ? BIOS_BASE : '') ?>;
  var timer = null, barra = null;
  function agenda() {
    if (timer) clearTimeout(timer);
    if (barra) { barra.remove(); barra = null; }
    timer = setTimeout(mostraAviso, Math.max(TIMEOUT - AVISO_ANTES, 30000));
  }
  function mostraAviso() {
    if (barra) return;
    barra = document.createElement('div');
    barra.setAttribute('role', 'alert');
    barra.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9999;display:flex;gap:12px;align-items:center;justify-content:center;'
      + 'padding:12px 16px;background:#2B2B26;color:#F4F2E8;font:14px "IBM Plex Sans",system-ui,sans-serif;box-shadow:0 -2px 12px rgba(0,0,0,.35)';
    barra.innerHTML = '⏳ Sua sessão expira em ~2 minutos por inatividade. '
      + '<button type="button" style="border:0;border-radius:8px;padding:8px 14px;background:#3E7B52;color:#fff;cursor:pointer;font:600 13px inherit">Continuar conectado</button>';
    barra.querySelector('button').addEventListener('click', function () {
      fetch(base + '/keepalive', { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok || r.redirected) { window.location.href = base + '/index'; return null; } return r.json(); })
        .then(function (j) { if (j && j.ok) { aplicaToken(j.csrf_token); agenda(); } })
        .catch(function () { window.location.href = base + '/index'; });
    });
    document.body.appendChild(barra);
  }
  /* X-04/Y-01: reidrata o token CSRF de TODOS os forms abertos com o token
     válido devolvido pelo keepalive — o POST não falha mais por token "velho"
     enquanto a sessão viver. */
  function aplicaToken(tok) {
    if (!tok || typeof tok !== 'string') return;
    window.BIOS_CSRF = tok;
    document.querySelectorAll('input[name="csrf_token"], input[name="_csrf"]').forEach(function (i) { i.value = tok; });
  }
  /* B-07: enquanto o usuário está MEXENDO a sessão não pode expirar. Cada interação
     re-arma a contagem local; a cada 5 min de atividade, renova a sessão no servidor
     (last_activity). Se o aviso já apareceu, só re-arma quando o servidor confirmar. */
  var ultimoPing = Date.now(), ultAtiv = 0, pinging = false;
  function renovaServidor(cb) {
    if (pinging) return; pinging = true;
    fetch(base + '/keepalive', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok || r.redirected) { window.location.href = base + '/index'; return null; }
        return r.json();
      })
      .then(function (j) { pinging = false; if (j) { ultimoPing = Date.now(); aplicaToken(j.csrf_token); if (cb) cb(); } })
      .catch(function () { pinging = false; });
  }
  function onAtividade() {
    var agora = Date.now();
    if (agora - ultAtiv < 1000) return;   /* throttle: no máx. 1×/s */
    ultAtiv = agora;
    if (barra) { renovaServidor(agenda); return; }   /* voltou durante o aviso */
    agenda();                                         /* re-arma a contagem local */
    if (agora - ultimoPing > 300000) renovaServidor();
  }
  ['mousemove', 'keydown', 'pointerdown', 'scroll', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, onAtividade, { passive: true });
  });
  /* X-04/Y-01: no envio de qualquer form POST, injeta o token mais recente
     conhecido (mantido pelo keepalive). Última linha de defesa junto ao PRG
     amigável do servidor (que repovoa os campos se ainda assim falhar). */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || (f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (!window.BIOS_CSRF) return;
    f.querySelectorAll('input[name="csrf_token"], input[name="_csrf"]').forEach(function (i) { i.value = window.BIOS_CSRF; });
  }, true);
  agenda();
})();
</script>
<?php endif; ?>
</body>
</html>
