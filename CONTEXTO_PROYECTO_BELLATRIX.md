# CONTEXTO_PROYECTO_BELLATRIX.md
### Documento maestro de transferencia — Sistema Bellatrix para "Ambrosía"
> Generado al cierre de la Fase 5. Este documento contiene todo lo que necesitas para construir la **Fase 6: Vistas Frontend Privado (Dashboards y Paneles)** sin acceso al código fuente del backend.

---

## 1. Arquitectura Base

**Bellatrix** es el sistema de información de "Ambrosía" (pastelería + heladería + salón de onces), construido en **PHP nativo bajo patrón MVC** (sin frameworks) más un frontend en **HTML/CSS/JS Vanilla**.

| Capa | Tecnología |
|---|---|
| Backend | PHP nativo (MVC), PDO con sentencias preparadas |
| Base de datos | MySQL (`pasteleriaok`) |
| Enrutamiento | Front Controller propio (`index.php` + `Router.php`), sin framework |
| Servidor local | XAMPP (Apache + `.htaccess` con `mod_rewrite`) |
| Frontend | HTML5, CSS3, Bootstrap 5 (CDN), JavaScript Vanilla + Fetch API |
| Autenticación | Sesiones PHP tradicionales (`$_SESSION` + cookie), **NO JWT** |

### 1.1 Estructura de carpetas del proyecto (raíz = `htdocs/bellatrix/`)

```
bellatrix/
├── index.php                  # Front Controller — TODA petición a /api/* pasa por aquí
├── routes.php                 # Registro central de TODAS las rutas de la API
├── .htaccess                  # DirectoryIndex index.html (Landing) + rewrite de /api/* a index.php
├── config/
│   └── Database.php           # Conexión PDO (Singleton)
├── models/                    # 17 clases de Modelo (Fase 1) — Producto, Cliente, Empleado,
│                               # Administrador, Cajero, Venta, Pedido, Pago, GestorVentas, etc.
├── app/
│   ├── core/                  # Router.php, Request.php, Response.php, Sesion.php, Middleware.php
│   └── controllers/           # 9 controladores (Fases 2-4), ver catálogo en sección 3
├── database/
│   └── 002_eliminar_tabla_usuarios.sql
├── index.html, catalogo.html, login.html, registro.html, carrito.html   # Frontend público (Fase 5)
└── assets/
    ├── css/estilos.css        # Sistema de diseño único de todo el sitio
    └── js/                    # api.js, componentes.js, catalogo.js, carrito.js, login.js, registro.js, landing.js
```

### 1.2 Cómo funciona una petición, de punta a punta

```
Navegador (fetch) → .htaccess → index.php (Front Controller)
      → arranca sesión, configura CORS
      → crea Router, incluye routes.php (registra TODAS las rutas)
      → Router::despachar() empareja URL + verbo HTTP contra las rutas registradas
      → ejecuta Middleware(s) de esa ruta (ver sección 2)
      → si pasan, ejecuta el método del Controlador correspondiente
      → el Controlador usa uno o más Modelos (PDO) y responde con Response::exito()/error()
```

### 1.3 Formato de respuesta — IDÉNTICO en TODA la API

**Todas** las respuestas del backend (sin excepción) tienen esta forma exacta:

```json
// Éxito:
{ "exito": true,  "mensaje": "Texto descriptivo...", "datos": { ... } }

// Error:
{ "exito": false, "mensaje": "Texto descriptivo del error..." }
```

`datos` puede ser un objeto, un arreglo, o un arreglo vacío `[]` — nunca falta la clave `exito`. Códigos HTTP usados: `200` OK, `201` creado, `400` datos faltantes, `401` no autenticado, `403` autenticado sin permiso, `404` no existe, `409` conflicto, `422` no pasó validación de negocio, `500` error interno.

---

## 2. Gestión de Estado y Autenticación

