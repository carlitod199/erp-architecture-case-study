-- 23/07/2026 — Colheita registrada NO CAMPO vai direto para a tela Colheita
--. Os papéis de campo (operador e encarregado) precisam
-- da ação agro.colheita.editar para a rota POST /colheitas do app.
-- Idempotente (INSERT só se faltar).
-- Aplicar junto do deploy no servidor01 (scripts/aplicar_colheita_operador.php).

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.slug = 'agro.colheita.editar'
 WHERE r.slug IN ('operador', 'encarregado')
   AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp
         WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );
