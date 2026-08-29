-- VERO — remove a tag interna (P-98)/(P-125) do NOME das regras de rateio.
-- O nome e' texto VISIVEL na tela custeio/rateios.php; o codigo casa por nome
-- (get-or-create), e os literais no codigo foram atualizados junto.
-- Idempotente: rodar de novo nao afeta linhas ja renomeadas.
UPDATE custeio_rateios SET nome = 'Atribuição sem safra'
 WHERE nome = 'Atribuição sem safra (P-98)';

UPDATE custeio_rateios SET nome = 'Rateio de combustível por horas'
 WHERE nome = 'Rateio de combustível por horas (P-125)';