### 2.1 Cookies de sesión tradicionales — NO JWT
El login NO devuelve ningún token para guardar en `localStorage`. En su lugar:
1. El backend llama a `session_start()` (vía `Sesion::iniciar()`) en cada petición.
2. Al hacer login exitoso, el backend guarda los datos del usuario en `$_SESSION['usuario']` y el navegador recibe automáticamente la cookie `PHPSESSID`.
3. **Cada `fetch()` posterior DEBE incluir `credentials: 'same-origin'`** (ya está resuelto: `api.js` lo hace automáticamente en `apiFetch()` — reutilízalo, no reinventes fetch a mano).
4. El backend identifica quién eres leyendo esa cookie en cada petición — no hay que mandar ningún header de autorización manual.

### 2.2 Forma exacta del objeto de sesión (idéntica para los 3 roles)

```json
{
  "id": 5,
  "tipo": "cliente",            // "cliente" | "empleado"
  "rol": "Cliente",              // "Cliente" | "Administrador" | "Cajero"
  "nombre": "Ana Torres",
  "identificador": "ana@correo.com"   // correo (Cliente) o usuario (Empleado)
}
```

Este es exactamente el objeto que devuelve `GET /api/auth/me` en `datos`, y el mismo que ya consume `obtenerSesionActual()` en `assets/js/api.js`.

### 2.3 Cómo se valida el rol (Middleware)
El backend usa un **Middleware de Roles** (`app/core/Middleware.php`) que se aplica por ruta en `routes.php`:
- `Middleware::autenticado()` → exige sesión activa, cualquier rol.
- `Middleware::rol(['Administrador'])` → exige sesión Y ese rol exacto.
- `Middleware::rol(['Administrador', 'Cajero'])` → exige sesión Y uno de esos roles.

Si el Middleware rechaza, el Front Controller responde `401` (no autenticado) o `403` (autenticado pero sin permiso) **antes** de ejecutar el controlador — el frontend de la Fase 6 debe manejar ambos casos (ver sección 5, patrón de guardia de sesión ya usado en `carrito.js`).

### 2.4 Login — dos "tipos" distintos
El body de `POST /api/auth/login` cambia según el actor:
```json
// Cliente:
{ "tipo": "cliente", "correo": "...", "contrasena": "..." }
// Empleado (Administrador o Cajero — el rol lo determina la BD, no el frontend):
{ "tipo": "empleado", "usuario": "...", "contrasena": "..." }
```
**Importante para la Fase 6:** actualmente NO existe una vista de login para empleados (el sitio público de la Fase 5 solo ofrece login de Cliente). La Fase 6 probablemente necesite su propia pantalla de login para Administrador/Cajero (`tipo: "empleado"`), separada de `login.html`.

---

## 3. Catálogo de la API (CRÍTICO — todas las rutas activas)

> Todas las rutas viven bajo `/api/...`. Formato: `[MÉTODO] ruta | Rol requerido | Body JSON esperado | Qué hace / qué devuelve en `datos``.

### 3.1 Autenticación (`AuthController`) — Fase 2

| Ruta | Rol | Body | Descripción |
|---|---|---|---|
| `POST /api/auth/login` | Público | `{tipo, correo/usuario, contrasena}` | Ver 2.4. Devuelve datos del usuario + guarda sesión |
| `POST /api/auth/registro` | Público | `{nombre, correo, contrasena, telefono?, direccion_entrega?}` | Crea Cliente nuevo (CU010). `contrasena` mín. 6 caracteres |
| `POST /api/auth/logout` | Público | — | Destruye la sesión |
| `GET /api/auth/me` | Autenticado (cualquiera) | — | Devuelve el objeto de sesión (ver 2.2) |
| `POST /api/auth/recuperar` | Público | `{correo}` | Genera token de recuperación (solo Cliente) |
| `POST /api/auth/restablecer` | Público | `{token, nueva_contrasena}` | Define nueva contraseña con el token |

### 3.2 Inventario — Productos, Materias Primas, Recetas, Alertas (`InventarioController`) — Fase 3

