<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Agenda (protótipo demo)
   Rota: /crm/consultor/agenda
   Fonte: docs/VERO_CRM_Consultor_Frutas_Mockup.html (VIEWS.agenda)
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* Dados locais fiéis ao mockup — TODO mover para _mock.php */

$DIAS  = [['Seg', '24'], ['Ter', '25'], ['Qua', '26'], ['Qui', '27'], ['Sex', '28'], ['Sáb', '29']];
$HORAS = ['07', '08', '09', '10', '11', '13', '14', '15', '16', '17'];
$HOJE  = 1;                                            /* Ter 25 */

/* Eventos por "hora-dia" (dia = índice em $DIAS). tipo: visit|follow|task|meet */
$EV = [
    '07-1' => [['visit',  'Riacho Grande',           'Poda pós-colheita']],
    '07-2' => [['visit',  'Boa Vista',               'Míldio U-02 + pré-colheita']],
    '08-0' => [['visit',  'Vale Verde',              'Resposta ao PBZ']],
    '09-2' => [['visit',  'Nova Aliança',            '2ª visita · rachadura']],
    '09-3' => [['visit',  'Serra Branca',            'Reativação · 61 d']],
    '10-4' => [['meet',   'Reunião regional',        'Equipe · Petrolina']],
    '11-0' => [['follow', 'Cotação U-03',            'Vence hoje']],
    '13-2' => [['visit',  'Santa Helena',            'Retomar O-112']],
    '14-3' => [['visit',  'São José',                'Reativação · 47 d']],
    '15-1' => [['task',   'Caderno de campo',        'Fechar registros da semana']],
    '16-2' => [['visit',  'Bom Jesus',               'Pós-venda bioinsumo']],
    '16-4' => [['follow', 'Proposta Riacho Grande',  'Adubação de reposição']],
    '17-4' => [['task',   'Fechamento semanal',      'Relatório automático']],
];

$PRODUTORES = ['João Almeida', 'Carlos Mendes', 'Maria Oliveira', 'Antônio Ribeiro',
               'Fernanda Sá', 'Roberto Nakamura', 'Helena Vasconcelos', 'José Bezerra'];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'agenda',
    'titulo' => 'Agenda',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" onclick="vModalOpen(\'vm-compromisso\')">＋ Compromisso</button>',
]);
?>

<style>
/* Grade semanal (sem equivalente no crm.css) — escopo .crm-app, cores var(--crm-*).
   Refino 25/08 (gestor: "melhorar design"): hoje tingido na coluna inteira,
   contagem por dia no cabeçalho, sábado esmaecido, chips com mais presença. */
