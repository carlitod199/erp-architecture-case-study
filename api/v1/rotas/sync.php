<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/rotas/sync.php
   Rotas de LEITURA (sync delta) do app de campo.

   Contrato com o app (vero_campo):
   - GET /sync/{modulo}?desde=YYYY-MM-DD HH:MM:SS
   - resposta: api_ok(['itens' => [...]]) — cada item tem 'id' e
     'updated_at'; o envelope já devolve sync.server_time, que o
     app grava como novo delta.

   Regras deste arquivo:
   - todo SELECT filtra tenant_id = vero_tenant() (multiempresa);
   - 'desde' é validado por regex antes de entrar na query;
   - prepared statements sempre; PDO com EMULATE_PREPARES=false,
     então NUNCA repetimos o mesmo placeholder nomeado (:t e :t2);
   - colunas que variam entre ambientes (migrations 101/146) são
     montadas dinamicamente com vero_has_column() para a query
     não estourar PDOException em banco defasado;
   - LIMIT 500 ORDER BY updated_at (o app pagina pelo delta).
   ============================================================ */

/** Lê e valida o parâmetro ?desde= (data ou data+hora). Null se ausente. */
function sync_desde(): ?string
{
    $desde = trim((string)($_GET['desde'] ?? ''));
    if ($desde === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $desde)) {
        api_erro('desde_invalido', "Parâmetro 'desde' deve ser YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.", 422);
    }
    return $desde;
}

/** Subquery de saldo por produto (entrada − saída + ajuste). Usa :t2 para
 *  não repetir o placeholder do tenant do SELECT externo (EMULATE=false). */
function sync_sql_saldo(): string
{
    return "SELECT produto_id,
                   SUM(CASE WHEN tipo = 'entrada' THEN quantidade
                            WHEN tipo = 'saida'   THEN -quantidade
                            WHEN tipo = 'ajuste'  THEN quantidade
                            ELSE 0 END) AS saldo
              FROM estoque_movimentacoes
             WHERE tenant_id = :t2
             GROUP BY produto_id";
}

/* Tombstones (Onda 6): na carga CHEIA (sem ?desde=) só o conjunto vivo
   interessa; no DELTA, linhas que SAÍRAM do conjunto (inativadas/canceladas/
   status fora da fila) voltam com _excluido=1 para o app removê-las do cache.
   Exclusão FÍSICA (DELETE) não é coberta — o padrão do VERO é inativar. */