| Ruta | Rol | Body | Descripción |
|---|---|---|---|
| `GET /api/inventario/productos?unidad=&buscar=` | Admin, Cajero | — | Lista TODOS los productos (incl. no disponibles) |
| `GET /api/inventario/productos/{id}` | Admin, Cajero | — | Ver un producto |
| `POST /api/inventario/productos` | Admin, Cajero | `{nombre, descripcion?, tipo?, unidad_negocio, precio, stock?, foto?}` | Crear producto |
| `PUT /api/inventario/productos/{id}` | Admin, Cajero | mismos campos + `disponible?` | Actualizar producto completo |
| `POST /api/inventario/productos/{id}/ajustar-stock` | Admin, Cajero | `{cantidad}` (+/-) | Suma/resta stock puntual |
| `DELETE /api/inventario/productos/{id}` | **Solo Admin** | — | Elimina producto |
| `GET /api/inventario/productos/{id}/receta` | Solo Admin | — | Ver insumos de un producto |
| `GET /api/inventario/materias-primas` | Solo Admin | — | Lista todos los insumos |
| `GET /api/inventario/materias-primas/bajo-stock` | Solo Admin | — | Solo insumos en/bajo su umbral mínimo |
| `POST /api/inventario/materias-primas` | Solo Admin | `{nombre, unidad_medida?, stock_actual?, stock_minimo}` | Crear insumo |
| `PUT /api/inventario/materias-primas/{id}` | Solo Admin | `{nombre?, unidad_medida?, stock_minimo?}` | Actualizar insumo |
| `POST /api/inventario/materias-primas/{id}/ajustar-stock` | Solo Admin | `{tipo: 'aumentar'\|'descontar', cantidad}` | Ajusta stock; genera alerta automática si aplica |
| `DELETE /api/inventario/materias-primas/{id}` | Solo Admin | — | Elimina insumo |
| `POST /api/inventario/recetas` | Solo Admin | `{id_producto, id_materia, cantidad}` | Agrega línea de receta |
| `DELETE /api/inventario/recetas/{id}` | Solo Admin | — | Elimina línea de receta |
| `GET /api/inventario/alertas` | Solo Admin | — | Lista alertas de stock ACTIVAS |
| `POST /api/inventario/alertas/{id}/atender` | Solo Admin | — | Marca una alerta como atendida |

**Forma de un producto (`obtenerDatos()`):**
```json
{ "id_producto": 3, "nombre": "Torta de Chocolate", "descripcion": "...", "tipo": "Tortas",
  "unidad_negocio": "Pastelería", "precio": 45000, "stock": 8, "foto": null, "disponible": true }
```

### 3.3 Punto de Venta / Ventas presenciales (`VentaController`) — Fase 3, CU005-CU008

| Ruta | Rol | Body | Descripción |
|---|---|---|---|
| `POST /api/ventas` | Admin, Cajero | `{canal, unidad_negocio}` | Abre venta nueva ('Activa'). `id_empleado` sale de la sesión |
| `GET /api/ventas` | Admin, Cajero | — | Admin ve TODAS; Cajero ve solo las SUYAS |
| `GET /api/ventas/{id}` | Admin, Cajero | — | Ver una venta con su detalle |
| `POST /api/ventas/{id}/productos` | Admin, Cajero | `{id_producto, cantidad}` | Agrega línea (valida stock) |
| `POST /api/ventas/{id}/finalizar` | Admin, Cajero | — | **CU008**: descuenta stock de productos + materia prima (receta), genera alertas si aplica, y registra el ingreso en la Caja del día (CU018) |
| `POST /api/ventas/{id}/anular` | Admin, Cajero | — | Marca venta como 'Anulada' |

**Forma de una venta:**
```json
{ "id_venta": 12, "fecha": "...", "total": 90000, "canal": "Presencial", "unidad_negocio": "Pastelería",
  "estado": "Activa", "id_empleado": 2,
  "detalles": [ { "id_producto": 3, "cantidad": 2, "precio_unitario": 45000, "subtotal": 90000 } ] }
```

