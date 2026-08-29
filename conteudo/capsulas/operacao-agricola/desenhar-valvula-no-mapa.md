---
slug: desenhar-valvula-no-mapa
titulo: "Desenhar a válvula no mapa da fazenda"
tipo: FAZER
modulo: operacao-agricola
objetivo: mapear
jornada: [implantacao]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /agro/mapa.php
    principal: true
permissoes: [agro.mapa_fazenda.editar]
papeis:
  nucleo: [gestor, rt_gerente, encarregado]
  consulta: [operador, dono]
relacoes:
  prerequisito: [cadastrar-valvula]
  relacionado: [cadastrar-talhao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
No mapa você desenha o polígono de cada válvula sobre a imagem de satélite. Ao salvar a geometria, o sistema recalcula a área da válvula pelo próprio desenho — deixando a área alinhada com o que existe no campo.

## Como fazer
1. Abra **Mapa da Fazenda** e clique na válvula que quer mapear.
2. Use a ferramenta de desenho do mapa para traçar o polígono da válvula.
3. Clique em **Salvar** a geometria — o polígono é gravado e a **área é recalculada pelo desenho**.
4. Se já tiver os mapas prontos, use **Importar mapa** (KML, KMZ ou GeoJSON) para casar os polígonos por código/nome da válvula.
5. Confira a área recalculada mostrada na mensagem de confirmação.

## Erros comuns
- **Enviar um formato não suportado.** A importação aceita KML, KMZ e GeoJSON (Shapefile é fase 2) e recusa arquivos acima de 5 MB.
- **Arquivo com nome que não bate com a válvula.** A importação casa polígono com válvula pelo código/nome — os que não encontram par aparecem em "sem correspondência" e não são aplicados.
- **Esperar sobrescrever mapas existentes sem marcar a opção.** Por padrão a importação só preenche válvulas sem mapa; para trocar um mapa já existente, escolha "sobrescrever" (ou limpe os polígonos da fazenda antes de reimportar).
- **Desenhar linha em vez de área.** Só é aceito polígono (GeoJSON Polygon/MultiPolygon); geometria inválida é recusada.

## Antes e depois
Antes: Cadastrar a válvula com seu código. Depois: Usar o mapa para conferir área, alertas e custo por válvula.

## Roteiro do vídeo
- Abrir Mapa da Fazenda e clicar numa válvula.
- Desenhar o polígono e salvar; mostrar a área recalculada na mensagem.
- Demonstrar a importação de um KML e o relatório de válvulas casadas.
