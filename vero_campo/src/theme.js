// Design tokens do VERO Campo — espec Fase 2 (VERO_Arquitetura_Especificacao_App_2026-07-22)
// Paleta teal de campo: alto contraste, legível ao sol, operável com luva.
// REGRA: valores podem mudar, CHAVES não — todas as telas importam estes nomes.

export const cores = {
  // marca / estrutura
  accent: '#0F766E', // primária teal — ações primárias, destaques, marca
  accentDeep: '#115E59', // teal profundo (hover/pressionado, sombras de marca)
  accent3: '#0D9488', // teal médio de apoio
  olive: '#45A196', // tom de apoio suave (ícones secundários)
  sidebar: '#14312B', // escura — barras de título, tab bar, topbars

  // superfícies
  page: '#F4F6F5', // fundo geral (espec 3.1)
  surface: '#FFFFFF', // cartões
  warm: '#F7FAF8', // superfície alternativa suave
  campo: '#EEF3F1', // fundo de campo preenchido (inputs)

  // tinta
  ink: '#12211D',
  ink2: '#1E2E29',
  muted: '#5C6B66',
  muted2: '#71807A',
  faint: '#A3B0AB',

  // linhas
  border: '#DDE4E1',
  border2: '#D2DBD7',
  track: '#E7EDEA',

  // realce da marca sobre fundo escuro (tab ativa, FAB, avatar)
  lime: '#2DD4BF',
  limeNeon: '#5EEAD4', // reservado, uso pontual
  limeInk: '#0A3E36',

  // status (espec 3.1)
  pos: '#15803D', // sucesso — concluído/enviado
  posBg: '#DCEFE2',
  amber: '#B45309', // alerta — atenção (estoque, LMR)
  amberDeep: '#8A4507',
  amberBg: '#FBEBD7',
  danger: '#B91C1C', // bloqueio — crítico
  dangerBg: '#F9E2E0',
  info: '#1D4ED8', // informação — em execução
  infoBg: '#E0E9FB',

  // === Onda 0 (aditivo) — hexes semânticos p/ eliminar literais soltos das telas ===
  posDeep: '#0A5C53', // sucesso profundo — antes literal em EstoqueItem/Aplicacoes/Sincronizacao
  dangerDeep: '#8F2A20', // erro profundo — antes literal em EstoqueItem/Sincronizacao
  accentInk: '#00464E', // teal escuro — RefreshControl (Estoque/Sincronizacao/Irrigacao)
  faintInk: '#7C8A85', // faint escurecido p/ quando carrega texto (WCAG ≥ faint)
  placeholder: '#6E7C77', // texto de placeholder legível (≥4,5:1) — não usar faint/muted2
  overlay: 'rgba(10,20,17,0.50)', // véu de modal/sheet (TabNavigator/AplicacaoConfirmar)
  loginGrad1: '#0A5058', // gradiente de login (topo)
  loginGrad2: '#08262A', // gradiente de login (base)
  shadowInk: '#0B211D', // cor-base das sombras (elevação)
};

export const raio = { card: 14, r: 11, sm: 8, pill: 28, circulo: 999 };
//                                              ^ Onda 0 (aditivo): avatares, FAB, bolinhas de status

// toque: alvo mínimo de toque (espec 3.1 — ≥48px, operável com luva)
// NOTA: valores existentes (xs..xl) NÃO alterados — telas dependem deles.
// Onda 0 acrescenta só chaves novas (xxl, rodapeLista). Migração base-4 = ver `escala`.
export const espaco = { xs: 6, sm: 10, md: 14, lg: 18, xl: 24, toque: 48, xxl: 32, rodapeLista: 96 };

// Escala de espaçamento base-4 nomeada (Onda 0, aditivo) — alvo das próximas ondas.
// Separada de `espaco` p/ não mudar nenhum valor que as telas já consomem.
export const escala = { px2: 2, px4: 4, px8: 8, px12: 12, px16: 16, px20: 20, px24: 24, px32: 32 };

export const fonte = {
  sans: 'IBMPlexSans_400Regular',
  sansMed: 'IBMPlexSans_500Medium',
  sansSemi: 'IBMPlexSans_600SemiBold',
  sansBold: 'IBMPlexSans_700Bold',
  mono: 'IBMPlexMono_500Medium',
  monoSemi: 'IBMPlexMono_600SemiBold',
};

// severidade -> cor (MIP / alertas)
export const severidade = {
  info: cores.info,
  atencao: cores.amber,
  critico: cores.danger,
  baixa: cores.pos,
  media: cores.amber,
  alta: cores.danger,
};

// =====================================================================
// Onda 0 — extensões ADITIVAS. Nada acima foi alterado. As telas ainda
// importam cores/raio/espaco/fonte pelos mesmos nomes e valores.
// =====================================================================

