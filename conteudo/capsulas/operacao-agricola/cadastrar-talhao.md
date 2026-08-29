---
slug: cadastrar-talhao
titulo: "Completar os dados técnicos de um talhão"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /agro/talhoes.php
    principal: true
permissoes: [agro.talhoes.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado, operador]
relacoes:
  prerequisito: [cadastrar-valvula]
  relacionado: [cadastrar-area-produtiva, cadastrar-variedade, cadastrar-porta-enxerto]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Esta é a ficha técnica completa do talhão: tipo de solo, espaçamentos, data de plantio, coordenadas e a área produtiva a que ele pertence. É o cadastro canônico — a válvula-espelho herda tudo daqui.

## Como fazer
1. Abra **Talhões** (ou entre pela ficha da válvula) e clique em **Editar** no talhão.
2. Confirme fazenda e código, e vincule a **área produtiva** — ela precisa ser da mesma fazenda do talhão.
3. Escolha o **tipo de solo** na lista (catálogo fechado mantido pelo RT) e informe espaçamento de linha e de planta.
4. Preencha data de plantio, nº de plantas real, variedade e porta-enxerto.
5. Clique em **Salvar** — os dados ficam gravados e a válvula-espelho é sincronizada.

## Erros comuns
- **Tentar digitar um tipo de solo fora do catálogo.** O campo só aceita valores da lista do RT; para incluir um novo, edite o catálogo no rodapé da tela ("Catálogo de tipos de solo").
- **Escolher uma área produtiva de outra fazenda.** O sistema recusa ("a área não pertence à fazenda escolhida") — a área precisa ser da mesma fazenda.
- **Achar que aqui se desenha o mapa.** O polígono (geometria) não é editado nesta tela; ele é desenhado na tela Mapa da Fazenda.
- **Informar nº de plantas "teórico".** Use a contagem real — o parreiral tem falhas e esse número alimenta a calculadora de mão de obra.

## Antes e depois
Antes: Cadastrar a válvula/unidade produtiva. Depois: Desenhar o polígono no Mapa da Fazenda para recalcular a área pelo desenho.

## Roteiro do vídeo
- Abrir Talhões e editar um talhão existente.
- Mostrar o select de tipo de solo (catálogo) e o link do catálogo no rodapé.
- Vincular a área produtiva da mesma fazenda; preencher espaçamentos e plantio.
- Salvar e narrar que a válvula-espelho herdou os dados.
