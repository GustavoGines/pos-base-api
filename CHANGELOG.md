# Changelog — Sistema POS (Backend)

Todos los cambios notables del servidor Laravel (API local On-Premise) están documentados aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.0.0/) y el proyecto adhiere a [Semantic Versioning](https://semver.org/).

---

## [1.3.0] — 2026-04-26 — Ferretería & Retail Edition

### 🚀 Nuevas Funcionalidades
- **Motor de Aumentos Masivos:** Nuevo motor de cálculos para actualizar precios de miles de productos en segundos, con soporte de almacenamiento histórico y reversión instantánea para corregir errores.
- **Logística y Remitos:** Estructura de base de datos habilitada para el nuevo módulo de remitos, con soporte de almacenamiento seguro para direcciones de entrega personalizadas por cliente.
- **Cheques y Tesorería:** Nueva arquitectura de base de datos para la cartera de cheques de terceros vinculada directamente a los turnos de caja para una auditoría contable estricta.
- **Listas de Precio (Premium):** El servidor ahora permite habilitar múltiples niveles de precio (Mayorista, Tarjeta, Especial) para aplicar descuentos o recargos de forma global.

### 🛠️ Mejoras y Optimizaciones
- **Protocolo de Auto-Reparación OTA:** Nuevas funciones de rescate de caché y base de datos. Destrucción automática de caché en el arranque y endpoint de migración forzada para asegurar que el sistema se recupere ante fallos del updater.
- **PIN de Rescate (Ghost Master):** Nuevo protocolo de seguridad cifrado que permite al administrador principal recuperar el acceso al sistema en caso de pérdida de credenciales.
- **Rendimiento de Caché:** Optimización en la limpieza de memoria del servidor tras cada actualización automática, garantizando que el sistema inicie más rápido y sin errores fantasma.
- **Trazabilidad Estricta:** Mejora profunda en el registro de auditoría; cada movimiento de stock, cierre de caja o cobro ahora queda sellado criptográficamente con el usuario exacto y la lista de precios utilizada.

### 🐛 Fixes
- Corregido error 500 al registrar pagos de cuenta corriente bajo ciertas condiciones de facturación.
- Corregido error donde los reportes de ventas por marca mostraban datos vacíos si la categoría no existía.
- Mejorada la lógica de anulación de remitos para preservar el historial de auditoría mediante borrado lógico.

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
