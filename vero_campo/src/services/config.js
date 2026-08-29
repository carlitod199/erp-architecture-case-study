// Constantes globais do app (Opção B: backend PHP /api/v1 do VERO).
// Multi-cliente (08/2026): a URL base NÃO mora mais aqui — ela é POR-APARELHO,
// montada a partir do código da empresa digitado pelo operador
// (https://<codigo>.example.com/api/v1). Ver src/services/ambiente.js.
// EXPO_PUBLIC_API_URL segue como override de desenvolvimento (inlined pelo
// Expo no bundle — permite Expo Go apontando pra LAN sem código de empresa).

// Modo demo (sem backend): aceita qualquer login e responde IA localmente.
// Desligado em 06/07/2026 — a API /api/v1 está no ar (auth, sync, escrita, IA).
export const MODO_DEMO = false;

// tempo limite padrão das chamadas (ms)
export const TIMEOUT = 15000;

// prazo do token "lembrar dispositivo" (dias) — decisão P-APP-4
export const TOKEN_DIAS = 30;

export default { TIMEOUT, TOKEN_DIAS, MODO_DEMO };
