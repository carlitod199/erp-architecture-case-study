<?php
declare(strict_types=1);
/* ============================================================
   VERO — A1-57: importador de mapa (KML / KMZ / GeoJSON) → GeoJSON.
   Converte polígonos de um arquivo em geometrias GeoJSON (Polygon/
   MultiPolygon) associadas a um NOME (para casar com o código/nome da
   válvula). Escreve em agro_talhoes.geometria (D8).

   ⚠ SECURITY REVIEW OBRIGATÓRIA (CSO) — lição PT-01 (upload→RCE):
   - XML parseado com XXE OFF: DOCTYPE REJEITADO (bloqueia entidades
     externas / billion-laughs) + LIBXML_NONET (sem rede); NUNCA
     LIBXML_NOENT (não substitui entidades). libxml 2.9+ (PHP 8.3) já
     desabilita entidade externa por padrão — a rejeição de DOCTYPE é
     defesa em profundidade.
   - KMZ (zip): entradas lidas da MEMÓRIA (getFromIndex), NUNCA extraídas
     para disco → sem path traversal; só entradas .kml; guarda de tamanho
     por entrada e de contagem (anti zip-bomb).
   - Limite de tamanho e allowlist de extensão validados aqui e no chamador.
   - NENHUM conteúdo do arquivo é executado, incluído ou ecoado como HTML.
   ============================================================ */

const MAPA_IMPORT_MAX_BYTES = 5_242_880;                 // 5 MB por arquivo/entrada
const MAPA_IMPORT_EXT       = ['kml', 'kmz', 'geojson', 'json'];
const MAPA_IMPORT_MAX_FEATURES = 2000;                   // teto de polígonos por importação

/** Parseia XML de KML de forma XXE-safe. Retorna SimpleXMLElement ou null. */
function mapa_xml_safe(string $xml): ?SimpleXMLElement
{
    /* rejeita DOCTYPE — corta XXE e expansão de entidades (billion laughs) */
    if (preg_match('/<!DOCTYPE/i', $xml)) return null;
    $prev = libxml_use_internal_errors(true);
    /* LIBXML_NONET: sem acesso a rede. SEM LIBXML_NOENT (não expandir entidades). */
    $sx = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $sx instanceof SimpleXMLElement ? $sx : null;
}

/** "lng,lat[,alt] lng,lat[,alt] ..." → [[lng,lat], ...] (float, validados). */
function mapa_kml_coords(string $s): array
{
    $ring = [];
    foreach (preg_split('/\s+/', trim($s)) ?: [] as $tuple) {
        if ($tuple === '') continue;
        $p = explode(',', $tuple);
        if (count($p) < 2) continue;
        $lng = (float)$p[0];
        $lat = (float)$p[1];
        if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) continue; // coord plausível
        $ring[] = [$lng, $lat];
    }
    return $ring;
}

/**
 * Sanea recursivamente coordenadas GeoJSON: toda folha [num,num] vira
 * [float,float] validada (±180/±90); qualquer conteúdo não-numérico (ex.:
 * string com HTML) invalida a geometria inteira → retorna null. Impede que
 * texto do arquivo sobreviva até o render do mapa (defesa anti-XSS/injeção).
 */
function mapa_coords_sanear($node)
{
    if (!is_array($node) || $node === []) return null;
    /* folha = par de números (elemento 0 não é array) */
    if (!is_array($node[0] ?? null)) {
        if (!isset($node[0], $node[1]) || !is_numeric($node[0]) || !is_numeric($node[1])) return null;
        $lng = (float)$node[0];
        $lat = (float)$node[1];
        if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) return null;
        return [$lng, $lat];
    }
    $out = [];
    foreach ($node as $child) {
        $s = mapa_coords_sanear($child);
        if ($s === null) return null;
        $out[] = $s;
    }
    return $out ?: null;
}

