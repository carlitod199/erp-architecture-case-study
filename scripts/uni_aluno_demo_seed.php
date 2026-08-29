<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_aluno_demo_seed.php
   Cria/atualiza um ALUNO de demonstração do LMS (banco separado).
   Idempotente. Só para dev/demo — troque a senha depois.
   Rodar: php scripts/uni_aluno_demo_seed.php
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$email  = 'aluno@vero.local';
$senha  = getenv('UNI_DEMO_SENHA') ?: bin2hex(random_bytes(8)); // defina UNI_DEMO_SENHA ou use a senha gerada impressa no fim
$nome   = 'Aluno Demonstração';
$perfil = 'gestor'; // vê catálogo, trilhas, prática, certificados e o painel da equipe

$cost = (int)(getenv('BCRYPT_COST') ?: 12);
$hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => max(10, min(13, $cost))]);

$pdo = uni_pdo();
$pdo->prepare(
    "INSERT INTO uni_usuario (nome, email, senha_hash, perfil, ativo, email_verificado_em)
     VALUES (?,?,?,?,1, NOW())
     ON DUPLICATE KEY UPDATE nome=VALUES(nome), senha_hash=VALUES(senha_hash),
        perfil=VALUES(perfil), ativo=1, tentativas_falhas=0, bloqueado_ate=NULL"
)->execute([$nome, $email, $hash, $perfil]);

$id = (int)$pdo->query("SELECT id FROM uni_usuario WHERE email = " . $pdo->quote($email))->fetchColumn();
echo "== Aluno de demonstração pronto ==\n";
echo "  id:     {$id}\n";
echo "  e-mail: {$email}\n";
echo "  senha:  {$senha}\n";
echo "  perfil: {$perfil}\n";
echo "  entrar: /universidade/login.php\n";
