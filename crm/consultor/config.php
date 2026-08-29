<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Configurações (protótipo)
   Rota: /crm/consultor/config · regras da carteira, alerta
   comercial, travas de conformidade e perfis de acesso.
   Tudo visual (sem POST/banco): campos e toggles só demonstram.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php — travas de conformidade e perfis */
$TRAVAS = [
    ['Bloquear recomendação em conflito com carência',
     'Cruza carência do produto com a colheita prevista do talhão.'],
    ['Filtrar catálogo pelo mercado de destino',
     'UE, EUA, Reino Unido e mercado interno com listas distintas.'],
    ['Exigir ART para recomendação com receituário',
     'Resolução CONFEA 1.149/2025.'],
    ['Exigir próxima ação para encerrar visita',
     'Campo obrigatório no encerramento.'],
];
$PERFIS = [
    ['Consultor',         'Própria',   'Própria',   'Ver',    '—'],
    ['Coordenador',       'Da equipe', 'Da equipe', 'Ver',    'Ver'],
    ['Gerente comercial', 'Todas',     'Todas',     'Editar', 'Editar'],
    ['Administrador',     'Todas',     'Todas',     'Editar', 'Editar'],
];

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'config',
    'titulo' => 'Configurações',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="Configurações salvas (demonstrativo)">Salvar alterações</button>',
]);
?>

<div class="crm-g2">

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Frequência-alvo de contato · regra da carteira</span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
      <div class="vfield"><label>Classe A</label><input type="text" value="15 dias"></div>
      <div class="vfield"><label>Classe B</label><input type="text" value="30 dias"></div>
      <div class="vfield"><label>Classe C</label><input type="text" value="45 dias"></div>
    </div>
    <div class="crm-sub" style="margin-top:10px">Define quando o produtor entra no radar por falta de contato.</div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Alerta de oportunidade parada · regra comercial</span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="vfield">
        <label>Critério</label>
        <select>
          <option>Acima da média histórica da etapa</option>
          <option>Prazo fixo</option>
        </select>
      </div>
      <div class="vfield"><label>Tolerância</label><input type="text" value="+50% da média"></div>
    </div>
    <div class="crm-sub" style="margin-top:10px">Hoje: Negociação tem média de 6 dias · alerta a partir de 9 dias.</div>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Conformidade · travas obrigatórias</span>
      <?= crm_pill(count($TRAVAS) . ' ativas', 'green') ?>
    </div>
    <?php foreach ($TRAVAS as $i => $t): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;<?= $i < count($TRAVAS) - 1 ? 'border-bottom:1px dashed var(--crm-line);' : '' ?>">
        <div style="flex:1;min-width:0">
          <div style="font-size:12.5px;font-weight:600"><?= h($t[0]) ?></div>
          <div class="crm-sub"><?= h($t[1]) ?></div>
        </div>
        <?= crm_pill('Ativa', 'green') ?>
        <input type="checkbox" checked
               style="width:18px;height:18px;accent-color:var(--crm-teal);cursor:pointer"
               data-toast="Trava de conformidade é obrigatória neste perfil (demonstrativo)"
               aria-label="Trava: <?= h($t[0]) ?>">
      </div>
    <?php endforeach; ?>
  </div>

  <div class="crm-card">
    <div class="crm-card__head">
      <span class="crm-card__title">Perfis de acesso · permissões</span>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr><th>Perfil</th><th>Carteira</th><th>Pipeline</th><th>Preços</th><th>Equipe</th></tr>
        </thead>
        <tbody>
          <?php foreach ($PERFIS as $p): ?>
            <tr>
              <td><strong><?= h($p[0]) ?></strong></td>
              <td><?= h($p[1]) ?></td>
              <td><?= h($p[2]) ?></td>
              <td><?= h($p[3]) ?></td>
              <td><?= h($p[4]) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php crm_shell_end();