/** Fecha o anel se necessário (GeoJSON exige o 1º ponto == último). */
function mapa_fecha_anel(array $ring): array
{
    $n = count($ring);
    if ($n >= 3 && ($ring[0][0] !== $ring[$n - 1][0] || $ring[0][1] !== $ring[$n - 1][1])) {
        $ring[] = $ring[0];
    }
    return $ring;
}

/** KML → [ ['nome'=>string, 'geometry'=>['type'=>..,'coordinates'=>..]], ... ]. */
function mapa_parse_kml(string $xml): array
{
    $sx = mapa_xml_safe($xml);
    if (!$sx) return [];
    $out = [];
    /* Placemark pode estar em qualquer profundidade → XPath sem namespace-prefix */
    $sx->registerXPathNamespace('k', 'http://www.opengis.net/kml/2.2');
    $placemarks = $sx->xpath('//*[local-name()="Placemark"]') ?: [];
    foreach ($placemarks as $pm) {
        if (count($out) >= MAPA_IMPORT_MAX_FEATURES) break;
        $nome = trim((string)(($pm->xpath('.//*[local-name()="name"]')[0] ?? '')));
        $polys = $pm->xpath('.//*[local-name()="Polygon"]') ?: [];
        $polyCoords = [];
        foreach ($polys as $poly) {
            /* outerBoundaryIs → LinearRing → coordinates (aninhado); ignora furos p/ simplicidade */
            $outer = $poly->xpath('.//*[local-name()="outerBoundaryIs"]//*[local-name()="coordinates"]')
                     ?: $poly->xpath('.//*[local-name()="coordinates"]');
            if (!$outer) continue;
            $ring = mapa_fecha_anel(mapa_kml_coords((string)$outer[0]));
            if (count($ring) >= 4) $polyCoords[] = [$ring];
        }
        if (!$polyCoords) continue;
        $geometry = count($polyCoords) === 1
            ? ['type' => 'Polygon', 'coordinates' => $polyCoords[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polyCoords];
        $out[] = ['nome' => $nome, 'geometry' => $geometry];
    }
    return $out;
}

/** GeoJSON (FeatureCollection | Feature | geometry) → mesma lista. */
function mapa_parse_geojson(string $text): array
{
    $j = json_decode($text, true);
    if (!is_array($j)) return [];
    $feats = [];
    $tipo = (string)($j['type'] ?? '');
    if ($tipo === 'FeatureCollection' && isset($j['features']) && is_array($j['features'])) $feats = $j['features'];
    elseif ($tipo === 'Feature') $feats = [$j];
    elseif (in_array($tipo, ['Polygon', 'MultiPolygon'], true)) $feats = [['type' => 'Feature', 'geometry' => $j, 'properties' => []]];
    else return [];

    $out = [];
    foreach ($feats as $f) {
        if (count($out) >= MAPA_IMPORT_MAX_FEATURES) break;
        if (!is_array($f)) continue;
        $geom = $f['geometry'] ?? null;
        if (!is_array($geom) || !in_array(($geom['type'] ?? ''), ['Polygon', 'MultiPolygon'], true)) continue;
        if (!isset($geom['coordinates']) || !is_array($geom['coordinates'])) continue;
        /* saneia coordenadas p/ float validado — descarta se houver conteúdo não-numérico */
        $coords = mapa_coords_sanear($geom['coordinates']);
        if ($coords === null) continue;
        $props = is_array($f['properties'] ?? null) ? $f['properties'] : [];
        $nome  = trim((string)($props['name'] ?? $props['nome'] ?? $props['codigo'] ?? $props['NAME'] ?? $props['Name'] ?? ''));
        /* mantém só type+coordinates saneadas (descarta propriedades arbitrárias do arquivo) */
        $out[] = ['nome' => $nome, 'geometry' => ['type' => $geom['type'], 'coordinates' => $coords]];
    }
    return $out;
}

/** KMZ (zip) → conteúdo do primeiro .kml (memória; sem extrair p/ disco). */
function mapa_kmz_extract_kml(string $tmpPath): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $z = new ZipArchive();
    if ($z->open($tmpPath) !== true) return null;
    if ($z->numFiles > 500) { $z->close(); return null; } // anti zip-bomb (contagem)
    $kml = null;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $name = (string)$z->getNameIndex($i);
        if (!preg_match('/\.kml$/i', $name)) continue;
        $stat = $z->statIndex($i);
        if ($stat && ($stat['size'] ?? 0) > 0 && $stat['size'] <= MAPA_IMPORT_MAX_BYTES) {
            $data = $z->getFromIndex($i, MAPA_IMPORT_MAX_BYTES);
            if ($data !== false) $kml = $data;
        }
        break; // doc.kml é o principal
    }
    $z->close();
    return $kml;
}

