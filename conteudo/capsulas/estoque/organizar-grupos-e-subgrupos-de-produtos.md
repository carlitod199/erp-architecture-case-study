---
slug: organizar-grupos-e-subgrupos-de-produtos
titulo: "Organizar produtos em grupos e subgrupos"
tipo: FAZER
modulo: estoque
objetivo: organizar
jornada: [continuo]
nivel: iniciante
duracao_seg: 130
rotas:
  - rota: /estoque/grupos_subgrupos.php
    principal: true
permissoes: [estoque.grupos_subgrupos.editar]
papeis:
  nucleo: [gestor, almoxarife, estoquista]
  consulta: [encarregado, dono]
relacoes:
  proximo: [cadastrar-um-produto-no-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Grupos e subgrupos são as gavetas do estoque: eles classificam os produtos (insumo, veterinário, peça, combustível, EPI, irrigação) para você filtrar, somar custo por categoria e achar as coisas rápido. Cadastrá-los antes deixa o cadastro de produto mais limpo e os relatórios mais úteis.

## Como fazer
1. Abra **Grupos e Subgrupos**.
2. Em **Novo grupo**, dê o **nome** e escolha o **tipo** (insumo, veterinário, peça, EPI etc.) e salve.
3. Dentro do grupo, crie os **subgrupos** para detalhar (ex.: grupo "Defensivo" → subgrupos "Fungicida", "Herbicida").
4. Ao cadastrar um produto, selecione o grupo/subgrupo criado.

## Erros comuns
- **Escolher um tipo que não existe na lista.** O tipo é validado; use apenas os previstos (insumo, veterinário, peça, combustível, EPI, irrigação, outro).
- **Tentar excluir um grupo em uso.** A exclusão é física; se houver produto ou subgrupo ligado, o sistema traduz o erro de vínculo numa mensagem — reclassifique os produtos antes de excluir.
- **Criar subgrupos genéricos demais.** Subgrupo serve para separar de verdade; "Outros" cheio não ajuda em nada nos relatórios.

## Antes e depois
Antes: Mapear as categorias de insumo que a fazenda usa. Depois: Cadastrar os produtos apontando para o grupo e o subgrupo certos.

## Roteiro do vídeo
- Abrir Grupos e Subgrupos.
- Criar um grupo "Defensivo" do tipo insumo.
- Adicionar subgrupos "Fungicida" e "Herbicida".
- Mostrar o grupo aparecendo no cadastro de produto.
