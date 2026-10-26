La versión 1.2.0 corrige cinco problemas detectados en la revisión del módulo: lectura de archivos a través de URLs de proveedores, envío de claves guardadas a destinos elegidos en una petición, ausencia de permisos en acciones personalizadas, HTML peligroso en traducciones y escrituras que alcanzaban otras tiendas.

Las rutas administrativas requieren sesión, token y los permisos de lectura, creación, edición o borrado de la acción. Las modificaciones usan POST. Crear, ejecutar o revisar traducciones requiere además editar productos. La configuración, el glosario y la memoria son globales; administrarlos requiere permiso de configuración del módulo y acceso a todas las tiendas. El cron es un trabajador global autenticado por un token aleatorio: mantén privada su URL.

Los proveedores cloud quedan fijados a sus URLs oficiales. Los endpoints propios son destinos de confianza definidos por quien administra la configuración global. No deben configurarse servidores no confiables. La lista de modelos solo usa el proveedor y la URL guardados; cambiar el endpoint no conserva su clave anterior. El cliente admite únicamente HTTP/HTTPS, rechaza credenciales embebidas, fragmentos, controles y redirecciones, limita la respuesta a 2 MB y evita devolver cuerpos sin interpretar o claves en los errores. Se exige HTTPS para enviar claves salvo en loopback. Se mantiene el acceso a servidores locales de IA como función explícita de configuración.

Todas las escrituras de productos pasan por un saneador, incluidas las traducciones de memorias importadas y las revisiones editadas. HTMLPurifier usa una lista permitida independiente de `PS_USE_HTMLPURIFIER`; si falta la biblioteca, el módulo rechaza la escritura de descripciones. Se conserva el formato básico y los enlaces seguros; estilos, eventos, scripts, SVG e iframes quedan fuera. No se ejecuta código devuelto por un modelo. No se garantiza que un modelo respete las instrucciones de traducción: la seguridad del HTML se aplica en el guardado.

Cada trabajo pertenece a una tienda. El repositorio administrativo limita consultas y operaciones a tiendas autorizadas, valida asociaciones de productos e idiomas y separa las tareas pendientes por tienda. El trabajador del back office hereda ese mismo límite. El cron puede procesar todos los trabajos. Al elegir ambas tiendas se crean trabajos separados; cada tienda aporta su texto de origen y recibe solo su propia traducción. La indexación y los hooks se ejecutan en ese contexto y lo restauran al terminar, incluso si hay una excepción.

Las filas ya guardadas por versiones anteriores no se modifican durante la actualización. Las tareas que ya existan conservan la tienda de su trabajo; para traducir ambas, crea una nueva operación con ese alcance. La revisión no incluyó una tienda de producción, configuración del servidor, temas o módulos de terceros, ni commits eliminados del historial accesible. El repositorio era privado al revisarlo. Gitleaks no encontró secretos en los cinco commits iniciales, los archivos locales ni los metadatos de la release. GitHub tenía desactivado secret scanning; el workflow añadido ejecuta Gitleaks y pruebas de regresión.

Para ejecutar las pruebas sin una tienda instalada:

```sh
php tests/run.php
```

La suite básica prueba permisos antes de cualquier consulta, CSRF, métodos HTTP, alcances, URLs y rechazo de HTML. Para las integraciones, usa exclusivamente una instancia desechable de MariaDB con la base `linguaflow_security_tests` creada, accesible como `root` sin contraseña por socket local, y una copia oficial de HTMLPurifier. El servidor HTTP de respuestas ficticias debe escuchar solo en loopback:

```sh
php -S 127.0.0.1:18973 tests/http-fixture.php
LFAI_TEST_DB_SOCKET=/tmp/test-mysql.sock \
LFAI_TEST_PURIFIER_AUTOLOAD=/ruta/htmlpurifier/library/HTMLPurifier.auto.php \
LFAI_TEST_HTTP_BASE=http://127.0.0.1:18973 php tests/run.php
```

Las pruebas de integración crean y eliminan tablas con prefijo `test_` dentro de esa base. No apuntes el socket a una instalación real. Se comprobaron peticiones HTTP reales contra respuestas ficticias, saneamiento con HTMLPurifier 4.18.0 y consultas reales con MariaDB. Las pruebas del límite con PrestaShop usan adaptadores mínimos; la instalación y los permisos efectivos de cada perfil deben verificarse también en una tienda de pruebas antes de actualizar producción.
