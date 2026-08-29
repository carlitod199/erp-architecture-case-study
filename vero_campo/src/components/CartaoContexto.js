import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { cores, fonte, raio } from '../theme';

// Cartão de Contexto da Válvula — o "motor de contexto" reutilizável (J-todas).
// linhas: [{ ic, k, v }]
export function CartaoContexto({ tag = 'CONTEXTO DE HOJE', titulo, linhas = [] }) {
  return (
    <LinearGradient colors={['#063d43', '#08262a']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.ctx}>
      <Text style={styles.tag}>{tag}</Text>
      <Text style={styles.titulo}>{titulo}</Text>
      <View style={{ marginTop: 10, gap: 8 }}>
        {linhas.map((l, i) => (
          <View key={i} style={styles.row}>
            <Text style={styles.ic}>{l.ic}</Text>
            <Text style={styles.k}>{l.k}</Text>
            <Text style={styles.v}>{l.v}</Text>
          </View>
        ))}
      </View>
    </LinearGradient>
  );
}

// Bússola de carência — selo verde/vermelho "pode colher?"
export function BussolaCarencia({ pode, texto, sub }) {
  return (
    <View style={[styles.car, pode ? styles.carOk : styles.carNo]}>
      <View style={[styles.big, { backgroundColor: pode ? cores.pos : cores.danger }]}>
        <Text style={styles.bigTxt}>{pode ? '✓' : '✕'}</Text>
      </View>
      <View style={{ flex: 1 }}>
        <Text style={[styles.carTxt, { color: pode ? '#0a5c53' : '#8f2a20' }]}>{texto}</Text>
        {!!sub && <Text style={styles.carSub}>{sub}</Text>}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  ctx: { borderRadius: raio.card, padding: 16, borderWidth: 1, borderColor: '#0c4a52' },
  tag: { fontSize: 11, color: '#a9c6c4', fontFamily: fonte.sansSemi, letterSpacing: 0.5 },
  titulo: { fontSize: 15, color: '#fff', fontFamily: fonte.sansBold, marginTop: 2 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 9 },
  ic: { width: 22, textAlign: 'center', fontSize: 14 },
  k: { color: '#9fbdbb', flex: 1, fontSize: 12.5, fontFamily: fonte.sansMed },
  v: { color: '#fff', fontSize: 12.5, fontFamily: fonte.sansBold },
  car: { flexDirection: 'row', alignItems: 'center', gap: 11, borderRadius: raio.r, padding: 12, borderWidth: 1 },
  carOk: { backgroundColor: cores.posBg, borderColor: '#b6ddd6' },
  carNo: { backgroundColor: cores.dangerBg, borderColor: '#e5c3bc' },
  big: { width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center' },
  bigTxt: { color: '#fff', fontSize: 19, fontFamily: fonte.sansBold },
  carTxt: { fontSize: 13, fontFamily: fonte.sansBold },
  carSub: { fontSize: 11.5, color: cores.muted, marginTop: 1, fontFamily: fonte.sansMed },
});

export default { CartaoContexto, BussolaCarencia };
