<?php
/* ============================================================
   VERO Agro — Viticultura · agro/_lib.php
   Helpers de UI + DADOS DE DEMONSTRAÇÃO (protótipo backbone).

   IMPORTANTE: os arrays abaixo são DADOS DE EXEMPLO, exibidos
   sempre com rótulo "demonstração / aguardando migration_120".
   NÃO são produção. Quando as tabelas do backbone existirem,
   trocar os getters vit_demo_* por consultas PDO filtradas por
   tenant_id. A estrutura de render das páginas não muda.
   ============================================================ */

require_once __DIR__ . '/../includes/functions.php'; // define BIOS_BASE, h()

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/* CSS específico da viticultura — injetado no <head> pelo agro_header.php */
$EXTRA_HEAD = '<link rel="stylesheet" href="' . h(BIOS_BASE) . '/assets/css/agro_viticultura.css">';

/* ---------- componentes (sem <span> para status) ---------- */

/** Badge em <div>. $dot = cor opcional do ponto. */
function vit_badge(string $label, string $cls = 'b-gray', string $dot = ''): string {
    $d = $dot !== '' ? '<i class="dot" style="background:' . h($dot) . '"></i>' : '';
    return '<div class="badge ' . h($cls) . '">' . $d . h($label) . '</div>';
}

/** Marcador "confirmar"/histórico — usa <small>, não <span>. */
function vit_flag(string $t = 'confirmar'): string {
    return '<small class="flag">' . h($t) . '</small>';
}

/** Topbar da página (título + subtítulo + seletores fazenda/safra). */
function vit_topbar(string $title, string $sub, bool $quick = true): string {
    ob_start(); ?>
<header class="vit-top">
  <div>
    <h1><?= h($title) ?></h1>
    <div class="sub"><?= h($sub) ?></div>
  </div>
  <div class="vit-spacer"></div>
  <div class="vit-selectors">
    <div class="vit-sel">
      <label for="vselFaz">Fazenda</label>
      <select id="vselFaz">
        <option value="all">Todas as fazendas</option>
        <option value="bv">Boa Vista</option>
        <option value="sh">Santa Helena</option>
      </select>
    </div>
    <div class="vit-sel">
      <label for="vselSaf">Safra</label>
      <select id="vselSaf">
        <option value="2526">2025-26</option>
        <option value="2425">2024-25</option>
      </select>
    </div>
    <?php if ($quick): ?>
    <button type="button" class="btn primary" onclick="location.href='<?= h(BIOS_BASE) ?>/agro/aplicacoes'">+ Lançamento rápido</button>
    <?php endif; ?>
  </div>
</header>
<?php
    return (string) ob_get_clean();
}

/** Faixa de demonstração / pendência de integração. */
function vit_demo(string $tabela): string {
    return '<div class="demo"><div class="ic">!</div><div><strong>Pré-visualização com dados de exemplo.</strong> '
         . 'Esta tela está no padrão VERO Agro, porém ainda <strong>aguardando integração</strong> '
         . '(migration <span class="mono">migration_120_viticultura_backbone</span> · tabela <span class="mono">' . h($tabela) . '</span>). '
         . 'Nenhum dado abaixo é de produção. A estrutura está pronta para receber consultas PDO filtradas por tenant.</div></div>';
}

/** Estado vazio padronizado para uma tabela. */
function vit_empty(int $cols, string $titulo, string $msg): string {
    return '<tr><td colspan="' . $cols . '" class="empty"><strong>' . h($titulo) . '</strong>' . h($msg) . '</td></tr>';
}

/* ============================================================
   DADOS DE DEMONSTRAÇÃO (não produção)
   ============================================================ */
