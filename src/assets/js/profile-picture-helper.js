/**
 * Profile Picture Helper
 * Funções auxiliares para carregar e exibir fotos de perfil.
 *
 * Sem foto, ou com uma foto que não carrega (arquivo apagado ou perdido numa
 * migração), o avatar mostra as iniciais do nome num círculo verde do IFSC,
 * igual em todas as páginas.
 */

// Caminho padrão para avatar (antes de saber quem é o usuário)
const DEFAULT_AVATAR = 'assets/img/default-avatar.svg';
const COR_AVATAR = '#1B7D3D';

/** Até duas iniciais: primeira e última palavra do nome ("Maria da Silva" -> "MS") */
function iniciaisDoNome(nome) {
    const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
    if (partes.length === 0) return '?';
    const primeira = partes[0][0];
    const ultima = partes.length > 1 ? partes[partes.length - 1][0] : '';
    return (primeira + ultima).toUpperCase();
}

/** Imagem (data URL) com as iniciais do nome, para usar como src de <img> */
function avatarIniciaisSrc(nome) {
    const iniciais = iniciaisDoNome(nome).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
        + '<circle cx="50" cy="50" r="50" fill="' + COR_AVATAR + '"/>'
        + '<text x="50" y="50" dy=".35em" text-anchor="middle" fill="#fff" font-size="40" font-weight="600" '
        + 'font-family="Source Sans Pro, Segoe UI, Helvetica, Arial, sans-serif">' + iniciais + '</text></svg>';
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

/** URL da foto salva (caminho relativo vindo da API) ou null */
function fotoPerfilUrl(profilePicture) {
    return profilePicture || null;
}

/**
 * Mostra a foto no <img>; sem foto, ou se ela não carregar, mostra as iniciais.
 * O nome fica em data-nome para o tratamento de erro abaixo.
 */
function aplicarFotoPerfil(img, profilePicture, nome) {
    img.classList.add('avatar-usuario');
    img.dataset.nome = nome || '';
    img.src = fotoPerfilUrl(profilePicture) || avatarIniciaisSrc(nome);
}

// Qualquer <img class="avatar-usuario"> que falhar ao carregar vira iniciais.
// (Eventos de erro de imagem não sobem, por isso a captura.)
document.addEventListener('error', function (e) {
    const img = e.target;
    if (img && img.tagName === 'IMG' && img.classList.contains('avatar-usuario') && !img.src.startsWith('data:')) {
        img.src = avatarIniciaisSrc(img.dataset.nome);
    }
}, true);

/**
 * Carrega a foto de perfil do usuário logado e atualiza elementos da página
 */
async function loadUserProfilePicture() {
    try {
        const response = await fetch('api/obter-perfil-usuario', {
            method: 'GET',
            credentials: 'include',
            silenciar401: true
        });

        if (!response.ok) {
            console.warn('Não foi possível carregar foto de perfil');
            return;
        }

        const data = await response.json();
        if (data.user) {
            updateProfilePictureElements(data.user.profile_picture, data.user.name);
        }
    } catch (error) {
        console.error('Erro ao carregar foto de perfil:', error);
    }
}

/**
 * Atualiza a foto (ou as iniciais) nos elementos com classe 'user-profile-picture'
 * e no menu do usuário, no topo
 */
function updateProfilePictureElements(profilePicture, nome) {
    document.querySelectorAll('.user-profile-picture').forEach(img => {
        aplicarFotoPerfil(img, profilePicture, nome);
        img.style.display = 'inline-block';
    });

    document.querySelectorAll('.navbar-user-avatar').forEach(container => {
        const icon = container.querySelector('i.fa-user-circle');
        if (icon) {
            icon.style.display = 'none';
        }

        let img = container.querySelector('img.navbar-avatar-img');
        if (!img) {
            img = document.createElement('img');
            img.className = 'navbar-avatar-img rounded-circle';
            img.alt = '';
            // Tamanho e espaçamento em assets/css/ifscentral.css
            container.insertBefore(img, container.firstChild);
        }
        aplicarFotoPerfil(img, profilePicture, nome);
    });
}

/**
 * Cria um elemento de avatar (imagem redonda)
 */
function createAvatarElement(profilePicture, size = 40, className = '', nome = '') {
    const img = document.createElement('img');
    img.className = `rounded-circle ${className}`;
    img.style.width = size + 'px';
    img.style.height = size + 'px';
    img.style.objectFit = 'cover';
    aplicarFotoPerfil(img, profilePicture, nome);
    return img;
}

/**
 * Retorna HTML para avatar inline
 */
function getAvatarHTML(profilePicture, size = 40, className = '', nome = '') {
    const escapar = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const src = fotoPerfilUrl(profilePicture) || avatarIniciaisSrc(nome);
    return `<img src="${escapar(src)}" data-nome="${escapar(nome)}" alt="${escapar(nome)}" class="avatar-usuario rounded-circle ${escapar(className)}"
                 style="width: ${size}px; height: ${size}px; object-fit: cover;">`;
}

// Auto-carregar ao carregar a página (se estiver autenticado)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadUserProfilePicture);
} else {
    loadUserProfilePicture();
}
