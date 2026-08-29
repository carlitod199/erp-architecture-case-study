import React, { useRef, useState, useEffect } from 'react';
import {
  View, Text, Pressable, Animated, Easing, TextInput, ActivityIndicator,
  ScrollView, RefreshControl, StyleSheet, AccessibilityInfo,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  cores, raio, fonte, espaco,
  // Onda 0 (semânticos/escala):
  tipo as tipos, elevacao, motion, interacao, acao, superficie, texto, estado,
} from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';

// =====================================================================
// VERO Campo — Componentes base (Onda 1). Telas COMPÕEM, não estilizam.
// Tudo consome tokens de theme.js (nada de hex/tamanho/raio soltos).
// Compatibilidade preservada: Tela, Cartao, Botao(tipo legado), Eyebrow,
// TituloSecao, Badge, papelApp mantêm assinatura anterior.
// =====================================================================

// ---- press-scale (motion.pressScale) reutilizável ----
function usePressScale() {
  const scale = useRef(new Animated.Value(1)).current;
  const bezier = Easing.bezier(...motion.curva.easeStandard);
  const animar = (para) => Animated.timing(scale, {
    toValue: para, duration: motion.duracao.press, easing: bezier, useNativeDriver: true,
  }).start();
  return { scale, aoEntrar: () => animar(motion.pressScale), aoSair: () => animar(1) };
}

// Envelope pressionável com press-scale + a11y (base de Botão/FAB).
export function Pressavel({ children, onPress, disabled, style, estilo, escala = true, accessibilityRole = 'button', ...rest }) {
  const { scale, aoEntrar, aoSair } = usePressScale();
  return (
    <Animated.View style={[escala && { transform: [{ scale }] }, style]}>
      <Pressable
        onPress={onPress}
        disabled={disabled}
        onPressIn={escala && !disabled ? aoEntrar : undefined}
        onPressOut={escala && !disabled ? aoSair : undefined}
        accessibilityRole={accessibilityRole}
        accessibilityState={{ disabled: !!disabled }}
        style={estilo}
        {...rest}
      >
        {children}
      </Pressable>
    </Animated.View>
  );
}

// safe=false por padrão: o AppHeader (topbar escura) já cobre a área da status bar.
// refrescar=true: TODA Tela rolável ganha "arrastar para atualizar" (sync completo).
export function Tela({ children, style, scroll = true, safe = false, refrescar = true }) {
  const Cont = safe ? SafeAreaView : View;
  const { refrescando, aoRefrescar } = useRefrescar();
  const inner = scroll ? (
    <ScrollView
      contentContainerStyle={[styles.body, style]}
      showsVerticalScrollIndicator={false}
      refreshControl={refrescar ? (
        <RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor={acao.primaria} colors={[acao.primaria]} />
      ) : undefined}
    >
      {children}
    </ScrollView>
  ) : (
    <View style={[styles.body, style]}>{children}</View>
  );
  return <Cont style={styles.tela} edges={['top']}>{inner}</Cont>;
}

// Card de superfície (elev.0 + hairline). `alerta`=cor da borda-esquerda de
// status; `destaque`=barra teal (ação); `onPress`=vira pressionável (press→warm).
export function Cartao({ children, style, plano, alerta, destaque, onPress, ...rest }) {
  const corAlerta = alerta === true ? estado.erro.texto : (typeof alerta === 'string' ? alerta : null);
  const base = [
    styles.card,
    plano && styles.cardPlano,
    destaque && styles.cardDestaque,
    corAlerta && { borderLeftWidth: 4, borderLeftColor: corAlerta },
    style,
  ];
  if (onPress) {
    return (
      <Pressable onPress={onPress} accessibilityRole="button"
        style={({ pressed }) => [...base, pressed && { backgroundColor: superficie.cartaoAlt }]} {...rest}>
        {children}
      </Pressable>
    );
  }
  return <View style={base} {...rest}>{children}</View>;
}

