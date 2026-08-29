---
slug: cadastrar-valvula
titulo: "Cadastrar uma válvula (a unidade produtiva)"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /agro/valvulas.php
    principal: true
  - rota: /agro/talhoes.php
permissoes: [agro.talhoes.editar]
papeis:
  nucleo: [gestor, rt_gerente, encarregado]
  consulta: [operador, dono]
relacoes:
  prerequisito: [cadastrar-cultura]
  proximo: [desenhar-valvula-no-mapa]
  relacionado: [cadastrar-talhao, cadastrar-area-produtiva, cadastrar-variedade]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A válvula é a unidade produtiva do VERO — é nela que safra, colheita, custo e apontamento se ancoram. Nesta operação válvula e talhão são a mesma coisa: você cadastra uma vez e o sistema mantém o espelho sincronizado sozinho.

## Como fazer
1. Abra **Válvulas** e clique em **+ Nova válvula**.
2. Informe a **fazenda** e o **código** — curto e único na fazenda (ex.: 5A, 2D).
3. Preencha área (ha), nº de plantas, variedade, estrutura de condução, porta-enxerto e tipo de irrigação, conforme tiver.
4. Clique em **Salvar** — a válvula fica gravada e a válvula-espelho é criada e sincronizada automaticamente.
5. Os dados técnicos finos (solo, coordenadas, espaçamento) você completa depois na **ficha** da válvula.

## Erros comuns
- **Repetir o código na mesma fazenda.** O sistema recusa código igual a outra válvula ativa da fazenda ("Já existe a válvula"). Códigos repetidos só são permitidos em fazendas diferentes.
- **Deixar o nº de plantas em branco e estranhar a calculadora.** A calculadora de mão de obra por planta usa esse número; sem ele, a estimativa de diárias fica incompleta.
- **Área negativa.** Área é grandeza física — o sistema bloqueia valores menores que zero.
- **Cadastrar a válvula antes da cultura/variedade.** A variedade só aparece na lista se já estiver cadastrada; cadastre a cultura e a variedade primeiro.

## Antes e depois
Antes: Cadastrar a cultura e a variedade que a válvula recebe. Depois: Desenhar o polígono da válvula no Mapa da Fazenda.

## Roteiro do vídeo
- Abrir Válvulas e clicar em + Nova válvula.
- Preencher fazenda, código, área e nº de plantas; narrar "válvula = unidade produtiva".
- Salvar e mostrar a linha nova com a válvula-espelho já sincronizada.
- Abrir a ficha da válvula e apontar onde ficam os dados técnicos completos.
