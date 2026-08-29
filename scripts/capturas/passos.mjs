// ============================================================
// VERO — Universidade / capturas de PASSOS com marcação (T7 visual)
// Lê conteudo/roteiros/*.json e gera, por passo, um print da tela
// com o elemento-alvo destacado (número + caixa/seta + legenda),
// mascarando dados sensíveis. Salva em assets/uni/passos/ (web).
//
// Uso:
//   node passos.mjs                 # todos os roteiros
//   node passos.mjs <slug>          # um roteiro
// Requer: npm i playwright (já instalado nesta pasta).
// ============================================================
import { chromium } from 'playwright';
import { readFileSync, writeFileSync, readdirSync, mkdirSync } from 'fs';
import { createHash } from 'crypto';
import { fileURLToPath } from 'url';
import { dirname, join, resolve } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const RAIZ = resolve(__dirname, '..', '..');            // .
const BASE = process.env.UNI_BASE || 'http://localhost/vero';
const ROTEIROS = join(RAIZ, 'conteudo', 'roteiros');
const SAIDA = join(RAIZ, 'assets', 'uni', 'passos');    // web-served
const VP = { width: 1440, height: 900 };

const LOGINS = {
  admin:  { email: 'admin@veroagro.local', senha: process.env.CAPTURA_SENHA_ADMIN || 'change_me' },
  escola: { email: 'escola@vero.local',    senha: process.env.CAPTURA_SENHA_ESCOLA || 'change_me' },
};

const args = process.argv.slice(2); // 0, 1 ou vários slugs
mkdirSync(SAIDA, { recursive: true });

const arquivos = readdirSync(ROTEIROS).filter(f => f.endsWith('.json') && (!args.length || args.includes(f.replace(/\.json$/, ''))));
if (!arquivos.length) { console.error('Nenhum roteiro encontrado' + (arg ? ' para ' + arg : '')); process.exit(1); }

// injetado na página: mascara dados sensíveis (blur)
function maskInPage() {
  const RX = [/R\$\s?[\d.,]+/, /\d{3}\.\d{3}\.\d{3}-\d{2}/, /\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/];
  const cabecalhoSens = /respons|colaborador|\bnome\b|cliente|fornecedor|cpf|cnpj|e-?mail|telefone|contato/i;
  document.querySelectorAll('[data-uni-mask]').forEach(e => e.style.filter = 'blur(7px)');
  document.querySelectorAll('table').forEach(tb => {
    const ths = [...tb.querySelectorAll('thead th, tr:first-child th, tr:first-child td')];
    ths.forEach((th, i) => {
      if (cabecalhoSens.test(th.textContent || '')) {
        tb.querySelectorAll('tbody tr').forEach(tr => {
          const c = tr.children[i]; if (c && !c.hasAttribute('data-uni-nomask')) c.style.filter = 'blur(7px)';
        });
      }
    });
  });
  const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const alvos = [];
  while (walk.nextNode()) {
    const t = walk.currentNode.textContent || '';
    if (RX.some(rx => rx.test(t)) && walk.currentNode.parentElement) alvos.push(walk.currentNode.parentElement);
  }
  alvos.forEach(e => { if (!e.closest('[data-uni-nomask]')) e.style.filter = 'blur(6px)'; });
}

// injetado: desenha a marcação sobre o elemento-alvo (arg único — evaluate passa 1 só)
function annotate(opts) {
  const { box, numero, label, tipo } = opts;
  const { x, y, width, height } = box;
  document.getElementById('uni-annot')?.remove();
  const layer = document.createElement('div');
  layer.id = 'uni-annot';
  layer.style.cssText = 'position:fixed;inset:0;z-index:2147483647;pointer-events:none;font-family:system-ui,sans-serif';
  const C = '#E8590C';
  const hi = document.createElement('div');
  hi.style.cssText = `position:fixed;left:${x-6}px;top:${y-6}px;width:${width+12}px;height:${height+12}px;`
    + `border:3px solid ${C};border-radius:10px;box-shadow:0 0 0 3px rgba(232,89,12,.25), 0 0 0 9999px rgba(10,20,25,.12)`;
  layer.appendChild(hi);
  const bd = document.createElement('div');
  bd.textContent = numero;
  bd.style.cssText = `position:fixed;left:${x-20}px;top:${y-20}px;width:36px;height:36px;border-radius:50%;`
    + `background:${C};color:#fff;font-weight:700;font-size:19px;line-height:36px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.4)`;
  layer.appendChild(bd);
  if (tipo === 'seta') {
    const ar = document.createElement('div');
    ar.textContent = '➜';
    ar.style.cssText = `position:fixed;left:${x-46}px;top:${y+height/2-16}px;color:${C};font-size:34px;transform:rotate(-8deg);text-shadow:0 1px 3px rgba(0,0,0,.4)`;
    layer.appendChild(ar);
  }
  if (label) {
    const lb = document.createElement('div');
    lb.textContent = label;
    const top = Math.min(y + height + 10, window.innerHeight - 40);
    lb.style.cssText = `position:fixed;left:${x}px;top:${top}px;background:${C};color:#fff;font-weight:600;`
      + `font-size:13px;padding:5px 11px;border-radius:7px;box-shadow:0 2px 8px rgba(0,0,0,.35)`;
    layer.appendChild(lb);
  }
  document.body.appendChild(layer);
}

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: VP, deviceScaleFactor: 1 });
const page = await ctx.newPage();