/**
 * Orquestra o parse por extensão. $content = bytes do arquivo (kml/geojson);
 * $tmpPath = caminho do upload (necessário só p/ KMZ/zip). Retorna a lista de
 * features {nome, geometry} — sempre saneada (só type+coordinates).
 */
function mapa_import_parse(string $ext, string $content, ?string $tmpPath): array
{
    $ext = strtolower($ext);
    if ($ext === 'kmz') {
        if ($tmpPath === null) return [];
        $kml = mapa_kmz_extract_kml($tmpPath);
        return $kml !== null ? mapa_parse_kml($kml) : [];
    }
    if ($ext === 'kml') return mapa_parse_kml($content);
    if ($ext === 'geojson' || $ext === 'json') return mapa_parse_geojson($content);
    return [];
}

/**
 * Área geodésica (esférica) de uma geometria GeoJSON em HECTARES.
 * Fórmula do excesso esférico (mesma do Google Maps computeArea / Leaflet):
 * soma sobre as arestas do anel de Δlon * (2 + senφ1 + senφ2), × R²/2.
 * Trata Feature/Polygon/MultiPolygon; subtrai furos (anéis internos).
 * Retorna null se não for polígono válido. Precisão boa p/ talhões (<< 1%).
 */
function mapa_area_ha_geojson($geom): ?float
{
    if (!is_array($geom)) return null;
    if (($geom['type'] ?? '') === 'Feature') $geom = $geom['geometry'] ?? null;
    if (!is_array($geom)) return null;
    $type   = (string)($geom['type'] ?? '');
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords)) return null;

    $R = 6378137.0; // raio equatorial WGS84 (m)
    $ringArea = static function (array $ring) use ($R): float {
        $n = count($ring);
        if ($n < 3) return 0.0;
        $a = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $p1 = $ring[$i];
            $p2 = $ring[($i + 1) % $n];
            if (!isset($p1[0], $p1[1], $p2[0], $p2[1]) || !is_numeric($p1[0]) || !is_numeric($p1[1]) || !is_numeric($p2[0]) || !is_numeric($p2[1])) return 0.0;
            $a += deg2rad((float)$p2[0] - (float)$p1[0])
                * (2 + sin(deg2rad((float)$p1[1])) + sin(deg2rad((float)$p2[1])));
        }
        return abs($a * $R * $R / 2.0);
    };
    $polyArea = static function (array $poly) use ($ringArea): float {
        if (!$poly || !is_array($poly[0] ?? null)) return 0.0;
        $area = $ringArea($poly[0]);                      // anel externo
        for ($i = 1, $c = count($poly); $i < $c; $i++) {  // furos
            if (is_array($poly[$i])) $area -= $ringArea($poly[$i]);
        }
        return max(0.0, $area);
    };

    $m2 = 0.0;
    if ($type === 'Polygon') {
        $m2 = $polyArea($coords);
    } elseif ($type === 'MultiPolygon') {
        foreach ($coords as $poly) { if (is_array($poly)) $m2 += $polyArea($poly); }
    } else {
        return null;
    }
    if ($m2 <= 0) return null;
    return round($m2 / 10000.0, 2); // m² → hectares
}

