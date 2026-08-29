---
slug: cadastrar-area-produtiva
titulo: "Cadastrar uma área produtiva"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /agro/areas_produtivas.php
    principal: true
permissoes: [agro.areas_produtivas.editar]
papeis:
  nucleo: [gestor, rt_gerente, encarregado]
  consulta: [operador, dono]
relacoes:
  proximo: [cadastrar-talhao]
  relacionado: [cadastrar-valvula]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A área produtiva é a macro-área da fazenda (ex.: Quadra Norte) que agrupa talhões. Serve para organizar e somar o parreiral por bloco, sem substituir o cadastro de cada talhão.

## Como fazer
1. Abra **Áreas Produtivas** e clique em **+ Nova área**.
2. Dê um **nome** à área (ex.: Quadra Norte) e escolha a **fazenda** — ambos obrigatórios.
3. Informe a área declarada em hectares, se quiser (opcional).
4. Clique em **Salvar** — a área passa a ficar disponível para vincular talhões.
5. O vínculo talhão → área é feito depois, no cadastro de Talhões.

## Erros comuns
- **Salvar sem escolher a fazenda.** A fazenda é obrigatória (coluna NOT NULL); sem ela o sistema recusa e a área não é criada.
- **Tentar excluir uma área com talhões.** Se houver talhão vinculado, a exclusão é bloqueada — mova os talhões para outra área antes.
- **Confundir "área declarada" com "área dos talhões".** A tela mostra as duas: a declarada é a que você digita; a dos talhões é a soma real dos talhões ativos vinculados.

## Antes e depois
Antes: Ter a fazenda cadastrada. Depois: Vincular os talhões a esta área no cadastro de Talhões.

## Roteiro do vídeo
- Abrir Áreas Produtivas e clicar em + Nova área.
- Nomear a área, escolher a fazenda e salvar.
- Mostrar as duas colunas de área (declarada x soma dos talhões) e o link para o cadastro de talhões.
