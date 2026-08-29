---
slug: consultar-aplicacoes-nutricionais
titulo: "Consultar as aplicações nutricionais da safra"
tipo: CONSULTAR
modulo: nutricao
objetivo: consultar
jornada: [safra]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /nutricao/aplicacoes_nutricionais.php
    principal: true
permissoes: [nutricao.aplicacoes_nutricionais.ver]
papeis:
  nucleo: [rt_gerente, agronomo, gestor, dono]
  consulta: [encarregado]
relacoes:
  relacionado: [consultar-fertirrigacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Esta tela é a vitrine das aplicações nutricionais — fertirrigação, adubação foliar e indutor de brotação — com produtos, dose do RT, custo e status. Ela é de leitura: o registro em si acontece no núcleo de Aplicações. Serve para acompanhar o que já foi aplicado e quanto custou.

## Como fazer
1. Abra **Aplicações Nutricionais** e, se quiser, filtre por tipo (fertirrigação, foliar, indutor).
2. Leia a lista: data, válvula, safra, produtos com a dose informada pelo RT, custo e status.
3. Para **registrar** uma nova aplicação, use o link "registrar" — a receita nasce em MIP → Aplicações (`/mip/aplicacoes.php`), escolhendo o tipo nutricional.
4. Acompanhe o status: "Aguardando RT" significa que a validação técnica ainda não saiu.

## Antes e depois
Antes: A aplicação é registrada e validada no núcleo de Aplicações. Depois: O custo aparece aqui e no Custo Realizado da safra.

## Roteiro do vídeo
- Abrir Aplicações Nutricionais, aplicar o filtro de tipo.
- Narrar a leitura de uma linha (produto, dose do RT, custo, status).
- Mostrar o link "registrar" levando ao núcleo de Aplicações.