### 3.4 Cajas por Unidad (`CajaController`) — Fase 3, CU018

| Ruta | Rol | Body / Query | Descripción |
|---|---|---|---|
| `GET /api/cajas/hoy?canal=&unidad=` | Admin, Cajero | query: `canal` (`Presencial`\|`En línea`), `unidad` (`Pastelería`\|`Heladería`) | Trae/crea la caja del día para esa combinación |
| `POST /api/cajas/egreso` | **Solo Admin** | `{canal, unidad_negocio, monto, fecha?}` | Registra egreso — **se RECHAZA (422) si supera el saldo disponible** (regla central del CU018) |
| `POST /api/cajas/ingreso` | Solo Admin | `{canal, unidad_negocio, monto, fecha?}` | Ingreso manual (ajuste, fuera de una venta) |
| `GET /api/cajas/unidad/{unidad}` | Solo Admin | — | Historial de cajas de una unidad |
| `GET /api/cajas/canal/{canal}` | Solo Admin | — | Historial de cajas de un canal |
| `GET /api/cajas/reportes/diario?fecha=` | Solo Admin | query `fecha` (default hoy) | Reporte de un día |
| `GET /api/cajas/reportes/semanal?inicio=` | Solo Admin | query `inicio` (AAAA-MM-DD) | Reporte de 7 días desde esa fecha |
| `GET /api/cajas/reportes/mensual?anio=&mes=` | Solo Admin | query `anio`, `mes` | Reporte de un mes calendario |
| `GET /api/cajas/reportes/rango?inicio=&fin=` | Solo Admin | query `inicio`, `fin` | Reporte de rango libre |

**Forma de una caja:**
```json
{ "id_gestor": 4, "canal": "Presencial", "unidad_negocio": "Pastelería", "fecha": "2026-07-30",
  "total_ventas": 320000, "total_egresos": 50000, "saldo": 270000 }
```
`saldo` es **generado por MySQL** (`total_ventas - total_egresos`) — nunca se envía en el body, es de solo lectura.

### 3.5 Catálogo Público (`CatalogoController`) — Fase 4, CU011 — sin sesión

| Ruta | Rol | Query | Descripción |
|---|---|---|---|
| `GET /api/catalogo/productos?unidad=&buscar=` | Público | — | Lista productos (con campo `agotado` calculado) |
| `GET /api/catalogo/productos/{id}` | Público | — | Ver un producto público |

**Forma pública de un producto** (distinta de la interna — sin `stock` ni `disponible` crudo):
```json
{ "id_producto": 3, "nombre": "...", "descripcion": "...", "tipo": "...",
  "unidad_negocio": "Pastelería", "precio": 45000, "foto": null, "agotado": false }
```

### 3.6 Carrito de Compras (`CarritoController`) — Fase 4, CU012 — solo Cliente

| Ruta | Rol | Body | Descripción |
|---|---|---|---|
| `GET /api/carrito` | Cliente | — | Trae/crea el carrito del cliente en sesión |
| `POST /api/carrito/productos` | Cliente | `{id_producto, cantidad}` | Agrega (valida stock dinámico) |
| `PUT /api/carrito/productos/{idProducto}` | Cliente | `{cantidad}` | Cambia cantidad (≤0 elimina la línea) |
| `DELETE /api/carrito/productos/{idProducto}` | Cliente | — | Elimina un producto del carrito |
| `DELETE /api/carrito` | Cliente | — | Vacía el carrito completo |

**Forma del carrito:**
```json
{ "id_carrito": 7, "subtotal": 90000,
  "items": [ { "id_producto": 3, "cantidad": 2, "precio_unitario": 45000, "subtotal": 90000 } ] }
```

### 3.7 Pedidos en Línea (`PedidoController`) — Fase 4, CU013/CU015/CU017

