<?php 
require '../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Documentação | IFSentral Lite</title>

  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/css/adminlte.min.css">
  
  <style>
    :root {
      --ifsc-primary: #1B7D3D;
      --ifsc-secondary: #0D4620;
      --ifsc-light: #2A9B4A;
    }
    
    .wrapper { display: flex; flex-direction: column; min-height: 100vh; }
    .content-wrapper { flex: 1; }
    pre {
        background-color: #f4f4f4;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    kbd {
        font-size: 0.85em;
        font-weight: 600;
    }
    .tab-content > .tab-pane > pre {
        margin: 0;
        border-radius: 0 0 4px 4px;
        border-top: none;
    }
    .nav-tabs-container {
        border: 1px solid #ddd;
        border-bottom: none;
        border-radius: 4px 4px 0 0;
    }
    .callout-warning {
        background-color: #fdf3e8 !important;
        border-left-color: #f59e0b !important;
    }
    
    /* IFSC Theme Colors */
    .btn-primary, .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
      background-color: var(--ifsc-primary) !important;
      border-color: var(--ifsc-primary) !important;
    }
    .btn-primary:hover {
      background-color: var(--ifsc-secondary) !important;
    }
    
    .card-primary .card-header {
      background-color: var(--ifsc-primary) !important;
    }
    
    .card-primary {
      border-top-color: var(--ifsc-primary) !important;
    }
    
    .navbar-light .navbar-brand {
      color: var(--ifsc-primary) !important;
    }
  </style>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">

  <?php require_once __DIR__ . '/../includes/header.php'; ?>
  
  <div class="content-wrapper">
    <section class="content-header">
      <div class="container">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1>Documentação</h1>
            <p class="text-muted">Como usar a plataforma: navegação, permissões, convites — e a documentação técnica da API de dispositivos.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="content">
      <div class="container">
        <div class="row">
          <div class="col-md-12">

            <div class="card card-success card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-map-signs mr-1"></i> Mapa da Plataforma — Onde Encontrar Cada Coisa</h3></div>
              <div class="card-body">
                <div class="table-responsive">
                  <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                      <tr><th>Quero...</th><th>Onde ir</th></tr>
                    </thead>
                    <tbody>
                      <tr><td>Ver os projetos dos quais participo</td><td><strong>Meus Projetos</strong> (menu superior)</td></tr>
                      <tr><td>Encontrar e pedir para entrar em um projeto público de outra pessoa</td><td><strong>Explorar Projetos</strong> (menu superior) — tem busca por nome, tag ou gerente</td></tr>
                      <tr><td>Ver todos os meus dispositivos, de todos os projetos, de uma vez</td><td><strong>Meu Perfil ▸ Meus Sensores</strong> (menu do avatar, canto superior direito)</td></tr>
                      <tr><td>Gerenciar um dispositivo específico (payloads, tags, mapeamento de valores, credenciais, excluir)</td><td>Dentro do projeto ▸ aba <strong>Dispositivos</strong> ▸ clique no dispositivo</td></tr>
                      <tr><td>Ver/aceitar convites recebidos, ou pedir para virar Moderador</td><td><strong>Meu Perfil</strong> (menu do avatar)</td></tr>
                      <tr><td>Trocar senha, ativar 2FA, editar dados da conta</td><td><strong>Configurações</strong> (menu do avatar) ▸ aba <strong>Segurança</strong></td></tr>
                      <tr><td>Ver notificações de convites e solicitações pendentes</td><td>Ícone de sino 🔔 no menu superior (fica ao lado do seu avatar)</td></tr>
                      <tr><td>Convidar alguém, aprovar solicitações de entrada, expulsar/promover membro, excluir o projeto</td><td>Dentro do projeto ▸ abas <strong>Participantes</strong>, <strong>Solicitações</strong> e <strong>Configurações</strong> (só aparecem funcionais se você for Gerente daquele projeto)</td></tr>
                      <tr><td>Gerenciar usuários do sistema, aprovar pedidos de Moderador</td><td><strong>Administração</strong> (menu superior — só visível para Admin)</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="card card-primary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-user-shield mr-1"></i> Perfis e Permissões</h3></div>
              <div class="card-body">
                <p>O sistema tem <strong>dois níveis</strong> de permissão que não devem ser confundidos: o <strong>perfil global</strong> da sua conta, e o <strong>papel</strong> que você tem dentro de <em>cada</em> projeto.</p>

                <h5>1. Perfil Global da Conta</h5>
                <table class="table table-bordered table-sm">
                  <thead class="thead-light"><tr><th>Perfil</th><th>O que pode fazer</th></tr></thead>
                  <tbody>
                    <tr>
                      <td><span class="badge badge-secondary">User</span></td>
                      <td>Perfil padrão de todo cadastro novo. Pode entrar em projetos (por convite ou solicitação), gerenciar seus próprios dispositivos dentro deles. <strong>Não pode criar projetos novos.</strong></td>
                    </tr>
                    <tr>
                      <td><span class="badge badge-success">Moderator</span></td>
                      <td>Tudo que User faz, <strong>mais</strong> a permissão de criar novos projetos (e virar Gerente deles). Vira Moderator solicitando em <strong>Meu Perfil</strong> e sendo aprovado por um Admin.</td>
                    </tr>
                    <tr>
                      <td><span class="badge badge-danger">Admin</span></td>
                      <td>Tudo que Moderator faz, mais: gerenciar todos os usuários do sistema (promover/rebaixar perfil, remover conta), aprovar ou rejeitar pedidos de Moderator, e configurar limites de requisição (rate limiting) da plataforma.</td>
                    </tr>
                  </tbody>
                </table>

                <h5 class="mt-4">2. Papel dentro de um Projeto</h5>
                <p>Ao entrar em um projeto (como convidado ou por solicitação aprovada), você recebe um dos dois papéis abaixo — <strong>independente do seu perfil global</strong>:</p>
                <table class="table table-bordered table-sm">
                  <thead class="thead-light"><tr><th>Papel</th><th>O que pode fazer <em>naquele projeto</em></th></tr></thead>
                  <tbody>
                    <tr>
                      <td><span class="badge badge-success">Gerente</span></td>
                      <td>Quem cria o projeto vira Gerente automaticamente. Pode: editar e excluir o projeto, convidar e expulsar membros, aprovar/rejeitar solicitações de entrada, promover um Participante a Gerente, criar/editar gráficos, gerenciar tags e mapeamento de valores dos dispositivos, e <strong>excluir dispositivos</strong> (permissão <code>canDeleteSensor</code>).</td>
                    </tr>
                    <tr>
                      <td><span class="badge badge-secondary">Participante</span></td>
                      <td>Pode ver o projeto, os dados e gráficos, e cadastrar novos dispositivos. <strong>Não pode</strong> convidar/expulsar ninguém, editar ou excluir o projeto, nem excluir dispositivos.</td>
                    </tr>
                  </tbody>
                </table>
                <div class="callout callout-info">
                  <p class="mb-0"><i class="icon fas fa-info"></i> Ou seja: um <strong>Admin</strong> do sistema pode muito bem ser só <strong>Participante</strong> num projeto específico se foi convidado como tal — os dois níveis são independentes.</p>
                </div>
              </div>
            </div>

            <div class="card card-warning card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-user-plus mr-1"></i> Como Entrar em um Projeto: Convites vs. Solicitações</h3></div>
              <div class="card-body">
                <p>Existem <strong>duas formas diferentes</strong> de entrar em um projeto — não confunda:</p>

                <h5><i class="fas fa-envelope mr-1"></i> Convite (quem convida é o Gerente)</h5>
                <ol>
                  <li>Um Gerente do projeto te convida pelo seu e-mail, na aba <strong>Participantes</strong> do projeto, escolhendo o papel (Gerente ou Participante) que você vai ter.</li>
                  <li>Você recebe o convite em <strong>Meu Perfil ▸ Convites Pendentes</strong> (e, se tiver conta cadastrada, também aparece no sino de notificações 🔔). Se o e-mail estiver configurado no servidor, você também recebe um e-mail.</li>
                  <li>Você pode <strong>Aceitar</strong> (entra no projeto com o papel definido pelo convite) ou <strong>Recusar</strong>.</li>
                  <li>Convites expiram automaticamente após um tempo — se perder o prazo, peça um novo convite.</li>
                </ol>
                <p class="text-muted"><small>Funciona para projetos públicos <strong>e</strong> privados — é a única forma de entrar num projeto privado.</small></p>

                <h5 class="mt-4"><i class="fas fa-hand-paper mr-1"></i> Solicitação de Participação (quem pede é você)</h5>
                <ol>
                  <li>Em <strong>Explorar Projetos</strong>, você encontra um projeto <strong>público</strong> que te interessa e clica em "Solicitar Participação".</li>
                  <li>O pedido cai na aba <strong>Solicitações</strong> do projeto, visível só para o Gerente (com um contador de pendências no menu).</li>
                  <li>O Gerente aprova ou rejeita. Se aprovado, você entra automaticamente como <strong>Participante</strong>.</li>
                </ol>
                <p class="text-muted"><small>Só funciona para projetos marcados como <strong>públicos</strong>. Projetos privados não aparecem em Explorar Projetos.</small></p>

                <div class="callout callout-warning">
                  <h5><i class="icon fas fa-exclamation-triangle"></i> Resumo</h5>
                  <p class="mb-0"><strong>Convite</strong> = o Gerente vem até você, funciona pra qualquer projeto, você escolhe o papel. <strong>Solicitação</strong> = você vai até o projeto, só pra projetos públicos, você sempre entra como Participante.</p>
                </div>
              </div>
            </div>

            <div class="card card-info card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-shield-alt mr-1"></i> Segurança da Conta</h3></div>
              <div class="card-body">
                <ul>
                  <li><strong>Esqueci minha senha:</strong> na tela de login, clique em "Esqueci minha senha" e informe seu e-mail. Um link de redefinição é enviado (só funciona se o servidor tiver e-mail configurado — pergunte ao administrador se não chegar nada).</li>
                  <li><strong>Autenticação de dois fatores (2FA):</strong> opcional, ativada em <strong>Configurações ▸ Segurança</strong>. Depois de ativada, todo login pede também um código de 6 dígitos do seu aplicativo autenticador (Google Authenticator, Authy, etc). Guarde os códigos de backup mostrados na hora da ativação — cada um só funciona uma vez, e servem pra entrar caso perca acesso ao aplicativo.</li>
                </ul>
              </div>
            </div>

            <div class="card card-warning card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-broadcast-tower mr-1"></i> Integração: The Things Network (TTN)</h3></div>
              <div class="card-body">
                <p>O Webhook do TTN envia um formato JSON complexo e possui limitações de URL (máx 64 caracteres). Para esta integração, utilize o nosso script "adaptador" chamado <code>/api/ttn-webhook</code>.</p>
                <p>Os dados recebidos via TTN serão salvos com <code>source='ttn'</code>, permitindo diferenciar dados de diferentes fontes (HTTP ou TTN).</p>
                <div class="callout callout-warning">
                  <h5><i class="icon fas fa-exclamation-triangle"></i> Pré-requisito Obrigatório</h5>
                  <p>Seu dispositivo no TTN (LoRaWAN) **deve** ter um **Payload Formatter** (Uplink) ativado. Nosso script procura pelo campo <code>decoded_payload</code>.</p>
                </div>
                <h5>Configurando o Webhook no TTN</h5>
                <ol>
                  <li>No IFSentral Lite, copie o <strong>ID do Dispositivo</strong> e a <strong>API Key</strong> do seu dispositivo.</li>
                  <li>No painel do TTN, adicione um Webhook JSON.</li>
                  <li>No campo <strong>Base URL</strong>, coloque a URL do seu servidor:
                    <pre><code>https://ifsentral.online/</code></pre>
                  </li>
                  <li>Marque a caixa <strong>Uplink message</strong>.</li>
                  <li>No campo <strong>Uplink message path</strong>, coloque **apenas** o caminho do endpoint e o `device_id`:
                    <pre><code>api/ttn-webhook?device_id=8</code></pre>
                  </li>
                  <li>Em <strong>Additional headers</strong>, clique em <strong>+ Add header entry</strong>.</li>
                  <li>Preencha os campos:
                    <ul>
                      <li>Header Name: <code>X-Api-Key</code></li>
                      <li>Header Value: <code>SUA_API_KEY_COMPLETA_AQUI</code></li>
                    </ul>
                  </li>
                </ol>
              </div>
            </div>

            <div class="card card-primary card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-key mr-1"></i> Autenticação da API de Dispositivos</h3></div>
              <div class="card-body">
                <h5>REST API (HTTP)</h5>
                <p>As rotas de envio e consulta de dados do seu dispositivo usam <code>X-Api-Key</code>:</p>
                <pre><code>X-Api-Key: SUA_API_KEY_AQUI</code></pre>
                <p>Os gráficos e estatísticas exibidos no painel do IFSentral Lite usam sua sessão de navegador logado automaticamente — você não precisa se preocupar com isso ao navegar pelo site.</p>
              </div>
            </div>

            <div class="card card-warning card-outline">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-lock mr-1"></i> Como Obter Suas Credenciais</h3></div>
              <div class="card-body">
                <h5>No Painel do IFSentral Lite</h5>
                <ol>
                  <li>Acesse <strong>Meus Projetos</strong></li>
                  <li>Clique no seu projeto</li>
                  <li>Clique no dispositivo que deseja gerenciar</li>
                  <li>Localize a seção <strong>"Informações de Acesso (API)"</strong></li>
                </ol>

                <h5>Informações Disponíveis</h5>
                <pre><code>📌 API REST
