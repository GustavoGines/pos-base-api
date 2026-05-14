# Changelog — Sistema POS (Backend)

Todos los cambios notables del servidor Laravel (API local On-Premise) están documentados aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.0.0/) y el proyecto adhiere a [Semantic Versioning](https://semver.org/).

---
## [1.4.4] - 2026-05-13 - Sincronización con Frontend v1.4.4

### 🚀 Mejoras
- **Versión sincronizada con el cliente:** El servidor se actualiza a v1.4.4 para acompañar la corrección crítica del cliente en la URL de sincronización de licencias. No hay cambios de código en la API ni en la base de datos.

---
## [1.4.3] - 2026-05-13 - Sincronización con Frontend v1.4.3

### 🚀 Mejoras
- **Versión sincronizada con el cliente:** El servidor se actualiza a v1.4.3 para acompañar el parche visual de la pantalla de Registro de Ventas en el cliente. No hay cambios de código en la API ni en la base de datos.

---
## [1.4.2] - 2026-05-13 - Estabilidad de Red y Optimización TCP

### 🚀 Mejoras de Estabilidad
- **Sincronización de Versión y Estabilidad:** El servidor se actualiza a v1.4.2 para emparejarse con el cliente de escritorio. Se preparó la arquitectura base para soportar la desactivación del Keep-Alive TCP proveniente de las PCs secundarias, mejorando drásticamente el rendimiento de red en entornos con conexiones intermitentes o Wi-Fi inestable. Las anulaciones de ventas ahora operan bajo un margen de tolerancia a bloqueos de tabla (lock-wait) más extenso y seguro.

---
## [1.4.1] - 2026-05-11 - Sincronización con Frontend v1.4.1

### 🚀 Mejoras
- **Versión sincronizada con el cliente:** El servidor se actualiza a v1.4.1 para acompañar el parche OTA del frontend. No hay cambios en la API ni en la base de datos.

---
## [1.4.0] - 2026-05-11 - Consumo Interno, Soporte Masivo y Blindaje de Turnos

### 🚀 Nuevas Funcionalidades (Mejoras en el Servidor)
- **Soporte Masivo Seguro:** El servidor ahora cuenta con rutas y controladores optimizados para manejar solicitudes masivas de rechazo o eliminación provenientes del nuevo Dashboard Enterprise, garantizando la integridad de la base de datos sin generar caídas.
- **Rutas de Modificación Liberadas:** Se destrabaron cachés internas del motor de rutas para habilitar la edición, eliminación y modificación de estados en presupuestos generados en tiempo real.
- **Motor de Pagos Combinados:** El servidor ahora soporta recibir cobros divididos para Cuentas Corrientes. Esto significa que un cliente puede venir y pagar su deuda usando mitad efectivo y mitad transferencia en un solo paso, de manera 100% segura y automática.
- **Gestión de Consumo Interno:** El servidor central ahora distingue inteligentemente entre ventas reales y mercadería retirada por los dueños o socios. Estos retiros ya no inflarán falsamente las estadísticas de tus productos más vendidos.
- **Reporte de Consumo Valorizado:** Se agregó la capacidad matemática al servidor para calcular exactamente cuánta plata (al costo) fue retirada del negocio para uso interno, permitiendo auditar estas salidas en la sección de Reportes.
- **Filtro de Consumo por Cliente Interno:** La API de reportes ahora es capaz de recibir identificadores individuales para segmentar dinámicamente y con altísima velocidad el consumo interno exclusivo de cada socio, empleado o dueño.
- **Validación Modular de Reportes:** Se vinculó el acceso al reporte de Consumo Interno con la presencia del módulo de Cuentas Corrientes, optimizando la lógica de negocio para licencias escalables.
- **Precisión en Arqueos de Caja:** Se actualizaron las calculadoras financieras del Cierre de Caja para contabilizar por separado las "Ventas Fiadas" (Cuenta Corriente). ¡Tus números de efectivo y tarjetas ahora serán mucho más exactos!

### 🐛 Correcciones y Estabilidad
- **Blindaje Total de Cajas (Multi-Terminal):** Reforzamos la seguridad del sistema en redes con múltiples cajas. Se solucionó un defecto técnico donde una caja secundaria que se quedaba sin turno activo intentaba "pedir prestado" el turno de la caja principal para guardar la venta. Ahora, el sistema bloquea esto y obliga a cada venta a registrarse estrictamente en la caja física de donde salió.
- **Blindaje Transaccional de Órdenes en Espera:** Se implementó un bloqueo de base de datos pesimista (`lockForUpdate`) dentro de una transacción segura para las rutas de cobro y anulación de ventas en espera. Esto previene un error crítico de "doble cobro" (*Race Condition*) en entornos con múltiples terminales si dos cajeros intentaban procesar la misma orden al mismo tiempo exacto.
- **Arreglo Crítico: Cálculos de Arqueo y Cuentas Corrientes:** Se corrigió un problema financiero severo donde los Abonos pagados en efectivo y los pagos de tickets en espera no se sumaban al saldo esperado de la gaveta de la caja, generando falsos "sobrantes". Ahora, el motor de arqueos computa a la perfección todas las transacciones físicas vinculadas al turno.

---
## [1.3.9] - 2026-05-08 - Actualización de Precios Dinámicos y Estabilidad de Cajas

### 🐛 Mejoras de Estabilidad
- **Gestión de Presupuestos:** Se solucionó un inconveniente que bloqueaba la facturación de presupuestos recuperados. El sistema ahora procesa correctamente el cambio de estado, asegurando una transición fluida desde la cotización hasta la venta final sin errores del servidor.



## [1.3.8] - 2026-05-07 - OTA Distribution Fix

### 🐛 Fixes
- **Empaquetado OTA:** El workflow de GitHub Actions ahora excluye correctamente `updater_log.txt`, `ota_result.txt` y `ota_pending.json` del ZIP de distribución. Anteriormente estos archivos de diagnóstico se sobreescribían al instalar el update, corrompiendo el log de la sesión activa y el resultado que el frontend lee para confirmar el éxito.
- **`.gitignore`:** Agregados explícitamente los archivos de diagnóstico OTA para que nunca sean trackeados por git en ningún entorno.

## [1.3.7] - 2026-05-06 - Auto-Retry Connection

### 🚀 Mejoras
- **Sincronización con Frontend:** Incremento a v1.3.7 para coincidir con el parche de calidad de vida del Frontend (Reintento de conexión automático). No hay cambios de código en la API local.

## [1.3.6] - 2026-05-06 - Dynamic Network IP Sync

### 🚀 Mejoras
- **Sincronización con Frontend:** Incremento a v1.3.6 para coincidir con el parche crítico del Frontend donde las PCs secundarias ahora consultan la IP dinámica de red en lugar de `127.0.0.1` para la verificación de versiones locales. No hay cambios de código en la API local.

## [1.3.5] - 2026-05-06 - Strict Cache Busting Fix

### 🐛 Fixes
- **HTTP Cache Control:** Implementadas las cabeceras HTTP `Cache-Control: no-cache, no-store` en el endpoint `/version-check` para garantizar que absolutamente ningún proxy, router de red o caché local impida que las PCs secundarias reciban la última versión detectada, eliminando el falso bucle de actualización "Actualizar servidor".

## [1.3.4] - 2026-05-06 - OTA Path Fix (Sincronización con Frontend)

### 🚀 Mejoras
- **Sincronización de Versiones:** Incremento de versión a v1.3.4 para mantener paridad con el frontend, el cual recibió mejoras vitales en la descarga OTA de archivos ZIP hacia la carpeta `%TEMP%`. No hay cambios de código en la API.

## [1.3.3] — 2026-05-06 — Cache & Sync Fix

### 🐛 Fixes
- **Caché del Actualizador OTA:** Se implementó `clearstatcache()` en el endpoint `/version-check` para garantizar que la verificación de versión evada las memorias caché (como OPcache), resolviendo el falso bucle de actualización en PCs secundarias.

---

## [1.3.2] — 2026-05-05 — Validaciones de Catálogo

### 🐛 Fixes
- **Consistencia de Datos:** Se agregó la regla de validación de unicidad (`unique`) a los nombres de las marcas y categorías en sus respectivos controladores para prevenir duplicados en la base de datos de manera estricta.

---

## [1.3.1] — 2026-05-04 — OTA Loop Fix

### 🐛 Fixes
- **[CRÍTICO] Bucle Infinito de Actualizaciones OTA:** Se agregó la ruta pública `/version-check` requerida por el frontend para validar la versión instalada localmente del backend. Su ausencia causaba que el frontend asumiera la versión `0.0.0` y entrara en un bucle infinito pidiendo actualizaciones ya instaladas.
- **Validación de Catálogo:** Se agregó regla de unicidad para impedir la creación o edición de productos con nombres duplicados.

---

## [1.3.0] — 2026-04-27 — Ferretería & Retail Edition

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
- **Seeders de Instalación Limpia:** Los seeders por defecto (`php artisan migrate --seed`) ahora generan una instalación neutral sin datos personales, sin licencias hardcodeadas y sin catálogo de demo. Listo para producción desde el primer comando.

### 🐛 Fixes
- Corregido error 500 al registrar pagos de cuenta corriente bajo ciertas condiciones de facturación.
- Corregido error donde los reportes de ventas por marca mostraban datos vacíos si la categoría no existía.
- Mejorada la lógica de anulación de remitos para preservar el historial de auditoría mediante borrado lógico.
- Eliminado el directorio `updater/` zombie del repositorio del backend. El único actualizador oficial es el que reside en el frontend. Fuente única de verdad.
- **[CRÍTICO] Failsafe de Módulos Premium:** Corregido bug donde el sistema bloqueaba módulos avanzados (`logística`, `cheques`, `listas de precio`) cuando el servidor de licencias respondía sin enviar el campo `business_type`. Ahora `LicenseSyncService` detecta la cuenta como Hardware Store si el plan incluye el módulo `quotes`, garantizando el desbloqueo total independientemente de la completitud del payload remoto.
- **[CRÍTICO] Bloqueo por Período de Gracia:** Corregido escenario donde restaurar una base de datos con `last_license_check` antiguo causaba que el sistema apareciera como "BLOQUEADO" desde el primer arranque, incluso con licencia válida. La solución es no incluir esa clave en los dumps de instalación.

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

[1.4.0]: https://github.com/GustavoGines/pos-base-api/compare/v1.3.9...v1.4.0
[1.3.9]: https://github.com/GustavoGines/pos-base-api/compare/v1.3.8...v1.3.9
[1.3.8]: https://github.com/GustavoGines/pos-base-api/compare/v1.3.0...v1.3.8
[1.3.0]: https://github.com/GustavoGines/pos-base-api/compare/v1.2.4...v1.3.0
[1.2.4]: https://github.com/GustavoGines/pos-base-api/compare/v1.1.0...v1.2.4
[1.1.0]: https://github.com/GustavoGines/pos-base-api/releases/tag/v1.1.0
