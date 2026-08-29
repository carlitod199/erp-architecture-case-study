import http from '../services/http';
import { TIMEOUT } from '../services/config';
import { lerBaseUrl } from '../services/ambiente';
import { lerToken } from '../services/authStorage';
import { gravarCache, removerDoCache, limparModulo, lerDelta, gravarDelta, zerarDeltas, garantirDonoDosDados } from './db';
import { pendentes, marcar, registrarFalha } from './fila';

// Módulos que devolvem o RETRATO ATUAL inteiro (sem delta): o cache é
// substituído, não fundido — registros que saíram do retrato somem.
const MODULOS_RETRATO = new Set(['fenologia', 'apontamentos_abertos', 'colheitas_pendentes', 'calc_parametros',
  'compras_aprovacoes_pendentes', // caixa do supervisor: some ao decidir
  'financeiro', // títulos a pagar/receber: view de retrato (substitui o cache)
  'cargas_colheita']); // romaneios de carga: view de retrato

// Códigos que o servidor devolve quando a REGRA DE NEGÓCIO rejeitou — reenviar
// nunca vai funcionar; o item vira 'falha' na hora (sem loop de retentativa).
const REJEICOES_DEFINITIVAS = new Set([
  'sem_permissao', 'status_invalido', 'tipo_invalido', 'alvo_invalido',
  'talhao_invalido', 'maquina_invalida', 'atividade_invalida', 'campo_obrigatorio',
  'leitura_menor', 'ja_reconhecido', 'alvo_repetido', 'litros_invalido',
  'horimetro_invalido', 'extensao_invalida', 'mime_invalido', 'arquivo_grande',
  'assinatura_grande', 'uuids_invalidos', 'nao_encontrado',
  'conflito', // 6.2: o escritório mexeu depois — reenviar sobrescreveria decisão
  // confirmação de aplicação (23/07): erros de "corrija e refaça" — reenviar
  // igual não adianta (o operador ajusta e confirma de novo)
  'sem_operador', 'sem_itens', 'quantidade_invalida', 'hora_invalida',
  'safra_fechada', 'sem_peso_caixa', 'confirmar_falhou',
  // compras (app): regra de negócio rejeitou — não retenta
  'solicitacao_vazia', 'aprovacao_invalida', 'pedido_nao_recebivel', 'periodo_fechado',
  // romaneio de carga: número já existe — reenviar igual nunca resolve
  'romaneio_duplicado',
  // packing (19/08): regra de negócio rejeitou o bipe/recepção — o operador
  // corrige no web (QR Codes/atividades) e bipa de novo; reenviar igual não adianta
  'unidade_invalida', 'cracha_invalido', 'funcao_indefinida',
  'posto_sem_atividade', 'beep_recusado',
  'sem_recepcao', // embalamento bloqueado sem recepção aceita — receber e bipar de novo
]);

// Módulos de leitura sincronizados no MVP
export const MODULOS_LEITURA = [
  'talhoes', 'safras', 'atividades', 'alertas',
  'estoque', 'maquinas', 'mip_referencias', 'parametros',
  'mip_recebidos', // caixa do líder (exige mip.ver; sem a permissão o módulo só é pulado)
  'fenologia',     // fase POR VARIEDADE de cada válvula (dia0 = poda; migs 157/159)
  'aplicacoes',    // fila de DFs do campo (exige mip.ver)
  'estoque_movimentacoes', // extrato do estoque, sem custos (P-75)
  'apontamentos_abertos',  // serviços iniciados (dois estágios) — aba Tarefas
  'colaboradores',         // finalizar c/ premiação (sem salários — P-75)
  'colheitas_pendentes',   // colheitas lançadas no escritório aguardando produção
  'calc_parametros',       // calculadora de MO (rendimentos/custos/premiação por tipo)
  // COMPRAS (exigem compras.ver; sem a permissão o módulo é só pulado)
  'fornecedores',
  'compras_solicitacoes',
  'compras_pedidos',
  'compras_aprovacoes_pendentes',
  // FINANCEIRO (exige financeiro.contas_pagar.ver e/ou .contas_receber.ver;
  // sem nenhuma das duas, o módulo é só pulado com estado parcial)
  'financeiro',
  // COLHEITA: cargas/romaneios (exige agro.romaneios_colheita.ver)
  'cargas_colheita',
];

// Baixa (carga inicial + delta) de um módulo e grava no cache local.
// Onda 6: pagina o delta (o servidor limita a 500 por página), aplica
// tombstones (_excluido=1 → remove do cache) e trata módulos de retrato.
const PAGINA = 500;
const MAX_PAGINAS = 20; // trava de segurança (10 mil registros por sync)

export async function baixarModulo(modulo) {
  let desde = MODULOS_RETRATO.has(modulo) ? null : await lerDelta(modulo);
  let total = 0;
  let serverTime = null;

  for (let pagina = 0; pagina < MAX_PAGINAS; pagina++) {
    const resp = await http.get(
      `/sync/${modulo}${desde ? `?desde=${encodeURIComponent(desde)}` : ''}`
    );
    const itens = resp.data?.itens || [];
    serverTime = resp.sync?.server_time || serverTime;

    // 22/07: cursor "do futuro" (o server_time da API já rodou 3h à frente do
    // relógio do banco) esconderia registros novos por horas — detecta e refaz
    // a carga CHEIA do módulo (comparação lexicográfica funciona no formato SQL)
    if (desde && serverTime && desde > serverTime) {
      desde = null;
      continue;
    }

    const vivos = itens.filter((i) => !i._excluido);
    const excluidos = itens.filter((i) => i._excluido).map((i) => i.id);

    if (MODULOS_RETRATO.has(modulo)) {
      // retrato atual: substitui o cache inteiro (uma página basta)
      await limparModulo(modulo);
      if (vivos.length) await gravarCache(modulo, vivos);
      total = vivos.length;
      break;
    }

    if (vivos.length) await gravarCache(modulo, vivos);
    if (excluidos.length) await removerDoCache(modulo, excluidos);
    total += vivos.length;

    if (itens.length < PAGINA) break; // última página
    // próxima página: avança o cursor para o updated_at do último item
    const ultimo = itens[itens.length - 1]?.updated_at;
    if (!ultimo || ultimo === desde) break; // sem progresso — evita loop
    desde = ultimo;
  }

  if (serverTime) await gravarDelta(modulo, serverTime);
  return total;
}

