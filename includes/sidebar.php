<?php
/* ============================================================
   VERO Agro — includes/sidebar.php
   Navegação de 2 níveis:
     • MENU MACRO  → coluna larga e legível (ícone + rótulo completo,
       agrupado em seções, seletor de fazenda e usuário).
     • SUBMENU MICRO → coluna reduzida com os micros do macro ativo
       (ícone distinto por item + texto compacto + tooltip).
   Lê a matriz central (includes/menu_agro.php) e os ícones de
   includes/agro_icons.php. Respeita permissão + plano.
   Espera $PAGE_VIEW (chave da view ativa) e $base (BIOS_BASE).
   ============================================================ */
require_once __DIR__ . '/menu_agro.php';
require_once __DIR__ . '/agro_icons.php';

if (!function_exists('h')) {
  function h(?string $s): string
  {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
$base      = $base      ?? (defined('BIOS_BASE') ? BIOS_BASE : '');
$PAGE_VIEW = $PAGE_VIEW ?? 'dashboard';
$__u       = $__u ?? (function_exists('currentUser') ? currentUser() : ['name' => 'Usuário', 'role' => '', 'initials' => 'U', 'tenant' => 'Fazenda']);

$__ctx     = bios_menu_ctx();
$__active  = bios_menu_resolve_view($PAGE_VIEW);
$__macros  = bios_menu_macros();

/* Macro ativo resolvido pelo $PAGE_VIEW. */
$__activeMacroSlug = $__active['macro'];
$__activeMacro = $__activeMacroSlug ? bios_menu_macro($__activeMacroSlug) : null;
/* Fallback 1: se o $PAGE_VIEW não casou com nenhum micro (ex.: tela de detalhe,
   cadastro consolidado/oculto, view dinâmica), usa o MÓDULO da própria página
   ($GUARD['macro']) — assim o menu destaca o dono real em vez de cair no 1º macro
   (que dava "Relatórios" e confundia). O $GUARD é definido pela página antes do
   header, então está em escopo aqui. */
if (!$__activeMacro && isset($GUARD['macro']) && $GUARD['macro'] !== '') {
  $mGuard = bios_menu_macro((string)$GUARD['macro']);
  if ($mGuard && bios_menu_macro_visivel($mGuard, $__ctx)) {
    $__activeMacro = $mGuard; $__activeMacroSlug = (string)$GUARD['macro'];
  }
}
/* Fallback 2 (último recurso): 1º macro visível. */
if (!$__activeMacro) {
  foreach ($__macros as $mtmp) {
    if (bios_menu_macro_visivel($mtmp, $__ctx)) { $__activeMacro = $mtmp; $__activeMacroSlug = $mtmp['slug']; break; }
  }
}
/* Hint de navegação (?_nav=<macro>): os atalhos agregados (Cadastros/Apontamentos)
   ANCORAM a sidebar no seu macro — a tela abre e o menu CONTINUA nele, sem pular
   pro módulo de origem. Só a sidebar lê o param; a tela o ignora. */
$__navHint = preg_replace('/[^a-z_]/', '', (string)($_GET['_nav'] ?? ''));
if ($__navHint !== '') {
  $mNav = bios_menu_macro($__navHint);
  if ($mNav && bios_menu_macro_visivel($mNav, $__ctx)) { $__activeMacro = $mNav; $__activeMacroSlug = $__navHint; }
}
/* URLs limpas (27/07): caminho atual sem .php p/ casar com rotas normalizadas */
$__curPath = preg_replace('#\.php$#', '', strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'));
?>
<aside class="navwrap<?= !empty($GLOBALS['BIOS_MENU_FLAT']) ? ' navwrap--flat' : '' ?>">
  <!-- ===== MENU MACRO (coluna larga, legível) ===== -->
  <nav class="macromenu" aria-label="Módulos">
    <?php /* Sistemas satélite (VERO CRM): a marca leva à home do sistema, não ao ERP */
      $__brandHref = (string)($GLOBALS['BIOS_BRAND_HREF'] ?? ($base . '/dashboard.php')); ?>
    <a href="<?= h($__brandHref) ?>" class="mm-brand" title="VERO">
      <!-- Arte final VERO (P-17/D10) enviada pelo cliente 04/07 -->
      <img src="<?= h($base) ?>/assets/img/brand/vero-stacked-white-notech.svg" alt="VERO" class="mm-logo">
      <?php if (!empty($GLOBALS['BIOS_BRAND_SUB'])): ?>
        <span style="display:block;margin-top:6px;font:600 9.5px var(--num,'IBM Plex Mono');text-transform:uppercase;letter-spacing:1.2px;color:#9DC3BC"><?= h((string)$GLOBALS['BIOS_BRAND_SUB']) ?></span>
      <?php endif; ?>
    </a>

    <?php /* Seletor de fazenda: só no ERP — sistemas satélite (CRM) não o usam */ ?>
    <?php if (empty($GLOBALS['BIOS_MENU_OVERRIDE'])): ?>
    <div class="mm-pick">
      <button type="button" class="mm-farm">
        <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent,#005059)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-5 7 5v13M9 21v-5h6v5"/></svg><?= h($__u['tenant'] ?? 'Fazenda') ?></span>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#9C8E7B" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
      </button>
    </div>
    <?php endif; ?>

    <div class="mm-list">
      <?php foreach (bios_menu_secoes() as $secaoTitulo => $slugs): ?>
        <?php
          // Macros visíveis desta seção, na ordem definida.
          $macrosSecao = [];
          foreach ($slugs as $slug) {
            $mc = bios_menu_macro($slug);
            if ($mc && bios_menu_macro_visivel($mc, $__ctx)) $macrosSecao[] = $mc;
          }
          if (!$macrosSecao) continue; // seção sem macro visível não aparece
        ?>
        <div class="mm-grp"><?= h($secaoTitulo) ?></div>
        <?php foreach ($macrosSecao as $macro): ?>
          <?php
            $visiveis = bios_menu_micros_visiveis($macro, $__ctx);
            /* 1º micro REAL (pula cabeçalhos de seção 'sep' e itens sem rota) —
               senão o link do macro cairia no 404 (ex.: submenu Cadastros agrupado,
               cujo 1º item é o cabeçalho "Estrutura & Safra"). */
            $primeiro = null;
            foreach ($visiveis as $mi) {
              if (!empty($mi['sep']) || empty($mi['rota'])) continue;
              $primeiro = $mi; break;
            }
            $href = $primeiro ? bios_menu_micro_href($base, $macro, $primeiro) : '#';
            $isActive = $macro['slug'] === $__activeMacroSlug;
          ?>
          <a href="<?= h($href) ?>" class="mm-item<?= $isActive ? ' active' : '' ?>">
            <?php /* 'glyph' opcional na matriz: ícone explícito (sistemas satélite/CRM);
                     sem ele, mapa canônico por slug como sempre */ ?>
            <?= !empty($macro['glyph']) ? bios_icon((string)$macro['glyph'], 18) : bios_macro_icon($macro['slug'], 18) ?>
            <span><?= h($macro['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <?php /* Universidade VERO — link estático (NÃO é macro: não gera permissão nem passa
               pela matriz). Movido p/ DENTRO do .mm-list para ALINHAR com os demais itens
               (antes ficava fora, sem o padding lateral do container — bugfix 27/07).
               Nos sistemas satélite (VERO CRM, matriz override) o link não se aplica. */ ?>
      <?php if (empty($GLOBALS['BIOS_MENU_OVERRIDE'])): ?>
      <div style="height:1px;background:rgba(255,255,255,.08);margin:6px 6px 5px" aria-hidden="true"></div>
      <a href="<?= h($base) ?>/universidade/" class="mm-item" title="Universidade VERO — aprender a usar o sistema">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 2 3 3 6 3s6-1 6-3v-5"/><path d="M22 10v6"/></svg>
        <span>Universidade</span>
      </a>
      <?php endif; ?>
    </div>

    <div class="mm-user">
      <div class="ava"><?= h($__u['initials'] ?? 'U') ?></div>
      <div style="flex:1;line-height:1.25;min-width:0">
        <div class="nm" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($__u['name'] ?? 'Usuário') ?></div>
        <div class="rl"><?= h(function_exists('roleLabel') ? roleLabel($__u['role'] ?? '') : 'Usuário') ?></div>
      </div>
      <a href="<?= h($base) ?>/logout.php" title="Sair" style="color:var(--side-muted,#8B7C68)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg></a>
    </div>
  </nav>

  <!-- ===== SUBMENU MICRO (coluna reduzida, colada ao topo) =====
       Menu PLANO (sistemas satélite/CRM): sem trilho — tudo na coluna principal. -->
  <?php if (empty($GLOBALS['BIOS_MENU_FLAT'])): ?>
  <div class="microrail">
    <nav class="mr-nav" aria-label="<?= $__activeMacro ? h($__activeMacro['label']) : '' ?>">
      <?php if ($__activeMacro): ?>
        <?php foreach (bios_menu_micros_visiveis($__activeMacro, $__ctx) as $micro): ?>
          <?php if (!empty($micro['sep'])): ?>
            <div class="mr-grp" role="presentation"><?= h($micro['sep']) ?></div>
            <?php continue; ?>
          <?php endif; ?>
          <?php
            $href   = bios_menu_micro_href($base, $__activeMacro, $micro);
            $view   = bios_menu_micro_view($__activeMacro, $micro);
            /* item ativo: por view (fluxo normal) OU, quando ancorado por _nav
               (atalho/launcher sem view), pela rota que bate com o path atual. */
            $rotaPath = $micro['rota'] ? $base . preg_replace('#\.php$#', '', strtok((string)$micro['rota'], '?')) : '';
            $active = ($view === $PAGE_VIEW) || ($__navHint !== '' && $rotaPath !== '' && $__curPath === $rotaPath);
            $isDev  = empty($micro['rota']);
          ?>
          <a href="<?= h($href) ?>" class="mr-item<?= $active ? ' active' : '' ?><?= $isDev ? ' dev' : '' ?>" title="<?= h($micro['label']) ?><?= $isDev ? ' (em breve)' : '' ?>">
            <?= bios_micro_icon($micro['slug'], 22) ?>
            <span><?= h($micro['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="mr-empty" title="Sem módulos disponíveis">—</div>
      <?php endif; ?>
    </nav>
  </div>
  <?php endif; ?>
</aside>