| Ruta | Rol | Body / Query | Descripción |
|---|---|---|---|
| `POST /api/pedidos` | Cliente | `{direccion_entrega}` | Confirma el carrito actual como Pedido (lo vacía) |
| `GET /api/pedidos` | Cliente | — | "Mis pedidos" — historial del cliente en sesión |
| `GET /api/pedidos/{id}` | Autenticado | — | Ver un pedido — Cliente solo puede ver LOS SUYOS (403 si no) |
| `GET /api/pedidos/gestion?estado=Confirmado` | Admin, Cajero | query `estado` (default `Confirmado`) | Bandeja de trabajo, filtrable por estado |
| `PUT /api/pedidos/{id}/estado` | Admin, Cajero | `{estado}` | Cambia el estado (dispara notificación automática al cliente) |

**Estados válidos del Pedido (ENUM, en orden):** `Pendiente de pago` → `Confirmado` → `En preparación` → `Listo para recoger` → `Entregado` (o `Cancelado` en cualquier punto).

**Forma de un pedido:**
```json
{ "id_pedido": 9, "id_cliente": 5, "estado": "Confirmado", "direccion_entrega": "...",
  "total": 90000, "fecha_creacion": "...", "fecha_actualizacion": "...", "id_empleado_gestion": null,
  "productos": [ { "id_producto": 3, "cantidad": 2, "precio_unitario": 45000, "subtotal": 90000 } ] }
```

### 3.8 Pagos — Pasarela SIMULADA (`PagoController`) — Fase 4, CU014 — solo Cliente

| Ruta | Rol | Body | Descripción |
|---|---|---|---|
| `POST /api/pagos` | Cliente | `{id_pedido, medio_pago}` (`'PSE'\|'Tarjeta crédito'\|'Tarjeta débito'\|'Nequi'\|'Otro'`) | Crea intento de pago 'Pendiente' |
| `POST /api/pagos/{id}/confirmar` | Cliente | `{aprobado: bool, referencia_pasarela?}` | **SIMULA** la respuesta de la pasarela. Si `aprobado:true`, el Pedido pasa a 'Confirmado' automáticamente |
| `GET /api/pagos/pedido/{idPedido}` | Autenticado | — | Lista intentos de pago de un pedido |
| `GET /api/pagos/{id}/comprobante` | Autenticado | — | Datos estructurados del comprobante (no genera PDF) |

⚠️ **No hay integración real con PSE/Nequi todavía** — es 100% simulado a propósito, decisión ya confirmada con el usuario.

### 3.9 Notificaciones (`NotificacionController`) — Fase 4, CU016 — solo Cliente

| Ruta | Rol | Descripción |
|---|---|---|
| `GET /api/notificaciones` | Cliente | Historial de notificaciones del cliente en sesión |

⚠️ El **envío real de correo sigue siendo simulado** (se escribe en el log del servidor, no se manda un email real) — decisión confirmada con el usuario, pendiente de definir hosting.

---

## 4. Convenciones del Frontend (Fase 5) — REUTILIZAR, no reinventar

Ya existen y están probados en producción de pruebas — la Fase 6 debe construir SOBRE esto, no crear su propio sistema paralelo:

| Archivo | Qué hace | Cómo se usa en la Fase 6 |
|---|---|---|
| `assets/css/estilos.css` | Sistema de diseño único de todo el sitio (ver paleta abajo) | Enlazar en cada HTML nuevo; reutilizar TODAS las clases ya definidas |
| `assets/js/api.js` | `apiFetch(ruta, {method, body})` — envoltorio fetch con `credentials:'same-origin'` y parseo JSON uniforme. `obtenerSesionActual()`, `formatearPrecioCOP()` | Base obligatoria de cualquier llamada a la API nueva |
| `assets/js/componentes.js` | `inicializarHeader()` (pinta "Hola, Nombre"/"Iniciar sesión" en `#zona-cuenta`), `tarjetaProductoHTML()`, `activarBotonesAgregarCarrito()`, `escaparHtml()` | Reutilizar `inicializarHeader()`; puede necesitar variantes para header de empleado (ver sección 5) |

