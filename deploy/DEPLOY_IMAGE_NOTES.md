# VERO — imagem de produção: job de migração + volumes

Complementa `deploy/Dockerfile`, `deploy/entrypoint.sh`, `deploy/php/*`, `deploy/migrate.php`.

## 1. Job de migração (usuário `vero_migrate`, DDL)

A app roda com `vero_app` (SEM DDL). A migração é um **container one-shot** que usa a
**MESMA imagem**, com `DB_USER=vero_migrate` + secret `mysql_migrate` (o `entrypoint`
renderiza o `.env`). `profiles: ["migrate"]` para não subir no `up`.

**Ordem do wrapper do job:**
```sh
# 1) SNAPSHOT ANTES (rollback de DDL ruim — a base está em ZFS):
zfs snapshot vpool/vero/mysql@pre-migracao
# 2) aplicar (migrate.php faz .sql E a série .php, rastreados, em ordem natural):
php /var/www/vero/deploy/migrate.php
# rollback, se preciso: zfs rollback vpool/vero/mysql@pre-migracao
```

`deploy/migrate.php`:
- **`database/migrations/*.sql`** — rastreadas por **checksum** em `schema_migrations`
  (igual ao `migrar.sh`, que NÃO existe aqui). Erro em QUALQUER statement do arquivo
  **aborta** (via `query()`+`nextRowset()`) e a migração **não é gravada** como aplicada.
- **`migrations/migration_NNN.php`** (série ad-hoc do packing) — agora **rastreadas por
  nome** (chave `php:<arquivo>`), então **rodam uma vez** e depois são puladas (resolve o
  "roda inteira todo deploy"). São idempotentes (checam `information_schema`).
- **Ordem natural** (`strnatcmp`) nos dois — sem o problema de `migration_10` vir antes de `migration_2`.
- **Exit codes:** `0` ok · `1` erro ao aplicar · `2` não obteve o `GET_LOCK` (outro job) · `3`
  checksum divergente (arquivo mudou após aplicado — o pipeline lê como FALHA, não sucesso).
- **1º boot com dump restaurado:** na primeira execução a série `.php` re-roda uma vez
  (idempotente → imprime "já existe") e passa a ser rastreada. Se quiser pular esse re-run,
  pré-popule `schema_migrations` com as chaves `php:migration_*.php` já aplicadas.

## 2. Volumes / mounts

| Caminho | Tipo | Persistência | Conteúdo / nota |
|---|---|---|---|
| `/var/www/vero/storage/uploads` | volume | **persistente** | XML NF-e + PDFs (fiscal), laudos, anexos de vendas/mip/RT/treinamentos |
| `/var/www/vero/storage_private` | **dataset ZFS** (`/vpool/vero/storage-private`) | **persistente** | `app_anexos/` + `ia_clima_cache.json`. **Dataset, não volume nomeado** — entra no backup `zfs send` da Fase 5. Fazer `zfs create` antes do 1º up. |
| `/var/www/vero/storage/certs` | volume/secret **RO** | **persistente** | certificado A1 `.pfx` (só quando a emissão for ligada) |
| `/var/www/vero/config/fiscal_secrets.php` | secret (arquivo) **RO** | — | senha do cert por tenant |
| `/run/php` | volume **compartilhado** php↔nginx | efêmero | socket `vero.sock` |
| `/tmp` | **tmpfs, teto 256M** | efêmero (RAM) | cache de clima + **PEMs temporários do sped** (em RAM: não vão a disco, sem apagamento seguro). Teto evita OOM por request. |
| sessão | **Redis db 1** | — | não é volume (trava 4) |
| logs | **stderr** | — | não é volume (trava 3) |

**`/tmp` precisa estar gravável** (está no `open_basedir`) — senão a SEFAZ quebra.