function vit_demo_variedades(): array {
    return [
        ['nome'=>'Itália','uso'=>'Mesa','api'=>'Não','porta'=>'IAC 766','ciclo'=>120,'faz'=>'Boa Vista'],
        ['nome'=>'Crimson Seedless','uso'=>'Mesa','api'=>'Sim','porta'=>'Paulsen 1103','ciclo'=>130,'faz'=>'Boa Vista'],
        ['nome'=>'Sugraone','uso'=>'Mesa','api'=>'Sim','porta'=>'IAC 572','ciclo'=>115,'faz'=>'Santa Helena'],
        ['nome'=>'Syrah','uso'=>'Vinho','api'=>'Não','porta'=>'Paulsen 1103','ciclo'=>140,'faz'=>'Boa Vista'],
        ['nome'=>'Cabernet Sauvignon','uso'=>'Vinho','api'=>'Não','porta'=>'SO4','ciclo'=>150,'faz'=>'Santa Helena'],
        ['nome'=>'BRS Vitória','uso'=>'Suco/mesa','api'=>'Sim','porta'=>'IAC 766','ciclo'=>125,'faz'=>'Santa Helena'],
    ];
}
function vit_demo_fenologia(): array {
    return [
        [1,'00','Repouso / dormência','E-L'],[2,'05','Gema algodão','E-L'],[3,'09','Brotação','E-L'],
        [4,'15','Folhas separadas','E-L'],[5,'19','Início de floração','E-L'],[6,'23','Pleno florescimento','E-L'],
        [7,'27','Frutificação (chumbinho)','E-L'],[8,'35','Início de maturação','E-L'],[9,'38','Maturação / colheita','E-L'],
    ];
}
function vit_demo_labs(): array {
    return [
        ['nome'=>'AgroLab Vale','cnpj'=>'12.345.678/0001-90','acr'=>'ISO/IEC 17025','cont'=>'(87) 3000-1010'],
        ['nome'=>'SoloFértil Análises','cnpj'=>'98.765.432/0001-21','acr'=>'confirmar','cont'=>'(54) 3200-7788'],
        ['nome'=>'BioVitis Microbiologia','cnpj'=>'45.612.300/0001-55','acr'=>'ISO/IEC 17025','cont'=>'(11) 4002-8922'],
    ];
}
function vit_demo_pontos(): array {
    return [
        ['r'=>'P-01','t'=>'T-01','v'=>'Itália','xy'=>'-15.79, -43.30','faz'=>'Boa Vista'],
        ['r'=>'P-02','t'=>'T-01','v'=>'Itália','xy'=>'-15.79, -43.31','faz'=>'Boa Vista'],
        ['r'=>'P-03','t'=>'T-03','v'=>'Syrah','xy'=>'-15.80, -43.30','faz'=>'Boa Vista'],
        ['r'=>'P-11','t'=>'T-04','v'=>'Sugraone','xy'=>'-15.71, -43.22','faz'=>'Santa Helena'],
        ['r'=>'P-12','t'=>'T-05','v'=>'Cabernet S.','xy'=>'-15.72, -43.23','faz'=>'Santa Helena'],
    ];
}
function vit_demo_aplicacoes(): array {
    return [
        ['id'=>'014','data'=>'12/05/2025','tipo'=>'Pulverização','talhao'=>'T-03','var'=>'Syrah','fase'=>'Floração','custo'=>'4.120','status'=>'pend'],
        ['id'=>'013','data'=>'04/05/2025','tipo'=>'Fertirrigação','talhao'=>'T-01','var'=>'Itália','fase'=>'Frutificação','custo'=>'2.880','status'=>'val'],
        ['id'=>'011','data'=>'21/04/2025','tipo'=>'Indução brotação','talhao'=>'T-03','var'=>'Syrah','fase'=>'Dormência','custo'=>'1.540','status'=>'val'],
        ['id'=>'022','data'=>'15/05/2025','tipo'=>'Pulverização','talhao'=>'T-05','var'=>'Cabernet S.','fase'=>'Floração','custo'=>'5.300','status'=>'pend'],
        ['id'=>'020','data'=>'02/05/2025','tipo'=>'Foliar','talhao'=>'T-04','var'=>'Sugraone','fase'=>'Maturação','custo'=>'1.960','status'=>'rasc'],
    ];
}
function vit_demo_alertas(): array {
    return [
        ['sev'=>'critico','cat'=>'residuo','txt'=>'Carga 12 — ingrediente acima do LMR do mercado (confirmar)','t'=>'T-05','d'=>'18/05','vt'=>1,'st'=>'aberto'],
        ['sev'=>'atencao','cat'=>'mip','txt'=>'Míldio recorrente na fase de floração','t'=>'T-03','d'=>'12/05','vt'=>1,'st'=>'aberto'],
        ['sev'=>'atencao','cat'=>'irrigacao','txt'=>'Válvula sem irrigação prevista nesta semana','t'=>'T-01','d'=>'11/05','vt'=>0,'st'=>'reconhecido'],
        ['sev'=>'atencao','cat'=>'foliar','txt'=>'Possível deficiência de K vs faixa cadastrada','t'=>'T-04','d'=>'08/05','vt'=>1,'st'=>'aberto'],
        ['sev'=>'info','cat'=>'grade','txt'=>'Produto aplicado fora da grade aprovada do mercado','t'=>'T-03','d'=>'05/05','vt'=>0,'st'=>'aberto'],
        ['sev'=>'info','cat'=>'dormex','txt'=>'Indução de brotação fora da janela planejada','t'=>'T-03','d'=>'21/04','vt'=>1,'st'=>'resolvido'],
    ];
}
function vit_demo_extracoes(): array {
    return [
        ['arq'=>'laudo_solo_t01.pdf','origem'=>'analise_solo','modelo'=>'extrator-laudo','conf'=>92,'status'=>'pend'],
        ['arq'=>'laudo_foliar_t03.pdf','origem'=>'analise_foliar','modelo'=>'extrator-laudo','conf'=>74,'status'=>'pend'],
        ['arq'=>'laudo_residuo_carga12.pdf','origem'=>'residuo','modelo'=>'extrator-laudo','conf'=>96,'status'=>'ok'],
    ];
}

/* mapas de status -> badge */
const VIT_APL_STATUS = [
    'val'  => ['b-green','#1E5A60','Validada'],
    'pend' => ['b-amber','#8a5a1c','Aguardando RT'],
    'rasc' => ['b-gray','#5d6655','Rascunho'],
];
const VIT_SEV = [
    'critico' => ['b-red','#A4423A','Crítico'],
    'atencao' => ['b-amber','#B5742B','Atenção'],
    'info'    => ['b-blue','#33576e','Info'],
];
const VIT_ALERTA_ST = ['aberto'=>'b-amber','reconhecido'=>'b-blue','resolvido'=>'b-green','descartado'=>'b-gray'];
