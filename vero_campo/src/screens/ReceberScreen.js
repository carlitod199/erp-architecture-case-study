import React, { useMemo, useState } from 'react';
import { View, Text, TextInput, Pressable, ScrollView, Alert, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import CarregandoVero from '../components/CarregandoVero';
import Scanner from '../components/Scanner';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { useAuth } from '../context/AuthContext';
import { papelApp } from '../components/ui';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { cores, fonte, raio, espaco } from '../theme';

// F5 (Onda 2) — Conferência de insumo no recebimento: ler QR/código →
// identificar o produto no cache de estoque → conferir quantidade × NF →
// lote/validade → confirmar. Confirmar = POST /recebimentos/confirmar na
// fila offline (entrada de estoque no ERP na sincronização).
// ATENÇÃO: a rota exige estoque.entradas.editar — operador puro pode ter o
// envio recusado (403 vira falha definitiva na fila); avisamos, não bloqueamos.

const dec = (v) => {
  const n = parseFloat(String(v || '').trim().replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

const fmtQtd = (n) =>
  (Number(n) || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });

const DATA_RE = /^\d{4}-\d{2}-\d{2}$/;

// 'YYYY-MM-DD' daqui a N meses (chips de validade +6m/+12m/+24m)
function mesesAFrente(n) {
  const d = new Date();
  d.setMonth(d.getMonth() + n);
  const p = (x) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
const VALIDADES = [
  { rotulo: '+6 meses', meses: 6 },
  { rotulo: '+12 meses', meses: 12 },
  { rotulo: '+24 meses', meses: 24 },
];

// Interpreta o conteúdo lido: etiquetas simples trazem só o código; rótulos
// de fabricante podem trazer "CODIGO|LOTE|VALIDADE" (ou ';') ou um JSON
// {codigo, lote, validade} — lote/validade pré-preenchem o formulário.
function interpretarEtiqueta(bruto) {
  const s = String(bruto || '').trim();
  if (s.startsWith('{')) {
    try {
      const o = JSON.parse(s);
      return {
        codigo: String(o.codigo || o.produto || o.id || '').trim(),
        lote: o.lote != null ? String(o.lote).trim() : '',
        validade: DATA_RE.test(String(o.validade || '').trim()) ? String(o.validade).trim() : '',
      };
    } catch { /* segue como texto simples */ }
  }
  const partes = s.split(/[|;]/).map((p) => p.trim()).filter(Boolean);
  if (partes.length > 1) {
    return {
      codigo: partes[0],
      lote: partes[1] || '',
      validade: DATA_RE.test(partes[2] || '') ? partes[2] : '',
    };
  }
  return { codigo: s, lote: '', validade: '' };
}

const FORM_VAZIO = {
  quantidade: '', nf: '', lote: '', validade: '', divergencia: false, observacao: '',
};

export default function ReceberScreen() {
  const nav = useNavigation();
  const { usuario } = useAuth();
  const { itens, carregado } = useDadosSync('estoque');
  const { sincronizando, sincronizarAgora } = useSync();

  const [scanner, setScanner] = useState(false);
  const [busca, setBusca] = useState('');
  const [produto, setProduto] = useState(null);
  const [form, setForm] = useState(FORM_VAZIO);
  const [sucesso, setSucesso] = useState(false);

  const papel = papelApp(usuario);

  // matching etiqueta → produto: mesmo critério da tela de Estoque (7.4)
  function aoLerCodigo(bruto) {
    setScanner(false);
    const { codigo, lote, validade } = interpretarEtiqueta(bruto);
    const p = (itens || []).find(
      (x) => String(x.codigo || '').toLowerCase() === codigo.toLowerCase()
        || String(x.id) === codigo
    );
    if (p) {
      setProduto(p);
      setForm({ ...FORM_VAZIO, lote, validade });
    } else {
      Alert.alert(
        'Código não encontrado',
        `Nenhum produto com o código "${codigo}" no aparelho. Sincronize ou busque pelo nome.`
      );
    }
  }

  function escolherManual(p) {
    setProduto(p);
    setForm(FORM_VAZIO);
    setBusca('');
  }

  function resetar() {
    setProduto(null);
    setForm(FORM_VAZIO);
    setBusca('');
    setSucesso(false);
  }

  // busca manual por nome/código (rótulo sem código de barras)
  const encontrados = useMemo(() => {
    const q = busca.trim().toLowerCase();
    if (q.length < 2) return [];
    return (itens || [])
      .filter((i) => String(i.nome || '').toLowerCase().includes(q)
        || String(i.codigo || '').toLowerCase().includes(q))
      .slice(0, 8);
  }, [itens, busca]);

  const qtd = dec(form.quantidade);
  const qtdOk = qtd !== null && qtd > 0;
  const validadePreenchida = form.validade.trim().length > 0;
  const validadeOk = !validadePreenchida || DATA_RE.test(form.validade.trim());
  const obsObrigatoriaOk = !form.divergencia || form.observacao.trim().length > 0;
  const podeConfirmar = !!produto && qtdOk && validadeOk && obsObrigatoriaOk;

  // Payload da fila: POST /recebimentos/confirmar (contrato smoked pela Onda 2)
  async function confirmar() {
    if (!podeConfirmar) return;
    await enfileirar({
      tipo: 'recebimento',
      rota: rotas.recebimentoConfirmar,
      metodo: 'POST',
      payload: {
        produto_id: produto.id,
        quantidade: qtd,
        lote: form.lote.trim() || null,
        validade: form.validade.trim() || null,
        nf_numero: form.nf.trim() || null,
        divergencia: !!form.divergencia,
        observacao: form.observacao.trim() || null,
      },
    });
    setSucesso(true);
    // não bloqueia a tela de sucesso: offline, o registro sobe depois sozinho
    sincronizarAgora().catch(() => {});
  }

  const cacheVazio = carregado && (itens || []).length === 0;

  // ---- tela de sucesso ----
  if (sucesso) {
    return (
      <View style={styles.tela}>
        <AppHeader titulo="Receber insumo" sub="Conferência no recebimento" />
        <View style={styles.sucessoWrap}>
          <View style={styles.sucessoCard}>
            <Text style={styles.sucessoIcone}>✓</Text>
            <Text style={styles.sucessoTitulo}>Recebimento na fila</Text>
            <Text style={styles.sucessoMsg}>
              {produto?.nome}{'\n'}
              {fmtQtd(qtd)} {produto?.unidade || ''} — o estoque atualiza na sincronização.
            </Text>
            <Pressable style={styles.btnPrim} onPress={resetar}>
              <Text style={styles.btnPrimTxt}>Receber outro</Text>
            </Pressable>
            <Pressable style={styles.btnGhost} onPress={() => nav.goBack()}>
              <Text style={styles.btnGhostTxt}>Voltar</Text>
            </Pressable>
          </View>
        </View>
      </View>
    );
  }

  return (
    <View style={styles.tela}>
      <AppHeader titulo="Receber insumo" sub="Conferência no recebimento" />

      <Scanner
        visivel={scanner}
        titulo="Aponte para o código do produto"
        aoLer={aoLerCodigo}
        aoFechar={() => setScanner(false)}
      />

      <ScrollView
        contentContainerStyle={styles.corpo}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        {/* aviso de permissão: papéis do ERP variam — avisa, não bloqueia */}
        {papel === 'operador' && (
          <View style={styles.avisoPerm}>
            <Text style={styles.avisoPermTxt}>
              ⚠️ Confirmação de recebimento exige perfil de almoxarife/gestor — o envio pode ser recusado.
            </Text>
          </View>
        )}

        {!carregado ? (
          <View style={{ alignItems: 'center', marginTop: 40 }}><CarregandoVero /></View>
        ) : cacheVazio ? (
          <View style={styles.card}>
            <Text style={styles.nomeProduto}>Nenhum produto no aparelho</Text>
            <Text style={[styles.detalheProduto, { marginTop: 6 }]}>
              Conecte-se à internet e sincronize para carregar o estoque da fazenda.
            </Text>
            <Pressable
              style={[styles.btnPrim, { marginTop: 12 }, sincronizando && { opacity: 0.7 }]}
              onPress={sincronizarAgora}
              disabled={sincronizando}
            >
              <Text style={styles.btnPrimTxt}>
                {sincronizando ? 'Sincronizando…' : 'Sincronizar agora'}
              </Text>
            </Pressable>
          </View>
        ) : !produto ? (
          <>
            {/* passo 1 — identificar o produto */}
            <Pressable style={styles.btnScan} onPress={() => setScanner(true)}>
              <Text style={styles.btnScanTxt}>📷 Ler código do produto</Text>
            </Pressable>

            <Text style={styles.ou}>ou busque pelo nome (rótulo sem código)</Text>
            <TextInput
              style={styles.busca}
              value={busca}
              onChangeText={setBusca}
              placeholder="Nome ou código do produto…"
              placeholderTextColor={cores.muted2}
              returnKeyType="search"
            />

            {busca.trim().length >= 2 && (
              encontrados.length > 0 ? (
                <View style={{ gap: espaco.sm }}>
                  {encontrados.map((p) => (
                    <Pressable
                      key={String(p.id)}
                      style={({ pressed }) => [styles.card, styles.cardBusca, pressed && { opacity: 0.9 }]}
                      onPress={() => escolherManual(p)}
                    >
                      <View style={{ flex: 1 }}>
                        <Text style={styles.nomeProduto}>{p.nome}</Text>
                        <Text style={styles.detalheProduto}>
                          {p.codigo ? `${p.codigo} · ` : ''}saldo {fmtQtd(p.saldo)} {p.unidade || ''}
                        </Text>
                      </View>
                      <Text style={styles.seta}>＋</Text>
                    </Pressable>
                  ))}
                </View>
              ) : (
                <Text style={styles.vazio}>Nenhum produto encontrado no aparelho.</Text>
              )
            )}
          </>
        ) : (
          <>
            {/* passo 2 — contexto do produto identificado */}
            <View style={[styles.card, styles.cardProduto]}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: espaco.sm }}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.nomeProduto}>{produto.nome}</Text>
                  <Text style={styles.detalheProduto}>
                    {produto.codigo ? `${produto.codigo} · ` : ''}saldo atual{' '}
                    <Text style={styles.saldoMono}>{fmtQtd(produto.saldo)}</Text> {produto.unidade || ''}
                  </Text>
                </View>
                <Pressable style={styles.btnTrocar} onPress={resetar} hitSlop={6}>
                  <Text style={styles.btnTrocarTxt}>Trocar</Text>
                </Pressable>
              </View>
            </View>

            {/* passo 3 — conferência contra a NF */}
            <View style={styles.card}>
              <View style={styles.duplo}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.campoRotulo}>Qtd. recebida ({produto.unidade || 'un'}) *</Text>
                  <TextInput
                    style={styles.input}
                    value={form.quantidade}
                    onChangeText={(t) => setForm((f) => ({ ...f, quantidade: t }))}
                    keyboardType="numeric"
                    placeholder="0,0"
                    placeholderTextColor={cores.faint}
                  />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.campoRotulo}>Nº da NF</Text>
                  <TextInput
                    style={styles.input}
                    value={form.nf}
                    onChangeText={(t) => setForm((f) => ({ ...f, nf: t }))}
                    placeholder="000000"
                    placeholderTextColor={cores.faint}
                  />
                </View>
              </View>

              <View>
                <Text style={styles.campoRotulo}>Lote</Text>
                <TextInput
                  style={[styles.input, styles.inputTexto]}
                  value={form.lote}
                  onChangeText={(t) => setForm((f) => ({ ...f, lote: t }))}
                  placeholder="Lote do fabricante"
                  placeholderTextColor={cores.faint}
                  autoCapitalize="characters"
                />
              </View>

              <View>
                <Text style={styles.campoRotulo}>Validade</Text>
                <View style={styles.chips}>
                  {VALIDADES.map((v) => {
                    const valor = mesesAFrente(v.meses);
                    const ativa = form.validade === valor;
                    return (
                      <Pressable
                        key={v.rotulo}
                        style={[styles.chip, ativa && styles.chipAtivo]}
                        onPress={() => setForm((f) => ({ ...f, validade: ativa ? '' : valor }))}
                      >
                        <Text style={[styles.chipTxt, ativa && { color: '#fff' }]}>{v.rotulo}</Text>
                      </Pressable>
                    );
                  })}
                </View>
                <TextInput
                  style={[styles.input, { marginTop: espaco.sm }, validadePreenchida && !validadeOk && styles.inputErro]}
                  value={form.validade}
                  onChangeText={(t) => setForm((f) => ({ ...f, validade: t }))}
                  placeholder="AAAA-MM-DD"
                  placeholderTextColor={cores.faint}
                />
                {validadePreenchida && !validadeOk && (
                  <Text style={styles.erroTxt}>Use o formato AAAA-MM-DD (ex.: 2027-01-22).</Text>
                )}
              </View>
            </View>

            {/* divergência com a NF */}
            <View style={styles.card}>
              <Pressable
                style={styles.linhaToggle}
                onPress={() => setForm((f) => ({ ...f, divergencia: !f.divergencia }))}
                accessibilityRole="switch"
                accessibilityState={{ checked: form.divergencia }}
              >
                <View style={{ flex: 1 }}>
                  <Text style={styles.toggleRotulo}>Divergência com a NF?</Text>
                  <Text style={styles.toggleSub}>
                    Quantidade, produto ou estado diferente do que a nota descreve.
                  </Text>
                </View>
                <View style={[styles.trilho, form.divergencia && styles.trilhoOn]}>
                  <View style={[styles.polegar, form.divergencia && styles.polegarOn]} />
                </View>
              </Pressable>

              <View style={{ marginTop: espaco.md }}>
                <Text style={styles.campoRotulo}>
                  Observação{form.divergencia ? ' * (descreva a divergência)' : ''}
                </Text>
                <TextInput
                  style={[
                    styles.input, styles.inputArea,
                    form.divergencia && !obsObrigatoriaOk && styles.inputErro,
                  ]}
                  value={form.observacao}
                  onChangeText={(t) => setForm((f) => ({ ...f, observacao: t }))}
                  placeholder={form.divergencia
                    ? 'O que veio diferente da NF?'
                    : 'Opcional — anotações do recebimento'}
                  placeholderTextColor={cores.faint}
                  multiline
                  textAlignVertical="top"
                />
              </View>
            </View>

            <Pressable
              style={[styles.btnConfirmar, !podeConfirmar && { opacity: 0.4 }]}
              disabled={!podeConfirmar}
              onPress={confirmar}
            >
              <Text style={styles.btnConfirmarTxt}>Confirmar recebimento</Text>
            </Pressable>
            <Text style={styles.dica}>
              A entrada de estoque é lançada no ERP na sincronização — sem sinal, fica guardada na fila.
            </Text>
          </>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cores.page },
  corpo: { padding: espaco.md, paddingBottom: 96, gap: espaco.md },

  // aviso de permissão (âmbar)
  avisoPerm: {
    backgroundColor: cores.amberBg, borderRadius: raio.r, padding: 12,
    borderLeftWidth: 4, borderLeftColor: cores.amber,
  },
  avisoPermTxt: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansSemi, lineHeight: 17 },

  // passo 1 — scan / busca manual
  btnScan: {
    minHeight: 58, borderRadius: raio.card, backgroundColor: cores.sidebar,
    alignItems: 'center', justifyContent: 'center', paddingHorizontal: espaco.lg,
  },
  btnScanTxt: { fontSize: 15.5, color: '#fff', fontFamily: fonte.sansBold },
  ou: { fontSize: 11.5, color: cores.muted2, fontFamily: fonte.sansMed, textAlign: 'center' },
  busca: {
    height: espaco.toque, borderRadius: raio.r, backgroundColor: cores.surface,
    paddingHorizontal: 14, fontSize: 13.5, color: cores.ink, fontFamily: fonte.sansMed,
    borderWidth: 1, borderColor: cores.border,
  },
  vazio: { textAlign: 'center', marginTop: espaco.sm, fontSize: 13, color: cores.muted, fontFamily: fonte.sansMed },

  // cards
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 14, gap: espaco.sm },
  cardBusca: { flexDirection: 'row', alignItems: 'center', minHeight: espaco.toque },
  cardProduto: { borderWidth: 1, borderColor: cores.accent },
  nomeProduto: { fontSize: 14.5, color: cores.ink, fontFamily: fonte.sansBold },
  detalheProduto: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2 },
  saldoMono: { fontFamily: fonte.monoSemi, color: cores.ink },
  seta: { fontSize: 18, color: cores.accent, fontFamily: fonte.sansBold, paddingHorizontal: 4 },
  btnTrocar: {
    minHeight: 38, borderRadius: raio.sm, paddingHorizontal: 12,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: cores.campo,
  },
  btnTrocarTxt: { fontSize: 12, color: cores.accent, fontFamily: fonte.sansBold },

  // formulário
  campoRotulo: {
    fontSize: 10.5, letterSpacing: 0.8, textTransform: 'uppercase',
    color: cores.muted2, fontFamily: fonte.sansBold, marginBottom: 6,
  },
  duplo: { flexDirection: 'row', gap: espaco.sm },
  input: {
    height: espaco.toque, borderRadius: raio.sm, backgroundColor: cores.campo,
    paddingHorizontal: 12, fontSize: 16, color: cores.ink, fontFamily: fonte.monoSemi,
  },
  inputTexto: { fontSize: 14, fontFamily: fonte.sansMed },
  inputArea: {
    height: 92, paddingTop: 10, fontSize: 13.5, fontFamily: fonte.sansMed,
  },
  inputErro: { borderWidth: 1.5, borderColor: cores.danger },
  erroTxt: { fontSize: 11, color: cores.danger, fontFamily: fonte.sansMed, marginTop: 4 },
  chips: { flexDirection: 'row', gap: espaco.xs + 2 },
  chip: {
    paddingHorizontal: 14, height: 40, borderRadius: raio.pill, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  chipAtivo: { backgroundColor: cores.accent },
  chipTxt: { fontSize: 12.5, color: cores.ink2, fontFamily: fonte.sansSemi },

  // toggle de divergência
  linhaToggle: { flexDirection: 'row', alignItems: 'center', gap: espaco.md, minHeight: espaco.toque },
  toggleRotulo: { fontSize: 14, color: cores.ink, fontFamily: fonte.sansBold },
  toggleSub: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 2, lineHeight: 16 },
  trilho: {
    width: 52, height: 30, borderRadius: raio.pill, backgroundColor: cores.track,
    padding: 3, justifyContent: 'center',
  },
  trilhoOn: { backgroundColor: cores.amber },
  polegar: {
    width: 24, height: 24, borderRadius: raio.pill, backgroundColor: cores.surface,
    alignSelf: 'flex-start',
  },
  polegarOn: { alignSelf: 'flex-end' },

  // confirmar
  btnConfirmar: {
    minHeight: 52, borderRadius: raio.r, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center',
  },
  btnConfirmarTxt: { fontSize: 15, color: '#fff', fontFamily: fonte.sansBold },
  dica: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, textAlign: 'center', lineHeight: 15 },

  // botões reutilizados (vazio/sucesso)
  btnPrim: {
    minHeight: espaco.toque, borderRadius: raio.r, backgroundColor: cores.accent,
    alignItems: 'center', justifyContent: 'center', alignSelf: 'stretch', paddingHorizontal: espaco.xl,
  },
  btnPrimTxt: { fontSize: 13.5, color: '#fff', fontFamily: fonte.sansBold },
  btnGhost: {
    minHeight: espaco.toque, borderRadius: raio.r, backgroundColor: cores.surface,
    borderWidth: 1.5, borderColor: cores.accent,
    alignItems: 'center', justifyContent: 'center', alignSelf: 'stretch', paddingHorizontal: espaco.xl,
  },
  btnGhostTxt: { fontSize: 13.5, color: cores.accent, fontFamily: fonte.sansBold },

  // sucesso
  sucessoWrap: { flex: 1, justifyContent: 'center', padding: espaco.xl },
  sucessoCard: {
    backgroundColor: cores.posBg, borderRadius: raio.card, padding: 26,
    alignItems: 'center', gap: espaco.sm,
  },
  sucessoIcone: { fontSize: 40, color: cores.pos, fontFamily: fonte.sansBold },
  sucessoTitulo: { fontSize: 17, color: '#0a5c53', fontFamily: fonte.sansBold },
  sucessoMsg: {
    fontSize: 13, color: '#0a5c53', fontFamily: fonte.sansMed,
    textAlign: 'center', lineHeight: 20, marginBottom: espaco.xs,
  },
});
