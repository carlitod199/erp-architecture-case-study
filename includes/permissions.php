<?php
declare(strict_types=1);

/* ============================================================
   VERO — includes/permissions.php
   GERADOR ÚNICO do catálogo de permissões (unificado no A0-04,
   04/07/2026 — antes havia dois geradores divergentes: este só
   emitia .ver e configuracoes/permissoes.php emitia
   ver/editar/excluir; agora a tela consome perm_catalogo_matriz()
   daqui). Padrão por micro: {base}.{micro}.ver/.editar/.excluir;
   por macro: {base}.ver. Fonte: matriz central de menu_agro.php.
   Catálogo/perfis LEGADOS do VERO clube (admin.*, acesso.*,
   esportes.*, estrategico.*, config.*, club_admin, receptionist
   etc.) foram REMOVIDOS no A0-04 — nenhum usuário/role do banco
   os utiliza (verificado: usuarios.perfil só tem super_admin;
   roles = super_admin/gestor/operador/financeiro/visualizador).
   ============================================================ */

/**
 * Catálogo agrupado por módulo, derivado da matriz central:
 * base => ['label', 'base', 'itens' => [{slug, label, micro, acao}]].
 * É o formato consumido pela tela configuracoes/permissoes.php.
 */
function perm_catalogo_matriz(): array
{
    require_once __DIR__ . '/menu_agro.php';
    $catalogo = [];
    foreach (bios_menu_macros() as $macro) {
        $base = (string)$macro['permbase'];
        $grupo = ['label' => (string)$macro['label'], 'base' => $base, 'itens' => []];
        $grupo['itens'][] = ['slug' => (string)$macro['perm'], 'label' => 'Ver módulo ' . $macro['label'], 'micro' => null, 'acao' => 'ver'];
        foreach ($macro['micros'] as $micro) {
            /* pula cabeçalhos de seção (sep) e entradas sem slug — são rótulos do
               submenu, não telas; sem isto gerariam perms malformadas (ex.: cadastros..ver) */
            if (!empty($micro['sep']) || ($micro['slug'] ?? '') === '') continue;
            $ver = bios_menu_micro_perm($macro, $micro);
            $prefixo = preg_replace('/\.ver$/', '', $ver);
            $grupo['itens'][] = ['slug' => $ver, 'label' => $micro['label'] . ' — ver', 'micro' => $micro['label'], 'acao' => 'ver'];
            $grupo['itens'][] = ['slug' => $prefixo . '.editar', 'label' => $micro['label'] . ' — editar', 'micro' => $micro['label'], 'acao' => 'editar'];
            $grupo['itens'][] = ['slug' => $prefixo . '.excluir', 'label' => $micro['label'] . ' — excluir', 'micro' => $micro['label'], 'acao' => 'excluir'];
        }
        $catalogo[$base] = $grupo;
    }
    return $catalogo;
}

/** Catálogo plano slug => ['label', 'module'] (inclui ver/editar/excluir). */
function bios_agro_permission_catalog(): array
{
    $perms = [];
    foreach (perm_catalogo_matriz() as $grupo) {
        foreach ($grupo['itens'] as $item) {
            $perms[$item['slug']] = [
                'label'  => $item['label'],
                'module' => $grupo['label'],
            ];
        }
    }
    return $perms;
}

function bios_permission_catalog(): array
{
    return bios_agro_permission_catalog() + [
        /* Onda 2 fenologia: permissão DEDICADA de aprovação (decisão gestor 15/07).
           gestor/super_admin já a têm por wildcard (agro.* / *); grantável a usuários
           específicos (RT/agrônomo) na gestão de permissões. */
        'agro.variedade_fenologia.aprovar' => ['label' => 'Fenologia da variedade — aprovar', 'module' => 'Gestão Agrícola'],
        /* Onda 5 (poda→safra): permissões dedicadas de ciclo de vida da safra
           (decisão gestor 15/07) — separam operacional × RT; gestor/admin por wildcard. */
        'agro.safra.abrir'          => ['label' => 'Safra — abrir', 'module' => 'Gestão Agrícola'],
        'agro.safra.confirmar_poda' => ['label' => 'Safra — confirmar poda finalizada (define dia 0)', 'module' => 'Gestão Agrícola'],
        'agro.safra.reabrir'        => ['label' => 'Safra — reabrir', 'module' => 'Gestão Agrícola'],
        'agro.safra.encerrar'       => ['label' => 'Safra — encerrar', 'module' => 'Gestão Agrícola'],
        'agro.safra.editar'         => ['label' => 'Safra — editar', 'module' => 'Gestão Agrícola'],
        'agro.safra.aprovar'        => ['label' => 'Safra — aprovar', 'module' => 'Gestão Agrícola'],
        /* CRM Agro (fase protótipo 13/08): permissão ÚNICA de acesso à demo —
           todos os micros dos macros crm_revenda/crm_corretor usam este slug. */
        'crm.demo.ver' => ['label' => 'CRM Agro (demo) — acessar', 'module' => 'CRM Agro'],
        '*' => ['label' => 'Acesso total', 'module' => 'Global'],
    ];
}

/**
 * Fallback de permissões por perfil quando role_permissions não tem
 * marcações para o perfil (P-05: a matriz definitiva dos 7 perfis
 * aguarda validação do cliente — tarefa A3-T9 grava o seed real).
 */
function bios_default_role_permissions(): array
{
    return [
        'super_admin' => ['*'],
        // Obs.: a permissão macro (ex.: agro.ver) NÃO libera os micros;
        // por isso usamos wildcards (agro.*) ou micros explícitos.
        'gestor' => [
            'dashboard.*', 'agro.*', 'estoque.*', 'compras.*', 'custeio.*',
            'nutricao.*', 'mip.*', 'irrigacao.*', 'maquinas.*', 'pessoas.*',
            'comercial.*', 'financeiro.*', 'fiscal.*', 'patrimonio.*', 'relatorios.*',
        ],
        'operador' => [
            'dashboard.visao_geral.ver',
            'agro.ver', 'agro.apontamentos_campo.ver', 'agro.ordens_servico.ver',
            'agro.colheita.ver', 'agro.produtividade.ver',
            'estoque.ver', 'estoque.saidas.ver', 'estoque.entradas.ver',
            'mip.ver', 'mip.monitoramentos.ver',
        ],
        'financeiro' => [
            'dashboard.visao_geral.ver', 'dashboard.dashboard_financeiro.ver',
            'financeiro.*', 'custeio.*', 'compras.*', 'fiscal.*', 'relatorios.*',
        ],
        'visualizador' => [
            'dashboard.*', '*.ver',
        ],
    ];
}

function bios_permission_labels(): array
{
    $labels = [];
    foreach (bios_permission_catalog() as $slug => $meta) {
        $labels[$slug] = $meta['label'];
    }
    return $labels;
}
