# Changelog - Sistema POS (Backend API)
Todos los cambios notables del servidor base están documentados aquí.


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
