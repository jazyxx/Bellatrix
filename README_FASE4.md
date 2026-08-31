# Bellatrix — Fase 4: Módulo E-Commerce, Pasarela de Pagos y Notificaciones

Este documento explica **qué se construyó en esta fase, cómo se conecta con las Fases 1-3, y cómo probar el flujo de compra completo** antes de pasar a la Fase 5.

> 📄 Los README de las Fases 1, 2 y 3 se conservan tal cual (`README_FASE1.md`, `README_FASE2.md`, `README_FASE3.md`).

---

## 1. ¿Qué se construyó?

Cinco controladores nuevos que completan el ciclo de e-commerce de principio a fin:

| Controlador | Casos de Uso |
|---|---|
| `CatalogoController.php` | CU011 (Ver Catálogo de Productos) — **público** |
| `CarritoController.php` | CU012 (Gestionar Carrito de Compras) — Cliente |
| `PedidoController.php` | CU013 (Realizar Pedido), CU015 (Consultar Estado), CU017 (Gestionar Pedidos) |
| `PagoController.php` | CU014 (Realizar Pago en Línea — pasarela **simulada**) |
| `NotificacionController.php` | CU016 (historial de Notificaciones recibidas) |

Además, cumplí el pendiente que confirmaste: **la generación automática de alertas de stock bajo ahora también ocurre después de finalizar una venta** (no solo al ajustar stock manualmente). Ver sección 3.

---

## 2. El flujo completo de compra, de punta a punta

```
1. Cliente navega el catálogo          → GET  /api/catalogo/productos        (público)
2. Cliente agrega productos al carrito → POST /api/carrito/productos          (CU012)
3. Cliente confirma su pedido          → POST /api/pedidos                    (CU013)
                                          (el carrito se convierte en Pedido,
                                           estado inicial: 'Pendiente de pago')
4. Cliente inicia el pago              → POST /api/pagos                      (CU014)
5. Cliente confirma la transacción     → POST /api/pagos/{id}/confirmar       (CU014, simulado)
                                          (si es aprobado, el Pedido pasa a
                                           'Confirmado' automáticamente, y se
                                           dispara la notificación al cliente)
6. Administrador/Cajero gestionan      → GET  /api/pedidos/gestion?estado=... (CU017)
   el pedido y avanzan su estado       → PUT  /api/pedidos/{id}/estado
                                          (cada cambio dispara una notificación
                                           nueva al cliente, automáticamente)
7. Cliente hace seguimiento            → GET  /api/pedidos/{id}               (CU015)
   y ve sus notificaciones             → GET  /api/notificaciones             (CU016)
```

Todo este flujo reutiliza al 100% la lógica de negocio que ya construimos en la Fase 1 (`Pedido::confirmar()`, `Pago::confirmarTransaccion()`, `Pedido::cambiarEstado()` → `notificarCliente()`). Los controladores de esta fase solo la conectan con rutas HTTP protegidas por rol.

---

## 3. La generación automática de alertas, ahora también tras una venta

Como pediste, refactoricé la regla "¿hay que generar una alerta de stock bajo?" para que viva en **un solo lugar** (`AlertaStock::generarSiAplica()`, en el Modelo), y ahora la usan **dos** controladores distintos:

```
AlertaStock::generarSiAplica($materiaPrima)
      │
      ├─ Llamado desde InventarioController::ajustarStockMateria()   (Fase 3, ajuste manual)
      │
      └─ Llamado desde VentaController::finalizar()                  (Fase 4, NUEVO)
              │
              └─ Después de que Venta::finalizar() descuenta la materia
                 prima de cada producto vendido (CU008), se revisa CADA
                 insumo afectado y se genera su alerta si aplica.
```

Esto significa que si, por ejemplo, una venta agota la harina disponible, la alerta aparecerá automáticamente en `GET /api/inventario/alertas` sin que nadie tenga que revisarlo manualmente.

---

## 4. Decisiones de diseño importantes de esta fase

### 4.1 El catálogo muestra TODOS los productos, marcados como "Agotado" cuando corresponde
Como se explica en el propio `CatalogoController.php`: el CU011 dice literalmente que el sistema **"marca automáticamente como Agotado"** lo que no tiene stock — es decir, el producto se sigue mostrando, no se oculta. Por eso el catálogo trae `Producto::listarTodos(false)` (todos) y agrega un campo calculado `'agotado'` en la respuesta, en vez de filtrar por la columna `disponible`.

> ⚠️ Nota técnica que quiero que conozcas: la columna `disponible` de tu tabla `productos` se usa, en la Fase 1, para DOS cosas a la vez: (a) que el Administrador desactive manualmente un producto, y (b) que se marque automáticamente en 0 cuando el stock llega a 0 (`Producto::marcarAgotado()`). Como es un solo campo para dos significados, technically un producto "desactivado a propósito" y uno "agotado por stock" se ven igual desde afuera. Para el alcance actual no es un problema, pero si en el futuro necesitas distinguir ambos casos (ej. ocultar productos descontinuados PERO seguir mostrando los agotados), lo ideal sería agregar una columna nueva a `productos` (ej. `activo`) separada de `disponible`. Te lo señalo para que lo tengas en el radar; no es necesario resolverlo ahora.

