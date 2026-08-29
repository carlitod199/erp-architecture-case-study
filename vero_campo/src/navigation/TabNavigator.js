import React, { useState } from 'react';
import { View, Text, Pressable, Modal, StyleSheet } from 'react-native';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { cores, fonte, raio, espaco } from '../theme';
import {
  InicioStack, TarefasStack, RegistrarStack, TalhoesStack,
  AvisosStack, ChatStack, MaisStack,
} from './stacks';
import { useSync } from '../context/SyncContext';
import { useAuth } from '../context/AuthContext';
import { papelApp } from '../components/ui';
import Icone from '../components/Icone';

const Tab = createBottomTabNavigator();

// Atalhos do botão central (+) Registrar, adaptados ao papel (espec seção 1:
// os 5 destinos da barra são fixos; só os atalhos do (+) variam).
const ATALHOS_REGISTRAR = {
  operador: [
    { ic: 'microfone', t: 'Modo mãos-livres', s: 'Fale e o assistente executa', ir: ['RegistrarTab', 'Agente'] },
    { ic: 'microfone', t: 'Registrar por voz', s: 'Fale o que fez — vira apontamento', ir: ['RegistrarTab', 'Voz'] },
    { ic: 'irrigacao', t: 'Irrigação', s: 'Lâmina e fertirrigação do dia', ir: ['RegistrarTab', 'Irrigacao'] },
    { ic: 'abastecimento', t: 'Abastecer / horímetro', s: 'Escolha a máquina', ir: ['TalhoesTab', 'Maquinas'] },
  ],
  encarregado: [
    { ic: 'microfone', t: 'Modo mãos-livres', s: 'Fale e o assistente executa', ir: ['RegistrarTab', 'Agente'] },
    { ic: 'microfone', t: 'Registrar por voz', s: 'Fale o que fez — vira apontamento', ir: ['RegistrarTab', 'Voz'] },
    { ic: 'monitoramento', t: 'Monitorar (MIP)', s: 'Ronda de pragas por válvula', ir: ['RegistrarTab', 'Ronda'] },
    { ic: 'irrigacao', t: 'Irrigação', s: 'Lâmina e fertirrigação do dia', ir: ['RegistrarTab', 'Irrigacao'] },
    { ic: 'abastecimento', t: 'Abastecer / horímetro', s: 'Escolha a máquina', ir: ['TalhoesTab', 'Maquinas'] },
  ],
  monitor: [
    { ic: 'microfone', t: 'Modo mãos-livres', s: 'Fale e o assistente executa', ir: ['RegistrarTab', 'Agente'] },
    { ic: 'microfone', t: 'Registrar por voz', s: 'Fale o que fez — vira apontamento', ir: ['RegistrarTab', 'Voz'] },
    { ic: 'monitoramento', t: 'Monitorar (MIP)', s: 'Ronda de pragas por válvula', ir: ['RegistrarTab', 'Ronda'] },
    { ic: 'upload', t: 'Enviar ao líder', s: 'Resumo da ronda do dia', ir: ['RegistrarTab', 'EnviarLider'] },
  ],
};

function BarraCustom({ state, navigation }) {
  const { pendentes } = useSync();
  const { usuario } = useAuth();
  const [registrarAberto, setRegistrarAberto] = useState(false);
  const atalhos = ATALHOS_REGISTRAR[papelApp(usuario)] || ATALHOS_REGISTRAR.encarregado;

  // Os CINCO destinos fixos da espec: HOJE · TAREFAS · (+) · MAPA · MAIS
  const abas = [
    { name: 'MeuDia', label: 'Hoje', icone: 'dia' },
    { name: 'TarefasTab', label: 'Tarefas', icone: 'tarefas' },
    { name: '__fab' },
    { name: 'TalhoesTab', label: 'Mapa', icone: 'mapa' },
    { name: 'MaisTab', label: 'Mais', icone: 'mais' },
  ];

  function irAtalho(ir) {
    setRegistrarAberto(false);
    // params: {} LIMPA parâmetros de visitas anteriores — navegar p/ uma tela
    // já montada sem params REAPROVEITA os antigos (válvula/modo presos,
    // "tela antiga"); os atalhos sempre abrem a tela zerada
    navigation.navigate(ir[0], { screen: ir[1], params: { _novo: Date.now() } });
  }

  return (
    <View style={styles.bar}>
      {abas.map((aba) => {
        if (aba.name === '__fab') {
          return (
            <View key="fab" style={styles.fabWrap}>
              <Pressable
                style={styles.fab}
                onPress={() => setRegistrarAberto(true)}
                accessibilityLabel="Registrar"
              >
                <Text style={styles.fabPlus}>+</Text>
              </Pressable>
            </View>
          );
        }
        const idx = state.routes.findIndex((r) => r.name === aba.name);
        const focado = state.index === idx;
        const cor = focado ? cores.lime : 'rgba(255,255,255,0.6)';
        return (
          <Pressable
            key={aba.name}
            style={styles.tab}
            onPress={() => {
              // 23/07: tocar na aba SEMPRE volta à raiz da stack dela — sem
              // isso, uma tela empilhada (ex.: conclusão antiga de colheita)
              // fica "presa" quando o usuário volta pra aba
              const raiz = {
                MeuDia: 'Home', TarefasTab: 'Tarefas', TalhoesTab: 'Talhoes', MaisTab: 'Mais',
              }[aba.name];
              if (raiz) navigation.navigate(aba.name, { screen: raiz });
              else navigation.navigate(aba.name);
            }}
            accessibilityLabel={aba.label}
          >
            <Icone nome={aba.icone} cor={cor} />
            <Text style={[styles.tabLabel, { color: cor }]}>{aba.label}</Text>
            {/* fila pendente sinalizada na aba MAIS (onde vive a Sincronização) */}
            {aba.name === 'MaisTab' && pendentes > 0 && (
              <View style={styles.badge}>
                <Text style={styles.badgeTxt}>{pendentes}</Text>
              </View>
            )}
          </Pressable>
        );
      })}

      {/* Painel do (+) Registrar — atalhos por papel, alvo de toque ≥48px */}
      <Modal
        visible={registrarAberto}
        transparent
        animationType="fade"
        onRequestClose={() => setRegistrarAberto(false)}
      >
        <Pressable style={styles.veu} onPress={() => setRegistrarAberto(false)}>
          <Pressable style={styles.folha} onPress={() => {}}>
            <Text style={styles.folhaTitulo}>Registrar</Text>
            {atalhos.map((a) => (
              <Pressable
                key={a.t}
                style={({ pressed }) => [styles.opcao, pressed && { backgroundColor: cores.campo }]}
                onPress={() => irAtalho(a.ir)}
                accessibilityLabel={a.t}
              >
                <View style={styles.opcaoIc}><Icone nome={a.ic} tam={22} cor={cores.accent} /></View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.opcaoT}>{a.t}</Text>
                  <Text style={styles.opcaoS}>{a.s}</Text>
                </View>
                <Text style={styles.opcaoSeta}>›</Text>
              </Pressable>
            ))}
            <Pressable
              style={({ pressed }) => [styles.fechar, pressed && { opacity: 0.85 }]}
              onPress={() => setRegistrarAberto(false)}
              accessibilityLabel="Fechar"
            >
              <Text style={styles.fecharTxt}>Fechar</Text>
            </Pressable>
          </Pressable>
        </Pressable>
      </Modal>
    </View>
  );
}

