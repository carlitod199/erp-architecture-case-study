import React, { useMemo } from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet, Alert } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useNavigation } from '@react-navigation/native';
import Svg, { Path } from 'react-native-svg';
import { Eyebrow, papelApp } from '../components/ui';
import Icone from '../components/Icone';
import CartaoClima from '../components/CartaoClima';
import { useSync } from '../context/SyncContext';
import { cores, fonte, raio, espaco, severidade } from '../theme';
import { useAuth } from '../context/AuthContext';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSafraAtiva, rotuloSafra } from '../hooks/useSafraAtiva';

// HOJE (espec seção 1): painel do dia — alertas · tarefas/OS · clima · sync ·
// KPIs do dia · atalhos por papel. Tudo lido dos caches offline existentes.

// tipo do serviço → { ic: nome no set de Icone, lbl: rótulo }
const TIPO_SERVICO = {
  poda: { ic: 'poda', lbl: 'Poda' },
  pulverizacao: { ic: 'aplicacao', lbl: 'Pulverização' },
  irrigacao: { ic: 'irrigacao', lbl: 'Irrigação' },
  colheita: { ic: 'uva', lbl: 'Colheita' },
  adubacao: { ic: 'adubacao', lbl: 'Adubação' },
  outro: { ic: 'servico', lbl: 'Serviço' },
};

// Registro rápido adaptado ao papel (espec: Home e barra adaptativas).
// 'colheita' (23/07, produção-só): abre SEMPRE o registro de produção
// (válvula → classificações → tela Colheita do sistema).
const ATALHOS_PAPEL = {
  operador: [
    { n: 'Colheita', ic: 'colheita', especial: 'colheita' },
    { n: 'Irrigação', ic: 'irrigacao', ir: ['RegistrarTab', 'Irrigacao'] },
    { n: 'Abastecer', ic: 'abastecimento', ir: ['TalhoesTab', 'Maquinas'] },
  ],
  encarregado: [
    { n: 'Colheita', ic: 'colheita', especial: 'colheita' },
    { n: 'Monitorar', ic: 'monitoramento', ir: ['RegistrarTab', 'Ronda'] },
    { n: 'Irrigação', ic: 'irrigacao', ir: ['RegistrarTab', 'Irrigacao'] },
    { n: 'Aplicações', ic: 'aplicacao', ir: ['TarefasTab', 'Aplicacoes'] },
  ],
  monitor: [
    { n: 'Monitorar', ic: 'monitoramento', ir: ['RegistrarTab', 'Ronda'] },
    { n: 'Enviar ao líder', ic: 'upload', ir: ['RegistrarTab', 'EnviarLider'] },
  ],
};

// "Contas" (financeiro) entra no lugar da Calculadora, mas só para quem tem
// permissão — montado dentro do componente (ver `consultar`).
const CONSULTAR_BASE = [
  { n: 'Estoque', ic: 'estoque', ir: ['MaisTab', 'Estoque'] },
  { n: 'Máquinas', ic: 'maquina', ir: ['TalhoesTab', 'Maquinas'] },
  { n: 'Análises', ic: 'analises', ir: ['MeuDia', 'MonitoramentosRecebidos'] },
];

// cor por ação — badge colorido deixa os atalhos "mais visuais" (cada ação
// com sua cor) e legíveis de relance, sem virar poluição.
const COR_ATALHO = {
  irrigacao:     { fg: '#1D4ED8', bg: '#E4EBFB' },
  colheita:      { fg: '#B45309', bg: '#FBEDDA' },
  abastecimento: { fg: '#475569', bg: '#E8ECF1' },
  monitoramento: { fg: '#0F766E', bg: '#DBEBE8' },
  aplicacao:     { fg: '#15803D', bg: '#DDF0E3' },
  upload:        { fg: '#7C3AED', bg: '#ECE5FB' },
  estoque:       { fg: '#0F766E', bg: '#DBEBE8' },
  maquina:       { fg: '#475569', bg: '#E8ECF1' },
  calculadora:   { fg: '#B45309', bg: '#FBEDDA' },
  caixa:         { fg: '#0F766E', bg: '#DBEBE8' },
  analises:      { fg: '#4F46E5', bg: '#E7E6FB' },
  poda:          { fg: '#0891B2', bg: '#DCF0F3' },
  uva:           { fg: '#7C3AED', bg: '#ECE5FB' },
  adubacao:      { fg: '#15803D', bg: '#DDF0E3' },
  servico:       { fg: '#475569', bg: '#E8ECF1' },
};
const corAtalho = (ic) => COR_ATALHO[ic] || { fg: cores.accent, bg: cores.campo };

