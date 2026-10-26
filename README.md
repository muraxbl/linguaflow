# LinguaFlow AI — traducción de catálogo con IA para PrestaShop

Módulo para **PrestaShop 1.7.6 a 9** (PHP 7.2 o superior) que traduce productos con IA:

- **Traducción automática de productos nuevos** a los idiomas que actives (con un retraso configurable para que termines de rellenar la ficha antes de traducir).
- **Acción masiva en el listado de productos**: selecciona productos → *Acciones masivas* → **Translate with AI (LinguaFlow)**. Funciona en el catálogo clásico (1.7.x / 8.0) y en la nueva lista de productos (8.1+ / 9).
- **Traducción masiva** desde *Catálogo › LinguaFlow AI*: productos sin traducir, por categoría (con subcategorías), por IDs o el catálogo completo.
- **Botón "Translate with AI"** en la pestaña *Módulos* de la ficha de producto.
- **Cola por lotes**: cada trabajo se divide en elementos (producto × idioma) que se procesan poco a poco. Cada producto se actualiza en cuanto llega su traducción. Los errores temporales (límites de uso, caídas de red) se reintentan hasta 3 veces.
- **Glosario obligatorio, memoria de traducción y revisión** (ver más abajo): terminología fija por idioma, reutilización de párrafos ya traducidos y retención de las traducciones dudosas hasta que las apruebes.
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
- **Anthropic** se llama por HTTP (Messages API, `anthropic-version: 2026-10-21`) porque el SDK oficial de PHP exige PHP 8.1. Nivel de *effort* configurable; la API 2026-10-21 no admite `temperature`, así que en los reintentos solo cuenta el motivo del rechazo. Modelo por defecto: `claude-opus-5-5`. En los modelos que lo admiten se activa `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`): si el modelo rechaza una petición, Anthropic la repite automáticamente con su modelo de respaldo recomendado.
- **OpenAI, compatibles y Open WebUI** usan `/chat/completions`. Si un modelo rechaza `temperature` (modelos de razonamiento), se deja de enviar automáticamente.
- **Ollama**: API nativa `/api/chat`, con opción de desactivar el razonamiento previo (`think=false`) y fijar `num_ctx`.
- **Open WebUI con Ollama detrás**: además de `/api/chat/completions`, Open WebUI publica la API de Ollama en `/ollama`. Usa el proveedor **Ollama** con URL base `https://tu-openwebui/ollama` y tu clave de Open WebUI: es la API nativa (admite `think` y `num_ctx`) y sigue funcionando aunque `/api/chat/completions` falle.

## Cómo se traduce

El motor está pensado para funcionar bien también con modelos locales pequeños (Gemma, Qwen…), no solo con los grandes:

1. **Campo a campo, nombre primero.** El nombre traducido se pasa como contexto al resto de campos. Un meta title "Nombre | Marca" con el mismo nombre reutiliza la traducción sin llamar al modelo.
2. **Descripciones párrafo a párrafo.** El HTML se trocea por etiquetas de bloque (`<p>`, `<h2>`, `<li>`…), que nunca llegan al modelo. Las etiquetas en línea (`<strong>`, `<a>`, `<br>`) viajan como marcadores cortos `<t1>…</t1>` / `<t2/>`, se valida que vuelvan una vez cada una y bien anidadas, y se restauran.
3. **Prompt por capas**, de lo fijo a lo variable (aprovecha la caché de prompt de Ollama, Anthropic y OpenAI): reglas, estilo del idioma (variante y tratamiento), instrucciones de la tienda, tipo de campo, pista de la categoría, **solo los términos del glosario que aparecen en ese párrafo**, contexto del producto y el texto.
4. **Validación y reintentos.** Se rechaza y se vuelve a pedir (hasta 3 veces, subiendo la temperatura y diciendo al modelo por qué se rechazó) si la respuesta viene vacía, en mayúsculas, sin una marca o código protegido, con los marcadores rotos o con una meta description demasiado larga. Último recurso: traducir el párrafo sin marcadores (se pierde la negrita, no la traducción).
5. **Revisión automática (QA).** Cifras perdidas (`2.200` = `2 200` = `2200`, `3,7` = `3.7`), texto sin traducir, restos de español, mayúsculas, longitudes SEO, negritas o enlaces perdidos y términos del glosario no respetados. **Si hay avisos, la traducción no se guarda en el producto**: queda en *LinguaFlow AI › Revisión* para aprobarla (editándola si hace falta), volver a traducirla o descartarla.