export default function TabNavigator() {
  return (
    <Tab.Navigator
      screenOptions={{ headerShown: false }}
      tabBar={(props) => <BarraCustom {...props} />}
    >
      {/* Destinos da barra (espec): HOJE · TAREFAS · (+) · MAPA · MAIS */}
      <Tab.Screen name="MeuDia" component={InicioStack} />
      <Tab.Screen name="TarefasTab" component={TarefasStack} />
      <Tab.Screen name="RegistrarTab" component={RegistrarStack} />
      <Tab.Screen name="TalhoesTab" component={TalhoesStack} />
      <Tab.Screen name="MaisTab" component={MaisStack} />
      {/* Rotas legadas fora da barra — mantidas para não quebrar navegação
          por nome ('ChatTab'/'AvisosTab'); o conteúdo agora vive em MAIS. */}
      <Tab.Screen name="ChatTab" component={ChatStack} />
      <Tab.Screen name="AvisosTab" component={AvisosStack} />
    </Tab.Navigator>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row', alignItems: 'flex-start', paddingTop: 8, height: 74,
    backgroundColor: cores.sidebar,
  },
  tab: { flex: 1, alignItems: 'center', gap: 3, minHeight: espaco.toque },
  tabLabel: { fontSize: 10, fontFamily: fonte.sansSemi },
  fabWrap: { flex: 1, alignItems: 'center' },
  fab: {
    width: 56, height: 56, borderRadius: 18, marginTop: -20, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center', borderWidth: 3, borderColor: cores.sidebar,
    shadowColor: '#0B211D', shadowOpacity: 0.4, shadowRadius: 10, shadowOffset: { width: 0, height: 6 }, elevation: 6,
  },
  fabPlus: {
    color: cores.limeInk, fontSize: 32, lineHeight: 36, includeFontPadding: false,
    textAlign: 'center', textAlignVertical: 'center', marginTop: -2,
  },
  badge: {
    position: 'absolute', top: -2, right: 22, minWidth: 16, height: 16, borderRadius: 8,
    backgroundColor: cores.amber, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 3,
  },
  badgeTxt: { color: '#fff', fontSize: 9, fontFamily: fonte.sansBold },

  // painel Registrar
  veu: { flex: 1, backgroundColor: 'rgba(10,20,17,0.55)', justifyContent: 'flex-end' },
  folha: {
    backgroundColor: cores.surface, borderTopLeftRadius: raio.card + 6, borderTopRightRadius: raio.card + 6,
    paddingHorizontal: espaco.md, paddingTop: espaco.lg, paddingBottom: espaco.xl + 8, gap: 4,
  },
  folhaTitulo: { fontSize: 16, color: cores.ink, fontFamily: fonte.sansBold, marginBottom: 8, marginLeft: 4 },
  opcao: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    minHeight: espaco.toque + 8, paddingHorizontal: 10, borderRadius: raio.r,
  },
  opcaoIc: {
    width: 40, height: 40, borderRadius: raio.sm, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  opcaoT: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansSemi },
  opcaoS: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  opcaoSeta: { fontSize: 20, color: cores.faint },
  fechar: {
    minHeight: espaco.toque, borderRadius: raio.r, marginTop: 10,
    borderWidth: 1.5, borderColor: cores.border2, alignItems: 'center', justifyContent: 'center',
  },
  fecharTxt: { fontSize: 14, color: cores.muted, fontFamily: fonte.sansBold },
});
