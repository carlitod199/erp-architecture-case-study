import React, { useEffect, useRef } from 'react';
import { View, Animated, Easing, StyleSheet } from 'react-native';
import Svg, { Circle } from 'react-native-svg';
import { cores } from '../theme';

// Spinner padrão do SISTEMA (.vspin do vero-ui.css), agora também no app:
// anel fino na cor do contexto com 1/4 do topo aberto, girando em 0,6s
// linear. Substitui o emblema serrilhado antigo. Mesma API de antes:
//   tamanho: lado em px (padrão 44)
//   tom: 'claro' pinta de verde-petróleo p/ fundos claros (cards brancos);
//        'escuro' usa branco p/ fundos escuros
//   cor: sobrepõe o tom com uma cor específica (ex.: limeInk no botão do login)
export default function CarregandoVero({ tamanho = 44, tom = 'claro', cor, style }) {
  const giro = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    const anim = Animated.loop(
      Animated.timing(giro, {
        toValue: 1,
        duration: 600, // mesmo ritmo do .vspin da web
        easing: Easing.linear,
        useNativeDriver: true,
      })
    );
    anim.start();
    return () => anim.stop();
  }, []);

  const rotacao = giro.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] });
  const tint = cor || (tom === 'claro' ? cores.accent : '#ffffff');
  // proporção do original (2px num spinner de 13px), com piso de 2px
  const traco = Math.max(2, Math.round(tamanho * 0.09));
  const raio = (tamanho - traco) / 2;
  const circ = 2 * Math.PI * raio;

  return (
    <View style={[{ width: tamanho, height: tamanho }, styles.caixa, style]}>
      <Animated.View style={{ width: tamanho, height: tamanho, opacity: 0.9, transform: [{ rotate: rotacao }] }}>
        <Svg width={tamanho} height={tamanho} viewBox={`0 0 ${tamanho} ${tamanho}`}>
          <Circle
            cx={tamanho / 2}
            cy={tamanho / 2}
            r={raio}
            stroke={tint}
            strokeWidth={traco}
            fill="none"
            strokeDasharray={`${circ * 0.75} ${circ * 0.25}`}
          />
        </Svg>
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  caixa: { alignItems: 'center', justifyContent: 'center' },
});
