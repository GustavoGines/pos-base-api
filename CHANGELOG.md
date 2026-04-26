# Changelog — Sistema POS (Backend)

Todos los cambios notables del servidor Laravel (API local On-Premise) están documentados aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.0.0/) y el proyecto adhiere a [Semantic Versioning](https://semver.org/).

---

## [1.3.0] — 2026-04-26 — Ferretería & Retail Edition

### 🚀 Nuevas Funcionalidades
- **Aumento Masivo de Precios:** Actualizá el precio de venta de cientos de productos con un solo clic. Filtrá por categoría, marca o una selección manual. Incluye previsualización del impacto antes de confirmar y reversión completa de lotes desde el historial.
- **Módulo de Remitos de Logística:** Generá remitos de entrega vinculados a cada venta, con dirección de entrega personalizada por cliente. Los remitos se imprimen en A4 con marca de agua, firma y pie de comprobante.
- **Cartera de Cheques de Terceros:** Registrá pagos con cheque de terceros directamente en la caja. El módulo incluye un dashboard con semáforo de vencimientos para gestionar el cobro de la cartera.
- **Motor de Precios Dinámicos (Listas de Precio):** Creá hasta 3 listas de precio diferenciadas (Mayorista, Especial, Tarjeta). El POS cambia de lista de precio en tiempo real desde el carrito de ventas. Ahora disponible para Retail Premium.
- **Exportación de Balance Mensual:** Descargá el balance mensual completo en PDF y Excel con un solo clic desde los reportes gerenciales.
- **Dashboard Gerencial por Marcas:** Analizá las ventas desglosadas por marca de producto.
- **Dirección de Entrega en Checkout:** Input para registrar la dirección de entrega directamente en la pantalla de cobro.
- **Gestión de Marcas en Catálogo:** CRUD completo de marcas de producto para organizar el catálogo.

### 🛠️ Mejoras y Optimizaciones
- **Modal de Novedades Responsivo:** El diálogo de actualización ahora es más amplio en escritorio (40% de la pantalla) para leer mejor todas las novedades.
- **PIN de Rescate Administrativo:** Protocolo de emergencia para recuperar acceso de administrador sin necesidad de modificar la base de datos manualmente.
- **Auditoría de Precios en Ventas:** Cada venta registra la lista de precio activa para trazabilidad contable completa.
- **Actualizador Automático Mejorado:** El actualizador limpia y regenera la caché del servidor tras cada actualización, eliminando los falsos bugs post-actualización.
- **Rendimiento en Catálogos Grandes:** Las actualizaciones masivas de precios se procesan en bloques seguros, sin riesgo de timeout en catálogos de más de 1.000 productos.
- **Icono de Impresora Global:** Acceso rápido a ajustes de hardware desde cualquier pantalla de la app.

### 🐛 Fixes
- Corregido error donde los cierres de caja y movimientos de stock se registraban a nombre del usuario incorrecto en la auditoría.
- Corregido error 500 al registrar pagos de cuenta corriente con ciertos métodos de pago.
- Corregido error 404 en reportes de ventas por marca y categoría.
- Corregido recorte de marca de agua en remitos impresos en papel tamaño Carta.
- Corregida pérdida de items al actualizar el listado de remitos en tiempo real.
- Corregido error al cargar presupuestos con listas de precio nuevas.

---

## [1.2.4] — 2026-04-14 — Updater Resilience

### 🛠️ Mejoras
- Refactorización del updater Dart del backend: espera genérica de 3 segundos al iniciar, captura de stdout/stderr de la migración, y limpieza del ZIP temporal post-deploy.
- Soporte para argumento `--component=backend` en el updater para distinguir el flujo de actualización del backend vs frontend.

---

## [1.1.0] — 2026-03-xx — Infraestructura OTA y Licencias

### 🚀 Nuevas Funcionalidades
- **API de Licencias (DRM):** Integración con el servidor de licencias central (Render). Sincronización automática diaria mediante `LicenseSyncService`, período de gracia de 72 horas, y soporte para planes SaaS y Lifetime.
- **Feature Flags Server-Driven:** El diccionario `license_features_dict` en `BusinessSettings` controla la habilitación de módulos de forma centralizada.
- **Guard SaaS para Multi-Listas:** `SettingController` valida que la licencia incluya `multiple_prices` antes de habilitar el feature toggle.
- **Gestión de Marcas (Brands):** CRUD completo de marcas de producto para organización del catálogo.
- **Caja Rápida:** Soporte de endpoints para el modo Fast POS.

---

[1.3.0]: https://github.com/GustavoGines/pos-base-api/compare/v1.2.4...v1.3.0
[1.2.4]: https://github.com/GustavoGines/pos-base-api/compare/v1.1.0...v1.2.4
[1.1.0]: https://github.com/GustavoGines/pos-base-api/releases/tag/v1.1.0
