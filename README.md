# Bellatrix — Fase 1: Modelos de Datos en PHP

Sistema de información para la pastelería, heladería y salón de onces **Ambrosía**.

Este documento explica, en lenguaje sencillo, **qué se construyó en esta fase, por qué se construyó así, y qué debes revisar** antes de pasar a la Fase 2.

---

## 1. ¿Qué es esta fase y por qué existe?

En el patrón de arquitectura **MVC (Modelo-Vista-Controlador)**, el **Modelo** es la capa que:

- Sabe **cómo se ve** cada tabla de la base de datos (sus columnas).
- Sabe **cómo leer, guardar, actualizar y borrar** datos en esa tabla.
- Contiene las **reglas de negocio "propias" de esa entidad** (por ejemplo: un `Producto` sabe cómo actualizar su propio stock; un `GestorVentas` sabe cómo rechazar un egreso si no hay saldo).

Todavía **no existen** ni el enrutador (`Router.php`), ni los controladores, ni las vistas HTML. Eso es intencional: en esta fase construimos los "cimientos" para que, en las fases siguientes, los controladores solo tengan que **orquestar** estos modelos, sin tener que volver a escribir SQL desde cero.

---

## 2. ⚠️ Decisión importante que tomé (necesita tu confirmación)

Tu script SQL trae **dos sistemas de usuarios en paralelo**:

| Sistema A (el que usé) | Sistema B (no usado en esta fase) |
|---|---|
| Tabla `cliente` + tabla `empleado` (con `administrador`/`cajero`) | Tabla `usuarios` (genérica, con rol `empleado`/`cliente`) |
| Coincide 1 a 1 con las clases `Cliente`, `Empleado`, `Administrador`, `Cajero` de tu **Diagrama de Clases** | No aparece en el Diagrama de Clases |

**Decidí construir los modelos sobre el Sistema A**, porque es el que tu Diagrama UML describe con propiedades y métodos exactos (`idCliente`, `iniciarSesion()`, `idEmpleado`, `rol`, etc.).

La tabla `usuarios` quedó **sin modelo propio** en esta fase. Antes de la Fase 2 (Autenticación) necesito que me confirmes una de estas opciones:
1. **Ignoramos `usuarios`** y el login se hace 100% contra `cliente`/`empleado` (mi recomendación, ya que es más consistente con el resto del sistema).
2. `usuarios` es una tabla legacy de un prototipo anterior y **la eliminamos** del script SQL.
3. Tienes un uso específico planeado para `usuarios` que no me has contado — cuéntamelo y ajustamos el diseño.

---

## 3. Estructura de archivos entregada

```
fase1/
├── config/
│   └── Database.php          → Conexión única (Singleton) a MySQL vía PDO
├── models/
│   ├── Producto.php
│   ├── MateriaPrima.php
│   ├── Receta.php
│   ├── AlertaStock.php
│   ├── Cliente.php
│   ├── Empleado.php          → Clase BASE (padre)
│   ├── Administrador.php     → extends Empleado
│   ├── Cajero.php            → extends Empleado
│   ├── CarritoDeCompras.php
│   ├── CarritoItem.php
│   ├── Pedido.php
│   ├── DetallePedido.php
│   ├── Pago.php
│   ├── Notificacion.php
│   ├── Venta.php
│   ├── DetalleVenta.php
│   └── GestorVentas.php
└── README.md                 → Este archivo
```

---

## 4. Cómo se mapeó cada tabla SQL a su clase PHP

| Tabla SQL | Clase PHP | Clase en tu Diagrama UML |
|---|---|---|
| `productos` | `Producto.php` | `Producto` |
| `materia_prima` | `MateriaPrima.php` | *(no explícita, requerida por CU008/CU019)* |
| `recetas` | `Receta.php` | *(no explícita, requerida por CU008)* |
| `alerta_stock` | `AlertaStock.php` | *(no explícita, requerida por CU019)* |
| `cliente` | `Cliente.php` | `Cliente` |
| `empleado` | `Empleado.php` | `Empleado` |
| `administrador` + `empleado` | `Administrador.php` | `Administrador` (hereda de `Empleado`) |
| `cajero` + `empleado` | `Cajero.php` | `Cajero` (hereda de `Empleado`) |
| `carrito` | `CarritoDeCompras.php` | `CarritoDeCompras` |
| `carrito_items` | `CarritoItem.php` | *(la lista `items` de `CarritoDeCompras`)* |
| `pedido` | `Pedido.php` | `Pedido` |
| `detalle_pedido` | `DetallePedido.php` | *(la lista `productos` de `Pedido`)* |
| `pago` | `Pago.php` | `Pago` |
| `notificacion` | `Notificacion.php` | `Notificacion` |
| `ventas` | `Venta.php` | `Venta` |
| `detalle_venta` | `DetalleVenta.php` | *(relación `Venta incluye Producto`)* |
| `gestor_ventas` | `GestorVentas.php` | `GestorVentas` |

---

## 5. Explicación de las decisiones de diseño más importantes

### 5.1 `Database.php` — Conexión única (Singleton)
En vez de que cada modelo abra su propia conexión a MySQL, existe **un solo punto de entrada**: `Database::getConnection()`. Esto ahorra recursos del servidor y centraliza la configuración (host, usuario, contraseña) en un único lugar. **Debes editar las 4 constantes al inicio de este archivo** (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) según tu entorno local (XAMPP, WAMP, Laragon, etc.).

