<?php
/**
 * Copie este arquivo para "env.php" (mesma pasta) e preencha com as
 * credenciais reais do seu servidor. O "env.php" nunca deve ir para o Git.
 *
 * Prefira este arquivo ao ".env": por ser PHP, o servidor web nunca entrega
 * o conteúdo dele como texto, mesmo que o .htaccess seja ignorado.
 */

return [
    // Banco de Dados (MySQL/MariaDB já existente no servidor)
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'ifsentral_bd',
    'DB_USER' => 'ifsentral_user',
    'DB_PASS' => 'SUBSTITUA_POR_UMA_SENHA_FORTE_AQUI',

    // Aplicação
    'APP_ENV' => 'production',

    // Endereço público do site, incluindo a subpasta se houver
    // (ex.: 'http://servidor.ifsc.edu.br/site1'). É usado nos links enviados
    // por e-mail. Deixe vazio para usar o endereço pelo qual o site for acessado.
    'APP_URL' => '',

    // Só necessário se outro site (outro domínio) for chamar a API pelo navegador
    'ALLOWED_ORIGINS' => '',

    // Mude para true SOMENTE se o servidor tiver HTTPS configurado: redireciona
    // todo acesso HTTP para HTTPS. Com false o site funciona nos dois.
    'FORCE_HTTPS' => false,

    // false = ativa contas imediatamente, sem confirmação por e-mail
    // (use quando não houver SMTP configurado)
    'ENABLE_EMAIL_FEATURES' => false,

    // Sessão (a flag Secure do cookie é ligada sozinha quando o acesso é HTTPS)
    'SESSION_HTTPONLY' => true,
    'SESSION_SAMESITE' => 'Lax',

    // E-mail / SMTP — só usado se ENABLE_EMAIL_FEATURES for true
    'SMTP_HOST' => 'smtp.seudominio.com',
    'SMTP_PORT' => 465,
    'SMTP_USER' => 'suporte@seudominio.com',
    'SMTP_PASS' => 'SUBSTITUA_PELA_SENHA_DO_E-MAIL',
    'SMTP_ENCRYPTION' => 'ssl',
    'MAIL_FROM_ADDRESS' => 'suporte@seudominio.com',
    'MAIL_FROM_NAME' => 'IFSentral Lite Smart Campus',
];
