import React, { useMemo, useState } from 'react';
import { View, Text, ScrollView, Image, StyleSheet } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { useRoute } from '@react-navigation/native';
import { Tela, Cartao, Botao, Input, Chip, Eyebrow } from '../components/ui';
import AppHeader from '../components/AppHeader';
import Icone from '../components/Icone';
import { useDadosSync } from '../hooks/useDadosSync';
import { useSync } from '../context/SyncContext';
import { enfileirar } from '../offline/fila';
import { rotas } from '../services/veroApi';
import { cores, fonte, raio, acao } from '../theme';

// Registro de horímetro — não-regressão validada localmente e no servidor
// (POST /maquinas/{id}/horimetro). A leitura entra na fila offline e é
// enviada quando houver sinal. Foto do painel: em breve.

const fmt = (n) =>
  (Number(n) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

// "3.420,1" ou "3420,1" -> 3420.1
const paraNumero = (txt) => {
  const limpo = txt.trim().replace(/\./g, '').replace(',', '.');
  const n = Number(limpo);
  return limpo === '' || Number.isNaN(n) ? null : n;
};

export default function HorimetroScreen() {
  const route = useRoute();
  const maquinaParam = route.params?.maquina || null;
  const { itens, carregado } = useDadosSync('maquinas');
  const { sincronizarAgora } = useSync();

  const [selId, setSelId] = useState(maquinaParam ? maquinaParam.id : null);
  const [leitura, setLeitura] = useState('');
  const [foto, setFoto] = useState(null); // 7.2: foto do painel
  const [salvo, setSalvo] = useState(null); // { diferenca }

  async function tirarFoto() {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) return;
    const r = await ImagePicker.launchCameraAsync({ quality: 0.5 });
    if (!r.canceled && r.assets?.[0]?.uri) setFoto(r.assets[0].uri);
  }

  // Lista real do cache; garante que a máquina recebida por parâmetro apareça
  // mesmo se o cache ainda não tiver sido baixado.
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

  const valor = useMemo(() => paraNumero(leitura), [leitura]);
  const menorQueAnterior = valor !== null && valor < anterior;
  const valido = maquina !== null && valor !== null && !menorQueAnterior;

  function trocarMaquina(m) {
    setSelId(m.id);
    setLeitura('');
    setSalvo(null);
  }

  async function salvar() {
    if (!valido) return;
    const uuid = await enfileirar({
      tipo: 'horimetro',
      rota: rotas.maquinaHorimetro(maquina.id),
      metodo: 'POST',
      payload: { horimetro: valor },
    });
    // 7.2: foto do painel vinculada à leitura (pai antes da foto na fila)
    if (foto) {
      await enfileirar({
        tipo: 'anexo',
        rota: rotas.anexos,
        metodo: 'POST',
        paiUuid: uuid,
        payload: {
          uri: foto, nome: 'painel.jpg', mime: 'image/jpeg',
          origem_uuid: uuid, origem_tipo: 'horimetro',
        },
      });
    }
    // dispara o envio sem bloquear a interface — offline, fica na fila
    sincronizarAgora().catch(() => {});
    setSalvo({ diferenca: valor - anterior });
    setFoto(null);
  }

  return (
    <View style={{ flex: 1, backgroundColor: cores.page }}>
      <AppHeader titulo="Horímetro" sub="Registrar leitura" />
      <Tela>
        {/* Máquina */}
        <Cartao>
          <Eyebrow>Máquina</Eyebrow>
          {lista.length === 0 ? (
            <Text style={styles.semMaquina}>
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
          <View style={styles.anteriorLinha}>
            <Text style={styles.anteriorRotulo}>Leitura anterior</Text>
            <Text style={styles.anteriorValor}>{maquina ? `${fmt(anterior)} h` : '—'}</Text>
          </View>
        </Cartao>

        {/* Nova leitura */}
        <Cartao>
          <Eyebrow>Nova leitura (h)</Eyebrow>
          <Input
            estiloCampo={[styles.inputBig, menorQueAnterior && styles.inputBigErro]}
            value={leitura}
            onChangeText={(t) => { setLeitura(t); setSalvo(null); }}
            placeholder={maquina ? fmt(anterior + 8) : '0,0'}
            keyboardType="decimal-pad"
          />
          {menorQueAnterior && (
            <Text style={styles.erro}>⚠️ leitura menor que a anterior ({fmt(anterior)} h)</Text>
          )}
        </Cartao>

        {/* Salvar / sucesso */}
        {salvo ? (
          <View style={styles.sucesso}>
            <Text style={styles.sucessoTitulo}>✓ Leitura registrada — será enviada quando houver sinal</Text>
            <Text style={styles.sucessoDif}>+{fmt(salvo.diferenca)} h desde a última leitura</Text>
          </View>
        ) : (
          <Botao titulo="Salvar leitura" disabled={!valido} onPress={salvar} />
        )}

        {/* 7.2: foto do painel — evidência da leitura */}
        <Cartao>
          <Eyebrow>Foto do painel (opcional)</Eyebrow>
          {foto ? (
            <View style={styles.fotoLinha}>
              <Image source={{ uri: foto }} style={styles.fotoPreview} />
              <View style={{ flex: 1, gap: 8 }}>
                <Botao variante="secundaria" tamanho="sm" titulo="Tirar outra" onPress={tirarFoto} />
                <Botao variante="perigo" tamanho="sm" titulo="Remover" onPress={() => setFoto(null)} />
              </View>
            </View>
          ) : (
            <Botao
              variante="secundaria"
              tamanho="sm"
              icone={<Icone nome="camera" tam={18} cor={acao.primaria} />}
              titulo="Fotografar o painel"
              onPress={tirarFoto}
              style={{ marginTop: 10 }}
            />
          )}
        </Cartao>
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  semMaquina: { fontSize: 12.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 10, lineHeight: 18 },
  chips: { gap: 7, paddingTop: 10 },
  anteriorLinha: {
    flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center',
    marginTop: 13, paddingTop: 11, borderTopWidth: 1, borderTopColor: cores.track,
  },
  anteriorRotulo: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed },
  anteriorValor: { fontSize: 16, color: cores.ink, fontFamily: fonte.monoSemi },
  inputBig: {
    height: 62, borderRadius: raio.r, paddingHorizontal: 16, marginTop: 10,
    textAlign: 'center', fontSize: 28, fontFamily: fonte.monoSemi,
  },
  inputBigErro: { backgroundColor: cores.dangerBg },
  erro: { fontSize: 12, color: cores.danger, fontFamily: fonte.sansSemi, marginTop: 8 },
  sucesso: { backgroundColor: cores.posBg, borderRadius: raio.card, padding: 15, alignItems: 'center' },
  sucessoTitulo: { fontSize: 13, color: '#0a5c53', fontFamily: fonte.sansBold, textAlign: 'center', lineHeight: 18 },
  sucessoDif: { fontSize: 18, color: '#0a5c53', fontFamily: fonte.monoSemi, marginTop: 6 },
  fotoLinha: { flexDirection: 'row', gap: 12, marginTop: 10, alignItems: 'center' },
  fotoPreview: { width: 84, height: 84, borderRadius: raio.r, backgroundColor: cores.track },
});
