import React, { useEffect, useMemo, useRef, useState } from 'react';
import { View, Text, Pressable, ScrollView, StyleSheet } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { cores, fonte, raio, espaco } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { useAuth } from '../context/AuthContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { Cartao, Botao, Input, Chip, TituloSecao, Badge } from '../components/ui';

// Romaneios de carga (colheita_cargas): o supervisor registra a carga que saiu
// do campo. Só registra e vê — corrigir/excluir e postar no estoque ficam no
// web. Escrita offline idempotente por client_uuid (rota /cargas).

const CLASSIFS = ['Premium', 'CAT 1', 'CAT 2', 'CAT 3', 'Perdidos'];
const UNIDADES = [{ k: 'caixa', t: 'Caixa' }, { k: 'palete', t: 'Palete' }, { k: 'cumbuca', t: 'Cumbuca' }];
const DESTINOS = [
  { k: 'venda', t: 'Venda' }, { k: 'packing', t: 'Packing' }, { k: 'armazenagem', t: 'Armazém' },
  { k: 'descarte', t: 'Descarte' }, { k: 'doacao', t: 'Doação' },
];
const CAIXAS_POR_PALETE = 110;
const SEQ_KEY = 'vero_romaneio_seq';

const numDec = (v) => {
  const n = Number(String(v).replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) ? n : 0;
};
const reais = (v) => (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
const dataBR = (v) => {
  const m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}` : '';
};
const yymmdd = () => new Date().toISOString().slice(2, 10).replace(/-/g, '');

// próximo número sugerido: A{idUsuario}-{AAMMDD}-{NN} (contador local por dia).
// Namespace por usuário evita colisão entre supervisores offline; o web usa
// outro formato. É só sugestão — o supervisor pode digitar o nº do talão.
async function proximoRomaneio(userId) {
  const dia = yymmdd();
  let seq = 1;
  try {
    const raw = await SecureStore.getItemAsync(SEQ_KEY);
    const st = raw ? JSON.parse(raw) : null;
    if (st && st.dia === dia && Number.isFinite(st.seq)) seq = st.seq + 1;
  } catch (_) { /* usa 1 */ }
  const nn = String(seq).padStart(2, '0');
  return { numero: `A${userId || 0}-${dia}-${nn}`, dia, seq };
}
async function consumirRomaneio(dia, seq) {
  try { await SecureStore.setItemAsync(SEQ_KEY, JSON.stringify({ dia, seq })); } catch (_) { /* nada */ }
}

export default function CargasColheitaScreen() {
  const { usuario, pode } = useAuth();
  const podeEditar = pode('agro.romaneios_colheita.editar');
  const { sincronizarAgora } = useSync();
  const { itens: cargas } = useDadosSync('cargas_colheita');
  const { itens: valvulas } = useDadosSync('talhoes');
  const { itens: registros } = useDadosSync('colheitas_pendentes');
  const { itens: safras } = useDadosSync('safras');
  const { itens: fenologia } = useDadosSync('fenologia');

  // sugestão de romaneio (recalcula ao montar e após cada envio)
  const sugestaoRef = useRef({ dia: yymmdd(), seq: 0 });
  const [romaneio, setRomaneio] = useState('');
  const [romaneioTocado, setRomaneioTocado] = useState(false);

  const [fazenda, setFazenda] = useState(null); // chave da fazenda (filtro de UI)
  const [buscaVal, setBuscaVal] = useState('');
  const [valvula, setValvula] = useState(null); // {id, ...}
  const [peso, setPeso] = useState('');
  const [classif, setClassif] = useState(null);
  const [unidade, setUnidade] = useState(null);
  const [qtd, setQtd] = useState('');
  const [destino, setDestino] = useState(null);
  const [registroId, setRegistroId] = useState(null);
  const [enviando, setEnviando] = useState(false);
  const [recemEnviadas, setRecemEnviadas] = useState([]); // romaneios da sessão (feedback imediato)

  async function recalcularSugestao() {
    const r = await proximoRomaneio(usuario?.id);
    sugestaoRef.current = { dia: r.dia, seq: r.seq };
    if (!romaneioTocado) setRomaneio(r.numero);
  }
  useEffect(() => { recalcularSugestao(); /* eslint-disable-line */ }, [usuario?.id]);

  // Fazenda por válvula, 100% offline. FONTE PRIMÁRIA (sync ≥ 20/08): o
  // /sync/talhoes entrega fazenda_id + nome direto em cada válvula. FALLBACK
  // (cache antigo que ainda não ressincronizou): deriva de fenologia
  // (talhao→safra) × safras (safra→fazenda_id) e das cargas dos últimos
  // 60 dias (talhao→NOME da fazenda). Quando nada é conhecido (ou só existe
  // 1 fazenda), o filtro não aparece e a tela segue exatamente como antes.
  const { fazendas, fazendaDoTalhao } = useMemo(() => {
    const idDoTalhao = new Map();    // talhao_id -> fazenda_id
    const nomeDaFazenda = new Map(); // fazenda_id -> nome
    // primária: campos diretos da válvula sincronizada
    for (const v of valvulas || []) {
      if (v.talhao_id == null || v.fazenda_id == null) continue;
      const fid = String(v.fazenda_id);
      idDoTalhao.set(String(v.talhao_id), fid);
      const nome = String(v.fazenda || '').trim();
      if (nome && !nomeDaFazenda.has(fid)) nomeDaFazenda.set(fid, nome);
    }
    // fallback 1: fenologia × safras (nunca sobrescreve a primária)
    const fazDaSafra = new Map(); // safra_id -> fazenda_id
    for (const s of safras || []) {
      if (s.fazenda_id != null) fazDaSafra.set(String(s.id), String(s.fazenda_id));
    }
    for (const f of fenologia || []) {
      const fid = fazDaSafra.get(String(f.safra_id));
      if (fid && f.talhao_id != null && !idDoTalhao.has(String(f.talhao_id))) {
        idDoTalhao.set(String(f.talhao_id), fid);
      }
    }
    // fallback 2: cargas recentes dão o NOME por talhão
    const nomeDoTalhao = new Map(); // talhao_id -> nome da fazenda
    for (const c of cargas || []) {
      const nome = String(c.fazenda || '').trim();
      if (c.talhao_id != null && nome) nomeDoTalhao.set(String(c.talhao_id), nome);
    }
    // nomes aprendidos: talhão com id E nome ensina fazenda_id -> nome
    const idDoNome = new Map();
    for (const [fid, nome] of nomeDaFazenda) idDoNome.set(nome.toLowerCase(), fid);
    for (const [tal, fid] of idDoTalhao) {
      const nome = nomeDoTalhao.get(tal);
      if (nome && !nomeDaFazenda.has(fid)) {
        nomeDaFazenda.set(fid, nome);
        idDoNome.set(nome.toLowerCase(), fid);
      }
    }
    const fazendaDoTalhao = new Map(); // talhao_id -> chave ('id:N' | 'nm:x')
    const rotulos = new Map();         // chave -> rótulo exibido
    for (const [tal, fid] of idDoTalhao) {
      fazendaDoTalhao.set(tal, `id:${fid}`);
      rotulos.set(`id:${fid}`, nomeDaFazenda.get(fid) || `Fazenda ${fid}`);
    }
    for (const [tal, nome] of nomeDoTalhao) {
      if (fazendaDoTalhao.has(tal)) continue;
      const fid = idDoNome.get(nome.toLowerCase());
      const chave = fid ? `id:${fid}` : `nm:${nome.toLowerCase()}`;
      fazendaDoTalhao.set(tal, chave);
      if (!rotulos.has(chave)) rotulos.set(chave, nome);
    }
    // lista só as fazendas com pelo menos uma válvula mapeada
    const usadas = new Set();
    for (const v of valvulas || []) {
      const chave = fazendaDoTalhao.get(String(v.talhao_id));
      if (chave) usadas.add(chave);
    }
    const fazendas = [...usadas]
      .map((chave) => ({ chave, rotulo: rotulos.get(chave) || 'Fazenda' }))
      .sort((a, b) => a.rotulo.localeCompare(b.rotulo, 'pt-BR'));
    return { fazendas, fazendaDoTalhao };
  }, [safras, fenologia, cargas, valvulas]);

  const valvulasDaFazenda = useMemo(() => {
    if (!fazenda) return valvulas || [];
    return (valvulas || []).filter((v) => fazendaDoTalhao.get(String(v.talhao_id)) === fazenda);
  }, [valvulas, fazenda, fazendaDoTalhao]);

  function trocarFazenda(chave) {
    const nova = fazenda === chave ? null : chave;
    setFazenda(nova);
    // válvula já escolhida de OUTRA fazenda não pode ficar: limpa a seleção
    if (nova && valvula && fazendaDoTalhao.get(String(valvula.talhao_id)) !== nova) {
      setValvula(null);
      setBuscaVal('');
    }
  }

  const valvulasAchadas = useMemo(() => {
    const q = buscaVal.trim().toLowerCase();
    // com fazenda escolhida, já lista as válvulas dela sem precisar digitar
    if (q.length < 1) return fazenda ? valvulasDaFazenda.slice(0, 8) : [];
    return valvulasDaFazenda
      .filter((v) => `${v.nome || ''} ${v.codigo || ''} ${v.talhao_nome || ''}`.toLowerCase().includes(q))
      .slice(0, 8);
  }, [buscaVal, valvulasDaFazenda, fazenda]);

  // registros de colheita do dia da válvula escolhida (vínculo opcional)
  const registrosDaValvula = useMemo(() => {
    if (!valvula) return [];
    return (registros || []).filter((r) => r.talhao_id === (valvula.talhao_id || valvula.id)).slice(0, 6);
  }, [registros, valvula]);

  // já existe carga com esse romaneio na base sincronizada? (aviso pré-envio)
  const romaneioJaExiste = useMemo(() => {
    const r = romaneio.trim().toLowerCase();
    if (!r) return false;
    return (cargas || []).some((c) => String(c.romaneio || '').toLowerCase() === r)
      || recemEnviadas.some((c) => c.romaneio.toLowerCase() === r);
  }, [romaneio, cargas, recemEnviadas]);

  const pesoN = numDec(peso);
  const pronto = podeEditar && romaneio.trim() && valvula && pesoN > 0 && !romaneioJaExiste && !enviando;

  function limpar(proximoNumero) {
    setValvula(null); setBuscaVal(''); setPeso(''); setClassif(null);
    setUnidade(null); setQtd(''); setDestino(null); setRegistroId(null);
    setRomaneioTocado(false); setRomaneio(proximoNumero || '');
  }

  async function enviar() {
    if (!pronto) return;
    setEnviando(true);
    try {
      const num = romaneio.trim();
      await enfileirar({
        tipo: 'carga_colheita',
        rota: rotas.cargas,
        metodo: 'POST',
        payload: {
          romaneio: num,
          setor_id: valvula.id,
          peso_kg: pesoN,
          classificacao: classif || undefined,
          unidade_apont: unidade || undefined,
          qtd_apont: unidade ? numDec(qtd) || undefined : undefined,
          caixas_por_palete: unidade === 'caixa' || unidade === 'palete' ? CAIXAS_POR_PALETE : undefined,
          destino: destino || undefined,
          registro_id: registroId || undefined,
        },
      });
      // consome o sequencial só se o número enviado foi a sugestão gerada
      if (!romaneioTocado) await consumirRomaneio(sugestaoRef.current.dia, sugestaoRef.current.seq);
      setRecemEnviadas((xs) => [{ romaneio: num, valvula: rotuloValvula(valvula), peso: pesoN }, ...xs]);
      sincronizarAgora().catch(() => {});
      const prox = await proximoRomaneio(usuario?.id);
      sugestaoRef.current = { dia: prox.dia, seq: prox.seq };
      limpar(prox.numero);
    } finally {
      setEnviando(false);
    }
  }

  function rotuloValvula(v) {
    if (!v) return '';
    return v.nome || v.codigo || v.talhao_nome || `válvula ${v.id}`;
  }

  // lista: recém-enviadas (pendentes) + cargas sincronizadas
  const listaSync = useMemo(() => (
    [...(cargas || [])].sort((a, b) => String(b.data_carga || '').localeCompare(String(a.data_carga || '')))
  ), [cargas]);

  if (!podeEditar && !pode('agro.romaneios_colheita.ver')) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Cargas" sub="Romaneios de colheita" />
        <View style={styles.corpo}><Cartao><Text style={styles.sub}>Você não tem acesso aos romaneios de colheita.</Text></Cartao></View>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Cargas" sub="Romaneios de colheita" />
      <ScrollView contentContainerStyle={styles.corpo} keyboardShouldPersistTaps="handled">
        {podeEditar && (
          <Cartao>
            <TituloSecao>Nova carga</TituloSecao>

            {/* romaneio (sugerido, editável) */}
            <Text style={styles.rot}>Romaneio</Text>
            <Input
              value={romaneio}
              onChangeText={(v) => { setRomaneio(v); setRomaneioTocado(true); }}
              placeholder="número do romaneio"
              autoCapitalize="characters"
            />
            {romaneioJaExiste && <Text style={styles.aviso}>Já existe uma carga com este romaneio. Use outro número.</Text>}

            {/* fazenda (filtro de UI — só aparece com 2+ fazendas conhecidas) */}
            {fazendas.length >= 2 && (
              <>
                <Text style={styles.rot}>Fazenda</Text>
                <View style={styles.chips}>
                  <Chip tamanho="toque" selecionado={!fazenda} onPress={() => setFazenda(null)}>Todas</Chip>
                  {fazendas.map((f) => (
                    <Chip key={f.chave} tamanho="toque" selecionado={fazenda === f.chave} onPress={() => trocarFazenda(f.chave)}>
                      {f.rotulo}
                    </Chip>
                  ))}
                </View>
              </>
            )}

            {/* válvula */}
            <Text style={styles.rot}>Válvula</Text>
            {valvula ? (
              <Pressable style={styles.selecionado} onPress={() => setValvula(null)}>
                <Icone nome="irrigacao" tam={15} cor={cores.accent} />
                <Text style={styles.selecionadoTxt}>{rotuloValvula(valvula)}</Text>
                <Text style={styles.trocar}>trocar</Text>
              </Pressable>
            ) : (
              <>
                <Input
                  value={buscaVal}
                  onChangeText={setBuscaVal}
                  placeholder={fazenda ? 'Buscar válvula da fazenda…' : 'Buscar válvula/talhão…'}
                />
                {valvulasAchadas.map((v) => (
                  <Pressable key={v.id} style={styles.achado} onPress={() => { setValvula(v); setBuscaVal(''); }}>
                    <Text style={styles.achadoNome}>{rotuloValvula(v)}{v.talhao_nome ? ` · ${v.talhao_nome}` : ''}</Text>
                    <Text style={styles.achadoAdd}>selecionar</Text>
                  </Pressable>
                ))}
              </>
            )}

            {/* vínculo opcional a um registro de colheita do dia */}
            {registrosDaValvula.length > 0 && (
              <>
                <Text style={styles.rot}>Vincular ao registro de colheita (opcional)</Text>
                <View style={styles.chips}>
                  <Chip selecionado={!registroId} onPress={() => setRegistroId(null)}>Sem vínculo</Chip>
                  {registrosDaValvula.map((r) => (
                    <Chip key={r.id} selecionado={registroId === r.id} onPress={() => setRegistroId(r.id)}>
                      {dataBR(r.data_colheita)}{r.variedade ? ` · ${r.variedade}` : ''}
                    </Chip>
                  ))}
                </View>
              </>
            )}

            {/* peso */}
            <Text style={styles.rot}>Peso (kg)</Text>
            <Input value={peso} onChangeText={(v) => setPeso(v.replace(/[^\d.,]/g, ''))} keyboardType="decimal-pad" placeholder="0" />

            {/* classificação */}
            <Text style={styles.rot}>Classificação (opcional)</Text>
            <View style={styles.chips}>
              {CLASSIFS.map((c) => (
                <Chip key={c} selecionado={classif === c} onPress={() => setClassif(classif === c ? null : c)}>{c}</Chip>
              ))}
            </View>

            {/* unidade + quantidade */}
            <Text style={styles.rot}>Apontamento por unidade (opcional)</Text>
            <View style={styles.chips}>
              {UNIDADES.map((u) => (
                <Chip key={u.k} selecionado={unidade === u.k} onPress={() => setUnidade(unidade === u.k ? null : u.k)}>{u.t}</Chip>
              ))}
            </View>
            {!!unidade && (
              <>
                <Input value={qtd} onChangeText={(v) => setQtd(v.replace(/[^\d.,]/g, ''))} keyboardType="decimal-pad" placeholder={`quantidade em ${unidade}s`} />
                {unidade === 'caixa' && numDec(qtd) > 0 && (
                  <Text style={styles.hint}>≈ {(numDec(qtd) / CAIXAS_POR_PALETE).toFixed(2)} palete(s) · {CAIXAS_POR_PALETE} cx/pal</Text>
                )}
                {unidade === 'palete' && numDec(qtd) > 0 && (
                  <Text style={styles.hint}>= {Math.round(numDec(qtd) * CAIXAS_POR_PALETE)} caixas · {CAIXAS_POR_PALETE} cx/pal</Text>
                )}
              </>
            )}

            {/* destino */}
            <Text style={styles.rot}>Destino (opcional)</Text>
            <View style={styles.chips}>
              {DESTINOS.map((d) => (
                <Chip key={d.k} selecionado={destino === d.k} onPress={() => setDestino(destino === d.k ? null : d.k)}>{d.t}</Chip>
              ))}
            </View>

            <Botao
              titulo={pronto ? 'Registrar carga' : (romaneioJaExiste ? 'Romaneio já existe' : 'Preencha romaneio, válvula e peso')}
              disabled={!pronto}
              onPress={enviar}
              style={{ marginTop: 14 }}
            />
          </Cartao>
        )}

        {/* lista de cargas */}
        <TituloSecao>Cargas recentes</TituloSecao>
        {recemEnviadas.map((c, i) => (
          <Cartao key={`nova-${c.romaneio}-${i}`} style={styles.itemCard}>
            <View style={{ flex: 1 }}>
              <Text style={styles.doc}>{c.romaneio}</Text>
              <Text style={styles.sub}>{c.valvula} · {reais(c.peso)} kg</Text>
            </View>
            <Badge bg={cores.amberBg} fg={cores.amberDeep}>Enviando</Badge>
          </Cartao>
        ))}
        {listaSync.length === 0 && recemEnviadas.length === 0 && (
          <Cartao><Text style={styles.sub}>Nenhuma carga nos últimos 60 dias.</Text></Cartao>
        )}
        {listaSync.map((c) => (
          <Cartao key={String(c.id)} style={styles.itemCard}>
            <View style={{ flex: 1 }}>
              <Text style={styles.doc}>{c.romaneio}</Text>
              <Text style={styles.sub}>
                {c.talhao || c.fazenda || 'válvula —'} · {reais(c.peso_kg)} kg
                {c.classificacao ? ` · ${c.classificacao}` : ''}
              </Text>
            </View>
            <View style={{ alignItems: 'flex-end', gap: 4 }}>
              <Text style={styles.data}>{dataBR(c.data_carga)}</Text>
              {c.origem === 'app' && <Badge bg={cores.track} fg={cores.muted2}>app</Badge>}
            </View>
          </Cartao>
        ))}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  corpo: { padding: espaco.md, paddingBottom: 96, gap: 10 },
  rot: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansSemi, marginTop: 12, marginBottom: 6 },
  aviso: { fontSize: 12, color: cores.danger, fontFamily: fonte.sansMed, marginTop: 6 },
  hint: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi, marginTop: 6 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  selecionado: {
    flexDirection: 'row', alignItems: 'center', gap: 8,
    backgroundColor: cores.campo, borderRadius: raio.sm, paddingHorizontal: 12, paddingVertical: 11,
  },
  selecionadoTxt: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  trocar: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },
  achado: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
    backgroundColor: cores.campo, borderRadius: raio.sm, paddingHorizontal: 12, paddingVertical: 11, marginTop: 6,
  },
  achadoNome: { flex: 1, fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  achadoAdd: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },
  itemCard: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  doc: { fontSize: 13.5, color: cores.ink, fontFamily: fonte.monoSemi },
  sub: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 3 },
  data: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.monoSemi },
});
