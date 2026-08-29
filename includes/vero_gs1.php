<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/vero_gs1.php
   Helper GS1-128 para a ETIQUETA DE CAIXA do Packing (exportação / GlobalG.A.P.).
   Monta a "element string" GS1 com Application Identifiers e gera a barra
   linear GS1-128 (Code128 com FNC1) como SVG inline, 100% offline via
   picqer/php-barcode-generator. Padrão de caixa de fruta fresca:
     (01) GTIN-14  ·  (3103) peso líquido kg (3 casas)  ·  (10) lote/batch
   A AI (10) é de comprimento variável → sempre por ÚLTIMO (dispensa FNC1
   separador). O texto legível (HRI) acompanha a barra e os campos vêm na
   etiqueta (lote, peso, GGN, variedade, etc.) — exigência de rastreabilidade.
   ============================================================ */

require_once __DIR__ . '/../vendor/autoload.php';

use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Renderers\SvgRenderer;

/** FNC1 do Code128 no picqer (linha TypeCode128: 241 => start GS1). */
const VERO_GS1_FNC1 = "\xF1"; // chr(241)

/**
 * Normaliza um GTIN para 14 dígitos (AI 01 é fixo em 14). Aceita EAN-8/12/13/14
 * (com dígito verificador) e valida o DV. Retorna null se ausente/ inválido.
 */
function vero_gs1_gtin14(?string $gtin): ?string
{
    if ($gtin === null) return null;
    $d = preg_replace('/\D+/', '', $gtin);
    if ($d === null || $d === '') return null;
    if (!in_array(strlen($d), [8, 12, 13, 14], true)) return null;
    $g14 = str_pad($d, 14, '0', STR_PAD_LEFT);
    return vero_gs1_gtin_valido($g14) ? $g14 : null;
}

/** Valida o dígito verificador de um GTIN-14 (pesos 3,1 da direita p/ esquerda). */
function vero_gs1_gtin_valido(string $g14): bool
{
    if (!preg_match('/^\d{14}$/', $g14)) return false;
    $sum = 0; $mult = 3;
    for ($i = 12; $i >= 0; $i--) { $sum += (int)$g14[$i] * $mult; $mult = $mult === 3 ? 1 : 3; }
    $dv = (10 - ($sum % 10)) % 10;
    return $dv === (int)$g14[13];
}

/** Lote/batch saneado p/ AI (10): charset GS1 alfanumérico, até 20. */
function vero_gs1_lote(string $lote): string
{
    // conjunto GS1 AI 82 (subconjunto seguro): A-Z a-z 0-9 e - . / espaço
    $l = preg_replace('/[^A-Za-z0-9\-.\/ ]+/', '', $lote) ?? '';
    return substr(trim($l), 0, 20);
}

/**
 * Monta a element string GS1 (para a barra) + a HRI (texto legível).
 * Ordem: (01) fixo, (3103) fixo, (10) variável por último.
 * @return array{raw:string,hri:string,gtin14:?string,peso6:?string,lote:string}
 */
function vero_gs1_element_string(?string $gtin14, ?float $pesoKg, string $lote): array
{
    $raw = VERO_GS1_FNC1;
    $hri = [];

    if ($gtin14 !== null) { $raw .= '01' . $gtin14; $hri[] = '(01)' . $gtin14; }

    $peso6 = null;
    if ($pesoKg !== null && $pesoKg > 0) {
        $milis = (int)round($pesoKg * 1000);
        if ($milis > 0 && $milis <= 999999) {
            $peso6 = str_pad((string)$milis, 6, '0', STR_PAD_LEFT);
            $raw  .= '3103' . $peso6;
            $hri[] = '(3103)' . $peso6;
        }
    }

    $lote = vero_gs1_lote($lote);
    if ($lote !== '') { $raw .= '10' . $lote; $hri[] = '(10)' . $lote; }

    return ['raw' => $raw, 'hri' => implode('  ', $hri), 'gtin14' => $gtin14, 'peso6' => $peso6, 'lote' => $lote];
}

/**
 * SVG inline (com viewBox → escala por CSS) da barra GS1-128 a partir da
 * element string crua (com FNC1). Use CSS no <svg> para dimensionar
 * (ex.: width:90mm;height:18mm) — a altura da barra não carrega informação.
 */
function vero_gs1_128_svg(string $raw, int $height = 30): string
{
    static $renderer = null;
    if ($renderer === null) {
        $renderer = new SvgRenderer();
        $renderer->setSvgType(SvgRenderer::TYPE_SVG_INLINE);
    }
    $barcode = (new TypeCode128())->getBarcode($raw);
    return $renderer->render($barcode, (float)$barcode->getWidth(), (float)$height);
}

/**
 * Code 128 "puro" (sem GS1/FNC1) — barra linear de um texto qualquer (ex.: o
 * código do crachá 'CRC-00001'). Mesmo renderizador; SVG inline escalável.
 */
function vero_code128_svg(string $data, int $height = 30): string
{
    return vero_gs1_128_svg($data, $height);
}