async function gotoStable(url) {
  for (let i = 0; i < 3; i++) {
    await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(700);
    if (page.url().replace(/\/$/, '') === url.replace(/\/$/, '') || page.url().includes(url.split('/vero')[1] || '##')) break;
  }
  await page.waitForLoadState('networkidle').catch(() => {});
}

let logadoComo = null;
async function login(qual) {
  if (logadoComo === qual) return;
  const cred = LOGINS[qual] || LOGINS.admin;
  await gotoStable(`${BASE}/index.php`);
  await page.fill('input[name="email"]', cred.email);
  await page.fill('input[name="senha"]', cred.senha);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), page.click('#submitBtn')]);
  await page.waitForTimeout(1200);
  logadoComo = qual;
}

const resultado = [];
for (const arq of arquivos) {
  const roteiro = JSON.parse(readFileSync(join(ROTEIROS, arq), 'utf8'));
  await login(roteiro.login || 'admin');
  console.log(`\n== ${roteiro.slug} (${roteiro.passos.length} passos) ==`);

  for (let i = 0; i < roteiro.passos.length; i++) {
    const p = roteiro.passos[i];
    const ordem = i + 1;
    await gotoStable(`${BASE}${p.rota}`);
    for (const pre of (p.pre_acoes || [])) {
      await page.click(pre, { timeout: 4000 }).catch(() => {});
      await page.waitForLoadState('networkidle').catch(() => {});  // cobre navegação (href) e modal (JS)
      await page.waitForTimeout(500);
    }

    const arquivoPng = `${roteiro.slug}-${ordem}.png`;
    let ok = false, hash = null;
    try {
      const loc = page.locator(p.seletor).first();
      await loc.scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {});
      const box = await loc.boundingBox({ timeout: 3000 }).catch(() => null);
      await page.evaluate(maskInPage).catch(() => {});
      if (box) { await page.evaluate(annotate, { box, numero: String(ordem), label: p.label || '', tipo: p.marca || 'caixa' }); ok = true; }
      await page.waitForTimeout(200);
    } catch (e) { /* segue para capturar a tela mesmo assim */ }
    try { // SEMPRE captura a tela (com ou sem marca) — nunca deixa passo sem imagem
      const buf = await page.screenshot({ clip: { x: 0, y: 0, width: VP.width, height: VP.height } });
      writeFileSync(join(SAIDA, arquivoPng), buf);
      hash = createHash('sha256').update(buf).digest('hex');
    } catch (e) { console.log(`  ✗ passo ${ordem} (screenshot): ${e.message.split('\n')[0]}`); }
    console.log(`  ${ok ? '✓' : '⚠'} passo ${ordem}: ${p.seletor}${ok ? '' : ' (sem marca — alvo não encontrado)'}`);
    resultado.push({
      slug: roteiro.slug, ordem, texto: p.texto, rota: p.rota, seletor: p.seletor,
      marca: p.marca || 'caixa', label: p.label || null,
      imagem_url: `/assets/uni/passos/${arquivoPng}`, hash, ok,
    });
  }
}

writeFileSync(join(__dirname, 'passos_resultado.json'), JSON.stringify(resultado, null, 1));
await browser.close();
console.log(`\n== ${resultado.length} passo(s) capturado(s) → passos_resultado.json ==`);
