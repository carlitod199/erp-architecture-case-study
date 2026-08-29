// Interpretação LOCAL e leve da fala transcrita (Onda 3 — registro por voz).
// Sem IA aqui: só palavra-chave para o TIPO de serviço e matching literal da
// VÁLVULA pelo nome/código dos itens sincronizados (useDadosSync('talhoes')).
// O operador sempre confere e corrige nos chips antes de usar.

const semAcento = (s) =>
  String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

// Tipos reconhecíveis por voz. poda/colheita/pulverizacao/adubacao existem no
// wizard de apontamento; irrigacao e desbrota são detectados e repassados —
// quem recebe o param decide o destino (irrigação tem tela própria).
export const TIPOS_VOZ = [
  { id: 'poda', rotulo: 'Poda', icone: '✂️', re: /\bpod(a|as|ar|ei|ou|ando|amos|aram)\b/ },
  { id: 'colheita', rotulo: 'Colheita', icone: '🧺', re: /\bcolh(eita|er|i|eu|endo|emos|eram|ido|ida|idas)\b/ },
  { id: 'pulverizacao', rotulo: 'Pulverização', icone: '💨', re: /\bpulveriz\w*|\baplic(acao|ar|uei|ou|ando|amos|aram)\b|\bdefensivo\b/ },
  { id: 'irrigacao', rotulo: 'Irrigação', icone: '💧', re: /\birrig\w*|\bmolh(ei|ou|ar|ando|amos)\b/ },
  { id: 'desbrota', rotulo: 'Desbrota', icone: '🌿', re: /\bdesbrot\w*/ },
  { id: 'adubacao', rotulo: 'Adubação', icone: '🌱', re: /\badub\w*|\bfertiliz\w*|\bnutric\w*/ },
];

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// "poda na válvula 5" → { id:'poda', … } | null (primeiro tipo que casar)
export function detectarTipo(texto) {
  const t = semAcento(texto);
  if (!t) return null;
  return TIPOS_VOZ.find((tp) => tp.re.test(t)) || null;
}

// Válvula citada no texto — devolve o ITEM do cache (ou null). Três passadas:
//  1) nome completo literal ("válvula 5a", "setor norte") — o mais longo vence
//  2) código citado como palavra isolada ("v12", "5a")
//  3) "válvula/setor/talhão 5" → pega só o número e procura nome/código com ele
export function detectarValvula(texto, itens) {
  const t = semAcento(texto);
  if (!t || !itens || itens.length === 0) return null;

  let melhor = null;
  for (const it of itens) {
    const nome = semAcento(it.nome);
    if (nome && t.includes(nome)) {
      if (!melhor || nome.length > semAcento(melhor.nome).length) melhor = it;
    }
  }
  if (melhor) return melhor;

  for (const it of itens) {
    const cod = semAcento(it.codigo != null ? String(it.codigo) : '');
    if (cod && new RegExp(`\\b${escapeRe(cod)}\\b`).test(t)) return it;
  }

  const m = t.match(/\b(?:valvula|setor|talhao)\s*(?:numero\s*)?(\d+\s*[a-z]?)\b/);
  if (m) {
    const num = m[1].replace(/\s+/g, '');
    const re = new RegExp(`\\b${escapeRe(num)}\\b`);
    return (
      itens.find((it) => re.test(semAcento(it.nome))) ||
      itens.find((it) => re.test(semAcento(it.codigo != null ? String(it.codigo) : ''))) ||
      null
    );
  }
  return null;
}

// Colheita por voz (23/07 — produção-só): "40 caixas premium, 3 perdidos,
// 12 de cat 2" → { classificacoes: {premium:40, perdidos:3, cat2:12},
// unidade: 'caixa'|'kg'|null }. Número por extenso não é tratado — o
// operador confere no formulário (os steppers ajustam rápido).
const CATS_VOZ = [
  { id: 'premium', re: /premium|primeira/ },
  { id: 'cat1', re: /\b(?:cat|categoria)\s*(?:1|um)\b/ },
  { id: 'cat2', re: /\b(?:cat|categoria)\s*(?:2|dois)\b/ },
  { id: 'cat3', re: /\b(?:cat|categoria)\s*(?:3|tres)\b/ },
  { id: 'perdidos', re: /perdid\w*|descarte|refugo/ },
];

export function detectarClassificacoes(texto) {
  const t = semAcento(texto);
  if (!t) return { classificacoes: {}, unidade: null };
  const unidade = /\bkg\b|\bquilos?\b/.test(t) ? 'kg'
    : /\bcaixas?\b|\bcx\b|\bcontentor(es)?\b/.test(t) ? 'caixa' : null;
  const classificacoes = {};
  // número seguido (até ~4 palavras) da categoria: "40 caixas premium",
  // "12 de cat 2", "3 perdidos"
  for (const c of CATS_VOZ) {
    // "40 caixas premium" (número → categoria)…
    const reNumAntes = new RegExp(
      `\\b(\\d+(?:[.,]\\d+)?)\\s*(?:caixas?|cx|kg|quilos?|contentor(?:es)?)?\\s*(?:de\\s+|da\\s+|na\\s+)?(?:${c.re.source})`
    );
    // …ou "premium 15 caixas" (categoria → número)
    const reNumDepois = new RegExp(
      `(?:${c.re.source})\\s*(?:com\\s+|de\\s+)?(\\d+(?:[.,]\\d+)?)`
    );
    const m = t.match(reNumAntes) || t.match(reNumDepois);
    if (m) {
      const n = Number(m[1].replace(',', '.'));
      if (Number.isFinite(n) && n > 0) classificacoes[c.id] = n;
    }
  }
  return { classificacoes, unidade };
}

export default { TIPOS_VOZ, detectarTipo, detectarValvula, detectarClassificacoes };