### 5.2 Herencia real: `Empleado` → `Administrador` / `Cajero`
Tal como pide tu Diagrama de Clases (`Empleado <|-- Administrador`), usé la herencia nativa de PHP con `extends`. Esto significa que `Administrador` y `Cajero` **automáticamente tienen** todas las propiedades y métodos de `Empleado` (nombre, usuario, `validarDatos()`, `ingresarSistema()`), y además cada uno agrega lo suyo (`nivelAcceso` en Administrador; `turno` en Cajero).

A nivel de base de datos, esto corresponde exactamente a cómo modelaste las tablas: `empleado` tiene los datos comunes, y `administrador`/`cajero` extienden esa fila con datos específicos, unidas por la misma llave primaria (`id_admin = id_empleado`, `id_cajero = id_empleado`).

### 5.3 Contraseñas siempre con `bcrypt`
Aunque en tu script SQL las contraseñas de prueba están en texto plano (ej. `angel123`) o con un hash de ejemplo, **todos los métodos de creación de usuarios en estos modelos usan `password_hash()`** (algoritmo bcrypt) antes de guardar cualquier contraseña nueva. Esto es un requisito de seguridad no negociable.

Como excepción temporal y **solo para que puedas hacer pruebas de inmediato con los datos de ejemplo de tu script**, `Empleado::ingresarSistema()` acepta tanto contraseñas hasheadas como en texto plano. **Te recomiendo re-hashear esos 2 usuarios de prueba en cuanto tengamos el `AuthController` en la Fase 2.**

### 5.4 El corazón del CU008 (descuento automático de stock)
Este caso de uso involucra **tres clases trabajando juntas**:

```
Venta::finalizar()
   │
   ├──► Producto::actualizarStock()      (descuenta el producto terminado)
   │
   └──► Receta::descontarInsumosPorVenta()
            │
            └──► MateriaPrima::descontarStock()   (descuento PROPORCIONAL, según receta)
```

Todo el proceso corre dentro de una **transacción SQL** (`beginTransaction()` / `commit()` / `rollBack()`): si algo falla a mitad de camino (por ejemplo, no hay suficiente harina), **ningún cambio se guarda**, evitando que el inventario quede en un estado inconsistente.

### 5.5 El corazón del CU018 (bloqueo de egresos)
`GestorVentas::registrarEgreso()` calcula el saldo disponible (`total_ventas - total_egresos`) **antes** de permitir el egreso. Si el monto solicitado supera ese saldo, lanza una excepción y el egreso **se rechaza automáticamente**. Además, gracias a la restricción `UNIQUE (canal, unidad_negocio, fecha)` de tu tabla, cada combinación de Pastelería/Heladería y Presencial/En línea tiene su **propia caja independiente por día**, tal como pediste.

> **Nota técnica:** la columna `saldo` de `gestor_ventas` es `GENERATED ALWAYS AS ... STORED` en tu script SQL, es decir, **MySQL la calcula solo**. Por eso ningún `INSERT`/`UPDATE` de `GestorVentas.php` intenta escribir en esa columna directamente — sería un error de SQL si lo hiciéramos.

### 5.6 El corazón del CU014 (pasarela de pagos)
En esta fase, `Pago::procesarPago()` y `Pago::confirmarTransaccion()` ya dejan lista toda la estructura de datos y el flujo (`Pendiente` → `Aprobado`/`Rechazado`), incluyendo que **un pago aprobado avanza automáticamente el `Pedido` a estado `'Confirmado'`**. La integración real con la pasarela externa (llamada HTTP a PSE/Nequi/etc.) se conectará en la **Fase 4**, sin necesidad de tocar este modelo.

### 5.7 Borrado lógico, no borrado físico
Los métodos `desactivar()` de `Cliente` y `Empleado` **no eliminan filas**, solo cambian `activo = 0`. Esto es intencional: si un cliente o empleado se eliminara físicamente, todas sus ventas, pedidos y pagos históricos (relacionados por llave foránea) se perderían o romperían. Es una práctica estándar en sistemas transaccionales.

### 5.8 Notificaciones: modelo listo, envío real pendiente (Fase 4)
`Notificacion::enviarCorreo()` y `enviarMensaje()` ya generan el mensaje correcto y guardan el registro en la base de datos, pero el envío real (conexión SMTP, por ejemplo con PHPMailer) queda marcado con `// TODO (Fase 4)`. Así el modelo queda 100% funcional a nivel de datos sin acoplarse todavía a una librería externa.

---

## 6. Cosas que NO se hicieron a propósito (para respetar el protocolo de fases)

- ❌ No hay `Router.php` ni `AuthController.php` (eso es Fase 2).
- ❌ No hay manejo de sesiones (`$_SESSION`) — los métodos `iniciarSesion()`/`ingresarSistema()` solo **validan credenciales y devuelven datos**; quién guarda la sesión es responsabilidad del futuro controlador.
- ❌ No hay integración real con pasarela de pagos ni envío real de correos (Fase 4).
- ❌ No hay ninguna vista HTML/CSS/Bootstrap (Fases 5 y 6).
- ❌ No se generan archivos PDF/Excel de reportes — los modelos devuelven los datos ya estructurados (arreglos), listos para que un controlador posterior los transforme al formato final.

---

## 7. Antes de la Fase 2, por favor confírmame:

1. ✅ / ❌ ¿Está bien la decisión de usar `cliente`/`empleado` como sistema de autenticación principal (ver sección 2)?
2. Los valores de conexión en `Database.php` (host, usuario, contraseña) — ¿coinciden con tu entorno local, o los ajusto?
3. ¿Confirmas que seguimos con **bcrypt** para las contraseñas nuevas (estándar de la industria), incluso aunque tu script SQL tenga datos de prueba en texto plano?

---

## ¿Procedemos a la Fase 2: Núcleo Backend (Router, Autenticación y Middleware de Roles)?
