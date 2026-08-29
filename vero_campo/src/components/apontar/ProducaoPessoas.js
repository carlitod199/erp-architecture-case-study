import React, { useEffect, useMemo, useState } from 'react';
import { View, Text, Pressable, TextInput, StyleSheet } from 'react-native';
import { cores, fonte, raio, espaco } from '../../theme';
import { useDadosSync } from '../../hooks/useDadosSync';

// "Pessoas do apontamento" (gestor 22/07 — espelho do dois estágios web):
//   COLABORADOR  → só a quantidade; meta e valor saem da REGRA VIGENTE no
//                  servidor (o app não pergunta nada de premiação)
//   TERCEIRIZADO → produção OU diária (o valor da diária vem do cadastro
//                  no servidor — o app não vê valores)
// O cache 'colaboradores' traz as duas origens (id 'op-N' / 'ter-N').
// onChange(lista) devolve o array PRONTO para /producao e /concluir:
//   [{ colaborador_id, quantidade }
//    { origem:'terceirizado', terceirizado_id, modalidade, quantidade }]
// IMPORTANTE: passe um onChange estável (ex.: o próprio setState do pai).

const num = (s) => {
  const n = Number(String(s ?? '').trim().replace(/\./g, '').replace(',', '.'));
  return Number.isFinite(n) && n > 0 ? n : null;
};

