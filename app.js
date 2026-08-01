// ===================== APP.JS: LÓGICA COMPARTIDA Y PETICIONES =====================

// Helper para determinar endpoint según la entidad
function getEndpointUrl(entity) {
    const isFile = window.location.protocol === 'file:';
    const baseUrl = isFile ? 'http://127.0.0.1:8000/' : './';

    const map = {
        'login': 'api_login.php?action=login',
        'logout': 'api_login.php?action=logout',
        'check': 'api_login.php?action=check',
        'clientes': 'api_clientes.php',
        'clientes_select': 'api_clientes.php?action=select',
        'productos': 'api_productos.php',
        'productos_select': 'api_productos.php?action=select',
        'pedidos': 'api_pedidos.php',
        'pedido_detalles': 'api_pedidos.php?action=detalles',
        'usuarios': 'api_usuarios.php',
        'reportes': 'api_reportes.php',
        'sistema': 'api_sistema.php'
    };

    const script = map[entity] || 'api.php?action=' + entity;
    return baseUrl + script;
}

// ── Foto helpers ────────────────────────────────────────────────────────────────
function getFotoUrl(fotoPath) {
    if (!fotoPath) return null;
    if (fotoPath.startsWith('data:')) return null; // legacy truncated base64 — ignore
    const isFile = window.location.protocol === 'file:';
    const base = isFile ? 'http://127.0.0.1:8000/' : './';
    return base + fotoPath;
}

function openLightbox(src, alt) {
    if (!src) return;
    let modal = document.getElementById('_lightboxModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = '_lightboxModal';
        modal.innerHTML = `
            <div id="_lightboxOverlay" style="
                position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.92);
                display:flex;align-items:center;justify-content:center;
                cursor:zoom-out;opacity:0;transition:opacity .2s;
            ">
                <button id="_lightboxClose" style="
                    position:fixed;top:16px;right:20px;background:none;border:none;
                    color:#fff;font-size:2rem;line-height:1;cursor:pointer;z-index:10000;
                    text-shadow:0 0 8px #000;
                ">&#x2715;</button>
                <img id="_lightboxImg" src="" alt="" style="
                    max-width:96vw;max-height:96vh;object-fit:contain;
                    border-radius:6px;box-shadow:0 8px 40px rgba(0,0,0,.6);
                    cursor:default;pointer-events:none;
                ">
            </div>`;
        document.body.appendChild(modal);
        const overlay = document.getElementById('_lightboxOverlay');
        overlay.addEventListener('click', closeLightbox);
        document.getElementById('_lightboxClose').addEventListener('click', (e) => {
            e.stopPropagation(); closeLightbox();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeLightbox();
        });
    }
    const overlay = document.getElementById('_lightboxOverlay');
    document.getElementById('_lightboxImg').src = src;
    document.getElementById('_lightboxImg').alt = alt || '';
    overlay.style.display = 'flex';
    requestAnimationFrame(() => { overlay.style.opacity = '1'; });
}

function closeLightbox() {
    const overlay = document.getElementById('_lightboxOverlay');
    if (!overlay) return;
    overlay.style.opacity = '0';
    setTimeout(() => { overlay.style.display = 'none'; }, 200);
}

