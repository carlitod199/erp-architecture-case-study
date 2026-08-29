<?php
declare(strict_types=1);
/* ============================================================
   VERO — A1-48c (painel de GAPS): meta da safra × realizado (colheita)
   → quanto falta (kg / kg/ha) e QUANTA MÃO DE OBRA para fechar o gap.

   Reusa o motor _calc_mo (atividade Colheita, rendimento por diária). SUGERE,
   nunca trava (Regra 1/D5). Read-only — não grava nada.

   Meta (prioridade): gestao_metas 'kg_total' → 'kg_ha'×área → senão a
   estimativa agregada por válvula (produtividade_planejada). Realizado:
   Σ colheita_registros.kg_total_realizado (mesma fonte do dashboard/resumo).

   A unidade da Colheita é da fazenda (taxonomia: "caixa"). O gap é em kg →
   o painel pede o PESO MÉDIO DA CAIXA (kg) — específico da fazenda, NÃO
   semeado (Regra 1). Sem peso ou sem parâmetro, mostra o gap e explica o que
   falta cadastrar, sem inventar número.
   ============================================================ */
require_once __DIR__ . '/_calc_mo.php';

/** Meta de produção em kg da safra + a fonte usada. */
function vero_calc_mo_meta_kg(int $safraId, float $areaHa, float $estimadoValvulasKg): array
{
    $t = vero_tenant();
    $mTotal = vero_val(
        "SELECT valor_meta FROM gestao_metas
          WHERE tenant_id = :t AND safra_id = :s AND indicador = 'kg_total'
          ORDER BY updated_at DESC LIMIT 1", [':t' => $t, ':s' => $safraId]);
    if ($mTotal !== null && (float)$mTotal > 0) return ['kg' => (float)$mTotal, 'fonte' => 'meta_kg'];

    $mHa = vero_val(
        "SELECT valor_meta FROM gestao_metas
          WHERE tenant_id = :t AND safra_id = :s AND indicador = 'kg_ha'
          ORDER BY updated_at DESC LIMIT 1", [':t' => $t, ':s' => $safraId]);
    if ($mHa !== null && (float)$mHa > 0 && $areaHa > 0) return ['kg' => (float)$mHa * $areaHa, 'fonte' => 'meta_kg_ha'];

    if ($estimadoValvulasKg > 0) return ['kg' => $estimadoValvulasKg, 'fonte' => 'valvulas'];
    return ['kg' => 0.0, 'fonte' => 'nenhuma'];
}

/**
 * Painel HTML do gap de produção + dimensionamento da MO (colheita) para fechar.
 * $estimadoValvulasKg = fallback da meta (Σ produtividade × área das válvulas).
 */
function vero_calc_mo_gaps_painel_html(int $safraId, float $areaHa, float $estimadoValvulasKg, float $realizadoKg): string
{
    $meta   = vero_calc_mo_meta_kg($safraId, $areaHa, $estimadoValvulasKg);
    $metaKg = $meta['kg'];
    $gapKg  = max(0.0, $metaKg - $realizadoKg);
    $gapHa  = $areaHa > 0 ? $gapKg / $areaHa : null;
    $pct    = $metaKg > 0 ? $realizadoKg / $metaKg * 100 : null;

    $fonteTxt = [
        'meta_kg'    => 'meta de colheita (kg) definida em Metas',
        'meta_kg_ha' => 'meta de produtividade (kg/ha) × área',
        'valvulas'   => 'estimativa agregada das válvulas (produtividade planejada)',
        'nenhuma'    => null,
    ][$meta['fonte']];

    /* parâmetro da Colheita (rendimento por diária, unidade da fazenda) */
    $tipoCol = (int)(vero_val(
        "SELECT id FROM agro_tipos_atividade
          WHERE tenant_id = :t AND categoria = 'colheita' ORDER BY id LIMIT 1",
        [':t' => vero_tenant()]) ?? 0);
    $unid = $tipoCol ? (string)(vero_val(
        "SELECT unidade_padrao FROM agro_tipos_atividade WHERE id = :i AND tenant_id = :t",
        [':i' => $tipoCol, ':t' => vero_tenant()]) ?? '') : '';
    $pc    = $tipoCol ? vero_calc_mo_parametros($tipoCol) : [];
    $rend  = (float)($pc['rendimento_por_diaria'] ?? 0.0);
    $fator = (float)($pc['fator_ajuste'] ?? 1.0); if ($fator <= 0) $fator = 1.0;
    $temParam = $rend > 0;
    $precisaPeso = $temParam && $unid !== 'kg'; // caixa/cacho → converte kg→unidade

    /* ERP-CALC 22/07: peso da caixa default do tenant (colheita.peso_caixa_kg) —
       pré-preenche o campo (editável); sem parâmetro, o campo fica vazio (Regra 1). */
    $pesoCxTenant = 0.0;
    if ($precisaPeso && $unid === 'caixa') {
        $pesoCxTenant = function_exists('vero_srv_param')
            ? (float)vero_srv_param('colheita.peso_caixa_kg', '0')
            : (float)(vero_val(
                "SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'colheita.peso_caixa_kg'",
                [':t' => vero_tenant()]) ?: 0);
    }

    $numBR = static fn(float $v, int $d = 0): string => number_format($v, $d, ',', '.');

    ob_start(); ?>
    <div class="vcard" id="calc-gaps" style="margin-top:0;border-top:1px solid #EEE8DB;border-radius:0">
      <div style="padding:12px 16px 4px"><strong>Gap de produção e mão de obra</strong></div>

      <?php if ($metaKg <= 0): ?>
        <div style="padding:4px 16px 14px" class="vhint">
          Sem meta de produção para esta safra. Defina a meta em
          <a href="<?= BIOS_BASE ?>/custeio/metas">Metas</a> (Colheita kg ou Produtividade kg/ha)
          ou preencha a produtividade planejada das válvulas.
        </div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0">
          <div style="padding:8px 16px"><div class="vhint">Meta</div>
            <strong class="vnum"><?= $numBR($metaKg) ?> kg</strong>
            <?php if ($fonteTxt): ?><div class="vhint" style="font-size:11px"><?= h($fonteTxt) ?></div><?php endif; ?></div>
          <div style="padding:8px 16px"><div class="vhint">Realizado</div>
            <strong class="vnum"><?= $numBR($realizadoKg) ?> kg</strong>
            <?php if ($pct !== null): ?><div class="vhint" style="<?= $pct >= 100 ? 'color:#1a7f4b' : ($pct < 80 ? 'color:#b3261e' : '') ?>"><?= $numBR($pct, 1) ?>%</div><?php endif; ?></div>
          <div style="padding:8px 16px"><div class="vhint">Falta colher</div>
            <strong class="vnum" style="color:<?= $gapKg > 0 ? '#b3261e' : '#1a7f4b' ?>"><?= $numBR($gapKg) ?> kg</strong>
            <?php if ($gapHa !== null && $gapKg > 0): ?><div class="vhint"><?= $numBR($gapHa) ?> kg/ha</div><?php endif; ?></div>
        </div>

        <?php if ($gapKg <= 0): ?>
          <div style="padding:4px 16px 14px;color:#1a7f4b">✔ Meta atingida — sem gap a dimensionar.</div>
        <?php elseif (!$temParam): ?>
          <div style="padding:4px 16px 14px" class="vhint">
            Rendimento da colheita ainda não cadastrado. Assim que o RT informar
            o rendimento por diária, o sistema dimensiona a equipe aqui. Não inventamos o número.
          </div>
        <?php else: ?>
          <div style="padding:8px 16px 14px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
            <?php if ($precisaPeso): ?>
            <div class="vfield" style="margin:0">
              <label style="font-size:12px">Peso médio da <?= h($unid ?: 'unidade') ?> (kg) *</label>
              <input type="number" step="0.01" min="0" id="gap-peso" style="width:130px" placeholder="ex.: 8"<?=
                $pesoCxTenant > 0 ? ' value="' . h(number_format($pesoCxTenant, 2, '.', '')) . '"' : '' ?>>
            </div>
            <?php endif; ?>
            <div class="vfield" style="margin:0">
              <label style="font-size:12px">Prazo (dias)</label>
              <input type="number" step="1" min="1" id="gap-prazo" style="width:100px" placeholder="ex.: 10">
            </div>
            <div id="gap-saida" class="vhint" style="flex:1;min-width:220px">
              <?= $precisaPeso ? 'Informe o peso médio da ' . h($unid ?: 'unidade') . ' e o prazo para dimensionar a equipe.'
                               : 'Informe o prazo para dimensionar a equipe.' ?>
            </div>
          </div>
          <script>
          (function(){
            var GAP=<?= jsvar($gapKg) ?>, REND=<?= jsvar($rend) ?>, FATOR=<?= jsvar($fator) ?>,
                PRECISA_PESO=<?= $precisaPeso ? 'true' : 'false' ?>, UNID=<?= jsvar($unid ?: 'unidade') ?>;
            var elPeso=document.getElementById('gap-peso'), elPrazo=document.getElementById('gap-prazo'), out=document.getElementById('gap-saida');
            function br(n,d){return (n).toLocaleString('pt-BR',{minimumFractionDigits:d||0,maximumFractionDigits:d||0});}
            function calc(){
              var prazo=parseFloat(elPrazo && elPrazo.value)||0;
              var peso=PRECISA_PESO ? (parseFloat(elPeso && elPeso.value)||0) : 1;
              if(PRECISA_PESO && peso<=0){ out.innerHTML='Informe o peso médio da '+UNID+'.'; return; }
              var trabalho = PRECISA_PESO ? (GAP/peso) : GAP;         // gap em unidade da atividade
              var diarias = trabalho / REND * FATOR;
              var msg = '<strong>'+br(diarias,1)+'</strong> diárias';
              if(PRECISA_PESO) msg += ' (~'+br(trabalho,0)+' '+UNID+')';
              if(prazo>0){ var pessoas=Math.ceil(diarias/prazo); msg += ' → <strong>'+pessoas+' pessoa(s)</strong> em '+br(prazo,0)+' dia(s)'; }
              else { msg += ' — informe o prazo para ver a equipe.'; }
              out.innerHTML = msg + ' <span style="opacity:.7">· estimativa</span>';
            }
            if(elPeso) elPeso.addEventListener('input',calc);
            if(elPrazo) elPrazo.addEventListener('input',calc);
          })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
