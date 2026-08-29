---
slug: cadastrar-alvo-de-controle
titulo: "Cadastrar um alvo de controle com nível de ação"
tipo: FAZER
modulo: mip
objetivo: cadastrar
jornada: [safra]
nivel: iniciante
duracao_seg: 145
rotas:
  - rota: /mip/alvos_controle.php
    principal: true
tela_app: alvo_controle
permissoes: [mip.alvos_controle.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [encarregado]
relacoes:
  proximo: [registrar-monitoramento-mip]
  relacionado: [cadastrar-ponto-de-amostragem]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O alvo de controle é o catálogo de pragas, doenças e plantas daninhas que você monitora. O que dá vida a ele é o nível de ação: o limiar que, atingido no monitoramento, dispara o alerta fitossanitário. Sem cadastrar o alvo e seu nível, o monitoramento não tem contra o que comparar a leitura.

## Como fazer
1. Abra **Alvos de Controle** e clique em novo alvo.
2. Informe o **nome** e escolha o **tipo** (praga, doença ou planta daninha).
3. Defina o **nível de ação** — o limiar (maior que zero) que aciona o alerta quando o índice o alcança.
4. Se aplicável, escolha a cultura a que o alvo se refere.
5. Clique em **Salvar** — o alvo passa a estar disponível para os monitoramentos e para o nível de infestação.

## Erros comuns
- **Salvar sem o nível de ação ou com zero.** O nível é obrigatório e precisa ser maior que zero — é ele que dispara o alerta. Sem um limiar válido o alvo não serve ao monitoramento.
- **Repetir um alvo já existente no mesmo tipo.** Não pode haver dois alvos com o mesmo nome no mesmo tipo. O sistema avisa e não grava o duplicado.
- **Tentar excluir um alvo já usado.** Se houver monitoramentos ligados a ele, o alvo é inativado em vez de excluído, para preservar o histórico. Use a inativação quando quiser tirá-lo de circulação.

## Antes e depois
Antes: Definir com o RT quais pragas e doenças acompanhar e seus limiares. Depois: Registrar os monitoramentos MIP que leem o índice contra esse nível.

## Roteiro do vídeo
- Abrir Alvos de Controle com o catálogo existente e o filtro por tipo.
- Criar um novo alvo, preencher nome e tipo.
- Destacar o campo **Nível de ação** e narrar: "É esse número que acende o alerta."
- Clicar em **Salvar** e mostrar o alvo na lista.
- Mostrar rapidamente o Nível de Infestação usando esse limiar.