.crm-app .crm-wkwrap { overflow-x: auto; }
.crm-app .crm-wk {
  display: grid; grid-template-columns: 56px repeat(6, 1fr); min-width: 900px;
  border: 1px solid var(--crm-line2); border-radius: 12px; overflow: hidden; background: var(--crm-card);
}
.crm-app .crm-wk .hd {
  background: var(--crm-bg2); padding: 10px 8px 9px; text-align: center;
  font: 700 10.5px var(--num,'IBM Plex Mono'); letter-spacing: .05em; text-transform: uppercase;
  color: var(--crm-ink3); border-bottom: 1px solid var(--crm-line2); border-right: 1px solid var(--crm-line);
}
.crm-app .crm-wk .hd b { display: block; font-size: 16px; color: var(--crm-ink); margin-top: 2px; letter-spacing: 0; }
.crm-app .crm-wk .hd .n {
  display: inline-block; margin-top: 4px; min-width: 17px; padding: 0 5px; border-radius: 99px;
  font-size: 9.5px; line-height: 15px; background: rgba(0,80,89,.1); color: var(--crm-teal);
}
.crm-app .crm-wk .hd.today { background: var(--crm-teal); color: rgba(255,255,255,.75); }
.crm-app .crm-wk .hd.today b { color: #fff; }
.crm-app .crm-wk .hd.today .n { background: rgba(255,255,255,.22); color: #fff; }
.crm-app .crm-wk .hr {
  padding: 9px 8px; text-align: right; font: 600 10px var(--num,'IBM Plex Mono'); color: var(--crm-ink3);
  border-right: 1px solid var(--crm-line); border-bottom: 1px solid var(--crm-line); background: var(--crm-bg2);
}
.crm-app .crm-wk .hr.lunch { border-bottom-style: dashed; }
.crm-app .crm-wk .cell {
  border-right: 1px solid var(--crm-line); border-bottom: 1px solid var(--crm-line);
  padding: 4px; min-height: 56px; transition: background .12s;
}
.crm-app .crm-wk .cell.lunch { border-bottom-style: dashed; }
.crm-app .crm-wk .cell:hover { background: var(--crm-bg2); }
.crm-app .crm-wk .cell.today { background: rgba(0,80,89,.045); }
.crm-app .crm-wk .cell.today:hover { background: rgba(0,80,89,.08); }
.crm-app .crm-wk .cell.wknd { background: repeating-linear-gradient(-45deg, transparent 0 7px, rgba(138,124,104,.05) 7px 8px); }
.crm-app .crm-ev {
  border-radius: 8px; padding: 6px 8px; font-size: 10.5px; line-height: 1.35; margin-bottom: 4px; cursor: pointer;
  border-left: 3px solid var(--crm-teal); background: rgba(0,80,89,.1);
  box-shadow: 0 1px 2px rgba(36,27,20,.06); transition: transform .1s, box-shadow .1s;
}
.crm-app .crm-ev:hover { transform: translateY(-1px); box-shadow: 0 3px 9px rgba(36,27,20,.13); }
.crm-app .crm-ev b { display: block; font-weight: 700; font-size: 11.5px; color: var(--crm-ink); }
.crm-app .crm-ev span { color: var(--crm-ink2); }
.crm-app .crm-ev.follow { background: rgba(181,124,26,.14);  border-left-color: var(--crm-amber); }
.crm-app .crm-ev.task   { background: rgba(138,124,104,.13); border-left-color: var(--crm-grey); }
.crm-app .crm-ev.meet   { background: rgba(109,91,166,.14);  border-left-color: var(--crm-violet); }
</style>

<?php /* KPIs da agenda retirados a pedido do gestor 25/08 — a tela é o calendário */ ?>
<div class="crm-card">
  <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:12px">
    <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Semana anterior">‹</button>
    <span style="font-size:13.5px;font-weight:700">24 a 29 de agosto de 2026</span>
    <button type="button" class="vbtn vbtn-sm vbtn-ghost" data-toast="Próxima semana">›</button>
    <div class="crm-tabs" style="margin:0 0 0 9px">
      <span class="crm-tab on">Semana</span>
      <span class="crm-tab" data-toast="Visão dia demonstrativa">Dia</span>
      <span class="crm-tab" data-toast="Visão mês demonstrativa">Mês</span>
    </div>
    <span style="flex:1"></span>
    <span style="display:flex;gap:11px;font-size:11px;color:var(--crm-ink3);align-items:center;flex-wrap:wrap">
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--crm-teal);margin-right:4px"></i>Visita</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--crm-amber);margin-right:4px"></i>Follow-up</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--crm-violet);margin-right:4px"></i>Reunião</span>
      <span><i style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--crm-grey);margin-right:4px"></i>Tarefa</span>
    </span>
  </div>

  <?php /* contagem de eventos por dia (badge do cabeçalho) */
  $porDia = array_fill(0, count($DIAS), 0);
  foreach ($EV as $k => $lista) { $porDia[(int)substr($k, strpos($k, '-') + 1)] += count($lista); }
  $SAB = count($DIAS) - 1; ?>
  <div class="crm-wkwrap">
    <div class="crm-wk">
      <div class="hd"></div>
      <?php foreach ($DIAS as $i => [$d, $n]): ?>
        <div class="hd<?= $i === $HOJE ? ' today' : '' ?>"><?= h($d) ?><b><?= h($n) ?></b>
          <?php if ($porDia[$i] > 0): ?><span class="n"><?= (int)$porDia[$i] ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php foreach ($HORAS as $hRow): $almoco = $hRow === '11'; /* 12h = almoço (linha tracejada) */ ?>
        <div class="hr<?= $almoco ? ' lunch' : '' ?>"><?= h($hRow) ?>:00</div>
        <?php foreach ($DIAS as $i => $d): ?>
          <div class="cell<?= $i === $HOJE ? ' today' : '' ?><?= $i === $SAB ? ' wknd' : '' ?><?= $almoco ? ' lunch' : '' ?>">
            <?php foreach ($EV[$hRow . '-' . $i] ?? [] as [$tipo, $t, $sub]): ?>
              <div class="crm-ev <?= h($tipo) ?>" data-toast="<?= h($t . ' · ' . $sub) ?>">
                <b><?= h($t) ?></b><span><?= h($sub) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* callout "Blocos criados sozinhos" removido a pedido do gestor 25/08 */ ?>
</div>

<!-- Modal: novo compromisso (mock — sem POST) -->
<div class="vmodal" id="vm-compromisso">
  <div class="vbox">
    <header>
      <h2>Novo compromisso</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-compromisso')">×</button>
    </header>
    <div class="vform">
      <div class="vgrid">
        <div class="vfield">
          <label>Tipo</label>
          <select>
            <option>Visita</option>
            <option>Follow-up</option>
            <option>Reunião</option>
            <option>Tarefa</option>
          </select>
        </div>
        <div class="vfield">
          <label>Produtor</label>
          <select>
            <?php foreach ($PRODUTORES as $p): ?>
              <option><?= h($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Data</label>
          <input type="text" value="26/08/2026">
        </div>
        <div class="vfield">
          <label>Hora</label>
          <input type="text" value="08:00">
        </div>
        <div class="vfield full">
          <label>Objetivo</label>
          <input type="text" placeholder="Ex.: avaliar resposta ao programa de cálcio">
        </div>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-compromisso')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="button" data-toast="Compromisso criado">Agendar</button>
      </div>
    </div>
  </div>
</div>

<?php crm_shell_end();
