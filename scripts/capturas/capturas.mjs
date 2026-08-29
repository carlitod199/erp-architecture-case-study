// ============================================================
// VERO — scripts/capturas/capturas.mjs  (pipeline T7, §12)
// Capturas automaticas (Playwright/Chromium) das telas ancoradas
// as capsulas da Universidade VERO.
//
// Fluxo:
//   1. Le rotas.json (gerado do banco da Universidade).
//   2. Faz login em /index.php mantendo a sessao (browser context).
//   3. Para cada rota: navega, mascara dados sensiveis, screenshot 1440x900 fullPage.
//   4. Compara com a captura anterior (sha256 + limiar de bytes) -> mudou.
//   5. Escreve resultado.json e promove saida/ -> anterior/ para a proxima rodada.
//
// Uso:
//   node capturas.mjs
//   BASE_URL=http://localhost/vero UNI_EMAIL=escola@vero.local UNI_SENHA=... node capturas.mjs
//
// Depois: php ../uni_capturas_aplicar.php  (aplica resultado.json no banco).
// ============================================================

import { chromium } from 'playwright';
import { createHash } from 'node:crypto';
import { readFile, writeFile, mkdir, readdir, copyFile, unlink, stat } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ---- Configuracao (tudo sobrescrivivel por env) ----
const CFG = {
  baseUrl:  (process.env.BASE_URL  || 'http://localhost/vero').replace(/\/+$/, ''),
  email:    process.env.UNI_EMAIL  || 'escola@vero.local',
  senha:    process.env.UNI_SENHA  || 'change_me',
  vwWidth:  parseInt(process.env.UNI_VW  || '1440', 10),
  vwHeight: parseInt(process.env.UNI_VH  || '900', 10),
  // limiar de mudanca: fracao de bytes que precisa diferir p/ marcar mudou=true
  // (amortece ruido pixel-a-pixel de telas dinamicas). Default 2%.
  limiar:   parseFloat(process.env.UNI_CAP_LIMIAR || '0.02'),
  timeout:  parseInt(process.env.UNI_TIMEOUT || '30000', 10),
  headless: process.env.UNI_HEADFUL ? false : true,
};

const dir = {
  base:     __dirname,
  rotas:    path.join(__dirname, 'rotas.json'),
  saida:    path.join(__dirname, 'saida'),
  anterior: path.join(__dirname, 'anterior'),
  resultado:path.join(__dirname, 'resultado.json'),
};

// project-relative prefix usado no resultado.json / DB (forward slashes)
const REL_PREFIX = 'scripts/capturas/anterior';

const log = (...a) => console.log(...a);

// ------------------------------------------------------------
// Mascaramento de dados sensiveis (roda no contexto da pagina,
// ANTES do screenshot). Heuristica — ver README (limitacoes).
// ------------------------------------------------------------
const MASK_CSS = `
  .uni-mask, [data-uni-mask]{ filter: blur(7px) !important; }
  [data-uni-nomask]{ filter: none !important; }
  /* estabiliza a captura: sem animacoes/transicoes/caret */
  *,*::before,*::after{ animation:none !important; transition:none !important; caret-color:transparent !important; }
`;

function maskInPage() {
  const MONEY = /R\$\s?\d/;
  const CPF   = /\d{3}\.\d{3}\.\d{3}-\d{2}/;
  const CNPJ  = /\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/;
  const HEADERS = [
    'responsável', 'responsavel', 'colaborador', 'nome', 'cliente',
    'fornecedor', 'cpf', 'cnpj', 'e-mail', 'email', 'telefone', 'contato',
  ];
  const marked = new Set();
  const mark = (el) => {
    if (!el || marked.has(el) || el.hasAttribute('data-uni-nomask')) return;
    el.classList.add('uni-mask');
    marked.add(el);
  };

  // 1) Colunas de tabela cujo cabecalho casa termos sensiveis.
  document.querySelectorAll('table').forEach((tbl) => {
    const heads = Array.from(tbl.querySelectorAll('thead th'));
    const scan  = heads.length ? heads : Array.from(tbl.querySelectorAll('tr:first-child th'));
    const idx = [];
    scan.forEach((th, i) => {
      const t = (th.textContent || '').trim().toLowerCase();
      if (HEADERS.some((term) => t.includes(term))) idx.push(i);
    });
    if (!idx.length) return;
    tbl.querySelectorAll('tbody tr').forEach((tr) => {
      const cells = tr.querySelectorAll('td');
      idx.forEach((i) => cells[i] && mark(cells[i]));
    });
  });

  // 2) Qualquer elemento-folha cujo texto pareca valor monetario / CPF / CNPJ.
  document.querySelectorAll('td, span, div, p, li, strong, b, a, dd').forEach((el) => {
    if (el.children.length > 3) return; // pula containers grandes
    const t = el.textContent || '';
    if (MONEY.test(t) || CPF.test(t) || CNPJ.test(t)) mark(el);
  });

  // 3) Opt-in explicito do front (futuro).
  document.querySelectorAll('[data-uni-mask]').forEach(mark);

  return marked.size;
}

