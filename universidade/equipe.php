<?php
declare(strict_types=1);
/* ============================================================
   VERO — Universidade — Painel do Gestor (/universidade/equipe.php)
   Adoção da equipe (sem ranking/competição — retrato, não corrida)
   + Checklist de implantação (Ordem Mestra), avaliado no banco do
   sistema por uni_gestor_checklist(). Auth pela sessão do ERP.
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();       // exige login (redireciona se ausente)
require_once __DIR__ . '/../includes/uni_gestor.php';  // uni_gestor_pode/resumo/equipe/checklist

$base = BIOS_BASE;
$ctx  = uni_ctx();
$pode = uni_gestor_pode($ctx);

if ($pode) {
    $resumo    = uni_gestor_resumo($ctx);
    $equipe    = uni_gestor_equipe($ctx);
    $checklist = uni_gestor_checklist($ctx);
    $feitos    = 0;
    foreach ($checklist as $it) if (!empty($it['concluido'])) $feitos++;
    $totalChk  = count($checklist);
}

/* Formata "última atividade" (timestamp do banco) em algo curto. */
function eq_ultima(?string $ts): string
{
    if (!$ts) return '—';
    $t = strtotime($ts);
    if ($t === false) return '—';
    $dias = (int)floor((time() - $t) / 86400);
    if ($dias <= 0) return 'hoje';
    if ($dias === 1) return 'ontem';
    if ($dias < 30) return "há {$dias} dias";
    return date('d/m/Y', $t);
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel do gestor · Universidade VERO</title>
<link rel="icon" type="image/svg+xml" href="<?= $base ?>/assets/img/brand/vero-symbol.svg">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/favicon-vero-32.png">
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/fonts/vero-fonts.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/uni-portal.css?v=<?= @filemtime(dirname(__DIR__).'/assets/css/uni-portal.css') ?: '1' ?>">
<style>
/* ---- específico do Painel do Gestor (reusa a paleta up-*) ---- */
.eq-intro{ margin:0 0 22px; }
.eq-intro h1{ margin:0 0 6px; font-size:24px; font-weight:800; color:var(--up-deep); }
.eq-intro p{ margin:0; color:var(--up-mut); max-width:70ch; }

.eq-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin:0 0 30px; }
.eq-kpi{ background:var(--up-card); border:1px solid var(--up-line); border-radius:14px; padding:16px 18px; }
.eq-kpi .n{ font-size:30px; font-weight:800; color:var(--up-accent); line-height:1; }
.eq-kpi .l{ margin-top:6px; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--up-mut); }

.eq-sec{ margin:0 0 34px; }
.eq-sec-h{ display:flex; align-items:baseline; gap:10px; margin:0 0 4px; }
.eq-sec-h h2{ margin:0; font-size:19px; font-weight:700; color:var(--up-deep); }
.eq-sec-sub{ font-size:13px; color:var(--up-mut); margin:0 0 16px; }

/* Tabela de adoção */
.eq-tbl-wrap{ overflow-x:auto; border:1px solid var(--up-line); border-radius:14px; background:var(--up-card); }
.eq-tbl{ width:100%; border-collapse:collapse; font-size:14px; min-width:720px; }
.eq-tbl th, .eq-tbl td{ padding:11px 14px; text-align:left; border-bottom:1px solid var(--up-line); vertical-align:middle; }
.eq-tbl thead th{ font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--up-mut); background:rgba(0,54,61,.03); }
.eq-tbl tbody tr:last-child td{ border-bottom:0; }
.eq-tbl td.num, .eq-tbl th.num{ text-align:right; font-variant-numeric:tabular-nums; }
.eq-nome{ font-weight:600; color:var(--up-ink); }
.eq-perfil{ display:inline-block; font-size:11px; padding:2px 8px; border-radius:999px; background:var(--up-sand); color:var(--up-deep); text-transform:capitalize; }
.eq-prog{ display:flex; align-items:center; gap:8px; min-width:150px; }
.eq-bar{ flex:1; height:8px; border-radius:999px; background:rgba(0,54,61,.10); overflow:hidden; }
.eq-bar span{ display:block; height:100%; background:linear-gradient(90deg,var(--up-accent),var(--up-deep)); border-radius:999px; }
.eq-pct{ font-size:12px; color:var(--up-mut); min-width:34px; text-align:right; font-variant-numeric:tabular-nums; }