// Variantes semânticas de botão (bg/press/cor/borda) — todas via tokens.
const VARIANTES = {
  primaria:   { bg: acao.primaria,     bgPress: acao.primariaPress, cor: texto.inverso,     borda: null },
  secundaria: { bg: superficie.cartao, bgPress: superficie.campo,   cor: acao.primaria,     borda: acao.primaria },
  ghost:      { bg: 'transparent',     bgPress: superficie.campo,   cor: acao.primaria,     borda: null },
  perigo:     { bg: estado.erro.fundo, bgPress: estado.erro.fundo,  cor: estado.erro.texto, borda: null },
  ambar:      { bg: cores.amber,       bgPress: cores.amberDeep,    cor: texto.inverso,     borda: null },
};
// Compat com a API antiga (tipo=primario/fantasma/ambar).
const LEGADO = { primario: 'primaria', fantasma: 'ghost', ambar: 'ambar' };

export function Botao({ titulo, children, onPress, variante, tipo, tamanho = 'md', icone, carregando, disabled, style, estilo, ...rest }) {
  const nome = variante || LEGADO[tipo] || tipo || 'primaria';
  const v = VARIANTES[nome] || VARIANTES.primaria;
  const { scale, aoEntrar, aoSair } = usePressScale();
  const bloqueado = disabled || carregando;
  return (
    <Animated.View style={[{ transform: [{ scale }] }, style]}>
      <Pressable
        onPress={onPress}
        disabled={bloqueado}
        onPressIn={!bloqueado ? aoEntrar : undefined}
        onPressOut={!bloqueado ? aoSair : undefined}
        accessibilityRole="button"
        accessibilityState={{ disabled: !!bloqueado }}
        style={({ pressed }) => [
          styles.btn,
          tamanho === 'sm' && styles.btnSm,
          { backgroundColor: v.bg },
          v.borda && { borderWidth: 1.5, borderColor: v.borda },
          pressed && !bloqueado && { backgroundColor: v.bgPress },
          bloqueado && styles.btnOff,
          estilo,
        ]}
      >
        {carregando ? (
          <ActivityIndicator color={v.cor} />
        ) : (
          <View style={styles.btnConteudo}>
            {icone}
            <Text style={[styles.btnTxt, { color: v.cor }]}>{titulo ?? children}</Text>
          </View>
        )}
      </Pressable>
    </Animated.View>
  );
}

// Campo de texto com rótulo/erro/dica. Placeholder legível (texto.placeholder).
export function Input({ rotulo, erro, dica, style, estiloCampo, onFocus, onBlur, ...props }) {
  const [focado, setFocado] = useState(false);
  return (
    <View style={style}>
      {!!rotulo && <Text style={styles.inputRotulo}>{rotulo}</Text>}
      <TextInput
        style={[styles.input, focado && styles.inputFocado, !!erro && styles.inputErro, estiloCampo]}
        placeholderTextColor={texto.placeholder}
        onFocus={(e) => { setFocado(true); onFocus && onFocus(e); }}
        onBlur={(e) => { setFocado(false); onBlur && onBlur(e); }}
        {...props}
      />
      {!!erro && <Text style={styles.inputErroTxt}>{erro}</Text>}
      {!erro && !!dica && <Text style={styles.inputDica}>{dica}</Text>}
    </View>
  );
}

// Chip de filtro/toggle. tamanho='mini'(36) | 'toque'(44). Estado a11y selected.
export function Chip({ children, selecionado, onPress, tamanho = 'mini', style }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityState={{ selected: !!selecionado }}
      style={({ pressed }) => [
        styles.chip,
        tamanho === 'toque' && styles.chipToque,
        selecionado ? styles.chipOn : (pressed && styles.chipPress),
        style,
      ]}
    >
      <Text style={[styles.chipTxt, selecionado && styles.chipTxtOn]}>{children}</Text>
    </Pressable>
  );
}

export function Eyebrow({ children }) {
  return <Text style={styles.eyebrow}>{children}</Text>;
}

