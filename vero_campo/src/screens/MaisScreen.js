import React, { useCallback, useState } from 'react';
import { View, Text, Pressable, StyleSheet, Alert } from 'react-native';
import { useNavigation, useFocusEffect } from '@react-navigation/native';
import biometria from '../services/biometria';
import AppHeader from '../components/AppHeader';
import { Tela, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';
import { useAuth } from '../context/AuthContext';
import { useSync } from '../context/SyncContext';
import { useDadosSync } from '../hooks/useDadosSync';
import { cores, fonte, raio, espaco } from '../theme';

// Aba MAIS (espec seção 1): tudo o que não é destino diário vive aqui —
// Sincronização, Alertas, Estoque (consulta), Copiloto, Ajuda e Perfil/sair.
export default function MaisScreen() {
  const nav = useNavigation();
  const { usuario, sair, codigoEmpresa, trocarEmpresa, pode } = useAuth();
  const podeContas = pode('financeiro.contas_pagar.ver') || pode('financeiro.contas_receber.ver');
  const podeCargas = pode('agro.romaneios_colheita.ver') || pode('agro.romaneios_colheita.editar');
  const podePosto = pode('packing.apontar.ver') || pode('packing.apontar.editar');
  const podeRecepcao = pode('packing.recepcao.ver') || pode('packing.recepcao.editar');
  const { pendentes, online } = useSync();
  const { itens: alertas } = useDadosSync('alertas');
  const alertasAbertos = alertas.filter((a) => a.status === 'aberto').length;

  const iniciais = (usuario?.nome || 'V C')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0].toUpperCase())
    .join('');

  function confirmarSaida() {
    Alert.alert(
      usuario?.nome || 'Sua conta',
      'Deseja sair do VERO Campo neste aparelho?\n\nRegistros pendentes na fila continuam guardados e serão enviados no próximo login.',
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Sair', style: 'destructive', onPress: () => { sair().catch(() => {}); } },
      ]
    );
  }

  // biometria (Onda 3C): ligar acontece no Login; aqui é o desligar
  const [bioAtiva, setBioAtiva] = useState(false);
  useFocusEffect(useCallback(() => {
    let ativo = true;
    biometria.lerPreferencia()
      .then((p) => { if (ativo) setBioAtiva(!!p?.ativa); })
      .catch(() => {});
    return () => { ativo = false; };
  }, []));

  function alternarBiometria() {
    if (!bioAtiva) {
      Alert.alert(
        'Biometria no login',
        'Para ativar, saia da conta e faça o login com a senha marcando "Entrar com biometria da próxima vez".'
      );
      return;
    }
    Alert.alert(
      'Desativar biometria',
      'A credencial guardada neste aparelho será apagada — o próximo login pedirá e-mail e senha.',
      [
        { text: 'Cancelar', style: 'cancel' },
        {
          text: 'Desativar', style: 'destructive',
          onPress: () => { biometria.desativar().then(() => setBioAtiva(false)).catch(() => {}); },
        },
      ]
    );
  }

  // trocar empresa: ÚNICO caminho que apaga o código da empresa (o "sair"
  // mantém o código — operador reentra só com a senha). Apaga token junto;
  // a navegação volta sozinha para a tela de código via RootNavigator.
  function confirmarTrocaEmpresa() {
    Alert.alert(
      'Trocar empresa',
      'Isso apaga o código da empresa, a sessão e TODOS os dados locais deste aparelho — inclusive registros pendentes ainda não enviados. Você vai precisar do código e da senha para entrar de novo.',
      [
        { text: 'Cancelar', style: 'cancel' },
        { text: 'Trocar', style: 'destructive', onPress: () => { trocarEmpresa().catch(() => {}); } },
      ]
    );
  }

  function abrirAjuda() {
    Alert.alert(
      'Ajuda',
      'Conteúdo de ajuda em construção.\n\nEm caso de dúvida, fale com o encarregado ou com o suporte VERO (dev@example.com).'
    );
  }

  const entradas = [
    {
      ic: 'sync', titulo: 'Sincronização', sub: online ? 'Conectado — fila e histórico de envio' : 'Sem sinal — registros aguardam na fila',
      badge: pendentes > 0 ? String(pendentes) : null, badgeCor: cores.amber,
      irPara: () => nav.navigate('Sincronizacao'),
    },
    {
      ic: 'alerta', titulo: 'Alertas', sub: 'Avisos de MIP, estoque e irrigação',
      badge: alertasAbertos > 0 ? String(alertasAbertos) : null, badgeCor: cores.danger,
      irPara: () => nav.navigate('Avisos'),
    },
    {
      ic: 'estoque', titulo: 'Estoque', sub: 'Consulta de saldo do almoxarifado',
      irPara: () => nav.navigate('Estoque'),
    },
    {
      ic: 'compras', titulo: 'Compras', sub: 'Solicitações, pedidos e recebimento',
      irPara: () => nav.navigate('Compras'),
    },
    ...(podeContas ? [{
      ic: 'caixa', titulo: 'Contas', sub: 'A pagar e a receber',
      irPara: () => nav.navigate('Contas'),
    }] : []),
    /* Packing (19/08): romaneio/recepção/posto saíram daqui — vivem agrupados
       no hub, que a Home destaca; a entrada única cobre quem chega pelo Mais. */
    ...(podeCargas || podePosto || podeRecepcao ? [{
      ic: 'colheita', titulo: 'Packing', sub: 'Romaneio, recepção e posto de caixas',
      irPara: () => nav.navigate('PackingHub'),
    }] : []),
    {
      ic: 'chat', titulo: 'Copiloto', sub: 'Pergunte sobre a fazenda e os registros',
      irPara: () => nav.navigate('Chat'),
    },
    {
      ic: 'biometria', titulo: 'Biometria no login',
      sub: bioAtiva ? 'Ativa neste aparelho — toque para desativar' : 'Desativada — ative na tela de login',
      badge: bioAtiva ? 'ON' : null, badgeCor: cores.pos,
      irPara: alternarBiometria,
    },
    {
      ic: 'inicio', titulo: 'Trocar empresa',
      sub: codigoEmpresa ? `Empresa atual: ${codigoEmpresa}` : 'Apaga o código e a sessão neste aparelho',
      irPara: confirmarTrocaEmpresa,
    },
    {
      ic: 'ajuda', titulo: 'Ajuda', sub: 'Como usar o VERO Campo',
      irPara: abrirAjuda,
    },
  ];

  return (
    <View style={{ flex: 1 }}>
      <AppHeader titulo="Mais" sub="Sincronização, consultas e conta" semVoltar />
      <Tela>
        <Eyebrow>Sua conta</Eyebrow>
        <View style={styles.perfilCard}>
          <View style={styles.avatar}>
            <Text style={styles.avatarTxt}>{iniciais}</Text>
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.nome}>{usuario?.nome || 'Usuário'}</Text>
            <Text style={styles.papel}>{rotuloPerfil(usuario?.perfil)}</Text>
          </View>
          <Pressable style={styles.btnSair} onPress={confirmarSaida} accessibilityLabel="Sair da conta">
            <Text style={styles.btnSairTxt}>Sair</Text>
          </Pressable>
        </View>

        <Eyebrow>Ferramentas</Eyebrow>
        <View style={styles.lista}>
          {entradas.map((e, i) => (
            <Pressable
              key={e.titulo}
              style={({ pressed }) => [
                styles.linha,
                i > 0 && styles.linhaDiv,
                pressed && { backgroundColor: cores.campo },
              ]}
              onPress={e.irPara}
              accessibilityLabel={e.titulo}
            >
              <View style={styles.linhaIc}><Icone nome={e.ic} tam={20} cor={cores.accent} /></View>
              <View style={{ flex: 1 }}>
                <Text style={styles.linhaTitulo}>{e.titulo}</Text>
                <Text style={styles.linhaSub}>{e.sub}</Text>
              </View>
              {!!e.badge && (
                <View style={[styles.badge, { backgroundColor: e.badgeCor }]}>
                  <Text style={styles.badgeTxt}>{e.badge}</Text>
                </View>
              )}
              <Text style={styles.seta}>›</Text>
            </Pressable>
          ))}
        </View>
      </Tela>
    </View>
  );
}

