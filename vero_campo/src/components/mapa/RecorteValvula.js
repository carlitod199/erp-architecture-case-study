import React, { useMemo } from 'react';
import { View, StyleSheet } from 'react-native';
import Svg, { Path, Defs, ClipPath, Image as SvgImage } from 'react-native-svg';
import { cores } from '../../theme';

// RECORTE da válvula: miniatura do satélite Esri CORTADA no formato do polígono
// da própria válvula (agro_talhoes.geometria). react-native-svg com ClipPath —
// leve o bastante p/ uma por card na lista. Precisa de internet p/ a imagem;
// offline mostra só o contorno do polígono. Sem geometria → nada (null).

const W = 62, H = 62, PAD = 0.14; // px do desenho + folga relativa do bbox

function aneisDe(g) {
  if (!g) return null;
  try {
    const o = typeof g === 'string' ? JSON.parse(g) : g;
    const geom = o.type === 'Feature' ? o.geometry : o;
    if (!geom) return null;
    if (geom.type === 'Polygon') return geom.coordinates;
    if (geom.type === 'MultiPolygon') return geom.coordinates.flat();
    return null;
  } catch { return null; }
}

export default function RecorteValvula({ valvula, cor = cores.pos }) {
  const dados = useMemo(() => {
    const aneis = aneisDe(valvula?.geometria);
    if (!aneis || !aneis.length) return null;

    let minLng = Infinity, maxLng = -Infinity, minLat = Infinity, maxLat = -Infinity;
    for (const anel of aneis) for (const par of anel) {
      const lng = Number(par[0]), lat = Number(par[1]);
      if (!Number.isFinite(lng) || !Number.isFinite(lat)) continue;
      if (lng < minLng) minLng = lng; if (lng > maxLng) maxLng = lng;
      if (lat < minLat) minLat = lat; if (lat > maxLat) maxLat = lat;
    }
    if (!Number.isFinite(minLng)) return null;

    // bbox QUADRADO centralizado (thumb quadrado, sem distorcer) + folga
    const span = Math.max(maxLng - minLng, maxLat - minLat, 0.0002) * (1 + PAD * 2);
    const cLng = (minLng + maxLng) / 2, cLat = (minLat + maxLat) / 2;
    const bxMin = cLng - span / 2, bxMax = cLng + span / 2;
    const byMin = cLat - span / 2, byMax = cLat + span / 2;

    const toXY = (lng, lat) => [((lng - bxMin) / span) * W, ((byMax - lat) / span) * H];
    const d = aneis
      .map((anel) => 'M ' + anel.map((p) => {
        const [x, y] = toXY(Number(p[0]), Number(p[1]));
        return `${x.toFixed(1)} ${y.toFixed(1)}`;
      }).join(' L ') + ' Z')
      .join(' ');

    // imagem estática de satélite (bboxSR/imageSR = 4326 p/ casar com o mapeamento linear)
    const bbox = `${bxMin},${byMin},${bxMax},${byMax}`;
    const url = `https://server.arcgisonline.com/arcgis/rest/services/World_Imagery/MapServer/export`
      + `?bbox=${bbox}&bboxSR=4326&imageSR=4326&size=${W * 2},${H * 2}&format=jpg&f=image`;
    return { d, url };
  }, [valvula?.geometria]);

  if (!dados) return null;
  const cid = `rec${valvula.id}`;

  return (
    <View style={styles.wrap}>
      <Svg width={W} height={H}>
        <Defs>
          <ClipPath id={cid}><Path d={dados.d} /></ClipPath>
        </Defs>
        <SvgImage
          href={{ uri: dados.url }}
          x={0} y={0} width={W} height={H}
          preserveAspectRatio="xMidYMid slice"
          clipPath={`url(#${cid})`}
        />
        <Path d={dados.d} fill="none" stroke={cor} strokeWidth={2} strokeLinejoin="round" />
      </Svg>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    width: W, height: H, borderRadius: 10, overflow: 'hidden',
    backgroundColor: cores.warm, borderWidth: 1, borderColor: cores.border,
  },
});
