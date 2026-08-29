<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/vero_qr.php
   Helper de QR Code para o Packing House (crachá do colhedor/embalador).
   Gera SVG INLINE 100% offline via chillerlan/php-qrcode (pure-PHP, sem GD,
   sem chamada de rede — o código do crachá nunca sai da máquina). O SVG
   escala por CSS e imprime nítido. O conteúdo do QR é o próprio código do
   crachá (ex.: 'CRC-00001'); um leitor de QR digita esse texto no campo do
   apontamento, resolvendo a pessoa em vero_srv_cracha_resolver().
   ============================================================ */

require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * SVG inline (markup <svg>…</svg>) de um QR que codifica $data.
 * Reaproveita uma instância única (a lista de crachás chama N vezes).
 * ECC nível M (equilíbrio densidade × tolerância a sujeira/impressão).
 */
function vero_qr_svg(string $data): string
{
    static $qr = null;
    if ($qr === null) {
        $qr = new QRCode(new QROptions([
            'version'       => QRCode::VERSION_AUTO,
            'eccLevel'      => QRCode::ECC_M,
            'outputType'    => QRCode::OUTPUT_MARKUP_SVG,
            'imageBase64'   => false,   // markup <svg> cru p/ embutir (não data URI)
            'quietzoneSize' => 2,
            'cssClass'      => 'vqr-svg',
            'markupDark'    => '#101828',
            'markupLight'   => '#ffffff',
        ]));
    }
    return $qr->render($data);
}