function rotuloPerfil(perfil) {
  const p = String(perfil || '').toLowerCase();
  if (p.includes('encarreg')) return 'Encarregado';
  if (p.includes('monitor')) return 'Monitor';
  if (p.includes('operador')) return 'Operador';
  if (p.includes('gestor')) return 'Gestor';
  if (p.includes('admin')) return 'Administrador';
  return 'Equipe de campo';
}

const styles = StyleSheet.create({
  perfilCard: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: raio.card, padding: espaco.md,
  },
  avatar: {
    width: 44, height: 44, borderRadius: 22, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center',
  },
  avatarTxt: { fontSize: 15, color: cores.limeInk, fontFamily: fonte.sansBold },
  nome: { fontSize: 15, color: cores.ink, fontFamily: fonte.sansBold },
  papel: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  btnSair: {
    minHeight: espaco.toque - 8, paddingHorizontal: 14, borderRadius: raio.r,
    borderWidth: 1.5, borderColor: cores.danger, alignItems: 'center', justifyContent: 'center',
  },
  btnSairTxt: { fontSize: 12.5, color: cores.danger, fontFamily: fonte.sansBold },

  lista: {
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: raio.card, overflow: 'hidden',
  },
  linha: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    minHeight: espaco.toque + 12, paddingHorizontal: espaco.md, paddingVertical: 10,
  },
  linhaDiv: { borderTopWidth: 1, borderTopColor: cores.border },
  linhaIc: {
    width: 38, height: 38, borderRadius: raio.sm, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  linhaTitulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansSemi },
  linhaSub: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  badge: {
    minWidth: 22, height: 22, borderRadius: 11, paddingHorizontal: 6,
    alignItems: 'center', justifyContent: 'center',
  },
  badgeTxt: { fontSize: 11, color: '#fff', fontFamily: fonte.sansBold },
  seta: { fontSize: 20, color: cores.faint, fontFamily: fonte.sansMed },
});
