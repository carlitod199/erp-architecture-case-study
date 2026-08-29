<?php
/**
 * VERO Agro — Refinador de mock das telas-engine.
 * Substitui blocks/bars genericos por conteudo de dominio, audita nomes fracos
 * e gera relatorio. Idempotente. Faz backup antes de alterar cada arquivo.
 *
 * Uso:  php scripts/refino_mock_blocks.php          (aplica)
 *       php scripts/refino_mock_blocks.php --dry    (so relatorio, nao grava)
 *
 * Seguranca: so toca telas que usam bios_mock_screen_render. Nao altera includes,
 * menu, sidebar, header/footer, nem telas bespoke (dashboard executivo, /agro).
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$DRY  = in_array('--dry', $argv, true);
$STAMP = date('Ymd-His');
$BK = $ROOT . '/_backups_refino/' . $STAMP;

$MODULES = ['dashboard','estoque','compras','custeio','maquinas','mip','irrigacao',
            'nutricao','comercial','contratos','financeiro','fiscal','patrimonio',
            'pessoas','relatorios','configuracoes'];

/* ---------- dicionario de dominio ---------- */
$BLOCKS_MACRO = [
  'dashboard'=>[['Faturamento x meta','Receita realizada da safra confrontada com a meta orcada por fazenda e cultura.'],['Custo por hectare','Composicao do custo/ha por categoria: insumos, mecanizacao, mao de obra e irrigacao.'],['Alertas da operacao','Desvios financeiros e operacionais que exigem conferencia do gestor.']],
  'estoque'=>[['Saldo e curva ABC','Itens classe A (defensivos e fertilizantes) concentram o maior valor imobilizado.'],['Lote e validade','Rastreabilidade por lote, data de fabricacao e vencimento para defensivos e sementes.'],['Estoque minimo','Itens abaixo do ponto de reposicao por almoxarifado e fazenda.']],
  'compras'=>[['Comparativo de fornecedor','Preco, prazo de entrega e condicao de pagamento por fornecedor de insumo.'],['Alcada de aprovacao','Faixa de valor que define aprovador: ate R$ 20 mil, supervisor; acima, gestor.'],['Vinculo com estoque','Recebimento parcial/total que alimenta saldo e custo medio do almoxarifado.']],
  'custos'=>[['Orcado x realizado','Diferenca entre orcamento da safra e custo comprometido/realizado por talhao.'],['Rateio de custo','Distribuicao de custos compartilhados entre talhoes por area e operacao.'],['Margem por cultura','Receita prevista menos custo por hectare, separada por cultura e safra.']],
  'nutricao'=>[['Analise de solo','Resultados de pH, V%, P, K e materia organica por ponto de amostragem.'],['Recomendacao tecnica','Faixas e interpretacoes registradas; a decisao de dose cabe ao responsavel tecnico.'],['Conferencia de aplicacao','Cruzamento entre recomendacao registrada, produto e area prevista.']],
  'mip'=>[['Mapa de infestacao','Niveis de atencao por talhao e ponto de amostragem (alvos ficticios).'],['Janela de monitoramento','Periodicidade das vistorias por cultura e estagio fenologico.'],['Severidade','Faixas visuais para priorizar conferencia; sem recomendacao automatica de dose.']],
  'irrigacao'=>[['Lamina planejada x aplicada','Diferenca em milimetros entre lamina prevista e medida por pivo/talhao.'],['Consumo de energia','Horas de bombeamento e energia estimada por setor irrigado.'],['Janela hidrica','Balanco hidrico simulado para priorizar turnos de rega.']],
  'maquinas'=>[['Disponibilidade da frota','Maquinas disponiveis, em uso e paradas para manutencao.'],['Custo por hora','Combustivel, manutencao e depreciacao rateados por horimetro/odometro.'],['Manutencao preventiva','Proximas revisoes por horas de uso e plano do fabricante.']],
  'pessoas'=>[['Escala da equipe','Operadores alocados por frente de trabalho, fazenda e atividade.'],['Apontamento de horas','Horas previstas x realizadas e pendencias de fechamento do dia.'],['Custo de mao de obra','Valor-hora por funcao rateado para o talhao e a operacao.']],
  'comercial'=>[['Estoque de producao','Volume colhido por silo/armazem, safra e classificacao de qualidade.'],['Carteira de contratos','Contratos de venda, comprador, preco e entrega futura.'],['Logistica de saida','Romaneios, frete e janela de carregamento por comprador.']],
  'financeiro'=>[['Agenda de vencimentos','Titulos a pagar e a receber agrupados por prazo e centro de custo agricola.'],['Fluxo de caixa rural','Entradas de venda de producao x saidas de insumos e operacao.'],['DRE por safra','Resultado por safra, fazenda e cultura usando o ledger do core.']],
  'fiscal'=>[['Conferencia de XML','NF-e importada x pedido x estoque, com chave de acesso e situacao fiscal.'],['Livro caixa produtor','Entradas e saidas do produtor rural para visao do contador.'],['Arquivo documental','Guarda de XML, PDF e comprovantes vinculados ao lancamento.']],
  'patrimonio'=>[['Inventario de ativos','Maquinas, terras, benfeitorias e implementos com valor de aquisicao.'],['Depreciacao gerencial','Valor atual e depreciacao acumulada (gerencial, mockada) por ativo.'],['Vinculo com fazenda','Ativo ligado a fazenda, centro de custo e responsavel.']],
  'relatorios'=>[['Filtros salvos','Periodo, fazenda, safra e cultura como recortes reutilizaveis.'],['Indicadores-chave','Produtividade, custo/ha e margem consolidados para diretoria.'],['Exportacao','Pacotes em PDF e planilha com dados ilustrativos.']],
  'configuracoes'=>[['Catalogos padrao','Culturas, unidades de medida e categorias de custo da operacao.'],['Parametros de orcamento','Bases para custo/ha, produtividade e fechamento de safra.'],['Permissoes agro','Perfis de acesso por modulo, fazenda e funcao.']],
  'agricola'=>[['Talhoes e safra','Area em hectares, cultura atual e safra vinculada por talhao.'],['Janela operacional','Periodo sugerido para planejar, executar e conferir apontamentos.'],['Produtividade','Produtividade planejada x realizada em t/ha ou sacas/ha.']],
];
$BARS_MACRO = [
  'dashboard'=>[['Receita realizada',78,'78% da meta'],['Custo comprometido',64,'64% do orcado'],['Margem prevista',31,'31%']],
  'estoque'=>[['Classe A (valor)',71,'71%'],['Itens em validade ok',86,'86%'],['Abaixo do minimo',12,'12%']],
  'compras'=>[['Dentro do orcamento',82,'82%'],['Recebido total',58,'58%'],['Aguardando aprovacao',23,'23%']],
  'custos'=>[['Realizado x orcado',67,'67%'],['Insumos',44,'44%'],['Mecanizacao',28,'28%']],
  'nutricao'=>[['Pontos analisados',74,'74%'],['Dentro da faixa',61,'61%'],['Aguardando laudo',18,'18%']],
  'mip'=>[['Talhoes em nivel ok',69,'69%'],['Em atencao',24,'24%'],['Vistorias no prazo',88,'88%']],
  'irrigacao'=>[['Lamina atingida',81,'81%'],['Eficiencia energetica',73,'73%'],['Deficit hidrico',16,'16%']],
  'maquinas'=>[['Frota disponivel',76,'76%'],['Em manutencao',14,'14%'],['Preventivas no prazo',64,'64%']],
  'pessoas'=>[['Horas realizadas',79,'79%'],['Frentes cobertas',85,'85%'],['Pendente fechamento',21,'21%']],
  'comercial'=>[['Volume contratado',62,'62%'],['Entregue',47,'47%'],['Disponivel em estoque',38,'38%']],
  'financeiro'=>[['A receber no prazo',72,'72%'],['A pagar no prazo',66,'66%'],['Conciliado',89,'89%']],
  'fiscal'=>[['XML conferido',84,'84%'],['Conciliado c/ pedido',71,'71%'],['Pendente',19,'19%']],
  'patrimonio'=>[['Ativos auditados',77,'77%'],['Depreciacao no plano',68,'68%'],['Sem vinculo',11,'11%']],
  'relatorios'=>[['Indicadores prontos',80,'80%'],['Exportados',55,'55%'],['Em revisao',22,'22%']],
  'configuracoes'=>[['Catalogos ativos',90,'90%'],['Parametros revisados',70,'70%'],['Pendente auditoria',17,'17%']],
  'agricola'=>[['Area planejada',83,'83%'],['Operacao concluida',59,'59%'],['Em execucao',27,'27%']],
];
/* nomes de produto realistas (substitui placeholders fracos) */
$NAME_FIX = [
  'Semente cultivar mock' => 'Semente Soja BRS 7980 RR',
  'BioDefensivo Alfa'     => 'Herbicida Glifosato 480 SL',
  'Corretivo SoloMax'     => 'Calcário Dolomítico PRNT 90%',
];

