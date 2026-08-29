<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_conteudo.php
   Rotina de publicação da Universidade (T2): markdown + frontmatter
   → tabelas uni_* (banco separado, via uni_pdo()).

   Fonte única = arquivo markdown versionado (Regra de Ouro nº 6).
   O banco é só destino de publicação. Republicar é idempotente:
   as tabelas-filhas são regravadas (delete+insert) e uni_publicacao
   só ganha linha nova quando o conteúdo muda (hash).

   Sem dependência externa (Regra nº 8): parser mínimo do subconjunto
   YAML usado no frontmatter da cápsula (escalares, listas inline
   [a,b] e em bloco, mapas aninhados e sequência de mapas).
   ============================================================ */

/* ─────────────────── Frontmatter + corpo ─────────────────── */

/** Separa o frontmatter (entre ---) do corpo markdown. */
function uni_front_parse(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // remove BOM
    if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $raw, $m)) {
        return ['front' => uni_yaml_min($m[1]), 'corpo' => trim($m[2])];
    }
    return ['front' => [], 'corpo' => trim($raw)];
}

/* ─────────────────── Parser YAML mínimo ─────────────────── */

/** Converte o subconjunto YAML do frontmatter em array PHP. */
function uni_yaml_min(string $yaml): array
{
    $toks = [];
    foreach (preg_split('/\R/', $yaml) as $ln) {
        if (trim($ln) === '' || preg_match('/^\s*#/', $ln)) continue;
        $indent = strlen($ln) - strlen(ltrim($ln, ' '));
        $toks[] = ['indent' => $indent, 'text' => rtrim(ltrim($ln, ' '))];
    }
    $i = 0;
    $r = uni_yaml_block($toks, $i, 0);
    return is_array($r) ? $r : [];
}

/** Bloco (mapa ou sequência) no nível de indentação $indent. */
function uni_yaml_block(array &$toks, int &$i, int $indent): mixed
{
    $n = count($toks);

    /* Sequência: itens '- ...' no mesmo indent. */
    if ($i < $n && $toks[$i]['indent'] === $indent && str_starts_with($toks[$i]['text'], '- ')) {
        $seq = [];
        while ($i < $n && $toks[$i]['indent'] === $indent && str_starts_with($toks[$i]['text'], '- ')) {
            $rest = substr($toks[$i]['text'], 2);
            if (preg_match('/^[A-Za-z0-9_]+:(\s|$)/', $rest)) {
                /* Item = mapa. Primeira chave vem na linha do '-'; as demais
                   ficam indentadas em $indent+2. */
                $map = [];
                [$k, $v] = uni_yaml_kv($rest);
                $map[$k] = $v !== '' ? uni_yaml_scalar($v) : null;
                $i++;
                $childIndent = $indent + 2;
                while ($i < $n && $toks[$i]['indent'] >= $childIndent && !str_starts_with($toks[$i]['text'], '- ')) {
                    [$k2, $v2] = uni_yaml_kv($toks[$i]['text']);
                    if ($v2 !== '') { $map[$k2] = uni_yaml_scalar($v2); $i++; }
                    else { $i++; $map[$k2] = ($i < $n && $toks[$i]['indent'] > $childIndent)
                        ? uni_yaml_block($toks, $i, $toks[$i]['indent']) : null; }
                }
                $seq[] = $map;
            } else {
                $seq[] = uni_yaml_scalar($rest);
                $i++;
            }
        }
        return $seq;
    }

    /* Mapa. */
    $map = [];
    while ($i < $n && $toks[$i]['indent'] === $indent) {
        [$k, $v] = uni_yaml_kv($toks[$i]['text']);
        $i++;
        if ($v !== '') {
            $map[$k] = uni_yaml_scalar($v);
        } elseif ($i < $n && $toks[$i]['indent'] > $indent) {
            $map[$k] = uni_yaml_block($toks, $i, $toks[$i]['indent']);
        } else {
            $map[$k] = null;
        }
    }
    return $map;
}

/** Divide "chave: valor" pela primeira ':'. */
function uni_yaml_kv(string $line): array
{
    $p = strpos($line, ':');
    if ($p === false) return [trim($line), ''];
    return [trim(substr($line, 0, $p)), trim(substr($line, $p + 1))];
}