// 5. Escala tipográfica (papéis fechados: tamanho + família + line-height + tracking).
// Objeto por papel, pronto p/ espalhar em StyleSheet: {...tipo.corpo}.
export const tipo = {
  display:    { fontFamily: fonte.monoSemi, fontSize: 44, lineHeight: 46, letterSpacing: -0.5 }, // número-âncora / KPI
  titulo:     { fontFamily: fonte.sansBold, fontSize: 20, lineHeight: 25, letterSpacing: -0.3 }, // título de tela/hero
  subtitulo:  { fontFamily: fonte.sansBold, fontSize: 17, lineHeight: 22, letterSpacing: -0.2 }, // card forte / saudação
  secao:      { fontFamily: fonte.sansBold, fontSize: 15, lineHeight: 20, letterSpacing: 0 },    // cabeçalho de seção
  corpo:      { fontFamily: fonte.sansMed,  fontSize: 15, lineHeight: 21, letterSpacing: 0 },    // corpo de leitura
  corpoForte: { fontFamily: fonte.sansSemi, fontSize: 15, lineHeight: 21, letterSpacing: 0 },    // nome em item de lista
  valor:      { fontFamily: fonte.monoSemi, fontSize: 15, lineHeight: 20, letterSpacing: 0 },    // qtd / horas / doses
  secundario: { fontFamily: fonte.sansMed,  fontSize: 13, lineHeight: 18, letterSpacing: 0 },    // metadados / sub de linha
  label:      { fontFamily: fonte.sansSemi, fontSize: 12, lineHeight: 16, letterSpacing: 0.2 },  // rótulo de campo
  eyebrow:    { fontFamily: fonte.sansBold, fontSize: 11, lineHeight: 14, letterSpacing: 1.0, textTransform: 'uppercase' }, // sobrescrito
  badge:      { fontFamily: fonte.sansBold, fontSize: 11, lineHeight: 14, letterSpacing: 0.2 },  // texto de badge/chip
  auxiliar:   { fontFamily: fonte.sansMed,  fontSize: 11, lineHeight: 15, letterSpacing: 0 },    // nota/disclaimer (piso 11px)
};

// 4. Elevação — props RN prontas (iOS shadow* + Android elevation). Espalhar: {...elevacao.n1}.
export const elevacao = {
  n0: { shadowColor: 'transparent', shadowOpacity: 0, shadowRadius: 0, shadowOffset: { width: 0, height: 0 }, elevation: 0 }, // card em superfície (usar borda hairline)
  n1: { shadowColor: cores.shadowInk, shadowOpacity: 0.06, shadowRadius: 8,  shadowOffset: { width: 0, height: 2 },  elevation: 2 },  // card destacado / sheet
  n2: { shadowColor: cores.shadowInk, shadowOpacity: 0.12, shadowRadius: 12, shadowOffset: { width: 0, height: 6 },  elevation: 6 },  // FAB / popover / snackbar
  n3: { shadowColor: cores.shadowInk, shadowOpacity: 0.18, shadowRadius: 20, shadowOffset: { width: 0, height: 10 }, elevation: 10 }, // modal / sheet ativo
};

// 8. Motion — durações (ms) + curvas (pontos de controle bézier p/ Easing.bezier no RN).
export const motion = {
  duracao: {
    press:   120, // feedback de toque
    rapido:  150, // chips, toggles, tooltips
    padrao:  220, // entradas de card, badges
    sheet:   300, // bottom sheets, seletores
    tela:    280, // transição de stack
    spinner: 1000, // carregamento (acelerar do 2000 atual)
  },
  // Pontos de controle p/ Easing.bezier(...pontos). (RN não usa strings CSS.)
  curva: {
    easeStandard:   [0.23, 1, 0.32, 1],  // ease-out forte (padrão de entrada)
    easeEmphasized: [0.32, 0.72, 0, 1],  // curva iOS de sheet
    easeOut:        [0.0, 0.0, 0.2, 1],
    easeInOut:      [0.4, 0.0, 0.2, 1],
    linear:         [0, 0, 1, 1],
  },
  pressScale: 0.97, // escala de press-in (substitui opacity)
  enterScale: 0.96, // entrar de scale(0.96)+opacity — nunca de 0
};

// Estados de interação (opacidades) — tokens p/ press/disabled.
export const interacao = {
  pressScale: motion.pressScale,
  pressOpacidade: 0.9,     // fallback quando não houver scale
  disabledOpacidade: 0.45, // conteúdo desabilitado
  veuOpacidade: 0.50,      // overlay de modal/sheet
};

// --- Camada semântica (aliases por cima dos primitivos; nada renomeado) ---

// 6.2 Ação
export const acao = {
  primaria: cores.accent,
  primariaPress: cores.accentDeep,
  apoio: cores.accent3,
  sobreEscuro: cores.lime, // FAB, tab ativa, avatar sobre chrome escuro
};

// 4. Superfícies (semântico)
export const superficie = {
  fundo: cores.page,
  cartao: cores.surface,
  cartaoAlt: cores.warm,
  campo: cores.campo,
  inversa: cores.sidebar,
  overlay: cores.overlay,
};

// 5/6.1 Texto (semântico)
export const texto = {
  primario: cores.ink,
  secundario: cores.muted,
  terciario: cores.muted2,   // usar só ≥12px
  placeholder: cores.placeholder,
  decorativo: cores.faint,   // só trilhos/divisórias
  inverso: '#FFFFFF',
};

// 6.3 Estados semânticos (texto/fundo, com variantes "deep")
export const estado = {
  sucesso: { texto: cores.pos,        textoDeep: cores.posDeep,    fundo: cores.posBg },
  alerta:  { texto: cores.amberDeep,  textoDeep: cores.amberDeep,  fundo: cores.amberBg },
  erro:    { texto: cores.danger,     textoDeep: cores.dangerDeep, fundo: cores.dangerBg },
  info:    { texto: cores.info,       textoDeep: cores.info,       fundo: cores.infoBg },
};

export default {
  cores, raio, espaco, fonte, severidade,
  // Onda 0 (aditivo):
  escala, tipo, elevacao, motion, interacao, acao, superficie, texto, estado,
};
