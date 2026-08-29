import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { cores, fonte, raio, espaco } from '../../theme';
import { Badge } from '../ui';

// Fila de áudios do registro por voz: o que ainda espera transcrição, o que
// já virou texto (pronto para "Usar na conclusão do serviço") e o que falhou de vez.

const quando = (iso) => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${dd}/${mm} às ${hh}:${mi}`;
};

export default function FilaVoz({ itens, online, processando, aoTranscrever, aoUsar, aoDescartar }) {
  if (!itens || itens.length === 0) return null;
  const nPendentes = itens.filter((i) => i.estado === 'pendente').length;

  return (
    <View style={{ gap: 10 }}>
      <View style={styles.cabecalho}>
        <Text style={styles.titulo}>Áudios na fila</Text>
        {nPendentes > 0 && (
          <Pressable
            style={[styles.btnTranscrever, (!online || processando) && { opacity: 0.45 }]}
            disabled={!online || processando}
            onPress={aoTranscrever}
            accessibilityLabel="Transcrever os áudios pendentes agora"
          >
            <Text style={styles.btnTranscreverTxt}>
              {processando ? 'Transcrevendo…' : online ? 'Transcrever agora' : 'Sem sinal'}
            </Text>
          </Pressable>
        )}
      </View>

      {itens.map((it) => (
        <View key={it.uuid} style={styles.card}>
          <View style={styles.linhaTopo}>
            <Text style={styles.quando}>🎙 Gravado {quando(it.criado_em)}</Text>
            {it.estado === 'pendente' && (
              <Badge tipo={online ? 'info' : 'at'}>
                {online ? 'aguardando transcrição' : 'na fila — transcreve com sinal'}
              </Badge>
            )}
            {it.estado === 'transcrito' && <Badge tipo="ok">texto pronto</Badge>}
            {it.estado === 'falha' && <Badge tipo="crit">falhou</Badge>}
          </View>

          {it.estado === 'transcrito' && !!it.texto && (
            <Text style={styles.texto}>“{it.texto}”</Text>
          )}
          {it.estado === 'pendente' && !!it.erro && (
            <Text style={styles.erro}>Última tentativa falhou: {it.erro}</Text>
          )}
          {it.estado === 'falha' && (
            <Text style={styles.erro}>{it.erro || 'Não deu para transcrever este áudio.'}</Text>
          )}

          <View style={styles.acoes}>
            {it.estado === 'transcrito' && (
              <Pressable style={styles.btnUsar} onPress={() => aoUsar(it)}>
                <Text style={styles.btnUsarTxt}>Usar na conclusão do serviço</Text>
              </Pressable>
            )}
            <Pressable style={styles.btnDescartar} onPress={() => aoDescartar(it)}>
              <Text style={styles.btnDescartarTxt}>Descartar</Text>
            </Pressable>
          </View>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  cabecalho: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10,
  },
  titulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  btnTranscrever: {
    minHeight: espaco.toque, paddingHorizontal: 14, borderRadius: raio.r,
    backgroundColor: cores.accent, alignItems: 'center', justifyContent: 'center',
  },
  btnTranscreverTxt: { fontSize: 12.5, color: cores.surface, fontFamily: fonte.sansBold },
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14, gap: 10 },
  linhaTopo: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' },
  quando: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansSemi },
  texto: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed, lineHeight: 19 },
  erro: { fontSize: 12, color: cores.danger, fontFamily: fonte.sansMed },
  acoes: { flexDirection: 'row', gap: 8 },
  btnUsar: {
    flex: 1, minHeight: espaco.toque, borderRadius: raio.r, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12,
  },
  btnUsarTxt: { fontSize: 13, color: cores.surface, fontFamily: fonte.sansBold },
  btnDescartar: {
    minHeight: espaco.toque, borderRadius: raio.r, backgroundColor: cores.dangerBg,
    alignItems: 'center', justifyContent: 'center', paddingHorizontal: 14,
  },
  btnDescartarTxt: { fontSize: 13, color: cores.danger, fontFamily: fonte.sansBold },
});
