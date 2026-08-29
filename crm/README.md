# VERO CRM — sistemas independentes (fase protótipo)

Três sistemas **SEPARADOS do ERP VERO** (o ERP não mostra nada do CRM e o CRM não
mostra o menu do ERP):

- **VERO CRM · Revenda de Insumos** — `crm/revenda/*.php` → rotas `/crm/revenda/...`
- **VERO CRM · Corretor de Frutas** — `crm/corretor/*.php` → rotas `/crm/corretor/...`
- **VERO CRM · Consultor de Frutas** — `crm/consultor/*.php` → rotas `/crm/consultor/...`
  Login próprio em `/crm/consultor/login`, mesma permissão `crm.demo.ver`.

Cada sistema tem **tela de login PRÓPRIA** (`crm/{modulo}/login`), mas a autenticação é a
do VERO (o form posta para `/index`; `auth.php` honra `BIOS_LOGIN_URL` para devolver o
não-logado à tela de login do sistema). Acesso: permissão `crm.demo.ver` (migration 209)
ou super_admin.

**Menu = o shell do VERO com menu PLANO**: o shell real do ERP
(`agro_header.php` + `sidebar.php`) é reutilizado com uma **matriz de menu própria por
sistema** (`crm_menu_matriz()` em `_lib.php`, injetada via `$GLOBALS['BIOS_MENU_OVERRIDE']`
/`BIOS_MENU_SECOES_OVERRIDE` + `BIOS_MENU_FLAT` antes do header). Todas as telas ficam na
coluna principal, **sem trilho de submenu**; os grupos viram cabeçalhos de seção
(`crm_menu_secoes()`). O ERP continua sem nenhum vestígio do CRM.

**Telas atuais**:

- **Revenda** — dashboard, meu-dia, clientes (+ detalhe `cliente`), pipeline (+ detalhe
  `oportunidade`), oportunidades, agenda, mapa, roi, comparativo, concorrencia, produtos,
  pedidos. Seções: Comercial, Relacionamento, Campo, Inteligência, Comercial (2ª).
- **Corretor** — dashboard, ceasa, carregamentos, financeiro, mao-de-obra, clima,
  mercados, config. Seções: Operação · Frutas, Gestão, Relacionamento.
- **Consultor** — dashboard (painel), meu-dia, acoes, produtores (+detalhe `produtor`),
  propriedades (+`propriedade`), talhoes, visitas (+`visita`), agenda, recomendacoes,
  analises (+`analise`), rota, oportunidades, pipeline (+`oportunidade`), propostas,
  dre (+`dre-cliente`), radar (+`automacoes`), indicadores (+`ind-campo`,
  `ind-carteira`), mobile, config. Seções: Meu dia, Carteira, Campo, Comercial,
  Inteligência, Gestão.

**Visual: tema CLARO padrão do VERO** — tokens de `assets/css/agro.css` (fundo `#EDEAE0`,
cards brancos, teal `#005059`, IBM Plex Sans/Mono). **Não** usar tema dark nem hex fora
dos tokens.

Fase atual: protótipo de alta fidelidade, apresentável — navegação real, dados 100% mock
(`_mock.php`), interações client-side. Sem backend/persistência.

## Como criar/editar uma tela

```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_lib.php';

$M = crm_mock();                       // TODOS os dados vêm daqui — nada inline

crm_shell_start([
    'modulo' => 'revenda',             // ou 'corretor'
    'micro'  => 'clientes',            // item ativo do menu (ver crm_definicao_menu em _lib.php)
    'titulo' => 'Clientes',
    'acoes'  => '<a class="vbtn vbtn-primary" href="...">＋ Novo</a>',  // opcional (máx. 1 primário)
    'demo'   => 'integração X',        // opcional: selo DEMONSTRATIVO no título
]);
?>
... conteúdo (componentes abaixo) ...
<?php crm_shell_end();
```

O exemplar é `revenda/dashboard.php`. O shell é o do VERO (header+sidebar reais); o
conteúdo vive em `.vwrap.crm-app`. O topbar é o **canônico do VERO** (`.vero-topbar`):
título + no máximo 1 botão primário — sem subtítulo (`sub` é ignorado), sem alternador
de papel, sem seletor de fazenda, sem selos de IA. Telas de detalhe (`cliente`,
`oportunidade`) realçam o item pai no menu via `crm_localiza()`. Tela nova = criar o
arquivo + registrar o item em `crm_definicao_menu()`.

