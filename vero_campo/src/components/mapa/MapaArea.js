import React, { useMemo, useRef, useEffect } from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { WebView } from 'react-native-webview';
import MapaFazenda from './MapaFazenda';
import { cores, fonte, raio, espaco } from '../../theme';

// MAPA DA ÁREA — polígonos reais (agro_talhoes.geometria, GeoJSON) sobre
// satélite Esri, via WebView + Leaflet (mesmo mapa do web em agro/mapa.php).
// Precisa de internet p/ os tiles; sem geometria OU offline cai no MapaFazenda
// (SVG esquemático 100% offline). Cor do polígono = situação da válvula.

const COR = { pos: '#1f9d55', amber: '#d9822b', danger: '#d64545' };

function parseGeo(g) {
  if (!g) return null;
  try {
    const o = typeof g === 'string' ? JSON.parse(g) : g;
    if (!o || !o.type) return null;
    return o.type === 'Feature' ? (o.geometry || null) : o;
  } catch { return null; }
}

export default function MapaArea({ valvulas, statusPorId, selecionadaId, aoTocar, online = true }) {
  const webRef = useRef(null);

  const features = useMemo(() => (
    (valvulas || [])
      .map((v) => {
        const geom = parseGeo(v.geometria);
        if (!geom) return null;
        const cor = COR[statusPorId?.[String(v.id)]] || COR.pos;
        return { type: 'Feature', properties: { id: String(v.id), nome: v.codigo || v.nome || '', cor }, geometry: geom };
      })
      .filter(Boolean)
  ), [valvulas, statusPorId]);

  // HTML recriado só quando o conjunto de áreas muda (não a cada seleção)
  const html = useMemo(() => montarHtml(features), [features]);

  // seleção destacada sem recarregar o WebView
  const aplicarSelecao = () => {
    webRef.current?.injectJavaScript(
      `window.__selecionar && window.__selecionar(${JSON.stringify(String(selecionadaId ?? ''))}); true;`
    );
  };
  useEffect(aplicarSelecao, [selecionadaId]);

  // sem área desenhável ou offline → mapa esquemático offline (pinos)
  if (features.length === 0 || !online) {
    return <MapaFazenda valvulas={valvulas} statusPorId={statusPorId} selecionadaId={selecionadaId} aoTocar={aoTocar} />;
  }

  function onMessage(e) {
    const id = e.nativeEvent.data;
    const v = (valvulas || []).find((x) => String(x.id) === String(id));
    if (v && aoTocar) aoTocar(v);
  }

  const semGeo = (valvulas || []).length - features.length;

  return (
    <View style={styles.card}>
      <Text style={styles.eyebrow}>Mapa da área</Text>
      <View style={styles.quadro}>
        <WebView
          ref={webRef}
          originWhitelist={['*']}
          source={{ html }}
          javaScriptEnabled
          domStorageEnabled
          scrollEnabled={false}
          startInLoadingState
          onMessage={onMessage}
          onLoadEnd={aplicarSelecao}
          style={styles.web}
        />
      </View>

      <View style={styles.legenda}>
        {[['pos', 'sem pendência'], ['amber', 'serviço em aberto'], ['danger', 'alerta aberto']].map(([k, t]) => (
          <View key={k} style={styles.legItem}>
            <View style={[styles.legPonto, { backgroundColor: cores[k] }]} />
            <Text style={styles.legTxt}>{t}</Text>
          </View>
        ))}
      </View>

      {semGeo > 0 && (
        <Text style={styles.nota}>{semGeo} válvula{semGeo === 1 ? '' : 's'} sem área desenhada — só na lista abaixo.</Text>
      )}
    </View>
  );
}

function montarHtml(features) {
  const fc = JSON.stringify({ type: 'FeatureCollection', features });
  return `<!doctype html><html><head>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>html,body,#map{height:100%;margin:0;background:#e9e6df}
.leaflet-container{background:#e9e6df}
.lbl{background:rgba(0,0,0,.55);border:0;color:#fff;font:600 11px sans-serif;border-radius:6px;padding:2px 6px;box-shadow:none}
.lbl:before{display:none}</style>
</head><body><div id="map"></div><script>
var data=${fc};var layers={};
var map=L.map('map',{zoomControl:false,attributionControl:false});
L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19}).addTo(map);
function base(f){return {color:'#ffffff',weight:2,fillColor:f.properties.cor,fillOpacity:0.35};}
function sel(f){return {color:'#ffffff',weight:4,fillColor:f.properties.cor,fillOpacity:0.55};}
var gj=L.geoJSON(data,{style:base,onEachFeature:function(f,l){
  layers[f.properties.id]=l;
  l.bindTooltip(f.properties.nome,{permanent:true,direction:'center',className:'lbl'});
  l.on('click',function(){ if(window.ReactNativeWebView){window.ReactNativeWebView.postMessage(f.properties.id);} });
}}).addTo(map);
try{map.fitBounds(gj.getBounds(),{padding:[18,18]});}catch(e){map.setView([-9.29,-40.45],14);}
window.__selecionar=function(id){for(var k in layers){var l=layers[k];var f=l.feature;l.setStyle(k===id?sel(f):base(f));if(k===id){try{l.bringToFront();}catch(e){}}}};
</script></body></html>`;
}

const styles = StyleSheet.create({
  card: { backgroundColor: cores.surface, borderRadius: raio.card, padding: espaco.md },
  eyebrow: { fontSize: 10, letterSpacing: 1, textTransform: 'uppercase', color: cores.muted2, fontFamily: fonte.sansBold },
  quadro: {
    marginTop: 10, height: 240, borderRadius: raio.r, overflow: 'hidden',
    backgroundColor: cores.warm, borderWidth: 1, borderColor: cores.border,
  },
  web: { flex: 1, backgroundColor: 'transparent' },
  legenda: { flexDirection: 'row', flexWrap: 'wrap', gap: espaco.md, marginTop: 10 },
  legItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  legPonto: { width: 9, height: 9, borderRadius: 5 },
  legTxt: { fontSize: 10.5, color: cores.muted, fontFamily: fonte.sansMed },
  nota: { fontSize: 10.5, color: cores.muted2, fontFamily: fonte.sansMed, marginTop: 7 },
});
