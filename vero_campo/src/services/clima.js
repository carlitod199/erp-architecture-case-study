import * as Location from 'expo-location';

// Clima via Open-Meteo — grátis, sem chave (mesma fonte do mapa do VERO web).
// Usa a posição do dispositivo (no campo = clima real das válvulas);
// sem permissão/GPS, cai na coordenada da fazenda (Vale do São Francisco).

export const FAZENDA = { latitude: -9.39, longitude: -40.5 };

// Válvulas de demonstração — fallback quando o cache offline ainda não sincronizou
export const VALVULAS = [
  { id: 'v5a', nome: 'Válvula 5A', latitude: -9.3720, longitude: -40.5210 },
  { id: 'v8', nome: 'Válvula 8', latitude: -9.3855, longitude: -40.4890 },
  { id: 'v12', nome: 'Válvula 12', latitude: -9.4030, longitude: -40.5075 },
  { id: 'v14', nome: 'Válvula 14', latitude: -9.4110, longitude: -40.4760 },
];

export async function obterCoordenada() {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status === 'granted') {
      const pos =
        (await Location.getLastKnownPositionAsync()) ||
        (await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Low }));
      if (pos?.coords) return pos.coords;
    }
  } catch (_) {}
  return FAZENDA;
}

export async function buscarClima({ latitude, longitude }) {
  const u =
    `https://api.open-meteo.com/v1/forecast?latitude=${latitude.toFixed(4)}&longitude=${longitude.toFixed(4)}` +
    '&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m' +
    '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max' +
    '&timezone=auto&forecast_days=7';
  const r = await fetch(u);
  if (!r.ok) throw new Error('Clima indisponível.');
  return r.json();
}

// Código WMO -> [ícone, descrição] (mesmo mapa do web)
export const WMO = {
  0: ['☀️', 'Céu limpo'], 1: ['🌤️', 'Predom. limpo'], 2: ['⛅', 'Parc. nublado'], 3: ['☁️', 'Nublado'],
  45: ['🌫️', 'Névoa'], 48: ['🌫️', 'Névoa gelada'], 51: ['🌦️', 'Garoa fraca'], 53: ['🌦️', 'Garoa'], 55: ['🌦️', 'Garoa forte'],
  61: ['🌧️', 'Chuva fraca'], 63: ['🌧️', 'Chuva'], 65: ['🌧️', 'Chuva forte'], 66: ['🌧️', 'Chuva gelada'], 67: ['🌧️', 'Chuva gelada'],
  71: ['🌨️', 'Neve fraca'], 73: ['🌨️', 'Neve'], 75: ['🌨️', 'Neve forte'], 80: ['🌦️', 'Pancadas'], 81: ['🌦️', 'Pancadas'],
  82: ['⛈️', 'Pancadas fortes'], 95: ['⛈️', 'Tempestade'], 96: ['⛈️', 'Tempestade/granizo'], 99: ['⛈️', 'Tempestade/granizo'],
};
export const wmo = (c) => WMO[c] || ['🌡️', '—'];

export const diaSemana = (iso) =>
  ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][new Date(iso + 'T00:00').getDay()];

export default { obterCoordenada, buscarClima, wmo, diaSemana };
