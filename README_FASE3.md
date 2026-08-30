# Bellatrix — Fase 3: Lógica de Negocio y Controladores de Casos de Uso

Este documento explica **qué se construyó en esta fase, cómo se conecta con las Fases 1 y 2, y cómo probarlo** antes de pasar a la Fase 4.

> 📄 `README_FASE1.md` (Modelos) y `README_FASE2.md` (Router/Auth) se conservan tal cual.

---

## 1. ¿Qué se construyó?

Tres controladores nuevos que **usan** los Modelos de la Fase 1 y quedan **protegidos** por el Middleware de Roles de la Fase 2:

| Controlador | Casos de Uso que implementa |
|---|---|
| `InventarioController.php` | CU002 (Gestionar Inventario), CU009 (Consultar Inventario), CU019 (Gestionar Materias Primas) |
| `VentaController.php` | CU005 (Gestionar Ventas), CU006 (Registrar Venta), CU007 (Añadir Producto a Venta), **CU008** (Finalizar/Anular Venta con descuento automático de stock) |
| `CajaController.php` | **CU018** (Gestionar Cajas por Unidad y Bloqueo de Egresos) |

---

## 2. ⚠️ Una decisión de diseño que tomé (por favor revísala)

Tu plan de trabajo original agrupaba el CU008 bajo un único "Controlador de Inventario & Producción". Sin embargo, al analizar el Diagrama de Clases con cuidado, noté que el CU008 en realidad ocurre en el momento de **finalizar una venta** (`Venta.finalizar()` en tu diagrama), no al editar el inventario manualmente.

Por eso **separé la responsabilidad en dos controladores**:
- `InventarioController` → gestión MANUAL del inventario (un Administrador/Cajero da de alta o edita productos/insumos).
- `VentaController` → el flujo del Punto de Venta, donde `finalizar()` es el que **dispara automáticamente** la lógica del CU008 (ya construida en la Fase 1, en `Venta::finalizar()` y `Receta::descontarInsumosPorVenta()`).

Esto respeta mejor el principio de "una clase, una responsabilidad" del patrón MVC, sin cambiar en nada la lógica de negocio que ya construimos. Si prefieres que estén unificados en un solo controlador, lo puedo ajustar fácilmente.

---

## 3. Estructura de archivos agregada en esta fase

```
bellatrix/
├── app/controllers/
│   ├── InventarioController.php  ← NUEVO
│   ├── VentaController.php       ← NUEVO
│   └── CajaController.php        ← NUEVO
├── routes.php                     ← ACTUALIZADO (nuevas rutas; se retiraron
│                                      las 3 rutas de demostración de la Fase 2)
├── models/
│   ├── Receta.php                 ← MODIFICADO (se agregó obtenerPorId())
│   └── AlertaStock.php            ← MODIFICADO (se agregaron obtenerPorId()
│                                      y obtenerActivaPorMateria())
└── README_FASE3.md                ← este archivo
```

> ℹ️ Igual que en la Fase 2, solo agregué métodos de **consulta puntual** (`obtenerPorId`, etc.) a dos modelos de la Fase 1; no toqué ninguna regla de negocio ya existente.

---

## 4. El flujo más importante de esta fase: CU008 + CU018 trabajando juntos

Esta es la secuencia completa que ocurre cuando un Cajero finaliza una venta (`POST /api/ventas/{id}/finalizar`):

```
VentaController::finalizar($idVenta)
   │
   ├─ 1) Venta::finalizar()                          [Fase 1 — CU008]
   │       │
   │       ├─ Por cada producto vendido:
   │       │     ├─ Producto::actualizarStock()        (descuenta el producto)
   │       │     └─ Receta::descontarInsumosPorVenta()  (descuenta materia prima proporcional)
   │       │
   │       └─ Todo dentro de una TRANSACCIÓN: si falta materia prima,
   │          nada se descuenta y el proceso se detiene aquí con un error.
   │
   └─ 2) GestorVentas::obtenerOCrearCajaDelDia() + registrarIngreso()  [Fase 1 — CU018]
           │
           └─ Solo se ejecuta SI el paso 1 fue exitoso. Registra el total
              de la venta como ingreso en la caja de HOY, para la unidad
              de negocio y canal específicos de esa venta.
```

**¿Por qué en ese orden?** Si el paso 1 (descuento de stock) falla, jamás llegamos al paso 2. Esto evita el peor escenario posible: registrar dinero en caja de una venta cuyo inventario, en realidad, nunca se pudo actualizar.

---

## 5. Explicación de las decisiones de diseño más importantes

### 5.1 ¿Quién puede hacer qué? (resumen de `routes.php`)

| Acción | Cliente | Cajero | Administrador |
|---|:---:|:---:|:---:|
| Gestionar productos (crear/editar) | ❌ | ✅ | ✅ |
| Eliminar productos | ❌ | ❌ | ✅ |
| Gestionar materias primas / recetas / alertas | ❌ | ❌ | ✅ |
| Operar el punto de venta (CU006-CU008) | ❌ | ✅ | ✅ |
| Ver historial de ventas | ❌ | Solo las suyas | Todas |
| Consultar la caja de HOY | ❌ | ✅ | ✅ |
| Registrar egresos / ingresos manuales | ❌ | ❌ | ✅ |
| Ver reportes históricos de caja | ❌ | ❌ | ✅ |

**Supuesto que agregué** (no estaba explícito en tus CUs): decidí que la gestión de **materias primas** sea exclusiva del Administrador, mientras que **productos terminados** los pueden gestionar Administrador y Cajero (tal como dice literalmente el CU002). Y que el Cajero, en el historial de ventas, solo vea las suyas. **Por favor confírmame si esto es correcto o si necesitas ajustar algún permiso.**

