import React, { useMemo } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { cores, fonte, raio, espaco } from '../../theme';
import { useDadosSync } from '../../hooks/useDadosSync';

// F3 — bloco "o sistema já sabe": contexto real da(s) válvula(s) escolhida(s)
// no wizard (variedade/área/nº plantas do módulo 'talhoes' + fase do módulo
// 'fenologia'). Cartão tracejado teal — sinaliza que nada disso precisa ser
// digitado pelo operador.
export default function ContextoValvula({ valvulas = [] }) {
  const { itens: fenologias } = useDadosSync('fenologia');

  const linhas = useMemo(() => (
    valvulas.map((v) => {
      const f = (fenologias || []).find(
        (x) => String(x.talhao_id) === String(v.talhao_id || v.id)
      );
      const partes = [
        v.variedade || v.cultura || null,
        v.area_ha ? `${String(v.area_ha).replace('.', ',')} ha` : null,
        v.num_plantas ? `${v.num_plantas} plantas` : null,
        f?.fase_nome ? `fase: ${f.fase_nome}` : null,
      ].filter(Boolean);
      return { id: v.id, nome: v.nome, detalhe: partes.join(' · ') };
    })
  ), [valvulas, fenologias]);

  if (linhas.length === 0) return null;

  return (
    <View style={styles.card}>
      <Text style={styles.tag}>✓ O SISTEMA JÁ SABE</Text>
      <View style={{ gap: 7 }}>
        {linhas.map((l) => (
          <View key={l.id}>
            <Text style={styles.nome}>{l.nome}</Text>
            {!!l.detalhe && <Text style={styles.detalhe}>{l.detalhe}</Text>}
          </View>
        ))}
      </View>
      <Text style={styles.rodape}>vinculado ao apontamento automaticamente</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderWidth: 1.5, borderStyle: 'dashed', borderColor: cores.accent,
    backgroundColor: cores.warm, borderRadius: raio.card, padding: espaco.md, gap: 8,
  },
  tag: {
    fontSize: 10.5, letterSpacing: 1, color: cores.accent,
    fontFamily: fonte.sansBold,
  },
  nome: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansBold },
  detalhe: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  rodape: { fontSize: 10.5, color: cores.olive, fontFamily: fonte.sansSemi },
});