### 4.1 Paleta de colores (variables CSS, ya definidas en `:root`)

| Variable | Valor | Uso |
|---|---|---|
| `--rosa-fuerte` | `#E2899B` | Acento/CTA de **Pastelería** |
| `--menta-fuerte` | `#6FAE8C` | Acento/CTA de **Heladería** |
| `--rosa-suave` / `--menta-suave` | tintes muy claros | Fondos de tarjetas/badges, nunca fondos de sección completos |
| `--salvia` / `--salvia-oscuro` | `#B0C4B1` / `#6F8A73` | Acentos secundarios |
| `--crema` | `#FFF9F6` | Fondo general de página (casi blanco) |
| `--espresso` | `#3B2A26` | Texto principal — SIEMPRE usar este color de texto, nunca negro puro |
| `--espresso-suave` | `#8A7770` | Texto secundario |
| `--fuente-display` | `'Fraunces'` | Titulares (cargar de Google Fonts vía CDN) |
| `--fuente-cuerpo` | `'Nunito Sans'` | Texto de lectura |
| `--radio-grande` / `--radio-suave` / `--radio-chico` | `28px` / `18px` / `12px` | Border-radius de tarjetas/paneles/inputs |
| `--sombra-suave` / `--sombra-chica` | sombras difusas ya calibradas | Dar profundidad a tarjetas, círculos y modales |

**Regla de contraste ya validada y aprobada por el usuario (aplícala igual en la Fase 6):** ninguna sección de ancho completo debe llevar un pastel saturado de fondo con texto encima. El fondo de página es crema/blanco casi neutro; el color fuerte (`--rosa-fuerte`/`--menta-fuerte`) vive en los ELEMENTOS (círculos, íconos, botones, tarjetas) siempre con sombra para dar profundidad, y el texto sobre esos elementos saturados va en blanco o `--espresso`, nunca pastel-sobre-pastel.

**Clases de componentes ya disponibles y reutilizables:** `.btn-ambrosia`, `.btn-ambrosia-outline`, `.btn-ambrosia-sage`, `.tarjeta-producto` (+ `__imagen`, `__cuerpo`, `__precio`), `.badge-pasteleria`/`.badge-heladeria`/`.badge-agotado`, `.formulario-ambrosia`, `.mensaje-formulario` (+ `--error`/`--exito`), `.estado-vacio`, `.header-ambrosia`, `.nav-ambrosia`, `.icono-accion` + `.contador-carrito`, `.hero-ambrosia`/`.hero-mancha` (círculo decorativo), `.festoneado` (borde tipo manga pastelera, separador de secciones — el elemento de firma visual del sitio), `.seccion`/`.seccion--crema`/`.seccion--blush`, `.icono-promesa`, `.onces-panel`, `.footer-ambrosia`, `.barra-filtros`/`.chip-filtro`, `.fila-carrito`/`.control-cantidad`/`.resumen-carrito`.

### 4.2 Patrón ya establecido: "guardia de sesión" en páginas privadas
`carrito.js` ya implementa el patrón que la Fase 6 DEBE replicar para las 3 vistas de dashboard: verificar la sesión ANTES de pintar cualquier contenido, y redirigir si no corresponde:
```js
document.addEventListener('DOMContentLoaded', async () => {
  const usuario = await obtenerSesionActual();
  if (!usuario || usuario.rol !== 'RolEsperado') {
    window.location.href = 'login.html'; // o la página de login que corresponda
    return;
  }
  // recién aquí se pinta el contenido privado
});
```

---

## 5. Misión de la Fase 6

Construir los **3 paneles privados**, cada uno con su propio guardia de sesión por rol (patrón de la sección 4.2), reutilizando `estilos.css`, `api.js` y `componentes.js` sin modificarlos.

### 5.1 Archivos a crear

