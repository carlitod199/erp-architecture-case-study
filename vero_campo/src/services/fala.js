import * as Speech from 'expo-speech';

// Voz do assistente (TTS) do VERO Campo — envolve expo-speech.
// Usado pelo modo mãos-livres (AgenteScreen) para LER as respostas em voz alta,
// deixando o operador com as mãos livres no campo.
//
// Só o que o app precisa: falar(texto, aoTerminar?) e parar().
// O restante da API do expo-speech (pause/resume/vozes) não é usado aqui.

// Remove o único markdown que o assistente emite (**negrito**) antes de falar —
// senão o TTS lê "asterisco asterisco". Também enxuga espaços duplicados.
function limpar(texto) {
  return String(texto || '')
    .replace(/\*\*(.*?)\*\*/g, '$1') // **negrito** -> negrito
    .replace(/\s+/g, ' ')
    .trim();
}

// Fala `texto` em pt-BR. `aoTerminar` (opcional) é chamado quando a fala conclui
// OU quando é interrompida/dá erro — assim o chamador nunca fica "preso" esperando.
function falar(texto, aoTerminar) {
  const conteudo = limpar(texto);
  if (!conteudo) {
    if (aoTerminar) aoTerminar();
    return;
  }
  // Interrompe qualquer fala anterior antes de começar a nova (evita fila).
  Speech.stop();
  Speech.speak(conteudo, {
    language: 'pt-BR',
    onDone: () => aoTerminar && aoTerminar(),
    onStopped: () => aoTerminar && aoTerminar(),
    onError: () => aoTerminar && aoTerminar(),
  });
}

// Interrompe a fala atual (barge-in: chamado antes de gravar um novo comando).
function parar() {
  Speech.stop();
}

export { falar, parar };
export default { falar, parar };