async function apiRequest(entity, method = 'GET', data = null, queryString = '') {
    let url = getEndpointUrl(entity);
    if (queryString) {
        url += (url.includes('?') ? '&' : '?') + queryString;
    }

    const options = {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include'
    };

    if (data && (method === 'POST' || method === 'PUT' || method === 'DELETE')) {
        options.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(url, options);
        const text = await response.text();

        if (!response.ok) {
            if (response.status === 401) {
                if (!window.location.pathname.endsWith('index.html') && window.location.pathname !== '/' && !window.location.pathname.endsWith('/')) {
                    localStorage.removeItem('usuario_megg');
                    sessionStorage.removeItem('usuario_megg');
                    window.location.href = 'index.html';
                }
            }
            let errorMsg = `Error ${response.status}`;
            try {
                const json = JSON.parse(text);
                errorMsg = json.error || errorMsg;
            } catch (e) {
                errorMsg = `Error del servidor (${response.status}): ${text.substring(0, 100)}`;
            }
            throw new Error(errorMsg);
        }

        if (!text) return {};
        const parsed = JSON.parse(text);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && parsed.error) {
            throw new Error(parsed.error);
        }
        return parsed;
    } catch (error) {
        console.error(`Error en API [${entity}]:`, error);
        throw error;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

function getDateToday() {
    return new Date().toISOString().split('T')[0];
}

function getSearchTerm() {
    const input = document.getElementById('globalSearch');
    return input ? input.value.toLowerCase().trim() : '';
}

function filterData(data, search, fields) {
    if (!Array.isArray(data)) return [];
    if (!search) return data;
    return data.filter(item => {
        return fields.some(field => {
            const val = item[field];
            if (val === undefined || val === null) return false;
            return String(val).toLowerCase().includes(search);
        });
    });
}

// Renderizar Navbar común
function renderNavbar(activeTab) {
    const usuarioGuardado = localStorage.getItem('usuario_megg') || 'Usuario';

    const navHtml = `
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom mb-3">
            <div class="container-fluid">
                <a class="navbar-brand brand-logo" href="pedidos.html"><i class="fas fa-tshirt"></i> El closet de Megg</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link ${activeTab === 'pedidos' ? 'active' : ''}" href="pedidos.html">Pedidos</a></li>
                        <li class="nav-item"><a class="nav-link ${activeTab === 'clientes' ? 'active' : ''}" href="clientes.html">Clientes</a></li>
                        <li class="nav-item"><a class="nav-link ${activeTab === 'productos' ? 'active' : ''}" href="productos.html">Productos</a></li>
                        <li class="nav-item"><a class="nav-link ${activeTab === 'usuarios' ? 'active' : ''}" href="usuarios.html">Usuarios</a></li>
                        <li class="nav-item"><a class="nav-link ${activeTab === 'reportes' ? 'active' : ''}" href="reportes.html">Reportes</a></li>
                        <li class="nav-item"><a class="nav-link ${activeTab === 'sistema' ? 'active' : ''}" href="sistema.html">Sistema</a></li>
                    </ul>
                    <div class="d-flex align-items-center gap-2">
                        <span class="navbar-text text-white-50 me-2" id="userDisplay">👋 ${escapeHtml(usuarioGuardado)}</span>
                        <button class="btn btn-outline-light btn-sm" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Salir</button>
                    </div>
                </div>
            </div>
        </nav>

        ${(activeTab === 'reportes' || activeTab === 'sistema') ? '' : `
        <div class="mb-3">
            <div class="search-box d-flex align-items-center">
                <i class="fas fa-search me-2"></i>
                <input type="text" id="globalSearch" placeholder="Buscar..." class="form-control-sm border-0" style="width: 100%;">
            </div>
        </div>
        `}
    `;

    const headerContainer = document.getElementById('navbarContainer');
    if (headerContainer) {
        headerContainer.innerHTML = navHtml;
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async () => {
                try { await apiRequest('logout', 'POST'); } catch (e) {}
                localStorage.removeItem('usuario_megg');
                window.location.href = 'index.html';
            });
        }
    }
}

// Validar sesión al cargar la página
async function checkSession() {
    const isLoginPage = window.location.pathname.endsWith('index.html') || window.location.pathname === '/' || window.location.pathname.endsWith('/');
    if (isLoginPage) return;

    try {
        const res = await apiRequest('check', 'GET');
        if (!res || !res.authenticated) {
            window.location.href = 'index.html';
        }
    } catch (err) {
        // apiRequest redirige automáticamente en caso de 401
    }
}

// Ejecutar validación de sesión automáticamente en páginas protegidas
checkSession();
