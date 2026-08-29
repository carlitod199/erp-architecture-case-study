# VERO — ERP de Gestão Agrícola

Plataforma web de gestão para produção agrícola irrigada (fruticultura de uva e manga, entre outras culturas), com app de campo offline-first. Monólito PHP 8.3 próprio (sem framework), multi-tenant, com módulos de operação agrícola, MIP, nutrição, irrigação, colheita, packing, estoque, compras, comercial, financeiro, fiscal (NF-e/SEFAZ), custeio por safra, pessoas/RH, máquinas, patrimônio, pecuária e CRM.

## Tecnologias

- **Backend:** PHP 8.3 (PDO/MySQL, sem ORM, sem `public/` — roteamento por rewrite), MySQL 8, Redis (sessões em produção)
- **Frontend:** PHP server-rendered + JavaScript, ECharts, Leaflet (mapas)
- **Fiscal:** [sped-nfe](https://github.com/nfephp-org/sped-nfe) (NF-e / SEFAZ), certificado A1
- **IA:** assistente do app de campo e importação de laudos via APIs Anthropic (Claude) e OpenAI-compatível (Groq/OpenAI) — opcional
- **App de campo:** React Native + Expo (`vero_campo/`), offline-first (SQLite + fila idempotente)
- **Deploy:** Docker (PHP-FPM + nginx), ver `deploy/`

## Requisitos

- PHP 8.3+ com `pdo_mysql mysqli opcache intl zip gd soap bcmath mbstring curl`
- MySQL 8 (porta 3306 — o DSN é montado sem porta)
- Composer
- Node 18+ (apenas para o app de campo)

## Instalação

```bash
composer install
cp .env.example .env            # preencha DB_*, UNI_DB_* e (opcional) chaves de IA
cp config/fiscal_secrets.php.example config/fiscal_secrets.php   # só se for usar emissão fiscal
```

1. Crie o banco principal e o banco da Universidade (opcional) e aplique as migrações:
   - `database/migrations/*.sql` — rastreadas por checksum (`deploy/migrate.php`)
   - `migrations/migration_*.php` — série idempotente (roda uma vez)
   - `database/install_uni.sql` — schema/conteúdo do módulo Universidade
2. Aponte o document root do servidor web para a raiz do projeto (não há `public/`).
3. Acesse `index.php` e faça login.

## Configuração (variáveis de ambiente)

Todas as credenciais vivem **fora do código**, em `.env` (chaves `DB_*`, `UNI_DB_*`, `OPENAI_*`/`IA_BASE_URL`) ou como variáveis de ambiente reais do processo (`APP_ENV`, `BIOS_LOG_DIR`, `BIOS_CRYPTO_KEY`, `BIOS_SECRET_KEY`, `ANTHROPIC_API_KEY`, …). Veja `.env.example` — nenhum valor real é versionado.

- Senha do certificado A1 (SEFAZ): `config/fiscal_secrets.php` (gitignored; template `.example`)
- Certificados `.pfx`: `storage/certs/` (fora do git)

## Deploy em produção

Imagem Docker multi-stage, migração como job one-shot e volumes persistentes: ver `deploy/README_IMAGEM_PRODUCAO.md` e `deploy/DEPLOY_IMAGE_NOTES.md`.

## App de campo

```bash
cd vero_campo
npm install
npx expo start
```

O app monta a URL do backend a partir do código da empresa (`https://<codigo>.<seu-dominio>/api/v1`) — ajuste `SUFIXO_DOMINIO` em `src/services/ambiente.js`. Ver `vero_campo/README.md`.

## Testes

Bateria de QA HTTP em `tests/bateria/` (ver `GABARITO.md`). **Nunca** aponte a bateria para um banco de produção — ela cria e limpa massa de teste.

## Licença

MIT — ver [LICENSE](LICENSE).