ID do Dispositivo: 7
Chave de API (X-Api-Key): a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6...</code></pre>
              </div>
            </div>

            <div class="card card-primary">
              <div class="card-header"><h3 class="card-title">Endpoint: Enviar Dados (Padrão)</h3></div>
              <div class="card-body">
                <p>Este endpoint é usado para salvar um novo conjunto de leituras (payload) do seu dispositivo no banco de dados.</p>
                <div class="callout callout-info">
                  <h5><i class="icon fas fa-info"></i> Novo: Identificação de Origem</h5>
                  <p>Cada payload agora é marcado com sua origem: <code>http</code> (este endpoint) ou <code>ttn</code>. Isso permite rastrear facilmente de onde vieram seus dados!</p>
                </div>

                <div class="callout callout-warning">
                  <h5><i class="icon fas fa-ruler mr-1"></i> Limites do Payload</h5>
                  <p class="mb-0">Mantenha o payload pequeno e simples: até <strong>2KB</strong> por envio (8KB via TTN), sem aninhamento profundo de objetos e sem chaves/textos muito longos. Payloads fora desses limites são rejeitados com <code>HTTP 400</code> e uma mensagem explicando o motivo.</p>
                </div>

                <h5><kbd class="bg-primary">POST</kbd> <code>/api/enviar-payload</code></h5>
                <hr>
                <h5>Headers HTTP</h5>
                <pre><code>Content-Type: application/json
