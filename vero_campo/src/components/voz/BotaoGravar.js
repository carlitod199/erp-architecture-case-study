import React, { useEffect, useRef } from 'react';
import { View, Text, Pressable, Animated, StyleSheet } from 'react-native';
import { cores, fonte, espaco } from '../../theme';

// Botão gigante de gravação (Onda 3). Toque LIGA / toque DESLIGA — mais
// robusto no campo que "manter pressionado" (a luva solta sem querer e o
// gesto quebra quando o dedo desliza). Alvo de 120px, bem acima do mínimo.

const fmt = (s) =>
  `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

export default function BotaoGravar({ gravando, enviando, duracao, onPress }) {
  const pulso = useRef(new Animated.Value(1)).current;

  // pulso suave do halo enquanto grava — indicador visual além da cor
  useEffect(() => {
    if (gravando) {
      const anim = Animated.loop(
        Animated.sequence([
          Animated.timing(pulso, { toValue: 1.12, duration: 620, useNativeDriver: true }),
          Animated.timing(pulso, { toValue: 1, duration: 620, useNativeDriver: true }),
        ])
      );
      anim.start();
      return () => anim.stop();
    }
    pulso.setValue(1);
    return undefined;
  }, [gravando, pulso]);

  return (
    <View style={styles.wrap}>
      <Animated.View
        style={[styles.halo, gravando && styles.haloGravando, { transform: [{ scale: pulso }] }]}
      >
        <Pressable
          style={({ pressed }) => [
            styles.botao,
            gravando && styles.botaoGravando,
            enviando && styles.botaoEnviando,
            pressed && { opacity: 0.85 },
          ]}
          onPress={onPress}
          disabled={enviando}
          accessibilityLabel={gravando ? 'Parar gravação' : 'Começar a gravar'}
        >
          <Text style={styles.icone}>{gravando ? '■' : '🎤'}</Text>
        </Pressable>
      </Animated.View>

      {gravando ? (
        <View style={styles.linhaGravando}>
          <View style={styles.pontoRec} />
          <Text style={styles.duracao}>{fmt(duracao)}</Text>
        </View>
      ) : (
        <Text style={styles.legenda}>
          {enviando ? 'transcrevendo o áudio…' : 'toque para gravar · toque de novo para parar'}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { alignItems: 'center', gap: 12, paddingVertical: espaco.sm },
  halo: {
    width: 148, height: 148, borderRadius: 74,
    backgroundColor: cores.track, alignItems: 'center', justifyContent: 'center',
  },
  haloGravando: { backgroundColor: cores.dangerBg },
  botao: {
    width: 120, height: 120, borderRadius: 60, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center',
  },
  botaoGravando: { backgroundColor: cores.danger },
  botaoEnviando: { backgroundColor: cores.muted },
  icone: { fontSize: 44, color: cores.surface },
  linhaGravando: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  pontoRec: { width: 10, height: 10, borderRadius: 5, backgroundColor: cores.danger },
  duracao: { fontSize: 22, color: cores.danger, fontFamily: fonte.monoSemi },
  legenda: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed, textAlign: 'center' },
});