// severidade do alerta → cor + ícone (badge)
const SEV = {
  critico: { cor: '#B91C1C', bg: '#F7E1DF', ic: 'aviso',  lbl: 'Crítico' },
  atencao: { cor: '#B45309', bg: '#FBEDDA', ic: 'alerta', lbl: 'Atenção' },
  info:    { cor: '#1D4ED8', bg: '#E4EBFB', ic: 'alerta', lbl: 'Informativo' },
};
const sevDe = (s) => SEV[s] || SEV.info;

function saudacao() {
  const h = new Date().getHours();
  if (h < 12) return 'Bom dia';
  if (h < 18) return 'Boa tarde';
  return 'Boa noite';
}

export default function HomeScreen() {
  const nav = useNavigation();
  const { usuario, sair, pode } = useAuth();
  // Consultar: "Contas" (a pagar/receber) no lugar da Calculadora, só p/ quem
  // tem permissão de financeiro; entra na posição da antiga calculadora.
  const podeContas = pode('financeiro.contas_pagar.ver') || pode('financeiro.contas_receber.ver');
  const consultar = podeContas
    ? [
      CONSULTAR_BASE[0], CONSULTAR_BASE[1],
      { n: 'Contas', ic: 'caixa', ir: ['MaisTab', 'Contas'] },
      CONSULTAR_BASE[2],
    ]
    : CONSULTAR_BASE;
  const { online, pendentes, falhas, sincronizando } = useSync();
  // caches offline reais — os mesmos que alimentam Tarefas e Alertas
  const { itens: alertas } = useDadosSync('alertas');
  const { itens: servicos } = useDadosSync('apontamentos_abertos');
  const safra = useSafraAtiva();

  const papel = papelApp(usuario);
  // "Packing" substitui o antigo tile "Cargas": agrupa romaneio
  // de carga, recepção e posto de caixas no hub. Aparece para quem tem QUALQUER
  // uma das permissões (o hub filtra por entrada); sem nenhuma, o atalho sai.
  // O registro de colheita segue acessível pela aba Registrar.
  const podePacking = pode('agro.romaneios_colheita.ver') || pode('agro.romaneios_colheita.editar')
    || pode('packing.apontar.ver') || pode('packing.apontar.editar')
    || pode('packing.recepcao.ver') || pode('packing.recepcao.editar');
  const atalhos = (ATALHOS_PAPEL[papel] || ATALHOS_PAPEL.encarregado)
    .map((s) => (
      s.especial === 'colheita'
        ? (podePacking ? { n: 'Packing', ic: 'colheita', ir: ['MaisTab', 'PackingHub'] } : null)
        : s
    ))
    .filter(Boolean);

  const alertasAbertos = useMemo(
    () => alertas.filter((a) => a.status === 'aberto'),
    [alertas]
  );
  const alertasTopo = useMemo(() => {
    const peso = { critico: 0, alta: 0, atencao: 1, media: 1 };
    return [...alertasAbertos]
      .sort((a, b) => (peso[a.severidade] ?? 2) - (peso[b.severidade] ?? 2))
      .slice(0, 2);
  }, [alertasAbertos]);

  const servicosTopo = (servicos || []).slice(0, 2);

  const nome = usuario?.nome?.split(' ')[0] || 'bem-vindo';
  const iniciais = (usuario?.nome || 'V C')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0].toUpperCase())
    .join('');

  // params: {} zera parâmetros de visitas anteriores (tela sempre abre limpa)
  const ir = ([tab, tela]) => nav.navigate(tab, { screen: tela, params: { _novo: Date.now() } });

  // atalho Colheita (produção-só, 23/07): abre SEMPRE o registro de produção
  // (válvula → classificações → tela Colheita do sistema). Apontamentos de
  // colheita da equipe, quando existirem, seguem na aba Tarefas.
  const acaoAtalho = (s) => (
    s.especial === 'colheita'
      ? nav.navigate('RegistrarTab', { screen: 'Apontamento', params: { soTipo: 'colheita' } })
      : ir(s.ir)
  );

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

  // estado da fila em uma frase honesta (espec 3.2: nunca prometer envio)
  const estadoSync = sincronizando
    ? 'Sincronizando…'
    : pendentes > 0
      ? (online ? `${pendentes} na fila — enviando` : `${pendentes} na fila — envia com sinal`)
      : (online ? 'Tudo enviado' : 'Sem sinal — registros ficam na fila');

  return (
    <SafeAreaView style={styles.tela} edges={['top']}>
      <View style={styles.topbar}>
        <Pressable style={styles.avatar} onPress={confirmarSaida} accessibilityLabel="Conta e sair" hitSlop={6}>
          <Text style={styles.avatarTxt}>{iniciais}</Text>
        </Pressable>
        <View style={{ flex: 1 }}>
          <Text style={styles.ola}>{saudacao()}, {nome}</Text>
          <Text style={styles.sub}>{rotuloSafra(safra) || 'VERO Campo'}</Text>
        </View>
        {/* sincronização na topbar: ⟳ + contagem da fila */}
        <Pressable
          style={styles.sino}
          onPress={() => nav.navigate('MaisTab', { screen: 'Sincronizacao' })}
          accessibilityLabel={estadoSync}
          hitSlop={8}
        >
          <Icone nome="sync" tam={22} cor={sincronizando ? cores.lime : cores.surface} />
          {pendentes > 0 && (
            <View style={[styles.sinoBadge, { backgroundColor: cores.amber }]}>
              <Text style={styles.sinoBadgeTxt}>{pendentes > 99 ? '99+' : pendentes}</Text>
            </View>
          )}
        </Pressable>
        {/* copiloto/assistente no lugar do sino — faísca de IA;
            alertas seguem na seção "Alertas do dia" e na aba Mais */}
        <Pressable
          style={styles.sino}
          onPress={() => nav.navigate('ChatTab')}
          accessibilityLabel="Assistente VERO"
          hitSlop={8}
        >
          <Svg width={24} height={24} viewBox="0 0 24 24">
            <Path
              fill={cores.lime}
              d="M11 2l1.9 5.6a2 2 0 0 0 1.3 1.3L19.8 11l-5.6 1.9a2 2 0 0 0-1.3 1.3L11 19.8l-1.9-5.6a2 2 0 0 0-1.3-1.3L2.2 11l5.6-1.9a2 2 0 0 0 1.3-1.3L11 2Z"
            />
            <Path
              fill={cores.surface}
              d="M19 14l.9 2.6a1.4 1.4 0 0 0 .9.9L23.4 18l-2.6.9a1.4 1.4 0 0 0-.9.9L19 22.4l-.9-2.6a1.4 1.4 0 0 0-.9-.9L14.6 18l2.6-.9a1.4 1.4 0 0 0 .9-.9L19 14Z"
            />
          </Svg>
        </Pressable>
      </View>

      <ScrollView style={styles.rolagem} contentContainerStyle={styles.body} showsVerticalScrollIndicator={false}>
        {/* 5.3: rejeições do servidor visíveis onde o operador está */}
        {falhas > 0 && (
          <Pressable
            style={styles.bannerFalha}
            onPress={() => nav.navigate('MaisTab', { screen: 'Sincronizacao' })}
          >
            <Text style={styles.bannerFalhaTxt}>
              ⚠️ {falhas} {falhas === 1 ? 'registro não foi aceito' : 'registros não foram aceitos'} pelo sistema — toque para ver
            </Text>
          </Pressable>
        )}

        {/* Clima no topo — previsão de 7 dias */}
        <CartaoClima />

        {/* Alertas do dia — críticos primeiro */}
        {alertasTopo.length > 0 && (
          <>
            <Eyebrow>Alertas do dia</Eyebrow>
            <View style={styles.bloco}>
              {alertasTopo.map((a, i) => {
                const sv = sevDe(a.severidade);
                return (
                  <Pressable
                    key={a.id}
                    style={({ pressed }) => [styles.rica, i > 0 && styles.itemDiv, pressed && styles.ricaPress]}
                    onPress={() => nav.navigate('MaisTab', { screen: 'Avisos' })}
                  >
                    <View style={[styles.rBadge, { backgroundColor: sv.bg }]}>
                      <Icone nome={sv.ic} tam={19} cor={sv.cor} />
                    </View>
                    <Text style={[styles.rTitulo, { flex: 1 }]} numberOfLines={2}>{a.titulo || 'Alerta'}</Text>
                    <Text style={styles.seta}>›</Text>
                  </Pressable>
                );
              })}
              {alertasAbertos.length > alertasTopo.length && (
                <Pressable style={[styles.ricaLink, styles.itemDiv]} onPress={() => nav.navigate('MaisTab', { screen: 'Avisos' })}>
                  <Text style={styles.verTodos}>Ver todos os {alertasAbertos.length} alertas</Text>
                </Pressable>
              )}
            </View>
          </>
        )}

        {/* Serviços/OS do dia */}
        <Eyebrow>Serviços do dia</Eyebrow>
        <View style={styles.bloco}>
          {servicosTopo.length === 0 && (
            <View style={styles.ricaVazia}>
              <Text style={styles.vazio}>Nenhum serviço em aberto — inicie pelo botão ＋</Text>
            </View>
          )}
          {servicosTopo.map((s, i) => {
            const ts = TIPO_SERVICO[s.tipo] || TIPO_SERVICO.outro;
            return (
              <Pressable
                key={String(s.id)}
                style={({ pressed }) => [styles.rica, i > 0 && styles.itemDiv, pressed && styles.ricaPress]}
                onPress={() => nav.navigate('TarefasTab')}
              >
                <View style={[styles.rBadge, { backgroundColor: corAtalho(ts.ic).bg }]}>
                  <Icone nome={ts.ic} tam={19} cor={corAtalho(ts.ic).fg} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.rTitulo} numberOfLines={1}>{ts.lbl}</Text>
                  <Text style={styles.rSub} numberOfLines={1}>{s.talhao_nome || 'sem válvula'}</Text>
                </View>
                <Text style={styles.seta}>›</Text>
              </Pressable>
            );
          })}
          {(servicos || []).length > 0 && (
            <Pressable style={[styles.ricaLink, styles.itemDiv]} onPress={() => nav.navigate('TarefasTab')}>
              <Text style={styles.verTodos}>Abrir a fila de serviços</Text>
            </Pressable>
          )}
        </View>

        <Eyebrow>Registro rápido</Eyebrow>
        <View style={styles.scRow}>
          {atalhos.map((s) => (
            <Pressable key={s.n} style={styles.sc} onPress={() => acaoAtalho(s)}>
              <View style={[styles.scIc, { backgroundColor: corAtalho(s.ic).fg }]}>
                <Icone nome={s.ic} tam={23} cor="#fff" tracado={1.9} />
              </View>
              <Text style={styles.scN}>{s.n}</Text>
            </Pressable>
          ))}
        </View>

        <Eyebrow>Consultar</Eyebrow>
        <View style={styles.scRow}>
          {consultar.map((s) => (
            <Pressable key={s.n} style={styles.sc} onPress={() => ir(s.ir)}>
              <View style={[styles.scIc, { backgroundColor: corAtalho(s.ic).fg }]}>
                <Icone nome={s.ic} tam={23} cor="#fff" tracado={1.9} />
              </View>
              <Text style={styles.scN}>{s.n}</Text>
            </Pressable>
          ))}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.sidebar },
  rolagem: { backgroundColor: cores.page },
  bannerFalha: {
    backgroundColor: cores.dangerBg, borderRadius: raio.card,
    paddingHorizontal: 14, paddingVertical: 12, marginBottom: 4,
  },
  bannerFalhaTxt: { fontSize: 12.5, color: cores.danger, fontFamily: fonte.sansBold, lineHeight: 18 },
  topbar: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    paddingHorizontal: espaco.md, paddingVertical: 12,
    backgroundColor: cores.sidebar,
  },
  avatar: {
    width: 40, height: 40, borderRadius: 20, backgroundColor: cores.lime,
    alignItems: 'center', justifyContent: 'center',
  },
  avatarTxt: { fontSize: 14, color: cores.limeInk, fontFamily: fonte.sansBold },
  ola: { fontSize: 17, color: '#fff', fontFamily: fonte.sansBold },
  sub: { fontSize: 11.5, color: 'rgba(255,255,255,0.6)', fontFamily: fonte.sansMed, marginTop: 1 },
  sino: { padding: 4 },
  sinoIc: { fontSize: 22 },
  sinoBadge: {
    position: 'absolute', top: 0, right: 0, minWidth: 16, height: 16, borderRadius: 8,
    backgroundColor: cores.danger, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 3,
  },
  sinoBadgeTxt: { fontSize: 9.5, color: '#fff', fontFamily: fonte.sansBold },
  body: { padding: espaco.md, paddingBottom: 96, gap: espaco.md },

  // blocos de lista (alertas / serviços)
  bloco: {
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: 16, overflow: 'hidden',
    shadowColor: cores.shadowInk, shadowOpacity: 0.06, shadowRadius: 8, shadowOffset: { width: 0, height: 3 }, elevation: 1,
  },
  itemLinha: {
    flexDirection: 'row', alignItems: 'center', gap: 10,
    minHeight: espaco.toque, paddingHorizontal: espaco.md, paddingVertical: 8,
  },
  itemDiv: { borderTopWidth: 1, borderTopColor: cores.track },
  itemTitulo: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  sevPonto: { width: 9, height: 9, borderRadius: 5 },
  seta: { fontSize: 18, color: cores.faint },
  verTodos: { fontSize: 12.5, color: cores.accent, fontFamily: fonte.sansBold },
  vazio: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed },
  chipExec: {
    backgroundColor: cores.infoBg, borderRadius: 20, paddingHorizontal: 8, paddingVertical: 3,
  },
  chipExecTxt: { fontSize: 10, color: cores.info, fontFamily: fonte.sansBold },

  // linha "rica" (Alertas/Serviços do dia) — badge colorido + título + sub
  rica: {
    flexDirection: 'row', alignItems: 'center', gap: 12,
    paddingHorizontal: 14, paddingVertical: 12, minHeight: espaco.toque + 10,
  },
  ricaPress: { backgroundColor: cores.campo },
  rBadge: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  sevDot: { width: 8, height: 8, borderRadius: 4, marginLeft: 4 },
  rTitulo: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansSemi, lineHeight: 18.5 },
  rSub: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  sevPill: { borderRadius: 20, paddingHorizontal: 8, paddingVertical: 3 },
  sevPillTxt: { fontSize: 10, fontFamily: fonte.sansBold, letterSpacing: 0.2 },
  ricaLink: { paddingHorizontal: 14, paddingVertical: 13 },
  ricaVazia: { paddingHorizontal: 14, paddingVertical: 16 },

  // sincronização na topbar
  syncIc: { color: cores.surface, fontFamily: fonte.sansBold },

  // atalhos — uma linha só por seção
  scRow: { flexDirection: 'row', gap: 8 },
  sc: {
    flex: 1, paddingVertical: 4, paddingHorizontal: 2, alignItems: 'center', gap: 6,
  },
  // tile sólido colorido, ícone branco — máxima presença (estilo app premium)
  scIc: {
    width: 50, height: 50, borderRadius: 15, alignItems: 'center', justifyContent: 'center',
    shadowColor: cores.shadowInk, shadowOpacity: 0.18, shadowRadius: 6, shadowOffset: { width: 0, height: 3 }, elevation: 3,
  },
  scN: { fontSize: 10.5, color: cores.ink2, fontFamily: fonte.sansSemi, textAlign: 'center' },
});
