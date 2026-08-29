# T7 — Capturas automáticas (Playwright)

Pipeline **T7** da Universidade VERO (§12 do documento de pipelines): tira
screenshots das telas ancoradas às cápsulas publicadas, mascara dados
sensíveis, detecta mudança visual e aplica o resultado no banco da
Universidade (cria/atualiza `uni_ativo` do tipo `imagem` e, se a tela mudou,
manda a cápsula para `status='revisao'` — o mesmo gatilho do T8).

```
scripts/capturas/
├── package.json        # deps (playwright), ESM
├── capturas.mjs        # o pipeline (login → navega → mascara → screenshot → diff)
├── rotas.json          # manifesto {slug, titulo, rota} gerado do banco
├── saida/              # capturas da rodada atual (esvaziado ao promover)
├── anterior/           # capturas da rodada anterior (base do diff)
└── resultado.json      # saída: [{slug, rota, arquivo, sha256, mudou}]
```

O aplicador no banco fica em `scripts/uni_capturas_aplicar.php` (um nível acima).

---

## Pré-requisitos

- **Node** v18+ (testado com v24) no PATH.
- **App local** servindo em `http://localhost/vero/` (WAMP).
- Playwright + Chromium instalados (ver abaixo — não vêm no repo).

## Instalação (uma vez)

```bash
cd scripts/capturas
npm install                 # instala o pacote playwright
npx playwright install chromium
```

> O download do Chromium é pesado (~150 MB). Se falhar por rede/proxy, é o
> único passo que trava o pipeline — o resto do script já está pronto.

## 1) Gerar o manifesto de rotas

O `rotas.json` sai direto do banco **separado** da Universidade (rota
principal de cada cápsula publicada):

```bash
# a partir da raiz do projeto (.)
php -r "require './includes/uni_db.php'; echo json_encode(uni_pdo()->query(\"SELECT c.slug,c.titulo,r.rota FROM uni_capsula c JOIN uni_capsula_rota r ON r.capsula_id=c.id WHERE r.principal=1 AND c.status='publicado'\")->fetchAll(PDO::FETCH_ASSOC));" > scripts/capturas/rotas.json
```

## 2) Rodar as capturas

```bash
cd scripts/capturas
node capturas.mjs
```

Variáveis de ambiente (todas opcionais):

| Var              | Default                     | O que faz                                    |
|------------------|-----------------------------|----------------------------------------------|
| `BASE_URL`       | `http://localhost/vero`     | base do app (sem barra final)                |
| `UNI_EMAIL`      | `escola@vero.local`         | login (perfil gestor, tenant 20 demo)        |
| `UNI_SENHA`      | `(defina via env)`           | senha do login                               |
| `UNI_VW`/`UNI_VH`| `1440` / `900`              | viewport da captura                          |
| `UNI_CAP_LIMIAR` | `0.02`                      | limiar (fração de bytes) p/ marcar `mudou`   |
| `UNI_TIMEOUT`    | `30000`                     | timeout de navegação (ms)                    |
| `UNI_HEADFUL`    | (vazio)                     | qualquer valor abre o browser visível        |

Exemplo com o Super Admin:

```bash
UNI_EMAIL=super@admin UNI_SENHA=... node capturas.mjs
```

## 3) Aplicar no banco da Universidade

```bash
# raiz do projeto
php scripts/uni_capturas_aplicar.php          # aplica
php scripts/uni_capturas_aplicar.php --dry    # simula, não grava
```

O aplicador é **idempotente**: rodar de novo com o mesmo `resultado.json` não
duplica `uni_ativo` nem re-marca cápsula. Toda a aplicação roda em uma
transação (rollback em erro).

---

## Como funciona o diff (`mudou`)

1. A captura da rodada vai para `saida/{slug}.png`.
2. Compara-se com `anterior/{slug}.png` (rodada passada):
   - não existe anterior → `mudou:true` (novidade);
   - `sha256` igual → `mudou:false`;
   - `sha256` diferente → `mudou:true` **somente** se a variação de bytes
     for ≥ `UNI_CAP_LIMIAR` (amortece ruído pixel-a-pixel de telas dinâmicas).
3. Ao fim, `saida/` é **promovido** para `anterior/` (base da próxima rodada).

Para forçar tudo como novo, apague a pasta `anterior/`.

---

## Mascaramento de dados sensíveis (heurística)

O screenshot é tirado **depois** de mascarar (blur) prováveis dados pessoais
e financeiros. Como as telas ainda **não têm atributos de teste estáveis**, a
seleção é heurística (`maskInPage` em `capturas.mjs`):

1. **Colunas de tabela** cujo cabeçalho (`th`) contém termos sensíveis:
   `responsável`, `colaborador`, `nome`, `cliente`, `fornecedor`, `cpf`,
   `cnpj`, `e-mail`, `telefone`, `contato` — mascara as células (`td`)
   daquela coluna.
2. **Textos** que casam padrões de valor monetário (`R$ …`), **CPF**
   (`000.000.000-00`) ou **CNPJ** (`00.000.000/0000-00`), em elementos-folha.
3. Respeita atributos de opt-in/opt-out: `[data-uni-mask]` sempre borra;
   `[data-uni-nomask]` nunca borra.

### Limitações conhecidas (importante)

- É **heurística**, não garantia: nomes fora de tabela, valores sem `R$`,
  ou colunas com cabeçalhos diferentes **podem escapar**. Revise as imagens
  antes de publicar as cápsulas externamente.
- Pode haver **falso-positivo** (borrar algo não sensível) — aceitável, pois
  o custo de vazar é maior que o de borrar demais.

### Pedido ao time de front (torna isto robusto)

Adicionem `data-uni-mask` nos elementos que sempre devem ser borrados na
captura (nomes de pessoas, CPF/CNPJ, valores) e `data-uni-nomask` nos que
nunca devem (rótulos, cabeçalhos). Com isso o pipeline deixa de depender de
heurística e passa a mascarar de forma determinística.

---

## Agendamento

Rodar a cada release (ou no cron do CI), em sequência:

```bash
# 1. atualiza o manifesto (rotas podem ter mudado)
php -r "..."  > scripts/capturas/rotas.json     # ver passo 1
# 2. captura
cd scripts/capturas && node capturas.mjs && cd ../..
# 3. aplica no banco
php scripts/uni_capturas_aplicar.php
```
