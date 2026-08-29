<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/print_doc.php  (A0-22: impressos A4 canônicos)
   Componente único de impressão: cabeçalho (logo VERO + identidade da
   empresa emissora + título + nº/data), CSS @page A4 e rodapé com
   paginação. Sem resíduos de dev. Domínio A0 (includes/).

   P-106 (resolvida): a LOGO é a do VERO (assets/img/logo_vero.png); a
   identidade textual (razão/CNPJ/endereço/inscrição) é da EMPRESA/fazenda
   e vem de tenant_parametros (chaves empresa.*). DEGRADÁVEL: enquanto o
   cliente não preencher (P-106), mostra placeholders — o código já nasce
   pronto; só falta popular os dados.

   Uso na tela de impressão:
     require_once __DIR__.'/../includes/print_doc.php';
     echo print_doc_css();
     echo print_doc_cabecalho('Ordem de Serviço', ['Nº' => $os['numero'], 'Data' => date('d/m/Y')]);
     ... conteúdo ...
     echo print_doc_rodape();
   ============================================================ */

/** Identidade da empresa emissora (degradável). tenant_parametros empresa.*
 *  → fallback agro_fazendas (1ª ativa) → placeholder. */
function print_doc_identidade(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $p = static function (string $chave): ?string {
        $v = function_exists('vero_srv_param') ? vero_srv_param($chave) : null;
        return ($v !== null && trim((string)$v) !== '') ? (string)$v : null;
    };
    $faz = null;
    if (function_exists('vero_row')) {
        $faz = vero_row("SELECT nome, cnpj_cpf, inscricao FROM agro_fazendas
                          WHERE tenant_id = :t AND ativo = 1 ORDER BY id LIMIT 1",
                        [':t' => vero_tenant()]) ?: null;
    }
    $cache = [
        'razao'     => $p('empresa.razao_social') ?? ($faz['nome'] ?? null),
        'cnpj'      => $p('empresa.cnpj')         ?? ($faz['cnpj_cpf'] ?? null),
        'inscricao' => $p('empresa.inscricao')    ?? ($faz['inscricao'] ?? null),
        'endereco'  => $p('empresa.endereco'),
        'municipio' => $p('empresa.municipio_uf'),
    ];
    return $cache;
}

/** CSS de impressão A4 (chame uma vez no <head>/topo do conteúdo imprimível). */
function print_doc_css(): string
{
    return <<<'CSS'
<style media="all">
  @page { size: A4; margin: 14mm 12mm 16mm 12mm; }
  .pd-doc { font-family:'IBM Plex Sans',system-ui,Arial,sans-serif; color:#241B14; font-size:12px; }
  .pd-head { display:flex; align-items:center; gap:14px; border-bottom:2px solid #005059; padding-bottom:10px; margin-bottom:14px; }
  .pd-logo { height:46px; width:auto; flex:none; }
  .pd-emit { flex:1; min-width:0; line-height:1.3; }
  .pd-emit .r { font-weight:700; font-size:14px; color:#00363D; }
  .pd-emit .l { color:#6B5D49; font-size:11px; }
  .pd-meta { text-align:right; font-size:11px; color:#241B14; white-space:nowrap; }
  .pd-meta .t { font-weight:700; font-size:15px; color:#005059; display:block; margin-bottom:3px; }
  .pd-meta b { color:#00363D; }
  .pd-foot { position:fixed; bottom:6mm; left:12mm; right:12mm; border-top:1px solid #C9BCA6;
             padding-top:4px; font-size:9.5px; color:#8B7C68; display:flex; justify-content:space-between; }
  @media print { .no-print { display:none !important; } .pd-foot { position:fixed; } }
  @media screen { .pd-doc { max-width:820px; margin:16px auto; background:#fff; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,.08); } }
</style>
CSS;
}

/** Cabeçalho do documento: logo VERO + identidade da empresa + título + meta (Nº/Data…). */
function print_doc_cabecalho(string $titulo, array $meta = []): string
{
    $id   = print_doc_identidade();
    $base = defined('BIOS_BASE') ? BIOS_BASE : '';
    $h    = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $ph   = static fn(?string $v, string $alt) => $v !== null ? $h($v) : '<span style="color:#B9A98F">' . $alt . '</span>';

    $linha2 = array_filter([
        $id['cnpj'] ? 'CNPJ/CPF: ' . $h($id['cnpj']) : null,
        $id['inscricao'] ? 'IE: ' . $h($id['inscricao']) : null,
    ]);
    $linha3 = array_filter([$id['endereco'] ? $h($id['endereco']) : null, $id['municipio'] ? $h($id['municipio']) : null]);

    $metaHtml = '';
    foreach ($meta as $k => $v) {
        $metaHtml .= '<div>' . $h($k) . ': <b>' . $h($v) . '</b></div>';
    }

    return '<div class="pd-head">'
         . '<img class="pd-logo" src="' . $h($base) . '/assets/img/logo_vero.png" alt="VERO">'
         . '<div class="pd-emit">'
         . '<div class="r">' . $ph($id['razao'], 'Identidade da empresa não configurada') . '</div>'
         . ($linha2 ? '<div class="l">' . implode(' · ', $linha2) . '</div>' : '')
         . ($linha3 ? '<div class="l">' . implode(' — ', $linha3) . '</div>' : '')
         . '</div>'
         . '<div class="pd-meta"><span class="t">' . $h($titulo) . '</span>' . $metaHtml . '</div>'
         . '</div>';
}

/** Rodapé fixo com identificação do emissor + espaço p/ paginação (impressão). */
function print_doc_rodape(?string $obs = null): string
{
    $id = print_doc_identidade();
    $h  = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $emp = $id['razao'] ? $h($id['razao']) : 'VERO';
    return '<div class="pd-foot"><span>' . $emp . ($obs ? ' · ' . $h($obs) : '')
         . '</span><span>Emitido pelo VERO · ' . date('d/m/Y H:i') . '</span></div>';
}
