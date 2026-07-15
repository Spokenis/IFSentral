/**
 * Profile Picture Helper
 * Funções auxiliares para carregar e exibir fotos de perfil
 */

// Caminho padrão para avatar
const DEFAULT_AVATAR = '../assets/img/default-avatar.svg';

/**
 * Carrega a foto de perfil do usuário logado e atualiza elementos da página
 */
async function loadUserProfilePicture() {
    try {
        const response = await fetch('../api/obter_perfil_usuario.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        if (!response.ok) {
            console.warn('Não foi possível carregar foto de perfil');
            return;
        }
        
        const data = await response.json();
        const user = data.user;
        
        if (user && user.profile_picture) {
            updateProfilePictureElements(user.profile_picture);
        }
    } catch (error) {
        console.error('Erro ao carregar foto de perfil:', error);
    }
}

/**
 * Atualiza todos os elementos com classe 'user-profile-picture' com a foto
 */
function updateProfilePictureElements(profilePicture) {
    const imageUrl = profilePicture ? '../../' + profilePicture : DEFAULT_AVATAR;
    
    // Atualizar imagens com classe específica
    document.querySelectorAll('.user-profile-picture').forEach(img => {
        img.src = imageUrl;
        img.style.display = 'inline-block';
    });
    
    // Atualizar avatares no navbar
    document.querySelectorAll('.navbar-user-avatar').forEach(container => {
        // Esconder ícone padrão se existir
        const icon = container.querySelector('i.fa-user-circle');
        if (icon && profilePicture) {
            icon.style.display = 'none';
        }
        
        // Criar ou atualizar imagem
        let img = container.querySelector('img.navbar-avatar-img');
        if (!img) {
            img = document.createElement('img');
            img.className = 'navbar-avatar-img rounded-circle';
            img.style.width = '32px';
            img.style.height = '32px';
            img.style.objectFit = 'cover';
            img.style.marginRight = '5px';
            container.insertBefore(img, container.firstChild);
        }
        img.src = imageUrl;
    });
}

/**
 * Cria um elemento de avatar (imagem redonda)
 */
function createAvatarElement(profilePicture, size = 40, className = '') {
    const img = document.createElement('img');
    img.src = profilePicture ? '../../' + profilePicture : DEFAULT_AVATAR;
    img.className = `rounded-circle ${className}`;
    img.style.width = size + 'px';
    img.style.height = size + 'px';
    img.style.objectFit = 'cover';
    img.style.border = '2px solid #dee2e6';
    return img;
}

/**
 * Retorna HTML para avatar inline
 */
function getAvatarHTML(profilePicture, size = 40, className = '') {
    const src = profilePicture ? '../../' + profilePicture : DEFAULT_AVATAR;
    return `<img src="${src}" class="rounded-circle ${className}" 
                 style="width: ${size}px; height: ${size}px; object-fit: cover; border: 2px solid #dee2e6;">`;
}

// Auto-carregar ao carregar a página (se estiver autenticado)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadUserProfilePicture);
} else {
    loadUserProfilePicture();
}
