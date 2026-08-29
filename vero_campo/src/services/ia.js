import http from './http';
import { MODO_DEMO, TIMEOUT } from './config';
import { lerBaseUrl } from './ambiente';
import { lerToken } from './authStorage';
import { buscarClima, wmo, VALVULAS } from './clima';

// Assistente de IA do VERO Campo.
//
// Contrato com o backend (api/v1/rotas/ia.php, proxy PHP -> OpenAI):
//   POST /ia/chat        { mensagens: [{ papel: 'usuario'|'assistente', texto }] }
//                        -> { ok, data: { resposta } }
//   POST /ia/transcrever multipart { audio: <arquivo m4a> }
//                        -> { ok, data: { texto } }
// A chave (OPENAI_API_KEY) fica no servidor, nunca no app. Regra de
// negócio continua no servidor.
//
// Em MODO_DEMO tudo responde localmente: o clima usa a Open-Meteo de verdade;
// estoque/tarefas/alertas usam dados de exemplo.

const atraso = (ms) => new Promise((r) => setTimeout(r, ms));

const semAcento = (s) =>
  s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

// ---- cérebro demo -------------------------------------------------------

function valvulaCitada(texto) {
  const t = semAcento(texto);
  return (
    VALVULAS.find((v) => t.includes(v.nome.toLowerCase().replace('válvula ', 'valvula '))) ||
    VALVULAS.find((v) => t.includes(v.id.replace('v', 'valvula '))) ||
    VALVULAS[0]
  );
}

async function respostaClima(texto) {
  const v = valvulaCitada(texto);
  try {
    const d = await buscarClima(v);
    const c = d.current;
    const [ic, desc] = wmo(c.weather_code);
    const amanha = d.daily;
    return (
      `${ic} Na ${v.nome} agora: ${Math.round(c.temperature_2m)}°C, ${desc.toLowerCase()}, ` +
      `umidade ${c.relative_humidity_2m}% e vento de ${Math.round(c.wind_speed_10m)} km/h.\n\n` +
      `Amanhã: máxima de ${Math.round(amanha.temperature_2m_max[1])}°C, mínima de ` +
      `${Math.round(amanha.temperature_2m_min[1])}°C, ${amanha.precipitation_probability_max[1]}% de chance de chuva.`
    );
  } catch (_) {
    return 'Não consegui consultar o clima agora — verifique a conexão e tente de novo.';
  }
}

async function responderDemo(texto) {
  const t = semAcento(texto);

  if (/clima|tempo|chuva|chover|temperatura|vento|umidade/.test(t)) {
    return respostaClima(texto);
  }
  if (/estoque|produto|defensivo|insumo|almoxarifado/.test(t)) {
    return (
      '📦 Resumo do estoque (Almoxarifado Sede):\n\n' +
      '• Mancozeb 800: 42 kg (ok)\n' +
      '• Óleo mineral: 8 L (⚠️ abaixo do mínimo)\n' +
      '• Sulfato de potássio: 320 kg (ok)\n\n' +
      'Quer o detalhe de algum item?'
    );
  }
  if (/tarefa|atividade|servico|hoje|pendente/.test(t)) {
    return (
      '✓ Você tem 6 tarefas hoje:\n\n' +
      '• Ronda MIP — Válvulas 5A e 8 (manhã)\n' +
      '• Poda — Válvula 12 (atrasada desde ontem)\n' +
      '• Conferir válvula 14 (pedido do Carlos)\n\n' +
      'Posso abrir a lista completa na aba Tarefas.'
    );
  }
  if (/alerta|praga|cigarrinha|mosca|doenca/.test(t)) {
    return (
      '🚨 2 alertas abertos:\n\n' +
      '• Crítico: cigarrinha a 18% na Válvula 5A — momento de pulverizar\n' +
      '• Info: Válvula 8 sem monitoramento há 6 dias\n\n' +
      'Toque no sino da tela inicial para ver os detalhes.'
    );
  }
  if (/valvula|irriga|bomba|agua/.test(t)) {
    const v = valvulaCitada(texto);
    return (
      `💧 ${v.nome}: irrigação concluída hoje às 06h40 (2h10 de bombeamento). ` +
      'Próximo turno programado para amanhã às 05h30. Nenhuma anomalia registrada.'
    );
  }
  if (/\b(oi|ola|bom dia|boa tarde|boa noite)\b/.test(t)) {
    return 'Olá! 👋 Posso ajudar com clima das válvulas, estoque, tarefas do dia, alertas e irrigação. O que você precisa?';
  }
  return (
    'Ainda estou aprendendo! Por enquanto consigo responder sobre:\n\n' +
    '• Clima das válvulas ("como está o clima na válvula 8?")\n' +
    '• Estoque ("quanto tem de óleo mineral?")\n' +
    '• Tarefas de hoje\n' +
    '• Alertas abertos\n' +
    '• Status de irrigação'
  );
}

// ---- API pública do serviço ---------------------------------------------

// mensagens: [{ papel: 'usuario'|'assistente', texto }] — a última é do usuário
// Devolve { resposta, pendente } — `pendente` = { resumo } quando o agente
// tem uma ESCRITA aguardando o "sim" do usuário (vira cartão de confirmação).
export async function perguntar(mensagens) {
  if (MODO_DEMO) {
    await atraso(500 + Math.random() * 600); // simula latência do servidor
    const ultima = mensagens[mensagens.length - 1];
    return { resposta: await responderDemo(ultima.texto), pendente: null };
  }
  const resp = await http.post('/ia/chat', { mensagens });
  return { resposta: resp.data.resposta, pendente: resp.data.pendente || null };
}

// Frases de exemplo devolvidas pela "transcrição" em modo demo
const TRANSCRICOES_DEMO = [
  'Como está o clima na válvula 5A?',
  'Quais são as minhas tarefas de hoje?',
  'Tem algum alerta aberto?',
];
let _idxTranscricao = 0;

export async function transcreverAudio(uri) {
  if (MODO_DEMO) {
    await atraso(900);
    const texto = TRANSCRICOES_DEMO[_idxTranscricao % TRANSCRICOES_DEMO.length];
    _idxTranscricao += 1;
    return texto;
  }
  // multipart não passa pelo http.js (que força JSON): fetch direto,
  // sem Content-Type manual — o fetch monta o boundary sozinho
  const base = await lerBaseUrl();
  if (!base) {
    // sem código de empresa não há servidor de IA — a mensagem vira o
    // aviso na tela do assistente (mesmo caminho de "falhou a transcrição")
    throw new Error('Informe o código da empresa para usar o assistente.');
  }
  const form = new FormData();
  form.append('audio', { uri, name: 'comando.m4a', type: 'audio/m4a' });
  const token = await lerToken();
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), TIMEOUT * 2); // áudio demora mais
  try {
    const resposta = await fetch(`${base}/ia/transcrever`, {
      method: 'POST',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      body: form,
      signal: ctrl.signal,
    });
    const json = await resposta.json();
    if (!resposta.ok || json.ok === false) {
      throw new Error(json.message || 'Não consegui transcrever o áudio.');
    }
    return json.data.texto;
  } finally {
    clearTimeout(t);
  }
}

export default { perguntar, transcreverAudio };
