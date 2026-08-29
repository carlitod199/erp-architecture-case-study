<?php
/* ============================================================
   VERO — Configurações / Integrações  (tela real, leitura)
   Substitui o mock. Rota: /configuracoes/integracoes.php
   Guard: configuracoes.integracoes
   Status real das integrações externas — sem expor chaves.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

$iaChave = (bool)getenv('ANTHROPIC_API_KEY');
$iaExtracoes = (int)vero_val("SELECT COUNT(*) FROM agro_ia_extracoes WHERE tenant_id = :t", [':t' => $t]);
$iaMes = (int)vero_val("SELECT COUNT(*) FROM agro_ia_extracoes
    WHERE tenant_id = :t AND DATE_FORMAT(created_at,'%Y-%m') = :m", [':t' => $t, ':m' => date('Y-m')]);

$geoTalhoes = (int)vero_val("SELECT COUNT(*) FROM agro_talhoes WHERE tenant_id = :t AND geometria IS NOT NULL", [':t' => $t]);

$integracoes = [
    [
        'nome' => 'IA — Importação de laudo (Anthropic)',
        'status' => $iaChave ? 'ativa' : 'pendente',
        'detalhe' => $iaChave
            ? "Chave configurada · {$iaExtracoes} extração(ões) no total · {$iaMes} neste mês · teto mensal R$ 600 com revisão humana obrigatória (D6)"
            : 'ANTHROPIC_API_KEY não configurada no .env — a tela de importação degrada para digitação manual',
        'rota' => '/nutricao/importar_laudo', 'acao' => 'Abrir importação',
    ],
    [
        'nome' => 'IA — Assistente VERO Campo (Anthropic)',
        'status' => $iaChave ? 'ativa' : 'pendente',
        'detalhe' => $iaChave
            ? 'Chat do app usa a API da Anthropic (Claude) com a mesma ANTHROPIC_API_KEY · voz (transcrição) segue no provedor OpenAI-compatível (OPENAI_API_KEY)'
            : 'ANTHROPIC_API_KEY não configurada no .env — o chat do app responde "assistente indisponível" até configurar',
        'rota' => null, 'acao' => null,
    ],
    [
        'nome' => 'Mapa — Leaflet + Esri World Imagery (CDN)',
        'status' => 'ativa',
        'detalhe' => "Bibliotecas via CDN unpkg (exige internet no navegador) · {$geoTalhoes} talhão(ões) com polígono desenhado",
        'rota' => '/agro/mapa', 'acao' => 'Abrir mapa',
    ],
    [
        'nome' => 'Fiscal — NF-e/NFS-e/MDF-e',
        'status' => 'fora do go-live',
        'detalhe' => 'Decisão travada D7: módulo Fiscal fora do go-live; schema pronto para fase futura',
        'rota' => null, 'acao' => null,
    ],
    [
        'nome' => 'Extrato bancário (OFX)',
        'status' => 'fase 2',
        'detalhe' => 'Conciliação atual é manual (saldo do extrato × razão) — import automático de OFX em fase 2',
        'rota' => '/financeiro/conciliacao_bancaria', 'acao' => 'Conciliação',
    ],
];

$badge = static fn(string $s): string => match ($s) {
    'ativa'           => '<span class="vbadge vb-ok">Ativa</span>',
    'pendente'        => '<span class="vbadge vb-off">Pendente</span>',
    'fora do go-live' => '<span class="vbadge vb-off">Fora do go-live</span>',
    default           => '<span class="vbadge vb-warn">Fase 2</span>',
};

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'integracoes'];
$PAGE_VIEW  = 'configuracoes_integracoes';
$PAGE_TITLE = 'Integrações';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Integrações', 'Status real das integrações externas — chaves nunca são exibidas', null) ?>

  <div class="vcard">
    <table class="vtable">
      <thead><tr><th>Integração</th><th>Status</th><th>Detalhe</th><th style="text-align:right"></th></tr></thead>
      <tbody>
      <?php foreach ($integracoes as $i): ?>
        <tr>
          <td><strong><?= h($i['nome']) ?></strong></td>
          <td><?= $badge((string)$i['status']) ?></td>
          <td class="vhint"><?= h($i['detalhe']) ?></td>
          <td style="text-align:right">
            <?php if ($i['rota']): ?>
              <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base . $i['rota'] ?>"><?= h((string)$i['acao']) ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Chaves de API ficam no .env do servidor e nunca aparecem no sistema — aqui só o status de configuração.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
