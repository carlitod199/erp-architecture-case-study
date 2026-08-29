import React, { useEffect, useRef, useState } from 'react';
import { View, Text, TextInput, StyleSheet } from 'react-native';
import { useRoute, useNavigation } from '@react-navigation/native';
import { lerRascunho, gravarRascunho, apagarRascunho } from '../offline/rascunhos';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import ProducaoPessoas from '../components/apontar/ProducaoPessoas';
import { Tela, Cartao, Badge, Chip, Botao, Eyebrow } from '../components/ui';
import { useSync } from '../context/SyncContext';
import { useDadosSync } from '../hooks/useDadosSync';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { cores, fonte, raio } from '../theme';

// CONCLUSÃO do serviço (modelo do gestor 22/07 — inversão): o apontamento
// nasce no escritório; o app preenche a EXECUÇÃO e conclui. Preencher é
// obrigatório: quantidade para poda/colheita e observação para todos —
// não existe concluir vazio. Vira 'pendente' (aguardando validação do web).

const dec = (v) => {
  const n = parseFloat(String(v || '').trim().replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

// tipo de serviço → ícone SVG (quando houver). NÃO altera o contrato de dados:
// servico.ic (emoji vindo de TarefasScreen) segue de fallback quando o tipo
// não tem ícone mapeado.
const TIPO_ICONE = {
  poda: 'poda',
  colheita: 'colheita',
  tratos_culturais: 'servico',
  aplicacao: 'aplicacao',
  pulverizacao: 'aplicacao',
  adubacao: 'adubacao',
  irrigacao: 'irrigacao',
  abastecimento: 'abastecimento',
  monitoramento: 'monitoramento',
};

// exigência de quantidade por tipo de serviço.
// COLHEITA NÃO pede quantidade aqui (produção-só, 23/07): o colhido por
// classificação vai pelo 🧺 Registro de produção → tela Colheita do sistema.
function regraQtd(tipo) {
  if (tipo === 'poda' || tipo === 'tratos_culturais') {
    return { pede: true, rotulo: 'Plantas trabalhadas *', unidades: ['plantas'] };
  }
  return { pede: false };
}

export default function TarefaDetalheScreen() {
  const nav = useNavigation();
  const route = useRoute();
  const { sincronizarAgora } = useSync();
  const servico = route.params?.servico || null;

  // voz (Onda 3): texto transcrito pré-preenche a observação da conclusão
  const [obs, setObs] = useState(String(route.params?.vozTexto || ''));
  const [qtd, setQtd] = useState('');
  const [unidade, setUnidade] = useState(null);
  const [colabs, setColabs] = useState([]); // colaboradores + terceirizados (payload pronto)
  const [pessoasIniciais, setPessoasIniciais] = useState(null); // do rascunho
  const [pessoasSalvas, setPessoasSalvas] = useState(0); // itens no último "Salvar pessoas"
  const [concluido, setConcluido] = useState(false);

  // RASCUNHO por serviço (22/07): sair da tela não perde o preenchido —
  // carrega ao abrir, salva a cada mudança (debounce), apaga ao finalizar
  const chaveRascunho = servico ? `conclusao:${servico.id}` : null;
  const [rascunhoLido, setRascunhoLido] = useState(false);
  const salvarTimer = useRef(null);

  useEffect(() => {
    if (!chaveRascunho) return;
    lerRascunho(chaveRascunho)
      .then((r) => {
        if (r) {
          if (r.obs && !route.params?.vozTexto) setObs(r.obs);
          if (r.qtd) setQtd(r.qtd);
          if (r.unidade) setUnidade(r.unidade);
          if (Array.isArray(r.colabs) && r.colabs.length) setPessoasIniciais(r.colabs);
        }
      })
      .catch(() => {})
      .finally(() => setRascunhoLido(true));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chaveRascunho]);

  useEffect(() => {
    if (!chaveRascunho || !rascunhoLido || concluido) return undefined;
    clearTimeout(salvarTimer.current);
    salvarTimer.current = setTimeout(() => {
      gravarRascunho(chaveRascunho, { obs, qtd, unidade, colabs }).catch(() => {});
    }, 600);
    return () => clearTimeout(salvarTimer.current);
  }, [chaveRascunho, rascunhoLido, concluido, obs, qtd, unidade, colabs]);

  if (!servico) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Serviço" />
        <Tela>
          <Cartao>
            <Text style={styles.texto}>Serviço não encontrado. Volte à lista.</Text>
          </Cartao>
        </Tela>
      </View>
    );
  }

  const regra = regraQtd(servico.tipo);

  // espelho da colheita do sistema (23/07): a válvula carrega a VARIEDADE —
  // a quantidade informada aqui é a quantidade colhida DAQUELA variedade
  const { itens: valvulasCache } = useDadosSync('talhoes');
  const variedade = (() => {
    const v = (valvulasCache || []).find(
      (x) => String(x.talhao_id || x.id) === String(servico.talhao_id)
    );
    return v?.variedade || null;
  })();
  const rotuloQtd = servico.tipo === 'colheita' && variedade
    ? `Quantidade colhida — ${variedade} *`
    : regra.pede ? regra.rotulo : null;
  const unidadeAtiva = unidade || (regra.pede ? regra.unidades[0] : null);
  const qtdNum = dec(qtd);
  const qtdOk = !regra.pede || (qtdNum !== null && qtdNum > 0);
  const obsOk = obs.trim().length > 0;
  const pronto = qtdOk && obsOk;

  const faltando = !qtdOk
    ? (servico.tipo === 'colheita' ? 'Informe a quantidade colhida' : 'Informe as plantas trabalhadas')
    : !obsOk ? 'Descreva como foi a execução' : null;

  // "Salvar pessoas" — grava a produção SEM finalizar (o operador vai
  // adicionando ao longo do serviço; o app manda a lista COMPLETA atual,
  // o servidor faz replace — replay nunca duplica)
  function salvarPessoas() {
    if (colabs.length === 0) return;
    enfileirar({
      tipo: 'apontamento_producao',
      rota: rotas.apontamentoProducaoId(servico.id),
      metodo: 'POST',
      payload: { colaboradores: colabs },
    })
      .then(() => {
        setPessoasSalvas(colabs.length);
        sincronizarAgora().catch(() => {});
      })
      .catch(() => {});
  }

  function concluir() {
    if (!pronto) return;
    setConcluido(true);
    if (chaveRascunho) apagarRascunho(chaveRascunho).catch(() => {});
    const payload = { observacao: `Concluído pelo app — ${obs.trim()}` };
    if (regra.pede && qtdNum > 0) {
      payload.quantidade = qtdNum;
      payload.quantidade_unidade = unidadeAtiva === 'caixa' ? 'caixa' : unidadeAtiva === 'kg' ? 'kg' : 'plantas';
    }
    // finalizar também manda o estado ATUAL das pessoas (replace) — cobre
    // quem adicionou gente e finalizou sem tocar em "Salvar pessoas"
    if (colabs.length > 0) payload.colaboradores = colabs;
    enfileirar({
      tipo: 'apontamento_concluir',
      rota: rotas.apontamentoConcluirId(servico.id),
      metodo: 'POST',
      payload,
    })
      .then(() => { sincronizarAgora(); })
      .catch(() => {});
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo={`${servico.titulo} — ${servico.local}`} sub={`iniciado ${servico.inicio}`} />
      <Tela>
        {/* Cabeçalho do serviço iniciado pelo escritório */}
        <Cartao>
          <View style={styles.topo}>
            {TIPO_ICONE[servico.tipo]
              ? <Icone nome={TIPO_ICONE[servico.tipo]} tam={24} cor={cores.accent} />
              : <Text style={styles.ic}>{servico.ic}</Text>}
            <View style={{ flex: 1 }}>
              <Text style={styles.titulo}>{servico.titulo}</Text>
              <Text style={styles.meta}>
                {servico.local} · iniciado {servico.inicio}
                {servico.responsavel ? `\nresponsável: ${servico.responsavel}` : ''}
              </Text>
            </View>
            <Badge tipo={concluido ? 'ok' : 'at'}>
              {concluido ? '✓ Concluído' : 'Em execução'}
            </Badge>
          </View>
          {!!servico.observacao && (
            <Text style={styles.obsInicial}>{servico.observacao}</Text>
          )}
        </Cartao>

        {!concluido ? (
          <>
            {/* Quantidade — obrigatória por tipo (colheita/poda/tratos) */}
            {regra.pede && (
              <Cartao>
                <Eyebrow>{rotuloQtd}</Eyebrow>
                <View style={styles.qtdLinha}>
                  <TextInput
                    style={styles.qtdInput}
                    value={qtd}
                    onChangeText={setQtd}
                    keyboardType="numeric"
                    placeholder="0"
                    placeholderTextColor={cores.faint}
                  />
                  {regra.unidades.length > 1 ? (
                    regra.unidades.map((u) => (
                      <Chip key={u} selecionado={unidadeAtiva === u} onPress={() => setUnidade(u)}>
                        {u === 'caixa' ? 'caixas' : 'kg'}
                      </Chip>
                    ))
                  ) : (
                    <Text style={styles.qtdUnidade}>{regra.unidades[0]}</Text>
                  )}
                </View>
                {servico.tipo === 'colheita' && unidadeAtiva === 'caixa' && (
                  <Text style={styles.dica}>o sistema converte caixas em kg pela config da fazenda</Text>
                )}
              </Cartao>
            )}

            {/* Observação da execução — obrigatória */}
            <Cartao>
              <Eyebrow>Como foi a execução? *</Eyebrow>
              <TextInput
                style={styles.input}
                value={obs}
                onChangeText={setObs}
                multiline
                placeholder="Ex.: terminado até a fileira 12; faltou o fundo por causa da chuva…"
                placeholderTextColor={cores.faint}
              />
            </Cartao>

            {/* Pessoas do apontamento — adicionar e SALVAR ao longo do serviço */}
            <ProducaoPessoas
              unidade={servico.tipo === 'colheita' ? 'caixas' : 'plantas'}
              onChange={setColabs}
              inicial={pessoasIniciais}
            />
            {colabs.length > 0 && (
              <Botao
                variante="secundaria"
                titulo={pessoasSalvas === colabs.length
                  ? `✓ ${pessoasSalvas} ${pessoasSalvas === 1 ? 'pessoa salva' : 'pessoas salvas'} no apontamento`
                  : `💾 Salvar pessoas no apontamento (${colabs.length})`}
                onPress={salvarPessoas}
              />
            )}

            <Botao
              titulo={faltando || '✓ Finalizar apontamento'}
              disabled={!pronto}
              onPress={concluir}
            />
          </>
        ) : (
          <Cartao>
            <Text style={styles.texto}>
              ✅ Execução registrada — o apontamento ficou aguardando validação do escritório.
            </Text>
            <Botao
              titulo="‹ Voltar aos serviços"
              onPress={() => nav.goBack()}
              style={{ marginTop: 13 }}
            />
          </Cartao>
        )}
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  topo: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  ic: { fontSize: 22 },
  titulo: { fontSize: 15.5, color: cores.ink, fontFamily: fonte.sansBold },
  meta: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2, lineHeight: 16 },
  obsInicial: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansMed, marginTop: 11, lineHeight: 18 },
  qtdLinha: { flexDirection: 'row', alignItems: 'center', gap: 9, justifyContent: 'flex-end', marginTop: 8 },
  qtdInput: {
    width: 88, height: 42, borderRadius: raio.sm, backgroundColor: cores.campo,
    paddingHorizontal: 10, fontSize: 15, color: cores.ink, fontFamily: fonte.monoSemi, textAlign: 'center',
  },
  qtdUnidade: { fontSize: 13, color: cores.muted, fontFamily: fonte.sansSemi },
  dica: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 8 },
  input: {
    minHeight: 84, borderRadius: raio.sm, backgroundColor: cores.campo, padding: 12, marginTop: 8,
    fontSize: 13, color: cores.ink, fontFamily: fonte.sansMed, textAlignVertical: 'top',
  },
  texto: { fontSize: 13, color: cores.ink2, fontFamily: fonte.sansMed, lineHeight: 19 },
});