// Cabeçalho de seção (tipo.secao) com contador opcional (mono) e slot de ação.
export function Secao({ children, contador, acao: acaoNode, style }) {
  return (
    <View style={[styles.secaoWrap, style]}>
      <Text style={styles.secao}>{children}</Text>
      {contador != null && <Text style={styles.secaoContador}>{contador}</Text>}
      {!!acaoNode && <View style={{ marginLeft: 'auto' }}>{acaoNode}</View>}
    </View>
  );
}

// Compat: título de seção puro (sem contador).
export function TituloSecao({ children }) {
  return <Text style={styles.secao}>{children}</Text>;
}

export function Badge({ children, tipo = 'mut', bg, fg, style }) {
  const paleta = {
    crit: [estado.erro.fundo, estado.erro.textoDeep],
    at:   [estado.alerta.fundo, estado.alerta.textoDeep],
    ok:   [estado.sucesso.fundo, estado.sucesso.textoDeep],
    info: [estado.info.fundo, estado.info.texto],
    mut:  [cores.track, texto.terciario],
  }[tipo] || [cores.track, texto.terciario];
  return (
    <View style={[styles.badge, { backgroundColor: bg || paleta[0] }, style]}>
      <Text style={[styles.badgeTxt, { color: fg || paleta[1] }]}>{children}</Text>
    </View>
  );
}

// Linha densa de lista (divisória inferior hairline). Press → campo (sem scale).
// `alerta`=barra vermelha à esquerda; `direita`=nó custom no fim; senão valor/unidade.
export function Linha({ icone, titulo, sub, subCor, valor, valorCor, unidade, direita, alerta, onPress, style }) {
  const conteudo = (
    <>
      {alerta && <View style={styles.linhaMarca} />}
      {!!icone && <View style={styles.linhaIcone}>{icone}</View>}
      <View style={{ flex: 1 }}>
        <Text style={styles.linhaTitulo} numberOfLines={1}>{titulo}</Text>
        {!!sub && <Text style={[styles.linhaSub, subCor && { color: subCor }]} numberOfLines={1}>{sub}</Text>}
      </View>
      {direita != null ? direita : (valor != null && (
        <Text style={[styles.linhaValor, valorCor && { color: valorCor }]}>{valor}</Text>
      ))}
      {!!unidade && <Text style={styles.linhaUnidade}>{unidade}</Text>}
    </>
  );
  if (!onPress) return <View style={[styles.linha, style]}>{conteudo}</View>;
  return (
    <Pressable onPress={onPress} accessibilityRole="button"
      style={({ pressed }) => [styles.linha, pressed && { backgroundColor: superficie.campo }, style]}>
      {conteudo}
    </Pressable>
  );
}

// Placeholder de carregamento (opacidade pulsante; respeita reduce-motion).
export function Skeleton({ largura = '100%', altura = 14, raio: r = raio.sm, style }) {
  const op = useRef(new Animated.Value(0.45)).current;
  useEffect(() => {
    let anim;
    AccessibilityInfo.isReduceMotionEnabled().then((reduzir) => {
      if (reduzir) { op.setValue(0.6); return; }
      anim = Animated.loop(Animated.sequence([
        Animated.timing(op, { toValue: 1, duration: 700, easing: Easing.inOut(Easing.ease), useNativeDriver: true }),
        Animated.timing(op, { toValue: 0.45, duration: 700, easing: Easing.inOut(Easing.ease), useNativeDriver: true }),
      ]));
      anim.start();
    });
    return () => { if (anim) anim.stop(); };
  }, []);
  return <Animated.View style={[{ width: largura, height: altura, borderRadius: r, backgroundColor: cores.track, opacity: op }, style]} />;
}