X-Api-Key: SUA_CHAVE_DE_API_AQUI</code></pre>
                <hr>
                <h5>Corpo (Body) da Requisição (JSON)</h5>
                <pre><code>{
  "device_id": 1,
  "payload": {
    "temperatura": 25.5,
    "umidade": 60
  }
}</code></pre>
                <hr>
                <h5>Resposta (Sucesso 201 Created)</h5>
                <pre><code>{
  "message": "Payload salvo com sucesso!",
  "payload_id": 42,
  "device_id": 1,
  "project_id": 2,
  "source": "http",
  "rate_limit": {
    "requests": 12,
    "limit": 60,
    "remaining": 48
  }
}</code></pre>
                <hr>
                <h5>Exemplos de Código</h5>
                <div class="card card-primary card-tabs">
                  <div class="card-header p-0 pt-1 nav-tabs-container">
                    <ul class="nav nav-tabs" id="tabs-enviar" role="tablist">
                      <li class="nav-item"><a class="nav-link active" id="tabs-enviar-js-tab" data-toggle="pill" href="#tabs-enviar-js" role="tab">JavaScript (Fetch)</a></li>
                      <li class="nav-item"><a class="nav-link" id="tabs-enviar-php-tab" data-toggle="pill" href="#tabs-enviar-php" role="tab">PHP (cURL)</a></li>
                      <li class="nav-item"><a class="nav-link" id="tabs-enviar-esp-tab" data-toggle="pill" href="#tabs-enviar-esp" role="tab">C++ (ESP32/Arduino)</a></li>
                    </ul>
                  </div>
                  <div class="card-body p-0">
                    <div class="tab-content" id="tabs-enviar-content">
                      <div class="tab-pane fade show active" id="tabs-enviar-js" role="tabpanel">
