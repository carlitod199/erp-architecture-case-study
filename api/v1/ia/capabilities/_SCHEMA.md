# Contrato do Manifesto de Capacidades (Agente Operacional VERO)

Cada capacidade que a IA pode executar é um arquivo `.json` neste diretório.
O agente (`ia_agente.php`) carrega TODOS os arquivos daqui e os expõe como
*tools* de function calling. **A IA nunca monta SQL** — ela só chama capacidades
registradas, e cada uma aponta para um handler que JÁ existe (rota do app em
`escrita.php`/`sync.php` OU serviço `vero_srv_*` do web), herdando RBAC + tenant
+ regra de negócio + idempotência.

## Schema

```jsonc
{
  "id": "modulo.acao",                 // único, namespaced por módulo (kebab/dot)
  "titulo": "Frase curta do que faz",
  "modulo": "irrigacao",               // agro|mip|irrigacao|maquinas|estoque|compras|financeiro|rh|...
  "permissao": "slug.real.editar",     // slug EXATO do catálogo (permissions/menu_agro)
  "tipo": "escrita",                   // leitura | escrita | destrutiva
  "confirmar": true,                   // escrita/destrutiva => true (gate de confirmação)
  "handler": {                         // COMO executar — reuso do que existe
    "metodo": "POST",                  // GET|POST
    "rota": "/irrigacao/apontamentos", // rota da API do app (preferir) …
    "funcao": null                     // …OU nome de vero_srv_*/handler PHP a chamar direto
  },
  "params": {                          // contrato dos argumentos
    "talhao_id": {
      "tipo": "int",                   // int|decimal|texto|data|hora|bool|lista|enum
      "obrigatorio": true,
      "resolver": "valvula",           // como virar id: valvula|alvo|produto|operador|maquina|null
      "desc": "válvula/setor onde ocorreu",
      "default": null,                 // valor padrão (ex.: "hoje" p/ data)
      "enum": null,                    // valores válidos p/ tipo enum
      "de_contexto": true              // pode ser preenchido do retrato do tenant/preferências
    }
  },
  "resumo": "Irrigação: {horas} h na válvula {talhao_id}",  // template do cartão de confirmação
  "regras": ["texto curto p/ o usuário", "…"],              // regras que o handler aplica
  "inverso": "irrigacao.estornar"      // id da capacidade de rollback, se houver (null caso irreversível)
}
```

## Preenchimento pelo agente (slot-filling)
- `obrigatorio:true` + sem valor no contexto/preferências ⇒ **pergunta ao usuário** (agrupando quando fizer sentido).
- `de_contexto:true` ⇒ tenta preencher do `ia_contexto()` (retrato do tenant) ou de `ia_preferencias`.
- `resolver` ⇒ transforma linguagem natural em id ("5A"→válvula #, "cochonilha"→alvo #). Ambíguo ⇒ oferece opções.

## Confirmação e auditoria
- `tipo` ∈ {escrita, destrutiva} ⇒ o **gate estrutural** mostra `resumo` e exige "confirmar" antes de executar. `destrutiva` também é bloqueada por voz (exige toque).
- Toda execução grava trilha em `ia_acoes` (hash-chain) com capability + params + resultado.

## Fontes de verdade para gerar capacidades
1. `api/v1/index.php` — tabela de rotas (ações já expostas ao app).
2. `includes/permissions.php` / `includes/menu_agro.php` — slugs `.editar/.excluir/.ver`.
3. `includes/vero_services.php` — `vero_srv_*` (processos do web ainda sem rota de app).