// ------------------------------------------------------------
async function sha256File(p) {
  const buf = await readFile(p);
  return createHash('sha256').update(buf).digest('hex');
}

async function login(page) {
  const url = `${CFG.baseUrl}/index.php`;
  log(`-> login: ${url} (${CFG.email})`);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: CFG.timeout });
  await page.fill('input[name="email"]', CFG.email);
  await page.fill('input[name="senha"]', CFG.senha);
  // clica e aguarda a navegacao pos-submit terminar (evita "execution context destroyed")
  await page.click('#submitBtn');
  await page.waitForLoadState('load', { timeout: CFG.timeout }).catch(() => {});
  await page.waitForLoadState('networkidle', { timeout: CFG.timeout }).catch(() => {});
  await page.waitForTimeout(500);
  // heuristica de sucesso: nao ficou preso na tela de login
  const aindaNoLogin = await page.$('#loginForm').catch(() => null);
  const urlAtual = page.url();
  if (aindaNoLogin && /\/index\.php/.test(urlAtual)) {
    const aviso = await page.$eval('#notice', (n) => (n.textContent || '').trim()).catch(() => '');
    throw new Error(`Login falhou (segue em index.php). Aviso: "${aviso || 'sem mensagem'}"`);
  }
  // deixa o redirect pos-login (ex.: -> dashboard) assentar antes de navegar
  await page.waitForLoadState('networkidle', { timeout: CFG.timeout }).catch(() => {});
  await page.waitForTimeout(800);
  log(`   login ok -> ${page.url()}`);
}

// goto resiliente: algumas telas disparam um redirect client-side com atraso
// que "interrompe" a navegacao. Reissuamos ate a URL estabilizar no alvo.
async function gotoStable(page, alvo, tentativas = 3) {
  let ultimoErro;
  for (let i = 0; i < tentativas; i++) {
    try {
      await page.goto(alvo, { waitUntil: 'domcontentloaded', timeout: CFG.timeout });
      await page.waitForLoadState('load', { timeout: CFG.timeout }).catch(() => {});
      // deixa qualquer redirect diferido acontecer e reavalia
      await page.waitForTimeout(500);
      const url = page.url().split('#')[0];
      if (url === alvo || url.startsWith(alvo)) return; // chegou (ou o alvo redireciona p/ si)
      // se redirecionou p/ outro lugar, tenta de novo apontando ao alvo
    } catch (e) {
      ultimoErro = e;
      // navegacao interrompida por outra: aguarda assentar e retenta
      await page.waitForLoadState('load', { timeout: CFG.timeout }).catch(() => {});
      await page.waitForTimeout(400);
      continue;
    }
    // chegou noutro lugar sem erro: mais uma volta forcando o alvo
  }
  // ultima tentativa "dura": navega e segue mesmo que a URL final difira
  await page.goto(alvo, { waitUntil: 'domcontentloaded', timeout: CFG.timeout })
    .catch((e) => { if (ultimoErro) throw ultimoErro; throw e; });
}

