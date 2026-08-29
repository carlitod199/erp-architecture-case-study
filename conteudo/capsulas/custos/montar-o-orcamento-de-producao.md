---
slug: montar-o-orcamento-de-producao
titulo: "Montar o orçamento de produção da safra"
tipo: FAZER
modulo: custos
objetivo: planejar
jornada: [safra]
nivel: avancado
duracao_seg: 180
rotas:
  - rota: /custeio/orcamento_producao.php
    principal: true
tela_app: orcamento_producao
permissoes: [custeio.orcamento_producao.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono, encarregado]
relacoes:
  prerequisito: [configurar-a-metodologia-de-custeio, parametrizar-a-cultura-da-safra]
  relacionado: [montar-o-orcamento-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O orçamento de produção é o planejamento detalhado do custo, item por item, seguindo a metodologia de custeio. Diferente do orçamento simples (só o total por categoria), aqui o VERO gera os itens a partir da metodologia e calcula os indicadores — custo por hectare, custo por kg e o ponto de equilíbrio — a partir da produtividade e do preço previstos.

## Como fazer
1. Abra **Orçamento de Produção** e clique em **+ Novo** para iniciar o assistente.
2. No cabeçalho, escolha **cultura, safra, fazenda e metodologia** e informe a **área** — o VERO já puxa a produtividade, o preço e a área dos parâmetros da cultura.
3. Confirme para **gerar os itens da metodologia** — cada grupo e item aparece numa tabela editável.
4. Ajuste os **valores previstos** item a item; os indicadores recalculam conforme você edita.
5. Peça a **aprovação** — este é o passo que trava o orçamento como plano oficial, e só **gestor ou administrador** pode aprovar.

## Erros comuns
- **Montar sem os parâmetros da cultura prontos.** Sem produtividade e preço previstos, os indicadores saem vazios ou errados. Parametrize a cultura antes.
- **Esperar ver a margem sem permissão financeira.** A margem só aparece para quem enxerga o financeiro (gestor, financeiro, contador) — operador e consulta não veem, e isso é proposital.
- **Achar que salvar aprova.** Salvar guarda os valores; a **aprovação** é um passo à parte, restrito a gestor/administrador.

## Antes e depois
Antes: Configurar a metodologia e parametrizar a cultura da safra. Depois: Fechar a safra e confrontar o previsto com o custo apurado.

## Roteiro do vídeo
- Abrir Orçamento de Produção e iniciar o assistente.
- Escolher cultura/safra/fazenda e mostrar os defaults sendo puxados.
- Gerar os itens e editar dois valores, narrando o custo/ha recalculando.
- Aprovar com um usuário gestor e mostrar o orçamento travado.
