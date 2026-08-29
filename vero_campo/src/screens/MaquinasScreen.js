import React, { useState } from 'react';
import { View, Text, Pressable, FlatList, RefreshControl, Alert, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import Scanner from '../components/Scanner';
import Icone from '../components/Icone';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { cores, fonte, raio, espaco } from '../theme';
import { useRefrescar } from '../hooks/useRefrescar';
import { Cartao, Botao, Badge } from '../components/ui';

// Frota — dados reais do cache offline (GET /sync/maquinas):
// id, codigo, nome, tipo, marca, modelo, horimetro_atual, status, updated_at.

const BADGE = {
  ativa: { rotulo: 'em dia', tipo: 'ok' },
  manutencao: { rotulo: 'manutenção', tipo: 'at' },
  inativa: { rotulo: 'inativa', tipo: 'mut' },
};

const fmtHoras = (n) =>
  (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const ehTrator = (tipo) => String(tipo || '').toLowerCase().includes('trator');

export default function MaquinasScreen() {
  const nav = useNavigation();
  const { itens, carregado } = useDadosSync('maquinas');
  const { refrescando, aoRefrescar } = useRefrescar();
  const { sincronizando, sincronizarAgora } = useSync();
  const [scanner, setScanner] = useState(false);

  // 7.4: escanear a plaqueta (QR/código) abre o horímetro da máquina certa
  function aoLerCodigo(codigo) {
    setScanner(false);
    const m = (itens || []).find(
      (x) => String(x.codigo || '').toLowerCase() === codigo.toLowerCase()
        || String(x.id) === codigo
    );
    if (m) nav.navigate('Horimetro', { maquina: m });
    else Alert.alert('Código não encontrado', `Nenhuma máquina com o código "${codigo}" no aparelho. Sincronize e tente de novo.`);
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Máquinas" sub="Frota" />

      <Pressable style={styles.btnScan} onPress={() => setScanner(true)}>
        <Icone nome="camera" tam={18} cor={cores.lime} />
        <Text style={styles.btnScanTxt}>Escanear plaqueta da máquina</Text>
      </Pressable>
      <Scanner
        visivel={scanner}
        titulo="Aponte para o QR/código da máquina"
        aoLer={aoLerCodigo}
        aoFechar={() => setScanner(false)}
      />

      <FlatList
        data={itens || []}
        keyExtractor={(m) => String(m.id || m.client_uuid)}
        contentContainerStyle={styles.corpo}
        refreshControl={<RefreshControl refreshing={refrescando} onRefresh={aoRefrescar} tintColor="#00464e" colors={["#00464e"]} />}
        ListEmptyComponent={
          <View style={styles.vazioWrap}>
            <Text style={styles.vazio}>
              {carregado
                ? 'Nenhuma máquina no aparelho ainda — sincronize para baixar a frota.'
                : 'Carregando…'}
            </Text>
            {carregado && (
              <Botao
                titulo={sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
                onPress={sincronizarAgora}
                disabled={sincronizando}
              />
            )}
          </View>
        }
        renderItem={({ item: m }) => {
          const b = BADGE[m.status] || BADGE.inativa;
          const descr = [m.tipo, m.marca, m.modelo].filter(Boolean).join(' · ');
          return (
            <Cartao>
              <View style={styles.linha1}>
                {ehTrator(m.tipo)
                  ? <Icone nome="maquina" tam={22} cor={cores.muted} />
                  : <Text style={styles.ic}>⚙️</Text>}
                <View style={{ flex: 1 }}>
                  <Text style={styles.nome}>{m.nome}</Text>
                  <Text style={styles.proxima}>{descr || m.codigo || ''}</Text>
                </View>
                <Badge tipo={b.tipo}>{b.rotulo}</Badge>
              </View>

              <View style={styles.horimetroLinha}>
                <Text style={styles.horimetro}>{fmtHoras(m.horimetro_atual)}</Text>
                <Text style={styles.horas}>h</Text>
              </View>
              <Text style={styles.leitura}>horímetro atual{m.codigo ? ` · ${m.codigo}` : ''}</Text>

              <View style={styles.btnLinha}>
                <Botao
                  titulo="Registrar horímetro"
                  variante="secundaria"
                  tamanho="sm"
                  style={{ flex: 1 }}
                  onPress={() => nav.navigate('Horimetro', { maquina: m })}
                />
                <Botao
                  variante="secundaria"
                  tamanho="sm"
                  icone={<Icone nome="abastecimento" tam={20} cor={cores.accent} />}
                  style={{ width: 54 }}
                  onPress={() => nav.navigate('Abastecimento', { maquina: m })}
                />
              </View>
            </Cartao>
          );
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  linha1: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  ic: { fontSize: 21 },
  nome: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansBold },
  proxima: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  horimetroLinha: { flexDirection: 'row', alignItems: 'baseline', gap: 5, marginTop: 12 },
  horimetro: { fontSize: 30, color: cores.ink, fontFamily: fonte.monoSemi },
  horas: { fontSize: 14, color: cores.muted, fontFamily: fonte.sansSemi },
  leitura: { fontSize: 11, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 3 },
  btnScan: {
    flexDirection: 'row', gap: 8,
    marginHorizontal: espaco.md, marginTop: 10, height: 46, borderRadius: raio.r,
    backgroundColor: cores.sidebar, alignItems: 'center', justifyContent: 'center',
  },
  btnScanTxt: { fontSize: 13, color: cores.lime, fontFamily: fonte.sansBold },
  btnLinha: { flexDirection: 'row', gap: 8, marginTop: 12 },
  vazioWrap: { alignItems: 'center', marginTop: 40, gap: 14, paddingHorizontal: 20 },
  vazio: { textAlign: 'center', fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 19 },
});
