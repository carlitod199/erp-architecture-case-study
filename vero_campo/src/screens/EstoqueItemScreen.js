import React, { useMemo } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { useRoute, useNavigation } from '@react-navigation/native';
import { Tela, Cartao, Botao, Badge, Eyebrow } from '../components/ui';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { cores, fonte, acao } from '../theme';
import { useDadosSync } from '../hooks/useDadosSync';

// Ficha do item — saldo × mínimo de route.params.produto (cache /sync/estoque,
// agora com a ficha técnica da bula) + movimentações reais do módulo
// 'estoque_movimentacoes'. SEM custos — P-75: valores não descem ao campo.

const fmtQtd = (n) =>
  (Number(n) || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });

// 'YYYY-MM-DD[ HH:MM]' → 'dd/mm hh:mm'
const fmtQuando = (valor) => {
  const m = String(valor || '').match(/^\d{4}-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (!m) return '';
  return `${m[2]}/${m[1]}${m[3] ? ` ${m[3]}:${m[4]}` : ''}`;
};

const ORIGEM_ROTULO = {
  aplicacao: 'aplicação', compra: 'compra', venda: 'venda',
  transferencia: 'transferência', ajuste: 'ajuste', apontamento: 'apontamento',
};

export default function EstoqueItemScreen() {
  const route = useRoute();
  const nav = useNavigation();
  const produto = route.params?.produto || null;
  const { itens: movs } = useDadosSync('estoque_movimentacoes');

  const movimentacoes = useMemo(() => (
    produto
      ? (movs || [])
          .filter((m) => String(m.produto_id) === String(produto.id))
          .sort((a, b) => String(b.data_movimento || '').localeCompare(String(a.data_movimento || '')))
          .slice(0, 6)
      : []
  ), [movs, produto]);

  if (!produto) {
    return (
      <View style={{ flex: 1, backgroundColor: cores.page }}>
        <AppHeader titulo="Ficha do item" />
        <Tela>
          <Cartao>
            <Text style={styles.infoRotulo}>Produto não encontrado. Volte à lista do estoque.</Text>
          </Cartao>
        </Tela>
      </View>
    );
  }

  const unidade = produto.unidade || '';
  const saldo = Number(produto.saldo) || 0;
  const minimo = Number(produto.estoque_minimo) || 0;
  const abaixo = saldo < minimo;

  // ficha técnica real (colunas da bula no sync 'estoque'); linha sem dado não aparece
  const infos = [
    produto.tipo_insumo ? { rotulo: 'Categoria', valor: produto.tipo_insumo } : null,
    produto.fabricante ? { rotulo: 'Fabricante', valor: produto.fabricante } : null,
    produto.registro_mapa ? { rotulo: 'Registro MAPA', valor: produto.registro_mapa } : null,
    produto.classe_toxicologica ? { rotulo: 'Classe toxicológica', valor: produto.classe_toxicologica } : null,
    produto.carencia_dias != null ? { rotulo: 'Carência', valor: `${produto.carencia_dias} dias` } : null,
    produto.intervalo_aplicacoes_dias != null
      ? { rotulo: 'Intervalo entre aplicações', valor: `${produto.intervalo_aplicacoes_dias} dias` } : null,
  ].filter(Boolean);

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo={produto.nome} sub={`Ficha do item${produto.codigo ? ` · ${produto.codigo}` : ''}`} />
      <Tela>
        {/* Saldo atual (real) */}
        <Cartao style={styles.cardSaldo}>
          <Eyebrow>Saldo atual</Eyebrow>
          <View style={styles.saldoLinha}>
            <Text style={[styles.saldo, !abaixo && { color: cores.ink }]}>{fmtQtd(saldo)}</Text>
            <Text style={styles.unidade}>{unidade}</Text>
          </View>
          {abaixo ? (
            <Badge tipo="crit" style={styles.badgeCentro}>
              ⚠️ Abaixo do mínimo ({fmtQtd(minimo)} {unidade})
            </Badge>
          ) : (
            <Badge tipo="ok" style={styles.badgeCentro}>
              OK · mínimo {fmtQtd(minimo)} {unidade}
            </Badge>
          )}
        </Cartao>

        {/* Ficha técnica real da bula */}
        {infos.length > 0 && (
          <Cartao>
            <Eyebrow>Informações</Eyebrow>
            {infos.map((i, idx) => (
              <View key={i.rotulo} style={[styles.infoLinha, idx > 0 && styles.sep]}>
                <Text style={styles.infoRotulo}>{i.rotulo}</Text>
                <Text style={styles.infoValor}>{i.valor}</Text>
              </View>
            ))}
          </Cartao>
        )}

        {/* Movimentações reais (sem custos — P-75) */}
        {movimentacoes.length > 0 && (
          <Cartao>
            <Eyebrow>Últimas movimentações</Eyebrow>
            {movimentacoes.map((m, idx) => {
              const entrada = m.tipo === 'entrada' || (m.tipo === 'ajuste' && Number(m.quantidade) >= 0);
              const sinal = entrada ? '+' : '−';
              const ref = [ORIGEM_ROTULO[m.origem_tipo] || m.origem_tipo, m.observacao]
                .filter(Boolean).join(' · ') || m.tipo;
              return (
                <View key={m.id} style={[styles.movLinha, idx > 0 && styles.sep]}>
                  <Text style={[styles.movQtd, { color: entrada ? cores.pos : cores.danger }]}>
                    {sinal}{fmtQtd(Math.abs(Number(m.quantidade) || 0))} {unidade}
                  </Text>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.movRef} numberOfLines={1}>{ref}</Text>
                    <Text style={styles.movQuando}>{fmtQuando(m.data_movimento)}</Text>
                  </View>
                </View>
              );
            })}
          </Cartao>
        )}

        {/* Alerta de reposição + solicitação de compra direto do app */}
        {abaixo && (
          <Cartao style={styles.cardAviso}>
            <Text style={styles.avisoTitulo}>Reposição recomendada</Text>
            <Text style={styles.avisoTxt}>Saldo abaixo do mínimo — solicite a compra deste item.</Text>
          </Cartao>
        )}
        <Botao
          variante="secundaria"
          icone={<Icone nome="compras" tam={18} cor={acao.primaria} />}
          titulo="Solicitar compra deste item"
          onPress={() => nav.navigate('NovaSolicitacao', { produto })}
        />
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  cardSaldo: { alignItems: 'center', paddingVertical: 20 },
  saldoLinha: { flexDirection: 'row', alignItems: 'baseline', gap: 6, marginTop: 6 },
  saldo: { fontSize: 46, color: cores.danger, fontFamily: fonte.monoSemi },
  unidade: { fontSize: 18, color: cores.muted, fontFamily: fonte.sansSemi },
  badgeCentro: { alignSelf: 'center', marginTop: 8 },
  infoLinha: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9 },
  sep: { borderTopWidth: 1, borderTopColor: cores.track },
  infoRotulo: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed },
  infoValor: { fontSize: 12.5, color: cores.ink, fontFamily: fonte.sansSemi },
  movLinha: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 9 },
  movQtd: { fontSize: 13, fontFamily: fonte.monoSemi, minWidth: 76 },
  movRef: { fontSize: 12.5, color: cores.ink, fontFamily: fonte.sansSemi },
  movQuando: { fontSize: 11, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  cardAviso: { backgroundColor: cores.amberBg, borderColor: 'transparent' },
  avisoTitulo: { fontSize: 13.5, color: cores.amberDeep, fontFamily: fonte.sansBold },
  avisoTxt: { fontSize: 12, color: cores.amberDeep, fontFamily: fonte.sansMed, marginTop: 5, lineHeight: 17, opacity: 0.9 },
});
