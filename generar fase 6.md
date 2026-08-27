Actúa como Desarrollador Full Stack Senior en PHP nativo, JS Vanilla y Bootstrap 5. 

Basándote en el contexto completo del proyecto, la base de datos MySQL, la lógica de controladores/modelos ya definida y la guía de diseño visual, genera el código completo para la **FASE 6: Vistas Frontend Privado (Dashboards y Paneles)**.

---

### 🎨 REGLAS DE DISEÑO VISUAL Y ESTÉTICA (ESTRICTO)
1. **Paleta Pastel:** Utiliza variables CSS globales (`:root`) definidas en un archivo CSS centralizado (`public/css/dashboard.css`) para mantener uniformidad:
   - Primario: Azul/Violeta pastel suave (ej: `#A3C4F3` / `#B9FBC0` / `#FBF8CC`).
   - Fondos: Blanco cálido y gris muy claro (`#F8F9FA` / `#FAF3DD`).
   - Bordes y Sombras: Bordes redondeados suaves (`border-radius: 12px`), sombras ultra ligeras (`box-shadow: 0 4px 15px rgba(0,0,0,0.03)`).
2. **Layout Base Unificado:**
   - Todos los paneles deben consumir un layout base compartido o incluir un sidebar y navbar persistentes y responsivos.
   - Sidebar con navegación adaptada dinámicamente según el rol de la sesión (`Admin`, `Cajero`, `Cliente`).
   - Tipografía limpia, limpia jerarquía visual de títulos y badges pastel para estados (ej: Pedido Pendiente, Caja Abierta/Cerrada).

---

### 🛠️ PREVENCIÓN DE ERRORES CONOCIDOS Y REGLAS TÉCNICAS

1. **Rutas y Assets:**
   - Usa rutas absolutas o dinámicas mediante constantes del sistema (`BASE_URL`) para evitar fallos de carga de CSS/JS o llamadas a la API según la profundidad de la URL.
2. **Manejo de Sesiones y Roles:**
   - Cada vista de esta fase debe validar estrictamente el middleware de autenticación y el rol de usuario antes de renderizar la interfaz. Redirigir al login si la sesión ha expirado.
3. **Consumo de API con JavaScript (FETCH / AJAX):**
   - **No recargar la página:** Las tablas, cambios de estado, apertura/cierre de cajas y registros del POS deben actualizar el DOM dinámicamente mediante `fetch()`.
   - **Manejo de Errores Frontend:** Todos los `fetch()` deben envolverse en bloques `try...catch`. Si la API responde con error JSON (`{ success: false, message: "..." }`), mostrar una alerta estilizada en pantalla (usar SweetAlert2 o modales Bootstrap pastel) en lugar de fallos silenciosos en consola.
4. **Respuesta JSON Limpia:**
   - Asegurar que los endpoints backend consumidos por las vistas devuelvan un encabezado `Content-Type: application/json` adecuado sin `warnings` o `notices` de PHP que corrompan el formato JSON.
5. **Manejo de Formularios y Modales:**
   - Limpiar y resetear los campos e inputs del DOM adecuadamente tras cerrar modales o completar acciones AJAX exitosas.

---

### 📋 MÓDULOS A DESARROLLAR EN LA FASE 6

#### 1. Panel de Administración (`/views/admin/`)
* **Dashboard Principal:** Tarjetas con métricas clave (Ventas del día, Stock crítico, Cajas activas, Pedidos pendientes).
* **Gestión de Inventario y Producción (CU008):** Tabla interactiva con búsqueda/filtros, modal para ajuste de stock y vista/creación de recetas.
* **Gestión y Conciliación de Cajas (CU018):** Visor global de cajas abiertas por unidad/cajero, historial de arqueos y reportes de diferencias.
* **Gestión de Usuarios y Roles:** Tabla de usuarios con opción para editar roles y activar/desactivar cuentas.

#### 2. Panel de Cajero / POS (`/views/cajero/`)
* **Punto de Venta POS Dinámico:**
  * Selector de productos rápido con barra de búsqueda y catálogo de acceso directo.
  * Carrito lateral en tiempo real con cálculo automático de totales, impuestos y cambio.
  * Botón de finalización de venta vía Fetch API enviando el JSON del pedido.
* **Caja Operativa (CU018):**
  * Interfaz de Apertura de Caja (monto inicial) y Cierre/Arqueo de Caja (monto final y observaciones).
  * Deshabilitar las funciones del POS si no existe una caja activa abierta para el cajero actual.

#### 3. Panel de Cliente (`/views/cliente/`)
* **Perfil de Usuario:** Formulario de actualización de datos personales y dirección.
* **Historial de Pedidos:** Tabla con listado de compras realizadas y estado en tiempo real.
* **Tracking de Pedidos:** Vista detallada de un pedido con barra de progreso visual (Pendiente -> En Preparación -> Enviado -> Entregado).

---

### 🚀 ENTREGABLES SOLICITADOS
Genera la estructura de carpetas, el archivo CSS central de estilos pastel (`dashboard.css`), las plantillas PHP/HTML con su maquetación Bootstrap 5 y los scripts JavaScript Vanilla (AJAX/Fetch) necesarios para cada uno de los 3 paneles.

Inicia entregando el código completo y organizado por archivos.