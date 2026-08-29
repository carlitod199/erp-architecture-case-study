---
slug: definir-a-tabela-de-precos
titulo: "Definir a tabela de preços para a venda sugerir o preço certo"
tipo: FAZER
modulo: comercial
objetivo: cadastrar
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /comercial/tabela_precos.php
    principal: true
permissoes: [comercial.vendas.editar]
papeis:
  nucleo: [comercial, gestor]
  consulta: [dono]
relacoes:
  proximo: [registrar-uma-venda]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A tabela de preços guarda regras de preço por cultura, variedade, calibre, embalagem, comprador, canal e safra. Na hora da venda, o sistema puxa a regra vigente mais específica como sugestão — então preencher bem a tabela é o que faz o preço certo aparecer sozinho.

## Como fazer
1. Abra **Tabela de preços** e clique em **Nova regra**.
2. Informe o **preço** e o **início de vigência** (os dois obrigatórios) e escolha a **moeda**.
3. Restrinja a regra o quanto precisar: cultura, variedade, calibre, embalagem, comprador, canal e safra. Quanto mais campos preenchidos, mais específica — e ela ganha das regras genéricas.
4. Se a regra tiver validade, informe o **fim de vigência**.
5. Clique em **Salvar** — a regra passa a sugerir preço nas novas vendas que se encaixarem nela.

## Erros comuns
- **Esquecer o início de vigência.** Sem data de início a regra não é aceita — ela precisa saber a partir de quando vale.
- **Criar duas regras igualmente específicas para o mesmo caso.** O sistema escolhe a vigente mais específica; regras concorrentes confundem a sugestão. Encerre a vigência da antiga ao trocar o preço.
- **Excluir uma regra antiga achando que some o histórico.** A exclusão apenas inativa (mantém a trilha). Para mudar o preço, crie uma nova regra com nova vigência em vez de apagar a anterior.

## Antes e depois
Antes: ter os preços acordados por cultura/comprador/canal. Depois: registrar vendas já com o preço sugerido automaticamente (e ajustável).

## Roteiro do vídeo
- Abrir Tabela de preços com regras cadastradas.
- Narrar: "A tabela não trava o preço da venda — ela sugere o preço certo, e você ajusta se precisar."
- Criar uma regra específica: cultura, comprador e preço com início de vigência.
- Salvar e mostrar, numa nova venda, o preço vindo sozinho.
