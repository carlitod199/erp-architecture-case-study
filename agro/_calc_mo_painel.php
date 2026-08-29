<?php
/* ============================================================
   VERO — A4-06 (UI/casca): painel da calculadora de mão de obra,
   embutido no apontamento. Painel de SUGESTÃO DE PLANEJAMENTO — NÃO grava
   nada no apontamento. Dimensiona pessoas/dias/custo pela fórmula
   "rendimento por diária" (motor agro/_calc_mo.php, PESQUISA §3).

   REATIVO AO APONTAMENTO (A4-06): o painel NÃO tem mais selects próprios de
   Safra/Válvula/Tipo. Ele LÊ os campos que o usuário já preenche no formulário
   do apontamento (talhão `f-talhao`, safra `f-safra`, tipo `f-tipo`, data
   `[name=data_apontamento]`) e recalcula sozinho nos eventos change/input
   desses campos (além dos inputs próprios de planejamento). Ao mudar o talhão,
   o "trabalho total" auto-preenche pela válvula: nº de plantas (unidade=planta)
   ou área ha (unidade=ha); caixa/cacho ficam manuais.

   Layout em 3 blocos (design aprovado A0, docs/mockups/calc_mo_redesign.html):
   1) Contexto (chips herdados do apontamento), 2) Planejamento (grid),
   3) Card de resultado proeminente (diárias/pessoas·dias/custo R$).

   INVARIANTES: todos os controles do painel usam só `id` (SEM `name`) → nunca
   entram no POST do apontamento; o painel só LÊ o form. Não grava, não altera
   o salvamento. Sem parâmetro para o tipo → avisa que aguarda o RT (P-91).

   MIOLO = os VALORES (rendimento/diária, custo) vêm da P-91 e são semeados
   pelo A0 em agro_calc_parametros. Motor canônico em agro/_calc_mo.php (A0) —
   este arquivo NÃO toca nele.
   ============================================================ */
declare(strict_types=1);

/* motor canônico: fornece vero_calc_mo_rendimento_observado_mapa() e demais funções
   usadas por este painel. require_once idempotente — corrige "Call to undefined function"
   nas telas que carregam só o painel (ex.: apontamento). */
require_once __DIR__ . '/_calc_mo.php';

/** HTML+CSS+JS do painel da calculadora de MO (sugestão; pode ficar dentro do <form>). */
/* Campos de PLANEJAMENTO da calculadora (os 2 blocos de entrada: modo "Trabalho
   total" e modo "Meta"). Extraídos do painel para poderem ser posicionados no
   form do apontamento, logo após "Hectares". O restante da calculadora (seletor
   de modo, contexto e resultado) + CSS/JS continuam em vero_calc_mo_painel_html(),
   que DEVE ser renderizado na MESMA página (o JS liga tudo por id, e o CSS .cmo-*
   vem do <style> do painel). Sem name nos inputs → não entram no POST. */
function vero_calc_mo_campos_planejamento_html(): string
{
    /* ERP-CALC 22/07: peso da caixa default do tenant (colheita.peso_caixa_kg) —
       usado p/ converter a produção prevista (kg) em caixas na colheita. Editável. */
    $pesoCxTenant = 0.0;
    if (function_exists('vero_srv_param')) {
        $pesoCxTenant = (float)vero_srv_param('colheita.peso_caixa_kg', '0');
    } elseif (function_exists('vero_val')) {
        $pesoCxTenant = (float)(vero_val(
            "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'colheita.peso_caixa_kg'",
            [':t' => vero_tenant()]) ?: 0);
    }
    ob_start(); ?>
      <!-- Planejamento (sistema ÚNICO): a quantidade serve como trabalho total OU
           meta de produção; a Base define se você informa os dias (→ pessoas) ou as
           pessoas (→ dias). Sem modo Meta separado — é o mesmo cálculo. -->
      <section class="cmo-block">
        <p class="cmo-block__t">Planejamento</p>
        <div class="cmo-grid">
          <div class="cmo-field">
            <label>Total a fazer</label>
            <div class="cmo-inputunit">
              <input type="text" id="calc-mo-trab" inputmode="decimal" placeholder="0">
              <select id="calc-mo-unid" class="cmo-unit-sel" aria-label="Unidade do total a fazer">
                <option value="">unid.</option>
                <option value="planta">planta</option>
                <option value="fila">fila</option>
                <option value="ha">ha</option>
                <option value="cacho">cacho</option>
                <option value="caixa">caixa</option>
                <option value="kg">kg</option>
                <option value="contentor">contentor</option>
                <option value="hora">hora</option>
              </select>
            </div>
            <div id="calc-mo-autonote" style="margin-top:5px;font-size:11px;line-height:1.35;color:var(--muted,#8A7C68)"></div>
          </div>
          <!-- ERP-CALC 22/07 (áudio do gestor): COLHEITA é por QUILO/CAIXA, não por
               planta. Unidade caixa/kg → o usuário informa a PRODUÇÃO PREVISTA da
               área (kg); com o peso da caixa (default do tenant/cultura, editável)
               o painel converte em caixas e preenche o "Total a fazer". Só id, sem
               name → nunca entram no POST do apontamento. -->
          <div class="cmo-field" id="calc-mo-wrap-prodkg" style="display:none">
            <label>Produção prevista <span class="cmo-unit">kg na área</span></label>
            <input type="text" id="calc-mo-prodkg" inputmode="decimal" placeholder="ex.: 40.000">
          </div>
          <div class="cmo-field" id="calc-mo-wrap-pesocx" style="display:none">
            <label id="calc-mo-pesocx-lbl">Peso da caixa <span class="cmo-unit">kg</span></label>
            <input type="text" id="calc-mo-pesocx" inputmode="decimal" placeholder="ex.: 22,00"<?=
              $pesoCxTenant > 0 ? ' value="' . h(number_format($pesoCxTenant, 2, ',', '.')) . '"' : '' ?>>
          </div>
          <div class="cmo-field">
            <label>Meta <span class="cmo-unit">por pessoa/dia</span></label>
            <input type="text" id="calc-mo-meta" inputmode="decimal" placeholder="—">
          </div>
          <!-- V-03/D-1: média de produtividade por pessoa (opcional). Preenchida →
               dimensiona a equipe pela MÉDIA; a Meta segue como o nº impresso na OS. -->
          <div class="cmo-field">
            <label>Média de produtividade <span class="cmo-unit">por pessoa/dia</span></label>
            <input type="text" id="calc-mo-media" inputmode="decimal" placeholder="—">
          </div>
          <div class="cmo-field">
            <label>Premiação <span class="cmo-unit">R$/un. acima</span></label>
            <input type="text" id="calc-mo-premio" inputmode="decimal" placeholder="—">
          </div>
          <div class="cmo-field">
            <label>Base do cálculo</label>
            <select id="calc-mo-modo">
              <option value="prazo">Dias → nº de pessoas</option>
              <option value="pessoas">Pessoas → nº de dias</option>
            </select>
          </div>
          <div class="cmo-field" id="calc-mo-wrap-prazo">
            <label>Dias para executar a atividade</label>
            <input type="text" id="calc-mo-prazo" inputmode="numeric" placeholder="0">
          </div>
          <div class="cmo-field" id="calc-mo-wrap-pessoas" style="display:none">
            <label>Pessoas na equipe</label>
            <input type="text" id="calc-mo-pessoas" inputmode="numeric" placeholder="0">
          </div>
        </div>
      </section>
    <?php return ob_get_clean();
}