## Componentes (helpers PHP de `_lib.php`)

| Helper | O quê |
|---|---|
| `crm_kpi($rotulo,$valor,$rodape,$cor,$icone)` | KPI card com faixa lateral (`teal green amber red blue violet`) |
| `crm_pill($txt,$cor)` / `crm_status_pill()` / `crm_risco_pill()` | pílulas de status |
| `crm_demo($txt)` | selo `DEMONSTRATIVO` — usar onde o dado viria de integração (ERP/CEASA/clima/IA) |
| `crm_callout($html,$cor,$icone)` | caixa contextual (`teal amber red green`) |
| `crm_trend($pct)` | ▲/▼/— colorido |
| `crm_avatar($nome,$cor,$tam)` | avatar de iniciais (`$tam='g'` p/ grande) |
| `crm_bar($pct,$cor)` | barra de proporção |
| `crm_kv($k,$v)` | linha rótulo→valor |
| `crm_url($modulo,$rota)` | URL limpa de tela do CRM |
| `crm_brl($v)` / `crm_num($v)` | formatação pt-BR |

Classes CSS (ver `assets/css/crm.css`, escopo `.crm-app`): grids `crm-g2/g3/g4/g23/g12`,
`crm-card` (+`__head`,`__title`), tabela `crm-tblwrap > crm-tbl` (th mono; `.num` à direita;
`tr.tap` clicável), `crm-chips/crm-chip(.on)`, `crm-tabs/crm-tab(.on)`, kanban
(`crm-kanban/crm-col/crm-deal` — via JS), timeline (`crm-tl/*`), mapa fake
(`crm-map/crm-pin/pin-{cor}/*`), clima (`crm-clima/crm-dia`), agenda (`crm-ag/*`),
`crm-hbars/crm-hbar`, `crm-crumb`, `crm-empty`.

**Cores em estilo inline**: use SEMPRE `var(--crm-*)` (mapeadas nos tokens claros do VERO
dentro de `.crm-app`) — nunca hex do mockup dark (`#18C4AB`, `#0A1310`, `rgba(24,196,171,…)`)
nem texto branco sobre fundo claro.

## Interações (assets/js/crm.js — carregado pelo shell)

- **Navegação por linha/card**: `data-href="URL"` → clique navega.
- **Toast mock**: `data-toast="Mensagem"`; dentro de `.vmodal`, fecha o modal antes. Programático: `crmToast('msg')`.
- **Modais**: modal canônico do VERO — `<div class="vmodal" id="vm-x"><div class="vbox">…`,
  abre com `vModalOpen('vm-x')`, confirma com `data-toast`. Esc/backdrop fecham.
- **Chips/tabs**: `.crm-chip` alterna `.on`; `.crm-tab` single-select. Visual apenas.
- **Kanban**: `crmKanbanInit('id', OPPS, ETAPAS, CLIENTES, urlDetalhe)` (dados via `jsvar()`).
- **Detalhe de oportunidade**: `crmOppInit({etapa, etapas})` + `crmOppMove(±1)` (`#crm-funil` etc.).
- **ROI**: `crmRoiCalc()` auto-liga nos ids `roiArea…roiProdSel` e escreve em `rInv…rFrase`.

Gráficos: barras/donut/anel em CSS/SVG inline — sem lib externa. Detalhe: padrão `?id=`.

## Regras

1. Dados SEMPRE de `crm_mock()`; faltou algo → adicionar em `_mock.php` com o cenário coerente.
2. Selo `crm_demo()` em tudo que viria de integração (ERP, CEASA, clima, IA, recomendação técnica).
3. Sistemas não se misturam: Revenda nunca linka Corretor e vice-versa.
4. Responsivo: grids `crm-g*` colapsam 4→2→1; tabelas largas dentro de `.crm-tblwrap`.
5. Sem POST/banco nas telas. Ações "salvar" = modal + `data-toast`.