<pre><code>const API_URL = 'https://ifsentral.online/api/enviar-payload';
const API_KEY = 'SUA_CHAVE_DE_API_AQUI';
const DEVICE_ID = 1;

async function enviarDados(temp, umid) {
  const dados = {
    device_id: DEVICE_ID,
    payload: { temperatura: temp, umidade: umid }
  };
  try {
    const response = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Key': API_KEY
      },
      body: JSON.stringify(dados)
    });
    const resultado = await response.json();
    if (!response.ok) throw new Error(resultado.error);
    console.log('✓ Sucesso:', resultado.message);
    console.log('  Payload ID:', resultado.payload_id);
    console.log('  Origem:', resultado.source);  // Agora mostra a origem!
  } catch (error) {
    console.error('✗ Erro:', error.message);
  }
}
enviarDados(25.5, 60);</code></pre>
                      </div>
                      <div class="tab-pane fade" id="tabs-enviar-php" role="tabpanel">
<pre><code>&lt;?php
$apiUrl = 'https://ifsentral.online/api/enviar-payload';
$apiKey = 'SUA_CHAVE_DE_API_AQUI';
$deviceId = 1;
$data = [
    'device_id' => $deviceId,
    'payload' => ['temperatura' => 25.5, 'umidade' => 60]
];
$payloadString = json_encode($data);
$headers = [
    'Content-Type: application/json',
    'X-Api-Key: ' . $apiKey,
    'Content-Length: ' . strlen($payloadString)
];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadString);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Para HTTPS em servidor de teste, pode ser necessário desabilitar verificação SSL
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Código HTTP: " . $httpcode . "\n";
$result = json_decode($response);
echo "Origem do dado: " . $result->source . "\n";  // Agora mostra!
echo "Resposta: " . $response;
?&gt;</code></pre>
                      </div>
                      <div class="tab-pane fade" id="tabs-enviar-esp" role="tabpanel">