### Glosario

*Catálogo › LinguaFlow glossary.* Término de origen → traducción por idioma (`EN`, `FR`, `IT`, `PT`, `DE`…). El término encaja sin distinguir mayúsculas, como palabra completa y en plural (`tira LED` encaja con `Tiras LED`, `plafón` con `plafones`). Se importa y exporta en el mismo formato que `glossary.json`:

```json
{ "plafón": { "EN": "ceiling light", "FR": "plafonnier", "DE": "Deckenleuchte" } }
```

### Memoria de traducción

- **Aprobadas** (`ok`): lo que apruebas en la revisión. Se guardan por campo y también por párrafo, así un párrafo corregido se reutiliza en todos los productos que lo repiten. Valen con cualquier modelo.
- **Automáticas** (`mt`): traducciones que pasaron la validación. Solo se reutilizan con el mismo modelo, versión del prompt, pista de categoría y términos de glosario aplicados; si cambia el glosario, se traducen de nuevo.
- Se puede importar un `tm.json` del traductor web.

### Pistas por categoría

Contexto para el modelo según la categoría por defecto del producto (o la de su categoría padre más cercana con pista). Por ejemplo, para "Estancas": *"barra" tiene LED integrado, "pantalla" usa tubos T8*.

### Probar el motor sin PrestaShop

`tools/try-translation.php` ejecuta el mismo motor desde la línea de comandos (no se incluye en el zip). La configuración va en variables de entorno, así la clave no queda escrita en ningún fichero:

```bash
export LFAI_PROVIDER=openwebui LFAI_BASE_URL=https://tu-openwebui LFAI_MODEL=gemma4:12b
read -s LFAI_API_KEY && export LFAI_API_KEY
php tools/try-translation.php --list-models
php tools/try-translation.php --to=FR --glossary=glossary.json --field=Description descripcion.html
```

## Qué se traduce

Se puede elegir qué campos traducir: nombre, resumen, descripción, meta title, meta description, textos de disponibilidad y plazos de entrega. Opciones:

- **Sobrescribir traducciones existentes**: si está desactivado, solo se traducen los campos vacíos o los que siguen siendo idénticos al idioma origen (PrestaShop copia el texto original en todos los idiomas al crear un producto).
- **URL amigable** generada a partir del nombre traducido.
- **Estilo por idioma** (variante y tratamiento: inglés británico, *vous*, *Sie*, portugués europeo…) e **instrucciones generales** de la tienda.
- **Marcas protegidas** (y el fabricante de cada producto) y **códigos protegidos** por expresión regular (IP65, E27, GU10, SMD 2835…): nunca se traducen y se comprueba que aparezcan en la traducción.
- **Ollama**: desactivar el razonamiento previo (`think=false`) de modelos como Gemma 4 o Qwen 3 y fijar `num_ctx`.

El HTML permitido de las descripciones se conserva y se sanea antes de guardar. Se reindexa la búsqueda y se lanza `actionProductUpdate` dentro del contexto de la tienda traducida.

### Multitienda

En la configuración se elige el alcance predeterminado:

- **Current shop context**: actúa solo en la tienda seleccionada en el selector de PrestaShop. Es el valor predeterminado de la versión 1.2.0. Si el selector está en un grupo o en todas las tiendas, selecciona una tienda concreta o elige el alcance siguiente.
- **All authorized shops (both shops when there are two)**: actúa en ambas tiendas cuando hay dos, siempre dentro de los permisos del empleado y de las asociaciones del producto.

El formulario de traducción masiva y el panel del producto permiten cambiar ese alcance para cada operación. La acción masiva del listado y los productos nuevos usan el valor de configuración.

Cada tienda tiene su propio trabajo y se traduce a partir de sus propios textos. No se copia la traducción de una tienda a la otra. La cola, la revisión y los avisos solo muestran trabajos de tiendas autorizadas; una tarea pendiente en una tienda no bloquea la traducción del mismo producto e idioma en otra. La memoria, el glosario y la configuración del proveedor siguen siendo globales.

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

Después: *Módulos › Gestor de módulos › Subir un módulo* → `linguaflowai.zip`.

El empaquetado requiere Git y toma únicamente archivos de ejecución versionados. Si estás desarrollando, añade los nuevos archivos al índice antes de generar el ZIP. Los archivos locales sin seguimiento, los tests y los metadatos del repositorio quedan fuera.

> El zip que genera GitHub ("Download ZIP") **no sirve**: contiene la carpeta `linguaflow-main/` y PrestaShop exige que el zip tenga dentro una carpeta llamada exactamente `linguaflowai/`. Usa `build.sh`. También se puede copiar el repositorio en `modules/linguaflowai` (la carpeta debe llamarse así). `vendor/` solo contiene el autoloader de Composer y se incluye en el repositorio, así que no hace falta ejecutar Composer.

## Hooks para otros módulos

- `actionLinguaFlowAIJobCompleted` — `['id_job' => int, 'job' => array]`
- `actionLinguaFlowAIProductTranslated` — `['id_product' => int, 'id_lang' => int, 'product' => Product]`

## Estructura

```
linguaflowai.php                       Módulo: instalación, hooks, configuración
controllers/admin/AdminLinguaFlowAIController.php   Panel de trabajos, revisión y endpoints AJAX
controllers/admin/AdminLinguaFlowAIGlossaryController.php   Glosario, memoria y pistas por categoría
controllers/front/cron.php             Worker por cron
src/Provider/                          Anthropic, OpenAI/compatibles/Open WebUI, Ollama (todo por cURL)
src/Translation/                       Motor: HTML y marcadores, glosario, términos protegidos,
                                       prompt, QA, memoria, traducción por producto y escritura
src/Queue/                             Trabajos, elementos, worker
views/js/notifier.js                   Toasts, notificaciones nativas, progreso, worker del back office
                                       y acción masiva del listado de productos (1.7.x a 9)
```

## Seguridad

La API key se guarda en la tabla de configuración de PrestaShop y nunca se vuelve a mostrar en el formulario. Al cambiar de proveedor **o de URL** sin introducir una clave nueva, la anterior se borra. Los proveedores cloud usan sus endpoints oficiales. Para servidores propios se selecciona OpenAI compatible, Ollama local u Open WebUI; hay que guardar el proveedor y la URL antes de cargar los modelos. Las claves requieren HTTPS salvo para servicios en loopback (`localhost`, `127.0.0.1`, `::1`).

Las acciones comprueban sesión, token y permisos antes de modificar datos. Traducir requiere también permiso para editar productos. Como el proveedor, el glosario y la memoria son globales, su administración requiere permiso de configuración del módulo y acceso a todas las tiendas. Las descripciones se sanean con HTMLPurifier, incluido en PrestaShop, aunque el saneador general de la tienda esté desactivado. No se permiten scripts, manejadores de eventos, iframes ni estilos en esas traducciones.

Los detalles y las pruebas están en [SECURITY.md](SECURITY.md). La revisión del código y del historial no detectó credenciales incrustadas; el correo del autor sigue formando parte de los metadatos de los commits.
