# Changelog - Sistema POS (Backend)
Todos los cambios notables del servidor de base de datos y API estan documentados aqui.

## [1.8.1] - 2026-09-02
### Novedades de esta Gran Actualizacion (desde v1.4.4)
- **Soporte Oficial para App Movil:** Nuevo modulo API y perfiles para control de stock, lectura de codigos de barra y ventas desde el celular.
- **Acceso Remoto y 4G:** Arquitectura y soporte nativo para Cloudflare Tunnels (Smart Auto-Fallback), permitiendo conectar el celular desde cualquier lugar del mundo sin configurar IPs.
- **Canal de Sincronizacion en Tiempo Real:** Las ventas hechas con el celular (incluso con 4G) se reflejan al instante (1 segundo) en el panel de ventas de la PC de escritorio.
- **Seguridad y Licencias Anti-Pirateria:** Sistema de validacion de licencias integrado con pantalla de bloqueo y boton de copiado facil de la clave.
- **Actualizaciones 100% Automaticas:** Sistema continuo, inteligente y completamente automatico tanto para el servidor como para la caja.
- **Personalizacion Extrema del Terminal:** 4 nuevos modos de vista para productos (Tarjetas grandes, medianas, lista y supermercado) y paneles redimensionables.



## [1.8.0] - 2026-08-31
### Seguridad
- Endpoint de rescate OTA del backend protegido con token secreto configurable.
### Mejorado
- Pipeline CI/CD: el backend ahora genera GitHub Release con artefacto ZIP en cada versión.
- `AppConfig` centraliza la detección de plataforma (isMobile, isDesktop, isWeb).

## [1.7.6] - 2026-08-30
### Correcciones
- Auto-selección de Categoría y Marca recién creadas al cerrar el gestor desde el formulario de la App Móvil.
- Auto-formato de URL corregido para IPs y dominios al configurar el servidor.

## [1.7.5] - 2026-08-29
### Correcciones
- Se reparó definitivamente un error de codificación que generaba caracteres extraños (como tildes o eñes rotas) en la tabla del catálogo y en los carteles de error en la App Móvil.
- Se eliminó la inyección automática de la ruta local en la configuración de la App Móvil para facilitar el uso de dominios limpios con Cloudflare Tunnels.
- Se restauró la selección automática de Marcas y Categorías al crearlas desde el catálogo en la App Móvil.

## [1.7.4] - 2026-08-29
### Novedades de esta Gran Actualización (desde v1.4.4)
- **Soporte Oficial para la App Móvil:** El sistema ahora cuenta con todo el motor interno preparado para comunicarse en tiempo real con nuestra nueva Aplicación Móvil.
- **Actualizaciones 100% Automáticas:** Rediseñamos por completo el sistema de actualización. Ahora, cuando haya mejoras tanto para la caja como para el servidor, el proceso será continuo, inteligente y completamente automático, sin requerir clics innecesarios.
- **Personalización Extrema del Terminal:** Agregamos 4 nuevos modos de vista para los productos de Acceso Rápido (Tarjetas grandes, medianas, lista clásica y modo 'supermercado' ultra compacto) para adaptarse perfectamente a tu forma de vender. Además, podés ajustar libremente qué porción de la pantalla ocupa el carrito y qué porción ocupan los productos.
- **Mayor Estabilidad y Resiliencia:** Mejoramos la conexión de red y le dimos al sistema la capacidad de manejar grandes volúmenes de datos durante las actualizaciones sin interrumpir tu trabajo.
- **Seguridad Mejorada y Pantalla de Bloqueo:** Si el sistema es bloqueado (por falta de pago o anomalías), la pantalla se mostrará de forma profesional con opciones claras y sin cierres bruscos de la aplicación.
- **Fixes de Sistema Operativo:** Solucionado el problema con Windows Smart App Control y los permisos nativos de los instaladores.
- **Limpieza y Pulido Visual:** Eliminamos textos innecesarios y pulimos las pantallas para que tu experiencia de uso sea más limpia y profesional. Corregido un pequeño error visual donde el botón de actualización persistía en el menú principal tras actualizar.

---

## [1.4.4] - 2026-08-25
### Mejoras
- Versión base estable con soporte OTA.

