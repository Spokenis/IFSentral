<?php
/**
 * Página: Configurações Gerais
 * Permite usuário atualizar suas informações pessoais e alterar senha
 */

require_once __DIR__ . '/../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - IFSentral</title>
    
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

        /* Tema IFSC para Botões */
        .btn-primary, .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
            background-color: var(--ifsc-primary) !important;
            border-color: var(--ifsc-primary) !important;
        }
        .btn-primary:hover {
            background-color: var(--ifsc-secondary) !important;
        }

        /* Cards Normais (fundo do cabeçalho preenchido) */
        .card-primary:not(.card-outline) > .card-header {
            background-color: var(--ifsc-primary) !important;
            color: #fff !important;
        }

        /* Cards Outline (apenas a linha superior colorida) */
        .card-primary.card-outline {
            border-top-color: var(--ifsc-primary) !important;
        }

        /* Cor do link ativo no menu lateral (Nav Pills) */
        .nav-pills .nav-link.active,
        .nav-pills .show > .nav-link {
            background-color: var(--ifsc-primary) !important;
            color: #fff !important;
        }

        .navbar-light .navbar-brand {
            color: var(--ifsc-primary) !important;
        }

        .profile-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .profile-badge.admin { background: #dc3545; color: white; }
        .profile-badge.moderator { background: #28a745; color: white; }
        .profile-badge.user { background: #6c757d; color: white; }
        
        .form-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .form-section h4 {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
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
                        <h1><i class="fas fa-cog mr-2"></i>Configurações</h1>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="content">
            <div class="container">
                <div class="row">
                    <div class="col-md-3">
                        <div class="card card-primary card-outline">
                            <div class="card-header">
                                <h3 class="card-title">Menu</h3>
                            </div>
                            <div class="card-body p-0">
                                <ul class="nav nav-pills flex-column">
                                    <li class="nav-item">
                                        <a href="#foto" class="nav-link active" data-toggle="tab">
                                            <i class="fas fa-camera mr-2"></i> Foto de Perfil
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#perfil" class="nav-link" data-toggle="tab">
                                            <i class="fas fa-user mr-2"></i> Informações Pessoais
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#seguranca" class="nav-link" data-toggle="tab">
                                            <i class="fas fa-lock mr-2"></i> Segurança
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#conta" class="nav-link" data-toggle="tab">
                                            <i class="fas fa-id-card mr-2"></i> Minha Conta
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-9">
                        <div class="tab-content">
                            <div class="tab-pane active" id="foto">
                                <div class="form-section">
                                    <h4><i class="fas fa-camera mr-2"></i>Foto de Perfil</h4>
                                    
                                    <div class="row">
                                        <div class="col-md-4 text-center">
                                            <div class="mb-3">
                                                <img id="preview-foto" src="/assets/img/default-avatar.svg" 
                                                     alt="Foto de Perfil" 
                                                     class="img-fluid rounded-circle" 
                                                     style="width: 150px; height: 150px; object-fit: cover; border: 3px solid var(--ifsc-primary);">
                                            </div>
                                            <p class="text-muted"><small>JPG, PNG, GIF ou WEBP<br>Máximo 5MB</small></p>
                                        </div>
                                        
                                        <div class="col-md-8">
                                            <form id="form-foto">
                                                <div class="form-group">
                                                    <label for="input-foto">Selecione uma foto</label>
                                                    <div class="custom-file">
                                                        <input type="file" class="custom-file-input" id="input-foto" accept="image/*">
                                                        <label class="custom-file-label" for="input-foto">Escolher arquivo</label>
                                                    </div>
                                                </div>
                                                
                                                <div id="foto-status"></div>
                                                
                                                <div class="btn-group mt-2" role="group">
                                                    <button type="submit" class="btn btn-primary" id="btn-upload-foto">
                                                        <i class="fas fa-upload mr-2"></i>Fazer Upload
                                                    </button>
                                                    <button type="button" class="btn btn-danger" id="btn-deletar-foto">
                                                        <i class="fas fa-trash mr-2"></i>Remover Foto
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane" id="perfil">
                                <div class="form-section">
                                    <h4><i class="fas fa-user mr-2"></i>Informações Pessoais</h4>
                                    
                                    <form id="form-perfil">
                                        <div class="form-group">
                                            <label for="input-name">Nome Completo</label>
                                            <input type="text" class="form-control" id="input-name" placeholder="Seu nome completo">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="input-email">E-mail</label>
                                            <input type="email" class="form-control" id="input-email" placeholder="seu@email.com">
                                            <small class="form-text text-muted">Usado para login e notificações</small>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="input-username">Username</label>
                                            <input type="text" class="form-control" id="input-username" placeholder="seu_username">
                                            <small class="form-text text-muted">Entre 3 e 20 caracteres</small>
                                        </div>
                                        
                                        <div id="perfil-status"></div>
                                        
                                        <button type="submit" class="btn btn-primary" id="btn-salvar-perfil">
                                            <i class="fas fa-save mr-2"></i>Salvar Alterações
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <div class="tab-pane" id="seguranca">
                                <div class="form-section">
                                    <h4><i class="fas fa-lock mr-2"></i>Alterar Senha</h4>
                                    
                                    <form id="form-senha">
                                        <div class="form-group">
                                            <label for="input-current-password">Senha Atual</label>
                                            <input type="password" class="form-control" id="input-current-password" placeholder="Digite sua senha atual">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="input-new-password">Nova Senha</label>
                                            <input type="password" class="form-control" id="input-new-password" placeholder="Digite a nova senha">
                                            <small class="form-text text-muted">Mínimo de 6 caracteres</small>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="input-confirm-password">Confirmar Nova Senha</label>
                                            <input type="password" class="form-control" id="input-confirm-password" placeholder="Confirme a nova senha">
                                        </div>
                                        
                                        <div id="senha-status"></div>
                                        
                                        <button type="submit" class="btn btn-warning" id="btn-alterar-senha">
                                            <i class="fas fa-key mr-2"></i>Alterar Senha
                                        </button>
                                    </form>
                                </div>

                                <div class="form-section" id="secao-2fa">
                                    <h4><i class="fas fa-shield-alt mr-2"></i>Autenticação de Dois Fatores (2FA)</h4>

                                    <div id="twofa-loading" class="text-muted">Carregando status...</div>

                                    <!-- Estado: 2FA desativado -->
                                    <div id="twofa-disabled-view" style="display:none;">
                                        <p class="text-muted">Adicione uma camada extra de segurança: além da senha, será exigido um código gerado por um aplicativo autenticador (Google Authenticator, Authy, etc.) a cada login.</p>
                                        <button type="button" class="btn btn-primary" id="btn-iniciar-2fa">
                                            <i class="fas fa-shield-alt mr-2"></i>Ativar 2FA
                                        </button>
                                    </div>

                                    <!-- Estado: configurando (mostra segredo + pede código de confirmação) -->
                                    <div id="twofa-setup-view" style="display:none;">
                                        <p>1. Adicione esta chave no seu aplicativo autenticador (entrada manual):</p>
                                        <div class="input-group mb-3">
                                            <input type="text" class="form-control" id="twofa-secret" readonly>
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-secondary" type="button" id="btn-copiar-secret">Copiar</button>
                                            </div>
                                        </div>
                                        <p class="text-muted"><small>Ou use a URI de configuração: <span id="twofa-uri" style="word-break: break-all;"></span></small></p>

                                        <p>2. Digite o código de 6 dígitos gerado pelo aplicativo para confirmar:</p>
                                        <form id="form-confirmar-2fa">
                                            <div class="form-group">
                                                <input type="text" class="form-control" id="input-2fa-confirmar-codigo" placeholder="000000" inputmode="numeric" maxlength="6">
                                            </div>
                                            <div id="twofa-setup-status"></div>
                                            <button type="submit" class="btn btn-primary" id="btn-confirmar-2fa">Confirmar e ativar</button>
                                            <button type="button" class="btn btn-link" id="btn-cancelar-2fa">Cancelar</button>
                                        </form>
                                    </div>

                                    <!-- Estado: códigos de backup (mostrados uma única vez) -->
                                    <div id="twofa-backup-codes-view" style="display:none;">
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            <strong>Guarde estes códigos de backup em um local seguro.</strong> Cada um pode ser usado uma única vez para entrar caso você perca acesso ao seu aplicativo autenticador. Eles não serão mostrados novamente.
                                        </div>
                                        <pre id="twofa-backup-codes-list" class="bg-light p-3"></pre>
                                        <button type="button" class="btn btn-primary" id="btn-concluir-2fa">Já salvei meus códigos</button>
                                    </div>

                                    <!-- Estado: 2FA ativado -->
                                    <div id="twofa-enabled-view" style="display:none;">
                                        <p><span class="badge badge-success"><i class="fas fa-check mr-1"></i>2FA está ativado</span></p>
                                        <p class="text-muted">Para desativar, confirme sua senha atual.</p>
                                        <form id="form-desativar-2fa" class="form-inline">
                                            <input type="password" class="form-control mr-2" id="input-2fa-senha-desativar" placeholder="Senha atual">
                                            <button type="submit" class="btn btn-danger" id="btn-desativar-2fa">Desativar 2FA</button>
                                        </form>
                                        <div id="twofa-disable-status"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane" id="conta">
                                <div class="form-section">
                                    <h4><i class="fas fa-id-card mr-2"></i>Informações da Conta</h4>
                                    
                                    <table class="table table-borderless">
                                        <tbody>
                                            <tr>
                                                <td><strong>ID do Usuário:</strong></td>
                                                <td id="info-user-id">-</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Perfil:</strong></td>
                                                <td id="info-profile">-</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Data de Criação:</strong></td>
                                                <td id="info-created">-</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Última Atualização:</strong></td>
                                                <td id="info-updated">-</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    
                                    <div class="alert alert-info mt-3">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        <strong>Nota:</strong> Apenas administradores podem alterar o perfil de usuários.
                                    </div>
                                </div>
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

<script>
    const API_OBTER_PERFIL = '/api/obter-perfil-usuario';
    const API_ATUALIZAR_PERFIL = '/api/atualizar-perfil';
    const API_ATUALIZAR_SENHA = '/api/atualizar-senha';
    const API_UPLOAD_FOTO = '/api/upload-foto-perfil';
    const API_DELETAR_FOTO = '/api/deletar-foto-perfil';
    const API_2FA_STATUS = '/api/2fa-status';
    const API_2FA_INICIAR = '/api/2fa-iniciar-configuracao';
    const API_2FA_CONFIRMAR = '/api/2fa-confirmar-configuracao';
    const API_2FA_DESATIVAR = '/api/2fa-desativar';
    
    const formPerfil = document.getElementById('form-perfil');
    const inputName = document.getElementById('input-name');
    const inputEmail = document.getElementById('input-email');
    const inputUsername = document.getElementById('input-username');
    const btnSalvarPerfil = document.getElementById('btn-salvar-perfil');
    const perfilStatusEl = document.getElementById('perfil-status');
    
    const formSenha = document.getElementById('form-senha');
    const inputCurrentPassword = document.getElementById('input-current-password');
    const inputNewPassword = document.getElementById('input-new-password');
    const inputConfirmPassword = document.getElementById('input-confirm-password');
    const btnAlterarSenha = document.getElementById('btn-alterar-senha');
    const senhaStatusEl = document.getElementById('senha-status');
    
    const formFoto = document.getElementById('form-foto');
    const inputFoto = document.getElementById('input-foto');
    const previewFoto = document.getElementById('preview-foto');
    const btnUploadFoto = document.getElementById('btn-upload-foto');
    const btnDeletarFoto = document.getElementById('btn-deletar-foto');
    const fotoStatusEl = document.getElementById('foto-status');
    
    const infoUserId = document.getElementById('info-user-id');
    const infoProfile = document.getElementById('info-profile');
    const infoCreated = document.getElementById('info-created');
    const infoUpdated = document.getElementById('info-updated');

    const twofaLoading = document.getElementById('twofa-loading');
    const twofaDisabledView = document.getElementById('twofa-disabled-view');
    const twofaSetupView = document.getElementById('twofa-setup-view');
    const twofaBackupCodesView = document.getElementById('twofa-backup-codes-view');
    const twofaEnabledView = document.getElementById('twofa-enabled-view');
    const btnIniciar2fa = document.getElementById('btn-iniciar-2fa');
    const twofaSecretInput = document.getElementById('twofa-secret');
    const twofaUriEl = document.getElementById('twofa-uri');
    const btnCopiarSecret = document.getElementById('btn-copiar-secret');
    const formConfirmar2fa = document.getElementById('form-confirmar-2fa');
    const inputConfirmarCodigo = document.getElementById('input-2fa-confirmar-codigo');
    const twofaSetupStatus = document.getElementById('twofa-setup-status');
    const btnCancelar2fa = document.getElementById('btn-cancelar-2fa');
    const twofaBackupCodesList = document.getElementById('twofa-backup-codes-list');
    const btnConcluir2fa = document.getElementById('btn-concluir-2fa');
    const formDesativar2fa = document.getElementById('form-desativar-2fa');
    const inputSenhaDesativar2fa = document.getElementById('input-2fa-senha-desativar');
    const twofaDisableStatus = document.getElementById('twofa-disable-status');
    
    async function carregarPerfil() {
        try {
            const response = await fetch(API_OBTER_PERFIL, {
                method: 'GET',
                credentials: 'include'
            });
            
            const data = await safeJson(response);
            const user = data.user;
            
            inputName.value = user.name || '';
            inputEmail.value = user.email || '';
            inputUsername.value = user.username || '';
            
            if (user.profile_picture) {
                previewFoto.src = '/' + user.profile_picture;
                btnDeletarFoto.disabled = false;
            } else {
                previewFoto.src = '/assets/img/default-avatar.svg';
                btnDeletarFoto.disabled = true;
            }
            
            infoUserId.textContent = user.id;
            
            const profileClass = user.profile.toLowerCase();
            infoProfile.innerHTML = `<span class="profile-badge ${profileClass}">${user.profile}</span>`;
            
            infoCreated.textContent = new Date(user.createdAt).toLocaleString('pt-BR');
            infoUpdated.textContent = new Date(user.updatedAt).toLocaleString('pt-BR');
            
        } catch (error) {
            console.error('Erro ao carregar perfil:', error);
        }
    }
    
    async function atualizarPerfil(e) {
        e.preventDefault();
        
        const name = inputName.value.trim();
        const email = inputEmail.value.trim();
        const username = inputUsername.value.trim();
        
        perfilStatusEl.innerHTML = '';
        
        if (!name || !email || !username) {
            perfilStatusEl.innerHTML = '<div class="alert alert-warning mt-3 mb-0">Preencha todos os campos</div>';
            return;
        }
        
        btnSalvarPerfil.disabled = true;
        btnSalvarPerfil.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Salvando...';
        
        try {
            const response = await fetch(API_ATUALIZAR_PERFIL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ name, email, username })
            });
            
            const result = await safeJson(response);
            perfilStatusEl.innerHTML = '<div class="alert alert-success mt-3 mb-0"><i class="fas fa-check-circle mr-2"></i>' + result.message + '</div>';
            
            setTimeout(() => {
                carregarPerfil();
                perfilStatusEl.innerHTML = '';
            }, 2000);
            
        } catch (error) {
            perfilStatusEl.innerHTML = '<div class="alert alert-danger mt-3 mb-0"><i class="fas fa-exclamation-circle mr-2"></i>' + error.message + '</div>';
        } finally {
            btnSalvarPerfil.disabled = false;
            btnSalvarPerfil.innerHTML = '<i class="fas fa-save mr-2"></i>Salvar Alterações';
        }
    }
    
    async function alterarSenha(e) {
        e.preventDefault();
        
        const current_password = inputCurrentPassword.value;
        const new_password = inputNewPassword.value;
        const confirm_password = inputConfirmPassword.value;
        
        senhaStatusEl.innerHTML = '';
        
        if (!current_password || !new_password || !confirm_password) {
            senhaStatusEl.innerHTML = '<div class="alert alert-warning mt-3 mb-0">Preencha todos os campos</div>';
            return;
        }
        
        if (new_password !== confirm_password) {
            senhaStatusEl.innerHTML = '<div class="alert alert-warning mt-3 mb-0">As senhas não coincidem</div>';
            return;
        }
        
        if (new_password.length < 6) {
            senhaStatusEl.innerHTML = '<div class="alert alert-warning mt-3 mb-0">A nova senha deve ter no mínimo 6 caracteres</div>';
            return;
        }
        
        btnAlterarSenha.disabled = true;
        btnAlterarSenha.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Alterando...';
        
        try {
            const response = await fetch(API_ATUALIZAR_SENHA, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ current_password, new_password, confirm_password })
            });
            
            const result = await safeJson(response);
            senhaStatusEl.innerHTML = '<div class="alert alert-success mt-3 mb-0"><i class="fas fa-check-circle mr-2"></i>' + result.message + '</div>';
            
            formSenha.reset();
            
            setTimeout(() => {
                senhaStatusEl.innerHTML = '';
            }, 3000);
            
        } catch (error) {
            senhaStatusEl.innerHTML = '<div class="alert alert-danger mt-3 mb-0"><i class="fas fa-exclamation-circle mr-2"></i>' + error.message + '</div>';
        } finally {
            btnAlterarSenha.disabled = false;
            btnAlterarSenha.innerHTML = '<i class="fas fa-key mr-2"></i>Alterar Senha';
        }
    }
    
    function previewFotoSelecionada() {
        const file = inputFoto.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewFoto.src = e.target.result;
            };
            reader.readAsDataURL(file);
            
            const fileName = file.name;
            document.querySelector('.custom-file-label').textContent = fileName;
        }
    }
    
    async function uploadFoto(e) {
        e.preventDefault();
        
        fotoStatusEl.innerHTML = '';
        
        const file = inputFoto.files[0];
        if (!file) {
            fotoStatusEl.innerHTML = '<div class="alert alert-warning mt-3 mb-0">Selecione uma foto primeiro</div>';
            return;
        }
        
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            fotoStatusEl.innerHTML = '<div class="alert alert-danger mt-3 mb-0">O arquivo é muito grande. Máximo 5MB</div>';
            return;
        }
        
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            fotoStatusEl.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WEBP</div>';
            return;
        }
        
        btnUploadFoto.disabled = true;
        btnUploadFoto.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enviando...';
        
        try {
            const formData = new FormData();
            formData.append('profile_picture', file);
            
            const response = await fetch(API_UPLOAD_FOTO, {
                method: 'POST',
                credentials: 'include',
                body: formData
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Erro ao fazer upload');
            }
            
            const result = await response.json();
            fotoStatusEl.innerHTML = '<div class="alert alert-success mt-3 mb-0"><i class="fas fa-check-circle mr-2"></i>' + result.message + '</div>';
            
            setTimeout(() => {
                carregarPerfil();
                fotoStatusEl.innerHTML = '';
                formFoto.reset();
                document.querySelector('.custom-file-label').textContent = 'Escolher arquivo';
            }, 2000);
            
        } catch (error) {
            fotoStatusEl.innerHTML = '<div class="alert alert-danger mt-3 mb-0"><i class="fas fa-exclamation-circle mr-2"></i>' + error.message + '</div>';
        } finally {
            btnUploadFoto.disabled = false;
            btnUploadFoto.innerHTML = '<i class="fas fa-upload mr-2"></i>Fazer Upload';
        }
    }
    
    async function deletarFoto() {
        if (!confirm('Tem certeza que deseja remover sua foto de perfil?')) {
            return;
        }
        
        fotoStatusEl.innerHTML = '';
        btnDeletarFoto.disabled = true;
        btnDeletarFoto.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Removendo...';
        
        try {
            const response = await fetch(API_DELETAR_FOTO, {
                method: 'POST',
                credentials: 'include'
            });
            
            const result = await safeJson(response);
            fotoStatusEl.innerHTML = '<div class="alert alert-success mt-3 mb-0"><i class="fas fa-check-circle mr-2"></i>' + result.message + '</div>';
            
            setTimeout(() => {
                carregarPerfil();
                fotoStatusEl.innerHTML = '';
            }, 2000);
            
        } catch (error) {
            fotoStatusEl.innerHTML = '<div class="alert alert-danger mt-3 mb-0"><i class="fas fa-exclamation-circle mr-2"></i>' + error.message + '</div>';
            btnDeletarFoto.disabled = false;
            btnDeletarFoto.innerHTML = '<i class="fas fa-trash mr-2"></i>Remover Foto';
        }
    }
    
    function mostrarView2fa(view) {
        [twofaLoading, twofaDisabledView, twofaSetupView, twofaBackupCodesView, twofaEnabledView].forEach(function(el) {
            el.style.display = 'none';
        });
        view.style.display = 'block';
    }

    async function carregar2FAStatus() {
        try {
            const response = await fetch(API_2FA_STATUS, { credentials: 'include' });
            const data = await safeJson(response);
            mostrarView2fa(data.enabled ? twofaEnabledView : twofaDisabledView);
        } catch (error) {
            twofaLoading.textContent = 'Erro ao carregar status do 2FA: ' + error.message;
        }
    }

    async function iniciar2FA() {
        btnIniciar2fa.disabled = true;
        try {
            const response = await fetch(API_2FA_INICIAR, { method: 'POST', credentials: 'include' });
            const data = await safeJson(response);
            twofaSecretInput.value = data.secret;
            twofaUriEl.textContent = data.otpauth_uri;
            inputConfirmarCodigo.value = '';
            twofaSetupStatus.innerHTML = '';
            mostrarView2fa(twofaSetupView);
        } catch (error) {
            alert('Erro ao iniciar configuração do 2FA: ' + error.message);
        } finally {
            btnIniciar2fa.disabled = false;
        }
    }

    async function confirmar2FA(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-confirmar-2fa');
        btn.disabled = true;
        twofaSetupStatus.innerHTML = '';

        try {
            const response = await fetch(API_2FA_CONFIRMAR, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ code: inputConfirmarCodigo.value.trim() })
            });
            const data = await safeJson(response);

            twofaBackupCodesList.textContent = data.backup_codes.join('\n');
            mostrarView2fa(twofaBackupCodesView);
        } catch (error) {
            twofaSetupStatus.innerHTML = '<div class="alert alert-danger mt-3 mb-0">' + error.message + '</div>';
        } finally {
            btn.disabled = false;
        }
    }

    async function desativar2FA(e) {
        e.preventDefault();
        const senha = inputSenhaDesativar2fa.value;
        if (!senha) {
            twofaDisableStatus.innerHTML = '<div class="alert alert-warning mt-3 mb-0">Informe sua senha atual</div>';
            return;
        }

        document.getElementById('btn-desativar-2fa').disabled = true;
        twofaDisableStatus.innerHTML = '';

        try {
            const response = await fetch(API_2FA_DESATIVAR, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ password: senha })
            });
            await safeJson(response);
            formDesativar2fa.reset();
            mostrarView2fa(twofaDisabledView);
        } catch (error) {
            twofaDisableStatus.innerHTML = '<div class="alert alert-danger mt-3 mb-0">' + error.message + '</div>';
        } finally {
            document.getElementById('btn-desativar-2fa').disabled = false;
        }
    }

    btnIniciar2fa.addEventListener('click', iniciar2FA);
    formConfirmar2fa.addEventListener('submit', confirmar2FA);
    btnCancelar2fa.addEventListener('click', function () { mostrarView2fa(twofaDisabledView); });
    btnConcluir2fa.addEventListener('click', carregar2FAStatus);
    formDesativar2fa.addEventListener('submit', desativar2FA);
    btnCopiarSecret.addEventListener('click', function () {
        twofaSecretInput.select();
        navigator.clipboard && navigator.clipboard.writeText(twofaSecretInput.value);
    });

    formPerfil.addEventListener('submit', atualizarPerfil);
    formSenha.addEventListener('submit', alterarSenha);
    formFoto.addEventListener('submit', uploadFoto);
    inputFoto.addEventListener('change', previewFotoSelecionada);
    btnDeletarFoto.addEventListener('click', deletarFoto);

    carregarPerfil();
    carregar2FAStatus();
</script>

</body>
</html>