// 22/07: cursores gravados enquanto o server_time saía do relógio errado do
// PHP deixaram registros num "ponto cego" (updated_at menor que o cursor).
// UMA VEZ por aparelho, zera todos os cursores e refaz a carga cheia.
const RESSINC_VERSAO = '2026-07-30-grupo-geometria-compras';

// Sincronização completa de leitura (chamar no login e ao reconectar).
export async function sincronizarLeitura(onProgresso) {
  // multi-cliente: se os dados locais pertencem a OUTRO servidor (troca de
  // empresa, ou instalação da era servidor01 sem marcador), expurga antes de
  // sincronizar — senão os caches dos dois bancos se misturam.
  try {
    await garantirDonoDosDados(await lerBaseUrl());
  } catch (_) { /* sem SQLite/ambiente agora: tenta na próxima */ }

  try {
    const marca = await lerDelta('__ressinc');
    if (marca !== RESSINC_VERSAO) {
      await zerarDeltas();
      await gravarDelta('__ressinc', RESSINC_VERSAO);
    }
  } catch (_) { /* sem SQLite agora: tenta na próxima */ }

  let total = 0;
  for (const m of MODULOS_LEITURA) {
    try {
      const n = await baixarModulo(m);
      total += n;
      onProgresso && onProgresso(m, n);
    } catch (e) {
      // segue para o próximo módulo; a UI mostra estado parcial
      onProgresso && onProgresso(m, -1, e);
    }
  }
  return total;
}

// Foto/anexo: multipart direto (o http.js força JSON) — mesmo padrão do
// transcreverAudio. O servidor resolve origem_uuid → id do apontamento.
async function enviarAnexoMultipart(payload) {
  // Sem código de empresa não há para onde enviar: classifica como
  // 'sem_conexao' para o item voltar a 'pendente' e a fila parar — nunca
  // vira 'falha' definitiva por falta de ambiente (não quebra a fila offline).
  const base = await lerBaseUrl();
  if (!base) {
    const e = new Error('Informe o código da empresa.');
    e.codigo = 'sem_conexao';
    throw e;
  }
  const token = await lerToken();
  const form = new FormData();
  form.append('arquivo', {
    uri: payload.uri,
    name: payload.nome || 'foto.jpg',
    type: payload.mime || 'image/jpeg',
  });
  form.append('client_uuid', payload.client_uuid);
  form.append('origem_uuid', payload.origem_uuid);
  if (payload.origem_tipo) form.append('origem_tipo', payload.origem_tipo); // 7.2: monitoramento/horimetro…
  if (payload.descricao) form.append('descricao', payload.descricao);

  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), TIMEOUT * 2); // upload é mais lento
  try {
    const resp = await fetch(`${base}/anexos`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
      body: form,
      signal: ctrl.signal,
    });
    const json = await resp.json();
    if (!resp.ok || json.ok === false) {
      const e = new Error(json.message || 'Falha ao enviar a foto.');
      e.codigo = json.error || 'erro'; // preserva o código p/ classificar a falha
      throw e;
    }
  } finally {
    clearTimeout(t);
  }
}

// Envia a fila pendente. Idempotente por client_uuid — reenvio não duplica.
// Erros são CLASSIFICADOS (Onda 5): sem conexão devolve o item a 'pendente' e
// para a fila; rejeição de negócio vira 'falha' na hora; o resto entra em
// backoff exponencial até o teto.
export async function enviarFila(onItem) {
  const itens = await pendentes();
  let enviados = 0;
  for (const it of itens) {
    await marcar(it.client_uuid, 'enviando');
    try {
      const payload = JSON.parse(it.payload);
      if (it.tipo === 'anexo') {
        await enviarAnexoMultipart(payload);
      } else {
        await http[it.metodo.toLowerCase()](it.rota, payload);
      }
      await marcar(it.client_uuid, 'confirmado');
      enviados += 1;
      onItem && onItem(it, 'confirmado');
    } catch (e) {
      const codigo = e?.codigo || '';
      if (codigo === 'sem_conexao') {
        // offline não é falha: volta a 'pendente' sem contar tentativa e
        // para a fila — os próximos falhariam do mesmo jeito
        await marcar(it.client_uuid, 'pendente');
        onItem && onItem(it, 'pendente', e);
        break;
      }
      const estado = await registrarFalha(
        it.client_uuid,
        e?.message || 'falha',
        REJEICOES_DEFINITIVAS.has(codigo)
      );
      onItem && onItem(it, estado, e);
      // não interrompe a fila — tenta os próximos
    }
  }
  return enviados;
}

export default { MODULOS_LEITURA, baixarModulo, sincronizarLeitura, enviarFila };
