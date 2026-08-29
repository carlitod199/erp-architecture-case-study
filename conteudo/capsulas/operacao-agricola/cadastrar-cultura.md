---
slug: cadastrar-cultura
titulo: "Cadastrar uma cultura"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: iniciante
duracao_seg: 120
rotas:
  - rota: /agro/culturas.php
    principal: true
permissoes: [agro.culturas.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado, operador]
relacoes:
  proximo: [cadastrar-variedade]
  relacionado: [cadastrar-estagio-fenologico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A cultura é a base de quase tudo no agrícola: o vínculo Safra × Válvula exige uma cultura, e ela define a unidade de produtividade e se a colheita vira entrada no estoque. Cadastre-a antes das variedades e das safras.

## Como fazer
1. Abra **Culturas** e clique em **+ Nova cultura**.
2. Informe o **nome** (ex.: Uva, Manga) — obrigatório e único por tenant.
3. Escolha a **unidade de produtividade** (kg/ha, t/ha, sacas/ha, @/ha ou L/ha).
4. Se a colheita desta cultura deve entrar no estoque, selecione o **produto** e o **almoxarifado** de destino; deixe vazio se a colheita não gera entrada.
5. Marque se **exige classificação** e clique em **Salvar**.

## Erros comuns
- **Repetir o nome de uma cultura ativa.** O sistema recusa nomes duplicados entre culturas ativas ("Já existe a cultura").
- **Esperar a colheita entrar no estoque sem configurar o produto.** Sem produto e almoxarifado de colheita definidos aqui, a colheita registra a produção mas NÃO movimenta estoque.
- **Cadastrar variedade no lugar da cultura.** Aqui é o nível cultura; o detalhe de cada variedade fica no módulo Variedades.

## Antes e depois
Antes: Definir a fazenda e as unidades produtivas. Depois: Cadastrar as variedades desta cultura.

## Roteiro do vídeo
- Abrir Culturas e clicar em + Nova cultura.
- Nomear a cultura, escolher a unidade de produtividade.
- Mostrar a configuração de produto/almoxarifado da colheita e salvar.