// Papel efetivo do usuário no app (espec item 0 — papéis v1: Operador,
// Encarregado, Monitor). O ERP hoje manda perfis mais largos (gestor, admin,
// demo…): quem gere cai na barra completa do encarregado.
export function papelApp(usuario) {
  const p = String(usuario?.perfil || '').toLowerCase();
  if (p.includes('monitor')) return 'monitor';
  if (p.includes('operador') || p.includes('visualiz')) return 'operador';
  return 'encarregado'; // encarregado, gestor, admin, demo → barra completa
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: superficie.fundo },
  body: { padding: espaco.md, paddingBottom: espaco.rodapeLista, gap: espaco.md },

  // Card
  card: {
    backgroundColor: superficie.cartao, borderWidth: StyleSheet.hairlineWidth,
    borderColor: cores.border, borderRadius: raio.card, padding: espaco.lg, ...elevacao.n0,
  },
  cardPlano: { padding: 0, overflow: 'hidden' },
  cardDestaque: { borderLeftWidth: 4, borderLeftColor: acao.primaria },

  // Botão
  btn: { minHeight: 52, borderRadius: raio.r, alignItems: 'center', justifyContent: 'center', paddingHorizontal: espaco.lg },
  btnSm: { minHeight: 44 },
  btnOff: { backgroundColor: cores.faint, borderWidth: 0, opacity: interacao.disabledOpacidade },
  btnConteudo: { flexDirection: 'row', alignItems: 'center', gap: espaco.sm },
  btnTxt: { ...tipos.corpoForte, fontFamily: fonte.sansBold },

  // Input
  inputRotulo: { ...tipos.label, color: texto.secundario, marginBottom: espaco.xs },
  input: {
    minHeight: 46, borderRadius: raio.sm, backgroundColor: superficie.campo,
    borderWidth: 1, borderColor: 'transparent', paddingHorizontal: espaco.md,
    ...tipos.corpo, color: texto.primario,
  },
  inputFocado: { borderColor: acao.primaria, backgroundColor: superficie.cartao },
  inputErro: { borderColor: estado.erro.texto },
  inputErroTxt: { ...tipos.auxiliar, color: estado.erro.texto, marginTop: espaco.xs },
  inputDica: { ...tipos.auxiliar, color: texto.terciario, marginTop: espaco.xs },

  // Chip
  chip: {
    minHeight: 36, paddingHorizontal: espaco.md, borderRadius: raio.pill,
    alignItems: 'center', justifyContent: 'center', backgroundColor: superficie.campo,
  },
  chipToque: { minHeight: 44 },
  chipPress: { backgroundColor: cores.track },
  chipOn: { backgroundColor: acao.primaria },
  chipTxt: { ...tipos.label, color: texto.secundario },
  chipTxtOn: { color: texto.inverso },

  // Eyebrow / Seção
  eyebrow: { ...tipos.eyebrow, color: texto.terciario },
  secaoWrap: { flexDirection: 'row', alignItems: 'center', gap: espaco.sm },
  secao: { ...tipos.secao, color: texto.primario },
  secaoContador: { ...tipos.valor, color: texto.terciario },

  // Badge
  badge: { paddingHorizontal: espaco.sm, paddingVertical: 4, borderRadius: raio.pill, alignSelf: 'flex-start' },
  badgeTxt: { ...tipos.badge },

  // Linha de lista
  linha: {
    flexDirection: 'row', alignItems: 'center', gap: espaco.sm,
    paddingVertical: 9, paddingRight: 2,
    borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: cores.border,
  },
  linhaMarca: { width: 3, alignSelf: 'stretch', borderRadius: 2, backgroundColor: estado.erro.texto, marginRight: espaco.xs },
  linhaIcone: {
    width: 38, height: 38, borderRadius: raio.sm, backgroundColor: superficie.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  linhaTitulo: { ...tipos.corpoForte, color: texto.primario },
  linhaSub: { ...tipos.secundario, color: texto.secundario, marginTop: 1 },
  linhaValor: { ...tipos.valor, fontSize: 16, color: texto.primario, textAlign: 'right', minWidth: 52 },
  linhaUnidade: { ...tipos.secundario, color: texto.terciario, minWidth: 26 },
});

export default { Tela, Cartao, Botao, Input, Chip, Eyebrow, Secao, TituloSecao, Badge, Linha, Skeleton, Pressavel };