export default function ProducaoPessoas({ unidade = 'caixas', onChange, inicial = null }) {
  const { itens: pessoas, carregado } = useDadosSync('colaboradores');
  const [escolhidos, setEscolhidos] = useState([]); // ids ('op-N'/'ter-N') na ordem
  const [qtds, setQtds] = useState({});             // id -> texto da quantidade
  const [modal, setModal] = useState({});           // id terceirizado -> 'producao'|'diaria'
  const [escolhendo, setEscolhendo] = useState(false);
  const [restaurado, setRestaurado] = useState(false);

  // rascunho (22/07): restaura a lista salva quando a tela reabre — o payload
  // guardado ({colaborador_id}/{terceirizado_id}) vira o estado interno
  useEffect(() => {
    if (restaurado || !Array.isArray(inicial) || inicial.length === 0) return;
    const ids = [];
    const qs = {};
    const ms = {};
    for (const it of inicial) {
      const id = it.terceirizado_id != null ? `ter-${it.terceirizado_id}` : `op-${it.colaborador_id}`;
      ids.push(id);
      qs[id] = String(it.quantidade ?? '');
      if (it.terceirizado_id != null) ms[id] = it.modalidade === 'diaria' ? 'diaria' : 'producao';
    }
    setEscolhidos(ids);
    setQtds(qs);
    setModal(ms);
    setRestaurado(true);
  }, [inicial, restaurado]);

  const porId = useMemo(() => {
    const m = {};
    (pessoas || []).forEach((c) => { m[c.id] = c; });
    return m;
  }, [pessoas]);

  // sobe pro pai a lista pronta pro payload sempre que algo muda
  useEffect(() => {
    if (!onChange) return;
    const lista = escolhidos
      .map((id) => ({ id, c: porId[id], q: num(qtds[id]) }))
      .filter((x) => x.c && x.q)
      .map((x) => {
        if (x.c.origem === 'terceirizado') {
          return {
            origem: 'terceirizado',
            terceirizado_id: x.c.pessoa_id,
            modalidade: modal[x.id] || (x.c.modalidade_padrao === 'diaria' ? 'diaria' : 'producao'),
            quantidade: x.q,
          };
        }
        // meta/valor saem da REGRA VIGENTE no servidor (o app não pergunta)
        return { colaborador_id: x.c.pessoa_id ?? x.c.id, quantidade: x.q };
      });
    onChange(lista);
  }, [escolhidos, qtds, modal, porId, onChange]);

  const disponiveis = useMemo(
    () => (pessoas || []).filter((c) => !escolhidos.includes(c.id)),
    [pessoas, escolhidos]
  );

  function adicionar(c) {
    setEscolhidos((ls) => (ls.includes(c.id) ? ls : [...ls, c.id]));
    if (c.origem === 'terceirizado') {
      setModal((m) => ({ ...m, [c.id]: c.modalidade_padrao === 'diaria' ? 'diaria' : 'producao' }));
    }
    setEscolhendo(false);
  }
  function remover(id) {
    setEscolhidos((ls) => ls.filter((x) => x !== id));
    setQtds((m) => { const n = { ...m }; delete n[id]; return n; });
  }
  function ajustar(id, delta) {
    setQtds((m) => {
      const atual = num(m[id]) || 0;
      const novo = Math.max(0, atual + delta);
      return { ...m, [id]: novo > 0 ? String(novo) : '' };
    });
  }

  const rotuloUnid = unidade === 'caixas' ? 'caixas' : 'plantas';

  return (
    <View style={styles.card}>
      <Text style={styles.eyebrow}>Pessoas do apontamento</Text>
      <Text style={styles.dica}>
        Colaborador → premiação pela regra vigente · Terceirizado → produção ou diária.
        Vá adicionando e salvando ao longo do serviço; o valor é calculado pelo sistema.
      </Text>

      {carregado && (pessoas || []).length === 0 ? (
        <Text style={[styles.dica, { marginTop: 8 }]}>
          Nenhuma pessoa no aparelho — sincronize para carregar a equipe.
        </Text>
      ) : (
        <>
          {escolhidos.length > 0 && (
            <View style={{ gap: 8, marginTop: 10 }}>
              {escolhidos.map((id) => {
                const c = porId[id];
                if (!c) return null;
                const terceirizado = c.origem === 'terceirizado';
                const modo = modal[id] || (c.modalidade_padrao === 'diaria' ? 'diaria' : 'producao');
                const sub = terceirizado
                  ? 'Terceirizado'
                  : [c.funcao, 'premiação pela regra'].filter(Boolean).join(' · ');
                return (
                  <View key={id} style={styles.pessoaBloco}>
                    <View style={styles.pessoa}>
                      <View style={{ flex: 1 }}>
                        <Text style={styles.nome} numberOfLines={1}>{c.nome}</Text>
                        <Text style={styles.funcao} numberOfLines={1}>{sub}</Text>
                      </View>
                      <Pressable style={styles.step} onPress={() => ajustar(id, -1)}>
                        <Text style={styles.stepTxt}>−</Text>
                      </Pressable>
                      <TextInput
                        style={styles.qtd}
                        value={qtds[id] || ''}
                        onChangeText={(t) => setQtds((m) => ({ ...m, [id]: t }))}
                        keyboardType="numeric"
                        placeholder="0"
                        placeholderTextColor={cores.faint}
                      />
                      <Pressable style={styles.step} onPress={() => ajustar(id, 1)}>
                        <Text style={styles.stepTxt}>＋</Text>
                      </Pressable>
                      <Pressable style={styles.remover} onPress={() => remover(id)}>
                        <Text style={styles.removerTxt}>✕</Text>
                      </Pressable>
                    </View>

                    {/* terceirizado escolhe: produção (qtd) ou diária (nº de diárias) */}
                    {terceirizado && (
                      <View style={styles.modalLinha}>
                        {['producao', 'diaria'].map((mo) => {
                          const ativa = modo === mo;
                          return (
                            <Pressable
                              key={mo}
                              style={[styles.modalChip, ativa && styles.modalChipAtivo]}
                              onPress={() => setModal((m) => ({ ...m, [id]: mo }))}
                            >
                              <Text style={[styles.modalChipTxt, ativa && { color: cores.surface }]}>
                                {mo === 'producao' ? `produção (${rotuloUnid})` : 'diária (dias)'}
                              </Text>
                            </Pressable>
                          );
                        })}
                      </View>
                    )}
                  </View>
                );
              })}
            </View>
          )}

          {escolhendo ? (
            <View style={{ gap: 6, marginTop: 10 }}>
              {disponiveis.length === 0 ? (
                <Text style={styles.dica}>Toda a equipe já está na lista.</Text>
              ) : (
                disponiveis.map((c) => (
                  <Pressable key={c.id} style={styles.opcao} onPress={() => adicionar(c)}>
                    <Text style={styles.opcaoNome}>{c.nome}</Text>
                    <Text style={styles.funcao}>
                      {c.origem === 'terceirizado' ? 'Terceirizado' : (c.funcao || 'Colaborador')}
                    </Text>
                  </Pressable>
                ))
              )}
              <Pressable style={styles.btnAdd} onPress={() => setEscolhendo(false)}>
                <Text style={[styles.btnAddTxt, { color: cores.muted }]}>Fechar lista</Text>
              </Pressable>
            </View>
          ) : (
            <Pressable style={styles.btnAdd} onPress={() => setEscolhendo(true)}>
              <Text style={styles.btnAddTxt}>＋ Adicionar pessoa</Text>
            </Pressable>
          )}

        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 15 },
  eyebrow: {
    fontSize: 10.5, letterSpacing: 1, textTransform: 'uppercase',
    color: cores.muted2, fontFamily: fonte.sansBold, marginBottom: 8,
  },
  dica: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, lineHeight: 16 },
  pessoaBloco: { gap: 7 },
  pessoa: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  nome: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansBold },
  funcao: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 1 },
  step: {
    width: espaco.toque, height: espaco.toque, borderRadius: raio.sm,
    backgroundColor: cores.campo, alignItems: 'center', justifyContent: 'center',
  },
  stepTxt: { fontSize: 18, color: cores.accent, fontFamily: fonte.sansBold },
  qtd: {
    width: 64, height: 42, borderRadius: raio.sm, backgroundColor: cores.campo,
    textAlign: 'center', fontSize: 15, color: cores.ink, fontFamily: fonte.monoSemi,
  },
  remover: {
    width: espaco.toque, height: espaco.toque,
    alignItems: 'center', justifyContent: 'center',
  },
  removerTxt: { fontSize: 14, color: cores.danger, fontFamily: fonte.sansBold },
  modalLinha: { flexDirection: 'row', gap: 7, paddingLeft: 2 },
  modalChip: {
    paddingHorizontal: 12, height: 34, borderRadius: 17, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  modalChipAtivo: { backgroundColor: cores.accent },
  modalChipTxt: { fontSize: 11, color: cores.ink2, fontFamily: fonte.sansSemi },
  opcao: {
    minHeight: espaco.toque, justifyContent: 'center',
    backgroundColor: cores.campo, borderRadius: raio.sm,
    paddingHorizontal: 12, paddingVertical: 8,
  },
  opcaoNome: { fontSize: 13, color: cores.ink, fontFamily: fonte.sansSemi },
  btnAdd: {
    minHeight: espaco.toque, marginTop: 10, borderRadius: raio.sm,
    backgroundColor: cores.posBg, alignItems: 'center', justifyContent: 'center',
  },
  btnAddTxt: { fontSize: 12.5, color: cores.accent, fontFamily: fonte.sansBold },
});