| Archivo HTML | Rol exigido | Casos de Uso que resuelve | Archivo(s) guía existentes |
|---|---|---|---|
| `login_empleado.html` + `assets/js/login_empleado.js` | Público (pre-sesión) | CU001 — login con `{tipo:'empleado', usuario, contrasena}` | Calcar de `login.html` + `login.js`, cambiando el payload (ver 2.4) |
| `cliente_dashboard.html` + `assets/js/cliente_dashboard.js` | `Cliente` | CU015 (estado de pedidos), CU016 (notificaciones), perfil básico | `carrito.js` (patrón de guardia de sesión), `componentes.js` (`inicializarHeader`, `formatearPrecioCOP`) |
| `admin_pos.html` + `assets/js/admin_pos.js` | `Administrador`, `Cajero` | CU006/CU007/CU008 — Punto de Venta: abrir venta, buscar/agregar productos, finalizar/anular | `catalogo.js` (patrón de búsqueda/filtro de productos), `carrito.js` (patrón de líneas +/- y subtotal) |
| `admin_caja.html` + `assets/js/admin_caja.js` | `Administrador` (consulta de "hoy" también `Cajero`) | CU018 — ver saldo del día, registrar egresos (mostrar el bloqueo 422 si excede saldo), reportes diario/semanal/mensual | Ninguno directo — nuevo; usar `apiFetch()` de `api.js` |
| `admin_inventario.html` + `assets/js/admin_inventario.js` | `Administrador`, `Cajero` (Cajero solo productos, no materias primas) | CU002/CU009 (productos), CU019 (materias primas + alertas) | `tarjetaProductoHTML()` de `componentes.js` como referencia de tarjeta (adaptar a modo edición) |
| `admin_pedidos.html` + `assets/js/admin_pedidos.js` | `Administrador`, `Cajero` | CU017 — bandeja de pedidos en línea por estado, cambiar estado | Reutiliza formas de datos de `PedidoController` (sección 3.7) |
| `admin_ventas.html` + `assets/js/admin_ventas.js` | `Administrador`, `Cajero` | CU005 — historial de ventas (Admin ve todas, Cajero solo las suyas) | — |

### 5.2 Flujo de acceso privado (a implementar en todas las vistas nuevas)
1. Página carga → guardia de sesión (sección 4.2) → si el `rol` no coincide, redirige a `login.html` (Cliente) o `login_empleado.html` (Administrador/Cajero).
2. `inicializarHeader()` de `componentes.js` ya pinta "Hola, Nombre"/"Cerrar sesión" — pero fue diseñada pensando en Cliente (revisa si necesita una variante para mostrar el nombre + rol de Empleado, o si basta tal cual).
3. Cada vista consume exclusivamente los endpoints de la sección 3 correspondientes a su rol — respeta los 403 que ya devuelve el backend en vez de intentar ocultar botones únicamente por CSS (la autorización real siempre vive en el backend).

### 5.3 Pendientes explícitos heredados de fases anteriores (decisiones NO tomadas aún)
- CU003 (gestión de usuarios internos / Cajeros desde el panel Admin) — el modelo `Administrador::gestionarEmpleado()` ya existe (Fase 1) pero **no tiene ruta ni controlador expuesto todavía**. Si `admin_inventario.html` o un nuevo `admin_empleados.html` lo necesita, hay que crear la ruta en `routes.php` primero.
- No existe generación de PDF para comprobantes/reportes — todo llega como JSON estructurado.
- El envío real de correo y la pasarela de pagos real siguen pendientes (simulados a propósito).

---

## 6. Credenciales de prueba disponibles (datos semilla del script SQL original)

| Usuario | Contraseña | Rol |
|---|---|---|
| `angel.admin` | `angel123` | Administrador |
| `thania.admin` | `thania123` | Administrador |

*(No hay Cajero de prueba en los datos semilla — crear uno vía `Administrador::gestionarEmpleado()` a nivel de modelo, o directamente en la base de datos, hasta que exista una ruta de API para ello — ver 5.3.)*
