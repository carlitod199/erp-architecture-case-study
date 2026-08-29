import React, { useMemo, useState } from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import { useRoute } from '@react-navigation/native';
import { Tela, Cartao, Botao, Input, Chip, Eyebrow } from '../components/ui';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { cores, fonte, raio, texto } from '../theme';

// Abastecimento de máquina no campo (mig 149; P-122: combustível sem estoque).
// O operador registra os LITROS (+ horímetro opcional); o valor fica para o
// escritório completar no web. Entra na fila offline
// (POST /maquinas/{id}/abastecimento) e sobe quando houver sinal.

const fmt = (n) =>
  (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

// "3.420,1" ou "3420,1" -> 3420.1
const paraNumero = (txt) => {
  const limpo = txt.trim().replace(/\./g, '').replace(',', '.');
  const n = Number(limpo);
  return limpo === '' || Number.isNaN(n) ? null : n;
};

export default function AbastecimentoScreen() {
  const route = useRoute();
  const maquinaParam = route.params?.maquina || null;
  const { itens, carregado } = useDadosSync('maquinas');
  const { sincronizarAgora } = useSync();

  const [selId, setSelId] = useState(maquinaParam ? maquinaParam.id : null);
  const [litros, setLitros] = useState('');
  const [leitura, setLeitura] = useState('');
  const [salvo, setSalvo] = useState(false);

  const lista = useMemo(() => {
    const base = itens || [];
    if (maquinaParam && !base.some((m) => String(m.id) === String(maquinaParam.id))) {
      return [maquinaParam, ...base];
    }
    return base;
  }, [itens, maquinaParam]);

  const maquina =
    lista.find((m) => String(m.id) === String(selId)) || lista[0] || null;
  const anterior = Number(maquina?.horimetro_atual) || 0;

  const nLitros = useMemo(() => paraNumero(litros), [litros]);
  const nLeitura = useMemo(() => paraNumero(leitura), [leitura]);
  const leituraMenor = nLeitura !== null && nLeitura < anterior;
  const valido = maquina !== null && nLitros !== null && nLitros > 0 && !leituraMenor;

  function trocarMaquina(m) {
    setSelId(m.id);
    setLitros('');
    setLeitura('');
    setSalvo(false);
  }

  async function salvar() {
    if (!valido) return;
    const payload = { litros: nLitros };
    if (nLeitura !== null) payload.horimetro = nLeitura;
    await enfileirar({
      tipo: 'abastecimento',
      rota: rotas.maquinaAbastecimento(maquina.id),
      metodo: 'POST',
      payload,
    });
    sincronizarAgora().catch(() => {});
    setSalvo(true);
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Abastecimento" sub="Registrar combustível" />
      <Tela>
        {/* Máquina */}
        <Cartao style={styles.cardGap}>
          <Eyebrow>Máquina</Eyebrow>
          {lista.length === 0 ? (
            <Text style={styles.aviso}>
              {carregado
                ? 'Nenhuma máquina no aparelho — sincronize na tela Máquinas.'
                : 'Carregando…'}
            </Text>
          ) : (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
              {lista.map((m) => {
                const ativa = maquina && String(m.id) === String(maquina.id);
                return (
                  <Chip key={String(m.id)} selecionado={ativa} onPress={() => trocarMaquina(m)}>{m.nome}</Chip>
                );
              })}
            </ScrollView>
          )}
        </Cartao>

        {/* Litros */}
        <Cartao style={styles.cardGap}>
          <Eyebrow>Litros abastecidos</Eyebrow>
          <View style={styles.inputLinha}>
            <Input
              style={{ flex: 1 }}
              estiloCampo={styles.inputCampo}
              value={litros}
              onChangeText={setLitros}
              keyboardType="numeric"
              placeholder="0,0"
            />
            <Text style={styles.inputUnidade}>L</Text>
          </View>
          <Text style={styles.nota}>O valor pago é lançado pelo escritório no VERO web.</Text>
        </Cartao>

        {/* Horímetro opcional */}
        <Cartao style={styles.cardGap}>
          <Eyebrow>Horímetro no abastecimento (opcional)</Eyebrow>
          <View style={styles.inputLinha}>
            <Input
              style={{ flex: 1 }}
              estiloCampo={[styles.inputCampo, leituraMenor && { color: cores.danger }]}
              value={leitura}
              onChangeText={setLeitura}
              keyboardType="numeric"
              placeholder={maquina ? fmt(anterior) : '0,0'}
            />
            <Text style={styles.inputUnidade}>h</Text>
          </View>
          {leituraMenor ? (
            <Text style={[styles.nota, { color: cores.danger }]}>
              Leitura menor que a atual ({fmt(anterior)} h).
            </Text>
          ) : (
            <Text style={styles.nota}>Se informado, também vira leitura oficial do horímetro.</Text>
          )}
        </Cartao>

        {salvo ? (
          <View style={styles.sucesso}>
            <Text style={styles.sucessoTxt}>
              ✓ Abastecimento salvo — será enviado quando houver sinal.
            </Text>
          </View>
        ) : (
          <Botao
            titulo="Registrar abastecimento"
            icone={<Icone nome="abastecimento" tam={20} cor={texto.inverso} />}
            disabled={!valido}
            onPress={salvar}
          />
        )}
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  cardGap: { gap: 8 },
  aviso: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed },
  chips: { gap: 8 },
  inputLinha: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  inputCampo: { height: 54, fontSize: 22, fontFamily: fonte.monoSemi },
  inputUnidade: { fontSize: 15, color: cores.muted, fontFamily: fonte.sansSemi },
  nota: { fontSize: 11, color: cores.muted2, fontFamily: fonte.sansMed },
  sucesso: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 16, alignItems: 'center' },
  sucessoTxt: { fontSize: 13, color: '#0a5c53', fontFamily: fonte.sansBold },
});
