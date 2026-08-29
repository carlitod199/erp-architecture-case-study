/* ============================================================
   VERO — assets/js/bios.js · v1.0.0
   ============================================================ */

'use strict';

/* ── Painel lateral ─────────────────────────────────────── */
const BiosPanel = {
  open(panelId) {
    const el = document.getElementById(panelId);
    if (el) el.classList.add('open');
  },
  close(panelId) {
    const el = document.getElementById(panelId);
    if (el) el.classList.remove('open');
  },
  toggle(panelId) {
    const el = document.getElementById(panelId);
    if (el) el.classList.toggle('open');
  }
};

/* ── Toast notifications ─────────────────────────────────── */
const BiosToast = {
  container: null,

  init() {
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.id = 'bios-toast-container';
      document.body.appendChild(this.container);
    }
  },

  show(message, type = 'info', duration = 4000) {
    this.init();
    const icons = {
      success: 'ti-circle-check',
      danger:  'ti-alert-circle',
      warning: 'ti-alert-triangle',
      info:    'ti-info-circle',
    };
    const toast = document.createElement('div');
    toast.className = `bios-toast bios-toast--${type}`;
    toast.innerHTML = `
      <i class="ti ${icons[type] || icons.info}" aria-hidden="true"></i>
      <span>${message}</span>
    `;
    this.container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity .3s';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  },

  success: (msg, d) => BiosToast.show(msg, 'success', d),
  danger:  (msg, d) => BiosToast.show(msg, 'danger', d),
  warning: (msg, d) => BiosToast.show(msg, 'warning', d),
  info:    (msg, d) => BiosToast.show(msg, 'info', d),
};

/* ── AJAX helper ─────────────────────────────────────────── */
const BiosAPI = {
  async get(url, params = {}) {
    const qs = new URLSearchParams(params).toString();
    const fullUrl = qs ? `${url}?${qs}` : url;
    const res = await fetch(fullUrl, {
      headers: { 'X-CSRF-Token': window.BIOS_CSRF || '' }
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  },

  async post(url, data = {}) {
    const body = new FormData();
    body.append('csrf_token', window.BIOS_CSRF || '');
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(url, { method: 'POST', body });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }
};

/* ── Loader inline ───────────────────────────────────────── */
const BiosLoader = {
  html(size = 40) {
    return `<div style="display:flex;align-items:center;justify-content:center;padding:${size}px 0;">
      <span style="font-size:13px;color:var(--text-tertiary);">Carregando...</span>
    </div>`;
  },
  set(elementId, html) {
    const el = document.getElementById(elementId);
    if (el) el.innerHTML = html;
  }
};

/* ── Formatadores ────────────────────────────────────────── */
const BiosFmt = {
  currency(val) {
    return 'R$ ' + Number(val).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  },
  number(val) {
    return Number(val).toLocaleString('pt-BR');
  },
  percent(val, decimals = 1) {
    return Number(val).toFixed(decimals) + '%';
  },
  initials(name) {
    if (!name) return '??';
    return name.trim().split(' ').filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
  }
};

/* ── Relógio ao vivo ─────────────────────────────────────── */
function biosStartClock(elementId) {
  function tick() {
    const el = document.getElementById(elementId);
    if (!el) return;
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    el.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  }
  tick();
  return setInterval(tick, 1000);
}

/* ── Confirmar ação destrutiva ───────────────────────────── */
function biosConfirm(message, onConfirm) {
  if (window.confirm(message)) onConfirm();
}

/* ── Copiar para clipboard ───────────────────────────────── */
function biosCopy(text) {
  navigator.clipboard.writeText(text)
    .then(() => BiosToast.success('Copiado para a área de transferência'))
    .catch(() => BiosToast.danger('Não foi possível copiar'));
}

/* ── Expose global ───────────────────────────────────────── */
window.BiosPanel  = BiosPanel;
window.BiosToast  = BiosToast;
window.BiosAPI    = BiosAPI;
window.BiosLoader = BiosLoader;
window.BiosFmt    = BiosFmt;
window.biosStartClock = biosStartClock;
window.biosConfirm    = biosConfirm;
window.biosCopy        = biosCopy;
