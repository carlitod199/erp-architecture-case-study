import http from './http';

// FONTE ÚNICA do contrato da API (Onda 5.6): as telas enfileiram escritas
// offline usando estas rotas — nunca strings soltas. Escrita é idempotente
// por client_uuid; leitura por delta (?desde=).

export const rotas = {
  apontamentos: '/apontamentos',
  apontamentoConcluirId: (id) => `/apontamentos/${id}/concluir`,
  apontamentoProducaoId: (id) => `/apontamentos/${id}/producao`,
  anexos: '/anexos',
  monitoramentos: '/monitoramentos',
  monitoramentosEnviar: '/monitoramentos/enviar',
  irrigacao: '/irrigacao/apontamentos',
  atividadeStatus: (id) => `/atividades/${id}/status`,
  alertaReconhecer: (id) => `/alertas/${id}/reconhecer`,
  aplicacaoConfirmar: (id) => `/aplicacoes/${id}/confirmar`,
  aplicacaoAssinar: (id) => `/aplicacoes/${id}/assinar`,
  // 23/07: DF emitida no campo (sem id) — confirma/assina resolvendo pela
  // emissao_uuid; a fila envia a emissão antes (pai_uuid)
  aplicacaoConfirmarCampo: '/aplicacoes/confirmar',
  aplicacaoAssinarCampo: '/aplicacoes/assinar',
  maquinaHorimetro: (id) => `/maquinas/${id}/horimetro`,
  maquinaAbastecimento: (id) => `/maquinas/${id}/abastecimento`,
  // Onda 2 (22/07): emitir DF pelo campo e conferência de insumo por QR
  aplicacaoEmitir: '/aplicacoes',
  recebimentoConfirmar: '/recebimentos/confirmar',
  // 23/07: colheita do campo vai DIRETO para a tela Colheita do sistema
  colheitas: '/colheitas',
  colheitaRealizadoId: (id) => `/colheitas/${id}/realizado`,
  // Romaneio de carga (colheita_cargas) — registro de campo, idempotente
  cargas: '/cargas',
  // COMPRAS (app): solicitar · aprovar/rejeitar pedido · receber contra pedido
  compraSolicitar: '/compras/solicitacoes',
  compraDecidir: (id, decisao) => `/compras/pedidos/${id}/${decisao}`, // decisao: 'aprovar'|'rejeitar'
  compraReceber: (id) => `/compras/pedidos/${id}/receber`,
  // PACKING HOUSE (19/08): recepção de cargas e posto de bipagem — escritas
  // idempotentes por client_uuid (o bipe INCREMENTA: cada leitura leva um
  // uuid próprio, senão o reenvio da fila contaria caixa dobrada)
  packingRecepcao: '/packing/recepcao',
  packingBeep: '/packing/apontar/beep',
};

export const veroApi = {
  // --- auth ---
  login: (email, senha, device) =>
    http.post('/auth/login', { email, senha, device }, { autenticado: false }),
  refresh: () => http.post('/auth/refresh'),
  logout: () => http.post('/auth/logout'),

  // --- sincronização (leitura por módulo, delta) ---
  sync: (modulo, desde) =>
    http.get(`/sync/${modulo}${desde ? `?desde=${encodeURIComponent(desde)}` : ''}`),

  // --- apontamento unificado (J3) ---
  criarApontamento: (payload) => http.post('/apontamentos', payload), // client_uuid no payload
  concluirApontamento: (clientUuid, dados) =>
    http.post(`/apontamentos/${clientUuid}/concluir`, dados), // dois tempos (hora-máquina)

  // --- monitoramento MIP (J1) ---
  criarMonitoramento: (payload) => http.post('/monitoramentos', payload),
  enviarMonitoramentos: (uuids) => http.post('/monitoramentos/enviar', { uuids }),
  recebidosLider: (desde) => veroApi.sync('mip_recebidos', desde),

  // --- irrigação (J5) ---
  criarIrrigacao: (payload) => http.post('/irrigacao/apontamentos', payload),

  // --- tarefas (J2) ---
  statusTarefa: (id, status) => http.post(`/atividades/${id}/status`, { status }),

  // --- alertas (J4) ---
  reconhecerAlerta: (id) => http.post(`/alertas/${id}/reconhecer`),
  produtosDoAlvo: (alvoId) => http.get(`/mip/alvos/${alvoId}/produtos`), // RT × saldo

  // --- aplicações DF/IF (J4 / P-APP-2) ---
  confirmarAplicacao: (id, dados) => http.post(`/aplicacoes/${id}/confirmar`, dados),
  assinarAplicacao: (id, dados) => http.post(`/aplicacoes/${id}/assinar`, dados),

  // --- máquinas ---
  registrarHorimetro: (maquinaId, payload) =>
    http.post(rotas.maquinaHorimetro(maquinaId), payload),
  registrarAbastecimento: (maquinaId, payload) =>
    http.post(rotas.maquinaAbastecimento(maquinaId), payload),

  // --- anexos (foto, em 2º plano) ---
  enviarAnexo: (form) =>
    http.post('/anexos', form, { autenticado: true }), // multipart montado na chamada

  // --- packing house (leituras diretas — precisam de rede) ---
  packingContexto: () => http.get('/packing/contexto'),
  packingPendentes: (unidadeId) =>
    http.get(`/packing/recepcao/pendentes?unidade_id=${encodeURIComponent(unidadeId)}`),
  packingAvaliar: (payload) => http.post('/packing/recepcao/avaliar', payload),
  packingTally: (data, setorId) =>
    http.get(`/packing/apontar/tally?data=${encodeURIComponent(data)}${setorId ? `&setor_id=${encodeURIComponent(setorId)}` : ''}`),
  packingRomaneio: (numero) =>
    http.get(`/packing/romaneio?numero=${encodeURIComponent(numero)}`),
};

export default veroApi;
