---
slug: cadastrar-ponto-de-amostragem
titulo: "Cadastrar um ponto de amostragem na válvula"
tipo: FAZER
modulo: mip
objetivo: cadastrar
jornada: [safra]
nivel: iniciante
duracao_seg: 130
rotas:
  - rota: /mip/pontos_amostragem.php
    principal: true
tela_app: ponto_amostragem
permissoes: [mip.pontos_amostragem.editar]
papeis:
  nucleo: [encarregado, rt_gerente]
  consulta: [operador]
relacoes:
  proximo: [registrar-monitoramento-mip]
  relacionado: [cadastrar-alvo-de-controle]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Os pontos de amostragem são os lugares fixos, dentro de cada válvula, onde a equipe sempre faz a leitura do monitoramento. Cadastrá-los antes garante que as medições venham sempre dos mesmos pontos — a base para comparar a evolução da praga ao longo do tempo sem viés de local.

## Como fazer
1. Abra **Pontos de Amostragem** e clique em novo ponto.
2. Escolha a **válvula** a que o ponto pertence.
3. Dê um **nome** ao ponto (algo que a equipe reconheça no campo).
4. Se tiver, informe latitude e longitude para localizar no mapa.
5. Clique em **Salvar** — o ponto passa a ficar disponível para escolha nos monitoramentos daquela válvula.

## Erros comuns
- **Salvar sem válvula ou sem nome.** Os dois são obrigatórios: o ponto não existe solto, ele pertence a uma válvula e precisa de um nome. Preencha ambos.
- **Repetir o mesmo nome na mesma válvula.** Não pode haver dois pontos com o mesmo nome na mesma válvula. Diferencie os nomes para não confundir a equipe.
- **Tentar excluir um ponto já usado.** Se houver monitoramentos feitos nele, a exclusão é bloqueada para não quebrar o histórico. Deixe o ponto e apenas pare de usá-lo, se for o caso.

## Antes e depois
Antes: Definir no campo os locais fixos de leitura em cada válvula. Depois: Registrar os monitoramentos MIP apontando o ponto onde a leitura foi feita.

## Roteiro do vídeo
- Abrir Pontos de Amostragem, filtrando por válvula.
- Criar um novo ponto, escolher a válvula e nomear.
- Narrar: "Sempre o mesmo ponto, sempre a mesma comparação."
- Clicar em **Salvar** e mostrar o ponto na lista.
- Abrir um monitoramento e mostrar o ponto aparecendo como opção.
