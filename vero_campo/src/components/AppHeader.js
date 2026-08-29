import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation } from '@react-navigation/native';
import { cores, fonte } from '../theme';
import { useSync } from '../context/SyncContext';

// Badge de sincronização SEMPRE visível no topo (espec 3.2): ⟳ com o nº de
// itens pendentes na fila; toque → tela de Sincronização (aba MAIS, funciona
// de qualquer stack via navegação aninhada). Corrige o badge escondido.
function BadgeSync() {
  const nav = useNavigation();
  const { pendentes, sincronizando } = useSync();
  const temFila = pendentes > 0;
  return (
    <Pressable
      style={[styles.sync, temFila && styles.syncFila]}
      onPress={() => nav.navigate('MaisTab', { screen: 'Sincronizacao' })}
      accessibilityLabel={
        temFila ? `Sincronização: ${pendentes} na fila` : 'Sincronização em dia'
      }
      hitSlop={6}
    >
      <Text style={[styles.syncIc, temFila && { color: '#fff' }]}>⟳</Text>
      {temFila && (
        <Text style={styles.syncNum}>{pendentes > 99 ? '99+' : pendentes}</Text>
      )}
      {!temFila && sincronizando && <View style={styles.syncPonto} />}
    </Pressable>
  );
}

// Topbar padrão do app — escura (mesma estrutura da Home), cobre a status bar.
export default function AppHeader({ titulo, sub, semVoltar, direita }) {
  const nav = useNavigation();
  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.bar}>
        {!semVoltar && (
          <Pressable style={styles.back} onPress={() => nav.goBack()} accessibilityLabel="Voltar" hitSlop={7}>
            <Text style={styles.backTxt}>‹</Text>
          </Pressable>
        )}
        <View style={{ flex: 1 }}>
          <Text style={styles.titulo}>{titulo}</Text>
          {!!sub && <Text style={styles.sub}>{sub}</Text>}
        </View>
        {direita}
        <BadgeSync />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { backgroundColor: cores.sidebar },
  bar: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    paddingHorizontal: 16, paddingVertical: 12,
    backgroundColor: cores.sidebar,
  },
  back: {
    width: 34, height: 34, borderRadius: 9,
    backgroundColor: 'rgba(255,255,255,0.12)', alignItems: 'center', justifyContent: 'center',
  },
  backTxt: { fontSize: 20, color: '#fff', marginTop: -2 },
  titulo: { fontSize: 16, color: '#fff', fontFamily: fonte.sansBold },
  sub: { fontSize: 11.5, color: 'rgba(255,255,255,0.6)', fontFamily: fonte.sansMed, marginTop: 1 },

  // badge ⟳ — alvo efetivo ≥48px (36 + hitSlop 6)
  sync: {
    minWidth: 36, height: 36, borderRadius: 10, paddingHorizontal: 8,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 4,
    backgroundColor: 'rgba(255,255,255,0.12)',
  },
  syncFila: { backgroundColor: cores.amber },
  syncIc: { fontSize: 17, color: 'rgba(255,255,255,0.85)', marginTop: -1 },
  syncNum: { fontSize: 12.5, color: '#fff', fontFamily: fonte.sansBold },
  syncPonto: {
    position: 'absolute', top: 5, right: 5, width: 6, height: 6, borderRadius: 3,
    backgroundColor: cores.lime,
  },
});