function vero_calc_mo_painel_html(): string
{
    $t    = vero_tenant();
    $hoje = date('Y-m-d');

    /* parâmetros vigentes de TODOS os tipos → mapa p/ o JS */
    $rows = vero_rows(
        "SELECT tipo_atividade_id, chave, valor FROM agro_calc_parametros
          WHERE tenant_id = :t AND ativo = 1
            AND vigencia_inicio <= :d1 AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)",
        [':t' => $t, ':d1' => $hoje, ':d2' => $hoje]); /* :d1/:d2 distintos — QA-011 */
    $params = [];
    foreach ($rows as $r) $params[(int)$r['tipo_atividade_id']][(string)$r['chave']] = (float)$r['valor'];

    /* ── Custo da diária por 3 CAMINHOS (solicitação 03/08) ─────────────────
       Prioridade: (1) FOLHA de pagamento (média), (2) PESSOAS E EQUIPE (média),
       (3) PARÂMETRO da atividade (agro_calc_parametros, per-tipo). Os dois
       primeiros são tenant-wide (o painel não tem seletor de equipe). Valores
       da PRÓPRIA são MENSAIS (÷ dias úteis no JS = diária, com dias úteis vivo);
       a TERCEIRIZADA já é uma diária. A resolução final é no JS (fillCusto). */
    $diasUteis = (float)(vero_val(
        "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'mo.dias_uteis_mes'",
        [':t' => $t]) ?: 22);
    if ($diasUteis <= 0) $diasUteis = 22;

    $encCfg = vero_srv_encargos_vigente();

    /* (1) FOLHA: média do custo_total (já com encargos) da competência FECHADA mais
       recente ÷ dias úteis = DIÁRIA própria. */
    $folhaRow = vero_row(
        "SELECT DATE_FORMAT(pe.competencia,'%m/%Y') AS comp, ROUND(AVG(l.custo_total),2) AS media
           FROM rh_folha_periodos pe
           JOIN rh_folha_lancamentos l ON l.periodo_id = pe.id AND l.tenant_id = pe.tenant_id
          WHERE pe.tenant_id = :t AND pe.status = 'fechado' AND l.custo_total > 0
          GROUP BY pe.id ORDER BY pe.competencia DESC LIMIT 1", [':t' => $t]);
    $folhaPropDiaria = ($folhaRow && $folhaRow['media'] !== null && (float)$folhaRow['media'] > 0)
        ? round((float)$folhaRow['media'] / $diasUteis, 2) : null;
    $folhaComp = $folhaRow['comp'] ?? null;

    /* (2) PESSOAS E EQUIPE (própria): MÉDIA das DIÁRIAS dos colaboradores ativos.
       diarista/trabalhador rural → valor_diaria (direto); CLT → (salário + encargos
       vigentes) ÷ dias úteis. Quem não tem nenhum dos dois fica de fora da média. */
    $somaD = 0.0; $nP = 0;
    foreach (vero_rows(
        "SELECT tipo_vinculo, salario_mensal, valor_diaria FROM agro_operadores
          WHERE tenant_id = :t AND ativo = 1", [':t' => $t]) as $o) {
        $vd = ($o['valor_diaria'] !== null && (float)$o['valor_diaria'] > 0) ? (float)$o['valor_diaria'] : null;
        if ($vd !== null) { $somaD += $vd; $nP++; continue; }
        if (($o['tipo_vinculo'] ?? '') === 'clt' && (float)($o['salario_mensal'] ?? 0) > 0) {
            $bruto = (float)$o['salario_mensal'];
            $enc   = $encCfg ? (float)(vero_srv_encargos_calc($bruto, $encCfg)['total'] ?? 0) : 0.0;
            $somaD += ($bruto + $enc) / $diasUteis; $nP++;
        }
    }
    $pessoasPropDiaria = $nP > 0 ? round($somaD / $nP, 2) : null;

    /* terceirizada: média das diárias (modalidade Diária, ativos) — já é DIÁRIA. */
    $tercMedia = vero_val(
        "SELECT ROUND(AVG(valor_diaria),2) FROM rh_terceirizados
          WHERE tenant_id = :t AND ativo = 1 AND modalidade_padrao = 'diaria' AND valor_diaria > 0",
        [':t' => $t]);
    $tercDiaria = ($tercMedia !== null && (float)$tercMedia > 0) ? (float)$tercMedia : null;

    $custoCad = [
        'diasUteis'         => $diasUteis,
        'folhaPropDiaria'   => $folhaPropDiaria,   /* (1) diária própria pela folha */
        'folhaComp'         => $folhaComp,          /* competência p/ o rótulo de origem */
        'pessoasPropDiaria' => $pessoasPropDiaria,  /* (2) média das diárias dos colaboradores */
        'nPessoas'          => $nP,
        'tercDiaria'        => $tercDiaria,         /* (2) média das diárias dos terceirizados */
    ];

    /* tipos de atividade (id → unidade padrão) — p/ resolver a unidade do f-tipo */
    $tipos = vero_rows(
        "SELECT id, COALESCE(unidade_padrao,'') AS unidade_padrao
           FROM agro_tipos_atividade WHERE tenant_id = :t", [':t' => $t]);
    $unid = [];
    foreach ($tipos as $r) $unid[(int)$r['id']] = (string)$r['unidade_padrao'];

    /* premiação por tipo: {meta, valor, unidade} da regra ATIVA vigente com tarifa (>0).
       Estimativa → pega a mais específica/recente por tipo (cultura-específica vence o
       coringa); o painel não sabe a cultura no server, e no tenant típico é única. */
    $rp = vero_rows(
        "SELECT tipo_atividade_id AS tipo, unidade, meta_qtd, valor_acima_meta
           FROM rh_regras_premiacao
          WHERE tenant_id = :t AND ativo = 1 AND valor_acima_meta > 0
            AND (vigencia_inicio IS NULL OR vigencia_inicio <= :d1)
            AND (vigencia_fim    IS NULL OR vigencia_fim    >= :d2)
          ORDER BY (cultura_id IS NULL), id DESC",  /* específica primeiro, recente desempata */
        [':t' => $t, ':d1' => $hoje, ':d2' => $hoje]);
    $premia = [];
    foreach ($rp as $r) {
        $tid = (int)$r['tipo'];
        if (isset($premia[$tid])) continue;         /* mantém a 1ª (mais específica/recente) */
        $premia[$tid] = ['meta' => (float)$r['meta_qtd'], 'valor' => (float)$r['valor_acima_meta'],
                         'unidade' => (string)$r['unidade']];
    }

    /* válvulas/talhões (id → nº plantas, área ha) + FALLBACKS da variedade/cultura
       (cachos por planta, peso da caixa/contentor, produtividade esperada). Estes
       são os valores-BASE; a SAFRA pode sobrescrever cachos/caixas/peso (V-06/D-2).
       (o <select> visível de válvula foi REMOVIDO; a válvula é herdada do talhão) */
    $valvulas = vero_rows(
        "SELECT tl.id, tl.area_ha, tl.num_plantas, tl.num_filas,
                vr.produtividade_esperada AS prod_kg_ha,
                vr.cachos_por_planta      AS cachos_planta,
                cu.peso_unidade_kg        AS peso_caixa,
                cu.peso_contentor_kg      AS peso_contentor
           FROM agro_talhoes tl
           LEFT JOIN agro_variedades vr ON vr.id = tl.variedade_id AND vr.tenant_id = tl.tenant_id
           LEFT JOIN agro_culturas   cu ON cu.id = vr.cultura_id   AND cu.tenant_id = tl.tenant_id
          WHERE tl.tenant_id = :t", [':t' => $t]);
    /* base (fallback) por talhão — usado quando NÃO há safra selecionada */
    $talBase = [];
    foreach ($valvulas as $v) {
        $talBase[(int)$v['id']] = [
            'plantas'    => ($v['num_plantas'] !== null && $v['num_plantas'] !== '') ? (float)$v['num_plantas'] : null,
            /* amarrio/desponte etc.: total pelas FILAS (fileiras) da válvula, fallback plantas */
            'filas'      => ($v['num_filas']   !== null && $v['num_filas']   !== '') ? (float)$v['num_filas']   : null,
            'area'       => ($v['area_ha']     !== null && $v['area_ha']     !== '') ? (float)$v['area_ha']     : null,
            /* colheita (C-QA 22/07): total em CAIXAS = produtividade prevista kg/ha × área ÷ peso da caixa */
            'prod_kg_ha' => ($v['prod_kg_ha']  !== null && $v['prod_kg_ha']  !== '') ? (float)$v['prod_kg_ha']  : null,
            'peso_caixa' => ($v['peso_caixa']  !== null && $v['peso_caixa']  !== '') ? (float)$v['peso_caixa']  : null,
            /* WP-CALC Z-06: raleio → cachos = plantas × cachos_por_planta (da variedade) */
            'cachos_planta'  => ($v['cachos_planta']  !== null && $v['cachos_planta']  !== '') ? (float)$v['cachos_planta']  : null,
            /* WP-CALC Z-05: colheita a granel → contentores = produção kg ÷ peso do contentor (da cultura) */
            'peso_contentor' => ($v['peso_contentor'] !== null && $v['peso_contentor'] !== '') ? (float)$v['peso_contentor'] : null,
            /* V-06/D-2: caixas por planta é parâmetro de SAFRA (sem fallback na variedade) */
            'caixas_planta'  => null,
            /* V-05: previsão de colheita (Σ kg) — só existe atrelada a um vínculo de safra */
            'previsto_kg'    => null,
        ];
    }

    /* vínculos Safra × Válvula (agro_safra_talhoes): overrides POR SAFRA. Cada
       campo COALESCE sobre o fallback da variedade/cultura — a safra vence quando
       preenchida; NULL na safra = mantém o fallback (V-06/D-2, reunião 29/07). */
    $safraRows = vero_rows(
        "SELECT st.id, st.talhao_id,
                st.cachos_por_planta AS s_cachos,
                st.caixas_por_planta AS s_caixas,
                st.peso_caixa_kg     AS s_peso_caixa
           FROM agro_safra_talhoes st
          WHERE st.tenant_id = :t", [':t' => $t]);

    /* V-05: previsão de colheita por vínculo — Σ kg_total_previsto (Premium/CAT)
       de colheita_registros por safra_talhao_id. Vira o "previsto (kg)" preferido. */
    $prevRows = vero_rows(
        "SELECT safra_talhao_id, SUM(kg_total_previsto) AS kg_prev
           FROM colheita_registros
          WHERE tenant_id = :t AND safra_talhao_id IS NOT NULL
          GROUP BY safra_talhao_id", [':t' => $t]);
    $prevBySt = [];
    foreach ($prevRows as $r) $prevBySt[(int)$r['safra_talhao_id']] = (float)$r['kg_prev'];

    /* mapa final re-keyed por [talhao][safra]: a chave '0' = "sem safra" (fallback
       puro); cada vínculo de safra vira uma entrada com os overrides já mesclados. */
    $talMap = [];
    foreach ($talBase as $tid => $base) $talMap[$tid] = ['0' => $base];
    $defBase = ['plantas' => null, 'filas' => null, 'area' => null, 'prod_kg_ha' => null, 'peso_caixa' => null,
                'cachos_planta' => null, 'peso_contentor' => null, 'caixas_planta' => null, 'previsto_kg' => null];
    foreach ($safraRows as $s) {
        $tid = (int)$s['talhao_id'];
        $sid = (int)$s['id'];
        $merged = $talBase[$tid] ?? $defBase;
        $sc = ($s['s_cachos']     !== null && $s['s_cachos']     !== '') ? (float)$s['s_cachos']     : null;
        $sk = ($s['s_caixas']     !== null && $s['s_caixas']     !== '') ? (float)$s['s_caixas']     : null;
        $sp = ($s['s_peso_caixa'] !== null && $s['s_peso_caixa'] !== '') ? (float)$s['s_peso_caixa'] : null;
        if ($sc !== null) $merged['cachos_planta'] = $sc;   /* safra vence a variedade */
        if ($sk !== null) $merged['caixas_planta'] = $sk;   /* só a safra define caixas/planta */
        if ($sp !== null) $merged['peso_caixa']    = $sp;   /* safra vence a cultura */
        $merged['previsto_kg'] = $prevBySt[$sid] ?? null;   /* V-05: previsão do vínculo */
        if (!isset($talMap[$tid])) $talMap[$tid] = ['0' => $defBase];
        $talMap[$tid][(string)$sid] = $merged;
    }

    /* rendimento OBSERVADO (Σ quantidade ÷ Σ diárias dos apontamentos reais, na
       unidade canônica, ≥10 diárias) — motor agro/_calc_mo.php. Só 1 chamada nova.
       [tipo_id => ['rendimento'=>float,'n_diarias'=>int]] */
    $mapaObservado = vero_calc_mo_rendimento_observado_mapa();

    $jParams = json_encode($params, JSON_UNESCAPED_UNICODE);
    $jUnid   = json_encode($unid,   JSON_UNESCAPED_UNICODE);
    $jPremia = json_encode($premia, JSON_UNESCAPED_UNICODE);
    $jTal    = json_encode($talMap, JSON_UNESCAPED_UNICODE);
    $jObs    = json_encode($mapaObservado, JSON_UNESCAPED_UNICODE);
    $jCusto  = json_encode($custoCad,      JSON_UNESCAPED_UNICODE);

    ob_start(); ?>
  <style>
  /* Calculadora de MO — tokens do padrão VERO (agro.css) c/ fallback */
  .cmo__body{padding:16px 18px 18px}
  .cmo__sub{margin-left:8px}
  .cmo-block{margin-bottom:18px}
  .cmo-block--last{margin-bottom:0}
  .cmo-seg{display:inline-flex;border:1px solid var(--border,#E3D9C8);border-radius:10px;overflow:hidden;margin-bottom:16px}
  .cmo-seg button{border:0;background:#fff;padding:7px 14px;font:inherit;cursor:pointer;color:var(--muted,#8A7C68)}
  .cmo-seg button.on{background:var(--accent-deep,#00363D);color:#fff;font-weight:600}
  .cmo-block__t{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--accent,#005059);margin:0 0 10px}
  .cmo-ctx{display:flex;flex-wrap:wrap;gap:8px}
  .cmo-chip{display:inline-flex;align-items:center;gap:6px;background:#EEF4F3;border:1px solid #CFE0DD;color:var(--accent-deep,#00363D);border-radius:10px;padding:7px 12px;font-size:13px;font-weight:500}
  .cmo-chip b{font-weight:700}
  .cmo-chip--muted{background:var(--warm,#FBF8F2);border-color:var(--border,#E3D9C8);color:var(--muted,#8A7C68)}
  .cmo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
  .cmo-grid--2{grid-template-columns:repeat(2,1fr)}
  .cmo-field label{display:block;font-size:12px;color:var(--muted,#8A7C68);margin-bottom:5px;font-weight:600}
  .cmo-field input,.cmo-field select{width:100%;padding:9px 11px;border:1px solid var(--border,#E3D9C8);border-radius:10px;font:inherit;background:#fff;color:var(--ink,#241B14)}
  .cmo-field input:focus,.cmo-field select:focus{outline:none;border-color:var(--accent,#005059);box-shadow:0 0 0 3px rgba(0,80,89,.12)}
  .cmo-unit{color:var(--muted,#8A7C68);font-weight:400}
  /* input-group: número + unidade acoplada à direita (Total a fazer) */
  .cmo-inputunit{display:flex;align-items:stretch}
  .cmo-inputunit>input{flex:1;min-width:0;border-radius:10px 0 0 10px;border-right:0}
  .cmo-inputunit>.cmo-unit-sel{width:auto;flex:0 0 auto;margin:0;padding:0 26px 0 10px;font:inherit;font-size:12.5px;
    font-weight:600;color:var(--accent-deep,#00363D);border:1px solid var(--border,#E3D9C8);border-radius:0 10px 10px 0;
    background:var(--warm,#FBF8F2);cursor:pointer}
  .cmo-inputunit>.cmo-unit-sel:focus{outline:none;border-color:var(--accent,#005059);box-shadow:0 0 0 3px rgba(0,80,89,.12);position:relative;z-index:1}
  .cmo-inputunit:focus-within>input{border-color:var(--accent,#005059)}
  .cmo-result{border:1px solid #CFE0DD;background:#F4FAF9;border-radius:14px;padding:16px 18px;display:flex;flex-wrap:wrap;align-items:center;gap:22px}
  .cmo-big{font-size:30px;font-weight:700;color:var(--accent-deep,#00363D);line-height:1;font-variant-numeric:tabular-nums}
  .cmo-big small{font-size:13px;font-weight:600;color:var(--muted,#8A7C68);margin-left:4px}
  .cmo-sep{width:1px;align-self:stretch;background:#CFE0DD}
  .cmo-kpi{min-width:90px}
  .cmo-k{font-size:11px;color:var(--muted,#8A7C68);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}
  .cmo-v{font-size:17px;font-weight:700;font-variant-numeric:tabular-nums}
  .cmo-v.cash{color:var(--accent-deep,#00363D)}
  .cmo-dim-sub{font-weight:500;color:var(--muted,#8A7C68);font-size:12px}
  .cmo-foot{margin-top:12px;font-size:12px;color:var(--muted,#8A7C68)}
  .cmo-foot:empty{display:none}
  .cmo-state{padding:14px 16px;border-radius:12px;font-size:13px}
  .cmo-state--warn{background:#FBF0EC;border:1px solid #E8C6BC;color:#9A3B2A}
  .cmo-state--info{background:var(--warm,#FBF8F2);border:1px solid var(--border,#E3D9C8);color:var(--muted,#8A7C68)}
  @media(max-width:640px){.cmo-grid,.cmo-grid--2{grid-template-columns:1fr}.cmo-sep{display:none}}
  </style>
  <div class="vcard cmo" style="margin-bottom:16px">
    <div class="vtoolbar">
      <strong style="font-size:14px">Calculadora de mão de obra</strong>
      <span class="vhint cmo__sub">Sugestão de planejamento (não grava no apontamento)</span>
      <div style="flex:1"></div>
      <button type="button" class="vbtn vbtn-ghost vbtn-sm" id="calc-mo-toggle">ocultar</button>
    </div>
    <div id="calc-mo-body" class="cmo__body">

      <!-- SISTEMA ÚNICO (17/07): sem seletor de modo. O bloco Planejamento cobre
           tanto "trabalho total" quanto "meta" (mesmo cálculo; a Base define a saída). -->

      <!-- BLOCO 1 (Contexto: chips Cultura/Válvula) removido a pedido do gestor 17/07 -->

      <!-- BLOCOS 2 e 2b (campos de Planejamento) foram MOVIDOS para o form do
           apontamento, logo após "Hectares" — ver vero_calc_mo_campos_planejamento_html().
           O JS liga tudo por id, então funciona com os campos fora deste card. -->

      <!-- BLOCO 3: card de resultado proeminente -->
      <section class="cmo-block cmo-block--last">
        <p class="cmo-block__t">Resultado (estimativa)</p>
        <div class="cmo-result" id="calc-mo-res" style="display:none">
          <div><div class="cmo-big"><span id="calc-mo-r-big">0,00</span><small id="calc-mo-r-big-u">diárias</small></div></div>
          <div class="cmo-sep" id="calc-mo-r-sep1"></div>
          <div class="cmo-kpi" id="calc-mo-r-dim"><div class="cmo-k" id="calc-mo-r-dim-k">Pessoas</div><div class="cmo-v" id="calc-mo-r-dim-v">—</div></div>
          <div class="cmo-sep" id="calc-mo-r-sep2"></div>
          <div class="cmo-kpi" id="calc-mo-r-kprop"><div class="cmo-k">Custo própria</div><div class="cmo-v cash" id="calc-mo-r-cprop">—</div></div>
          <div class="cmo-kpi" id="calc-mo-r-kterc"><div class="cmo-k">Custo terceir.</div><div class="cmo-v cash" id="calc-mo-r-cterc">—</div></div>
          <div class="cmo-sep" id="calc-mo-r-sep3" style="display:none"></div>
          <div class="cmo-kpi" id="calc-mo-r-kpremia" style="display:none"><div class="cmo-k">Premiação (est.)</div><div class="cmo-v cash" id="calc-mo-r-cpremia">—</div></div>
        </div>
        <div class="cmo-state cmo-state--info" id="calc-mo-state">Escolha o talhão e o tipo de atividade no apontamento para estimar a mão de obra.</div>
        <p class="cmo-foot" id="calc-mo-foot"></p>
      </section>

    </div>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const PARAMS   = <?= $jParams ?>;
    const UNID     = <?= $jUnid ?>;
    const TAL      = <?= $jTal ?>;
    const OBSERVADO = <?= $jObs ?>;
    const PREMIA   = <?= $jPremia ?>; /* tipo_id => {meta, valor, unidade} — premiação */
    const CUSTOCAD = <?= $jCusto ?>; /* custo diária: folha → pessoas/equipe → parâmetro */
    const $ = id => document.getElementById(id);
    const body = $('calc-mo-body');
    if (!body) return;

    /* controles próprios do painel (só id, sem name) */
    const trab = $('calc-mo-trab'), modo = $('calc-mo-modo');
    const res = $('calc-mo-res'), state = $('calc-mo-state'), foot = $('calc-mo-foot');
    const unidSel = $('calc-mo-unid'); /* unidade da meta — selecionável pelo usuário */
    /* ERP-CALC 22/07: colheita por quilo — produção prevista (kg) + peso da caixa */
    const prodKg = $('calc-mo-prodkg'), pesoCx = $('calc-mo-pesocx');
    const wrapProd = $('calc-mo-wrap-prodkg'), wrapPeso = $('calc-mo-wrap-pesocx');
    const pesoLbl = $('calc-mo-pesocx-lbl'); /* WP-CALC: rótulo muda caixa↔contentor */
    const PESO_CONTENTOR_PADRAO = 20; /* WP-CALC Z-05: default do contentor quando a cultura não define */
    const metaInput = $('calc-mo-meta'), premioInput = $('calc-mo-premio'); /* V-01/V-02: meta+premiação inline */
    const mediaInput = $('calc-mo-media'); /* V-03: média de produtividade (por pessoa) — opcional */
    const bigV = $('calc-mo-r-big'), bigU = $('calc-mo-r-big-u');
    const dimK = $('calc-mo-r-dim-k'), dimV = $('calc-mo-r-dim-v');
    const cprop = $('calc-mo-r-cprop'), cterc = $('calc-mo-r-cterc');
    const premSep = $('calc-mo-r-sep3'), premKpi = $('calc-mo-r-kpremia'), premV = $('calc-mo-r-cpremia');

    /* campos do apontamento que o painel LÊ (nunca escreve) */
    const fTalhao = document.getElementById('f-talhao');
    const fSafra  = document.getElementById('f-safra');
    const fTipo   = document.getElementById('f-tipo');
    const fData   = document.querySelector('[name=data_apontamento]');

    const decv = v => { v = String(v || '').trim().replace(/\./g, '').replace(',', '.'); const n = parseFloat(v); return isNaN(n) ? 0 : n; };
    const fmt  = n => n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const int0 = n => n.toLocaleString('pt-BR', { maximumFractionDigits: 0 });
    const numBR = n => n.toLocaleString('pt-BR', { maximumFractionDigits: 3 }); /* p/ semear campos sem zeros à toa */

    /* unidade agora vem do SELECT (o usuário escolhe); o tipo só sugere o padrão */
    function unidade() { return (unidSel && unidSel.value) || ''; }
    function unidadePadraoDoTipo() { return UNID[parseInt((fTipo && fTipo.value) || '0', 10)] || ''; }
    /* ao trocar o tipo, pré-seleciona a unidade padrão dele (se existir na lista) */
    function aplicarUnidadePadrao() {
      const u = unidadePadraoDoTipo();
      if (u && [...unidSel.options].some(o => o.value === u)) unidSel.value = u;
    }

    /* V-01/V-02: meta e premiação são EDITÁVEIS no apontamento (o cliente muda por
       variedade/tamanho/estado da fruta). O template vigente só semeia o default. */
    function metaVal()   { return decv(metaInput.value); }    // meta por pessoa/dia (0 = usa rendimento)
    function mediaVal()  { return decv(mediaInput.value); }   // V-03: média por pessoa/dia (0 = usa meta)
    function premioVal() { return decv(premioInput.value); }  // R$ por unidade acima da meta
    function seedPremia() {
      const pr = PREMIA[parseInt((fTipo && fTipo.value) || '0', 10)];
      metaInput.value   = (pr && pr.meta  > 0) ? numBR(pr.meta)  : '';
      premioInput.value = (pr && pr.valor > 0) ? numBR(pr.valor) : '';
    }

    /* cultura derivada do vínculo da safra (f-safra): o rótulo do vínculo é
       "safra · cultura" (mesma origem VINCULOS que o apontamento usa) */
    function culturaNome() {
      if (!fSafra || !fSafra.value || !fSafra.selectedOptions[0]) return '';
      const txt = (fSafra.selectedOptions[0].textContent || '');
      const parts = txt.split('·');
      return (parts.length > 1 ? parts[parts.length - 1] : txt).trim();
    }

    /* contexto: nada a sincronizar (unidade agora é o próprio <select>) */
    function syncContext() {}

    /* trabalho total pré-preenchido pela válvula (talhão) conforme a unidade */
    const autoNote = $('calc-mo-autonote');
    function setNote(msg) { if (autoNote) autoNote.textContent = msg || ''; }

    /* ERP-CALC 22/07: unidade caixa/kg = colheita → mostra produção prevista (kg)
       e, se caixa, o peso da caixa. Demais unidades escondem os dois campos. */
    function toggleColheitaFields() {
      const u = unidade(), col = (u === 'caixa' || u === 'kg' || u === 'contentor');
      if (wrapProd) wrapProd.style.display = col ? '' : 'none';
      if (wrapPeso) wrapPeso.style.display = (u === 'caixa' || u === 'contentor') ? '' : 'none';
      /* WP-CALC Z-05: o mesmo campo de peso serve caixa (embalamento) e contentor (a granel) */
      if (pesoLbl && pesoLbl.firstChild) pesoLbl.firstChild.nodeValue = (u === 'contentor') ? 'Peso do contentor ' : 'Peso da caixa ';
    }

    /* colheita: converte a produção prevista (kg, DIGITADA) no "Total a fazer"
       (caixas = kg ÷ peso da caixa, arredonda p/ cima; unidade kg = direto).
       Nunca usa nº de plantas — colheita se colhe por quilo (áudio 22/07). */
    function syncColheita() {
      const u = unidade();
      if (u !== 'caixa' && u !== 'kg' && u !== 'contentor') return;
      const kg = decv(prodKg && prodKg.value);
      if (kg <= 0) { setNote('Colheita: informe a produção prevista da área (kg) — o cálculo é por quilo, não por planta.'); return; }
      if (u === 'kg') {
        trab.value = int0(kg);
        setNote(int0(kg) + ' kg previstos — total a fazer em kg (previsão, ajustável).');
        return;
      }
      /* caixa e contentor: total = kg ÷ peso (arredonda p/ cima) — só muda o rótulo/fonte do peso */
      const contentor = (u === 'contentor');
      const peso = decv(pesoCx && pesoCx.value);
      const un = contentor ? 'contentor' : 'caixa';
      if (peso <= 0) { setNote('Informe o peso do ' + un + ' (kg) para converter os ' + int0(kg) + ' kg previstos em ' + un + 's.'); return; }
      const unidades = Math.ceil(kg / peso);
      trab.value = int0(unidades);
      setNote('≈ ' + int0(unidades) + ' ' + un + 's ← ' + int0(kg) + ' kg previstos ÷ ' + fmt(peso) + ' kg/' + un + ' (previsão — ajustável).');
    }

    /* V-06/D-2: o mapa é re-keyed por [talhão][safra]. Resolve a entrada da safra
       selecionada (f-safra = safra_talhao_id); sem safra escolhida, ou vínculo
       inexistente, cai na chave '0' (fallback puro da variedade/cultura). */
    function talInfo() {
      const tid = parseInt((fTalhao && fTalhao.value) || '0', 10);
      const sid = parseInt((fSafra && fSafra.value) || '0', 10);
      const byT = TAL[tid];
      if (!byT) return null;
      return byT[sid] || byT[0] || null;
    }

    function autoFill() {
      const info = talInfo();
      const u = unidade();
      toggleColheitaFields();
      if (u === 'planta') {
        if (info) trab.value = (info.plantas && info.plantas > 0) ? int0(info.plantas) : '';
        setNote('');
      } else if (u === 'fila') {
        /* AMARRIO/DESPONTE etc. (QA 03/08): total pelas FILAS (fileiras) da válvula.
           Enquanto num_filas não é cadastrado, cai no nº de plantas (fallback). */
        if (info && info.filas > 0) {
          trab.value = int0(info.filas);
          setNote(int0(info.filas) + ' filas cadastradas na válvula (ajustável).');
        } else if (info && info.plantas > 0) {
          trab.value = int0(info.plantas);
          setNote('Sem nº de filas na válvula — usando ' + int0(info.plantas)
            + ' plantas (cadastre "Nº de filas" na válvula p/ o total correto).');
        } else {
          setNote('');
        }
      } else if (u === 'ha') {
        if (info) trab.value = (info.area && info.area > 0) ? String(info.area).replace('.', ',') : '';
        setNote('');
      } else if (u === 'cacho') {
        /* RALEIO (WP-CALC Z-06): cachos = nº de plantas × cachos_por_planta da variedade */
        if (info && info.plantas > 0 && info.cachos_planta > 0) {
          const total = info.plantas * info.cachos_planta;
          trab.value = int0(total);
          /* QA 03/08: nem todo cacho vai a raleio — o total é uma PREVISÃO máxima;
             o usuário ajusta para os cachos que realmente serão ralados. */
          setNote(int0(info.plantas) + ' plantas × ' + numBR(info.cachos_planta) + ' cachos = ' + int0(total)
            + ' cachos (previsão máxima — ajuste p/ os cachos que serão ralados).');
        } else {
          setNote((info && info.plantas > 0)
            ? 'Cadastre "cachos por planta" na variedade desta válvula para estimar o total de cachos.'
            : ''); /* sem nº de plantas → manual, não mexe no que o usuário digitou */
        }
      } else if (u === 'caixa' || u === 'kg' || u === 'contentor') {
        /* COLHEITA: o talhão só SUGERE a produção prevista (produtividade esperada
           kg/ha × área) e o peso da caixa/contentor (cultura); o usuário confirma/edita.
           Sem sugestão → campos abertos p/ digitar (degradação honesta). */
        if (info && u === 'caixa' && pesoCx && decv(pesoCx.value) <= 0 && info.peso_caixa > 0) {
          pesoCx.value = fmt(info.peso_caixa);
        }
        if (u === 'contentor' && pesoCx && decv(pesoCx.value) <= 0) {
          pesoCx.value = fmt((info && info.peso_contentor > 0) ? info.peso_contentor : PESO_CONTENTOR_PADRAO);
        }
        /* V-05: PREFERE a previsão de colheita (Σ kg_total_previsto do vínculo de
           safra — Premium/CAT). Só cai na produtividade esperada × área (variedade)
           quando não há previsão lançada. Continua editável — é sugestão. */
        if (info && prodKg && info.previsto_kg > 0) {
          prodKg.value = int0(Math.round(info.previsto_kg));
        } else if (info && prodKg && info.prod_kg_ha > 0 && info.area > 0) {
          prodKg.value = int0(Math.round(info.prod_kg_ha * info.area));
        }
        syncColheita();
      } else {
        setNote(''); /* hora/outros → manual: não mexe no que o usuário digitou */
      }
    }

    function setState(kind, msg) {
      res.style.display = 'none';
      res.title = '';
      foot.textContent = '';
      state.style.display = '';
      state.className = 'cmo-state cmo-state--' + kind;
      state.textContent = msg;
    }

    function togglePrazo() {
      const p = modo.value === 'prazo';
      $('calc-mo-wrap-prazo').style.display = p ? '' : 'none';
      $('calc-mo-wrap-pessoas').style.display = p ? 'none' : '';
    }

    /* CADEIA DE PRIORIDADE do rendimento por diária de um tipo:
       (1) OBSERVADO[tid].rendimento (Σ qtd ÷ Σ diárias reais) → fonte 'observado';
       (2) PARAMS[tid].rendimento_por_diaria (referência/RT)   → fonte 'referencia';
       (3) nada confiável → null (mantém o aviso "aguardando o RT", P-91).
       Devolve {rendimento, fonte, nDiarias} ou null. */
    function resolveRendimento(tid) {
      const o = OBSERVADO[tid];
      if (o && o.rendimento > 0) return { rendimento: o.rendimento, fonte: 'observado', nDiarias: o.n_diarias | 0 };
      const p = PARAMS[tid];
      if (p && p.rendimento_por_diaria > 0) return { rendimento: p.rendimento_por_diaria, fonte: 'referencia', nDiarias: 0 };
      return null;
    }

    /* rótulo curto da origem do rendimento (para foot no modo total e title em ambos) */
    function origemTxt(rend) {
      return rend.fonte === 'observado'
        ? 'rendimento observado: ' + fmt(rend.rendimento) + '/dia · base ' + int0(rend.nDiarias) + ' diárias'
        : 'rendimento de referência (ajustável pelo RT)';
    }

    /* preâmbulo comum: valida contexto do apontamento e devolve {p, rend} (ou null).
       p = parâmetros do tipo (custos/fator; pode faltar) · rend = rendimento resolvido pela cadeia */
    function ensureParam() {
      syncContext();
      const tid = parseInt((fTipo && fTipo.value) || '0', 10);
      const temTalhao = !!(fTalhao && fTalhao.value);
      /* degradar honesto: nada escolhido ainda no apontamento → dica neutra */
      if (!tid && !temTalhao) { setState('info', 'Escolha o talhão e o tipo de atividade no apontamento para estimar a mão de obra.'); return null; }
      if (!tid) { setState('info', 'Escolha o tipo de atividade no apontamento para estimar a mão de obra.'); return null; }
      const rend = resolveRendimento(tid);
      if (!rend) {
        /* A-06: a mensagem agora APONTA o caminho do cadastro
           (link relativo — o painel só é incluído por telas de /agro/) */
        setState('warn', 'Parâmetros ainda não cadastrados para esta atividade (rendimento por diária). Cadastre em Gestão Agrícola → Parâmetros de Rendimento (MO) — a calculadora liga sozinha. ');
        const aPar = document.createElement('a');
        aPar.href = 'parametros_rendimento.php';
        aPar.textContent = 'Abrir cadastro de parâmetros';
        aPar.style.fontWeight = '600';
        state.appendChild(aPar);
        return null;
      }
      return { p: PARAMS[tid] || {}, rend: rend, tid: tid };
    }

    function fatorDe(p) { return p.fator_ajuste > 0 ? p.fator_ajuste : 1; }

    /* mostra/esconde os KPIs secundários do card (dimensão + custos + separadores).
       Modo Meta = minimalista: só o número de pessoas em destaque. */
    function showExtras(show) {
      const d = show ? '' : 'none';
      ['calc-mo-r-sep1', 'calc-mo-r-dim', 'calc-mo-r-sep2', 'calc-mo-r-kprop', 'calc-mo-r-kterc']
        .forEach(i => { const e = $(i); if (e) e.style.display = d; });
    }

    /* custo da diária pelos 3 CAMINHOS, na ordem: (1) folha (média) → (2) pessoas e
       equipe (média) → (3) parâmetro da atividade. Própria vem MENSAL (÷ dias úteis);
       terceirizada já é diária. Devolve {prop, propOrig, terc, tercOrig}. */
    function resolveCusto(p) {
      let prop = 0, propOrig = '';
      if (CUSTOCAD.folhaPropDiaria > 0) {
        prop = CUSTOCAD.folhaPropDiaria;
        propOrig = 'folha ' + (CUSTOCAD.folhaComp || '');
      } else if (CUSTOCAD.pessoasPropDiaria > 0) {
        prop = CUSTOCAD.pessoasPropDiaria;
        propOrig = 'média de ' + int0(CUSTOCAD.nPessoas) + ' colaborador(es)';
      } else if (p.custo_diaria_propria > 0) {
        prop = p.custo_diaria_propria;
        propOrig = 'parâmetro da atividade';
      }
      let terc = 0, tercOrig = '';
      if (CUSTOCAD.tercDiaria > 0) {
        terc = CUSTOCAD.tercDiaria;
        tercOrig = 'média das diárias de terceirizados';
      } else if (p.custo_diaria_terceirizada > 0) {
        terc = p.custo_diaria_terceirizada;
        tercOrig = 'parâmetro da atividade';
      }
      return { prop: prop, propOrig: propOrig, terc: terc, tercOrig: tercOrig };
    }

    /* preenche os KPIs de custo (comum aos dois modos) + rótulo de origem no rodapé */
    function fillCusto(ctx, diarias) {
      const p = ctx.p;
      const cc = resolveCusto(p);
      cprop.textContent = cc.prop > 0 ? 'R$ ' + fmt(diarias * cc.prop) : '—';
      cterc.textContent = cc.terc > 0 ? 'R$ ' + fmt(diarias * cc.terc) : '—';
      cprop.title = cc.propOrig ? 'Custo próprio: ' + cc.propOrig + ' (R$ ' + fmt(cc.prop) + '/diária)' : '';
      cterc.title = cc.tercOrig ? 'Custo terceirizado: ' + cc.tercOrig + ' (R$ ' + fmt(cc.terc) + '/diária)' : '';
      /* V-03/D-1: torna explícito qual número dimensionou a equipe (média × meta). */
      const meta = metaVal(), media = mediaVal(), un = unidade() || 'un';
      let base;
      if (media > 0) {
        base = 'equipe dimensionada pela média de ' + fmt(media) + ' ' + un + '/pessoa/dia'
             + (meta > 0 ? ' · meta ' + fmt(meta) + ' vai na OS do encarregado' : '');
      } else if (meta > 0) {
        base = 'equipe dimensionada pela meta de ' + fmt(meta) + ' ' + un + '/pessoa/dia';
      } else {
        base = origemTxt(ctx.rend);
      }
      const origs = [];
      if (cc.propOrig) origs.push('própria ' + cc.propOrig);
      if (cc.tercOrig) origs.push('terceir. ' + cc.tercOrig);
      foot.textContent = base + (fatorDe(p) !== 1 ? ' · fator ' + fmt(fatorDe(p)) : '')
        + (origs.length ? ' · custo: ' + origs.join(' | ') : '') + '.';
    }

    /* premiação estimada: prêmio pelo EXCEDENTE quando a turma rende ACIMA da meta.
       P-06: a BASE do excedente é a MÉDIA de produtividade informada
       pelo gestor (não o rendimento de referência) — quando informada; sem média, cai
       no rendimento resolvido. O multiplicador é o Nº DE PESSOAS que a calculadora
       dimensionou (não as diárias fracionárias). Fórmula:
         excedente = máx(0, base − meta)      (base = média informada, senão rendimento)
         premiação = excedente × nº de pessoas × tarifa (R$/un.)
       Cenário Chiquinho: meta 70, média 90, R$ 2/planta, 33 pessoas → 20 × 33 × 2 = R$ 1.320.
       Só aparece p/ atividade premiável com tarifa, meta e base > meta. */
    function fillPremia(ctx, nPessoas) {
      const meta = metaVal(), tarifa = premioVal(), media = mediaVal();
      const base = media > 0 ? media : ctx.rend.rendimento;   /* P-06: MÉDIA informada tem prioridade */
      const excedente = Math.max(0, base - meta);
      const nP = (nPessoas && nPessoas > 0) ? Math.round(nPessoas) : 0;
      const premio = (meta > 0 && tarifa > 0 && excedente > 0 && nP > 0) ? excedente * nP * tarifa : 0;
      if (premio <= 0) { premSep.style.display = 'none'; premKpi.style.display = 'none'; return; }
      premSep.style.display = ''; premKpi.style.display = '';
      premV.textContent = 'R$ ' + fmt(premio);
      const un = unidade() || 'un';
      const baseTxt = media > 0 ? 'média ' + fmt(base) : 'rendimento ' + fmt(base);
      foot.textContent += ' Premiação estimada: excedente ' + fmt(excedente) + ' ' + un + '/pessoa ('
        + baseTxt + ' − meta ' + fmt(meta) + ') × ' + int0(nP) + ' pessoa(s) × R$ ' + fmt(tarifa)
        + ' = R$ ' + fmt(premio) + '.';
    }

    function computeTotal(ctx) {
      const p = ctx.p, rend = ctx.rend;
      const trabv = decv(trab.value);
      if (trabv <= 0) { setState('warn', 'Informe o total a fazer (' + (unidade() || 'unidade') + ').'); return; }
      /* V-03/D-1: dimensiona a equipe pela MÉDIA (se informada); senão pela META;
         senão pelo rendimento. A meta segue como o nº que vai na OS do encarregado. */
      const meta = metaVal(), media = mediaVal();
      const baseDim = media > 0 ? media : (meta > 0 ? meta : rend.rendimento);
      const diarias = trabv / baseDim * fatorDe(p);
      const diariasArr = Math.ceil(diarias);   // diárias arredondadas p/ CIMA (exibição e custo)
      state.style.display = 'none';
      res.style.display = '';
      res.title = origemTxt(rend);
      showExtras(true);
      bigV.textContent = int0(diariasArr); bigU.textContent = 'diárias';
      /* P-06: nº de pessoas dimensionado (base da premiação): prazo → ⌈diárias/prazo⌉;
         pessoas → o nº informado pelo gestor. */
      let nPessoasDim = 0;
      if (modo.value === 'prazo') {
        /* V-04: base = dias → o resultado é o Nº DE PESSOAS.
           Destaque = pessoas arredondado p/ CIMA (inteiro), SEM o rótulo "diária";
           as diárias (fracionárias) passam a ser o KPI de apoio. */
        const pr = decv($('calc-mo-prazo').value);
        const pessoas = pr > 0 ? Math.ceil(diarias / pr) : null;
        nPessoasDim = pessoas || 0;
        bigV.textContent = pessoas !== null ? int0(pessoas) : '—';
        bigU.textContent = pessoas !== null ? (pessoas === 1 ? 'pessoa' : 'pessoas') : 'informe os dias';
        dimK.textContent = 'Diárias';
        dimV.innerHTML = int0(diariasArr) +
          ' <small class="cmo-dim-sub">' + (pr > 0 ? 'em ' + int0(pr) + ' dia(s)' : 'informe os dias') + '</small>';
      } else {
        const pe = decv($('calc-mo-pessoas').value);
        nPessoasDim = pe > 0 ? pe : 0;
        dimK.textContent = 'Dias';
        dimV.innerHTML = (pe > 0 ? Math.ceil(diarias / pe) : '—') +
          ' <small class="cmo-dim-sub">' + (pe > 0 ? 'com ' + int0(pe) + ' pessoa(s)' : 'informe as pessoas') + '</small>';
      }
      fillCusto(ctx, diariasArr);
      fillPremia(ctx, nPessoasDim);
    }

    function compute() {
      const ctx = ensureParam();
      if (!ctx) return;
      computeTotal(ctx);
    }

    /* ── eventos ─────────────────────────────────────────────── */
    $('calc-mo-toggle').addEventListener('click', () => {
      const show = body.style.display === 'none';
      body.style.display = show ? '' : 'none';
      $('calc-mo-toggle').textContent = show ? 'ocultar' : 'mostrar';
      if (show) { syncContext(); compute(); }
    });
    modo.addEventListener('change', () => { togglePrazo(); compute(); });
    ['calc-mo-trab', 'calc-mo-prazo', 'calc-mo-pessoas', 'calc-mo-meta', 'calc-mo-media', 'calc-mo-premio']
      .forEach(i => $(i).addEventListener('input', compute));
    /* colheita: digitou kg previstos / peso da caixa → reconverte o total e recalcula */
    if (prodKg) prodKg.addEventListener('input', () => { syncColheita(); compute(); });
    if (pesoCx) pesoCx.addEventListener('input', () => { syncColheita(); compute(); });
    /* usuário trocou a unidade → re-auto-preenche a meta (planta/ha) e recalcula */
    unidSel.addEventListener('change', () => { autoFill(); compute(); });

    /* reatividade: mudou algo no apontamento → herda contexto, auto-preenche e recalcula */
    function onApont() { autoFill(); compute(); }
    if (fTalhao) fTalhao.addEventListener('change', onApont);
    /* trocar o TIPO sugere a unidade padrão + semeia meta/premiação do template, e recalcula */
    if (fTipo)   fTipo.addEventListener('change', () => { aplicarUnidadePadrao(); seedPremia(); onApont(); });
    if (fSafra)  fSafra.addEventListener('change', onApont);
    if (fData) { fData.addEventListener('change', compute); fData.addEventListener('input', compute); }

    /* estado inicial (reflete o que já estiver selecionado, ex.: edição) */
    togglePrazo();
    aplicarUnidadePadrao();
    seedPremia();
    autoFill();
    compute();
  });
  </script>
<?php
    return ob_get_clean();
}
