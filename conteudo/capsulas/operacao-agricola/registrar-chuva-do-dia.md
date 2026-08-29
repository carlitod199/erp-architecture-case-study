---
slug: registrar-chuva-do-dia
titulo: "Registrar a chuva do dia no pluviômetro"
tipo: FAZER
modulo: operacao-agricola
objetivo: registrar
jornada: [safra]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /agro/clima.php
    principal: true
permissoes: [agro.clima.editar]
papeis:
  nucleo: [encarregado, operador, gestor]
  consulta: [rt_gerente, dono]
relacoes:
  relacionado: [desenhar-valvula-no-mapa]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Aqui você lança a leitura manual do pluviômetro: chuva do dia por fazenda (válvula opcional) e temperaturas mín/máx. É um registro simples — o VERO acumula o mês e o ano, sem derivar recomendações a partir dele.

## Como fazer
1. Abra **Clima e Chuvas** e clique em **+ Novo registro**.
2. Escolha a **fazenda** e a **data** — obrigatórias; a válvula é opcional.
3. Informe a **chuva em mm** e, se medir, as temperaturas mínima e máxima.
4. Clique em **Salvar** — o registro entra no acumulado do mês e do ano.

## Erros comuns
- **Lançar dois registros para a mesma fazenda/válvula na mesma data.** O sistema recusa duplicidade e pede para editar o registro existente daquele dia.
- **Válvula de outra fazenda.** A válvula escolhida precisa pertencer à fazenda selecionada; senão o lançamento é recusado.
- **Temperatura mínima maior que a máxima.** É bloqueado — confira os valores antes de salvar.
- **Esperar alertas automáticos de clima.** Este registro é só a leitura do pluviômetro; ele não gera recomendação nem trava aplicação (as faixas de referência do RT ficam em outro bloco e apenas avisam).

## Antes e depois
Antes: Ter a fazenda cadastrada. Depois: Acompanhar o acumulado mensal e anual de chuva.

## Roteiro do vídeo
- Abrir Clima e Chuvas e clicar em + Novo registro.
- Escolher fazenda e data, digitar mm de chuva e temperaturas.
- Salvar e mostrar os KPIs de mm do mês e acumulado do ano.
