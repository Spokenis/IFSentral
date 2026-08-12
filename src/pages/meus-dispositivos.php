<?php
/**
 * Página: Meus Dispositivos
 * Lista todos os dispositivos de todos os projetos do usuário
 */

require_once __DIR__ . '/../auth/auth_check.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Dispositivos - IFSentral</title>
    
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

        .device-card {
            transition: all 0.3s;
            border-left: 4px solid var(--ifsc-primary);
        }
        .device-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .project-section {
            margin-bottom: 30px;
        }
        .project-header {
            background: linear-gradient(135deg, var(--ifsc-primary) 0%, var(--ifsc-secondary) 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .role-gerente { background: #28a745; color: white; }
        .role-participante { background: #6c757d; color: white; }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        .stats-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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
                        <h1><i class="fas fa-microchip mr-2"></i>Meus Dispositivos</h1>
                    </div>
                </div>
            </div>
        </section>
        
        <section class="content">
            <div class="container">
                <div class="row">
                    <div class="col-md-6">
                        <div class="stats-box">
                            <div class="d-flex align-items-center">
                                <div class="mr-3">
                                    <i class="fas fa-microchip fa-3x" style="color: var(--ifsc-primary);"></i>
                                </div>
                                <div>
                                    <h5 class="mb-0" id="total-devices">0</h5>
                                    <small class="text-muted">Dispositivos Total</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stats-box">
                            <div class="d-flex align-items-center">
                                <div class="mr-3">
                                    <i class="fas fa-project-diagram fa-3x text-success"></i>
                                </div>
                                <div>
                                    <h5 class="mb-0" id="total-projects">0</h5>
                                    <small class="text-muted">Projetos Ativos</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="loading-state" class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-3x" style="color: var(--ifsc-primary);"></i>
                    <p class="mt-3">Carregando dispositivos...</p>
                </div>
                
                <div id="empty-state" class="empty-state" style="display: none;">
                    <i class="fas fa-microchip"></i>
                    <h4>Nenhum dispositivo encontrado</h4>
                    <p>Você ainda não possui dispositivos cadastrados em seus projetos.</p>
                    <a href="meus-projetos.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus mr-2"></i>Ver Meus Projetos
                    </a>
                </div>
                
                <div id="devices-container"></div>
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
    const API_LISTAR_DISPOSITIVOS = '../api/listar_meus_dispositivos.php';
    
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');
    const devicesContainer = document.getElementById('devices-container');
    const totalDevicesEl = document.getElementById('total-devices');
    const totalProjectsEl = document.getElementById('total-projects');
    
    async function carregarDispositivos() {
        try {
            loadingState.style.display = 'block';
            emptyState.style.display = 'none';
            devicesContainer.innerHTML = '';
            
            const response = await fetch(API_LISTAR_DISPOSITIVOS, {
                method: 'GET',
                credentials: 'include'
            });
            
            const data = await safeJson(response);
            
            loadingState.style.display = 'none';
            
            totalDevicesEl.textContent = data.total_devices || 0;
            totalProjectsEl.textContent = data.total_projects || 0;
            
            if (!data.data || data.data.length === 0) {
                emptyState.style.display = 'block';
                return;
            }
            
            data.data.forEach(project => {
                renderProject(project);
            });
            
        } catch (error) {
            console.error('Erro ao carregar dispositivos:', error);
            loadingState.style.display = 'none';
            emptyState.style.display = 'block';
        }
    }
    
    function renderProject(project) {
        const roleClass = project.role_name === 'Gerente' ? 'role-gerente' : 'role-participante';
        
        const projectSection = document.createElement('div');
        projectSection.className = 'project-section';
        
        projectSection.innerHTML = `
            <div class="project-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1">
                            <i class="fas fa-project-diagram mr-2"></i>${escapeHtml(project.project_name)}
                        </h4>
                        <p class="mb-0 opacity-75">${escapeHtml(project.project_description || 'Sem descrição')}</p>
                    </div>
                    <div>
                        <span class="role-badge ${roleClass}">
                            <i class="fas ${project.role_name === 'Gerente' ? 'fa-crown' : 'fa-user'} mr-1"></i>
                            ${escapeHtml(project.role_name)}
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="row" id="devices-project-${project.project_id}"></div>
        `;
        
        devicesContainer.appendChild(projectSection);
        
        const devicesRow = document.getElementById(`devices-project-${project.project_id}`);
        
        project.devices.forEach(device => {
            const deviceCard = document.createElement('div');
            deviceCard.className = 'col-md-6 col-lg-4 mb-3';
            
            deviceCard.innerHTML = `
                <div class="card device-card h-100">
                    <div class="card-body">
                        <h5 class="card-title" style="color: var(--ifsc-primary);">
                            <i class="fas fa-microchip mr-2"></i>
                            ${escapeHtml(device.name)}
                        </h5>
                        <p class="card-text text-muted small mt-2">
                            ${escapeHtml(device.description || 'Sem descrição')}
                        </p>
                        
                        <div class="mt-3">
                            <small class="text-muted d-block mb-1">
                                <i class="fas fa-key mr-1"></i>
                                <strong>API Key:</strong>
                            </small>
                            <code class="d-block bg-light p-2 rounded small" style="font-size: 0.75rem; word-break: break-all;">
                                ${escapeHtml(device.api_key || 'N/A')}
                            </code>
                        </div>
                        
                        <div class="mt-3 pt-3 border-top">
                            <small class="text-muted d-block">
                                <i class="fas fa-clock mr-1"></i>
                                Criado em: ${formatDate(device.createdAt)}
                            </small>
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <a href="gerenciar-projeto.php?id=${project.project_id}" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye mr-1"></i>Ver Projeto
                        </a>
                    </div>
                </div>
            `;
            
            devicesRow.appendChild(deviceCard);
        });
    }
    
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('pt-BR', { 
            day: '2-digit', 
            month: '2-digit', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    carregarDispositivos();
</script>

</body>
</html>