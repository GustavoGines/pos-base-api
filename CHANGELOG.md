# Changelog 📝 Sistema POS (Backend)
Todos los cambios notables del servidor web y base de datos (Laravel/PHP) están documentados aquí.

## [1.7.3] - 2026-08-29
### Correcciones
- Corrección crítica en el actualizador: el proceso PHP ahora se localiza con ruta absoluta para garantizar la ejecución correcta de las migraciones y optimizaciones en todos los entornos.
- Corrección de codificación de texto en la interfaz de inicio: caracteres especiales como tildes y eñes ahora se muestran correctamente.
- Mejora en la app móvil: la barra de "Producto no encontrado" ahora desaparece correctamente al escanear el siguiente código.
- Corrección en el formulario de productos: las marcas y categorías recién creadas ahora se seleccionan automáticamente en el desplegable.

---

## [1.7.0] - 2026-08-26
### Novedades de esta Gran Actualización (desde v1.4.4)
- **Soporte Oficial para la App Móvil:** El sistema ahora cuenta con todo el motor interno preparado para comunicarse en tiempo real con nuestra nueva Aplicación Móvil.
- **Actualizaciones 100% Automáticas:** Rediseñamos por completo el sistema de actualización. Ahora, cuando haya mejoras tanto para la caja como para el servidor, el proceso será continuo, inteligente y completamente automático, sin requerir clics innecesarios.
- **Personalización Extrema del Terminal:** Agregamos 4 nuevos modos de vista para los productos de Acceso Rápido (Tarjetas grandes, medianas, lista clásica y modo 'supermercado' ultra compacto) para adaptarse perfectamente a tu forma de vender. Además, podés ajustar libremente qué porción de la pantalla ocupa el carrito y qué porción ocupan los productos.
- **Mayor Estabilidad y Resiliencia:** Mejoramos la conexión de red y le dimos al sistema la capacidad de manejar grandes volúmenes de datos durante las actualizaciones sin interrumpir tu trabajo.
- **Limpieza y Pulido Visual:** Eliminamos textos innecesarios y pulimos las pantallas para que tu experiencia de uso sea más limpia y profesional. Corregido un pequeño error visual donde el botón de actualización persistía en el menú principal.

## [1.6.4] - 2026-08-26
### Mejoras
- Preparado el backend para soportar la actualización silenciosa sin interrumpir al frontend prematuramente.

## [1.6.3] - 2026-08-26
### Bugs Arreglados
- Solucionado el reinicio de conexión prematuro en Laravel durante despliegues pesados.

## [1.6.2] - 2026-08-26
### Bugs Arreglados
- Solucionado el parseo de changelogs en scripts de GitHub Actions (awk) garantizando codificación UTF-8 pura sin BOM.

## [1.6.1] - 2026-08-25
### Bugs Arreglados
- Corregido el Endpoint de Version para evitar retornos cacheados erróneos del OPcache de PHP al finalizar una actualización.

## [1.5.0] - 2026-08-25
### Características Nuevas
- Script automático para disparar git pull, composer install, y limpiezas de caché de forma unificada para actualizaciones de backend.

## [1.4.4] - 2026-08-25
### Mejoras
- Versión base estable.
