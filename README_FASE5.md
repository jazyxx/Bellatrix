# Bellatrix — Fase 5: Vistas Frontend Público (Landing Page y Catálogo)

Este documento explica **qué se construyó, las decisiones de diseño, y cómo probar el sitio público** antes de pasar a la Fase 6.

> 📄 Los README de las Fases 1-4 se conservan tal cual.

---

## 1. ¿Qué se construyó?

El sitio público completo de Ambrosía, en HTML5 + CSS3 + Bootstrap 5 (CDN) + JavaScript Vanilla (Fetch API), consumiendo la API construida en las Fases 2-4:

| Página | Casos de Uso | Requiere sesión |
|---|---|---|
| `index.html` — Landing | — (contenido de marca + destacados) | No |
| `catalogo.html` — Catálogo | CU011 | No (agregar al carrito sí exige sesión) |
| `login.html` | CU001 | No |
| `registro.html` | CU010 | No |
| `carrito.html` | CU012, CU013 | **Sí** (redirige a login si no hay sesión) |

```
bellatrix/
├── index.html, catalogo.html, login.html, registro.html, carrito.html
├── assets/
│   ├── css/estilos.css       ← sistema de diseño completo
│   └── js/
│       ├── api.js             ← envoltorio fetch compartido
│       ├── componentes.js     ← header dinámico + tarjeta de producto
│       ├── landing.js, catalogo.js, login.js, registro.js, carrito.js
├── .htaccess                  ← MODIFICADO (ver sección 2)
└── README_FASE5.md
```

---

## 2. ⚠️ Ajuste necesario en `.htaccess`

Le agregué **una sola línea** a tu `.htaccess` (que ya tenías funcionando, no toqué nada más):

```apache
DirectoryIndex index.html index.php
```

**¿Por qué?** Sin esta línea, al visitar `http://localhost/bellatrix/` tu servidor mostraría el JSON de la API (`index.php`) en vez de la Landing Page, porque ambos archivos compiten por ser la página de inicio. Esta línea le dice a Apache explícitamente: "si existe `index.html`, muéstralo primero". El resto de tu `.htaccess` (el `RewriteRule` que manda `/api/...` a `index.php`) sigue exactamente igual.

---

## 3. El sistema de diseño, explicado

### 3.1 Paleta y tipografía
Usé tu paleta pastel de referencia tal cual (rosa `#EDAFB8`, blush `#F3CFDC`, menta `#D2EECF`, salvia `#B0C4B1`), con un café-espresso (`#4A332E`) como color de texto en vez de negro puro — para que hasta el texto "sepa" a la marca. La rosa se usa para Pastelería y la menta para Heladería en las insignias de producto: el color no es decorativo, **codifica información** (con qué unidad de negocio corresponde cada producto).

Tipografía: **Fraunces** (serif cálida, con personalidad editorial de repostería) para titulares, y **Nunito Sans** (redondeada, amigable) para el texto — ambas de Google Fonts, cargadas por CDN.

### 3.2 El elemento de firma: el borde festoneado
Entre el Hero y la sección de Destacados (y en el footer) vas a ver un borde ondulado que imita el trazo de una manga pastelera decorando una torta. Está hecho con un truco de CSS puro (gradiente radial repetido, `.festoneado` en `estilos.css`), sin imágenes ni SVG — así que es liviano y se ve nítido en cualquier resolución. Es el detalle por el que quise que el sitio se sintiera específicamente como el de una pastelería, y no una plantilla genérica de e-commerce.

### 3.3 El "Salón de Onces"
Le di su propia sección en la Landing (no estaba en tu imagen de referencia "Landing Reference", que era de una pastelería sin esa tradición) porque es un diferenciador real de Ambrosía frente a cualquier otra pastelería. Me tomé la libertad de inventar el contenido (horario, texto) ya que no me diste esos datos — **por favor revísalo y dime si quieres que lo ajuste** a la realidad de tu negocio.

---

## 4. El flujo de "checkout exige autenticación", en detalle

Hay **dos niveles** de exigencia de sesión, a propósito:

