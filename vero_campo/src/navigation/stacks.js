import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';

import HomeScreen from '../screens/HomeScreen';
import AlertasScreen from '../screens/AlertasScreen';
import AlertaDetalheScreen from '../screens/AlertaDetalheScreen';
import MonitoramentosRecebidosScreen from '../screens/MonitoramentosRecebidosScreen';

import TarefasScreen from '../screens/TarefasScreen';
import TarefaDetalheScreen from '../screens/TarefaDetalheScreen';
import AplicacoesScreen from '../screens/AplicacoesScreen';
import AplicacaoConfirmarScreen from '../screens/AplicacaoConfirmarScreen';
import AplicacaoAssinaturaScreen from '../screens/AplicacaoAssinaturaScreen';

import ApontamentoScreen from '../screens/ApontamentoScreen';
import RondaScreen from '../screens/RondaScreen';
import MonitoramentoScreen from '../screens/MonitoramentoScreen';
import EnviarLiderScreen from '../screens/EnviarLiderScreen';
import IrrigacaoScreen from '../screens/IrrigacaoScreen';
import ReceberScreen from '../screens/ReceberScreen';
import VozScreen from '../screens/VozScreen';
import AgenteScreen from '../screens/AgenteScreen';
import CalculadoraScreen from '../screens/CalculadoraScreen';

import TalhoesScreen from '../screens/TalhoesScreen';
import TalhaoScreen from '../screens/TalhaoScreen';
import EstoqueScreen from '../screens/EstoqueScreen';
import EstoqueItemScreen from '../screens/EstoqueItemScreen';
import ComprasScreen from '../screens/ComprasScreen';
import ContasScreen from '../screens/ContasScreen';
import CargasColheitaScreen from '../screens/CargasColheitaScreen';
import PackingHubScreen from '../screens/PackingHubScreen';
import PackingApontarScreen from '../screens/PackingApontarScreen';
import PackingRecepcaoScreen from '../screens/PackingRecepcaoScreen';
import NovaSolicitacaoScreen from '../screens/NovaSolicitacaoScreen';
import ReceberPedidoScreen from '../screens/ReceberPedidoScreen';
import MaquinasScreen from '../screens/MaquinasScreen';
import HorimetroScreen from '../screens/HorimetroScreen';
import AbastecimentoScreen from '../screens/AbastecimentoScreen';

import SincronizacaoScreen from '../screens/SincronizacaoScreen';
import ChatScreen from '../screens/ChatScreen';
import MaisScreen from '../screens/MaisScreen';

const semHeader = { headerShown: false };

export function InicioStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Home" component={HomeScreen} />
      <S.Screen name="Alertas" component={AlertasScreen} />
      <S.Screen name="AlertaDetalhe" component={AlertaDetalheScreen} />
      <S.Screen name="MonitoramentosRecebidos" component={MonitoramentosRecebidosScreen} />
      <S.Screen name="Talhao" component={TalhaoScreen} />
    </S.Navigator>
  );
}

export function TarefasStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Tarefas" component={TarefasScreen} />
      <S.Screen name="TarefaDetalhe" component={TarefaDetalheScreen} />
      <S.Screen name="Aplicacoes" component={AplicacoesScreen} />
      <S.Screen name="AplicacaoConfirmar" component={AplicacaoConfirmarScreen} />
      <S.Screen name="AplicacaoAssinatura" component={AplicacaoAssinaturaScreen} />
      <S.Screen name="Apontamento" component={ApontamentoScreen} />
    </S.Navigator>
  );
}

export function RegistrarStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Apontamento" component={ApontamentoScreen} />
      <S.Screen name="Ronda" component={RondaScreen} />
      <S.Screen name="Monitoramento" component={MonitoramentoScreen} />
      <S.Screen name="EnviarLider" component={EnviarLiderScreen} />
      <S.Screen name="Irrigacao" component={IrrigacaoScreen} />
      <S.Screen name="Receber" component={ReceberScreen} />
      <S.Screen name="Voz" component={VozScreen} />
      <S.Screen name="Agente" component={AgenteScreen} />
      <S.Screen name="Calculadora" component={CalculadoraScreen} />
    </S.Navigator>
  );
}

export function TalhoesStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Talhoes" component={TalhoesScreen} />
      <S.Screen name="Talhao" component={TalhaoScreen} />
      <S.Screen name="Estoque" component={EstoqueScreen} />
      <S.Screen name="EstoqueItem" component={EstoqueItemScreen} />
      <S.Screen name="Compras" component={ComprasScreen} />
      <S.Screen name="NovaSolicitacao" component={NovaSolicitacaoScreen} />
      <S.Screen name="ReceberPedido" component={ReceberPedidoScreen} />
      <S.Screen name="Maquinas" component={MaquinasScreen} />
      <S.Screen name="Horimetro" component={HorimetroScreen} />
      <S.Screen name="Abastecimento" component={AbastecimentoScreen} />
    </S.Navigator>
  );
}

export function ChatStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Chat" component={ChatScreen} />
    </S.Navigator>
  );
}

export function AvisosStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Avisos" component={AlertasScreen} />
      <S.Screen name="AlertaDetalhe" component={AlertaDetalheScreen} />
      <S.Screen name="Sincronizacao" component={SincronizacaoScreen} />
      <S.Screen name="Talhao" component={TalhaoScreen} />
    </S.Navigator>
  );
}

// Aba MAIS (espec seção 1): Sincronização · Alertas · Estoque(consulta) ·
// Copiloto · Ajuda · Perfil. Duplica rotas de consulta para que os navigate()
// por nome resolvam dentro da própria aba (padrão já usado em Talhao/Apontamento).
export function MaisStack() {
  const S = createNativeStackNavigator();
  return (
    <S.Navigator screenOptions={semHeader}>
      <S.Screen name="Mais" component={MaisScreen} />
      <S.Screen name="Sincronizacao" component={SincronizacaoScreen} />
      <S.Screen name="Avisos" component={AlertasScreen} />
      <S.Screen name="AlertaDetalhe" component={AlertaDetalheScreen} />
      <S.Screen name="Estoque" component={EstoqueScreen} />
      <S.Screen name="EstoqueItem" component={EstoqueItemScreen} />
      <S.Screen name="Compras" component={ComprasScreen} />
      <S.Screen name="Contas" component={ContasScreen} />
      <S.Screen name="Cargas" component={CargasColheitaScreen} />
      <S.Screen name="PackingHub" component={PackingHubScreen} />
      <S.Screen name="PackingApontar" component={PackingApontarScreen} />
      <S.Screen name="PackingRecepcao" component={PackingRecepcaoScreen} />
      <S.Screen name="NovaSolicitacao" component={NovaSolicitacaoScreen} />
      <S.Screen name="ReceberPedido" component={ReceberPedidoScreen} />
      <S.Screen name="Chat" component={ChatScreen} />
      <S.Screen name="Talhao" component={TalhaoScreen} />
    </S.Navigator>
  );
}
