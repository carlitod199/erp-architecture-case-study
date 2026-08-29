import React, { useEffect, useRef, useState } from 'react';
import { View, Text, PanResponder, StyleSheet } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { useNavigation, useRoute } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { Tela, Cartao, Botao, Badge, Input, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';
import { cores, fonte, raio } from '../theme';
import { useAuth } from '../context/AuthContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { useSync } from '../context/SyncContext';

// J4 / P-48 — assinatura dos operadores exigida pelo GlobalG.A.P., agora REAL:
// cada quadro assinado entra na fila offline (POST /aplicacoes/{id}/assinar
// com o SVG do traço) e sobe quando houver sinal.

const horaAgora = () => {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

// Quadro de assinatura desenhável — devolve os traços (paths SVG) via aoMudar.
function QuadroAssinatura({ nome, aoMudarNome, aoMudar, titulo, subtitulo, placeholder }) {
  const [tracos, setTracos] = useState([]);
  const [tracoAtual, setTracoAtual] = useState('');
  const [larguraQuadro, setLarguraQuadro] = useState(320);
  const larguraRef = useRef(320); // espelho da largura p/ o closure do PanResponder
  const tracoRef = useRef('');
  const tracosRef = useRef([]);
  const aoMudarRef = useRef(aoMudar);
  aoMudarRef.current = aoMudar;

  const pan = useRef(
    PanResponder.create({
      onStartShouldSetPanResponder: () => true,
      onMoveShouldSetPanResponder: () => true,
      // dentro de ScrollView: não deixar o scroll roubar o traço
      onPanResponderTerminationRequest: () => false,
      onShouldBlockNativeResponder: () => true,
      onPanResponderGrant: (e) => {
        const { locationX, locationY } = e.nativeEvent;
        tracoRef.current = `M ${locationX.toFixed(1)} ${locationY.toFixed(1)}`;
        setTracoAtual(tracoRef.current);
      },
      onPanResponderMove: (e) => {
        const { locationX, locationY } = e.nativeEvent;
        tracoRef.current += ` L ${locationX.toFixed(1)} ${locationY.toFixed(1)}`;
        setTracoAtual(tracoRef.current);
      },
      onPanResponderRelease: () => {
        if (tracoRef.current.includes('L')) {
          tracosRef.current = [...tracosRef.current, tracoRef.current];
          setTracos(tracosRef.current);
          aoMudarRef.current(tracosRef.current, larguraRef.current);
        }
        tracoRef.current = '';
        setTracoAtual('');
      },
    }),
  ).current;

  function limpar() {
    tracosRef.current = [];
    setTracos([]);
    setTracoAtual('');
    tracoRef.current = '';
    aoMudarRef.current([], larguraRef.current);
  }

  const assinado = tracos.length > 0;

  return (
    <Cartao>
      {!!titulo && (
        <View style={{ marginBottom: 9 }}>
          <Text style={styles.quadroTitulo}>{titulo}</Text>
          {!!subtitulo && <Text style={styles.quadroSub}>{subtitulo}</Text>}
        </View>
      )}
      <View style={styles.opTopo}>
        <Input
          style={styles.opNomeWrap}
          estiloCampo={styles.opNomeCampo}
          value={nome}
          onChangeText={aoMudarNome}
          placeholder={placeholder || 'Nome de quem assina'}
        />
        <Badge tipo={assinado ? 'ok' : 'mut'}>{assinado ? 'Assinado ✓' : 'Pendente'}</Badge>
      </View>

      <View
        style={styles.quadro}
        onLayout={(e) => {
          const w = Math.round(e.nativeEvent.layout.width) || 320;
          larguraRef.current = w;
          setLarguraQuadro(w);
        }}
        {...pan.panHandlers}
      >
        <Svg style={StyleSheet.absoluteFill} viewBox={`0 0 ${larguraQuadro} 150`}>
          {tracos.map((t, i) => (
            <Path key={i} d={t} stroke={cores.ink} strokeWidth={2.5} fill="none" strokeLinecap="round" strokeLinejoin="round" />
          ))}
          {!!tracoAtual && (
            <Path d={tracoAtual} stroke={cores.ink} strokeWidth={2.5} fill="none" strokeLinecap="round" strokeLinejoin="round" />
          )}
        </Svg>
        {!assinado && !tracoAtual && (
          <View style={styles.dicaLinha}>
            <Icone nome="biometria" tam={16} cor={cores.faint} />
            <Text style={styles.dica}>assine aqui com o dedo</Text>
          </View>
        )}
      </View>

      <Botao titulo="Limpar" variante="ghost" tamanho="sm" onPress={limpar} style={styles.limparBtn} />
    </Cartao>
  );
}

// Monta o SVG final enviado ao servidor (rota valida tamanho ≤ 500 KB).
function montarSvg(tracos, largura) {
  const paths = tracos
    .map((t) => `<path d="${t}" stroke="#1a1a1a" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`)
    .join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${largura} 150">${paths}</svg>`;
}

export default function AplicacaoAssinaturaScreen() {
  const nav = useNavigation();
  const route = useRoute();
  const { usuario } = useAuth();
  const { sincronizarAgora } = useSync();
  const df = route.params?.df || null;
  // modo caixa do supervisor: assina SÓ o receituário (papel rt) de um DF que o
  // operador já confirmou e assinou. Vem de AplicacoesScreen (papel: 'rt').
  const soRt = route.params?.papel === 'rt';

  const [nome1, setNome1] = useState(usuario?.nome || '');
  const [nome2, setNome2] = useState('');
  const [tracos1, setTracos1] = useState([]);
  const [tracos2, setTracos2] = useState([]);
  // largura real de cada quadro — o SVG salvo precisa do MESMO viewBox em que o
  // traço foi capturado, senão a assinatura sai desalinhada no web/PDF
  const [largura1, setLargura1] = useState(360);
  const [largura2, setLargura2] = useState(360);
  const [segundo, setSegundo] = useState(false);
  const [concluido, setConcluido] = useState(null); // hora da conclusão
  const [enviando, setEnviando] = useState(false);

  const pronto = tracos1.length > 0 && nome1.trim().length > 0
    && (!segundo || (tracos2.length > 0 && nome2.trim().length > 0));

  // concluiu → volta sozinho para a fila de Aplicações (navigate para uma
  // rota anterior da stack faz POP: some daqui e da confirmação de uma vez)
  useEffect(() => {
    if (!concluido) return undefined;
    const t = setTimeout(() => nav.navigate('Aplicacoes'), 1800);
    return () => clearTimeout(t);
  }, [concluido, nav]);

  async function concluir() {
    if (!pronto || enviando || !df) return;
    setEnviando(true);
    try {
      // DF do campo (sem id): assina resolvendo pela emissao_uuid; encadeia
      // na fila (pai_uuid = emissão) — emissão e confirmação sobem antes
      const doCampo = !!df.emissao_uuid;
      const rota = doCampo ? rotas.aplicacaoAssinarCampo : rotas.aplicacaoAssinar(df.id);
      const pai = doCampo ? df.emissao_uuid : null;
      const extra = doCampo ? { emissao_uuid: df.emissao_uuid } : {};
      // no modo caixa do supervisor o único quadro é o RT; senão é o operador
      await enfileirar({
        tipo: 'aplicacao_assinar',
        rota,
        metodo: 'POST',
        paiUuid: pai,
        payload: { ...extra, operador_nome: nome1.trim(), papel: soRt ? 'rt' : 'operador', assinatura_svg: montarSvg(tracos1, largura1) },
      });
      // papel RT (receituário) — opcional; pode assinar depois no web
      if (!soRt && segundo && tracos2.length > 0) {
        await enfileirar({
          tipo: 'aplicacao_assinar',
          rota,
          metodo: 'POST',
          paiUuid: pai,
          payload: { ...extra, operador_nome: nome2.trim(), papel: 'rt', assinatura_svg: montarSvg(tracos2, largura2) },
        });
      }
      setConcluido(horaAgora());
      sincronizarAgora().catch(() => {});
    } finally {
      setEnviando(false);
    }
  }

  if (!df) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Assinatura dos operadores" />
        <Tela>
          <Cartao>
            <Text style={styles.explica}>DF não encontrado. Volte à fila de aplicações.</Text>
          </Cartao>
        </Tela>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo={soRt ? 'Assinatura do RT' : 'Assinaturas'} sub={`DF #${df.id} · GlobalG.A.P.`} />
      <Tela>
        <Cartao>
          <Eyebrow>Por que assinar?</Eyebrow>
          <Text style={styles.explica}>
            {soRt
              ? `Este DF já foi executado e assinado pelo operador. Como Responsável Técnico, assine o receituário para concluir a certificação. Vinculado ao DF #${df.id}${df.talhao_nome ? ` (${df.talhao_nome})` : ''}.`
              : `A certificação GlobalG.A.P. exige a assinatura de quem EXECUTOU a aplicação (operador). O Responsável Técnico assina o receituário — pode ser aqui ou depois no VERO web. Vinculado ao DF #${df.id}${df.talhao_nome ? ` (${df.talhao_nome})` : ''}.`}
          </Text>
        </Cartao>

        <QuadroAssinatura
          nome={nome1} aoMudarNome={setNome1}
          aoMudar={(t, w) => { setTracos1(t); if (w) setLargura1(w); }}
          titulo={soRt ? 'Responsável Técnico — receituário *' : 'Operador — quem executou *'}
          placeholder={soRt ? 'Nome do RT' : 'Nome de quem aplicou'}
        />
        {soRt ? null : segundo ? (
          <QuadroAssinatura
            nome={nome2} aoMudarNome={setNome2}
            aoMudar={(t, w) => { setTracos2(t); if (w) setLargura2(w); }}
            titulo="Responsável Técnico — receituário"
            subtitulo="opcional aqui — pode assinar depois no VERO web"
            placeholder="Nome do RT"
          />
        ) : (
          !concluido && (
            <Botao titulo="＋ Adicionar assinatura do RT" variante="ghost" tamanho="sm" onPress={() => setSegundo(true)} style={styles.maisUm} />
          )
        )}

        {concluido ? (
          <View style={styles.sucesso}>
            <Text style={styles.sucessoTitulo}>✓ DF #{df.id} assinado e registrado</Text>
            <Text style={styles.sucessoTxt}>
              Concluído às {concluido} · será enviado quando houver sinal.
            </Text>
            <Botao titulo="‹ Voltar às aplicações" tamanho="sm" onPress={() => nav.navigate('Aplicacoes')} style={styles.btnVoltar} />
          </View>
        ) : (
          <Botao
            titulo={pronto ? (soRt ? 'Registrar assinatura do RT' : 'Concluir aplicação') : 'Assine e informe o nome para concluir'}
            disabled={!pronto || enviando}
            onPress={concluir}
          />
        )}
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  quadroTitulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  quadroSub: { fontSize: 11, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  explica: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansMed, lineHeight: 19 },
  opTopo: { flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 11 },
  opNomeWrap: { flex: 1 },
  opNomeCampo: { fontFamily: fonte.sansBold },
  quadro: {
    height: 150, borderRadius: raio.r, backgroundColor: '#fff',
    borderWidth: 1.5, borderColor: cores.border2, borderStyle: 'dashed',
    overflow: 'hidden', alignItems: 'center', justifyContent: 'center',
  },
  dicaLinha: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  dica: { fontSize: 12.5, color: cores.faint, fontFamily: fonte.sansMed },
  limparBtn: { alignSelf: 'flex-end', marginTop: 7 },
  maisUm: { alignSelf: 'center' },
  sucesso: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 16, alignItems: 'center' },
  sucessoTitulo: { fontSize: 14.5, color: '#0a5c53', fontFamily: fonte.sansBold },
  sucessoTxt: { fontSize: 12, color: '#0a5c53', fontFamily: fonte.sansMed, marginTop: 5 },
  btnVoltar: { marginTop: 13, alignSelf: 'stretch' },
});
