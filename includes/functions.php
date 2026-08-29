<?php
require_once __DIR__ . '/bootstrap.php';
/* ============================================================
   VERO — includes/functions.php
   Funções auxiliares globais
   Todas as funções usam guard if(!function_exists()) para
   não conflitar com definições locais em páginas que as
   redeclaram (ex: estrategico/dashboard.php, acoes.php etc.)
   ============================================================ */

/* ── Base URL do projeto (sem barra final) ───────────────────
   Auto-detecta a base a partir da raiz do projeto vs DOCUMENT_ROOT.
   Local (…/www/bios_a)  -> '/bios_a'
   Produção (raiz do host) -> ''                                */
if (!defined('BIOS_BASE')) {
    $__bios_base = '';
    $__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\')) : '';
    $__projroot = str_replace('\\', '/', dirname(__DIR__)); // .../includes -> raiz do projeto
    if ($__docroot !== '' && str_starts_with($__projroot, $__docroot)) {
        $__bios_base = rtrim(substr($__projroot, strlen($__docroot)), '/');
    }
    define('BIOS_BASE', $__bios_base);
}

/* ── Segurança ───────────────────────────────────────────── */

if (!function_exists('h')) {
    /** Escapa saída HTML com segurança */
    function h(?string $str): string {
        return htmlspecialchars((string)($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('jsvar')) {
    /** JSON seguro para embutir em <script> (auditoria seg. 23/07, A-2/XSS raiz-a).
     *  Os flags HEX bloqueiam o breakout `</script>` e o uso do valor em atributos
     *  (aspas/apóstrofo/&). USAR SEMPRE que um dado do banco vira `var x = <?= ... ?>`
     *  dentro de HTML — NÃO usar em resposta de API (Content-Type: application/json). */
    function jsvar($data): string {
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
    }
}

/* BUG-CSRF (QA 19/07) + A2 (auditoria R3, 22/07): janela de tolerância para
   tokens recém-rotacionados. Tradeoff: aceitar os últimos 5 tokens DESTA MESMA
   sessão por até 4 h não enfraquece a proteção contra CSRF (um atacante externo
   continua sem conseguir ler token nenhum — todos são segredos presos à
   sessão/cookie httponly); apenas evita que um form aberto numa aba "morra"
   quando outras abas da mesma sessão provocam rotação do token (multi-aba com
   mais de 2 abas perdia digitação com o histórico anterior de 2).
   A validação segue obrigatória em todo POST. */
if (!defined('CSRF_PREV_MAX'))   define('CSRF_PREV_MAX', 5);       // quantos tokens antigos guardar
if (!defined('CSRF_PREV_GRACE')) define('CSRF_PREV_GRACE', 14400); // por quanto tempo (s) aceitá-los

if (!function_exists('csrf_token_valido')) {
    /** Valida um token contra o atual da sessão OU os recém-rotacionados (janela). */
    function csrf_token_valido(string $token): bool
    {
        if ($token === '') return false;
        $atual = (string)($_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '');
        if ($atual !== '' && hash_equals($atual, $token)) return true;
        foreach ((array)($_SESSION['csrf_prev'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $t  = (string)($p['t'] ?? '');
            $em = (int)($p['em'] ?? 0);
            if ($t !== '' && (time() - $em) <= CSRF_PREV_GRACE && hash_equals($t, $token)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('csrf_rotate')) {
    /** Rotaciona o token CSRF preservando o anterior na janela de tolerância
     *  (multi-aba: forms já renderizados continuam válidos por CSRF_PREV_GRACE). */
    function csrf_rotate(): string
    {
        $antigo = (string)($_SESSION['csrf_token'] ?? '');
        if ($antigo !== '') {
            $prev = (array)($_SESSION['csrf_prev'] ?? []);
            array_unshift($prev, ['t' => $antigo, 'em' => time()]);
            $_SESSION['csrf_prev'] = array_slice($prev, 0, CSRF_PREV_MAX);
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}

function csrfCheck(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $token = '';
    $viaHeaderOuJson = false;   // token veio de header/corpo JSON → é AJAX na certa

    // Form POST normal
    if (isset($_POST['csrf_token'])) {
        $token = (string)$_POST['csrf_token'];
    } elseif (isset($_POST['_csrf'])) {
        $token = (string)$_POST['_csrf'];
    }

    // Header AJAX
    if ($token === '') {
        $token = (string)(
            $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_BIOS_CSRF']
            ?? ''
        );
        if ($token !== '') $viaHeaderOuJson = true;
    }

    // JSON body
    if ($token === '') {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $token = (string)($json['csrf_token'] ?? $json['_csrf'] ?? '');
                if ($token !== '') $viaHeaderOuJson = true;
            }
        }
    }

    if (!csrf_token_valido($token)) {
        /* BUG-CSRF (QA 19/07): falha de CSRF num submit de PÁGINA não pode virar
           JSON cru em tela preta. Navegação → flash de erro + redirect (PRG: a
           tela volta com token novo no form). AJAX → JSON 403 com o token atual
           para o front re-hidratar e reenviar sem perder a digitação. */
        $ehNavegacao = (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate')
            || (!$viaHeaderOuJson && !isAjax()
                && str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html'));

        if ($ehNavegacao) {
            /* A2 (auditoria): preserva a digitação para repovoar após o PRG. */
            vero_old_input_stash($_POST);
            /* mesma chave/formato de vero_flash() (includes/vero_crud.php) */
            $_SESSION['vero_flash'][] = ['tipo' => 'aviso',
                'msg' => 'Sua sessão foi renovada e o formulário expirou por segurança. Nada foi gravado, '
                       . 'mas o que você preencheu foi mantido — confira os campos e clique em salvar novamente.'];
            header('Location: ' . (string)($_SERVER['REQUEST_URI'] ?? '/'), true, 303);
            exit;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'         => false,
            'success'    => false,
            'error'      => 'csrf',
            'message'    => 'Sessão renovada — o token de segurança expirou. Tente novamente.',
            'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),   // re-hidratação do front
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('csrf')) {
    /** Retorna token CSRF atual da sessão */
    function csrf(): string {
        return $_SESSION['csrf_token'] ?? '';
    }
}

/* ── Preservação de digitação no bounce de CSRF (auditoria A2) ──────────────
   Quando um POST de formulário de navegação falha o CSRF (sessão renovada em
   outra aba), a digitação seria perdida no redirect PRG. O csrfCheck guarda o
   que foi enviado (menos os tokens) por PATH+TTL; vero_flash_html() consome
   isso uma única vez e emite um <script> que repovoa os campos por `name`.
   One-shot: take() sempre apaga o stash ao ler. */
if (!function_exists('vero_old_input_path')) {
    function vero_old_input_path(): string {
        return (string) parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    }
}
if (!function_exists('vero_old_input_stash')) {
    /** Guarda os campos submetidos (exceto tokens) para repovoar após o bounce. */
    function vero_old_input_stash(array $post): void {
        unset($post['csrf_token'], $post['_csrf']);
        $_SESSION['vero_old_input'] = [
            'path' => vero_old_input_path(),
            'data' => $post,
            'at'   => time(),
        ];
    }
}
if (!function_exists('vero_old_input_take')) {
    /** Devolve (e APAGA — one-shot) os dados guardados p/ o path atual; TTL 5 min. */
    function vero_old_input_take(): array {
        $s = $_SESSION['vero_old_input'] ?? null;
        unset($_SESSION['vero_old_input']);
        if (!is_array($s)) return [];
        $valido = ($s['path'] ?? '') === vero_old_input_path()
            && (time() - (int)($s['at'] ?? 0)) <= 300;
        return $valido ? (array)($s['data'] ?? []) : [];
    }
}

if (!function_exists('isAjax')) {
    /** Verifica se é requisição AJAX ou JSON */
    function isAjax(): bool {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }
}

/* ── Strings ─────────────────────────────────────────────── */

if (!function_exists('initials')) {
    /** Gera iniciais de um nome (máx. 2 letras) */
    function initials(?string $name): string {
        if (!$name) return '?';
        $parts = array_filter(explode(' ', trim($name)));
        $result = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $result .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $result ?: '?';
    }
}

if (!function_exists('truncate')) {
    /** Trunca string com reticências */
    function truncate(string $str, int $max = 40): string {
        return mb_strlen($str) > $max ? mb_substr($str, 0, $max) . '…' : $str;
    }
}

if (!function_exists('slug')) {
    /** Slug a partir de string */
    function slug(string $str): string {
        $str = mb_strtolower($str);
        $str = preg_replace('/[áàãâä]/u', 'a', $str);
        $str = preg_replace('/[éèêë]/u', 'e', $str);
        $str = preg_replace('/[íìîï]/u', 'i', $str);
        $str = preg_replace('/[óòõôö]/u', 'o', $str);
        $str = preg_replace('/[úùûü]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        return trim($str, '-');
    }
}

/* ── Formatação ──────────────────────────────────────────── */

if (!function_exists('brl')) {
    /** Formata valor em Reais */
    function brl(?float $value, bool $showSymbol = true): string {
        $formatted = number_format((float)($value ?? 0), 2, ',', '.');
        return $showSymbol ? 'R$ ' . $formatted : $formatted;
    }
}

if (!function_exists('numFmt')) {
    /** Formata número com separadores */
    function numFmt(?float $value, int $decimals = 0): string {
        return number_format((float)($value ?? 0), $decimals, ',', '.');
    }
}

if (!function_exists('pct')) {
    /** Formata percentual */
    function pct(?float $value, int $decimals = 1): string {
        return number_format((float)($value ?? 0), $decimals, ',', '.') . '%';
    }
}

if (!function_exists('dateBR')) {
    /** Formata data do MySQL para BR */
    function dateBR(?string $date): string {
        if (!$date || $date === '0000-00-00') return '—';
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}

if (!function_exists('datetimeBR')) {
    /** Formata datetime para BR */
    function datetimeBR(?string $dt): string {
        if (!$dt) return '—';
        $ts = strtotime($dt);
        return $ts ? date('d/m/Y \à\s H:i', $ts) : '—';
    }
}

if (!function_exists('timeBR')) {
    /** Formata hora */
    function timeBR(?string $dt): string {
        if (!$dt) return '—';
        $ts = strtotime($dt);
        return $ts ? date('H:i', $ts) : '—';
    }
}

if (!function_exists('competencia')) {
    /** Retorna competência formatada (ex: Mai/2026) */
    function competencia(string $yearMonth): string {
        $meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
        [$year, $month] = explode('-', $yearMonth) + [null, null];
        if (!$year || !$month) return $yearMonth;
        return ($meses[(int)$month - 1] ?? $month) . '/' . $year;
    }
}

if (!function_exists('daysDiff')) {
    /** Diferença de dias entre duas datas */
    function daysDiff(?string $dateFrom, ?string $dateTo = null): int {
        $from = $dateFrom ? new DateTime($dateFrom) : new DateTime();
        $to   = $dateTo   ? new DateTime($dateTo)   : new DateTime();
        return (int)$from->diff($to)->days;
    }
}

/* ── Badges HTML ─────────────────────────────────────────── */

if (!function_exists('badgeStatus')) {
    /** Gera badge de status de associado */
    function badgeStatus(?string $status): string {
        $map = [
            'Ativo'        => ['success', 'ti-circle-check'],
            'Inativo'      => ['neutral', 'ti-circle-minus'],
            'Inadimplente' => ['danger',  'ti-alert-circle'],
            'Suspenso'     => ['warning', 'ti-ban'],
            'Isento'       => ['neutral', 'ti-minus'],
        ];
        [$type, $icon] = $map[$status] ?? ['neutral', 'ti-circle'];
        return sprintf(
            '<span class="bios-badge bios-badge--%s"><i class="ti %s" aria-hidden="true"></i>%s</span>',
            h($type), h($icon), h($status ?? '—')
        );
    }
}

if (!function_exists('badgeFin')) {
    /** Gera badge de situação financeira */
    function badgeFin(?string $status): string {
        $map = [
            'Pago'      => ['success', 'ti-circle-check'],
            'Em aberto' => ['warning', 'ti-clock'],
            'Vencido'   => ['danger',  'ti-alert-circle'],
            'Isento'    => ['neutral', 'ti-minus'],
        ];
        [$type, $icon] = $map[$status] ?? ['neutral', 'ti-circle'];
        return sprintf(
            '<span class="bios-badge bios-badge--%s"><i class="ti %s" aria-hidden="true"></i>%s</span>',
            h($type), h($icon), h($status ?? '—')
        );
    }
}

if (!function_exists('badge')) {
    /** Gera badge genérico */
    function badge(string $label, string $type = 'neutral', string $icon = ''): string {
        $iconHtml = $icon ? "<i class=\"ti {$icon}\" aria-hidden=\"true\"></i>" : '';
        return sprintf(
            '<span class="bios-badge bios-badge--%s">%s%s</span>',
            h($type), $iconHtml, h($label)
        );
    }
}

/* ── Avatar HTML ─────────────────────────────────────────── */

if (!function_exists('avatar')) {
    function avatar(string $name, string $size = 'sm', string $color = 'info'): string {
        return sprintf(
            '<div class="bios-avatar bios-avatar--%s bios-avatar--%s" aria-hidden="true">%s</div>',
            h($size), h($color), h(initials($name))
        );
    }
}

/* ── Paginação ───────────────────────────────────────────── */

if (!function_exists('paginationHTML')) {
    /** Gera HTML da paginação */
    function paginationHTML(int $currentPage, int $totalPages, string $baseUrl = '?'): string {
        if ($totalPages <= 1) return '';

        $sep  = str_contains($baseUrl, '?') ? '&' : '?';
        $html = '<div class="bios-pagination__pages">';

        if ($currentPage > 1) {
            $html .= "<a href=\"{$baseUrl}{$sep}page=" . ($currentPage - 1) . "\" class=\"bios-page-btn\"><i class=\"ti ti-chevron-left\"></i></a>";
        }

        $range = range(max(1, $currentPage - 2), min($totalPages, $currentPage + 2));
        foreach ($range as $i) {
            $active = $i === $currentPage ? 'active' : '';
            $html .= "<a href=\"{$baseUrl}{$sep}page={$i}\" class=\"bios-page-btn {$active}\">{$i}</a>";
        }

        if ($currentPage < $totalPages) {
            $html .= "<a href=\"{$baseUrl}{$sep}page=" . ($currentPage + 1) . "\" class=\"bios-page-btn\"><i class=\"ti ti-chevron-right\"></i></a>";
        }

        $html .= '</div>';
        return $html;
    }
}

/* ── JSON response ───────────────────────────────────────── */

if (!function_exists('jsonOk')) {
    function jsonOk(array $data = [], string $message = 'OK'): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => $message, 'data' => $data]);
        exit;
    }
}

if (!function_exists('jsonError')) {
    function jsonError(string $message, int $code = 400): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }
}

/* ── Roteamento de "landing": primeiro módulo acessível ao usuário ─────────── */
if (!function_exists('vero_dbn_perm')) {
    /** Replica a lógica de _auth_hasPermission de forma auto-contida (sem sessão). */
    function vero_dbn_perm(string $perm, string $role, array $perms): bool
    {
        if (in_array($role, ['super_admin', 'club_admin'], true)) return true;
        if (in_array('*', $perms, true)) return true;
        if (in_array($perm, $perms, true)) return true;
        foreach ($perms as $g) {
            if (is_string($g) && str_ends_with($g, '.*') && str_starts_with($perm, substr($g, 0, -1))) return true;
        }
        $dot = strrpos($perm, '.');
        if ($dot !== false && in_array('*' . substr($perm, $dot), $perms, true)) return true;
        return false;
    }
}
if (!function_exists('bios_has_module_perm')) {
    /** Verdadeiro se o usuário tem QUALQUER permissão do módulo (ex.: 'esportes.*'). */
    function bios_has_module_perm(string $mod, string $role, array $perms): bool
    {
        if (in_array($role, ['super_admin', 'club_admin'], true)) return true;
        if (in_array('*', $perms, true)) return true;
        $pref = $mod . '.';
        foreach ($perms as $p) {
            if (is_string($p) && str_starts_with($p, $pref)) return true;
        }
        return false;
    }
}
if (!function_exists('bios_landing_url')) {
    /**
     * Destino pós-login (e do "Voltar ao início" do 403), SEM BIOS_BASE.
     * Landing padrão = dashboard (mesma permissão do guard da tela); perfil
     * sem essa permissão vai ao PRIMEIRO micro VISÍVEL da matriz canônica do
     * menu (bios_menu_primeira_rota). R12B1: a lista local antiga usava slugs
     * legados ('dashboard.view', 'agro.fazenda.ver') e o atalho "qualquer
     * permissão do módulo" mandava perfis restritos (mao_de_obra/monitor) a
     * telas proibidas → 403 em loop logo após o login.
     * Sem NENHUMA tela visível → 403 honesto (motivo=sem_telas).
     */
    function bios_landing_url(string $role, array $perms): string
    {
        /* Matriz canônica do menu (require tardio: menu_agro.php já inclui
           este arquivo no topo — carregar aqui evita ciclo na inicialização). */
        require_once __DIR__ . '/menu_agro.php';

        $ctx = ['plano' => bios_plano_tenant(), 'role' => $role, 'perms' => $perms];

        /* Landing (pedido 21/07 noite): Mapa da Fazenda — clima geral + previsão de
           15 dias na abertura. Fallback: dashboard e, então, 1ª rota do menu. */
        if (vero_dbn_perm('agro.mapa_fazenda.ver', $role, $perms)) {
            return '/agro/mapa';
        }
        if (vero_dbn_perm('dashboard.visao_geral.ver', $role, $perms)
            && bios_plano_libera($ctx['plano'], 'dashboard', 'visao_geral')) {
            return '/dashboard';
        }

        return bios_menu_primeira_rota($ctx) ?? '/403?motivo=sem_telas';
    }
}