<pre><code>/* Requer HTTPClient.h e ArduinoJson.h */
#include &lt;WiFi.h&gt;
#include &lt;HTTPClient.h&gt; // Para HTTP
#include &lt;WiFiClientSecure.h&gt; // Para HTTPS
#include &lt;ArduinoJson.h&gt;

const char* serverName = "https://ifsentral.online/api/enviar-payload";
const char* apiKey = "SUA_CHAVE_DE_API_AQUI";
const int deviceId = 1;

// Objeto de cliente seguro
WiFiClientSecure client;

void enviarDados(float temp, float umid) {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;

    // NOTA: Para HTTPS, pode ser necessário ignorar a validação SSL
    client.setInsecure(); // Ignora validação SSL
    
    http.begin(client, serverName); // Inicia com o cliente seguro
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Api-Key", apiKey);

    DynamicJsonDocument doc(256);
    doc["device_id"] = deviceId;
    doc["payload"]["temperatura"] = temp;
    doc["payload"]["umidade"] = umid;

    String jsonBuffer;
    serializeJson(doc, jsonBuffer);

    int httpResponseCode = http.POST(jsonBuffer);
    if (httpResponseCode > 0) {
      Serial.printf("HTTP Response code: %d\n", httpResponseCode);
      Serial.println(http.getString());
    } else {
      Serial.printf("HTTP POST falhou, erro: %s\n", http.errorToString(httpResponseCode).c_str());
    }
    http.end();
  }
}</code></pre>
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <div class="card card-success">
              <div class="card-header"><h3 class="card-title">Endpoint: Buscar Últimos Dados (Padrão)</h3></div>
              <div class="card-body">
                <p>Este endpoint é usado para buscar os últimos payloads registrados por um dispositivo.</p>
                <h5><kbd class="bg-success">GET</kbd> <code>/api/buscar-payloads</code></h5>
                <hr>
                <h5>Headers HTTP</h5>
                <pre><code>X-Api-Key: SUA_CHAVE_DE_API_AQUI</code></pre>
                <hr>
                
                <h5>Parâmetros (Query String)</h5>
                <ul>
                  <li><code>device_id</code> (Obrigatório): O ID do seu dispositivo.</li>
                  <li><code>limit</code> (Opcional): Número de resultados. (Padrão: 10, máximo 1000).</li>
                  <li><code>startDate</code> (Opcional): Data de início (Formato: YYYY-MM-DD).</li>
                  <li><code>endDate</code> (Opcional): Data de fim (Formato: YYYY-MM-DD).</li>
                </ul>
                <pre><code>/api/buscar-payloads?device_id=1&limit=50&startDate=2025-11-01</code></pre>
                
                <hr>
                <h5>Exemplos de Código</h5>
                <div class="card card-success card-tabs">
                  <div class="card-header p-0 pt-1 nav-tabs-container">
                    <ul class="nav nav-tabs" id="tabs-buscar" role="tablist">
                      <li class="nav-item"><a class="nav-link active" id="tabs-buscar-js-tab" data-toggle="pill" href="#tabs-buscar-js" role="tab">JavaScript (Fetch)</a></li>
                      <li class="nav-item"><a class="nav-link" id="tabs-buscar-php-tab" data-toggle="pill" href="#tabs-buscar-php" role="tab">PHP (cURL)</a></li>
                      <li class="nav-item"><a class="nav-link" id="tabs-buscar-esp-tab" data-toggle="pill" href="#tabs-buscar-esp" role="tab">C++ (ESP32/Arduino)</a></li>
                    </ul>
                  </div>
                  <div class="card-body p-0">
                    <div class="tab-content" id="tabs-buscar-content">
                      <div class="tab-pane fade show active" id="tabs-buscar-js" role="tabpanel">
