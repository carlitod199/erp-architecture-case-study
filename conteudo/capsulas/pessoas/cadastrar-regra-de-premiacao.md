---
slug: cadastrar-regra-de-premiacao
titulo: "Cadastrar uma regra de premiação para pagar produção acima da meta"
tipo: FAZER
modulo: pessoas
objetivo: cadastrar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /pessoas/premiacao.php
    principal: true
permissoes: [pessoas.premiacao.editar]
papeis:
  nucleo: [rh, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [cadastrar-um-colaborador]
  proximo: [fechar-a-folha-do-mes]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A regra de premiação define quanto se paga por produção que passa da meta numa atividade (por planta, caixa, kg, hora, etc.). Ela não paga ninguém sozinha: serve para dimensionar a equipe e, na hora do apontamento, virar o valor de premiação que entra no bruto da folha.

## Como fazer
1. Abra **Premiação** e clique em **Nova regra**.
2. Escolha a **atividade** e a **unidade** (planta, caixa, kg, ha, hora...) — os dois são obrigatórios.
3. Escolha a **cultura** (ou deixe em branco para valer em todas).
4. Informe a **meta** (produção esperada por pessoa) e o **valor acima da meta** — o que se paga por unidade excedente.
5. Defina a **vigência** (início e, se quiser, fim). Clique em **Salvar**.

## Erros comuns
- **Cair no bloqueio de vigência sobreposta.** Não pode existir duas regras ativas para a mesma atividade e cultura no mesmo período. Encerre a vigência da regra anterior (ou inative-a) antes de criar a nova.
- **Achar que cadastrar a regra já paga a premiação.** A regra é só o parâmetro. O valor só é gerado quando o trabalho é lançado no apontamento de campo, e entra no bruto da folha do mês.
- **Inverter meta e valor.** Meta é quanto a pessoa precisa produzir; valor acima da meta é o preço do excedente. Trocar os dois distorce todo o cálculo.

## Antes e depois
Antes: ter a atividade e a cultura cadastradas. Depois: apontar a produção em campo e fechar a folha do mês com as premiações incluídas.

## Roteiro do vídeo
- Abrir Premiação com regras já cadastradas na lista.
- Narrar: "Esta tela não paga ninguém — ela define a regra. O pagamento nasce lá no apontamento."
- Criar nova regra: atividade colheita, unidade caixa, meta e valor por caixa acima da meta.
- Definir vigência e salvar.
- Fechar reforçando que o valor cairá na folha via apontamento.