/** Escalar: bool, null, lista inline [a,b] ou string (aspas removidas). */
function uni_yaml_scalar(string $s): mixed
{
    $s = trim($s);
    if ($s === '' || $s === '~' || $s === 'null') return null;
    if ($s === 'true')  return true;
    if ($s === 'false') return false;
    if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
        $inner = trim(substr($s, 1, -1));
        if ($inner === '') return [];
        return array_map(fn($x) => uni_yaml_scalar(trim($x)), explode(',', $inner));
    }
    if (strlen($s) >= 2 && (($s[0] === '"' && str_ends_with($s, '"')) || ($s[0] === "'" && str_ends_with($s, "'")))) {
        return substr($s, 1, -1);
    }
    return $s;
}

/* ─────────────────── Helpers de coerção ─────────────────── */

function uni_str(mixed $v, int $max): ?string
{
    if ($v === null) return null;
    if (is_array($v)) $v = implode(',', array_map('strval', $v));
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function uni_data(mixed $v): ?string
{
    $v = trim((string)($v ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/* ─────────────────── Publicação ─────────────────── */

/**
 * Publica UMA cápsula (arquivo .md) no banco da Universidade.
 * Upsert por slug + regrava tabelas-filhas + registra uni_publicacao
 * quando o conteúdo muda. Retorna resumo.
 */
function uni_publicar_arquivo(PDO $pdo, string $caminho, ?int $publicadoPor = null): array
{
    $raw = @file_get_contents($caminho);
    if ($raw === false) {
        throw new RuntimeException("Não foi possível ler {$caminho}");
    }
    $parsed = uni_front_parse($raw);
    $fm = $parsed['front'];
    $corpo = $parsed['corpo'];

    $slug   = trim((string)($fm['slug'] ?? ''));
    $titulo = trim((string)($fm['titulo'] ?? ''));
    $tipo   = strtoupper(trim((string)($fm['tipo'] ?? '')));
    if ($slug === '' || $titulo === '' || $tipo === '') {
        throw new RuntimeException("Frontmatter incompleto (slug/titulo/tipo) em {$caminho}");
    }
    if (!in_array($tipo, ['FAZER', 'ENTENDER', 'CONSULTAR', 'PRATICAR', 'VERIFICAR'], true)) {
        throw new RuntimeException("tipo inválido '{$tipo}' em {$caminho}");
    }
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        throw new RuntimeException("slug inválido '{$slug}' (use kebab-case) em {$caminho}");
    }

    $conteudoHash = hash('sha256', $raw);
    $nivel = (string)($fm['nivel'] ?? 'iniciante');
    if (!in_array($nivel, ['iniciante', 'intermediario', 'expert'], true)) $nivel = 'iniciante';

    $cols = [
        'tenant_id'          => (isset($fm['tenant_id']) && $fm['tenant_id'] !== '') ? (int)$fm['tenant_id'] : null,
        'titulo'             => mb_substr($titulo, 0, 200),
        'tipo'               => $tipo,
        'resumo'             => uni_str($fm['resumo'] ?? null, 400),
        'corpo_md'           => $corpo,
        'modulo'             => uni_str($fm['modulo'] ?? null, 64),
        'objetivo'           => uni_str($fm['objetivo'] ?? null, 64),
        'jornada'            => uni_str($fm['jornada'] ?? null, 160),
        'nivel'              => $nivel,
        'duracao_seg'        => isset($fm['duracao_seg']) ? (int)$fm['duracao_seg'] : null,
        'status'             => 'publicado',
        'versao'             => uni_str($fm['versao'] ?? null, 20),
        'vero_versao_min'    => uni_str($fm['vero_versao_min'] ?? null, 20),
        'vero_versao_max'    => uni_str($fm['vero_versao_max'] ?? null, 20),
        'dono_email'         => uni_str($fm['dono'] ?? ($fm['dono_email'] ?? null), 190),
        'revisado_em'        => uni_data($fm['revisado_em'] ?? null),
        'proxima_revisao_em' => uni_data($fm['proxima_revisao_em'] ?? null),
        'ativo'              => 1,
    ];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM uni_capsula WHERE slug = ? LIMIT 1");
        $st->execute([$slug]);
        $existente = $st->fetch();

        if ($existente) {
            $id = (int)$existente['id'];
            $set = [];
            $vals = [];
            foreach ($cols as $k => $v) { $set[] = "{$k} = ?"; $vals[] = $v; }
            $set[] = "updated_by = ?"; $vals[] = $publicadoPor;
            $vals[] = $id;
            $pdo->prepare("UPDATE uni_capsula SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
            $acao = 'atualizada';
        } else {
            $ins = ['slug' => $slug] + $cols + ['created_by' => $publicadoPor];
            $ph = implode(', ', array_fill(0, count($ins), '?'));
            $pdo->prepare("INSERT INTO uni_capsula (" . implode(', ', array_keys($ins)) . ") VALUES ({$ph})")
                ->execute(array_values($ins));
            $id = (int)$pdo->lastInsertId();
            $acao = 'inserida';
        }

        /* Rotas (delete+insert). tela_app do frontmatter aplica a todas. */
        $pdo->prepare("DELETE FROM uni_capsula_rota WHERE capsula_id = ?")->execute([$id]);
        $telaApp = uni_str($fm['tela_app'] ?? null, 80);
        $insRota = $pdo->prepare("INSERT INTO uni_capsula_rota (capsula_id, rota, tela_app, principal) VALUES (?,?,?,?)");
        $nRotas = 0;
        foreach ((array)($fm['rotas'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $rota = trim((string)($r['rota'] ?? ''));
            if ($rota === '') continue;
            $insRota->execute([$id, mb_substr($rota, 0, 200), $telaApp, !empty($r['principal']) ? 1 : 0]);
            $nRotas++;
        }

        /* Permissões. */
        $pdo->prepare("DELETE FROM uni_capsula_permissao WHERE capsula_id = ?")->execute([$id]);
        $insPerm = $pdo->prepare("INSERT INTO uni_capsula_permissao (capsula_id, permissao_chave) VALUES (?,?)");
        foreach ((array)($fm['permissoes'] ?? []) as $p) {
            $p = trim((string)$p);
            if ($p !== '') $insPerm->execute([$id, mb_substr($p, 0, 120)]);
        }

        /* Papéis (nucleo/consulta). */
        $pdo->prepare("DELETE FROM uni_capsula_papel WHERE capsula_id = ?")->execute([$id]);
        $insPap = $pdo->prepare("INSERT INTO uni_capsula_papel (capsula_id, perfil, relevancia) VALUES (?,?,?)");
        $papeis = (array)($fm['papeis'] ?? []);
        foreach (['nucleo', 'consulta'] as $rel) {
            foreach ((array)($papeis[$rel] ?? []) as $perf) {
                $perf = trim((string)$perf);
                if ($perf !== '') $insPap->execute([$id, mb_substr($perf, 0, 40), $rel]);
            }
        }

        /* Relações (guarda slug; resolve id quando o alvo já existe). */
        $pdo->prepare("DELETE FROM uni_capsula_relacao WHERE capsula_id = ?")->execute([$id]);
        $insRel = $pdo->prepare("INSERT INTO uni_capsula_relacao (capsula_id, relacionada_slug, relacionada_id, tipo) VALUES (?,?,?,?)");
        $lookup = $pdo->prepare("SELECT id FROM uni_capsula WHERE slug = ? LIMIT 1");
        $relacoes = (array)($fm['relacoes'] ?? []);
        foreach (['prerequisito', 'proximo', 'relacionado'] as $tp) {
            foreach ((array)($relacoes[$tp] ?? []) as $rslug) {
                $rslug = trim((string)$rslug);
                if ($rslug === '') continue;
                $lookup->execute([$rslug]);
                $rid = $lookup->fetchColumn();
                $insRel->execute([$id, mb_substr($rslug, 0, 160), $rid !== false ? (int)$rid : null, $tp]);
            }
        }
        /* Backfill: liga referências de outras cápsulas que apontam p/ este slug. */
        $pdo->prepare("UPDATE uni_capsula_relacao SET relacionada_id = ? WHERE relacionada_slug = ? AND relacionada_id IS NULL")
            ->execute([$id, $slug]);

        /* uni_publicacao: registra só quando o conteúdo muda. */
        $q = $pdo->prepare("SELECT conteudo_hash FROM uni_publicacao WHERE capsula_id = ? ORDER BY id DESC LIMIT 1");
        $q->execute([$id]);
        $prev = $q->fetchColumn();
        $publicou = false;
        if ($prev === false || (string)$prev !== $conteudoHash) {
            $pdo->prepare("INSERT INTO uni_publicacao (capsula_id, versao, changelog, conteudo_hash, publicado_por) VALUES (?,?,?,?,?)")
                ->execute([$id, (string)($cols['versao'] ?? ''), $prev === false ? 'primeira publicação' : 'conteúdo alterado', $conteudoHash, $publicadoPor]);
            $publicou = true;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ['slug' => $slug, 'id' => $id, 'acao' => $acao, 'rotas' => $nRotas, 'publicacao' => $publicou];
}
