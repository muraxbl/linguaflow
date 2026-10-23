# LinguaFlow AI — traducción de catálogo con IA para PrestaShop

Módulo para **PrestaShop 1.7.6 a 9** (PHP 7.2 o superior) que traduce productos con IA:

- **Traducción automática de productos nuevos** a los idiomas que actives (con un retraso configurable para que termines de rellenar la ficha antes de traducir).
- **Acción masiva en el listado de productos**: selecciona productos → *Acciones masivas* → **Translate with AI (LinguaFlow)**. Funciona en el catálogo clásico (1.7.x / 8.0) y en la nueva lista de productos (8.1+ / 9).
- **Traducción masiva** desde *Catálogo › LinguaFlow AI*: productos sin traducir, por categoría (con subcategorías), por IDs o el catálogo completo.
- **Botón "Translate with AI"** en la pestaña *Módulos* de la ficha de producto.
- **Cola por lotes**: cada trabajo se divide en elementos (producto × idioma) que se procesan poco a poco. Cada producto se actualiza en cuanto llega su traducción. Los errores temporales (límites de uso, caídas de red) se reintentan hasta 3 veces.
- **Aviso al terminar**: el módulo lanza el hook `actionLinguaFlowAIJobCompleted`, escribe en el registro de PrestaShop y muestra un toast en el back office y una notificación nativa del navegador (que hay que activar una vez desde la configuración). Mientras hay trabajos en marcha aparece un indicador de progreso en la esquina.

## Proveedores

| Proveedor | URL base por defecto | API key | Listado de modelos |
|---|---|---|---|
| Anthropic (Claude) | `https://api.anthropic.com` | obligatoria | `GET /v1/models` |
| OpenAI | `https://api.openai.com/v1` | obligatoria | `GET /models` |
| Compatible con OpenAI (OpenRouter, Groq, Mistral, LM Studio, vLLM…) | (hay que indicarla) | opcional | `GET /models` |
| Ollama Cloud | `https://ollama.com` | obligatoria | `GET /api/tags` |
| Ollama local | `http://localhost:11434` | no | `GET /api/tags` |
| Open WebUI | `http://localhost:3000` | sí (clave de Open WebUI) | `GET /api/models` |

En la configuración, **Load models** consulta la API del proveedor y rellena un desplegable. Si el proveedor no permite listar modelos, se escribe el nombre a mano.

Notas:
- **Anthropic** se llama por HTTP (Messages API, `anthropic-version: 2026-10-21`) porque el SDK oficial de PHP exige PHP 8.1. Usa salida estructurada (JSON schema) y el nivel de *effort* configurable. Modelo por defecto: `claude-opus-5-5`. En los modelos que lo admiten se activa `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`): si el modelo rechaza una petición, Anthropic la repite automáticamente con su modelo de respaldo recomendado.
- **OpenAI** usa `response_format: json_schema` en modo estricto. Los proveedores compatibles y Open WebUI reciben el formato en el prompt y la respuesta se interpreta de forma tolerante (bloques de código, texto alrededor).
- **Ollama** usa `format` con el JSON schema (Ollama 0.5+).

## Qué se traduce

Se puede elegir qué campos traducir: nombre, resumen, descripción, meta title, meta description, textos de disponibilidad y plazos de entrega. Opciones:

- **Sobrescribir traducciones existentes**: si está desactivado, solo se traducen los campos vacíos o los que siguen siendo idénticos al idioma origen (PrestaShop copia el texto original en todos los idiomas al crear un producto).
- **URL amigable** generada a partir del nombre traducido.
- **Instrucciones de estilo** (tono, tuteo/usted, variante regional…) y **términos que nunca se traducen** (marcas).

El HTML de las descripciones se conserva. La traducción se escribe en todas las tiendas a las que pertenece el producto, se reindexa la búsqueda y se lanza `actionProductUpdate` para que otros módulos se enteren.

## Procesamiento de la cola

- **Desde el back office** (por defecto): cualquier pestaña abierta del back office procesa la cola en lotes de unos 20 segundos. Solo una pestaña actúa a la vez, y un bloqueo de MySQL (`GET_LOCK`) impide que dos procesos trabajen a la vez.
- **Cron** (recomendado para catálogos grandes): la configuración muestra una URL con token, por ejemplo:

  ```
  */5 * * * * curl -s "https://tutienda.com/module/linguaflowai/cron?token=XXXX"
  ```

  Parámetros opcionales: `limit` (número máximo de elementos, por defecto 50) y `seconds` (presupuesto de tiempo, por defecto 240).

## Instalación

Requisitos: PrestaShop 1.7.6 o superior, PHP 7.2 o superior y las extensiones cURL, JSON y mbstring. Sin dependencias externas.

```bash
./build.sh          # genera linguaflowai.zip
```

Después: *Módulos › Gestor de módulos › Subir un módulo* → `linguaflowai.zip`. También se puede copiar el repositorio en `modules/linguaflowai` (la carpeta debe llamarse así). `vendor/` solo contiene el autoloader de Composer y se incluye en el repositorio, así que no hace falta ejecutar Composer.

## Hooks para otros módulos

- `actionLinguaFlowAIJobCompleted` — `['id_job' => int, 'job' => array]`
- `actionLinguaFlowAIProductTranslated` — `['id_product' => int, 'id_lang' => int, 'product' => Product]`

## Estructura

```
linguaflowai.php                       Módulo: instalación, hooks, configuración
controllers/admin/AdminLinguaFlowAIController.php   Panel de trabajos + endpoints AJAX
controllers/front/cron.php             Worker por cron
src/Provider/                          Anthropic, OpenAI/compatibles/Open WebUI, Ollama (todo por cURL)
src/Translation/                       Prompt + escritura en product_lang
src/Queue/                             Trabajos, elementos, worker
src/Controller/Admin/BulkTranslateController.php    Acción masiva de la lista de productos de 8.1+
views/js/notifier.js                   Toasts, notificaciones nativas, progreso, worker del back office
                                       y acción masiva del catálogo clásico (1.7.x / 8.0)
```

## Seguridad

La API key se guarda en la tabla de configuración de PrestaShop y nunca se vuelve a mostrar en el formulario. Al cambiar de proveedor sin introducir una clave nueva, la anterior se borra.