async function capturar(page, item) {
  const alvo = `${CFG.baseUrl}${item.rota}`;
  const arquivo = path.join(dir.saida, `${item.slug}.png`);
  await gotoStable(page, alvo);
  await page.waitForLoadState('networkidle', { timeout: CFG.timeout }).catch(() => {});
  await page.waitForTimeout(400);

  await page.addStyleTag({ content: MASK_CSS });
  const nMasc = await page.evaluate(maskInPage);

  await page.screenshot({ path: arquivo, fullPage: true });
  const sha = await sha256File(arquivo);
  return { arquivo, sha, nMasc };
}

// Compara com a captura anterior. mudou=true quando:
//  - nao existe anterior (novidade), OU
//  - sha difere E a variacao de bytes >= limiar (amortece ruido).
async function comparar(slug, shaNovo, arquivoNovo) {
  const prev = path.join(dir.anterior, `${slug}.png`);
  if (!existsSync(prev)) return { mudou: true, motivo: 'novo' };
  const shaPrev = await sha256File(prev);
  if (shaPrev === shaNovo) return { mudou: false, motivo: 'identico' };
  const [a, b] = await Promise.all([stat(arquivoNovo), stat(prev)]);
  const ratio = Math.abs(a.size - b.size) / Math.max(b.size, 1);
  const mudou = ratio >= CFG.limiar;
  return { mudou, motivo: `sha!= ratio=${(ratio * 100).toFixed(2)}% (limiar ${(CFG.limiar * 100).toFixed(1)}%)` };
}

async function promover() {
  // move saida/*.png -> anterior/ (base da proxima rodada)
  const arqs = (await readdir(dir.saida)).filter((f) => f.endsWith('.png'));
  for (const f of arqs) {
    const src = path.join(dir.saida, f);
    const dst = path.join(dir.anterior, f);
    await copyFile(src, dst);
    await unlink(src);
  }
  return arqs.length;
}

async function main() {
  await mkdir(dir.saida, { recursive: true });
  await mkdir(dir.anterior, { recursive: true });

  if (!existsSync(dir.rotas)) {
    throw new Error(`rotas.json nao encontrado em ${dir.rotas}. Gere-o (ver README).`);
  }
  const rotas = JSON.parse(await readFile(dir.rotas, 'utf8'));
  if (!Array.isArray(rotas) || !rotas.length) {
    log('Nenhuma rota em rotas.json — nada a capturar.');
    return;
  }

  log(`== T7 capturas — ${rotas.length} rota(s) | base=${CFG.baseUrl} | vp ${CFG.vwWidth}x${CFG.vwHeight} ==`);

  const browser = await chromium.launch({ headless: CFG.headless });
  const context = await browser.newContext({
    viewport: { width: CFG.vwWidth, height: CFG.vwHeight },
    deviceScaleFactor: 1,
    locale: 'pt-BR',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(CFG.timeout);

  const resultado = [];
  try {
    await login(page);

    for (const item of rotas) {
      try {
        const { arquivo, sha, nMasc } = await capturar(page, item);
        const { mudou, motivo } = await comparar(item.slug, sha, arquivo);
        resultado.push({
          slug: item.slug,
          rota: item.rota,
          arquivo: `${REL_PREFIX}/${item.slug}.png`,
          sha256: sha,
          mudou,
        });
        log(`  ${mudou ? '!' : '='} ${item.slug} — ${item.rota} — masc:${nMasc} — ${motivo}`);
      } catch (e) {
        log(`  x ${item.slug} — ${item.rota} — ERRO: ${e.message}`);
        resultado.push({
          slug: item.slug,
          rota: item.rota,
          arquivo: null,
          sha256: null,
          mudou: false,
          erro: e.message,
        });
      }
    }
  } finally {
    await context.close();
    await browser.close();
  }

  await writeFile(dir.resultado, JSON.stringify(resultado, null, 2) + '\n', 'utf8');
  const promovidas = await promover();

  const ok = resultado.filter((r) => r.sha256).length;
  const mud = resultado.filter((r) => r.mudou).length;
  const err = resultado.filter((r) => r.erro).length;
  log(`== fim: ${ok} captura(s), ${mud} mudou, ${err} erro(s) | ${promovidas} promovida(s) p/ anterior/ ==`);
  log(`   resultado -> ${dir.resultado}`);
}

main().catch((e) => {
  console.error('FALHA:', e.message);
  process.exit(1);
});
