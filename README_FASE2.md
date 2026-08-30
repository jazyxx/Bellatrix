# Bellatrix — Fase 2: Núcleo Backend (Router, Autenticación y Middleware de Roles)

Este documento explica **qué se construyó en esta fase, cómo encaja con la Fase 1, y cómo probarlo** antes de pasar a la Fase 3.

> 📄 El README de la Fase 1 (explicación de los Modelos) se conservó como `README_FASE1.md`.

---

## 1. ¿Qué problema resuelve esta fase?

En la Fase 1 construimos los **Modelos** (la capa que sabe hablar con la base de datos). Pero todavía no existía nada que:

- Recibiera una petición HTTP real (ej. cuando el frontend haga `fetch('/api/auth/login')`).
- Decidiera **a qué controlador** debía ir esa petición.
- Verificara **quién es** la persona que hace la petición (¿está logueada?).
- Verificara **qué puede hacer** esa persona (¿es Administrador, Cajero o Cliente?).

Eso es exactamente lo que se construyó en la Fase 2: el **"sistema nervioso"** del backend.

---

## 2. Cambio en la base de datos (según tu confirmación)

Creé `database/002_eliminar_tabla_usuarios.sql`. **Debes ejecutarlo en tu phpMyAdmin antes de probar esta fase**, para eliminar la tabla `usuarios` que ya no se usa. Verifiqué que ninguna otra tabla tiene una `FOREIGN KEY` apuntando hacia ella, así que es 100% seguro ejecutarlo.

---

## 3. Estructura de archivos agregada en esta fase

```
bellatrix/
├── database/
│   └── 002_eliminar_tabla_usuarios.sql   ← NUEVO (migración)
├── app/
│   ├── core/
│   │   ├── Router.php        ← NUEVO (enrutador central)
│   │   ├── Request.php       ← NUEVO (lee datos JSON entrantes)
│   │   ├── Response.php      ← NUEVO (estandariza respuestas JSON)
│   │   ├── Sesion.php        ← NUEVO (maneja $_SESSION)
│   │   └── Middleware.php    ← NUEVO (Middleware de Roles)
│   └── controllers/
│       └── AuthController.php ← NUEVO (login, registro, logout, recuperación)
├── routes.php                 ← NUEVO (registro de todas las rutas de la API)
├── index.php                  ← NUEVO (Front Controller, punto único de entrada)
├── .htaccess                  ← NUEVO (URLs limpias vía Apache mod_rewrite)
├── models/
│   └── Cliente.php            ← MODIFICADO (se agregó obtenerPorCorreo())
├── config/Database.php        (sin cambios, de la Fase 1)
└── models/*.php                (sin cambios, de la Fase 1 — 17 archivos)
```

> ℹ️ El único archivo modificado de la Fase 1 fue `models/Cliente.php`: le agregué el método `obtenerPorCorreo()`, necesario para que la recuperación de contraseña busque al cliente por correo de forma directa (usando el índice `UNIQUE` de la columna), en vez de recorrer toda la tabla. El resto de los modelos de la Fase 1 **no se tocaron**.

---

## 4. Cómo funciona el flujo completo de una petición

```
Navegador (fetch)
      │
      ▼
.htaccess  →  redirige TODO a index.php
      │
      ▼
index.php  →  arranca sesión, configura CORS, crea el Router
      │
      ▼
routes.php →  aquí se registran todas las rutas (con sus middlewares)
      │
      ▼
Router::despachar()
      │
      ├─ 1) ¿Alguna ruta registrada coincide con la URL + verbo HTTP pedidos?
      ├─ 2) Si tiene Middlewares, los ejecuta en orden (¿autenticado? ¿rol correcto?)
      ├─ 3) Si todo pasa, llama al Controlador correspondiente
      └─ 4) El Controlador usa los Modelos (Fase 1) y responde con Response::exito()/error()
```

---

## 5. Piezas construidas, explicadas una por una

### 5.1 `Router.php` — El enrutador central
Como el proyecto es **PHP nativo sin frameworks**, no existe un enrutador "mágico" como el de Laravel: lo construimos a mano. Permite registrar rutas así:

