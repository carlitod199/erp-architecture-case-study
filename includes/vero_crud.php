<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/vero_crud.php
   Camada CRUD mínima e reutilizável para as telas operacionais.
   ------------------------------------------------------------
   Contratos que este arquivo respeita (não reimplementa nada):
   - Sessão/login/permissões: includes/auth.php (incluir ANTES).
   - CSRF: csrf() e csrfCheck() de includes/functions.php.
   - Permissão: vero_dbn_perm() de includes/functions.php
     (wildcards '*', 'agro.*', '*.ver' já suportados).
   - Conexão: Database::getConnection() de includes/db.php.
   - Guard de página: $GUARD + agro_header.php (como nas telas mock).

   Padrão de uso em uma tela (ver agro/fazendas.php):
     require auth.php  →  require vero_crud.php
     if POST: csrfCheck() → vero_can() → insert/update/delete → vero_redirect()
     $GUARD = [...]; require agro_header.php; render; require footer.
   ============================================================ */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/* ───────────────────────── Núcleo / contexto ───────────────────────── */

function vero_pdo(): PDO
{
    return Database::getConnection();
}

function vero_tenant(): int
{
    $t = (int)($_SESSION['tenant_id'] ?? 0);
    if ($t <= 0) {
        // Sessão sem tenant é anomalia: nunca operar sem escopo multiempresa.
        http_response_code(403);
        exit('Sessão sem tenant. Faça login novamente.');
    }
    return $t;
}

function vero_uid(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

/** Permissão do usuário logado (super_admin/club_admin e wildcards inclusos). */
function vero_can(string $perm): bool
{
    $role  = (string)($_SESSION['user_role'] ?? '');
    $perms = (array)($_SESSION['permissions'] ?? []);
    if (function_exists('vero_dbn_perm')) {
        return vero_dbn_perm($perm, $role, $perms);
    }
    return in_array($role, ['super_admin', 'club_admin'], true);
}

/** Nega ação de escrita sem derrubar a página: flash + volta (PRG). */
function vero_require(string $perm): void
{
    if (!vero_can($perm)) {
        vero_flash('erro', 'Sem permissão para esta ação (' . h($perm) . ').');
        vero_redirect();
    }
}

/* ───────────────────────── Flash + redirect (PRG) ───────────────────────── */

function vero_flash(string $tipo, string $msg): void
{
    $_SESSION['vero_flash'][] = ['tipo' => $tipo, 'msg' => $msg];
}

function vero_flash_html(): string
{
    $out = '';
    foreach ((array)($_SESSION['vero_flash'] ?? []) as $f) {
        $cls = $f['tipo'] === 'ok' ? 'vflash-ok' : ($f['tipo'] === 'aviso' ? 'vflash-aviso' : 'vflash-erro');
        $out .= '<div class="vflash ' . $cls . '">' . h($f['msg']) . '</div>';
    }
    unset($_SESSION['vero_flash']);

    /* A2 (auditoria): após um bounce de CSRF, repovoa os campos por `name` com o
       que o usuário havia digitado (guardado por csrfCheck). One-shot: take()
       apaga o stash. Só preenche campos VAZIOS; pula tokens/senha/arquivo e
       campos-array (name[]) — formulários de linhas dinâmicas se refazem sozinhos. */
    $old = function_exists('vero_old_input_take') ? vero_old_input_take() : [];
    if ($old) {
        $json = json_encode($old, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $out .= '<script>(function(){var d=' . $json . ';function fill(){'
            . 'var reabrir=null;'
            . 'for(var k in d){if(!Object.prototype.hasOwnProperty.call(d,k)||typeof d[k]!=="string")continue;'
            . 'var els=document.getElementsByName(k);'
            . 'for(var i=0;i<els.length;i++){var el=els[i],t=(el.type||"").toLowerCase();'
            . 'if(t==="password"||t==="file"||t==="hidden"||t==="submit"||t==="button")continue;'
            . 'if(t==="checkbox"||t==="radio"){if(el.value===d[k])el.checked=true;continue;}'
            . 'if((el.value||"")===""){el.value=d[k];if(!reabrir&&el.closest)reabrir=el.closest(".vmodal");}}}'
            /* X-04/Y-01: reabre o modal com os campos repovoados — o usuário
               continua de onde parou (não parece que "caiu"). */
            . 'if(reabrir)reabrir.classList.add("open");}'
            . 'if(document.readyState!=="loading")fill();else document.addEventListener("DOMContentLoaded",fill);})();</script>';
    }
    return $out;
}

/** Redireciona para a própria tela (ou URL relativa interna) e encerra. */
function vero_redirect(?string $url = null): never
{
    if ($url === null) {
        $url = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '/';
        $keep = [];
        foreach (['q', 'pg', 'fazenda', 'safra'] as $k) {          // filtros preservados no PRG
            if (isset($_GET[$k]) && $_GET[$k] !== '') $keep[$k] = (string)$_GET[$k];
        }
        if ($keep) $url .= '?' . http_build_query($keep);
    }
    /* URLs limpas (27/07): remove o .php do caminho, preservando a query —
       o redirect PRG já aponta para a versão sem extensão (sem hop extra). */
    $url = preg_replace('#\.php(\?|$)#', '$1', $url, 1);
    header('Location: ' . $url);
    exit;
}

/* ───────────────────────── Entrada (POST) ───────────────────────── */

function vero_str(string $k, int $max = 255): ?string
{
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v === '') return null;
    return mb_substr($v, 0, $max);
}

function vero_int(string $k): ?int
{
    $v = trim((string)($_POST[$k] ?? ''));
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}

/** Aceita "1.234,56", "1234.56" e "25.000" (ponto como milhar pt-BR sem decimais). */
function vero_dec(string $k): ?float
{
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v === '') return null;
    $v = str_replace(' ', '', $v);
    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        // só pontos em grupos de 3 => separador de milhar (ex.: 25.000 = 25000)
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

function vero_date(string $k): ?string
{
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v) ?: DateTime::createFromFormat('d/m/Y', $v);
    return $d ? $d->format('Y-m-d') : null;
}

