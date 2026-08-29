<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/fiscal/SefazTools.php  (SEFAZ F-SEFAZ-1)
   Adapter ISOLADO do sped-nfe: o core do VERO NÃO conhece a lib; só este
   arquivo. Monta o NFePHP\NFe\Tools a partir de fiscal_config (mig.154) +
   certificado (storage/certs, fora do git) + senha (config/fiscal_secrets.php,
   gitignored — NUNCA em texto no banco/git). Expõe a "prova de vida"
   (Status Serviço) em homologação. Tolerante a config incompleta: NUNCA fatal.
   ============================================================ */

require_once __DIR__ . '/../../vendor/autoload.php';

use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

/** Config fiscal do tenant atual (linha única). */
function vero_fiscal_config(): ?array
{
    return vero_row("SELECT * FROM fiscal_config WHERE tenant_id = :t LIMIT 1", [':t' => vero_tenant()]) ?: null;
}

/** Cofre de segredos fiscais do tenant (config/fiscal_secrets.php, gitignored — NUNCA no banco/git).
 *  Aceita 2 formatos por tenant_id: (a) array ['cert_path'=>..., 'cert_password'=>...]
 *  ou (b) string 'senha' (legado). Normaliza p/ ['cert_path','cert_password']; senha vazia = ausente. */
function vero_fiscal_secrets(int $tenantId): array
{
    $out = ['cert_path' => '', 'cert_password' => null];
    $f = __DIR__ . '/../../config/fiscal_secrets.php';
    if (!is_file($f)) return $out;
    $map = require $f;
    if (!is_array($map) || !array_key_exists($tenantId, $map)) return $out;
    $v = $map[$tenantId];
    if (is_array($v)) {
        $out['cert_path'] = trim((string)($v['cert_path'] ?? ''));
        $pw = array_key_exists('cert_password', $v) ? (string)$v['cert_password'] : '';
    } else {
        $pw = (string)$v;                    // legado: o valor é a própria senha
    }
    $out['cert_password'] = trim($pw) === '' ? null : $pw;   // senha em branco = ainda não entregue
    return $out;
}

/** Senha do certificado do tenant (de config/fiscal_secrets.php, gitignored). NUNCA do banco. */
function vero_fiscal_cert_senha(int $tenantId): ?string
{
    return vero_fiscal_secrets($tenantId)['cert_password'];
}

/** Caminho absoluto do .pfx: cert_path do cofre (se definido) OU storage/certs + fiscal_config.cert_arquivo. */
function vero_fiscal_cert_path(?array $cfg): ?string
{
    $override = vero_fiscal_secrets((int)($cfg['tenant_id'] ?? 0))['cert_path'];
    if ($override !== '') {
        // absoluto (C:\.. , C:/.. ou /..) → usa como está; senão relativo à raiz do projeto
        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $override) ? $override : (__DIR__ . '/../../' . $override);
    }
    $arq = basename((string)($cfg['cert_arquivo'] ?? ''));
    return $arq === '' ? null : __DIR__ . '/../../storage/certs/' . $arq;
}

/** O que falta para a integração funcionar (checklist de configuração). */
function vero_sefaz_faltando(?array $cfg): array
{
    $falta = [];
    if (!$cfg) return ['configuração fiscal (fiscal_config) não criada'];
    if (empty($cfg['uf']))              $falta[] = 'UF de emissão';
    if (empty($cfg['cnpj_cpf']))        $falta[] = 'CNPJ/CPF do emitente';
    if (empty($cfg['razao_social']))    $falta[] = 'razão social do emitente';
    $pfx = vero_fiscal_cert_path($cfg);
    if ($pfx === null)      $falta[] = 'certificado A1 (.pfx): defina cert_arquivo (banco) ou cert_path (config/fiscal_secrets.php)';
    elseif (!is_file($pfx)) $falta[] = 'arquivo do certificado não encontrado: ' . $pfx;
    if (vero_fiscal_cert_senha((int)($cfg['tenant_id'] ?? 0)) === null) $falta[] = 'senha do certificado (config/fiscal_secrets.php)';
    return $falta;
}

/** Monta o Tools do sped-nfe (ou null + motivo). Não emite nada — só prepara. */
function vero_sefaz_tools(): array
{
    $cfg = vero_fiscal_config();
    $falta = vero_sefaz_faltando($cfg);
    if ($falta) return ['tools' => null, 'faltando' => $falta, 'cfg' => $cfg];

    $tpAmb = (int)($cfg['ambiente'] ?? 2) === 1 ? 1 : 2;   // default seguro: homologação
    $doc   = preg_replace('/\D/', '', (string)$cfg['cnpj_cpf']);
    $chave = (($cfg['tipo_pessoa'] ?? 'PJ') === 'PF' || strlen($doc) === 11) ? 'cpf' : 'cnpj';

    $confArr = [
        'atualizacao' => date('Y-m-d H:i:s'),
        'tpAmb'       => $tpAmb,
        'razaosocial' => (string)$cfg['razao_social'],
        'siglaUF'     => (string)$cfg['uf'],
        $chave        => $doc,
        'schemes'     => 'PL_009_V4',
        'versao'      => '4.00',
        'CSC'         => (string)($cfg['csc'] ?? ''),
        'CSCid'       => (string)($cfg['id_csc'] ?? ''),
    ];
    try {
        $pfx  = vero_fiscal_cert_path($cfg);
        $cert = Certificate::readPfx((string)file_get_contents($pfx), (string)vero_fiscal_cert_senha((int)$cfg['tenant_id']));
        $tools = new Tools(json_encode($confArr), $cert);
        $tools->model('55');                 // NF-e modelo 55 (uva = atacado/B2B)
        return ['tools' => $tools, 'faltando' => [], 'cfg' => $cfg];
    } catch (Throwable $e) {
        return ['tools' => null, 'faltando' => ['certificado/senha inválidos: ' . $e->getMessage()], 'cfg' => $cfg];
    }
}

/** PROVA DE VIDA: consulta Status do Serviço na SEFAZ (homologação por padrão). */
function vero_sefaz_status_teste(): array
{
    $t = vero_sefaz_tools();
    if (!$t['tools']) return ['ok' => false, 'faltando' => $t['faltando'], 'cStat' => null, 'xMotivo' => 'Configuração incompleta'];
    try {
        $xml = $t['tools']->sefazStatus((string)$t['cfg']['uf'], (int)$t['cfg']['ambiente']);
        $st  = new SimpleXMLElement($xml);
        $ns  = $st->getNamespaces(true);
        // extrai cStat/xMotivo independente do namespace
        $cStat   = (string)($st->xpath('//*[local-name()="cStat"]')[0]   ?? '');
        $xMotivo = (string)($st->xpath('//*[local-name()="xMotivo"]')[0] ?? '');
        return ['ok' => $cStat === '107', 'cStat' => $cStat, 'xMotivo' => $xMotivo, 'faltando' => []]; // 107 = Serviço em Operação
    } catch (Throwable $e) {
        return ['ok' => false, 'cStat' => null, 'xMotivo' => 'Falha na comunicação: ' . $e->getMessage(), 'faltando' => []];
    }
}