1. **En el Catálogo** (`catalogo.html`): cualquiera puede mirar los productos libremente (CU011 es público). Solo al hacer clic en "Agregar" se verifica la sesión (`activarBotonesAgregarCarrito()` en `componentes.js`); si no hay sesión de Cliente, se guarda la página actual en `sessionStorage` y se redirige a `login.html?motivo=carrito`.

2. **En el Carrito** (`carrito.html`): aquí la exigencia es más estricta — ni siquiera se intenta mostrar el carrito. Apenas carga la página, `carrito.js` verifica la sesión ANTES de pintar nada, y redirige de inmediato si no la hay. Esto tiene sentido porque un carrito vacío/ajeno no le sirve de nada a un visitante sin cuenta.

En ambos casos, después de iniciar sesión exitosamente, `login.js` te regresa automáticamente a la página desde la que veías comprando (usando el valor guardado en `sessionStorage`).

---

## 5. Decisiones que quiero que confirmes

1. **No incluí un formulario de "Suscríbete al newsletter"** como tenía tu imagen de referencia (Dakingo), porque el backend no tiene un endpoint real para eso todavía — no quise construir un formulario decorativo que en realidad no hace nada. En su lugar, puse una sección "Visítanos" con dirección y horario (información honesta y útil). Si quieres el newsletter real, necesitaríamos agregar esa funcionalidad al backend primero.
2. **El contenido de "Salón de Onces"** (horario, descripción) lo inventé — revísalo.
3. Como no tengo fotos reales de tus productos, las tarjetas usan un fondo degradado pastel con la inicial del nombre del producto en vez de una imagen. Cuando tengas fotografías reales, solo hay que aprovechar el campo `foto` que ya existe en la tabla `productos` (Fase 1) y ajustar `tarjetaProductoHTML()` en `componentes.js` para mostrar `<img src="...">` en vez del degradado — ese campo ya llega disponible en los datos que entrega el backend, listo para cuando tengas las imágenes.
4. `carrito.html` termina en la confirmación del pedido (CU013); **no incluí la pantalla de pago (CU014)** todavía — decidí que ese último paso (elegir medio de pago y confirmar la transacción simulada) tenga su propia vista dedicada, para no sobrecargar el carrito. ¿La construyo ahora como parte de esta fase, o la dejamos para cuando lleguemos al Panel de Cliente en la Fase 6, junto con "mis pedidos"?

---

## 6. Cómo probar el sitio

1. Asegúrate de que tu proyecto esté en `C:\xampp\htdocs\bellatrix\` con la estructura completa (backend + estos archivos nuevos).
2. Abre `http://localhost/bellatrix/` — deberías ver la Landing Page (no el JSON de la API).
3. Ve a **Catálogo**, prueba los filtros (Todo / Pastelería / Heladería) y la búsqueda.
4. Sin iniciar sesión, haz clic en "Agregar" en cualquier producto — debe llevarte a `login.html` con el aviso de "Inicia sesión para continuar con tu compra".
5. Crea una cuenta nueva en **Crear cuenta**, inicia sesión, y verifica que te regresa automáticamente al catálogo.
6. Agrega un par de productos, ve a **Mi carrito** (ícono 🧺 del header), prueba cambiar cantidades (+/-) y eliminar un producto.
7. Escribe una dirección y confirma el pedido — debe mostrarte el número de pedido generado.

---

## 7. Cosas que NO se hicieron a propósito

- ❌ No hay Panel de Administrador/Cajero/Cliente (dashboards) — eso es la Fase 6.
- ❌ No hay pantalla de pago (CU014) — ver pregunta 4 de la sección 5.
- ❌ No hay página de detalle de producto individual (se puede agregar directo desde la tarjeta) — si la necesitas, la agrego.
- ❌ Las imágenes de producto son un placeholder de color (ver pregunta 3).

---

## ¿Procedemos a la Fase 6: Vistas Frontend Privado (Dashboards y Paneles)? ¿O prefieres que antes resolvamos la pantalla de pago (CU014) de la Fase 5?
