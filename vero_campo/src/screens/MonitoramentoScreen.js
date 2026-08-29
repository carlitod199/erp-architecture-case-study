import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, Pressable, TextInput, ScrollView, Image, Modal, FlatList, StyleSheet } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { useNavigation, useRoute } from '@react-navigation/native';
import { lerRondaHoje, marcarPontoRonda, concluirRondaValvula } from '../offline/db';
import AppHeader from '../components/AppHeader';
import { cores, fonte, raio, espaco } from '../theme';
import { Cartao, Chip, Botao } from '../components/ui';
import Icone from '../components/Icone';
import { useDadosSync } from '../hooks/useDadosSync';
import { useParametro } from '../hooks/useParametro';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { useSync } from '../context/SyncContext';

// Monitoramento MIP = MESMOS CAMPOS do "Novo monitoramento" do VERO web
//: Data · Válvula · Ponto de amostragem · Unidade do índice ·
// Plantas amostradas · Alvos (qtd, índice, local, severidade) · Fotos ·
// Observação. A safra vem do vínculo vigente (o servidor resolve) e o índice
// é calculado pela regra de 3 quando não digitado. Uma leitura = UMA chamada
// multi-alvo em POST /monitoramentos (rascunho; o envio ao líder consolida).

// Meta de pontos por válvula na ronda: tenant_parametros 'mip.meta_pontos_valvula'.
const META_PONTOS_PADRAO = 5;

// Local onde o alvo foi encontrado — mesma whitelist do web (mig 163 + C-44)
const LOCAIS = [
  { id: 'folha', rotulo: 'Folha' },
  { id: 'ramo', rotulo: 'Ramo' },
  { id: 'cacho', rotulo: 'Cacho' },
  { id: 'ponteiros', rotulo: 'Ponteiros' },
  { id: 'casca', rotulo: 'Casca' },
  { id: 'mato', rotulo: 'Mato' },
];

const SEVERIDADES = [
  { id: 'baixa', rotulo: 'Baixa' },
  { id: 'media', rotulo: 'Média' },
  { id: 'alta', rotulo: 'Alta' },
];