<pre><code>// Exemplo buscando os últimos 25
const API_URL = 'https://ifsentral.online/api/buscar-payloads';
const API_KEY = 'SUA_CHAVE_DE_API_AQUI';
const DEVICE_ID = 1;

async function buscarDados() {
  try {
    const response = await fetch(`${API_URL}?device_id=${DEVICE_ID}&limit=25`, {
      method: 'GET',
      headers: { 'X-Api-Key': API_KEY }
    });
    // ... (resto do código JS)
  } catch (error) {
    console.error('Erro:', error.message);
  }
}
buscarDados();</code></pre>
                      </div>
                      <div class="tab-pane fade" id="tabs-buscar-php" role="tabpanel">
<pre><code>&lt;?php
$deviceId = 1;
// Exemplo buscando dados de um período
$queryParams = http_build_query([
    'device_id' => $deviceId,
    'startDate' => '2025-11-01',
    'endDate' => '2025-11-05'
]);
$apiUrl = 'https://ifsentral.online/api/buscar-payloads?' . $queryParams;
// ... (resto do código PHP)
?&gt;</code></pre>
                      </div>
                      <div class="tab-pane fade" id="tabs-buscar-esp" role="tabpanel">
<pre><code>// Exemplo buscando os últimos 25
const char* serverName = "https://ifsentral.online/api/buscar-payloads";
const char* apiKey = "SUA_CHAVE_DE_API_AQUI";
const int deviceId = 1;