/**
 * Casa cada feature (por NOME) a uma válvula do tenant (código primeiro, depois
 * nome) e grava a geometria. $modo: 'so_vazias' (não sobrescreve mapa existente)
 * ou 'sobrescrever'. Escrita tenant-scoped (vero_update). Chamar em transação.
 * Ao gravar o polígono, RECALCULA a área da válvula (area_ha) e sincroniza a
 * válvula-espelho no modo unificado. Retorna relatório
 * ['casados','ignoradas','sem_nome','sem_match'=>[]].
 */
function mapa_import_aplicar(array $feats, string $modo = 'so_vazias'): array
{
    $rep = ['casados' => 0, 'ignoradas' => 0, 'sem_nome' => 0, 'sem_match' => []];
    $t = vero_tenant();

    /* X-01: antes o casamento era EXATO (codigo/nome) — nomes do
       Google Earth ("5e", " 5E ", "5-E") não batiam e os polígonos sumiam em
       silêncio, dando a impressão de "só 1 de 4". Agora carrega as válvulas 1x e
       casa por chave NORMALIZADA (minúsculo, sem acento, só alfanumérico).
       Cada polígono vai para UMA válvula distinta — se dois polígonos casam a
       mesma válvula, o 2º é reportado como conflito (não sobrescreve o 1º). */
    $norm = static function (string $s): string {
        $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = strtolower($s);
        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    };
    /* BUG 24/07: casar SOMENTE com válvulas ATIVAS. O mapa e o seletor de
       desenho já listam apenas ativas (WHERE ativo=1); a matriz aqui não
       filtrava e, quando existia um código duplicado INATIVO (ex.: um "5A"
       antigo desativado), ele "roubava" o casamento e o polígono ia para a
       válvula que não aparece no mapa — a importação dizia "1 atualizada" mas
       nada surgia ("importar não funciona"). Ordena por id p/ determinismo. */
    $vs = vero_rows(
        "SELECT id, codigo, nome, geometria FROM agro_talhoes WHERE tenant_id=:t AND ativo=1 ORDER BY id",
        [':t' => $t]);
    $idx = [];   // chave normalizada => linha da válvula
    /* prioridade: 1ª passada casa por CÓDIGO (todas), 2ª só preenche chaves que
       sobraram por NOME — assim um nome "5A" nunca ganha de um código "5A". */
    foreach (['codigo', 'nome'] as $campo) {
        foreach ($vs as $v) {
            $k = $norm((string)($v[$campo] ?? ''));
            if ($k !== '' && !isset($idx[$k])) $idx[$k] = $v;
        }
    }
    $usados = [];   // id de válvula já casada nesta importação (evita 2 polígonos → 1 válvula)
    foreach ($feats as $ft) {
        $nome = trim((string)($ft['nome'] ?? ''));
        $geom = $ft['geometry'] ?? null;
        if ($nome === '' || !is_array($geom)) { $rep['sem_nome']++; continue; }
        $tal = $idx[$norm($nome)] ?? null;
        if (!$tal) { $rep['sem_match'][] = $nome; continue; }
        $vid = (int)$tal['id'];
        if (isset($usados[$vid])) { $rep['sem_match'][] = $nome . ' (conflito: já casou com outro polígono)'; continue; }
        if ($modo !== 'sobrescrever' && !empty($tal['geometria'])) { $rep['ignoradas']++; continue; }

        /* grava o polígono e RECALCULA a área da válvula pelo próprio polígono */
        $upd = ['geometria' => json_encode($geom, JSON_UNESCAPED_UNICODE)];
        $areaHa = mapa_area_ha_geojson($geom);
        if ($areaHa !== null) $upd['area_ha'] = $areaHa;
        vero_update('agro_talhoes', $vid, $upd);

        /* modo unificado: mantém a válvula-espelho (agro_setores) com a nova área */
        if (function_exists('vero_a1_valvula_unificada') && vero_a1_valvula_unificada()) {
            $row = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t", [':i' => $vid, ':t' => $t]);
            if ($row) vero_a1_sync_espelho($row);
        }

        $usados[$vid] = true;
        $rep['casados']++;
        if ($areaHa !== null) $rep['area_recalc'] = ($rep['area_recalc'] ?? 0) + 1;
    }
    return $rep;
}