const dec = (v) => {
  const n = parseFloat(String(v || '').trim().replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

// 'YYYY-MM-DD' de N dias atrás (0 = hoje) e rótulo curto
function diaAtras(n) {
  const d = new Date();
  d.setDate(d.getDate() - n);
  const p = (x) => String(x).padStart(2, '0');
  return {
    valor: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`,
    rotulo: n === 0 ? 'Hoje' : n === 1 ? 'Ontem' : `${p(d.getDate())}/${p(d.getMonth() + 1)}`,
  };
}
const DIAS = [diaAtras(0), diaAtras(1), diaAtras(2)];

// Ícone ilustrativo por nome do alvo (os alvos reais não trazem ícone)
function iconeDoAlvo(nome) {
  const n = (nome || '').toLowerCase();
  if (n.includes('cigarrinha')) return '🦗';
  if (n.includes('mosca')) return '🪰';
  if (n.includes('trip')) return '🐜';
  if (n.includes('acaro') || n.includes('ácaro')) return '🕷️';
  if (n.includes('lagarta')) return '🐛';
  return '🐛';
}

export default function MonitoramentoScreen() {
  const nav = useNavigation();
  const route = useRoute();
  // válvula vinda da ronda (RondaScreen navega com { valvula }); sem ela, o
  // formulário mostra o seletor de válvula (igual ao web)
  const daRonda = route.params?.valvula || null;

  const { sincronizarAgora } = useSync();
  const { itens: alvosSync, carregado } = useDadosSync('mip_referencias');
  const { itens: valvulas } = useDadosSync('talhoes');

  const alvos = useMemo(() => (
    (alvosSync || []).map((a) => ({
      id: a.id, nome: a.nome, icone: iconeDoAlvo(a.nome), nivel_acao: a.nivel_acao,
    }))
  ), [alvosSync]);

  const TOTAL_PONTOS = useParametro('mip.meta_pontos_valvula', META_PONTOS_PADRAO);

  // ── estado do formulário (espelho do web) ──
  // seletor de válvula SEMPRE visível; a ronda só pré-seleciona
  const [valvula, setValvula] = useState(daRonda || null);
  const [data, setData] = useState(DIAS[0].valor);
  const [unidade, setUnidade] = useState('%');
  const [amostradas, setAmostradas] = useState('');
  const [contagens, setContagens] = useState({});   // { [alvoId]: qtd }
  const [detalhes, setDetalhes] = useState({});     // { [alvoId]: {nivel, local, sev} }
  // estilo do sistema (23/07): a tela começa LIMPA — o monitor ADICIONA só
  // os alvos que encontrou (select), sem poluir com o cadastro inteiro
  const [escolhidosAlvos, setEscolhidosAlvos] = useState([]); // ids na ordem
  const [seletorAlvo, setSeletorAlvo] = useState(false);
  const [observacao, setObservacao] = useState('');
  const [foto, setFoto] = useState(null);
  const [ponto, setPonto] = useState(1);            // ponto da RONDA (progresso)
  const [concluido, setConcluido] = useState(false);
  const [salvando, setSalvando] = useState(false);

  // abertura NOVA pelos atalhos (_novo): zera a tela montada
  useEffect(() => {
    if (!route.params?._novo) return;
    setValvula(daRonda || null); setData(DIAS[0].valor); setUnidade('%');
    setAmostradas(''); setContagens({}); setDetalhes({}); setEscolhidosAlvos([]); setObservacao('');
    setFoto(null); setPonto(1); setConcluido(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.params?._novo]);

  const talhaoId = valvula ? (valvula.talhao_id || valvula.id) : null;

  // na ronda: retoma o progresso do dia DA VÁLVULA SELECIONADA (trocar de
  // válvula pelo seletor recarrega os pontos dela)
  useEffect(() => {
    if (!daRonda || !valvula) return;
    setPonto(1);
    setConcluido(false);
    lerRondaHoje()
      .then((mapa) => {
        const feitos = mapa[String(valvula.id)] || 0;
        if (feitos >= TOTAL_PONTOS) setConcluido(true);
        else if (feitos > 0) setPonto(feitos + 1);
      })
      .catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [valvula?.id]);

  async function tirarFoto() {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) return;
    const r = await ImagePicker.launchCameraAsync({ quality: 0.5 });
    if (!r.canceled && r.assets?.[0]?.uri) setFoto(r.assets[0].uri);
  }

  function contar(id, delta) {
    setContagens((c) => ({ ...c, [id]: Math.max(0, (c[id] || 0) + delta) }));
  }

  function detalhar(id, patch) {
    setDetalhes((d) => ({ ...d, [id]: { ...(d[id] || {}), ...patch } }));
  }

  const algumAlvo = escolhidosAlvos.some((id) => (contagens[id] || 0) > 0);

  // lista do select: alfabética, sem os já adicionados
  const alvosDisponiveis = useMemo(() => (
    alvos
      .filter((a) => !escolhidosAlvos.includes(a.id))
      .sort((x, y) => String(x.nome).localeCompare(String(y.nome), 'pt-BR'))
  ), [alvos, escolhidosAlvos]);

  function adicionarAlvo(a) {
    setEscolhidosAlvos((ls) => (ls.includes(a.id) ? ls : [...ls, a.id]));
    setContagens((c) => ({ ...c, [a.id]: c[a.id] || 1 })); // achou → começa em 1
    setSeletorAlvo(false);
  }

  function removerAlvo(id) {
    setEscolhidosAlvos((ls) => ls.filter((x) => x !== id));
    setContagens((c) => { const n = { ...c }; delete n[id]; return n; });
    setDetalhes((d) => { const n = { ...d }; delete n[id]; return n; });
  }

  // Grava a leitura: UMA chamada multi-alvo (migs 170/173) + foto vinculada.
  async function salvar() {
    if (salvando || !talhaoId || !algumAlvo) return;
    setSalvando(true);
    try {
      const nAmostradas = parseInt(amostradas, 10);
      const listaAlvos = alvos
        .filter((a) => (contagens[a.id] || 0) > 0)
        .map((a) => {
          const d = detalhes[a.id] || {};
          return {
            alvo_id: a.id,
            quantidade_encontrada: contagens[a.id],
            // índice digitado vence; vazio → o servidor calcula (regra de 3)
            nivel_infestacao: dec(d.nivel) ?? undefined,
            local_infestacao: d.local || undefined,
            severidade_qualitativa: d.sev || undefined,
          };
        });

      const uuid = await enfileirar({
        tipo: 'monitoramento',
        rota: rotas.monitoramentos,
        metodo: 'POST',
        payload: {
          talhao_id: talhaoId,
          data: `${data} 00:00:00`,
          unidade: unidade.trim() || undefined,
          plantas_amostradas: Number.isFinite(nAmostradas) && nAmostradas > 0 ? nAmostradas : undefined,
          observacao: observacao.trim() || undefined,
          alvos: listaAlvos,
        },
      });

      // foto vinculada à leitura (a fila envia o pai antes; o servidor
      // resolve origem_uuid → id)
      if (foto) {
        await enfileirar({
          tipo: 'anexo',
          rota: rotas.anexos,
          metodo: 'POST',
          paiUuid: uuid,
          payload: {
            uri: foto, nome: 'monitoramento.jpg', mime: 'image/jpeg',
            origem_uuid: uuid, origem_tipo: 'monitoramento',
          },
        });
      }

      // progresso da ronda (só quando veio dela) — a meta é OPCIONAL: quem
      // decide encerrar é o operador, pelo botão "Concluir válvula"
      if (daRonda) {
        await marcarPontoRonda(valvula.id).catch(() => {});
        if (ponto >= TOTAL_PONTOS) {
          setConcluido(true);
          sincronizarAgora().catch(() => {});
          return;
        }
        setPonto((p) => p + 1);
      } else {
        setConcluido(true);
        sincronizarAgora().catch(() => {});
        return;
      }

      // limpa para o próximo ponto da ronda
      setContagens({});
      setDetalhes({});
      setEscolhidosAlvos([]);
      setFoto(null);
      setObservacao('');
    } finally {
      setSalvando(false);
    }
  }

  const rotuloValvula = valvula
    ? [valvula.nome, valvula.cultura || valvula.talhao_nome].filter(Boolean).join(' · ')
    : 'Escolha a válvula';

  if (concluido) {
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Monitoramento" sub={rotuloValvula} />
        <View style={styles.sucessoWrap}>
          <Cartao style={styles.sucessoCard}>
            <Text style={styles.sucessoIcone}>✓</Text>
            <Text style={styles.sucessoTitulo}>
              {daRonda ? `${valvula?.nome || 'Válvula'} completa` : 'Leitura registrada'}
            </Text>
            <Text style={styles.sucessoMsg}>
              {daRonda
                ? `${TOTAL_PONTOS} de ${TOTAL_PONTOS} pontos coletados.\nO índice será calculado e revisado no envio ao líder.`
                : 'A leitura ficou como rascunho — use "Enviar ao líder" para consolidar e disparar os alertas.'}
            </Text>
            <Botao
              titulo={daRonda ? 'Voltar à ronda' : 'Voltar'}
              onPress={() => nav.goBack()}
              style={{ alignSelf: 'stretch' }}
            />
          </Cartao>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.tela}>
      <AppHeader
        titulo="Monitoramento"
        sub={daRonda ? `${rotuloValvula} · Ponto ${ponto} de ${TOTAL_PONTOS}` : rotuloValvula}
      />

      {/* indicador dos pontos da ronda */}
      {!!daRonda && (
        <View style={styles.pontos}>
          {Array.from({ length: TOTAL_PONTOS }, (_, i) => i + 1).map((n) => (
            <View key={n} style={[styles.pontoBolinha, n < ponto && styles.pontoFeito, n === ponto && styles.pontoAtual]}>
              <Text style={[styles.pontoNum, (n < ponto || n === ponto) && { color: '#fff' }]}>
                {n < ponto ? '✓' : n}
              </Text>
            </View>
          ))}
        </View>
      )}

      <ScrollView contentContainerStyle={styles.corpo} showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
        {/* Válvula — seletor sempre visível (a ronda só pré-seleciona) */}
        <Cartao>
          <Text style={styles.obsRotulo}>Válvula *</Text>
          <View style={styles.locais}>
            {(valvulas || []).map((v) => (
              <Chip key={v.id} selecionado={valvula?.id === v.id} onPress={() => setValvula(v)}>
                {v.nome}
              </Chip>
            ))}
          </View>
        </Cartao>

        {/* Data */}
        <Cartao>
          <Text style={styles.obsRotulo}>Data *</Text>
          <View style={styles.locais}>
            {DIAS.map((d) => (
              <Chip key={d.valor} selecionado={data === d.valor} onPress={() => setData(d.valor)}>
                {d.rotulo}
              </Chip>
            ))}
          </View>
        </Cartao>

        {/* Unidade do índice + Plantas amostradas (lado a lado, como no web) */}
        <View style={styles.duplo}>
          <Cartao style={{ flex: 1 }}>
            <Text style={styles.obsRotulo}>Unidade do índice</Text>
            <TextInput
              style={[styles.input, { minHeight: 0, marginTop: 9 }]}
              value={unidade}
              onChangeText={setUnidade}
              placeholder="%"
              placeholderTextColor={cores.faint}
              maxLength={30}
            />
          </Cartao>
          <Cartao style={{ flex: 1 }}>
            <Text style={styles.obsRotulo}>Plantas amostradas</Text>
            <TextInput
              style={[styles.input, { minHeight: 0, marginTop: 9 }]}
              placeholder="Ex.: 20"
              placeholderTextColor={cores.faint}
              value={amostradas}
              onChangeText={setAmostradas}
              keyboardType="number-pad"
            />
          </Cartao>
        </View>

        {/* Alvos — estilo do sistema: adiciona SÓ o que encontrou (select) */}
        <Text style={styles.dica}>
          Adicione os alvos que encontrou — com plantas amostradas o índice sai pela regra de 3 (digite o índice só se quiser sobrescrever).
        </Text>
        {carregado && alvos.length === 0 && (
          <Text style={[styles.dica, { color: cores.amberDeep }]}>
            Nenhum alvo do RT no aparelho — sincronize para carregar.
          </Text>
        )}

        {escolhidosAlvos.map((idAlvo) => alvos.find((x) => x.id === idAlvo)).filter(Boolean).map((a) => {
          const qtd = contagens[a.id] || 0;
          const d = detalhes[a.id] || {};
          // SEMÁFORO AO VIVO (desenho do gestor 23/07): o monitor sabe no campo
          // se o alvo "estourou" — índice manual vence; senão regra de 3;
          // senão a contagem crua. 🔴 ≥ nível · 🟡 ≥ 70% do nível · 🟢 abaixo.
          const nAmostradasLive = parseInt(amostradas, 10);
          const indiceLive = dec(d.nivel) ?? (
            Number.isFinite(nAmostradasLive) && nAmostradasLive > 0
              ? (qtd / nAmostradasLive) * 100
              : qtd
          );
          const nivelNum = a.nivel_acao != null ? Number(a.nivel_acao) : null;
          const farol = qtd > 0 && nivelNum > 0
            ? (indiceLive >= nivelNum ? 'vermelho' : indiceLive >= nivelNum * 0.7 ? 'amarelo' : 'verde')
            : null;
          const corFarol = farol === 'vermelho' ? cores.danger : farol === 'amarelo' ? cores.amber : cores.pos;
          return (
            <Cartao key={a.id} style={farol === 'vermelho' && styles.alvoAlerta}>
              <View style={styles.alvoLinha}>
                <Text style={styles.alvoIcone}>{a.icone}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={styles.alvoNome}>{a.nome}</Text>
                  {a.nivel_acao != null && (
                    <View style={styles.nivelLinha}>
                      {!!farol && <View style={[styles.farol, { backgroundColor: corFarol }]} />}
                      <Text style={[styles.alvoNivel, farol === 'vermelho' && { color: cores.danger, fontFamily: fonte.sansBold }]}>
                        {qtd > 0
                          ? `índice ~${String(Math.round(indiceLive * 10) / 10).replace('.', ',')} · nível ${String(a.nivel_acao).replace('.', ',')}${farol === 'vermelho' ? ' — EM ALERTA' : ''}`
                          : `nível de ação: ${String(a.nivel_acao).replace('.', ',')}`}
                      </Text>
                    </View>
                  )}
                </View>
                <View style={styles.contador}>
                  <Pressable style={[styles.btnConta, qtd === 0 && { opacity: 0.35 }]} onPress={() => contar(a.id, -1)}>
                    <Text style={styles.btnContaTxt}>−</Text>
                  </Pressable>
                  <Text style={styles.qtd}>{qtd}</Text>
                  <Pressable style={[styles.btnConta, styles.btnMais]} onPress={() => contar(a.id, +1)}>
                    <Text style={[styles.btnContaTxt, { color: '#fff' }]}>+</Text>
                  </Pressable>
                  <Pressable style={styles.removerAlvo} onPress={() => removerAlvo(a.id)} accessibilityLabel={`Remover ${a.nome}`}>
                    <Text style={styles.removerAlvoTxt}>✕</Text>
                  </Pressable>
                </View>
              </View>

              {/* detalhes do alvo (aparecem quando contou) — colunas do web */}
              {qtd > 0 && (
                <View style={styles.alvoDetalhes}>
                  <View style={styles.detLinha}>
                    <Text style={styles.detRotulo}>Índice</Text>
                    <TextInput
                      style={styles.detInput}
                      value={d.nivel || ''}
                      onChangeText={(t) => detalhar(a.id, { nivel: t })}
                      keyboardType="numeric"
                      placeholder="auto"
                      placeholderTextColor={cores.faint}
                    />
                  </View>
                  <View style={styles.detLinha}>
                    <Text style={styles.detRotulo}>Local</Text>
                    <View style={[styles.locais, { flex: 1, marginTop: 0 }]}>
                      {LOCAIS.map((l) => {
                        const ativo = d.local === l.id;
                        return (
                          <Pressable
                            key={l.id}
                            style={[styles.localChip, styles.chipMini, ativo && styles.localChipAtivo]}
                            onPress={() => detalhar(a.id, { local: ativo ? null : l.id })}
                          >
                            <Text style={[styles.chipMiniTxt, ativo && { color: '#fff' }]}>{l.rotulo}</Text>
                          </Pressable>
                        );
                      })}
                    </View>
                  </View>
                  <View style={styles.detLinha}>
                    <Text style={styles.detRotulo}>Severid.</Text>
                    <View style={[styles.locais, { flex: 1, marginTop: 0 }]}>
                      {SEVERIDADES.map((s) => {
                        const ativo = d.sev === s.id;
                        return (
                          <Pressable
                            key={s.id}
                            style={[styles.localChip, styles.chipMini, ativo && styles.localChipAtivo]}
                            onPress={() => detalhar(a.id, { sev: ativo ? null : s.id })}
                          >
                            <Text style={[styles.chipMiniTxt, ativo && { color: '#fff' }]}>{s.rotulo}</Text>
                          </Pressable>
                        );
                      })}
                    </View>
                  </View>
                </View>
              )}
            </Cartao>
          );
        })}

        {/* ＋ Adicionar alvo — select no estilo do sistema (lista alfabética) */}
        {alvosDisponiveis.length > 0 && (
          <Pressable style={styles.btnAddAlvo} onPress={() => setSeletorAlvo(true)}>
            <Text style={styles.btnAddAlvoTxt}>＋ Adicionar alvo encontrado</Text>
          </Pressable>
        )}
        <Modal visible={seletorAlvo} transparent animationType="fade" onRequestClose={() => setSeletorAlvo(false)}>
          <Pressable style={styles.veu} onPress={() => setSeletorAlvo(false)}>
            <View style={styles.folha}>
              <Text style={styles.folhaTitulo}>Qual alvo você encontrou?</Text>
              <FlatList
                data={alvosDisponiveis}
                keyExtractor={(a) => String(a.id)}
                style={{ maxHeight: 420 }}
                renderItem={({ item: a }) => (
                  <Pressable style={styles.opcaoAlvo} onPress={() => adicionarAlvo(a)}>
                    <Text style={styles.opcaoAlvoIc}>{a.icone}</Text>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.opcaoAlvoTxt}>{a.nome}</Text>
                      {a.nivel_acao != null && (
                        <Text style={styles.opcaoAlvoSub}>nível de ação: {String(a.nivel_acao).replace('.', ',')}</Text>
                      )}
                    </View>
                  </Pressable>
                )}
              />
            </View>
          </Pressable>
        </Modal>

        {/* Foto (opcional) — vira anexo da leitura */}
        <Cartao>
          <View style={styles.rotuloLinha}>
            <Text style={styles.obsRotulo}>Foto (opcional)</Text>
            <Icone nome="camera" tam={16} cor={cores.muted2} />
          </View>
          {foto ? (
            <View style={styles.fotoLinha}>
              <Image source={{ uri: foto }} style={styles.fotoPreview} />
              <View style={{ flex: 1, gap: 8 }}>
                <Botao titulo="Tirar outra" variante="secundaria" tamanho="sm" onPress={tirarFoto} />
                <Botao titulo="Remover" variante="perigo" tamanho="sm" onPress={() => setFoto(null)} />
              </View>
            </View>
          ) : (
            <Botao
              titulo="Fotografar o alvo encontrado"
              variante="secundaria"
              tamanho="sm"
              icone={<Icone nome="camera" tam={18} cor={cores.accent} />}
              onPress={tirarFoto}
              style={{ marginTop: 10 }}
            />
          )}
        </Cartao>

        {/* Observação */}
        <Cartao>
          <Text style={styles.obsRotulo}>Observação</Text>
          <TextInput
            style={styles.input}
            placeholder="Ex.: folhas novas com ninfas na face inferior…"
            placeholderTextColor={cores.faint}
            value={observacao}
            onChangeText={setObservacao}
            multiline
          />
        </Cartao>
      </ScrollView>

      <View style={styles.rodape}>
        <Botao
          titulo={!talhaoId ? 'Escolha a válvula'
            : !algumAlvo ? 'Conte ao menos um alvo'
            : daRonda
              ? (ponto < TOTAL_PONTOS ? `Salvar ponto ${ponto} e avançar` : `Salvar ponto ${ponto} e concluir`)
              : 'Salvar monitoramento'}
          disabled={salvando || !talhaoId || !algumAlvo}
          onPress={salvar}
          style={{ alignSelf: 'stretch' }}
        />
        {/* meta de pontos é opcional: com ao menos 1 leitura salva, o operador
            pode encerrar a válvula sem cumprir os pontos restantes */}
        {!!daRonda && ponto > 1 && (
          <Botao
            variante="ghost"
            tamanho="sm"
            onPress={async () => {
              await concluirRondaValvula(valvula.id, TOTAL_PONTOS).catch(() => {});
              setConcluido(true);
              sincronizarAgora().catch(() => {});
            }}
            style={{ alignSelf: 'stretch' }}
          >
            {`✓ Concluir válvula com ${ponto - 1} ${ponto - 1 === 1 ? 'leitura' : 'leituras'}`}
          </Botao>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  pontos: { flexDirection: 'row', justifyContent: 'center', gap: 10, paddingVertical: 14 },
  pontoBolinha: { width: 32, height: 32, borderRadius: 16, backgroundColor: cores.track, alignItems: 'center', justifyContent: 'center' },
  pontoFeito: { backgroundColor: cores.pos },
  pontoAtual: { backgroundColor: cores.accent },
  pontoNum: { fontSize: 12.5, color: cores.muted2, fontFamily: fonte.sansBold },
  corpo: { paddingHorizontal: espaco.md, paddingTop: 10, paddingBottom: 24, gap: 10 },
  dica: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, marginBottom: 2 },
  duplo: { flexDirection: 'row', gap: 10 },
  alvoLinha: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  alvoIcone: { fontSize: 20 },
  alvoNome: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  alvoAlerta: { borderWidth: 1.5, borderColor: cores.danger },
  nivelLinha: { flexDirection: 'row', alignItems: 'center', gap: 5, marginTop: 2 },
  farol: { width: 9, height: 9, borderRadius: 5 },
  alvoNivel: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  contador: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  btnConta: { width: 44, height: 44, borderRadius: raio.r, backgroundColor: cores.campo, alignItems: 'center', justifyContent: 'center' },
  btnMais: { backgroundColor: cores.accent },
  btnContaTxt: { fontSize: 22, color: cores.ink, fontFamily: fonte.sansBold, marginTop: -2 },
  qtd: { minWidth: 40, textAlign: 'center', fontSize: 24, color: cores.ink, fontFamily: fonte.monoSemi },
  alvoDetalhes: { marginTop: 11, borderTopWidth: 1, borderTopColor: cores.border, paddingTop: 10, gap: 9 },
  detLinha: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  detRotulo: { width: 58, fontSize: 10.5, letterSpacing: 0.5, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold },
  detInput: {
    flex: 0, width: 110, height: 40, borderRadius: raio.sm, backgroundColor: cores.campo,
    paddingHorizontal: 10, fontSize: 14, color: cores.ink, fontFamily: fonte.monoSemi,
  },
  removerAlvo: { width: 34, height: 44, alignItems: 'center', justifyContent: 'center' },
  removerAlvoTxt: { fontSize: 14, color: cores.danger, fontFamily: fonte.sansBold },
  btnAddAlvo: {
    minHeight: espaco.toque, borderRadius: raio.r, backgroundColor: cores.posBg,
    borderWidth: 1.5, borderColor: cores.accent, borderStyle: 'dashed',
    alignItems: 'center', justifyContent: 'center',
  },
  btnAddAlvoTxt: { fontSize: 13.5, color: cores.accent, fontFamily: fonte.sansBold },
  veu: { flex: 1, backgroundColor: 'rgba(10,20,17,0.45)', justifyContent: 'center', padding: 24 },
  folha: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14, maxHeight: 500 },
  folhaTitulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold, marginBottom: 8 },
  opcaoAlvo: {
    minHeight: espaco.toque, flexDirection: 'row', alignItems: 'center', gap: 10,
    paddingHorizontal: 12, paddingVertical: 8, borderRadius: raio.sm, marginBottom: 4, backgroundColor: cores.campo,
  },
  opcaoAlvoIc: { fontSize: 18 },
  opcaoAlvoTxt: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansSemi },
  opcaoAlvoSub: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  obsRotulo: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  rotuloLinha: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  fotoLinha: { flexDirection: 'row', gap: 12, marginTop: 10, alignItems: 'center' },
  fotoPreview: { width: 84, height: 84, borderRadius: raio.sm, backgroundColor: cores.track },
  locais: { flexDirection: 'row', flexWrap: 'wrap', gap: 7, marginTop: 10 },
  localChip: { paddingHorizontal: 13, paddingVertical: 8, borderRadius: 20, backgroundColor: cores.campo },
  localChipAtivo: { backgroundColor: cores.accent },
  chipMini: { paddingHorizontal: 10, paddingVertical: 6 },
  chipMiniTxt: { fontSize: 11, color: cores.ink2, fontFamily: fonte.sansSemi },
  input: {
    marginTop: 9, minHeight: 72, backgroundColor: cores.campo, borderRadius: raio.sm,
    padding: 11, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed, textAlignVertical: 'top',
  },
  rodape: { padding: espaco.md, paddingBottom: 22, gap: 8 },
  sucessoWrap: { flex: 1, justifyContent: 'center', padding: espaco.xl },
  sucessoCard: { padding: 26, alignItems: 'center', gap: 10 },
  sucessoIcone: { fontSize: 40, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 17, color: cores.ink, fontFamily: fonte.sansBold },
  sucessoMsg: { fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 20, marginBottom: 8 },
});
