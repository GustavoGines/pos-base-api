# Changelog - Caja4U (Backend API)
Todos los cambios notables del servidor base están documentados aquí.


## [2.2.0] - 2026-11-25
### Control Comercial OTA v2, Pruebas Gratuitas y Blindaje Anti-Rollback
- Exposición de token OTA irreversible (`license_ota_token` SHA-256), enmascaramiento estricto de `license_key` en endpoints públicos y preservación integral de metadatos de licencia y estado demo al restaurar copias de seguridad.
- Expiración exacta al cierre del día (`23:59:59 -03:00`), soporte nativo de Demos de 15 días (`license_is_trial`), backfill de sello de integridad para instalaciones heredadas y protección anti-rollback por marca de agua (`license_max_observed_at`) con re-anclaje seguro de IDs.
### Modo Exploración con Datos de Ejemplo Dinámicos por Rubro
- Nuevo `DemoEnvironmentService` con catálogos, clientes, proveedores y turnos realistas para 6 verticales comerciales, bloqueo preventivo de emisión CAE ante ARCA en modo demo y limpieza topológica transaccional con Cuádruple Candado.
### Clientes sin DNI Obligatorio, Fiscalidad ARCA y Servicios en Segundo Plano
- Soporte limpio para clientes Consumidor Final sin documento (`document_number = NULL`, `document_type = 99`), actualizaciones parciales seguras, búsqueda por teléfono en listado/papelera y normalización automática en WSFEv1 (`DocTipo = 99` / `DocNro = 0` bajo tope) con validaciones estrictas de CUIT e IIBB.
- Protección `withoutOverlapping(10)` en tareas programadas (`license:sync` y `afip:retry-contingency`) y propagación exacta de códigos de salida en `instalar_tareas_bg.bat`.

## [2.1.2] - 2026-11-21
### Estabilidad OTA, Reinicio en Caliente de Servicios y Sincronización de Entorno
- Nivelación de versión v2.1.2 con soporte completo de reinicio en caliente de workers de colas (`queue:restart`), WebSockets (`reverb:restart`) y tareas programadas tras actualizaciones OTA.
- Saneamiento idempotente de variables `.env` y preservación de configuración local para clientes que actualizan desde `v1.4.4+` y `v1.8.5+`.

## [2.1.1] - 2026-11-21
### Motor OTA Diferencial Ultrarrápido y Blindaje de Actualización
- Nuevo endpoint `/api/version-check` con huella criptográfica `vendor_hash` (`SHA-256` de `composer.lock`) para selección inteligente entre paquete completo (`Full ZIP`) y paquete liviano (`App ZIP` ~850 KB con `vendor/composer/*` sincronizado).
- Pipeline dual de distribución con manifiesto de integridad (`ota_manifest.json`) para actualización y poda segura de archivos obsoletos en segundos.

## [2.1.0] - 2026-11-20
### Facturación Electrónica ARCA / AFIP, Libro IVA y Seguridad
- Soporte integral para Factura A, B y C con cálculo de IVA discriminado y validación de padrón/receptor.
- Generador de CSR y configuración guiada de certificados digitales ARCA/AFIP con soporte de entorno Demo (Homologación) y Producción.
- Libro IVA Compras y Ventas, posición mensual de IVA, desglose fiscal de comprobantes y percepciones IIBB en compras y facturación diferida.
- Verificación criptográfica Ed25519 offline/online para validación de licencias y planes modulares.
### Lotes, Vencimientos, Producción Propia y Aislamiento por Rubro (Opción 1A)
- Control de lotes y vencimientos con despacho automático FEFO/FIFO, auto-activación desde compras, reserva/liberación en presupuestos y alertas predictivas.
- Módulo de Producción Propia y recetas multi-nivel con descuento automático de insumos y registro de mermas.
- Aislamiento modular `batch_expiration` y `production` en licencias y rutas protegidas por rubro (`retail` vs `hardware_store`), manteniendo compatibilidad 100% con v1.8.5.
### Catálogo, Reportes y Branding Caja4U
- Rebranding del motor backend a **Caja4U** manteniendo compatibilidad total con variables de entorno y sesiones existentes.
- Porciones en combos, edición rápida de precios desde carrito y filtros de ordenamiento avanzados en catálogo.
- Optimización de consultas SQL en alertas predictivas de inventario (`havingRaw`) compatible con MySQL `only_full_group_by`.
- Traducciones completas de validación al español (`lang/es/validation.php`) y seeder de datos de demostración (`DemoMarketingDataSeeder`).

## [2.0.1] - 2026-10-12
### Parches y Compatibilidad
- Nivelación de paridad de versión 2.0.1 con los clientes de frontend (Desktop y Mobile).

## [2.0.0] - 2026-10-12
### Novedades y E-commerce
- Sincronización omnicanal de catálogo e integración bidireccional con Mercado Libre, WooCommerce, Tiendanube y plataforma web propia.
- Mapeo automático de SKU y control de stock centralizado multi-plataforma.
- Idempotencia estricta en webhooks y prevención de duplicación de descuentos de inventario.
- Blindaje de rutas de e-commerce mediante middleware de validación de licencias (`feature:ecommerce_sync`).
### Importación y Exportación
- Exportación nativa a Excel con formato numérico estandarizado y codificación UTF-8 pura.
- Importador masivo de catálogo en Excel optimizado con FastExcel, tolerante a formatos de moneda y columnas variables.
### Seguridad y Base de Datos
- Blindaje relacional con restricciones de eliminación para integridad de transacciones y registros contables.
- Cifrado AES-256 de certificados digitales AFIP.
- Bitácora de sincronización para auditoría técnica de eventos omnicanal.
### Licenciamiento
- Desacople de presupuestos en modo Retail mediante asignación modular de add-ons.
- Nivelación de funciones base (Mercado Pago QR, ARCA/AFIP y App Móvil incluidas en plan estándar).

## [1.8.5] - 2026-09-06
### Agregado
- Limpieza del repositorio para la nueva distribución y paridad de payloads con las apps de frontend.

## [1.8.4] - 2026-09-02
### Novedades
- Parche de compatibilidad para actualizador OTA.

## [1.8.3] - 2026-09-02
### Novedades
- Nueva API /api/system/install-path para obtener la ruta de instalacion local del sistema.
- Agregada apertura automatica del puerto 8080 en el Firewall de Windows dentro del script instalador.
- Optimizacion del checkeo de versiones y compatibilidad con el cliente movil.
- Se agrego soporte paralelo para el dashboard movil en conexiones 4G lentas.