```php
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->get('/api/productos/{id}', [ProductoController::class, 'ver']); // (ejemplo para Fase 3)
```

Soporta:
- Los 4 verbos HTTP principales: `get()`, `post()`, `put()`, `delete()`.
- **Parámetros dinámicos** en la URL, como `{id}` (listos para cuando en la Fase 3 necesitemos rutas como `/api/pedidos/{id}/estado`).
- **Middlewares por ruta**, que se ejecutan en orden ANTES de llegar al controlador.
- Si nada coincide, responde automáticamente con un `404`.

### 5.2 `index.php` — El "Front Controller"
Es el **único** archivo al que Apache le entrega todas las peticiones (gracias al `.htaccess`). Se encarga de la configuración global: arrancar la sesión, permitir CORS (para que el frontend pueda hacer `fetch()` sin bloqueos del navegador), y finalmente delegarle todo al Router. También envuelve todo en un `try/catch`, así que si algo inesperado falla en cualquier parte del sistema, el usuario recibe un JSON de error claro en vez de una pantalla en blanco.

### 5.3 `Sesion.php` — Manejo centralizado de sesiones
En vez de que cada controlador use `$_SESSION` directamente (lo cual es propenso a errores de "olvidé hacer `session_start()`" o "cada quien guarda la sesión con una forma distinta"), **todo pasa por esta clase**. Guarda siempre la misma estructura para los 3 actores del sistema:

```php
$_SESSION['usuario'] = [
    'id'            => 5,
    'tipo'          => 'cliente' | 'empleado',
    'rol'           => 'Cliente' | 'Administrador' | 'Cajero',
    'nombre'        => 'Angel Jimenez',
    'identificador' => 'correo@ejemplo.com' o 'nombre.usuario',
];
```

Esta uniformidad es lo que hace posible que el Middleware de Roles sea tan simple: siempre pregunta por `'rol'`, sin importar si la sesión es de un Cliente o de un Empleado.

### 5.4 `Middleware.php` — El Middleware de Roles que pediste
Dos "guardias de seguridad":

- **`Middleware::autenticado()`** → exige que haya sesión activa, de cualquier rol. Se usa para rutas como "ver mi perfil", donde solo importa que estés logueado.
- **`Middleware::rol(['Administrador'])`** → exige sesión activa **y** que el rol esté en la lista permitida. Se usa para rutas exclusivas, ej. gestión de empleados (solo Administrador) o el punto de venta (Administrador o Cajero).

Ambos, si detectan un problema, responden inmediatamente con `401` (no autenticado) o `403` (autenticado pero sin permiso) y **el controlador nunca se llega a ejecutar**.

### 5.5 `AuthController.php` — Autenticación real (CU001 y CU010)
Implementa 6 acciones, todas usando los Modelos ya construidos en la Fase 1 (no repite SQL):

| Método | Ruta | ¿Qué hace? |
|---|---|---|
| `login()` | `POST /api/auth/login` | Autentica Cliente o Empleado (usa `Cliente::iniciarSesion()` / `Empleado::ingresarSistema()`) y guarda la sesión |
| `registro()` | `POST /api/auth/registro` | Crea una cuenta de Cliente nueva (usa `Cliente::registrarse()`) y notifica bienvenida |
| `logout()` | `POST /api/auth/logout` | Destruye la sesión activa |
| `me()` | `GET /api/auth/me` *(protegida)* | Devuelve los datos de quien tiene sesión activa ahora mismo |
| `recuperar()` | `POST /api/auth/recuperar` | Genera un token de recuperación y "envía" el correo (simulado hasta Fase 4) |
| `restablecer()` | `POST /api/auth/restablecer` | Define una nueva contraseña usando el token recibido |

**Detalle de seguridad importante:** tanto en `login()` como en `recuperar()`, los mensajes de error son **intencionalmente genéricos** ("Correo o contraseña incorrectos", en vez de decir cuál de los dos falló; o el mismo mensaje de "revisa tu correo" exista o no la cuenta). Esto evita que un atacante pueda usar el sistema para averiguar qué correos están registrados.

