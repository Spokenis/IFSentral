<?php
/**
 * termos.php - Termos e Condições de Uso da Plataforma IFSentral
 */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Termos de Serviço | IFSentral</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
    }
    body {
      background-color: #f4f6f9;
    }
    .terms-container {
      max-width: 900px;
      margin: 40px auto;
      padding: 20px;
    }
    .card {
      box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
      border-radius: 8px;
    }
    .card-header {
      background-color: var(--ifsc-primary);
      color: white;
      border-radius: 8px 8px 0 0 !important;
    }
  </style>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand-md navbar-light navbar-white">
    <div class="container">
      <a href="/login" class="navbar-brand">
        <span class="brand-text font-weight-bold" style="color: var(--ifsc-primary);">
          <i class="fas fa-network-wired mr-2"></i>IFSentral
        </span>
      </a>
      <ul class="navbar-nav ml-auto">
        <li class="nav-item">
          <a href="/login" class="btn btn-outline-success btn-sm">Voltar ao Login</a>
        </li>
      </ul>
    </div>
  </nav>

  <!-- Content Wrapper -->
  <div class="content-wrapper">
    <div class="container terms-container">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title m-0"><i class="fas fa-file-contract mr-2"></i> Termos de Serviço e Uso da Plataforma</h3>
        </div>
        <div class="card-body p-4" style="line-height: 1.7;">
          <p class="text-muted">Última atualização: 12 de agosto de 2026</p>

          <h5 class="text-success mt-4">1. Aceitação dos Termos</h5>
          <p>Ao acessar e utilizar a plataforma <strong>IFSentral</strong>, você concorda expressamente em cumprir e estar vinculado aos presentes Termos de Serviço, bem como a todas as leis e regulamentos aplicáveis. Caso não concorde com qualquer parte destes termos, por favor, abstenha-se de utilizar nossos serviços.</p>

          <h5 class="text-success mt-4">2. Descrição do Serviço</h5>
          <p>O <strong>IFSentral</strong> é uma plataforma voltada para o gerenciamento de dispositivos IoT, telemetria, visualização de gráficos e automação acadêmica/institucional. Os serviços são fornecidos "no estado em que se encontram", podendo sofrer atualizações, melhorias ou indisponibilidades temporárias para manutenção.</p>

          <h5 class="text-success mt-4">3. Cadastro e Conta de Usuário</h5>
          <p>Para utilizar determinadas funcionalidades da plataforma, o usuário deve criar uma conta fornecendo informações precisas, completas e atualizadas. O usuário é o único responsável por manter a confidencialidade de sua senha e por todas as atividades que ocorram em sua conta.</p>

          <h5 class="text-success mt-4">4. Uso Aceitável</h5>
          <p>O usuário compromete-se a não utilizar o IFSentral para:</p>
          <ul>
            <li>Transmitir ou armazenar conteúdos ilegais, maliciosos ou que violem direitos de terceiros;</li>
            <li>Tentar burlar mecanismos de segurança, rate limiting ou autenticação do broker MQTT;</li>
            <li>Sobrecarregar os servidores ou interferir no uso da plataforma por outros usuários.</li>
          </ul>

          <h5 class="text-success mt-4">5. Privacidade e Proteção de Dados</h5>
          <p>Respeitamos a sua privacidade. Os dados coletados (como informações de perfil e payloads de dispositivos) são utilizados estritamente para o funcionamento do ecossistema IoT da instituição, em conformidade com as diretrizes de segurança aplicáveis.</p>

          <h5 class="text-success mt-4">6. Modificações dos Termos</h5>
          <p>Reservamo-nos o direito de modificar estes Termos de Serviço a qualquer momento. Alterações entram em vigor imediatamente após sua publicação na plataforma. O uso contínuo dos serviços constitui aceitação tácita dos novos termos.</p>

          <h5 class="text-success mt-4">7. Contato</h5>
          <p>Dúvidas, sugestões ou solicitações relativas a estes Termos de Serviço podem ser encaminhadas através do suporte oficial da instituição.</p>

          <div class="mt-4 text-center">
            <a href="/registrar" class="btn btn-success">
              <i class="fas fa-arrow-left mr-2"></i> Voltar ao Cadastro
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="main-footer text-center text-muted">
    <div class="float-right d-none d-sm-inline">
      <b>Versão</b> 1.0.0
    </div>
    <strong>Copyright &copy; 2026 IFSentral.</strong> Todos os direitos reservados.
  </footer>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
</body>
</html>