/* Checklist */
.eq-chk{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:10px; }
.eq-chk li{ display:flex; gap:14px; align-items:flex-start; background:var(--up-card); border:1px solid var(--up-line); border-radius:14px; padding:14px 16px; }
.eq-chk li.ok{ border-color:rgba(0,80,89,.35); background:rgba(0,80,89,.04); }
.eq-mark{ flex:none; width:26px; height:26px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; border:2px solid var(--up-line); color:var(--up-mut); }
.eq-chk li.ok .eq-mark{ background:var(--up-accent); border-color:var(--up-accent); color:#fff; }
.eq-chk-body{ flex:1; min-width:0; }
.eq-chk-body h3{ margin:0 0 3px; font-size:15px; font-weight:600; color:var(--up-ink); }
.eq-chk-body p{ margin:0; font-size:13px; color:var(--up-mut); }
.eq-chk-foot{ margin-top:8px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.eq-status{ font-size:12px; font-weight:600; }
.eq-status.ok{ color:var(--up-accent); }
.eq-status.pend{ color:#a06a1f; }
.eq-aprender{ font-size:13px; font-weight:600; text-decoration:none; padding:4px 12px; border:1px solid var(--up-accent); border-radius:999px; color:var(--up-accent); }
.eq-aprender:hover{ background:var(--up-accent); color:#fff; }

/* Acesso restrito */
.eq-restrito{ max-width:560px; margin:40px auto; background:var(--up-card); border:1px solid var(--up-line); border-radius:16px; padding:32px; text-align:center; }
.eq-restrito .ic{ font-size:38px; }
.eq-restrito h2{ margin:12px 0 8px; color:var(--up-deep); }
.eq-restrito p{ margin:0 auto; color:var(--up-mut); max-width:44ch; }

.eq-vazio{ color:var(--up-mut); font-size:14px; padding:18px; border:1px dashed var(--up-line); border-radius:14px; }
</style>
</head>
<body class="uni-portal">
<?= uni_portal_header($ctx, 'equipe') ?>

<main class="up-wrap">
<?php if (!$pode): ?>
  <div class="eq-restrito">
    <div class="ic">🔒</div>
    <h2>Acesso restrito</h2>
    <p>Este painel é para donos, gestores e administradores. Fale com o responsável da sua fazenda se você precisa acompanhar a adoção da equipe.</p>
  </div>
<?php else: ?>

  <div class="eq-intro">
    <h1>Painel do gestor</h1>
    <p>Um retrato de como a equipe da <?= uni_h($ctx['tenant_nome']) ?> está adotando o VERO e o que ainda falta na implantação. Não é um ranking — é para você ver onde ajudar.</p>
  </div>

  <div class="eq-kpis">
    <div class="eq-kpi"><div class="n"><?= (int)$resumo['pessoas'] ?></div><div class="l">Pessoas na equipe</div></div>
    <div class="eq-kpi"><div class="n"><?= (int)$resumo['ativos'] ?></div><div class="l">Já começaram</div></div>
    <div class="eq-kpi"><div class="n"><?= (int)$resumo['media_pct'] ?>%</div><div class="l">Adoção média</div></div>
    <div class="eq-kpi"><div class="n"><?= (int)$resumo['total_caps'] ?></div><div class="l">Cápsulas disponíveis</div></div>
    <div class="eq-kpi"><div class="n"><?= (int)$feitos ?>/<?= (int)$totalChk ?></div><div class="l">Implantação concluída</div></div>
  </div>

  <!-- ADOÇÃO DA EQUIPE -->
  <section class="eq-sec">
    <div class="eq-sec-h"><h2>Adoção da equipe</h2></div>
    <p class="eq-sec-sub">Progresso de cada pessoa nas cápsulas — em ordem alfabética, sem comparação entre colegas.</p>
    <?php if (!$equipe): ?>
      <div class="eq-vazio">Nenhuma pessoa ativa encontrada nesta fazenda.</div>
    <?php else: ?>
      <div class="eq-tbl-wrap">
        <table class="eq-tbl">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Perfil</th>
              <th>Cápsulas</th>
              <th style="min-width:170px">Progresso</th>
              <th class="num">Matrículas</th>
              <th class="num">Certificados</th>
              <th>Última atividade</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($equipe as $p): $pct = (int)$p['percentual']; ?>
            <tr>
              <td class="eq-nome"><?= uni_h($p['nome']) ?></td>
              <td><span class="eq-perfil"><?= uni_h(str_replace('_', ' ', (string)$p['perfil'])) ?></span></td>
              <td class="num"><?= (int)$p['concluidas'] ?>/<?= (int)$resumo['total_caps'] ?></td>
              <td>
                <div class="eq-prog">
                  <div class="eq-bar"><span style="width:<?= max(0, min(100, $pct)) ?>%"></span></div>
                  <span class="eq-pct"><?= $pct ?>%</span>
                </div>
              </td>
              <td class="num"><?= (int)$p['matriculas'] ?></td>
              <td class="num"><?= (int)$p['certificados'] ?></td>
              <td><?= uni_h(eq_ultima($p['ultima'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- CHECKLIST DE IMPLANTAÇÃO -->
  <section class="eq-sec">
    <div class="eq-sec-h"><h2>Checklist de implantação</h2></div>
    <p class="eq-sec-sub">A Ordem Mestra da implantação — cada item é verificado automaticamente no sistema (<?= (int)$feitos ?> de <?= (int)$totalChk ?> concluídos).</p>
    <?php if (!$checklist): ?>
      <div class="eq-vazio">Nenhum item de checklist configurado. Rode <code>scripts/uni_checklist_seed.php</code>.</div>
    <?php else: ?>
      <ul class="eq-chk">
        <?php foreach ($checklist as $it): $ok = !empty($it['concluido']); ?>
          <li class="<?= $ok ? 'ok' : '' ?>">
            <div class="eq-mark"><?= $ok ? '✓' : '' ?></div>
            <div class="eq-chk-body">
              <h3><?= uni_h($it['titulo']) ?></h3>
              <?php if (!empty($it['descricao_md'])): ?><p><?= uni_h($it['descricao_md']) ?></p><?php endif; ?>
              <div class="eq-chk-foot">
                <span class="eq-status <?= $ok ? 'ok' : 'pend' ?>"><?= $ok ? 'Concluído' : 'Pendente' ?></span>
                <?php if (!empty($it['capsula_slug'])): ?>
                  <a class="eq-aprender" href="<?= $base ?>/universidade/capsula/<?= uni_h($it['capsula_slug']) ?>">Aprender →</a>
                <?php endif; ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php endif; ?>
</main>

<footer class="up-rodape">Universidade VERO · <?= uni_h($ctx['tenant_nome']) ?> · painel do gestor</footer>
</body>
</html>
