# VERO — imagem de produção (índice)

Guia único para construir e subir a **imagem da aplicação** VERO na VPS nova
(`sua-vps`, stack MySQL 8 / Redis 7.2 / nginx 1.27 / PHP 8.3-FPM). Reúne os
artefatos e as decisões; os detalhes ficam nos arquivos citados.

> **VERO NÃO é framework** (Laravel/Symfony). É um monólito PHP 8.3 próprio, PDO,
> sem ORM, **sem `public/`**, roteamento por rewrite `@extensionless` do nginx, e
> **não gera PHP em runtime** (por isso `opcache.validate_timestamps=0` é seguro).

## Artefatos (todos em `deploy/`, exceto onde indicado)
| Arquivo | O quê |
|---|---|
| `Dockerfile` | imagem multi-stage (composer `--no-dev` + autoload otimizado; user 10001) |
| `../.dockerignore` | **barra `.env`, `config/fiscal_secrets.php`, `vendor`, junk** (não vaza segredo) |
| `php/zz-vero.ini` | php.ini: hardening + opcache + upload 32M/40M + `session.save_handler=redis` |
| `php/vero-fpm.conf` | pool FPM: **socket unix** `/run/php/vero.sock`, user 10001, logs no stderr |
| `entrypoint.sh` | materializa `/run/secrets/*` → `.env` + session-Redis + env vars |
| `migrate.php` | runner das `database/migrations/*.sql` (checksum) — substitui o `migrar.sh` |
| `DEPLOY_IMAGE_NOTES.md` | job de migração + tabela de volumes |
| `../composer.json` `../composer.lock` `../.env.example` | deps + config de exemplo |

## Passo a passo

### 1. Extensões PHP (contrato)
Base `php:8.3-fpm` + `pdo_mysql mysqli opcache intl zip gd soap bcmath redis` **+ `mbstring` + `curl`**
(os dois últimos faltavam na lista e são obrigatórios — `mbstring` pervasivo + sped-nfe; `curl` = SEFAZ/IA).
O `Dockerfile` já instala tudo; se sua base pinada traz, troque o `FROM` e remova o bloco.

### 2. Build
```sh
docker build -f deploy/Dockerfile -t vero-app:<tag> .    # a partir da RAIZ do repo
```
Doc root = **`/var/www/vero`** (raiz, sem `public/`). O código vem no artefato/tarball
(`vero_deploy_*.zip`) — **sem git remote**.

### 3. Secrets (arquivos em `/run/secrets/`)
| Secret | Uso | Já existe? |
|---|---|---|
| `mysql_app` | senha do `vero_app` (runtime, SEM DDL) | sim |
| `mysql_migrate` | senha do `vero_migrate` (só no job de migração, DDL) | **CRIAR** |
| `redis_password` | auth do Redis (sessão db 1) | sim |
| `openai_api_key` | Groq (IA do app de campo) | **CRIAR** |
| `anthropic_api_key` | opcional (importação de laudos) | **CRIAR (se usar)** |
O `entrypoint.sh` lê `mysql_app`/`redis_password` de arquivo; as chaves de IA, passe como
env ou descomente as linhas de `/run/secrets/*` no entrypoint.

### 4. Configuração (env vars REAIS do container)
`DB_*`, `UNI_DB_*`, `OPENAI_*`/`IA_BASE_URL` vão para o **`.env`** (o entrypoint renderiza).
Estas o app lê **só via getenv/$_ENV** → passe como env var:
`APP_ENV=production`, `BIOS_LOG_STDERR=1`, `BIOS_CURL_CAINFO=/etc/ssl/certs/ca-certificates.crt`,
`IA_AGENTE_BASE_URL=…`. (Detalhes em `../.env.example`.) DSN é **sem porta** → MySQL na **3306**.

### 5. Sessão = Redis database 1
`session.save_handler=redis` (no `zz-vero.ini`); o `save_path` com `auth=<redis_password>&database=1`
é escrito pelo entrypoint em runtime. **Sem mudança de código** — VERO herda do php.ini.

### 6. Volumes (ver `DEPLOY_IMAGE_NOTES.md`)
Persistentes: `storage/uploads`, `storage_private`, `storage/certs`. Compartilhado php↔nginx:
`/run/php` (socket). Efêmero e **gravável**: `/tmp` (o sped grava PEMs temporários por chamada).
Logs = stderr, sessão = Redis (nenhum dos dois é volume).

### 7. nginx (o agente de infra escreve, base = `deploy/nginx_vero.conf`)
Doc root `/var/www/vero`; **replicar** o `location @extensionless` (executa `$uri.php`), a
canonical `.php`, e os `deny` de `^~ /includes/`, `/config/`, `/database/` (não há `public/`
isolando). Trocar `fastcgi_pass` para `unix:/run/php/vero.sock`. Ajustar o `listen.owner/group`
do FPM (`vero-fpm.conf`) para o usuário do nginx.

### 8. Migração (job one-shot, usuário `vero_migrate`)
Container com a **mesma imagem** + `DB_USER=vero_migrate` + secret `mysql_migrate`:
```sh
php /var/www/vero/deploy/migrate.php                                   # .sql (checksum)
for m in $(ls /var/www/vero/migrations/migration_*.php | sort -V); do php "$m"; done   # série ad-hoc (idempotente)
```
O runtime segue com `vero_app`. 1º boot: restaure o **dump** (produção) e o job só aplica o pendente.

### 9. Healthcheck
VERO **não tem** endpoint DB-free → use o `/healthz` do nginx. Para checar o banco:
`php /var/www/vero/scripts/db_ping.php` (imprime OK/erro).

### 10. Smoke test (autoridade)
`curl -s -o /dev/null -w '%{http_code}' https://<host>/ → 200`. Depois, logado: Novo Apontamento
(Iniciar→Finalizar/calculadora), Aplicação/DF, Monitoramento multialvo, Produtos, e perfis
restritos **sem 403**.

### 11. NF-e (sped-nfe 5.2.6) — passa no hardening
Assina via **extensão `openssl`** (não CLI) e fala com a SEFAZ via **cURL** — **zero** `proc_open`/
`shell_exec`/`file_get_contents`-URL. `disable_functions` e `allow_url_fopen=Off` **não quebram**.
A emissão está **bloqueada até chegar o certificado A1**; quando chegar, montar `storage/certs/*.pfx`
+ `config/fiscal_secrets.php` (senha por tenant). Timeout SEFAZ ~40s (< 110s do `max_execution_time`).
Teste seguro em homolog: `php scripts/fiscal_prova_vida.php` (read-only, `sefazStatus`).

## Não usar / não precisa
- **Sem workers, sem cron, sem WebSocket/SSE** — só PHP-FPM servindo requests.
- **Sem build de assets** (não há `package.json` no ERP).
- **Sem migração no boot** — é sempre o job separado.
- **Tenant = sessão** (coluna `tenant_id`; base única). Sem subdomínio→tenant, sem CNAME.
