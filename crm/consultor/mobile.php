<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / App de Campo (protótipo)
   Rota: /crm/consultor/mobile · preview do PWA offline-first
   em três molduras de celular (Meu Dia, Registro de visita e
   ficha do produtor). Dados fictícios do mockup; molduras
   recriadas em CSS escopado .crm-app com tokens claros VERO.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'mobile',
    'titulo' => 'App de Campo',
    'demo'   => 'preview do app',
]);
?>

<style>
/* Molduras de celular — escopo .crm-app, tokens claros do VERO.
   Exceção autorizada: a barra do APARELHO usa tom escuro discreto
   (teal profundo), como hardware — o conteúdo segue o tema claro. */
.crm-app .crm-phones { display: flex; gap: 26px; flex-wrap: wrap; justify-content: center; }
.crm-app .crm-phone { width: 288px; flex: none; }
.crm-app .crm-phone__fr {
  border: 9px solid var(--crm-teal-d); border-radius: 34px; overflow: hidden;
  background: var(--crm-bg); box-shadow: 0 14px 34px rgba(36,27,20,.18);
  height: 580px; display: flex; flex-direction: column;
}
.crm-app .crm-phone__nb { background: var(--crm-teal-d); height: 20px; display: flex; align-items: center; justify-content: center; }
.crm-app .crm-phone__nb i { width: 64px; height: 5px; background: rgba(255,255,255,.25); border-radius: 20px; }
.crm-app .crm-phone__pt {
  background: var(--crm-teal-d); color: #fff; padding: 11px 13px;
  display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600;
}
.crm-app .crm-phone__pb { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
.crm-app .crm-phone__pb .crm-callout { margin: 0; }
.crm-app .crm-phone__pn { background: var(--crm-teal-d); display: flex; justify-content: space-around; padding: 9px 0 11px; }
.crm-app .crm-phone__pn span { font-size: 9.5px; font-weight: 600; color: rgba(255,255,255,.55); }
.crm-app .crm-phone__pn span.on { color: #fff; }
.crm-app .crm-phone__cap { text-align: center; font-size: 11.5px; color: var(--crm-ink3); margin-top: 11px; font-weight: 600; }
.crm-app .crm-mcard { background: var(--crm-card); border: 1px solid var(--crm-line2); border-radius: 11px; padding: 11px; font-size: 12px; }
.crm-app .crm-mcard .lbl {
  font: 600 9.5px var(--num, 'IBM Plex Mono'); text-transform: uppercase;
  letter-spacing: .8px; color: var(--crm-ink3);
}
.crm-app .crm-bigbtn {
  display: block; width: 100%; border: 0; border-radius: 12px; padding: 13px;
  background: var(--crm-teal); color: #fff; text-align: center;
  font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit;
}
.crm-app .crm-bigbtn--gh { background: var(--crm-card); color: var(--crm-teal); border: 1px solid var(--crm-line2); }
</style>

<?= crm_callout('<strong>PWA offline-first, mesma base do desktop.</strong> O consultor passa o dia na '
    . 'estrada e dentro do pomar, muitas vezes sem sinal. Tudo que importa em campo cabe em 3 toques; '
    . 'o registro grava local e sincroniza quando a conexão volta.', 'teal') ?>

<div class="crm-card" style="margin-top:14px">
  <div class="crm-phones">

    <!-- Fone 1 · Meu Dia -->
    <div class="crm-phone">
      <div class="crm-phone__fr">
        <div class="crm-phone__nb"><i></i></div>
        <div class="crm-phone__pt">Meu Dia · 25/08</div>
        <div class="crm-phone__pb">
          <div class="crm-mcard">
            <div class="lbl">Agora · 07:30</div>
            <div style="font-weight:700;font-size:14px;margin-top:3px">Fazenda Boa Vista</div>
            <div class="crm-sub">João Almeida · 12 km · Petrolina</div>
            <div style="margin-top:8px"><?= crm_pill('U-03 em carência', 'red') ?></div>
          </div>
          <button type="button" class="crm-bigbtn" data-toast="Check-in registrado · 07:34 · GPS ok">Fazer check-in</button>
          <button type="button" class="crm-bigbtn crm-bigbtn--gh" data-toast="Navegação · demonstrativo">Navegar até lá</button>
          <div class="crm-mcard">
            <div class="lbl">Próximas paradas</div>
            <div style="margin-top:6px;line-height:2">
              <div><strong style="font-family:var(--num,'IBM Plex Mono')">09:45</strong> · Nova Aliança</div>
              <div><strong style="font-family:var(--num,'IBM Plex Mono')">13:30</strong> · Santa Helena <?= crm_pill('32 d', 'red') ?></div>
              <div><strong style="font-family:var(--num,'IBM Plex Mono')">16:00</strong> · Bom Jesus</div>
            </div>
          </div>
          <?= crm_callout('<strong>Serra Branca está a 11 km da sua rota.</strong> '
              . '61 dias sem contato. Encaixar às 15:00?', 'amber') ?>
        </div>
        <div class="crm-phone__pn">
          <span class="on">Hoje</span><span>Carteira</span><span>Visita</span><span>Ações</span>
        </div>
      </div>
      <div class="crm-phone__cap">Meu Dia · abre o app já sabendo o que fazer</div>
    </div>

    <!-- Fone 2 · Registro de visita -->
    <div class="crm-phone">
      <div class="crm-phone__fr">
        <div class="crm-phone__nb"><i></i></div>
        <div class="crm-phone__pt">Registrar visita <span style="margin-left:auto;font-size:9.5px;opacity:.75">offline</span></div>
        <div class="crm-phone__pb">
          <?= crm_callout('<strong>Check-in 07:34 · GPS confirmado.</strong> Boa Vista · −9,3891 / −40,5030', 'green') ?>
          <div class="crm-mcard">
            <div class="lbl">Talhão</div>
            <div style="display:flex;gap:5px;margin-top:7px;flex-wrap:wrap">
              <span class="crm-chip on">U-02</span><span class="crm-chip">U-01</span><span class="crm-chip">U-03</span>
            </div>
          </div>
          <div class="crm-mcard">
            <div class="lbl">O que você viu</div>
            <div style="display:flex;gap:5px;margin-top:7px;flex-wrap:wrap">
              <span class="crm-chip on">Míldio</span><span class="crm-chip">Oídio</span><span class="crm-chip">Tripes</span>
              <span class="crm-chip">Rachadura</span><span class="crm-chip">Nutrição</span>
            </div>
            <div style="display:flex;gap:7px;margin-top:10px">
              <button type="button" class="crm-bigbtn crm-bigbtn--gh" style="flex:1;padding:10px" data-toast="Foto anexada (demonstrativo)">Foto</button>
              <button type="button" class="crm-bigbtn crm-bigbtn--gh" style="flex:1;padding:10px" data-toast="Ditado por voz (demonstrativo)">Ditar</button>
            </div>
          </div>
          <?= crm_callout('<strong>U-03 bloqueado.</strong> Carência 14 d × colheita 21/09. '
              . '2 alternativas sugeridas.', 'red') ?>
          <button type="button" class="crm-bigbtn" data-toast="Visita encerrada · sincroniza ao voltar o sinal">Encerrar visita</button>
        </div>
        <div class="crm-phone__pn">
          <span>Hoje</span><span>Carteira</span><span class="on">Visita</span><span>Ações</span>
        </div>
      </div>
      <div class="crm-phone__cap">Registro em 3 toques, com trava de conformidade</div>
    </div>

    <!-- Fone 3 · Ficha do produtor -->
    <div class="crm-phone">
      <div class="crm-phone__fr">
        <div class="crm-phone__nb"><i></i></div>
        <div class="crm-phone__pt">João Almeida</div>
        <div class="crm-phone__pb">
          <div class="crm-mcard">
            <div style="display:flex;align-items:center;gap:4px">
              <?= crm_avatar('João Almeida', 'teal') ?>
              <div>
                <div style="font-weight:700;font-size:13px">João Almeida</div>
                <div class="crm-sub">Grupo Almeida · Classe A</div>
              </div>
            </div>
            <div style="display:flex;gap:5px;margin-top:9px;flex-wrap:wrap">
              <?= crm_pill('Ativo', 'green') ?><?= crm_pill('Uva', 'teal') ?><?= crm_pill('Manga', 'amber') ?>
            </div>
          </div>
          <div class="crm-mcard">
            <div class="lbl">Crédito disponível</div>
            <div style="font:600 19px var(--num,'IBM Plex Mono');color:var(--crm-green);margin-top:3px">R$ 233.500</div>
            <div class="crm-sub">de R$ 420.000 · sem vencidos</div>
          </div>
          <div class="crm-mcard">
            <div class="lbl">Talhões · estágio</div>
            <div style="font-size:11.5px;margin-top:6px;line-height:2">
              <div><?= crm_pill('U-01', 'grey') ?> BRS Vitória · <strong>Amolecimento</strong></div>
              <div><?= crm_pill('U-02', 'grey') ?> Arra 15 · <strong>Floração</strong></div>
              <div><?= crm_pill('U-03', 'grey') ?> Sweet Globe · <strong style="color:var(--crm-red)">Carência</strong></div>
              <div><?= crm_pill('M-01', 'grey') ?> Palmer · <strong>Fluxo veget.</strong></div>
            </div>
          </div>
          <div class="crm-mcard">
            <div class="lbl">Oportunidades</div>
            <div style="font-size:11.5px;margin-top:5px;line-height:1.9">
              <div><strong style="font-family:var(--num,'IBM Plex Mono')">O-115</strong> · R$ 186.000 · Proposta</div>
              <div><strong style="font-family:var(--num,'IBM Plex Mono')">O-124</strong> · R$ 58.000 · Prospecção</div>
            </div>
          </div>
          <button type="button" class="crm-bigbtn crm-bigbtn--gh" data-toast="Relatório enviado ao produtor (demonstrativo)">Enviar relatório</button>
        </div>
        <div class="crm-phone__pn">
          <span>Hoje</span><span class="on">Carteira</span><span>Visita</span><span>Ações</span>
        </div>
      </div>
      <div class="crm-phone__cap">Ficha 360 na mão, dentro do pomar</div>
    </div>

  </div>
</div>

<?php crm_shell_end();