void buscarDados() {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    String url = String(serverName) + "?device_id=" + String(deviceId) + "&limit=25";
    // ... (resto do código C++)
  }
}</code></pre>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card card-danger">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-tachometer-alt mr-1"></i> Rate Limiting</h3></div>
              <div class="card-body">
                <p>Para proteger a plataforma, há um limite de requisições por dispositivo:</p>
                <div class="callout callout-info">
                  <h5><i class="icon fas fa-info"></i> Limite Padrão</h5>
                  <p><strong>60 requisições por minuto</strong> por dispositivo</p>
                </div>

                <h5>Como Funciona</h5>
                <ul>
                  <li>Cada dispositivo tem sua própria cota de 60 req/min</li>
                  <li>Aplica-se apenas ao <strong>envio</strong> de dados (<code>/api/enviar-payload</code> e <code>/api/ttn-webhook</code>) — endpoints de <strong>leitura</strong> como <code>/api/buscar-payloads</code> não têm rate limit por enquanto</li>
                  <li>O contador reseta a cada minuto</li>
                  <li>Admins podem aumentar o limite por dispositivo</li>
                </ul>

                <h5>Quando Você Ultrapassa o Limite</h5>
                <p>Se ultrapassar 60 requisições/min, você recebe <code>HTTP 429 Too Many Requests</code> com uma mensagem de erro e o campo <code>retry_after</code> indicando em quantos segundos tentar de novo.</p>

                <h5>💡 Como Evitar</h5>
                <ol>
                  <li><strong>Combine dados</strong>: Em vez de enviar 10 requisições, envie 1 com 10 dados</li>
                  <li><strong>Aumente o intervalo</strong>: Se está enviando a cada 1 segundo, tente a cada 2 segundos</li>
                  <li><strong>Contato admin</strong>: Se precisa de mais, contate o administrador</li>
                </ol>

                <div class="callout callout-warning">
                  <h5><i class="icon fas fa-exclamation-triangle"></i> Exemplo do Erro</h5>
                  <p><strong>❌ Evite:</strong> Enviar temperatura, umidade, pressão em 3 requisições separadas<br>
                  <strong>✅ Faça:</strong> Enviar os 3 valores em 1 requisição JSON</p>
                </div>

                <h5>Monitoramento</h5>
                <p>Toda resposta de sucesso do envio de dados inclui um campo <code>rate_limit</code> mostrando quantas requisições você já usou e quantas ainda restam nesse minuto.</p>
              </div>
            </div>

            <div class="card card-secondary">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-exclamation-circle mr-1"></i> Erros Comuns e Troubleshooting</h3></div>
              <div class="card-body">
                <h5>❌ 401 Unauthorized</h5>
                <p><strong>Causa</strong>: API Key incorreta ou ausente</p>
                <p><strong>Solução</strong>:</p>
                <ol>
                  <li>Verifique se está usando o header <code>X-Api-Key</code> correto</li>
                  <li>Copie novamente da página do dispositivo (verifique espaços)</li>
                  <li>Verifique se o header está sendo enviado</li>
                </ol>

                <h5>❌ 403 Forbidden</h5>
                <p><strong>Causa</strong>: Você não tem permissão para acessar este dispositivo</p>
                <p><strong>Solução</strong>:</p>
                <ol>
                  <li>Verifique se você é membro do projeto</li>
                  <li>Verifique se está usando o <code>device_id</code> correto</li>
                  <li>Contate o admin se foi descadastrado</li>
                </ol>

                <h5>❌ 404 Not Found</h5>
                <p><strong>Causa</strong>: Dispositivo ou endpoint não existe</p>
                <p><strong>Solução</strong>:</p>
                <ol>
                  <li>Verifique se a URL está correta</li>
                  <li>Verifique se o <code>device_id</code> existe</li>
                  <li>Verifique se o dispositivo não foi deletado</li>
                </ol>

                <h5>❌ 429 Too Many Requests</h5>
                <p><strong>Causa</strong>: Limite de 60 requisições por minuto ultrapassado</p>
                <p><strong>Solução</strong>: Ver seção de <strong>Rate Limiting</strong> acima</p>

                <h5>❌ 500 Internal Server Error</h5>
                <p><strong>Causa</strong>: Erro no servidor</p>
                <p><strong>Solução</strong>:</p>
                <ol>
                  <li>Tente novamente em alguns segundos</li>
                  <li>Verifique seus dados (JSON válido?)</li>
                  <li>Contate o administrador se persistir</li>
                </ol>

                <h5>❌ JSON Inválido</h5>
                <p><strong>Causa</strong>: Seu JSON não é válido</p>
                <p><strong>Verificar</strong>:</p>
                <ul>
                  <li>Aspas duplas (não simples): <code>"temp": 25</code></li>
                  <li>Sem vírgula no final: <code>{"a": 1, "b": 2}</code></li>
                  <li>Use <a href="https://jsonlint.com" target="_blank">JSONLint</a> para validar</li>
                </ul>
              </div>
            </div>

            <div class="card card-info">
              <div class="card-header"><h3 class="card-title"><i class="fas fa-book mr-1"></i> Recursos Adicionais</h3></div>
              <div class="card-body">
                <h5>Para Usuários Novos</h5>
                <ul>
                  <li><a href="/api/obter-chaves-dispositivo" target="_blank">🧾 Chaves e acesso do dispositivo</a></li>
                  <li><a href="/documentacao" target="_blank">📘 Esta documentação da API</a></li>
                </ul>

                <h5>Documentação Técnica</h5>
                <ul>
                  <li><a href="https://www.json.org/" target="_blank">JSON.org - Especificação JSON</a></li>
                  <li><a href="https://developer.mozilla.org/en-US/docs/Web/HTTP/Status" target="_blank">MDN - HTTP Status Codes</a></li>
                </ul>

                <h5>Ferramentas Úteis</h5>
                <ul>
                  <li><a href="https://www.postman.com/" target="_blank">Postman</a> - Testar APIs</li>
                  <li><a href="https://jsonlint.com/" target="_blank">JSONLint</a> - Validar JSON</li>
                </ul>

                <h5>Suporte</h5>
                <p>Se tiver dúvidas ou encontrar problemas:</p>
                <ul>
                  <li>📧 Contate o administrador</li>
                  <li>💬 Verifique a documentação antes de contatar</li>
                  <li>🐞 Forneça códigos de erro e print de telas</li>
                </ul>
              </div>
            </div>

          </div>
        </div>
      </div>
    </section>
  </div>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/3.2.0/js/adminlte.min.js"></script>
<script src="/assets/js/fetch-helpers.js"></script>
<script src="/assets/js/profile-picture-helper.js"></script>

</body>
</html>