### 5.2 El bloqueo de egresos del CU018, en la práctica
`GestorVentas::registrarEgreso()` (ya construido en la Fase 1) lanza una excepción si el monto supera el saldo disponible. `CajaController::registrarEgreso()` simplemente atrapa esa excepción y la convierte en una respuesta HTTP `422` con el mensaje exacto (incluye el monto solicitado y el saldo real), para que el frontend pueda mostrárselo directamente al Administrador.

### 5.3 Alertas automáticas de stock bajo (CU019)
Cuando se ajusta manualmente el stock de una materia prima (`POST /api/inventario/materias-primas/{id}/ajustar-stock`), el controlador revisa automáticamente si quedó en stock bajo (`MateriaPrima::tieneStockBajo()`, de la Fase 1) y, si es así, genera una `AlertaStock` — pero solo si no existe ya una activa para ese mismo insumo, para no llenar el panel de alertas duplicadas.

> **Nota importante:** por ahora, esta verificación de alertas solo se dispara cuando alguien **ajusta manualmente** el stock desde el panel de Administrador. El descuento automático de materia prima que ocurre en `Venta::finalizar()` (CU008) **todavía no dispara esta misma verificación**. Lo dejé fuera a propósito para no acoplar `VentaController` con `InventarioController`; si quieres que también se generen alertas automáticamente después de cada venta, lo puedo agregar en la Fase 4 como una mejora explícita.

### 5.4 Todos los datos "sensibles" salen de la Sesión, no del cuerpo de la petición
Fíjate que en `VentaController::crear()`, el `id_empleado` de la venta se toma de `Sesion::obtenerId()`, **nunca** de lo que envía el frontend. Esto evita que alguien manipule la petición para registrar una venta a nombre de otro empleado.

---

## 6. Cómo probar esta fase con `curl`

Primero inicia sesión como Administrador (guarda la cookie) y luego prueba, por ejemplo, el flujo completo de una venta:

```bash
# 1) Login como Administrador
curl -X POST http://localhost/bellatrix/api/auth/login -H "Content-Type: application/json" \
  -c cookies.txt -d '{"tipo":"empleado","usuario":"angel.admin","contrasena":"angel123"}'

# 2) Crear un producto de prueba
curl -X POST http://localhost/bellatrix/api/inventario/productos -H "Content-Type: application/json" \
  -b cookies.txt -d '{"nombre":"Torta de Chocolate","unidad_negocio":"Pastelería","precio":45000,"stock":10}'
# (anota el "id_producto" que devuelve)

# 3) Abrir una venta presencial
curl -X POST http://localhost/bellatrix/api/ventas -H "Content-Type: application/json" \
  -b cookies.txt -d '{"canal":"Presencial","unidad_negocio":"Pastelería"}'
# (anota el "id_venta" que devuelve)

# 4) Agregar el producto a la venta (reemplaza {id_venta} y {id_producto})
curl -X POST http://localhost/bellatrix/api/ventas/{id_venta}/productos -H "Content-Type: application/json" \
  -b cookies.txt -d '{"id_producto": {id_producto}, "cantidad": 2}'

# 5) Finalizar la venta -> dispara CU008 (descuenta stock) + CU018 (registra en caja)
curl -X POST http://localhost/bellatrix/api/ventas/{id_venta}/finalizar -b cookies.txt

# 6) Verificar el estado de la caja de hoy
curl "http://localhost/bellatrix/api/cajas/hoy?canal=Presencial&unidad=Pastelería" -b cookies.txt

# 7) Probar el BLOQUEO de egresos (pide más de lo que hay en saldo -> debe dar error 422)
curl -X POST http://localhost/bellatrix/api/cajas/egreso -H "Content-Type: application/json" \
  -b cookies.txt -d '{"canal":"Presencial","unidad_negocio":"Pastelería","monto":999999999}'
```

---

## 7. Cosas que NO se hicieron a propósito

- ❌ No hay controlador de Usuarios Internos (CU003 — crear/editar Cajeros desde el panel Admin). Ya existe la lógica en `Administrador::gestionarEmpleado()` desde la Fase 1; solo falta exponerla como ruta. Se puede incluir en la Fase 4 o dejarla para cuando construyamos el Panel de Administrador (Fase 6) — tú decides.
- ❌ No hay generación de reportes en PDF/Excel — los endpoints de reportes devuelven JSON estructurado, listo para que el frontend (o un generador de PDF en una fase posterior) lo transforme.
- ❌ El catálogo PÚBLICO (CU011, sin necesidad de sesión) todavía no existe — se construye en la Fase 4/5 junto con el Carrito y el flujo E-commerce.
- ❌ Las alertas de stock no se generan automáticamente tras una venta (ver nota en la sección 5.3).

---

## 8. Antes de la Fase 4, por favor confírmame:

1. ¿Estás de acuerdo con la separación `InventarioController` / `VentaController` (sección 2)?
2. ¿Los permisos por rol de la tabla en la sección 5.1 son los que necesitas, o algo debe cambiar (ej. que el Cajero también pueda ver el historial completo de ventas)?
3. ¿Quieres que en la Fase 4 agregue también la generación automática de alertas después de una venta (mencionado en la sección 5.3), o prefieres dejarlo así por ahora?

---

## ¿Procedemos a la Fase 4: Módulo E-Commerce, Pasarela de Pagos y Sistema de Notificaciones?
