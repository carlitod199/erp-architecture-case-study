import React, { useEffect, useState } from 'react';
import { View, Text, ScrollView, StyleSheet } from 'react-native';
import CarregandoVero from './CarregandoVero';
import { cores, fonte, raio } from '../theme';
import { buscarClima, obterCoordenada, wmo, diaSemana } from '../services/clima';

// Card de clima da Home — condição atual + PREVISÃO DE 7 DIAS.
// Sem seletor de válvula: uma previsão só, pela posição do aparelho (no campo
// = clima real da fazenda); sem GPS/permissão, cai na coordenada da fazenda.

export default function CartaoClima() {
  const [dados, setDados] = useState(null);
  const [erro, setErro] = useState(null);

  useEffect(() => {
    let ativo = true;
    (async () => {
      try {
        const pos = await obterCoordenada();
        const d = await buscarClima({ latitude: pos.latitude, longitude: pos.longitude });
        if (ativo) setDados(d);
      } catch (e) {
        console.log('[clima] falha:', e?.message || e);
        if (ativo) setErro(e?.message || 'erro desconhecido');
      }
    })();
    return () => { ativo = false; };
  }, []);

  return (
    <View style={styles.card}>
      {!!erro && <Text style={styles.err}>⚠️ Clima indisponível — {erro}</Text>}

      {!erro && !dados && (
        <View style={styles.centro}>
          <CarregandoVero tamanho={40} />
        </View>
      )}

      {!erro && dados && (
        <>
          <View style={styles.agora}>
            <Text style={styles.icone}>{wmo(dados.current.weather_code)[0]}</Text>
            <View style={{ flex: 1 }}>
              <Text style={styles.temp}>{Math.round(dados.current.temperature_2m)}°C</Text>
              <Text style={styles.desc}>
                {wmo(dados.current.weather_code)[1]} · sensação {Math.round(dados.current.apparent_temperature)}°C
              </Text>
            </View>
            <View style={styles.metas}>
              <Text style={styles.meta}>💧 {dados.current.relative_humidity_2m}%</Text>
              <Text style={styles.meta}>🌬 {Math.round(dados.current.wind_speed_10m)} km/h</Text>
            </View>
          </View>

          {/* 7 dias — rolagem horizontal para caber com folga em qualquer tela */}
          <ScrollView
            horizontal
            showsHorizontalScrollIndicator={false}
            style={styles.prev}
            contentContainerStyle={styles.prevConteudo}
          >
            {dados.daily.time.map((t, i) => (
              <View key={t} style={styles.dia}>
                <Text style={styles.diaNome}>{i === 0 ? 'hoje' : diaSemana(t)}</Text>
                <Text style={styles.diaIc}>{wmo(dados.daily.weather_code[i])[0]}</Text>
                <Text style={styles.diaTemp}>
                  {Math.round(dados.daily.temperature_2m_max[i])}°{' '}
                  <Text style={styles.diaMin}>{Math.round(dados.daily.temperature_2m_min[i])}°</Text>
                </Text>
                <Text style={styles.diaChuva}>💧{dados.daily.precipitation_probability_max[i]}%</Text>
              </View>
            ))}
          </ScrollView>
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: 15 },
  centro: { alignItems: 'center', justifyContent: 'center', minHeight: 90 },
  err: { fontSize: 12, color: cores.muted, fontFamily: fonte.sansMed },
  agora: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  icone: { fontSize: 38 },
  temp: { fontSize: 26, color: cores.ink, fontFamily: fonte.monoSemi },
  desc: { fontSize: 11.5, color: cores.muted, fontFamily: fonte.sansMed, marginTop: 1 },
  metas: { alignItems: 'flex-end', gap: 3 },
  meta: { fontSize: 11.5, color: cores.ink2, fontFamily: fonte.sansSemi },
  prev: { marginTop: 13, borderTopWidth: 1, borderTopColor: cores.border },
  prevConteudo: { paddingTop: 11, gap: 4 },
  dia: { width: 56, alignItems: 'center', gap: 2 },
  diaNome: { fontSize: 10, color: cores.muted2, fontFamily: fonte.sansSemi, textTransform: 'capitalize' },
  diaIc: { fontSize: 19 },
  diaTemp: { fontSize: 12, color: cores.ink, fontFamily: fonte.sansBold },
  diaMin: { color: cores.muted, fontFamily: fonte.sansMed },
  diaChuva: { fontSize: 10, color: cores.muted, fontFamily: fonte.sansMed },
});