### 5.6 Formato estándar de todas las respuestas (`Response.php`)
Toda la API responde siempre con la misma forma, para que el futuro frontend (Fases 5/6) pueda manejar cualquier endpoint de manera genérica:

```json
// Éxito:
{ "exito": true, "mensaje": "Sesión iniciada correctamente...", "datos": { "id_cliente": 5, "nombre": "...", ... } }

// Error:
{ "exito": false, "mensaje": "Correo o contraseña incorrectos." }
```

---

## 6. Cómo probar esta fase (sin frontend todavía)

Como las Vistas se construyen hasta la Fase 5/6, puedes probar todo el backend ya mismo con **Postman**, **Thunder Client** (extensión de VS Code) o `curl`. Supón que el proyecto está en `http://localhost/bellatrix/`:

**Registrar un cliente nuevo:**
```bash
curl -X POST http://localhost/bellatrix/api/auth/registro \
  -H "Content-Type: application/json" \
  -d '{"nombre":"Ana Torres","correo":"ana@correo.com","contrasena":"clave123"}'
```

**Iniciar sesión como cliente:**
```bash
curl -X POST http://localhost/bellatrix/api/auth/login \
  -H "Content-Type: application/json" \
  -c cookies.txt \
  -d '{"tipo":"cliente","correo":"ana@correo.com","contrasena":"clave123"}'
```

**Iniciar sesión como Administrador** (usando los datos de prueba de tu script SQL):
```bash
curl -X POST http://localhost/bellatrix/api/auth/login \
  -H "Content-Type: application/json" \
  -c cookies.txt \
  -d '{"tipo":"empleado","usuario":"angel.admin","contrasena":"angel123"}'
```

**Ver quién tiene la sesión activa** (usa la cookie guardada en el paso anterior):
```bash
curl http://localhost/bellatrix/api/auth/me -b cookies.txt
```

**Probar el Middleware de Roles** (con la sesión de Administrador activa, esto debe funcionar):
```bash
curl http://localhost/bellatrix/api/admin/ping -b cookies.txt
```

**Probar que el Middleware BLOQUEA correctamente** (sin cookie / sin sesión, esto debe dar `401`):
```bash
curl http://localhost/bellatrix/api/admin/ping
```

**Cerrar sesión:**
```bash
curl -X POST http://localhost/bellatrix/api/auth/logout -b cookies.txt
```

---

## 7. Cosas que NO se hicieron a propósito (para respetar el protocolo de fases)

- ❌ No hay controladores de Inventario, Ventas, Cajas ni Pedidos (eso es la Fase 3).
- ❌ Las rutas `/api/admin/ping`, `/api/caja/ping` y `/api/cliente/ping` son **solo de demostración** del Middleware de Roles; se eliminarán cuando lleguen las rutas reales en la Fase 3.
- ❌ El envío real de correos (`enviarCorreo()`) sigue siendo una simulación (`error_log`), tal como se dejó documentado en la Fase 1 — se conecta en la Fase 4.
- ❌ No hay protección CSRF todavía (razonable para una API consumida por el mismo frontend con cookies de sesión, pero es algo a considerar si el alcance del proyecto crece).
- ❌ No hay ninguna vista HTML — todo se prueba con `curl`/Postman por ahora.

---

## 8. Antes de la Fase 3, por favor confírmame:

1. ¿Ejecutaste ya el script `database/002_eliminar_tabla_usuarios.sql` en tu base de datos?
2. ¿La ruta donde tienes el proyecto corre bien con `.htaccess` (¿tienes `mod_rewrite` activado en tu Apache/XAMPP)? Si prefieres, puedo darte una alternativa sin `.htaccess`.
3. ¿Probaste el login de Cliente, Administrador/Cajero, y el Middleware de Roles con los comandos de la sección 6?

---

## ¿Procedemos a la Fase 3: Lógica de Negocio y Controladores de Casos de Uso (CU008 — Inventario/Producción, y CU018 — Gestión de Cajas)?