/** GET /sync/{modulo} — leitura delta por módulo. */
function rota_sync(array $usuario, string $modulo): never
{
    $tenant = vero_tenant();
    $desde  = sync_desde();
    $ehDelta = $desde !== null;

    switch ($modulo) {

        /* ── Talhões (na prática: setores/válvulas com o talhão-pai) ── */
        case 'talhoes': {
            // Colunas técnicas do talhão variam por ambiente (migration 101):
            // sem cultura_atual_id não há join de cultura; sem centroide_*
            // caímos em latitude/longitude (existem desde o esquema base).
            $temCultura   = vero_has_column('agro_talhoes', 'cultura_atual_id');
            $temCentroide = vero_has_column('agro_talhoes', 'centroide_lat');

            $colCultura   = $temCultura
                ? "t.cultura_atual_id, c.nome AS cultura"
                : "NULL AS cultura_atual_id, NULL AS cultura";
            $colCentroide = $temCentroide
                ? "t.centroide_lat, t.centroide_lng"
                : "t.latitude AS centroide_lat, t.longitude AS centroide_lng";
            $joinCultura  = $temCultura
                ? "LEFT JOIN agro_culturas c ON c.id = t.cultura_atual_id"
                : "";

            // Contexto de viticultura (migs 155/161 + base): variedade,
            // porta-enxerto, estrutura de condução e nº de plantas da válvula —
            // mesmo contexto que o web mostra no apontamento (itens 1.2/T-08).
            $colVar  = "NULL AS variedade_id, NULL AS variedade, NULL AS prod_kg_ha, NULL AS peso_caixa_cultura,
                        NULL AS cachos_por_planta, NULL AS peso_contentor_cultura";
            $joinVar = "";
            if (vero_has_column('agro_talhoes', 'variedade_id')) {
                // produtividade esperada + peso da caixa da cultura alimentam a
                // sugestão de colheita da calculadora de MO (23/07)
                $colProd = vero_has_column('agro_variedades', 'produtividade_esperada')
                    ? "vr.produtividade_esperada AS prod_kg_ha" : "NULL AS prod_kg_ha";
                $colPesoCx = vero_has_column('agro_culturas', 'peso_unidade_kg')
                    ? "cu2.peso_unidade_kg AS peso_caixa_cultura" : "NULL AS peso_caixa_cultura";
                // WP-CALC Z-06/Z-05 (mig 191/192): raleio usa cachos/planta da
                // variedade; colheita a granel usa o peso do contentor da cultura
                $colCachos = vero_has_column('agro_variedades', 'cachos_por_planta')
                    ? "vr.cachos_por_planta AS cachos_por_planta" : "NULL AS cachos_por_planta";
                $colPesoCt = vero_has_column('agro_culturas', 'peso_contentor_kg')
                    ? "cu2.peso_contentor_kg AS peso_contentor_cultura" : "NULL AS peso_contentor_cultura";
                $colVar  = "t.variedade_id, vr.nome AS variedade, {$colProd}, {$colPesoCx}, {$colCachos}, {$colPesoCt}";
                $joinVar = "LEFT JOIN agro_variedades vr ON vr.id = t.variedade_id
                            LEFT JOIN agro_culturas cu2 ON cu2.id = vr.cultura_id";
            }
            $colPe  = "NULL AS porta_enxerto";
            $joinPe = "";
            if (vero_has_column('agro_talhoes', 'porta_enxerto_id')) {
                $colPe  = "pe.nome AS porta_enxerto";
                $joinPe = "LEFT JOIN agro_porta_enxertos pe ON pe.id = t.porta_enxerto_id";
            }
            $colEstrutura = vero_has_column('agro_talhoes', 'estrutura_sistema')
                ? "t.estrutura_sistema" : "NULL AS estrutura_sistema";
            $colPlantas = vero_has_column('agro_talhoes', 'num_plantas')
                ? "t.num_plantas" : "NULL AS num_plantas";

            // Bomba vinculada à VÁLVULA (agro_bomba_valvulas.setor_id, migs 135/160)
            // — derivada com MIN() para nunca duplicar a linha do setor.
            $colBomba  = "NULL AS bomba_nome, NULL AS bomba_vazao_m3h, NULL AS bomba_potencia_kw";
            $joinBomba = "";
            if (vero_has_column('agro_bomba_valvulas', 'setor_id')) {
                $colBomba  = "bo.nome AS bomba_nome, bo.vazao_m3h AS bomba_vazao_m3h, bo.potencia_kw AS bomba_potencia_kw";
                $joinBomba = "LEFT JOIN (SELECT setor_id, tenant_id, MIN(bomba_id) AS bomba_id
                                           FROM agro_bomba_valvulas GROUP BY setor_id, tenant_id) bv
                                     ON bv.setor_id = s.id AND bv.tenant_id = s.tenant_id
                              LEFT JOIN agro_bombas bo ON bo.id = bv.bomba_id AND bo.ativo = 1";
            }

            // geometria (GeoJSON Polygon) do talhão-pai — desenha a área real
            // no mapa de satélite do app (mesma coluna que o web usa em mapa.php)
            $colGeo = vero_has_column('agro_talhoes', 'geometria') ? "t.geometria" : "NULL AS geometria";

            // Fazenda do talhão-pai (20/08): o seletor de fazenda do romaneio no
            // app deduzia o vínculo por fenologia/cargas (cobertura parcial) —
            // expor id+nome aqui fecha 100% sem quebrar contrato (campos novos).
            $colFaz  = "NULL AS fazenda_id, NULL AS fazenda";
            $joinFaz = "";
            if (vero_has_column('agro_talhoes', 'fazenda_id')) {
                $colFaz  = "t.fazenda_id, fz.nome AS fazenda";
                $joinFaz = "LEFT JOIN agro_fazendas fz ON fz.id = t.fazenda_id";
            }

            $colExcluido = $ehDelta ? "(CASE WHEN s.ativo = 1 THEN 0 ELSE 1 END) AS _excluido" : "0 AS _excluido";
            $filtroVivo  = $ehDelta ? "" : " AND s.ativo = 1";
            $sql = "SELECT s.id, s.talhao_id, s.nome, s.codigo, s.tipo, s.area_ha, s.ativo, s.updated_at,
                           t.nome AS talhao_nome, t.area_ha AS talhao_area_ha,
                           {$colCultura}, {$colCentroide}, {$colGeo},
                           {$colVar}, {$colPe}, {$colEstrutura}, {$colPlantas},
                           {$colBomba}, {$colFaz}, {$colExcluido}
                      FROM agro_setores s
                      LEFT JOIN agro_talhoes t ON t.id = s.talhao_id
                      {$joinCultura}
                      {$joinVar}
                      {$joinPe}
                      {$joinBomba}
                      {$joinFaz}
                     WHERE s.tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND s.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY s.updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Safras ── */
        case 'safras': {
            $sql = "SELECT id, identificacao, fazenda_id, data_inicio, data_fim_prevista, status, updated_at
                      FROM agro_safras
                     WHERE tenant_id = :t";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Fenologia POR VARIEDADE da válvula (migs 157/159 — dia0 = poda) ──
           Retrato ATUAL por talhão (ignora 'desde': o conjunto é pequeno e a
           fase muda com o passar dos dias, não com updated_at). O app funde por
           id (= talhao_id) no cache. Usa o MESMO resolver do web. */
        case 'fenologia': {
            if (!vero_has_column('agro_safra_talhoes', 'talhao_id')
                || !vero_has_column('agro_variedade_fases', 'dia_inicio')) {
                api_ok(['itens' => []]); // banco sem as migrations — app esconde o bloco
            }
            $helper = dirname(__DIR__, 3) . '/agro/_fenologia_helper.php';
            if (is_file($helper)) {
                require_once $helper;
            }
            if (!function_exists('vero_a1_variedade_fase_por_dias')) {
                api_ok(['itens' => []]);
            }

            $colPoda = vero_has_column('agro_safra_talhoes', 'data_poda')
                ? "st.data_poda" : "NULL AS data_poda";
            $rows = vero_rows(
                "SELECT st.talhao_id, st.safra_id, {$colPoda},
                        s.identificacao AS safra, s.data_inicio, s.status AS safra_status,
                        t.variedade_id, vr.nome AS variedade
                   FROM agro_safra_talhoes st
                   JOIN agro_safras s   ON s.id = st.safra_id  AND s.tenant_id = st.tenant_id
                   JOIN agro_talhoes t  ON t.id = st.talhao_id AND t.tenant_id = st.tenant_id
              LEFT JOIN agro_variedades vr ON vr.id = t.variedade_id
                  WHERE st.tenant_id = :t
                    AND (s.status IS NULL OR s.status NOT IN ('encerrada', 'cancelada'))
                  ORDER BY s.data_inicio DESC",
                [':t' => $tenant]
            );

            /* F1 (alerta LMR no app): 'colheita_dia_inicio' = dia_inicio da fase
               de COLHEITA da fenologia aprovada vigente da variedade (nome que
               contenha "colheita", case-insensitive). O app estima os dias até a
               colheita (colheita_dia_inicio − dias_desde_poda) e compara com a
               carência do produto — mesma régua do C-36 do web (mip/aplicacoes.php).
               Sem fase de colheita → null (sem dado, sem alerta). Cache por
               variedade p/ não repetir a query no loop. */
            $colheitaPorVar = [];
            $colheitaIni = static function (int $varId) use (&$colheitaPorVar): ?int {
                if (array_key_exists($varId, $colheitaPorVar)) {
                    return $colheitaPorVar[$varId];
                }
                // Mesma seleção de versão do resolver vero_a1_variedade_fase_por_dias
                // (helper agro/_fenologia_helper.php): maior versão 'aprovada' ativa.
                // Placeholders todos ÚNICOS (:t/:t2, :v/:v2) — EMULATE_PREPARES=false.
                $fases = vero_rows(
                    "SELECT fa.nome, fa.dia_inicio
                       FROM agro_variedade_fases fa
                       JOIN agro_variedade_fenologia fe ON fe.id = fa.fenologia_id
                      WHERE fa.tenant_id = :t AND fe.variedade_id = :v
                        AND fe.status = 'aprovada' AND fe.ativo = 1 AND fa.ativo = 1
                        AND fe.versao = (SELECT MAX(versao) FROM agro_variedade_fenologia
                                          WHERE tenant_id = :t2 AND variedade_id = :v2
                                            AND status = 'aprovada' AND ativo = 1)
                      ORDER BY fa.dia_inicio",
                    [':t' => vero_tenant(), ':v' => $varId,
                     ':t2' => vero_tenant(), ':v2' => $varId]
                );
                $ini = null;
                foreach ($fases as $f) {
                    if (mb_stripos((string)$f['nome'], 'colheita') !== false) {
                        $ini = (int)$f['dia_inicio'];
                        break; // primeira fase de colheita (menor dia_inicio)
                    }
                }
                return $colheitaPorVar[$varId] = $ini;
            };

            $itens = [];
            $vistos = [];
            $agora = time();
            foreach ($rows as $r) {
                $tal = (int)$r['talhao_id'];
                if (isset($vistos[$tal])) {
                    continue; // safra mais recente vence
                }
                $vistos[$tal] = true;
                // dia0 = poda confirmada da válvula; fallback: início da safra
                $poda = $r['data_poda'] ?: $r['data_inicio'];
                if (!$poda || empty($r['variedade_id'])) {
                    continue;
                }
                $dias = (int)floor(($agora - strtotime((string)$poda)) / 86400);
                if ($dias < 0) {
                    continue;
                }
                $fase = vero_a1_variedade_fase_por_dias((int)$r['variedade_id'], $dias);
                $itens[] = [
                    'id'              => $tal, // chave do cache do app
                    'talhao_id'       => $tal,
                    'safra_id'        => (int)$r['safra_id'],
                    'safra'           => $r['safra'],
                    'variedade'       => $r['variedade'],
                    'data_poda'       => (string)$poda,
                    'dias_desde_poda' => $dias,
                    'fase_nome'       => $fase['nome'] ?? null,
                    'dia_inicio'      => isset($fase['dia_inicio']) ? (int)$fase['dia_inicio'] : null,
                    'dia_fim'         => isset($fase['dia_fim']) ? (int)$fase['dia_fim'] : null,
                    'volume_mm_dia'   => $fase['volume_mm_dia'] ?? null,
                    // F1: dia_inicio da fase de colheita da variedade (null se não houver)
                    'colheita_dia_inicio' => $colheitaIni((int)$r['variedade_id']),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ];
            }
            api_ok(['itens' => $itens]);
        }

        /* ── Apontamentos EM ABERTO (status 'iniciado', dois estágios mig 167) ──
           Decisão do gestor 20/07: a lista de serviços do app parte do
           APONTAMENTO INICIADO (não do planejamento de atividades). Concluído/
           validado sai da lista via tombstone no delta. */
        case 'apontamentos_abertos': {
            if (!vero_has_column('agro_apontamentos', 'iniciado_em')) {
                api_ok(['itens' => []]); // banco sem a mig 167
            }
            $colExcluido = $ehDelta
                ? "(CASE WHEN a.status = 'iniciado' THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND a.status = 'iniciado'";
            $colResp = vero_has_column('agro_apontamentos', 'responsavel_id')
                ? "resp.nome AS responsavel" : "NULL AS responsavel";
            $joinResp = vero_has_column('agro_apontamentos', 'responsavel_id')
                ? "LEFT JOIN agro_operadores resp ON resp.id = a.responsavel_id" : "";
            $sql = "SELECT a.id, a.tipo, a.talhao_id, t.nome AS talhao_nome,
                           a.data_apontamento, a.iniciado_em, a.observacao,
                           a.status, a.updated_at, {$colResp}, {$colExcluido}
                      FROM agro_apontamentos a
                 LEFT JOIN agro_talhoes t ON t.id = a.talhao_id
                      {$joinResp}
                     WHERE a.tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND a.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY a.updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Atividades planejadas (canceladas viram tombstone no delta) ── */
        case 'atividades': {
            $colExcluido = $ehDelta
                ? "(CASE WHEN a.status = 'cancelada' THEN 1 ELSE 0 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND a.status <> 'cancelada'";
            $sql = "SELECT a.id, a.descricao, a.tipo, a.data_planejada, a.status, a.observacao,
                           a.talhao_id, a.updated_at, t.nome AS talhao_nome, {$colExcluido}
                      FROM agro_atividades a
                      LEFT JOIN agro_talhoes t ON t.id = a.talhao_id
                     WHERE a.tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND a.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY a.updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Alertas em aberto/reconhecidos (resolvidos = tombstone no delta) ── */
        case 'alertas': {
            $colExcluido = $ehDelta
                ? "(CASE WHEN status IN ('aberto','reconhecido') THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND status IN ('aberto','reconhecido')";
            $sql = "SELECT id, categoria, severidade, titulo, mensagem, status, data,
                           talhao_id, reconhecido_em, updated_at, {$colExcluido}
                      FROM agro_alertas
                     WHERE tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Estoque (produtos ativos + saldo agregado) ──
           Ficha técnica da bula (carência, classe tox, registro MAPA) entra
           quando as colunas existem. SEM custos — P-75 (CSO): valores
           financeiros não descem ao app de campo. */
        case 'estoque': {
            $extras = '';
            foreach (['tipo_insumo', 'fabricante', 'registro_mapa', 'classe_toxicologica',
                      'carencia_dias', 'lmr_dias', 'intervalo_aplicacoes_dias'] as $col) {
                $extras .= vero_has_column('estoque_produtos', $col)
                    ? ", p.{$col}" : ", NULL AS {$col}";
            }
            // grupo do produto (estoque_grupos) — organiza a tela do app por seções
            $temGrupo = vero_has_column('estoque_produtos', 'grupo_id')
                     && vero_has_column('estoque_grupos', 'nome');
            $colGrupo = $temGrupo ? ", p.grupo_id, g.nome AS grupo" : ", NULL AS grupo_id, NULL AS grupo";
            $joinGrupo = $temGrupo ? " LEFT JOIN estoque_grupos g ON g.id = p.grupo_id AND g.tenant_id = p.tenant_id" : "";
            $colExcluido = $ehDelta
                ? ", (CASE WHEN p.ativo = 1 THEN 0 ELSE 1 END) AS _excluido"
                : ", 0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND p.ativo = 1";
            $sql = "SELECT p.id, p.codigo, p.nome, p.unidade, p.estoque_minimo, p.ativo, p.updated_at,
                           COALESCE(m.saldo, 0) AS saldo{$extras}{$colGrupo}{$colExcluido}
                      FROM estoque_produtos p
                      LEFT JOIN (" . sync_sql_saldo() . ") m ON m.produto_id = p.id{$joinGrupo}
                     WHERE p.tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant, ':t2' => $tenant];
            if ($desde !== null) {
                $sql .= " AND p.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY p.updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Máquinas ativas (inativadas viram tombstone no delta) ── */
        case 'maquinas': {
            $colExcluido = $ehDelta
                ? "(CASE WHEN ativo = 1 THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND ativo = 1";
            $sql = "SELECT id, codigo, nome, tipo, marca, modelo, horimetro_atual, status, ativo, updated_at, {$colExcluido}
                      FROM maquinas
                     WHERE tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Colaboradores (agro_operadores) p/ a premiação no concluir (F3) ──
           P-75 (CSO): NENHUM salário/custo desce ao app — só identificação e
           vínculo. tipo_vinculo: clt|diarista|terceirizado|outro (o servidor
           decide a modalidade da premiação por ele; o app não calcula nada).
           Inativados viram tombstone no delta. */
        case 'colaboradores': {
            if (!vero_has_column('agro_operadores', 'tipo_vinculo')) {
                api_ok(['itens' => []]); // banco sem o cadastro de operadores
            }
            $colExcluido = $ehDelta
                ? "(CASE WHEN ativo = 1 THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND ativo = 1";
            $sql = "SELECT id, nome, funcao, tipo_vinculo, ativo, updated_at, {$colExcluido}
                      FROM agro_operadores
                     WHERE tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY updated_at LIMIT 500";
            $itens = [];
            foreach (vero_rows($sql, $p) as $r) {
                // id prefixado: o cache do app é por id e as duas tabelas colidem
                $r['pessoa_id'] = (int)$r['id'];
                $r['origem'] = 'colaborador';
                $r['id'] = 'op-' . $r['id'];
                $itens[] = $r;
            }
            // Pessoas do apontamento (22/07): TERCEIRIZADOS de rh_terceirizados —
            // produção OU diária no app; o valor da diária fica no servidor (P-75).
            if (vero_has_column('rh_terceirizados', 'modalidade_padrao')) {
                $colExcluido2 = $ehDelta
                    ? "(CASE WHEN ativo = 1 THEN 0 ELSE 1 END) AS _excluido"
                    : "0 AS _excluido";
                $filtroVivo2 = $ehDelta ? "" : " AND ativo = 1";
                $sql2 = "SELECT id, nome, modalidade_padrao, ativo, updated_at, {$colExcluido2}
                           FROM rh_terceirizados
                          WHERE tenant_id = :t2{$filtroVivo2}";
                $p2 = [':t2' => $tenant];
                if ($desde !== null) {
                    $sql2 .= " AND updated_at > :desde2";
                    $p2[':desde2'] = $desde;
                }
                $sql2 .= " ORDER BY updated_at LIMIT 500";
                foreach (vero_rows($sql2, $p2) as $r) {
                    $itens[] = [
                        'id' => 'ter-' . $r['id'],
                        'pessoa_id' => (int)$r['id'],
                        'origem' => 'terceirizado',
                        'nome' => $r['nome'],
                        'funcao' => 'Terceirizado',
                        'tipo_vinculo' => 'terceirizado',
                        'modalidade_padrao' => $r['modalidade_padrao'],
                        'ativo' => $r['ativo'],
                        'updated_at' => $r['updated_at'],
                        '_excluido' => $r['_excluido'],
                    ];
                }
            }
            api_ok(['itens' => $itens]);
        }

        /* ── Aplicações/DF do campo (fila de execução do operador) ──
           Item composto: cada DF carrega 'itens' => produtos com dose e
           carência (mesmo padrão de mip_referencias). SEM custos (P-75). */
        case 'aplicacoes': {
            api_exigir('mip.ver'); // DF é documento do módulo MIP

            $colExcluido = $ehDelta
                ? "(CASE WHEN a.status IN ('planejada','rascunho','registrada') THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND a.status IN ('planejada', 'rascunho', 'registrada')";
            $sql = "SELECT a.id, a.tipo, a.talhao_id, t.nome AS talhao_nome, a.status,
                           a.data, a.data_prevista, a.area_aplicada_ha,
                           a.volume_calda_l, a.volume_calda_ha_l, a.condicao_ceu,
                           a.observacao, a.updated_at, {$colExcluido}
                      FROM agro_aplicacoes a
                 LEFT JOIN agro_talhoes t ON t.id = a.talhao_id
                     WHERE a.tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND a.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY a.updated_at LIMIT 500";
            $dfs = vero_rows($sql, $p);

            $porDf = [];
            if ($dfs !== [] && vero_has_column('agro_aplicacao_itens', 'aplicacao_id')) {
                $ids = array_map(static fn(array $a): int => (int)$a['id'], $dfs);
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                $linhas = vero_rows(
                    "SELECT i.aplicacao_id, i.produto_id, p.nome AS produto, i.dose_valor,
                            i.dose_unidade, i.quantidade_consumida, i.quantidade_unidade,
                            i.carencia_dias, i.intervalo_reentrada_horas
                       FROM agro_aplicacao_itens i
                       JOIN estoque_produtos p ON p.id = i.produto_id
                      WHERE i.tenant_id = ? AND i.aplicacao_id IN ({$marcadores})
                      ORDER BY p.nome",
                    array_merge([$tenant], $ids)
                );
                foreach ($linhas as $l) {
                    $dfId = (int)$l['aplicacao_id'];
                    unset($l['aplicacao_id']);
                    $porDf[$dfId][] = $l;
                }
            }
            // Status de assinatura por DF (papel operador × rt) — alimenta a
            // caixa "aguardando assinatura do RT" no app. Papel é coluna aditiva
            // (23/07): em banco defasado tratamos toda assinatura como operador.
            $sigPorDf = [];
            if ($dfs !== [] && vero_has_column('agro_aplicacao_assinaturas', 'aplicacao_id')) {
                $temPapel = vero_has_column('agro_aplicacao_assinaturas', 'papel');
                $ids = array_map(static fn(array $a): int => (int)$a['id'], $dfs);
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                $colPapel = $temPapel ? 'papel' : "NULL AS papel";
                $sigs = vero_rows(
                    "SELECT aplicacao_id, {$colPapel}
                       FROM agro_aplicacao_assinaturas
                      WHERE tenant_id = ? AND aplicacao_id IN ({$marcadores})",
                    array_merge([$tenant], $ids)
                );
                foreach ($sigs as $s) {
                    $dfId = (int)$s['aplicacao_id'];
                    if (!isset($sigPorDf[$dfId])) $sigPorDf[$dfId] = ['operador' => 0, 'rt' => 0];
                    $papel = ($s['papel'] ?? null) === 'rt' ? 'rt' : 'operador';
                    $sigPorDf[$dfId][$papel] = 1;
                }
            }

            $itens = [];
            foreach ($dfs as $a) {
                $a['itens'] = $porDf[(int)$a['id']] ?? [];
                $sig = $sigPorDf[(int)$a['id']] ?? ['operador' => 0, 'rt' => 0];
                $a['assinado_operador'] = $sig['operador'];
                $a['assinado_rt'] = $sig['rt'];
                $itens[] = $a;
            }
            api_ok(['itens' => $itens]);
        }

        /* ── Movimentações de estoque (extrato, SEM custos — P-75) ── */
        case 'estoque_movimentacoes': {
            $sql = "SELECT m.id, m.produto_id, m.tipo, m.quantidade, m.data_movimento,
                           m.origem_tipo, m.observacao, m.updated_at
                      FROM estoque_movimentacoes m
                     WHERE m.tenant_id = :t";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND m.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY m.updated_at DESC LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── COMPRAS: fornecedores (nomes para pedidos/recebimento) ── */
        case 'fornecedores': {
            api_exigir('compras.ver');
            $colExcluido = $ehDelta ? "(CASE WHEN ativo = 1 THEN 0 ELSE 1 END) AS _excluido" : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND ativo = 1";
            $sql = "SELECT id, nome, cnpj_cpf, telefone, ativo, updated_at, {$colExcluido}
                      FROM fornecedores WHERE tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) { $sql .= " AND updated_at > :desde"; $p[':desde'] = $desde; }
            $sql .= " ORDER BY updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── COMPRAS: solicitações (SC) + itens — o campo acompanha o status ── */
        case 'compras_solicitacoes': {
            api_exigir('compras.ver');
            $sql = "SELECT s.id, s.numero, s.status, s.data_solicitacao, s.justificativa,
                           s.solicitante_id, s.safra_talhao_id, s.updated_at
                      FROM compras_solicitacoes s
                     WHERE s.tenant_id = :t";
            $p = [':t' => $tenant];
            if ($desde !== null) { $sql .= " AND s.updated_at > :desde"; $p[':desde'] = $desde; }
            $sql .= " ORDER BY s.updated_at DESC LIMIT 200";
            $sols = vero_rows($sql, $p);
            $porSol = [];
            if ($sols !== []) {
                $ids = array_map(static fn(array $a): int => (int)$a['id'], $sols);
                $marc = implode(',', array_fill(0, count($ids), '?'));
                $linhas = vero_rows(
                    "SELECT si.solicitacao_id, si.produto_id, si.descricao, si.quantidade, pr.nome AS produto
                       FROM compras_solicitacao_itens si
                  LEFT JOIN estoque_produtos pr ON pr.id = si.produto_id
                      WHERE si.tenant_id = ? AND si.solicitacao_id IN ({$marc})",
                    array_merge([$tenant], $ids)
                );
                foreach ($linhas as $l) { $sid = (int)$l['solicitacao_id']; unset($l['solicitacao_id']); $porSol[$sid][] = $l; }
            }
            $itens = [];
            foreach ($sols as $s) { $s['itens'] = $porSol[(int)$s['id']] ?? []; $itens[] = $s; }
            api_ok(['itens' => $itens]);
        }

        /* ── COMPRAS: pedidos (PC) + itens — ver status e receber ── */
        case 'compras_pedidos': {
            api_exigir('compras.ver');
            $vivos = "('aprovacao','aprovado','recebido_parcial','recebido')";
            $colExcluido = $ehDelta
                ? "(CASE WHEN p.status IN {$vivos} THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND p.status IN {$vivos}";
            $sql = "SELECT p.id, p.numero, p.status, p.valor_total, p.data_pedido,
                           p.data_entrega_prevista, p.condicao_pagamento, p.acima_orcamento,
                           p.fornecedor_id, f.nome AS fornecedor, p.updated_at, {$colExcluido}
                      FROM compras_pedidos p
                 LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                     WHERE p.tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) { $sql .= " AND p.updated_at > :desde"; $p[':desde'] = $desde; }
            $sql .= " ORDER BY p.updated_at DESC LIMIT 200";
            $peds = vero_rows($sql, $p);
            $porPed = [];
            if ($peds !== []) {
                $colValidade = vero_has_column('estoque_produtos', 'controla_validade')
                    ? ", pr.controla_validade" : ", 0 AS controla_validade";
                $ids = array_map(static fn(array $a): int => (int)$a['id'], $peds);
                $marc = implode(',', array_fill(0, count($ids), '?'));
                $linhas = vero_rows(
                    "SELECT pi.pedido_id, pi.id AS pedido_item_id, pi.produto_id, pi.descricao,
                            pi.quantidade, pi.quantidade_recebida, pi.valor_unitario, pr.nome AS produto{$colValidade}
                       FROM compras_pedido_itens pi
                  LEFT JOIN estoque_produtos pr ON pr.id = pi.produto_id
                      WHERE pi.tenant_id = ? AND pi.pedido_id IN ({$marc})",
                    array_merge([$tenant], $ids)
                );
                foreach ($linhas as $l) { $pid = (int)$l['pedido_id']; unset($l['pedido_id']); $porPed[$pid][] = $l; }
            }
            $itens = [];
            foreach ($peds as $pd) { $pd['itens'] = $porPed[(int)$pd['id']] ?? []; $itens[] = $pd; }
            api_ok(['itens' => $itens]);
        }

        /* ── COMPRAS: aprovações pendentes (RETRATO — some ao decidir) ── */
        case 'compras_aprovacoes_pendentes': {
            api_exigir('compras.ver');
            $sql = "SELECT a.pedido_id AS id, a.id AS aprovacao_id, a.created_at AS enviado_em,
                           p.numero, p.valor_total, p.acima_orcamento, p.data_pedido,
                           f.nome AS fornecedor, p.updated_at,
                           (SELECT COUNT(*) FROM compras_pedido_itens i
                             WHERE i.tenant_id = p.tenant_id AND i.pedido_id = p.id) AS itens_qtd
                      FROM compras_aprovacoes a
                      JOIN compras_pedidos p ON p.id = a.pedido_id AND p.tenant_id = a.tenant_id
                 LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                     WHERE a.tenant_id = :t AND a.status = 'pendente' AND p.status = 'aprovacao'
                     ORDER BY a.id DESC LIMIT 200";
            api_ok(['itens' => vero_rows($sql, [':t' => $tenant])]);
        }

        /* ── Referências MIP: alvos + produtos indicados pelo RT ──
           Item composto: cada alvo carrega 'produtos' => [...]. A lista de
           produtos vem de uma segunda query agrupada em PHP (mais simples
           e legível que JSON_ARRAYAGG). */
        case 'mip_referencias': {
            $colExcluido = $ehDelta
                ? "(CASE WHEN ativo = 1 THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";
            $filtroVivo = $ehDelta ? "" : " AND ativo = 1";
            $sql = "SELECT id, nome, tipo, nivel_acao, ativo, updated_at, {$colExcluido}
                      FROM mip_alvos
                     WHERE tenant_id = :t{$filtroVivo}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY updated_at LIMIT 500";
            $alvos = vero_rows($sql, $p);

            // mip_alvo_produtos nasce na migration 146: em banco defasado a
            // tabela pode não existir — nesse caso devolvemos alvos sem produtos.
            $porAlvo = [];
            if ($alvos !== [] && vero_has_column('mip_alvo_produtos', 'alvo_id')) {
                $ids = array_map(static fn(array $a): int => (int)$a['id'], $alvos);
                $marcadores = implode(',', array_fill(0, count($ids), '?'));
                // F1 (alerta LMR no app): carência e LMR da bula do produto —
                // mesmas colunas que o C-36 do web usa (estoque_produtos.carencia_dias
                // / lmr_dias, mig da bula DB-27). Guarded p/ banco defasado.
                $colCarencia = vero_has_column('estoque_produtos', 'carencia_dias')
                    ? ", p.carencia_dias" : ", NULL AS carencia_dias";
                $colLmr = vero_has_column('estoque_produtos', 'lmr_dias')
                    ? ", p.lmr_dias" : ", NULL AS lmr_dias";
                // Placeholders posicionais (não misturar com nomeados na mesma query).
                $linhas = vero_rows(
                    "SELECT ap.alvo_id, ap.produto_id, p.nome, ap.dose, ap.dose_unidade,
                            ap.volume_calda_ha, ap.ativo{$colCarencia}{$colLmr}
                       FROM mip_alvo_produtos ap
                       JOIN estoque_produtos p ON p.id = ap.produto_id
                      WHERE ap.tenant_id = ? AND ap.ativo = 1 AND ap.alvo_id IN ({$marcadores})",
                    array_merge([$tenant], $ids)
                );
                foreach ($linhas as $l) {
                    $alvoId = (int)$l['alvo_id'];
                    unset($l['alvo_id']);
                    $porAlvo[$alvoId][] = $l;
                }
            }
            $itens = [];
            foreach ($alvos as $a) {
                $a['produtos'] = $porAlvo[(int)$a['id']] ?? [];
                $itens[] = $a;
            }
            api_ok(['itens' => $itens]);
        }

        /* ── Parâmetros da CALCULADORA de mão de obra (gestor 23/07) ──
           Espelho do painel agro/_calc_mo_painel.php: um item por tipo de
           atividade com unidade padrão, rendimento (referência + OBSERVADO
           dos apontamentos reais), custos de diária, fator e a regra de
           premiação vigente (meta/tarifa). RETRATO — conjunto pequeno. */
        case 'calc_parametros': {
            if (!vero_has_column('agro_calc_parametros', 'tipo_atividade_id')) {
                api_ok(['itens' => []]);
            }
            $hoje = date('Y-m-d');
            $tipos = vero_rows(
                "SELECT id, nome, COALESCE(unidade_padrao,'') AS unidade_padrao
                   FROM agro_tipos_atividade WHERE tenant_id = :t AND ativo = 1
                  ORDER BY nome", [':t' => $tenant]);
            $params = [];
            foreach (vero_rows(
                "SELECT tipo_atividade_id, chave, valor FROM agro_calc_parametros
                  WHERE tenant_id = :t AND ativo = 1
                    AND vigencia_inicio <= :d1 AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)",
                [':t' => $tenant, ':d1' => $hoje, ':d2' => $hoje]) as $r) {
                $params[(int)$r['tipo_atividade_id']][(string)$r['chave']] = (float)$r['valor'];
            }
            $premia = [];
            if (vero_has_column('rh_regras_premiacao', 'meta_qtd')) {
                foreach (vero_rows(
                    "SELECT tipo_atividade_id AS tipo, unidade, meta_qtd, valor_acima_meta
                       FROM rh_regras_premiacao
                      WHERE tenant_id = :t AND ativo = 1 AND valor_acima_meta > 0
                        AND (vigencia_inicio IS NULL OR vigencia_inicio <= :d1)
                        AND (vigencia_fim    IS NULL OR vigencia_fim    >= :d2)
                      ORDER BY (cultura_id IS NULL), id DESC",
                    [':t' => $tenant, ':d1' => $hoje, ':d2' => $hoje]) as $r) {
                    $tid = (int)$r['tipo'];
                    if (!isset($premia[$tid])) {
                        $premia[$tid] = ['meta' => (float)$r['meta_qtd'], 'valor' => (float)$r['valor_acima_meta']];
                    }
                }
            }
            // rendimento OBSERVADO (Σ qtd ÷ Σ diárias reais) — motor do painel web
            $obs = [];
            $motor = __DIR__ . '/../../../agro/_calc_mo.php';
            if (is_file($motor)) {
                require_once $motor;
                if (function_exists('vero_calc_mo_rendimento_observado_mapa')) {
                    $obs = vero_calc_mo_rendimento_observado_mapa();
                }
            }
            $itens = [];
            foreach ($tipos as $tp) {
                $tid = (int)$tp['id'];
                $p = $params[$tid] ?? [];
                $o = $obs[$tid] ?? null;
                $itens[] = [
                    'id'                  => $tid,
                    'nome'                => $tp['nome'],
                    'unidade_padrao'      => $tp['unidade_padrao'],
                    'rendimento'          => (float)($p['rendimento_por_diaria'] ?? 0),
                    'custo_propria'       => (float)($p['custo_diaria_propria'] ?? 0),
                    'custo_terceirizada'  => (float)($p['custo_diaria_terceirizada'] ?? 0),
                    'fator'               => (float)($p['fator_ajuste'] ?? 0),
                    'premio_meta'         => (float)($premia[$tid]['meta'] ?? 0),
                    'premio_valor'        => (float)($premia[$tid]['valor'] ?? 0),
                    'rend_observado'      => $o !== null ? (float)($o['rendimento'] ?? 0) : 0,
                    'obs_diarias'         => $o !== null ? (int)($o['n_diarias'] ?? 0) : 0,
                    'updated_at'          => date('Y-m-d H:i:s'),
                ];
            }
            api_ok(['itens' => $itens]);
        }

        /* ── Colheitas PENDENTES de produção (gestor 23/07) ──
           O escritório lança a colheita (prevista) na tela Colheita; o campo
           preenche o REALIZADO pelo app. Pendente = realizado zerado.
           RETRATO (ignora ?desde=): conjunto pequeno; sair do retrato =
           preenchida/apagada e some do cache do app. */
        case 'colheitas_pendentes': {
            if (!vero_has_column('colheita_registros', 'kg_total_realizado')) {
                api_ok(['itens' => []]);
            }
            api_ok(['itens' => vero_rows(
                "SELECT r.id, r.setor_id, r.talhao_id, r.variedade_id,
                        s.nome AS setor_nome, t.nome AS talhao_nome,
                        vr.nome AS variedade, r.data_colheita,
                        r.kg_total_previsto, r.updated_at
                   FROM colheita_registros r
                   JOIN agro_setores s ON s.id = r.setor_id AND s.tenant_id = r.tenant_id
              LEFT JOIN agro_talhoes t ON t.id = r.talhao_id
              LEFT JOIN agro_variedades vr ON vr.id = r.variedade_id
                  WHERE r.tenant_id = :t
                    AND (r.kg_total_realizado IS NULL OR r.kg_total_realizado = 0)
                  ORDER BY r.data_colheita DESC, r.id DESC
                  LIMIT 200",
                [':t' => $tenant]
            )]);
        }

        /* ── Pontos de amostragem MIP (cadastro por válvula, A1-20) ──
           Alimenta o select "Ponto de amostragem" do formulário de
           monitoramento do app (paridade com o Novo monitoramento web).
           Sem coluna 'ativo': exclusão física não tombstona (cadastro raro). */
        case 'mip_pontos': {
            if (!vero_has_column('mip_pontos_amostragem', 'talhao_id')) {
                api_ok(['itens' => []]); // banco sem o cadastro (A1-20)
            }
            $sql = "SELECT id, talhao_id, nome, latitude, longitude, updated_at,
                           0 AS _excluido
                      FROM mip_pontos_amostragem
                     WHERE tenant_id = :t";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Parâmetros do tenant (chave/valor) ── */
        case 'parametros': {
            // updated_at existe desde a migration 134 (bloco AUDIT), mas
            // confirmamos: sem a coluna, devolvemos NOW() e ignoramos o delta.
            $temUpdated = vero_has_column('tenant_parametros', 'updated_at');
            $colUpdated = $temUpdated ? 'updated_at' : 'NOW() AS updated_at';

            $sql = "SELECT id, chave, valor, {$colUpdated}
                      FROM tenant_parametros
                     WHERE tenant_id = :t";
            $p = [':t' => $tenant];
            if ($desde !== null && $temUpdated) {
                $sql .= " AND updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= $temUpdated ? " ORDER BY updated_at LIMIT 500" : " ORDER BY chave LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── Monitoramentos MIP recebidos (visão do líder/gestor) ── */
        case 'mip_recebidos': {
            api_exigir('mip.ver');

            // Colunas da migration 146 (quantidade_encontrada, status,
            // monitor_id) podem não existir em banco defasado.
            $temQtd     = vero_has_column('mip_monitoramentos', 'quantidade_encontrada');
            $temStatus  = vero_has_column('mip_monitoramentos', 'status');
            $temMonitor = vero_has_column('mip_monitoramentos', 'monitor_id');

            $colQtd     = $temQtd ? 'm.quantidade_encontrada' : 'NULL AS quantidade_encontrada';
            // 8.1: consolidação por área no app do líder (regra de 3)
            $colPlantas = vero_has_column('mip_monitoramentos', 'plantas_amostradas')
                ? 'm.plantas_amostradas' : 'NULL AS plantas_amostradas';
            $colLocal   = vero_has_column('mip_monitoramentos', 'local_infestacao')
                ? 'm.local_infestacao' : 'NULL AS local_infestacao';
            $colMonitor = $temMonitor
                ? 'm.monitor_id, u.nome AS monitor_nome'
                : 'NULL AS monitor_id, NULL AS monitor_nome';
            $joinUser   = $temMonitor ? 'LEFT JOIN usuarios u ON u.id = m.monitor_id' : '';
            // Sem a coluna status, todo legado conta como 'enviado' (regra da 146).
            // Delta: leituras que voltaram a rascunho viram tombstone.
            $filtroStatus = ($temStatus && !$ehDelta) ? "AND m.status = 'enviado'" : '';
            $colExcluido = ($temStatus && $ehDelta)
                ? "(CASE WHEN m.status = 'enviado' THEN 0 ELSE 1 END) AS _excluido"
                : "0 AS _excluido";

            $sql = "SELECT m.id, m.talhao_id, m.ponto_id, m.alvo_id, m.data_monitoramento,
                           m.nivel_infestacao, m.unidade, {$colQtd}, {$colPlantas}, {$colLocal}, {$colMonitor},
                           m.observacao, m.updated_at,
                           a.nome AS alvo_nome, a.nivel_acao, t.nome AS talhao_nome, {$colExcluido}
                      FROM mip_monitoramentos m
                      LEFT JOIN mip_alvos a ON a.id = m.alvo_id
                      LEFT JOIN agro_talhoes t ON t.id = m.talhao_id
                      {$joinUser}
                     WHERE m.tenant_id = :t {$filtroStatus}";
            $p = [':t' => $tenant];
            if ($desde !== null) {
                $sql .= " AND m.updated_at > :desde";
                $p[':desde'] = $desde;
            }
            $sql .= " ORDER BY m.updated_at LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── FINANCEIRO: contas a pagar/receber (RETRATO — view no app) ──
           Uma permissão por tipo: só entra 'pagar' e/ou 'receber' que o
           usuário pode ver. Janela: títulos não cancelados, vencendo dos
           últimos 90 dias em diante (mais futuros e sem vencimento). O app
           deriva "vencido" (aberto + venc < hoje) e filtra por aba. */
        case 'financeiro': {
            $tipos = [];
            if (api_pode('financeiro.contas_pagar.ver'))   { $tipos[] = 'pagar'; }
            if (api_pode('financeiro.contas_receber.ver')) { $tipos[] = 'receber'; }
            if ($tipos === []) {
                api_exigir('financeiro.contas_pagar.ver'); // 403 padrão do módulo
            }
            $ph = [];
            $p  = [':t' => $tenant];
            foreach ($tipos as $i => $tp) { $k = ":tipo{$i}"; $ph[] = $k; $p[$k] = $tp; }
            $inTipos = implode(',', $ph);
            // 'documento' nasce em migration posterior à 120 — protege banco defasado
            $colDoc = vero_has_column('movimentacoes_financeiras', 'documento')
                ? 'm.documento' : 'NULL AS documento';
            // 'fazenda_id' nasce na migration 212 — mesmo cinto de segurança;
            // campos NOVOS na resposta (id + nome) não quebram o contrato do app
            $temFaz  = vero_has_column('movimentacoes_financeiras', 'fazenda_id');
            $colFaz  = $temFaz
                ? 'm.fazenda_id, fz.nome AS fazenda'
                : 'NULL AS fazenda_id, NULL AS fazenda';
            $joinFaz = $temFaz
                ? 'LEFT JOIN agro_fazendas fz ON fz.id = m.fazenda_id AND fz.tenant_id = m.tenant_id'
                : '';
            $sql = "SELECT m.id, m.tipo, m.status, m.descricao, {$colDoc}, m.valor,
                           m.data_vencimento, m.data_pagamento, m.origem_tipo,
                           m.fornecedor_id, f.nome AS fornecedor, {$colFaz}, m.updated_at
                      FROM movimentacoes_financeiras m
                 LEFT JOIN fornecedores f ON f.id = m.fornecedor_id AND f.tenant_id = m.tenant_id
                 {$joinFaz}
                     WHERE m.tenant_id = :t AND m.tipo IN ({$inTipos})
                       AND m.status <> 'cancelado'
                       AND (m.data_vencimento IS NULL OR m.data_vencimento >= CURDATE() - INTERVAL 90 DAY)
                     ORDER BY m.data_vencimento IS NULL, m.data_vencimento, m.id DESC
                     LIMIT 500";
            api_ok(['itens' => vero_rows($sql, $p)]);
        }

        /* ── COLHEITA: cargas/romaneios (RETRATO — view + anti-duplicado) ──
           Últimas cargas do tenant: alimenta a lista da tela e deixa o app
           avisar sobre romaneio repetido antes de enviar. Colunas mais novas
           (destino/apontamento) protegidas por vero_has_column. */
        case 'cargas_colheita': {
            api_exigir('agro.romaneios_colheita.ver');
            $colDestino = vero_has_column('colheita_cargas', 'destino')
                ? 'c.destino' : 'NULL AS destino';
            $colUn = vero_has_column('colheita_cargas', 'unidade_apont')
                ? 'c.unidade_apont, c.qtd_apont, c.caixas_por_palete'
                : 'NULL AS unidade_apont, NULL AS qtd_apont, NULL AS caixas_por_palete';
            $sql = "SELECT c.id, c.romaneio, c.talhao_id, c.registro_id, c.safra_talhao_id,
                           c.data_carga, c.peso_kg, c.classificacao, {$colDestino}, {$colUn},
                           c.origem, c.updated_at,
                           tl.codigo AS talhao, fz.nome AS fazenda
                      FROM colheita_cargas c
                 LEFT JOIN agro_talhoes tl ON tl.id = c.talhao_id
                 LEFT JOIN agro_fazendas fz ON fz.id = tl.fazenda_id
                     WHERE c.tenant_id = :t
                       AND c.data_carga >= CURDATE() - INTERVAL 60 DAY
                     ORDER BY c.data_carga DESC, c.id DESC
                     LIMIT 500";
            api_ok(['itens' => vero_rows($sql, [':t' => $tenant])]);
        }

        default:
            api_erro('modulo_desconhecido', "Módulo de sync '{$modulo}' não existe.", 404);
    }
}

/** GET /mip/alvos/{id}/produtos — produtos indicados pelo RT para um alvo,
 *  com o saldo em estoque de cada um (o app mostra antes da pulverização). */
function rota_mip_alvo_produtos(array $usuario, string $alvoId): never
{
    api_exigir('mip.ver'); // mesmo guard do sync mip_recebidos (espírito PT-02)

    $tenant = vero_tenant();
    $alvo   = (int)$alvoId; // a rota já garante \d+, o cast é cinto de segurança

    // Tabela nasce na migration 146: em banco defasado, lista vazia.
    if (!vero_has_column('mip_alvo_produtos', 'alvo_id')) {
        api_ok(['itens' => []]);
    }

    $sql = "SELECT ap.produto_id, p.nome, ap.dose, ap.dose_unidade, ap.volume_calda_ha,
                   COALESCE(m.saldo, 0) AS saldo
              FROM mip_alvo_produtos ap
              JOIN estoque_produtos p ON p.id = ap.produto_id
              LEFT JOIN (" . sync_sql_saldo() . ") m ON m.produto_id = ap.produto_id
             WHERE ap.alvo_id = :alvo AND ap.tenant_id = :t AND ap.ativo = 1
             ORDER BY p.nome";
    api_ok(['itens' => vero_rows($sql, [':alvo' => $alvo, ':t' => $tenant, ':t2' => $tenant])]);
}
