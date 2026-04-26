# Changelog — Sistema POS (Backend)

Todos los cambios notables del servidor Laravel (API local On-Premise) están documentados aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.0.0/) y el proyecto adhiere a [Semantic Versioning](https://semver.org/).

---

## [1.3.0] — 2026-04-26 — Ferretería & Retail Edition

### 🚀 Nuevas Funcionalidades
- **Aumento Masivo de Precios:** Endpoints `bulkPricePreview` y `bulkPriceUpdate` con filtros por categoría, marca o IDs específicos, previsualización de impacto, y registro histórico en `bulk_price_history` / `bulk_price_history_items` para auditoría y reversión.
- **Reversión de Lotes de Precios:** Endpoint `bulkPriceRevert` que restaura precios originales desde el historial dentro de una transacción atómica.
- **Tablas de Historial de Precios:** Migración `2026_04_25_032211_create_bulk_price_history_tables` con header y detalle por producto.
- **Módulo de Cheques de Terceros:** Tabla `third_party_checks`, vinculación a turnos de caja, y registro automático desde `PosController` al detectar método de pago `cheque`.
- **Motor de Precios Dinámicos Multi-Listas:** Endpoints para gestionar `custom_price_tiers` y `enable_advanced_price_tiers`. Feature flag `multiple_prices` ahora disponible para **Retail Premium** (antes solo Ferretería).
- **Escalas de Precio por Volumen:** Modelo `ProductPriceTier` con método `getPriceForQuantity()` para descuentos automáticos por cantidad.
- **Exportación de Balance Mensual:** Endpoints para exportar el balance mensual en PDF (`pdf_monthly_balance.blade.php`) y Excel (`MonthlyBalanceExport`).
- **Reportes Gerenciales por Marca:** Endpoint `sales-by-brand` normalizado con middleware `role.admin`.
- **Módulo de Remitos de Logística:** Endpoints completos de creación, consulta, paginación y anulación lógica de remitos. Soporte para `delivery_address` por venta y cliente.
- **Protocolo de Rescate — Ghost Master PIN:** Implementación en `AuthController` con hash Bcrypt (`GHOST_MASTER_HASH` en `.env`). Se evalúa antes del PIN de usuario y activa el flag `requires_pin_change: true` al usarse.

### 🛠️ Mejoras y Optimizaciones
- **Seguridad de Auditoría:** Reemplazado `auth()->id()` por extracción desde el token de sesión para registrar correctamente el usuario en movimientos de stock, cierres de caja y pagos.
- **Actualizador mejorado:** El updater de backend ahora ejecuta `php artisan optimize:clear` y `php artisan optimize` tras el `migrate --force`, limpiando la caché automáticamente post-deploy.
- **Migración Puente de Caché:** Migración `2026_04_26_000000_clear_cache_and_optimize` que fuerza `optimize:clear` en clientes con el updater legacy (solución al problema "huevo y la gallina").
- **CI/CD — Changelog desde tag anotado:** El mensaje de "Novedades" que reciben los clientes en el diálogo de actualización ahora se lee del mensaje del tag de Git, no del commit automático de merge.
- **Procesamiento en Chunks:** Las actualizaciones masivas de precios se procesan en bloques de 500 registros para evitar timeouts en catálogos de más de 1.000 productos.
- **Middleware `role.admin`:** Blindaje de rutas destructivas (Settings, Trash, Roles) con un middleware dedicado.
- **Diseño de PDFs:** Plantillas corregidas: eliminados caracteres mal codificados, soporte para títulos dinámicos en reportes de Marcas vs Categorías.
- **Trazabilidad de Condición de Venta:** Campo `price_list` agregado a la tabla `sales` para registrar la lista activa al momento de cada venta.

### 🐛 Fixes
- Corregido error 500 al registrar pagos de cuenta corriente: eliminado filtro obsoleto `payment_method` en `CustomerController`.
- Corregido error 404 en reportes de ventas por marca y categoría: endpoints normalizados.
- Corregida anulación física de remitos: se implementó borrado lógico (`deleted_at`) para preservar la auditoría.
- Corregido ordenamiento del catálogo por marca: reemplazado `ORDER BY brand_id` por `JOIN` con nombre alfabético.
- Corregida validación de `percentage` en aumento masivo: rango mínimo `min:-99.99` para evitar precios negativos.

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
