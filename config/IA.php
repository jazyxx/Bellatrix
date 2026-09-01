<?php
/**
 * ==========================================================================
 *  config/IA.php
 * ==========================================================================
 *  Configuración del proveedor de IA usado por el Asistente Financiero
 *  (app/controllers/AsistenteIAController.php).
 *
 *  Configurado por defecto con GROQ, porque su plan gratuito es real:
 *  no pide tarjeta de crédito, no cobra nada, y da miles de peticiones
 *  gratis por día — ideal para un proyecto de grado. Groq habla el
 *  mismo formato que OpenAI ("Chat Completions"), así que el
 *  controlador NO necesita ningún cambio: solo estos 3 valores.
 *
 *  CÓMO OBTENER TU API KEY GRATIS DE GROQ (2 minutos):
 *    1. Entra a https://console.groq.com y crea una cuenta (con
 *       correo, Google o GitHub — no pide tarjeta).
 *    2. En el menú lateral, entra a "API Keys" → "Create API Key".
 *    3. Copia la clave (empieza con "gsk_...") — solo se muestra una
 *       vez, así que guárdala ya — y pégala abajo en IA_API_KEY.
 *
 *  LÍMITES DEL PLAN GRATUITO (de sobra para una sustentación o un
 *  negocio pequeño): 14.400 peticiones al día y ~30.000 tokens por
 *  minuto con el modelo configurado abajo. Se reinician cada 24h.
 *
 *  ¿Y si quiero usar otro proveedor? Este mismo controlador también
 *  funciona, cambiando solo estos 3 valores, con: OpenAI
 *  (api.openai.com/v1/chat/completions, de pago), OpenRouter
 *  (openrouter.ai/api/v1/chat/completions, tiene modelos ":free") o
 *  DeepSeek (api.deepseek.com/chat/completions) — todos hablan el
 *  mismo formato "OpenAI-compatible".
 *
 *  NUNCA subas este archivo con tu clave real a un repositorio público
 *  (GitHub). Si vas a subir el proyecto, agrega config/IA.php a tu
 *  .gitignore y deja aquí solo el valor de ejemplo.
 * ==========================================================================
 */

return [
    // Endpoint de Groq (formato "OpenAI-compatible").
    'IA_ENDPOINT' => 'https://api.groq.com/openai/v1/chat/completions',

    // Modelo gratuito de Groq. llama-3.3-70b-versatile da las mejores
    // respuestas (más razonamiento) para interpretar cifras financieras.
    // Si necesitas más peticiones por día y te alcanza con respuestas
    // más simples, cambia a 'llama-3.1-8b-instant' (mismo límite de
    // peticiones, pero más rápido y con más margen de uso diario).
    'IA_MODELO'   => 'openai/gpt-oss-120b',

    // Tu clave secreta de Groq (formato "gsk_..."). NUNCA la expongas
    // en el frontend: por eso todo esto vive del lado del servidor.
    'IA_API_KEY'  => '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',
];