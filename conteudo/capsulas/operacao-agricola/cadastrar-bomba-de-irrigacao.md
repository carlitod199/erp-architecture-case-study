---
slug: cadastrar-bomba-de-irrigacao
titulo: "Cadastrar uma bomba e suas válvulas"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: intermediario
duracao_seg: 150
rotas:
  - rota: /agro/bombas.php
    principal: true
permissoes: [irrigacao.bombas.editar]
papeis:
  nucleo: [gestor, rt_gerente, encarregado]
  consulta: [operador]
relacoes:
  prerequisito: [cadastrar-valvula]
  relacionado: [registrar-chuva-do-dia]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A bomba de irrigação e o vínculo com as válvulas que ela atende são a base da fertirrigação (IF): o documento IF referencia a bomba usada. Cadastrar a bomba e marcar suas válvulas deixa a IF pronta para apontar.

## Como fazer
1. Abra **Bombas** e clique em **+ Nova bomba**.
2. Escolha a **fazenda** e informe o **nome** da bomba — obrigatórios.
3. Preencha código, vazão (m³/h) e potência (kW), se tiver.
4. Marque as **válvulas** que a bomba atende — só aparecem válvulas ativas da mesma fazenda.
5. Clique em **Salvar** — a bomba e o vínculo com as válvulas ficam gravados.

## Erros comuns
- **Repetir o nome da bomba na fazenda.** O sistema recusa nome igual a outra bomba ativa da mesma fazenda.
- **Marcar válvula de outra fazenda.** Só entram válvulas ativas da fazenda da bomba; válvulas de outra fazenda são ignoradas no vínculo.
- **Vazão ou potência negativas.** São grandezas físicas e o sistema bloqueia valores negativos.
- **Excluir uma bomba usada em IF.** A exclusão inativa a bomba, mas se ela já foi referenciada em documentos IF o histórico é preservado e o sistema avisa — ela só some dos selects e vínculos.

## Antes e depois
Antes: Ter as válvulas da fazenda cadastradas. Depois: Apontar a fertirrigação (IF) referenciando esta bomba.

## Roteiro do vídeo
- Abrir Bombas e clicar em + Nova bomba.
- Escolher a fazenda, nomear, informar vazão/potência.
- Marcar as válvulas atendidas e salvar; mostrar a lista de válvulas na linha.
