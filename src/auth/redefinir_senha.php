<?php
// redefinir_senha.php - Valida o token de redefinição e exibe o formulário de nova senha

require_once '../config/config.php';
require_once '../config/db.php';

function renderizarErro($titulo, $mensagem) {
    echo "<!DOCTYPE html>
    <html lang='pt-br'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$titulo}</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .container { background-color: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; max-width: 500px; width: 90%; }
            .icone { font-size: 48px; color: #d9534f; margin-bottom: 20px; }
            h1 { color: #333; font-size: 24px; margin-top: 0; }
            p { color: #666; font-size: 16px; line-height: 1.5; margin-bottom: 30px; }
            .btn { display: inline-block; padding: 12px 24px; background-color: #1B7D3D; color: #fff; text-decoration: none; border-radius: 4px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='icone'>❌</div>
            <h1>{$titulo}</h1>
            <p>{$mensagem}</p>
            <a href='/esqueci-senha' class='btn'>Solicitar novo link</a>
        </div>
    </body>
    </html>";
    exit;
}

if (!isset($_GET['token']) || empty(trim($_GET['token']))) {
    renderizarErro("Token Ausente", "Nenhum código de redefinição foi fornecido. Utilize o link exato enviado para o seu e-mail.");
}

$tokenPuro = trim($_GET['token']);
$tokenHash = hash('sha256', $tokenPuro);

try {
    $stmt = $conn->prepare("SELECT id, expires_at FROM email_verifications WHERE token_hash = ? AND type = 'PASSWORD_RESET' AND used = 0");
    $stmt->execute([$tokenHash]);
    $verificacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verificacao) {
        renderizarErro("Link Inválido", "Este link de redefinição de senha não é válido ou já foi utilizado.");
    }

    if (strtotime($verificacao['expires_at']) < time()) {
        renderizarErro("Link Expirado", "O seu link de redefinição expirou. Solicite um novo link.");
    }
} catch (PDOException $e) {
    error_log("Erro ao validar token de redefinição: " . $e->getMessage());
    renderizarErro("Erro no Servidor", "Ocorreu um erro interno. Por favor, tente novamente mais tarde.");
}

// Token válido: exibe o formulário de nova senha
$tokenSeguro = htmlspecialchars($tokenPuro, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Redefinir Senha | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  <style>
    :root { --ifsc-primary: #1B7D3D; --ifsc-secondary: #0D4620; }
    #status-msg { margin-top: 15px; font-size: 0.9em; }
    .btn-primary, .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
      background-color: var(--ifsc-primary) !important;
      border-color: var(--ifsc-primary) !important;
    }
    .card-primary .card-header, .card-primary { border-top-color: var(--ifsc-primary) !important; background-color: var(--ifsc-primary) !important; }
  </style>
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <a href="/"><b>IF</b>Sentral</a>
  </div>
  <div class="card card-outline card-primary">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Escolha sua nova senha</p>

      <form id="reset-form">
        <input type="hidden" id="token" value="<?php echo $tokenSeguro; ?>">
        <div class="input-group mb-3">
          <input type="password" class="form-control" placeholder="Nova senha" id="new_password" minlength="6" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
        </div>
        <div class="input-group mb-3">
          <input type="password" class="form-control" placeholder="Confirme a nova senha" id="confirm_password" minlength="6" required>
          <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
        </div>
        <button type="submit" class="btn btn-primary btn-block" id="reset-button">Redefinir senha</button>
        <div id="status-msg"></div>
      </form>

      <p class="mb-0 mt-3">
        <a href="/login" class="text-center">Voltar ao login</a>
      </p>
    </div>
  </div>
</div>

<script>
  const form = document.getElementById('reset-form');
  const statusMsg = document.getElementById('status-msg');
  const resetButton = document.getElementById('reset-button');

  form.addEventListener('submit', async function(event) {
    event.preventDefault();

    const new_password = document.getElementById('new_password').value;
    const confirm_password = document.getElementById('confirm_password').value;

    if (new_password !== confirm_password) {
      statusMsg.innerHTML = '<span style="color: red;">As senhas não coincidem.</span>';
      return;
    }

    statusMsg.innerHTML = 'Salvando...';
    resetButton.disabled = true;

    try {
      const response = await fetch('/api/redefinir-senha', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: document.getElementById('token').value,
          new_password: new_password,
          confirm_password: confirm_password
        })
      });

      const resultado = await response.json();

      if (!response.ok) {
        throw new Error(resultado.error || 'Erro desconhecido');
      }

      statusMsg.innerHTML = '<span style="color: green;">Senha redefinida com sucesso! Redirecionando para o login...</span>';
      setTimeout(function () { window.location.href = '/login'; }, 2000);

    } catch (error) {
      statusMsg.innerHTML = `<span style="color: red;">${error.message}</span>`;
      resetButton.disabled = false;
    }
  });
</script>
</body>
</html>
