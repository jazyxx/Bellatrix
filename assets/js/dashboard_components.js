/**
 * assets/js/dashboard_components.js
 * Inyecta dinámicamente el sidebar y el layout base unificado en las páginas de panel.
 * Diseñado con variables de diseño pastel y adaptable según el rol.
 */

async function injectDashboardLayout(rol, activeTabId) {
  const sidebarLinks = {
    'Cliente': [
      { id: 'cliente_dashboard', href: 'cliente_dashboard.html', icon: 'bi-person-circle', text: 'Mi Perfil' },
      { id: 'cliente_pedidos', href: 'cliente_pedidos.html', icon: 'bi-bag-check-fill', text: 'Mis Pedidos' },
      { id: 'logout', href: '#', icon: 'bi-box-arrow-right', text: 'Cerrar Sesión', onclick: 'cerrarSesionDashboard()' }
    ],
    'Administrador': [
      { id: 'admin_pos', href: 'admin_pos.html', icon: 'bi-cart3', text: 'Punto de Venta' },
      { id: 'admin_caja', href: 'admin_caja.html', icon: 'bi-cash-coin', text: 'Control de Caja' },
      { id: 'admin_inventario', href: 'admin_inventario.html', icon: 'bi-box-seam-fill', text: 'Inventario/Recetas' },
      { id: 'admin_pedidos', href: 'admin_pedidos.html', icon: 'bi-truck', text: 'Pedidos Online' },
      { id: 'admin_ventas', href: 'admin_ventas.html', icon: 'bi-graph-up-arrow', text: 'Historial Ventas' },
      { id: 'logout', href: '#', icon: 'bi-box-arrow-right', text: 'Salir', onclick: 'cerrarSesionDashboard()' }
    ],
    'Cajero': [
      { id: 'cajero_pos', href: 'admin_pos.html', icon: 'bi-cart3', text: 'Punto de Venta' },
      { id: 'cajero_inventario', href: 'admin_inventario.html', icon: 'bi-box-seam-fill', text: 'Inventario' },
      { id: 'cajero_pedidos', href: 'admin_pedidos.html', icon: 'bi-truck', text: 'Pedidos Online' },
      { id: 'cajero_ventas', href: 'admin_ventas.html', icon: 'bi-graph-up-arrow', text: 'Mis Ventas' },
      { id: 'logout', href: '#', icon: 'bi-box-arrow-right', text: 'Salir', onclick: 'cerrarSesionDashboard()' }
    ]
  };

  const links = sidebarLinks[rol] || [];
  const usuario = await obtenerSesionActual();
  const userName = usuario ? usuario.nombre : 'Usuario';
  
  // Guardamos el contenido actual del body
  const currentContent = document.body.innerHTML;
  
  // Reemplazamos el body con la estructura de layout
  document.body.innerHTML = `
    <div class="dashboard-layout">
      <!-- Sidebar -->
      <aside class="dashboard-sidebar">
        <a href="index.html" class="dashboard-brand">
          Ambrosía
          <span>Sistema Bellatrix</span>
        </a>
        
        <ul class="sidebar-menu">
          ${links.map(l => `
            <li class="sidebar-item ${l.id === activeTabId ? 'active' : ''}">
              <a href="${l.href}" ${l.onclick ? `onclick="${l.onclick}; return false;"` : ''}>
                <i class="bi ${l.icon} me-2"></i>${l.text}
              </a>
            </li>
          `).join('')}
        </ul>
        
        <div class="sidebar-user">
          <span class="sidebar-user-name">${userName}</span>
          <span class="sidebar-user-role">${rol}</span>
        </div>
      </aside>
      
      <!-- Main Content -->
      <main class="dashboard-main" id="dashboard-main-content">
        <!-- Botón para móviles -->
        <div class="d-md-none mb-3 d-flex align-items-center justify-content-between bg-white p-3 rounded shadow-sm">
          <span class="font-weight-bold">Ambrosía Dashboard</span>
          <button class="menu-toggle" onclick="toggleMobileSidebar()"><i class="bi bi-list"></i></button>
        </div>
        
        <div id="alert-container-dashboard"></div>
        
        ${currentContent}
      </main>
    </div>
  `;
}

function toggleMobileSidebar() {
  const sidebar = document.querySelector('.dashboard-sidebar');
  if (sidebar) {
    sidebar.classList.toggle('active');
  }
}

async function cerrarSesionDashboard() {
  if (confirm('¿Estás seguro de que deseas salir del panel?')) {
    await apiFetch('api/auth/logout', { method: 'POST' });
    window.location.href = 'index.html';
  }
}

function showDashboardAlert(mensaje, tipo = 'success') {
  const container = document.getElementById('alert-container-dashboard');
  if (!container) return;
  
  const classMap = {
    'success': 'badge-pastel-success',
    'danger': 'badge-pastel-danger',
    'warning': 'badge-pastel-warning',
    'primary': 'badge-pastel-primary'
  };
  
  const classAlert = tipo === 'danger' ? 'danger' : (tipo === 'warning' ? 'warning' : 'success');
  
  container.innerHTML = `
    <div class="alert alert-${classAlert} d-flex align-items-center justify-content-between p-3 border-0 rounded-3 mb-4 shadow-sm" style="background-color: var(--db-${tipo === 'danger' ? 'danger' : (tipo === 'warning' ? 'warning' : 'success')}); color: var(--db-espresso);">
      <div>
        <span class="badge-pastel ${classMap[tipo]} me-2">${tipo.toUpperCase()}</span>
        <strong>${mensaje}</strong>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.8rem;"></button>
    </div>
  `;
}

function escaparHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto ?? '';
  return div.innerHTML;
}
