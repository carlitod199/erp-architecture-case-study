import React, { useMemo } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import Svg, { Circle, Line, Text as SvgText } from 'react-native-svg';
import { cores, fonte, raio, espaco } from '../../theme';

// Onda 2 — MAPA ESQUEMÁTICO da fazenda, 100% offline (sem tiles/sem rede):
// cada válvula com centroide_lat/centroide_lng do cache 'talhoes' vira um
// pino no SVG. A posição é o centroide NORMALIZADO para o viewBox (min/max
// de lat/lng com margem); válvulas sem coordenada ficam fora do desenho —
// continuam na lista logo abaixo, que segue sendo o acesso principal.
// Cor do pino = situação da válvula (calculada pela tela que usa o mapa):
//   pos    → sem pendência
//   amber  → serviço em aberto (apontamentos_abertos por talhao_id)
//   danger → alerta aberto (alertas por talhao_id)

const VB_W = 340;
const VB_H = 200;
const MARGEM = 30;
const PASSO_GRADE = 42; // grade leve de fundo, só pra leitura espacial

const numOuNull = (v) => {
  const n = Number(v);
  return v != null && v !== '' && !Number.isNaN(n) && n !== 0 ? n : null;
};

export default function MapaFazenda({ valvulas, statusPorId, selecionadaId, aoTocar }) {
  // normalização: min/max de lat/lng do conjunto → viewBox com margem.
  // span mínimo evita divisão por zero quando há 1 pino (ou todos no mesmo ponto).
  const pinos = useMemo(() => {
    const comCoord = (valvulas || [])
      .map((v) => ({ v, lat: numOuNull(v.centroide_lat), lng: numOuNull(v.centroide_lng) }))
      .filter((p) => p.lat !== null && p.lng !== null);
    if (comCoord.length === 0) return [];

    let minLat = Infinity; let maxLat = -Infinity;
    let minLng = Infinity; let maxLng = -Infinity;
    for (const p of comCoord) {
      if (p.lat < minLat) minLat = p.lat;
      if (p.lat > maxLat) maxLat = p.lat;
      if (p.lng < minLng) minLng = p.lng;
      if (p.lng > maxLng) maxLng = p.lng;
    }
    const spanLat = Math.max(maxLat - minLat, 0.0004);
    const spanLng = Math.max(maxLng - minLng, 0.0004);

    return comCoord.map(({ v, lat, lng }) => ({
      v,
      // x cresce com a longitude (oeste→leste); y cresce para o SUL
      // (lat maior = mais ao norte = mais alto na tela)
      x: MARGEM + ((lng - minLng) / spanLng) * (VB_W - 2 * MARGEM),
      y: MARGEM + ((maxLat - lat) / spanLat) * (VB_H - 2 * MARGEM),
    }));
  }, [valvulas]);

  if (pinos.length === 0) return null; // sem coordenadas sincronizadas → só a lista

  const semCoord = (valvulas || []).length - pinos.length;
  const corDe = { pos: cores.pos, amber: cores.amber, danger: cores.danger };

  // pino selecionado desenha por último (fica por cima dos vizinhos)
  const ordenados = [...pinos].sort((a, b) => {
    const selA = String(a.v.id) === String(selecionadaId) ? 1 : 0;
    const selB = String(b.v.id) === String(selecionadaId) ? 1 : 0;
    return selA - selB;
  });

  const grade = [];
  for (let gx = PASSO_GRADE; gx < VB_W; gx += PASSO_GRADE) {
    grade.push(<Line key={`v${gx}`} x1={gx} y1={0} x2={gx} y2={VB_H} stroke={cores.track} strokeWidth={1} />);
  }
  for (let gy = PASSO_GRADE; gy < VB_H; gy += PASSO_GRADE) {
    grade.push(<Line key={`h${gy}`} x1={0} y1={gy} x2={VB_W} y2={gy} stroke={cores.track} strokeWidth={1} />);
  }

  return (
    <View style={styles.card}>
      <Text style={styles.eyebrow}>Mapa da fazenda</Text>

      <View style={styles.quadro}>
        <Svg width="100%" height={206} viewBox={`0 0 ${VB_W} ${VB_H}`}>
          {grade}
          {ordenados.map(({ v, x, y }) => {
            const sel = String(v.id) === String(selecionadaId);
            const cor = corDe[statusPorId?.[String(v.id)]] || cores.pos;
            return (
              <React.Fragment key={v.id}>
                {sel && (
                  <Circle cx={x} cy={y} r={12.5} fill="none" stroke={cor} strokeWidth={1.8} />
                )}
                <Circle
                  cx={x} cy={y} r={7.5}
                  fill={cor} stroke={cores.surface} strokeWidth={2}
                />
                {sel && (
                  <SvgText
                    x={x} y={y - 17}
                    fontSize={11} fontFamily={fonte.sansBold}
                    fill={cores.ink} textAnchor="middle"
                  >
                    {v.codigo || v.nome}
                  </SvgText>
                )}
                {/* alvo de toque generoso (operável com luva) */}
                <Circle
                  cx={x} cy={y} r={17}
                  fill={cores.surface} fillOpacity={0}
                  onPress={() => aoTocar && aoTocar(v)}
                />
              </React.Fragment>
            );
          })}
        </Svg>
      </View>

      {/* legenda das cores de situação */}
      <View style={styles.legenda}>
        <View style={styles.legItem}>
          <View style={[styles.legPonto, { backgroundColor: cores.pos }]} />
          <Text style={styles.legTxt}>sem pendência</Text>
        </View>
        <View style={styles.legItem}>
          <View style={[styles.legPonto, { backgroundColor: cores.amber }]} />
          <Text style={styles.legTxt}>serviço em aberto</Text>
        </View>
        <View style={styles.legItem}>
          <View style={[styles.legPonto, { backgroundColor: cores.danger }]} />
          <Text style={styles.legTxt}>alerta aberto</Text>
        </View>
      </View>

      {semCoord > 0 && (
        <Text style={styles.nota}>
          {semCoord} válvula{semCoord === 1 ? '' : 's'} sem coordenada — apenas na lista abaixo.
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: espaco.md },
  eyebrow: { fontSize: 10, letterSpacing: 1, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold },
  quadro: {
    marginTop: 10, borderRadius: raio.r, overflow: 'hidden',
    backgroundColor: cores.warm, borderWidth: 1, borderColor: cores.border,
  },
  legenda: { flexDirection: 'row', flexWrap: 'wrap', gap: espaco.md, marginTop: 10 },
  legItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  legPonto: { width: 9, height: 9, borderRadius: 5 },
  legTxt: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed },
  nota: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 7 },
});
