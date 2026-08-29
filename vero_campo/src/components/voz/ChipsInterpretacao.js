import React from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet } from 'react-native';
import { cores, fonte, raio, espaco } from '../../theme';
import { TIPOS_VOZ } from './interpretar';

// "O que entendi" — chips do tipo de serviço e da válvula detectados no texto
// transcrito. Tudo corrigível: tocar em outro chip troca; tocar no ativo
// desmarca (o apontamento segue sem pré-preenchimento daquele campo).

function Chip({ ativo, rotulo, icone, onPress }) {
  return (
    <Pressable
      style={[styles.chip, ativo && styles.chipAtivo]}
      onPress={onPress}
      accessibilityLabel={`${rotulo}${ativo ? ' (selecionado)' : ''}`}
    >
      {!!icone && <Text style={styles.chipIcone}>{icone}</Text>}
      <Text style={[styles.chipTxt, ativo && { color: cores.surface }]}>{rotulo}</Text>
    </Pressable>
  );
}

export default function ChipsInterpretacao({ tipo, aoTrocarTipo, valvula, aoTrocarValvula, valvulas }) {
  return (
    <View style={{ gap: 10 }}>
      <Text style={styles.eyebrow}>O que entendi — toque para corrigir</Text>

      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.linha}>
        {TIPOS_VOZ.map((t) => (
          <Chip
            key={t.id}
            ativo={tipo === t.id}
            rotulo={t.rotulo}
            icone={t.icone}
            onPress={() => aoTrocarTipo(tipo === t.id ? null : t.id)}
          />
        ))}
      </ScrollView>

      {valvulas.length > 0 ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.linha}>
          {valvulas.map((v) => (
            <Chip
              key={v.id}
              ativo={valvula?.id === v.id}
              rotulo={v.nome}
              icone="📍"
              onPress={() => aoTrocarValvula(valvula?.id === v.id ? null : v)}
            />
          ))}
        </ScrollView>
      ) : (
        <Text style={styles.dica}>Sem válvulas no aparelho — sincronize para o app reconhecer os locais.</Text>
      )}

      {!tipo && !valvula && (
        <Text style={styles.dica}>Não reconheci serviço nem local na fala — marque acima se quiser pré-preencher.</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  eyebrow: {
    fontSize: 10.5, letterSpacing: 1, textTransform: 'uppercase',
    color: cores.muted2, fontFamily: fonte.sansBold,
  },
  linha: { gap: 8, paddingRight: espaco.md },
  chip: {
    flexDirection: 'row', alignItems: 'center', gap: 6,
    minHeight: espaco.toque, paddingHorizontal: 14, paddingVertical: 10,
    borderRadius: raio.pill, backgroundColor: cores.campo,
  },
  chipAtivo: { backgroundColor: cores.accent },
  chipIcone: { fontSize: 14 },
  chipTxt: { fontSize: 13, color: cores.ink2, fontFamily: fonte.sansSemi },
  dica: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed },
});