### 4.2 La pasarela de pagos es una SIMULACIÓN a propósito
`PagoController::confirmar()` reemplaza, por ahora, al "webhook" que en producción llegaría directamente desde PSE/Nequi/etc. El Cliente mismo dispara la confirmación (con `"aprobado": true/false` en el body), simulando la respuesta de la pasarela. Esto es exactamente lo que pedía tu plan de trabajo para esta fase ("simulación/integración de Pasarela de Pagos") y te permite probar el flujo de compra completo sin necesitar credenciales reales todavía. Cuando tengas una cuenta real de PSE/Nequi, solo hay que reemplazar el INTERIOR de ese método por la llamada HTTP real — el resto del sistema no se entera del cambio.

### 4.3 Reglas de "propiedad" (un cliente no puede ver pedidos/pagos de otro)
`PedidoController::ver()` y `PagoController` verifican que, si quien consulta tiene rol Cliente, el pedido/pago le pertenezca (comparando `idCliente` contra `Sesion::obtenerId()`). Administrador y Cajero sí pueden ver cualquier pedido (lo necesitan para el CU017). Esto evita que, cambiando el `id` en la URL, un cliente pueda espiar información de otro.

### 4.4 Notificaciones: se siguen "enviando" de forma simulada
Tal como quedó documentado desde la Fase 1, `Notificacion::enviarCorreo()` todavía escribe en el log del servidor en vez de mandar un correo real. **No integré una librería SMTP real en esta fase** porque no formaba parte explícita de lo que planeamos ("Sistema de notificaciones... al cambiar el estado del pedido" ya está resuelto a nivel de lógica y de disparo automático). Si quieres que conecte PHPMailer/SMTP de verdad ahora, dímelo y lo agrego antes de pasar a la Fase 5; si no, quedará pendiente para cuando definamos cómo vas a alojar el proyecto (algunos hostings tienen restricciones para enviar correo saliente).

---

## 5. Cómo probar el flujo completo con `curl`

```bash
# 1) Registrar e iniciar sesión como Cliente
curl -X POST http://localhost/bellatrix/api/auth/registro -H "Content-Type: application/json" \
  -d '{"nombre":"Ana Torres","correo":"ana2@correo.com","contrasena":"clave123"}'

curl -X POST http://localhost/bellatrix/api/auth/login -H "Content-Type: application/json" \
  -c cookies_cliente.txt -d '{"tipo":"cliente","correo":"ana2@correo.com","contrasena":"clave123"}'

# 2) Ver el catálogo público (no necesita cookie)
curl http://localhost/bellatrix/api/catalogo/productos

# 3) Agregar un producto al carrito (reemplaza {id_producto} por uno real)
curl -X POST http://localhost/bellatrix/api/carrito/productos -H "Content-Type: application/json" \
  -b cookies_cliente.txt -d '{"id_producto": {id_producto}, "cantidad": 2}'

# 4) Confirmar el pedido
curl -X POST http://localhost/bellatrix/api/pedidos -H "Content-Type: application/json" \
  -b cookies_cliente.txt -d '{"direccion_entrega":"Calle 10 # 20-30, Bogotá"}'
# (anota el "id_pedido")

# 5) Iniciar el pago
curl -X POST http://localhost/bellatrix/api/pagos -H "Content-Type: application/json" \
  -b cookies_cliente.txt -d '{"id_pedido": {id_pedido}, "medio_pago": "PSE"}'
# (anota el "id_pago")

# 6) Confirmar el pago (simulado, aprobado = true)
curl -X POST http://localhost/bellatrix/api/pagos/{id_pago}/confirmar -H "Content-Type: application/json" \
  -b cookies_cliente.txt -d '{"aprobado": true}'

# 7) Ver que el pedido ya quedó "Confirmado"
curl http://localhost/bellatrix/api/pedidos/{id_pedido} -b cookies_cliente.txt

# 8) Ver que se generó la notificación automática
curl http://localhost/bellatrix/api/notificaciones -b cookies_cliente.txt

# 9) Ahora como Administrador: ver la bandeja de pedidos confirmados y avanzar su estado
curl -X POST http://localhost/bellatrix/api/auth/login -H "Content-Type: application/json" \
  -c cookies_admin.txt -d '{"tipo":"empleado","usuario":"angel.admin","contrasena":"angel123"}'

curl "http://localhost/bellatrix/api/pedidos/gestion?estado=Confirmado" -b cookies_admin.txt

curl -X PUT http://localhost/bellatrix/api/pedidos/{id_pedido}/estado -H "Content-Type: application/json" \
  -b cookies_admin.txt -d '{"estado":"En preparación"}'
```

---

## 6. Cosas que NO se hicieron a propósito

- ❌ La pasarela de pagos sigue siendo una simulación (ver sección 4.2).
- ❌ El envío real de correos sigue siendo una simulación por log (ver sección 4.4) — dime si lo conectamos ahora.
- ❌ No hay generación de comprobantes en PDF — `PagoController::comprobante()` devuelve los datos ya estructurados en JSON, listos para que un generador de PDF los use en una fase posterior.
- ❌ No se agregó el CU003 (gestión de usuarios internos vía API) — sigue pendiente de decidir si va en una fase dedicada al Panel de Administrador.

---

## 7. Antes de la Fase 5, por favor confírmame:

1. ¿Está bien que la generación de alertas tras una venta quede como se explicó en la sección 3?
2. Sobre las notificaciones por correo (sección 4.4): ¿las conecto a un envío real (SMTP/PHPMailer) ahora, o seguimos con la simulación hasta que definamos el hosting?
3. ¿Todo el flujo de compra de la sección 5 te funcionó al probarlo?

---

## ¿Procedemos a la Fase 5: Vistas Frontend Público (Landing Page y Catálogo)?
