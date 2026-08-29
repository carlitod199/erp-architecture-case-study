import React from 'react';
import { View, Text, Pressable, StyleSheet } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import AppHeader from '../components/AppHeader';
import { Tela, Eyebrow } from '../components/ui';
import Icone from '../components/Icone';
import { useAuth } from '../context/AuthContext';
import { cores, fonte, raio } from '../theme';

// Hub do PACKING (pedido gestor 19/08): o tile "Packing" da Home agrupa aqui
// os três processos — romaneio de carga, recepção e posto de caixas — que
// antes ficavam espalhados no menu Mais. Cada entrada só aparece com a
// permissão respectiva (mesma regra que gated as telas individualmente).
export default function PackingHubScreen() {
  const nav = useNavigation();
  const { pode } = useAuth();

  const podeCargas   = pode('agro.romaneios_colheita.ver') || pode('agro.romaneios_colheita.editar');
  const podePosto    = pode('packing.apontar.ver') || pode('packing.apontar.editar');
  const podeRecepcao = pode('packing.recepcao.ver') || pode('packing.recepcao.editar');

  const entradas = [
    ...(podeCargas ? [{
      ic: 'colheita', titulo: 'Romaneio de carga', sub: 'Registra a carga da colheita (destino packing)',
      irPara: () => nav.navigate('Cargas'),
    }] : []),
    ...(podeRecepcao ? [{
      ic: 'recebidos', titulo: 'Recepção de cargas', sub: 'Chegada do campo — relógio de frio e gates',
      irPara: () => nav.navigate('PackingRecepcao'),
    }] : []),
    ...(podePosto ? [{
      ic: 'scanner', titulo: 'Posto de caixas', sub: 'Colheita e embalamento por caixa (bipagem)',
      irPara: () => nav.navigate('PackingApontar'),
    }] : []),
  ];

  return (
    <View style={{ flex: 1 }}>
      <AppHeader titulo="Packing" sub="Do campo à caixa embalada" />
      <Tela>
        <Eyebrow>Processos</Eyebrow>
        {entradas.length === 0 ? (
          <View style={styles.vazio}>
            <Text style={styles.vazioTxt}>
              Sem acesso ao packing — peça as permissões ao gestor da conta.
            </Text>
          </View>
        ) : (
          <View style={styles.lista}>
            {entradas.map((e, i) => (
              <Pressable
                key={e.titulo}
                style={({ pressed }) => [
                  styles.linha,
                  i > 0 && styles.linhaDiv,
                  pressed && { backgroundColor: cores.campo },
                ]}
                onPress={e.irPara}
                accessibilityLabel={e.titulo}
              >
                <View style={styles.linhaIc}><Icone nome={e.ic} tam={20} cor={cores.accent} /></View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.linhaTitulo}>{e.titulo}</Text>
                  <Text style={styles.linhaSub}>{e.sub}</Text>
                </View>
                <Text style={styles.seta}>›</Text>
              </Pressable>
            ))}
          </View>
        )}
      </Tela>
    </View>
  );
}

const styles = StyleSheet.create({
  lista: {
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: raio.md, overflow: 'hidden',
  },
  linha: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14 },
  linhaDiv: { borderTopWidth: 1, borderTopColor: cores.border },
  linhaIc: {
    width: 38, height: 38, borderRadius: raio.sm, backgroundColor: cores.campo,
    alignItems: 'center', justifyContent: 'center',
  },
  linhaTitulo: { fontFamily: fonte.semibold, fontSize: 15, color: cores.texto },
  linhaSub: { fontFamily: fonte.regular, fontSize: 12.5, color: cores.textoSuave, marginTop: 1 },
  seta: { fontSize: 22, color: cores.textoSuave, marginLeft: 4 },
  vazio: {
    backgroundColor: cores.surface, borderWidth: 1, borderColor: cores.border,
    borderRadius: raio.md, padding: 18,
  },
  vazioTxt: { fontFamily: fonte.regular, fontSize: 14, color: cores.textoSuave },
});