function emit_blocks(array $items): string {
  $s = "\$mockBlocks = array (\n";
  foreach ($items as $i=>$it){ $s.="  $i => \n  array (\n    'title' => '".addslashes($it[0])."',\n    'desc' => '".addslashes($it[1])."',\n  ),\n"; }
  return $s.');';
}
function emit_bars(array $items): string {
  $s = "\$mockBars = array (\n";
  foreach ($items as $i=>$it){ $s.="  $i => \n  array (\n    'label' => '".addslashes($it[0])."',\n    'percent' => ".(int)$it[1].",\n    'value' => '".addslashes($it[2])."',\n  ),\n"; }
  return $s.');';
}
function replace_block(string $src, string $var, string $new, bool &$ok): string {
  $ok=false;
  $pat = '/^\$'.preg_quote($var,'/').' = (?:array \(|\[).*?^(?:\)|\])\s*;/ms';
  if (!preg_match($pat,$src)) return $src;
  $ok=true;
  return preg_replace($pat,str_replace('\\','\\\\',$new),$src,1);
}

$alterados=[]; $backups=[]; $audit=[]; $sem=[];
foreach ($MODULES as $mod){
  $d="$ROOT/$mod"; if(!is_dir($d)) continue;
  foreach (glob("$d/*.php") as $p){
    $src=file_get_contents($p);
    if (strpos($src,'bios_mock_screen_render')===false) continue;
    preg_match("/'macro' => '([a-z_]+)'/",$src,$gm);
    $macro=$gm[1]??$mod;
    foreach ($NAME_FIX as $bad=>$good){ $src=str_replace($bad,$good,$src); }
    $new=$src; $changed=false;
    if (isset($BLOCKS_MACRO[$macro])){ $new=replace_block($new,'mockBlocks',emit_blocks($BLOCKS_MACRO[$macro]),$o); $changed=$changed||$o; }
    if (isset($BARS_MACRO[$macro])){   $new=replace_block($new,'mockBars',  emit_bars($BARS_MACRO[$macro]),$o);   $changed=$changed||$o; }
    if (!$changed && $new===$src){ $sem[]="$mod/".basename($p); continue; }
    if ($new!==$src){
      if(!$DRY){
        $bkp="$BK/$mod"; if(!is_dir($bkp)) mkdir($bkp,0775,true);
        file_put_contents("$bkp/".basename($p),$src);
        file_put_contents($p,$new);
      }
      $alterados[]="$mod/".basename($p);
      $backups[]="_backups_refino/$STAMP/$mod/".basename($p);
    }
  }
}
echo ($DRY?"[DRY] ":"")."Alterados: ".count($alterados)."\n";
echo "Backups : ".count($backups).($DRY?" (nao gravados)":" em _backups_refino/$STAMP")."\n";
echo "Sem blocks/bars: ".count($sem)."\n";
file_put_contents("$ROOT/scripts/refino_report_$STAMP.json",
  json_encode(['alterados'=>$alterados,'backups'=>$backups,'sem'=>$sem],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Relatorio: scripts/refino_report_$STAMP.json\n";
