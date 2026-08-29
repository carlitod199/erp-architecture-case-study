<?php
declare(strict_types=1);
/* ============================================================
   VERO — FUNRURAL: regime SELECIONÁVEL POR SAFRA (A3, P-117)
   A P-117 mudou (superou "só folha"): modelar AMBOS os regimes e
   deixar o cliente escolher POR SAFRA.
     • folha    — 20%% INSS patronal + RAT + SENAR SOBRE A FOLHA
                  (é encargo patronal → entra no custo de MDO da safra).
     • receita  — contribuição SUBSTITUTIVA ~1,5%% SOBRE A RECEITA da
                  comercialização (→ vira DESPESA DE VENDA / F7 no
                  resultado; NÃO onera a folha daquela safra).
   É OPÇÃO FISCAL do produtor (Lei 13.606) e pode MUDAR entre safras —
   por isso a decisão é por safra, com um default do tenant.

   Armazenado em tenant_parametros.chave='folha.funrural_regime' (chave-
   valor, SEM migration — mesmo precedente de resultado.descontos/T30):
     { "default":"folha", "safras": { "<safra_id>":"receita", ... } }

   ESTE ARQUIVO É A FONTE ÚNICA da decisão de regime. Consumidores
   (ONDA 4, quando liberada):
     (1) FOLHA→custeio MDO: safra em 'receita' NÃO lança o INSS patronal
         substituído no MDO (só FGTS/férias/13º/RAT-SENAR conforme regra);
     (2) VENDA/resultado: safra em 'receita' gera a despesa FUNRURAL
         = FUNRURAL_ALIQUOTA_RECEITA%% × receita da comercialização (F7).
   ============================================================ */

const FUNRURAL_REGIMES = [
    'folha'   => 'Folha — 20% patronal + RAT + SENAR sobre a folha',
    'receita' => 'Receita — substitutiva ~1,5% sobre a comercialização',
];
const FUNRURAL_REGIME_DEFAULT   = 'folha'; /* base P-117 (cliente+contador) */
const FUNRURAL_ALIQUOTA_RECEITA = 1.5;     /* %% — seed; refina com o contador (liga P-90/venda) */

/** Config bruta do parâmetro (array vazio se ausente). */
function funrural_config(): array
{
    $j = vero_val("SELECT valor FROM tenant_parametros WHERE tenant_id = :t AND chave = 'folha.funrural_regime'",
        [':t' => vero_tenant()]);
    $c = $j ? json_decode((string)$j, true) : [];
    return is_array($c) ? $c : [];
}

/** Default do tenant (folha|receita). */
function funrural_default(): string
{
    $d = (string)(funrural_config()['default'] ?? FUNRURAL_REGIME_DEFAULT);
    return isset(FUNRURAL_REGIMES[$d]) ? $d : FUNRURAL_REGIME_DEFAULT;
}

/** Regime efetivo de uma safra: override da safra, senão o default do tenant. */
function funrural_regime_safra(int $safraId): string
{
    $c = funrural_config();
    $r = (string)($c['safras'][(string)$safraId] ?? funrural_default());
    return isset(FUNRURAL_REGIMES[$r]) ? $r : FUNRURAL_REGIME_DEFAULT;
}

/** Grava o default + os overrides por safra (valida contra FUNRURAL_REGIMES). */
function funrural_salvar(string $default, array $porSafra): void
{
    if (!isset(FUNRURAL_REGIMES[$default])) $default = FUNRURAL_REGIME_DEFAULT;
    $safras = [];
    foreach ($porSafra as $sid => $reg) {
        $sid = (int)$sid;
        $reg = (string)$reg;
        /* só guarda override que difere do default e é válido — mantém o JSON enxuto */
        if ($sid > 0 && isset(FUNRURAL_REGIMES[$reg]) && $reg !== $default) {
            $safras[(string)$sid] = $reg;
        }
    }
    $val = json_encode(['default' => $default, 'safras' => $safras], JSON_UNESCAPED_UNICODE);
    $id  = vero_val("SELECT id FROM tenant_parametros WHERE tenant_id = :t AND chave = 'folha.funrural_regime'",
        [':t' => vero_tenant()]);
    if ($id) {
        vero_update('tenant_parametros', (int)$id, ['valor' => $val]);
    } else {
        vero_insert('tenant_parametros', [
            'chave' => 'folha.funrural_regime', 'valor' => $val,
            'descricao' => 'Regime de FUNRURAL por safra: folha | receita',
        ]);
    }
}
