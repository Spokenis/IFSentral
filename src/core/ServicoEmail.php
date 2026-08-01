<?php
// Arquivo: src/core/ServicoEmail.php

// Ajuste os caminhos conforme a localização real da pasta vendor e do seu config
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php'; // Ou o caminho correto para o seu config.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class ServicoEmail {
    private $mailer;

    public function __construct() {
        $this->mailer = new PHPMailer(true);
        
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host       = SMTP_HOST;
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = SMTP_USER;
            $this->mailer->Password   = SMTP_PASS;
            $this->mailer->SMTPSecure = SMTP_ENCRYPTION; 
            $this->mailer->Port       = SMTP_PORT;
            $this->mailer->CharSet    = 'UTF-8';
            $this->mailer->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        } catch (Exception $e) {
            error_log("Erro ao configurar PHPMailer: {$this->mailer->ErrorInfo}");
        }
    }

    public function enviarEmailConvite($paraEmail, $nomeProjeto, $nomeRemetente) {
        $baseUrl = rtrim(APP_URL, '/');
        $linkSistema = $baseUrl . "/src/pages/login.html";
        
        $assunto = "Convite para participar do projeto: {$nomeProjeto}";
        $corpo = "
            <h2>Você foi convidado!</h2>
            <p>O usuário <b>{$nomeRemetente}</b> convidou você para participar do projeto <b>{$nomeProjeto}</b> no IFSentral - Smart Campus.</p>
            <p>Para aceitar, acesse o sistema, faça login (ou crie sua conta usando este e-mail) e verifique seus convites pendentes.</p>
            <p><a href='{$linkSistema}' style='padding: 10px 15px; background-color: #1B7D3D; color: white; text-decoration: none; border-radius: 5px;'>Acessar o Sistema</a></p>
        ";

        return $this->enviar($paraEmail, 'Convidado', $assunto, $corpo);
    }

    public function enviarEmailConfirmacao($paraEmail, $paraNome, $token) {
        $baseUrl = str_replace('/src/pages/index.html', '', APP_URL);
        $linkVerificacao = $baseUrl . "/src/auth/verificar_email.php?token=" . $token;
        
        $assunto = "Confirme seu cadastro no IFSentral - Smart Campus";
        $corpo = "
            <h2>Bem-vindo ao IFSentral, {$paraNome}!</h2>
            <p>Para concluir seu cadastro e ativar sua conta, por favor, clique no link abaixo:</p>
            <p><a href='{$linkVerificacao}' style='padding: 10px 15px; background-color: #1B7D3D; color: white; text-decoration: none; border-radius: 5px;'>Confirmar meu E-mail</a></p>
            <br>
            <p>Ou copie e cole este link no seu navegador:</p>
            <p>{$linkVerificacao}</p>
            <p><small>Este link expira em 24 horas.</small></p>
        ";

        return $this->enviar($paraEmail, $paraNome, $assunto, $corpo);
    }

    private function enviar($paraEmail, $paraNome, $assunto, $corpo) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($paraEmail, $paraNome);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $assunto;
            $this->mailer->Body    = $corpo;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\r\n", "\r\n\r\n"], $corpo));
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Erro ao enviar e-mail para {$paraEmail}: {$this->mailer->ErrorInfo}");
            return false;
        }
    }
}