/* ───────────────────────── Leitura ───────────────────────── */

function vero_rows(string $sql, array $p = []): array
{
    $st = vero_pdo()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function vero_row(string $sql, array $p = []): ?array
{
    $st = vero_pdo()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function vero_val(string $sql, array $p = [])
{
    $st = vero_pdo()->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
}

/**
 * Valida uma FK vinda do request contra o tenant atual (auditoria seg. 23/07,
 * A-4/A-5). Retorna o próprio id se a linha existir NESTE tenant, senão null —
 * assim `'x_id' => vero_fk_tenant('tabela', vero_int('x_id'))` neutraliza
 * referência a id sequencial de outro tenant. O nome da tabela é SEMPRE um
 * literal do código (allowlist do chamador), nunca entrada do usuário.
 */
function vero_fk_tenant(string $tabela, ?int $id): ?int
{
    if ($id === null || $id <= 0) return null;
    $ok = vero_val("SELECT id FROM `{$tabela}` WHERE id = :i AND tenant_id = :t",
        [':i' => $id, ':t' => vero_tenant()]);
    return $ok ? $id : null;
}

/** id => label para selects, sempre escopado no tenant. */
function vero_options(string $table, string $labelCol = 'nome', string $extraWhere = '', array $p = []): array
{
    $sql = "SELECT id, {$labelCol} AS label FROM {$table}
            WHERE tenant_id = :t " . ($extraWhere ? " AND {$extraWhere} " : '') . "
            ORDER BY {$labelCol}";
    $rows = vero_rows($sql, array_merge([':t' => vero_tenant()], $p));
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = (string)$r['label'];
    return $out;
}

/**
 * Rótulo CURTO de exibição de uma safra (P-04). Remove o sufixo interno "-NN"
 * usado apenas para desambiguar o código quando há mais de uma safra do mesmo
 * período (ex.: "2026.2-01" → "2026.2"). É SÓ apresentação — NÃO altera o
 * código no banco nem as amarrações. Códigos sem esse padrão ficam intactos
 * ("2027.1" → "2027.1", "QA5-2027.1" → "QA5-2027.1").
 */
function vero_safra_rotulo(string $ident): string
{
    $curto = preg_replace('/-\d+$/', '', trim($ident));
    return ($curto === null || $curto === '') ? $ident : $curto;
}

/**
 * Mapa id => rótulo curto para montar <select>/labels de safra (P-04),
 * desambiguando colisões: se duas safras distintas colapsam no mesmo rótulo
 * curto, a 2ª (3ª…) recebe um sufixo discreto " ·N" para continuar
 * selecionável, mantendo SEMPRE o value = id real.
 *
 * Aceita tanto linhas de banco ([['id'=>..,'identificacao'=>..], ...]) quanto
 * um mapa id=>identificacao (retorno de vero_options).
 */
function vero_safra_rotulos(array $safras, string $idKey = 'id', string $identKey = 'identificacao'): array
{
    $out = [];
    $vistos = [];
    foreach ($safras as $k => $s) {
        if (is_array($s)) {
            $id    = (int)($s[$idKey] ?? 0);
            $ident = (string)($s[$identKey] ?? '');
        } else {
            $id    = (int)$k;
            $ident = (string)$s;
        }
        $rot = vero_safra_rotulo($ident);
        $n = $vistos[$rot] = ($vistos[$rot] ?? 0) + 1;
        $out[$id] = $n > 1 ? $rot . ' ·' . $n : $rot;
    }
    return $out;
}

/** Descobre se a coluna existe (usado p/ detectar migrations pendentes). */
function vero_has_column(string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (!isset($cache[$key])) {
        $cache[$key] = (bool)vero_val(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c",
            [':t' => $table, ':c' => $col]
        );
    }
    return $cache[$key];
}

/* ───────────────────────── Escrita ───────────────────────── */

/** INSERT com tenant + auditoria. Retorna o id novo. */
function vero_insert(string $table, array $data): int
{
    $data['tenant_id']  = vero_tenant();
    $data['created_by'] = vero_uid();
    $data['updated_by'] = vero_uid();

    $cols = array_keys($data);
    $sql  = "INSERT INTO {$table} (" . implode(',', $cols) . ")
             VALUES (:" . implode(',:', $cols) . ")";
    $st = vero_pdo()->prepare($sql);
    foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
    $st->execute();
    return (int)vero_pdo()->lastInsertId();
}

/** UPDATE por id SEMPRE dentro do tenant. */
function vero_update(string $table, int $id, array $data): void
{
    $data['updated_by'] = vero_uid();
    $sets = [];
    foreach (array_keys($data) as $k) $sets[] = "{$k} = :{$k}";
    $sql = "UPDATE {$table} SET " . implode(', ', $sets) . "
            WHERE id = :_id AND tenant_id = :_t LIMIT 1";
    $st = vero_pdo()->prepare($sql);
    foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
    $st->bindValue(':_id', $id, PDO::PARAM_INT);
    $st->bindValue(':_t', vero_tenant(), PDO::PARAM_INT);
    $st->execute();
}

/**
 * Exclusão segura: se a tabela tem `ativo`, inativa (soft delete);
 * senão tenta DELETE e traduz violação de FK em mensagem amigável.
 */
function vero_delete(string $table, int $id): void
{
    if (vero_has_column($table, 'ativo')) {
        vero_update($table, $id, ['ativo' => 0]);
        vero_flash('ok', 'Registro inativado. Ele deixa de aparecer nas listas ativas, mas o histórico é preservado.');
        return;
    }
    try {
        $st = vero_pdo()->prepare("DELETE FROM {$table} WHERE id = :id AND tenant_id = :t LIMIT 1");
        $st->execute([':id' => $id, ':t' => vero_tenant()]);
        vero_flash('ok', 'Registro excluído.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            vero_flash('erro', 'Não é possível excluir: existem registros vinculados a este item.');
        } else {
            throw $e;
        }
    }
}

/* ───────────────────────── Segurança de upload ───────────────────────── */

/**
 * Confere que o CONTEÚDO REAL do arquivo enviado corresponde ao tipo declarado
 * pela extensão, complementando (sem substituir) a allowlist de extensão já
 * aplicada por cada tela — defesa em camadas contra arquivos disfarçados.
 *
 *  - Imagens (jpg/jpeg/png/gif/webp): precisa ser uma imagem raster real.
 *  - PDF: precisa começar com a assinatura de um PDF de verdade.
 *  - Outros tipos: retorna true (validação específica fica a cargo da tela).
 *
 * @param string $tmpPath caminho temporário do upload ($_FILES[...]['tmp_name'])
 * @param string $ext     extensão já normalizada em minúsculas
 * @return bool           true se o conteúdo é coerente com a extensão
 */
function vero_upload_conteudo_ok(string $tmpPath, string $ext): bool
{
    if ($tmpPath === '' || !is_readable($tmpPath)) {
        return false;
    }
    $ext = strtolower($ext);

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $info = @getimagesize($tmpPath);
        // getimagesize só reconhece imagens reais; qualquer outra coisa (PHP,
        // HTML, script disfarçado) devolve false e é recusada.
        return is_array($info)
            && isset($info['mime'])
            && strncmp((string)$info['mime'], 'image/', 6) === 0;
    }

    if ($ext === 'pdf') {
        $fh = @fopen($tmpPath, 'rb');
        if ($fh === false) {
            return false;
        }
        $cabecalho = (string)fread($fh, 5);
        fclose($fh);
        return strncmp($cabecalho, '%PDF-', 5) === 0;
    }

    return true;
}

/* ───────────────────────── UI compartilhada ───────────────────────── */

/**
 * CSS + JS das telas CRUD (cards, tabela, badges, modal, formulário).
 * Usar em $EXTRA_HEAD antes de incluir agro_header.php.
 * Paleta alinhada ao shell existente (fundo #EDEAE0, accent #005059).
 */
function vero_assets(): string
{
    return <<<'HTML'
<style>
.vwrap{padding:26px 30px;width:100%;box-sizing:border-box}
.vhead{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.vhead h1{font-size:22px;font-weight:700;margin:0;color:#1E1610}
.vhead .vsub{color:#6B5F53;font-size:12.5px;margin-top:3px}
.vbtn{display:inline-flex;align-items:center;gap:7px;border:0;border-radius:10px;padding:10px 16px;font:600 13px 'IBM Plex Sans',sans-serif;cursor:pointer;text-decoration:none}
.vbtn-primary{background:#005059;color:#fff}
.vbtn-primary:hover{background:#00363D}
.vbtn-ghost{background:transparent;color:#005059;border:1px solid #C9C2B4}
.vbtn-sm{padding:6px 10px;font-size:12px;border-radius:8px}
.vcard{background:#fff;border:1px solid #DDD6C8;border-radius:14px;box-shadow:0 1px 2px rgba(43,32,24,.05)}
.vtoolbar{display:flex;gap:10px;align-items:center;padding:14px 16px;border-bottom:1px solid #EEE8DB;flex-wrap:wrap}
.vtoolbar input[type=text],.vtoolbar select{border:1px solid #D5CEBF;border-radius:9px;padding:8px 11px;font:13px 'IBM Plex Sans';background:#FBFAF6;min-width:200px}
.vtable{width:100%;border-collapse:collapse;font-size:13px}
.vtable th{text-align:left;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8A7D6E;padding:11px 16px;border-bottom:1px solid #EEE8DB}
.vtable td{padding:11px 16px;border-bottom:1px solid #F2EDE2;color:#2B2018;vertical-align:middle}
.vtable tr:last-child td{border-bottom:0}
.vtable tr:hover td{background:#FAF8F1}
.vnum{font-family:'IBM Plex Mono',monospace}
/* status = ponto colorido + texto (sem pílula) — decisão do usuário 04/07 */
.vbadge{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:500;color:#2B2018;white-space:nowrap}
.vbadge::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--vbdot,#9A8C78);flex:none}
.vb-ok{--vbdot:#0E7E72}.vb-off{--vbdot:#B23A2E}.vb-info{--vbdot:#005059}.vb-warn{--vbdot:#B57C1A}
/* ações = ícone sem borda/fundo; cor só no hover (editar teal, excluir vermelho) */
.vactions{display:flex;gap:2px;justify-content:flex-end;align-items:center}
.vactions form{margin:0}
.vicon{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:0;background:none;cursor:pointer;color:#9A8C78;border-radius:8px;text-decoration:none;transition:color .15s ease}
.vicon svg{width:17px;height:17px;display:block}
.vicon-edit:hover,.vicon-acao:hover{color:#005059}.vicon-del:hover{color:#B23A2E}
.vicon:focus-visible{outline:2px solid #005059;outline-offset:2px}
.vempty{padding:34px;text-align:center;color:#8A7D6E;font-size:13px}
.vflash{border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:13px;font-weight:500}
.vflash-ok{background:#E3F0E6;color:#1E6B34;border:1px solid #BEDCC6}
.vflash-erro{background:#F3E7E4;color:#9A3B2A;border:1px solid #E4C4BC}
.vflash-aviso{background:#F7EFD9;color:#8A6D1A;border:1px solid #E8D9A8}
.vpagin{padding:12px 16px;display:flex;gap:6px}
.vpagin a,.vpagin span{padding:5px 10px;border-radius:8px;border:1px solid #D5CEBF;font-size:12px;text-decoration:none;color:#2B2018}
.vpagin .cur{background:#005059;color:#fff;border-color:#005059}
/* modal */
.vmodal{position:fixed;inset:0;background:rgba(20,15,10,.45);display:none;align-items:flex-start;justify-content:center;z-index:60;padding:5vh 16px}
.vmodal.open{display:flex}
.vmodal .vbox{background:#FDFCF9;border-radius:16px;max-width:720px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 18px 50px rgba(0,0,0,.25)}
.vbox header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px 8px}
.vbox header h2{margin:0;font-size:17px}
.vclose{border:0;background:none;font-size:20px;cursor:pointer;color:#6B5F53;line-height:1}
.vform{padding:10px 22px 20px}
.vgrid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px 16px}
.vgrid .full{grid-column:1/-1}
.vfield label{display:block;font-size:12px;font-weight:600;color:#4A4034;margin-bottom:5px}
.vfield input,.vfield select,.vfield textarea{width:100%;box-sizing:border-box;border:1px solid #D5CEBF;border-radius:9px;padding:9px 11px;font:13px 'IBM Plex Sans';background:#fff}
.vfield input:focus,.vfield select:focus{outline:2px solid #00505933;border-color:#005059}
.vform-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:16px}
.vhint{font-size:11.5px;color:#8A7D6E;margin-top:4px}
@media(max-width:820px){.vgrid{grid-template-columns:1fr}.vwrap{padding:18px 14px}}
</style>
<script>
function vModalOpen(id){var m=document.getElementById(id);if(m){m.classList.add('open');var f=m.querySelector('input,select');if(f)f.focus();}}
/* C-43/A-07: "+ Novo" após um "Editar" abria o form ainda
   preenchido com o registro anterior (a página inteira é renderizada em modo
   edição via ?editar=N). Se o form do vm-form tem id gravado, recarrega a URL
   limpa com ?novo=1 (o load reabre o modal já vazio); senão, abre direto. */
function vModalNovo(id){
  var m=document.getElementById(id);if(!m)return;
  var idc=m.querySelector('input[name="id"]');
  if(idc&&idc.value!==''){
    var u=new URL(location.href);
    u.searchParams.delete('editar');u.searchParams.set('novo','1');
    location.href=u;return;
  }
  vModalOpen(id);
}
document.addEventListener('DOMContentLoaded',function(){
  try{if(new URL(location.href).searchParams.get('novo')==='1'&&document.getElementById('vm-form'))vModalOpen('vm-form');}catch(e){}
});
function vModalClose(id){var m=document.getElementById(id);if(m)m.classList.remove('open');
  if(history.replaceState){var u=new URL(location.href);u.searchParams.delete('editar');u.searchParams.delete('novo');history.replaceState(null,'',u);}}
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.vmodal.open').forEach(function(m){m.classList.remove('open')})});
document.addEventListener('click',function(e){if(e.target.classList&&e.target.classList.contains('vmodal'))e.target.classList.remove('open')});
</script>
HTML;
}

/**
 * Topbar da tela: barra fixa no topo do conteúdo com o título e a ação
 * principal (+ Novo). O título vive AQUI (não mais um <h1> solto no corpo);
 * o subtítulo/descrição foi removido das telas por decisão do A0 (04/07).
 * `$subtitulo` é aceito por compatibilidade de assinatura, mas ignorado.
 */
function vero_page_header(string $titulo, string $subtitulo = '', ?string $novoLabel = '+ Novo'): string
{
    $btn = $novoLabel !== null
        ? '<button class="vbtn vbtn-primary" type="button" onclick="vModalNovo(\'vm-form\')">' . h($novoLabel) . '</button>'
        : '';
    return '<header class="vero-topbar">'
        . '<h1 class="vero-topbar__title">' . h($titulo) . '</h1>'
        . '<div class="vero-topbar__actions">' . $btn . '</div>'
        . '</header>';
}

/* Campos de formulário */
function vero_f_text(string $name, string $label, ?string $val = '', bool $req = false, string $hint = '', string $type = 'text'): string
{
    return '<div class="vfield"><label>' . h($label) . ($req ? ' *' : '') . '</label>'
        . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h((string)$val) . '"' . ($req ? ' required' : '') . '>'
        . ($hint !== '' ? '<div class="vhint">' . h($hint) . '</div>' : '') . '</div>';
}

function vero_f_select(string $name, string $label, array $options, $selected = null, bool $req = false, string $placeholder = '— Selecione —'): string
{
    $html = '<div class="vfield"><label>' . h($label) . ($req ? ' *' : '') . '</label>'
        . '<select name="' . h($name) . '"' . ($req ? ' required' : '') . '>'
        . '<option value="">' . h($placeholder) . '</option>';
    foreach ($options as $k => $v) {
        $sel = ((string)$k === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . h((string)$k) . '"' . $sel . '>' . h((string)$v) . '</option>';
    }
    return $html . '</select></div>';
}

/** Badge ativo/inativo. */
function vero_b_ativo($ativo): string
{
    return ((int)$ativo === 1)
        ? '<span class="vbadge vb-ok">Ativo</span>'
        : '<span class="vbadge vb-off">Inativo</span>';
}

/** Ícone SVG (lixeira) para a ação de excluir. */
function vero_ico_lixeira(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>';
}
/** Ícone SVG (lápis) para a ação de editar. */
function vero_ico_lapis(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
}

/** Exclusão como mini-form POST com CSRF + confirmação — ícone sem borda/fundo.
 *  Confirmação = veroConfirm estilizado (AUD-024: intercepta via data-confirm em
 *  vero-ui.js) com fallback para confirm() nativo se o JS não carregar.
 *  $rotulo (OBS#8): ajusta title/aria-label do botão e o rótulo do OK do diálogo
 *  — use 'Inativar' quando a ação real é soft-delete. Default preserva os ~100
 *  call sites existentes. */
function vero_btn_excluir(int $id, string $confirmMsg = 'Confirma a exclusão/inativação deste registro?', string $rotulo = 'Excluir'): string
{
    return '<form method="post" data-confirm="' . h($confirmMsg) . '" data-confirm-danger data-confirm-ok="' . h($rotulo) . '"'
        . ' onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute(\'data-confirm\'))">'
        . '<input type="hidden" name="csrf_token" value="' . h(csrf()) . '">'
        . '<input type="hidden" name="acao" value="excluir">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="vicon vicon-del" type="submit" title="' . h($rotulo) . '" aria-label="' . h($rotulo) . '">' . vero_ico_lixeira() . '</button></form>';
}

/** Link "Editar" que reabre o modal preenchido (?editar=ID) — ícone sem borda/fundo. */
function vero_btn_editar(int $id): string
{
    $qs = $_GET;
    $qs['editar'] = $id;
    return '<a class="vicon vicon-edit" href="?' . h(http_build_query($qs)) . '" title="Editar" aria-label="Editar">' . vero_ico_lapis() . '</a>';
}

/* Ícones das demais AÇÕES de tabela (padrão: dentro de tabelas, botão = ícone). */
function vero_ico_olho(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
}
function vero_ico_mover(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16m0-16L3.5 7.5M7 4l3.5 3.5"/><path d="M17 20V4m0 16 3.5-3.5M17 20l-3.5-3.5"/></svg>';
}
function vero_ico_voltar(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7v6h6"/><path d="M3.5 13a9 9 0 1 0 2.6-7.4L3 8"/></svg>';
}
function vero_ico_imprimir(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>';
}
function vero_ico_seta(): string /* avançar etapa (ex.: converter em pedido) */
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
}
function vero_ico_x(): string /* cancelar / recusar */
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>';
}
function vero_ico_check(): string /* aprovar / confirmar */
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>';
}
function vero_ico_receber(): string /* receber (entrada de mercadoria) */
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>';
}

/** Ação de tabela por ÍCONE via POST (CSRF + confirmação) — para cancelar/recusar
 *  etc. sem virar link. $acao vai no campo `acao`; hover vermelho opcional. */
function vero_btn_icone_post(string $ico, string $label, string $acao, int $id, string $confirmMsg = '', bool $perigo = false): string
{
    $conf = $confirmMsg !== ''
        ? ' data-confirm="' . h($confirmMsg) . '"' . ($perigo ? ' data-confirm-danger' : '') . ' data-confirm-ok="' . h($label) . '"'
          . ' onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute(\'data-confirm\'))"'
        : '';
    return '<form method="post" style="display:inline"' . $conf . '>'
        . '<input type="hidden" name="csrf_token" value="' . h(csrf()) . '">'
        . '<input type="hidden" name="acao" value="' . h($acao) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="vicon ' . ($perigo ? 'vicon-del' : 'vicon-acao') . '" title="' . h($label) . '" aria-label="' . h($label) . '">' . $ico . '</button></form>';
}

/** Botão de AÇÃO de TABELA como ÍCONE sem borda (padrão do sistema: dentro de
 *  tabelas, ação = ícone com tooltip). $ico = um vero_ico_*; $label vira title +
 *  aria-label. Ação JS → $onclick (attribute-safe: aspas simples nos args);
 *  navegação → $href. */
function vero_btn_icone(string $ico, string $label, string $onclick = '', string $href = ''): string
{
    $t = ' title="' . h($label) . '" aria-label="' . h($label) . '"';
    if ($href !== '') {
        return '<a class="vicon vicon-acao"' . $t . ' href="' . h($href) . '">' . $ico . '</a>';
    }
    return '<button type="button" class="vicon vicon-acao"' . $t . ' onclick="' . $onclick . '">' . $ico . '</button>';
}

/** Paginação simples preservando filtros. */
function vero_pagination(int $page, int $total, int $perPage): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) return '';
    $page = max(1, min($page, $pages));

    /* Link de uma página (ou seta). $cls != '' => nunca vira "atual". */
    $link = static function (int $i, string $label = '', string $cls = '') use ($page): string {
        $txt = $label !== '' ? $label : (string)$i;
        if ($cls === '' && $i === $page) {
            return '<span class="cur">' . h($txt) . '</span>';
        }
        $qs = $_GET;
        $qs['pg'] = $i;
        unset($qs['editar']);
        return '<a' . ($cls !== '' ? ' class="' . h($cls) . '"' : '')
            . ' href="?' . h(http_build_query($qs)) . '">' . h($txt) . '</a>';
    };

    /* JANELA (bug 27/07: importações grandes mostravam TODAS as páginas — ex.:
       30 quadradinhos). Mostra 1 … (atual±1) … último, com setas ‹ ›. */
    $win  = 1;
    $nums = [1, $pages];
    for ($i = $page - $win; $i <= $page + $win; $i++) {
        if ($i >= 1 && $i <= $pages) $nums[] = $i;
    }
    $nums = array_values(array_unique($nums));
    sort($nums);

    $html = '<div class="vpagin">';
    if ($page > 1) $html .= $link($page - 1, '‹', 'pg-nav');
    $prev = 0;
    foreach ($nums as $n) {
        if ($prev && $n - $prev > 1) $html .= '<span class="pg-gap" style="padding:0 6px;color:#9A8C78">…</span>';
        $html .= $link($n);
        $prev = $n;
    }
    if ($page < $pages) $html .= $link($page + 1, '›', 'pg-nav');
    return $html . '</div>';
}

/* ─────────────── A0-20 (ARQ-01): bloco resiliente ───────────────
   Envolve a carga de UM bloco de tela (dashboards, cards): falhou →
   loga e devolve um aviso VISÍVEL no lugar (nunca bloco vazio
   silencioso). Adoção incremental pelos donos nas telas de blocos. */
function vero_bloco(callable $fn, string $rotulo = 'este bloco'): string
{
    try {
        return (string)$fn();
    } catch (Throwable $e) {
        error_log('[VERO][bloco] falha em ' . $rotulo . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return '<div class="vempty" role="alert" style="border-left:4px solid #B3261E">'
            . '⚠ Não foi possível carregar ' . h($rotulo) . ' — o erro foi registrado. Recarregue a página.'
            . '</div>';
    }
}