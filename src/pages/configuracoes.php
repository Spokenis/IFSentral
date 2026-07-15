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
                                                <img id="preview-foto" src="../assets/img/default-avatar.svg" 
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
                                            <label for="input-email">Email</label>
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
<script src="../assets/js/fetch-helpers.js"></script>
<script src="../assets/js/profile-picture-helper.js"></script>

<script>
    const API_OBTER_PERFIL = '../api/obter_perfil_usuario.php';
    const API_ATUALIZAR_PERFIL = '../api/atualizar_perfil.php';
    const API_ATUALIZAR_SENHA = '../api/atualizar_senha.php';
    const API_UPLOAD_FOTO = '../api/upload_foto_perfil.php';
    const API_DELETAR_FOTO = '../api/deletar_foto_perfil.php';
    
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
                previewFoto.src = '../../' + user.profile_picture;
                btnDeletarFoto.disabled = false;
            } else {
                previewFoto.src = '../assets/img/default-avatar.svg';
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
    
    formPerfil.addEventListener('submit', atualizarPerfil);
    formSenha.addEventListener('submit', alterarSenha);
    formFoto.addEventListener('submit', uploadFoto);
    inputFoto.addEventListener('change', previewFotoSelecionada);
    btnDeletarFoto.addEventListener('click', deletarFoto);
    
    carregarPerfil();
</script>

</body>
</html>