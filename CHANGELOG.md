# Changelog — Sistema POS (Backend)
Todos los cambios del servidor API están documentados aquí.

## [1.6.6] - 2026-08-26
### Novedades de esta Gran Actualización (desde v1.4.4)
- **Soporte Oficial para la App Móvil:** El sistema ahora cuenta con todo el motor interno preparado para comunicarse en tiempo real con nuestra nueva Aplicación Móvil.
- **Actualizaciones 100% Automáticas:** Rediseñamos por completo el sistema de actualización. Ahora, cuando haya mejoras tanto para la caja como para el servidor, el proceso será continuo, inteligente y completamente automático, sin requerir clics innecesarios.
- **Mayor Estabilidad y Resiliencia:** Mejoramos la conexión de red y le dimos al sistema la capacidad de manejar grandes volúmenes de datos durante las actualizaciones sin interrumpir tu trabajo.
- **Limpieza y Pulido Visual:** Eliminamos textos innecesarios y pulimos las pantallas para que tu experiencia de uso sea más limpia y profesional.
- **Validación Estricta de Actualización:** El actualizador ahora garantiza de forma estricta que la memoria del servidor se purgue completamente para eliminar los "falsos avisos" de actualización pendiente.
## [1.6.4] - 2026-08-26
### Mejoras
- Sincronización de versión con el cliente de escritorio (Mejora UX sin clics intermedios).

## [1.6.3] - 2026-08-26
### Mejoras
- Sincronización de versión con el cliente de escritorio (mejoras de UX en el actualizador).

## [1.6.2] - 2026-08-26
### Correcciones
- Compatibilidad de versión con el frontend.

## [1.6.0] - 2026-08-26
### Mejoras
- Actualización mayor para dar soporte completo a la App Móvil.


---
## [1.4.5] - 2026-05-14 - QA Testing Suite y Soporte Universal de Base de Datos

### ðŸš€ Nuevas Funcionalidades
- **Suite de Testing Automatizada:** ImplementaciÃ³n completa de un entorno de pruebas con 100% de cobertura en las rutas crÃ­ticas del sistema (Ventas, Turnos, Cuentas Corrientes, Presupuestos y AutenticaciÃ³n). El entorno utiliza SQLite *in-memory* para permitir despliegues y pruebas en integraciÃ³n continua (CI/CD) sin afectar los datos en producciÃ³n.
- **IntegraciÃ³n Continua (CI/CD):** ConfiguraciÃ³n de GitHub Actions para ejecutar automÃ¡ticamente la suite de tests (con PHP 8.3) en cada `push` o `pull request`, asegurando la estabilidad del cÃ³digo en la nube antes de cualquier despliegue a producciÃ³n.

### ðŸ› Correcciones y Ajustes TÃ©cnicos
- **Compatibilidad de Migraciones (MySQL / SQLite):** Se refactorizaron las migraciones del sistema para soportar de manera universal tanto MySQL como SQLite. EspecÃ­ficamente, se cambiÃ³ el casteo de variables tipo `enum` a `string` y se blindÃ³ la ejecuciÃ³n de comandos `Artisan::call('optimize')` y mÃ©todos exclusivos como `MODIFY COLUMN` para evitar crasheos durante los tests.
- **Validaciones Rigurosas:** Ajustes de validaciÃ³n de entradas HTTP y tipos de datos para robustecer la API ante escenarios de testing exhaustivos.
- **Linter y CÃ³digo Limpio:** ResoluciÃ³n de advertencias estÃ¡ticas (PHP0416 Intelephense) declarando propiedades protegidas explÃ­citas en las suites de prueba para mantener un estÃ¡ndar de calidad impecable.
- **Respuestas HTTP EstÃ¡ndar:** EstandarizaciÃ³n de respuestas `200 OK` vs `204 No Content` para asegurar la compatibilidad estricta con clientes JSON en endpoints de eliminaciÃ³n definitiva (Trash Management).

---
## [1.4.4] - 2026-05-13 - SincronizaciÃ³n con Frontend v1.4.4

### ðŸš€ Mejoras
- **VersiÃ³n sincronizada con el cliente:** El servidor se actualiza a v1.4.4 para acompaÃ±ar la correcciÃ³n crÃ­tica del cliente en la URL de sincronizaciÃ³n de licencias. No hay cambios de cÃ³digo en la API ni en la base de datos.

---
## [1.4.3] - 2026-05-13 - SincronizaciÃ³n con Frontend v1.4.3

### ðŸš€ Mejoras
- **VersiÃ³n sincronizada con el cliente:** El servidor se actualiza a v1.4.3 para acompaÃ±ar el parche visual de la pantalla de Registro de Ventas en el cliente. No hay cambios de cÃ³digo en la API ni en la base de datos.

---
## [1.4.2] - 2026-05-13 - Estabilidad de Red y OptimizaciÃ³n TCP

### ðŸš€ Mejoras de Estabilidad
- **SincronizaciÃ³n de VersiÃ³n y Estabilidad:** El servidor se actualiza a v1.4.2 para emparejarse con el cliente de escritorio. Se preparÃ³ la arquitectura base para soportar la desactivaciÃ³n del Keep-Alive TCP proveniente de las PCs secundarias, mejorando drÃ¡sticamente el rendimiento de red en entornos con conexiones intermitentes o Wi-Fi inestable. Las anulaciones de ventas ahora operan bajo un margen de tolerancia a bloqueos de tabla (lock-wait) mÃ¡s extenso y seguro.

---
## [1.4.1] - 2026-05-11 - SincronizaciÃ³n con Frontend v1.4.1

### ðŸš€ Mejoras
- **VersiÃ³n sincronizada con el cliente:** El servidor se actualiza a v1.4.1 para acompaÃ±ar el parche OTA del frontend. No hay cambios en la API ni en la base de datos.

---
## [1.4.0] - 2026-05-11 - Consumo Interno, Soporte Masivo y Blindaje de Turnos

### ðŸš€ Nuevas Funcionalidades (Mejoras en el Servidor)
- **Soporte Masivo Seguro:** El servidor ahora cuenta con rutas y controladores optimizados para manejar solicitudes masivas de rechazo o eliminaciÃ³n provenientes del nuevo Dashboard Enterprise, garantizando la integridad de la base de datos sin generar caÃ­das.
- **Rutas de ModificaciÃ³n Liberadas:** Se destrabaron cachÃ©s internas del motor de rutas para habilitar la ediciÃ³n, eliminaciÃ³n y modificaciÃ³n de estados en presupuestos generados en tiempo real.
- **Motor de Pagos Combinados:** El servidor ahora soporta recibir cobros divididos para Cuentas Corrientes. Esto significa que un cliente puede venir y pagar su deuda usando mitad efectivo y mitad transferencia en un solo paso, de manera 100% segura y automÃ¡tica.
- **GestiÃ³n de Consumo Interno:** El servidor central ahora distingue inteligentemente entre ventas reales y mercaderÃ­a retirada por los dueÃ±os o socios. Estos retiros ya no inflarÃ¡n falsamente las estadÃ­sticas de tus productos mÃ¡s vendidos.
- **Reporte de Consumo Valorizado:** Se agregÃ³ la capacidad matemÃ¡tica al servidor para calcular exactamente cuÃ¡nta plata (al costo) fue retirada del negocio para uso interno, permitiendo auditar estas salidas en la secciÃ³n de Reportes.
- **Filtro de Consumo por Cliente Interno:** La API de reportes ahora es capaz de recibir identificadores individuales para segmentar dinÃ¡micamente y con altÃ­sima velocidad el consumo interno exclusivo de cada socio, empleado o dueÃ±o.
- **ValidaciÃ³n Modular de Reportes:** Se vinculÃ³ el acceso al reporte de Consumo Interno con la presencia del mÃ³dulo de Cuentas Corrientes, optimizando la lÃ³gica de negocio para licencias escalables.
- **PrecisiÃ³n en Arqueos de Caja:** Se actualizaron las calculadoras financieras del Cierre de Caja para contabilizar por separado las "Ventas Fiadas" (Cuenta Corriente). Â¡Tus nÃºmeros de efectivo y tarjetas ahora serÃ¡n mucho mÃ¡s exactos!

### ðŸ› Correcciones y Estabilidad
- **Blindaje Total de Cajas (Multi-Terminal):** Reforzamos la seguridad del sistema en redes con mÃºltiples cajas. Se solucionÃ³ un defecto tÃ©cnico donde una caja secundaria que se quedaba sin turno activo intentaba "pedir prestado" el turno de la caja principal para guardar la venta. Ahora, el sistema bloquea esto y obliga a cada venta a registrarse estrictamente en la caja fÃ­sica de donde saliÃ³.
- **Blindaje Transaccional de Ã“rdenes en Espera:** Se implementÃ³ un bloqueo de base de datos pesimista (`lockForUpdate`) dentro de una transacciÃ³n segura para las rutas de cobro y anulaciÃ³n de ventas en espera. Esto previene un error crÃ­tico de "doble cobro" (*Race Condition*) en entornos con mÃºltiples terminales si dos cajeros intentaban procesar la misma orden al mismo tiempo exacto.
- **Arreglo CrÃ­tico: CÃ¡lculos de Arqueo y Cuentas Corrientes:** Se corrigiÃ³ un problema financiero severo donde los Abonos pagados en efectivo y los pagos de tickets en espera no se sumaban al saldo esperado de la gaveta de la caja, generando falsos "sobrantes". Ahora, el motor de arqueos computa a la perfecciÃ³n todas las transacciones fÃ­sicas vinculadas al turno.

---
## [1.3.9] - 2026-05-08 - ActualizaciÃ³n de Precios DinÃ¡micos y Estabilidad de Cajas

### ðŸ› Mejoras de Estabilidad
- **GestiÃ³n de Presupuestos:** Se solucionÃ³ un inconveniente que bloqueaba la facturaciÃ³n de presupuestos recuperados. El sistema ahora procesa correctamente el cambio de estado, asegurando una transiciÃ³n fluida desde la cotizaciÃ³n hasta la venta final sin errores del servidor.



## [1.3.8] - 2026-05-07 - OTA Distribution Fix

### ðŸ› Fixes
- **Empaquetado OTA:** El workflow de GitHub Actions ahora excluye correctamente `updater_log.txt`, `ota_result.txt` y `ota_pending.json` del ZIP de distribuciÃ³n. Anteriormente estos archivos de diagnÃ³stico se sobreescribÃ­an al instalar el update, corrompiendo el log de la sesiÃ³n activa y el resultado que el frontend lee para confirmar el Ã©xito.
- **`.gitignore`:** Agregados explÃ­citamente los archivos de diagnÃ³stico OTA para que nunca sean trackeados por git en ningÃºn entorno.

## [1.3.7] - 2026-05-06 - Auto-Retry Connection

### ðŸš€ Mejoras
- **SincronizaciÃ³n con Frontend:** Incremento a v1.3.7 para coincidir con el parche de calidad de vida del Frontend (Reintento de conexiÃ³n automÃ¡tico). No hay cambios de cÃ³digo en la API local.

## [1.3.6] - 2026-05-06 - Dynamic Network IP Sync

### ðŸš€ Mejoras
- **SincronizaciÃ³n con Frontend:** Incremento a v1.3.6 para coincidir con el parche crÃ­tico del Frontend donde las PCs secundarias ahora consultan la IP dinÃ¡mica de red en lugar de `127.0.0.1` para la verificaciÃ³n de versiones locales. No hay cambios de cÃ³digo en la API local.

## [1.3.5] - 2026-05-06 - Strict Cache Busting Fix

### ðŸ› Fixes
- **HTTP Cache Control:** Implementadas las cabeceras HTTP `Cache-Control: no-cache, no-store` en el endpoint `/version-check` para garantizar que absolutamente ningÃºn proxy, router de red o cachÃ© local impida que las PCs secundarias reciban la Ãºltima versiÃ³n detectada, eliminando el falso bucle de actualizaciÃ³n "Actualizar servidor".

## [1.3.4] - 2026-05-06 - OTA Path Fix (SincronizaciÃ³n con Frontend)

### ðŸš€ Mejoras
- **SincronizaciÃ³n de Versiones:** Incremento de versiÃ³n a v1.3.4 para mantener paridad con el frontend, el cual recibiÃ³ mejoras vitales en la descarga OTA de archivos ZIP hacia la carpeta `%TEMP%`. No hay cambios de cÃ³digo en la API.

## [1.3.3] â€” 2026-05-06 â€” Cache & Sync Fix

### ðŸ› Fixes
- **CachÃ© del Actualizador OTA:** Se implementÃ³ `clearstatcache()` en el endpoint `/version-check` para garantizar que la verificaciÃ³n de versiÃ³n evada las memorias cachÃ© (como OPcache), resolviendo el falso bucle de actualizaciÃ³n en PCs secundarias.

---

## [1.3.2] â€” 2026-05-05 â€” Validaciones de CatÃ¡logo

### ðŸ› Fixes
- **Consistencia de Datos:** Se agregÃ³ la regla de validaciÃ³n de unicidad (`unique`) a los nombres de las marcas y categorÃ­as en sus respectivos controladores para prevenir duplicados en la base de datos de manera estricta.

---

## [1.3.1] â€” 2026-05-04 â€” OTA Loop Fix

### ðŸ› Fixes
- **[CRÃTICO] Bucle Infinito de Actualizaciones OTA:** Se agregÃ³ la ruta pÃºblica `/version-check` requerida por el frontend para validar la versiÃ³n instalada localmente del backend. Su ausencia causaba que el frontend asumiera la versiÃ³n `0.0.0` y entrara en un bucle infinito pidiendo actualizaciones ya instaladas.
- **ValidaciÃ³n de CatÃ¡logo:** Se agregÃ³ regla de unicidad para impedir la creaciÃ³n o ediciÃ³n de productos con nombres duplicados.

---

## [1.3.0] â€” 2026-04-27 â€” FerreterÃ­a & Retail Edition

### ðŸš€ Nuevas Funcionalidades
- **Motor de Aumentos Masivos:** Nuevo motor de cÃ¡lculos para actualizar precios de miles de productos en segundos, con soporte de almacenamiento histÃ³rico y reversiÃ³n instantÃ¡nea para corregir errores.
- **LogÃ­stica y Remitos:** Estructura de base de datos habilitada para el nuevo mÃ³dulo de remitos, con soporte de almacenamiento seguro para direcciones de entrega personalizadas por cliente.
- **Cheques y TesorerÃ­a:** Nueva arquitectura de base de datos para la cartera de cheques de terceros vinculada directamente a los turnos de caja para una auditorÃ­a contable estricta.
- **Listas de Precio (Premium):** El servidor ahora permite habilitar mÃºltiples niveles de precio (Mayorista, Tarjeta, Especial) para aplicar descuentos o recargos de forma global.

### ðŸ› ï¸ Mejoras y Optimizaciones
- **Protocolo de Auto-ReparaciÃ³n OTA:** Nuevas funciones de rescate de cachÃ© y base de datos. DestrucciÃ³n automÃ¡tica de cachÃ© en el arranque y endpoint de migraciÃ³n forzada para asegurar que el sistema se recupere ante fallos del updater.
- **PIN de Rescate (Ghost Master):** Nuevo protocolo de seguridad cifrado que permite al administrador principal recuperar el acceso al sistema en caso de pÃ©rdida de credenciales.
- **Rendimiento de CachÃ©:** OptimizaciÃ³n en la limpieza de memoria del servidor tras cada actualizaciÃ³n automÃ¡tica, garantizando que el sistema inicie mÃ¡s rÃ¡pido y sin errores fantasma.
- **Trazabilidad Estricta:** Mejora profunda en el registro de auditorÃ­a; cada movimiento de stock, cierre de caja o cobro ahora queda sellado criptogrÃ¡ficamente con el usuario exacto y la lista de precios utilizada.
- **Seeders de InstalaciÃ³n Limpia:** Los seeders por defecto (`php artisan migrate --seed`) ahora generan una instalaciÃ³n neutral sin datos personales, sin licencias hardcodeadas y sin catÃ¡logo de demo. Listo para producciÃ³n desde el primer comando.

### ðŸ› Fixes
- Corregido error 500 al registrar pagos de cuenta corriente bajo ciertas condiciones de facturaciÃ³n.
- Corregido error donde los reportes de ventas por marca mostraban datos vacÃ­os si la categorÃ­a no existÃ­a.
- Mejorada la lÃ³gica de anulaciÃ³n de remitos para preservar el historial de auditorÃ­a mediante borrado lÃ³gico.
- Eliminado el directorio `updater/` zombie del repositorio del backend. El Ãºnico actualizador oficial es el que reside en el frontend. Fuente Ãºnica de verdad.
- **[CRÃTICO] Failsafe de MÃ³dulos Premium:** Corregido bug donde el sistema bloqueaba mÃ³dulos avanzados (`logÃ­stica`, `cheques`, `listas de precio`) cuando el servidor de licencias respondÃ­a sin enviar el campo `business_type`. Ahora `LicenseSyncService` detecta la cuenta como Hardware Store si el plan incluye el mÃ³dulo `quotes`, garantizando el desbloqueo total independientemente de la completitud del payload remoto.
- **[CRÃTICO] Bloqueo por PerÃ­odo de Gracia:** Corregido escenario donde restaurar una base de datos con `last_license_check` antiguo causaba que el sistema apareciera como "BLOQUEADO" desde el primer arranque, incluso con licencia vÃ¡lida. La soluciÃ³n es no incluir esa clave en los dumps de instalaciÃ³n.

---

## [1.2.4] â€” 2026-04-14 â€” Updater Resilience

### ðŸ› ï¸ Mejoras
- RefactorizaciÃ³n del updater Dart del backend: espera genÃ©rica de 3 segundos al iniciar, captura de stdout/stderr de la migraciÃ³n, y limpieza del ZIP temporal post-deploy.
- Soporte para argumento `--component=backend` en el updater para distinguir el flujo de actualizaciÃ³n del backend vs frontend.

---

## [1.1.0] â€” 2026-03-xx â€” Infraestructura OTA y Licencias

### ðŸš€ Nuevas Funcionalidades
- **API de Licencias (DRM):** IntegraciÃ³n con el servidor de licencias central (Render). SincronizaciÃ³n automÃ¡tica diaria mediante `LicenseSyncService`, perÃ­odo de gracia de 72 horas, y soporte para planes SaaS y Lifetime.
- **Feature Flags Server-Driven:** El diccionario `license_features_dict` en `BusinessSettings` controla la habilitaciÃ³n de mÃ³dulos de forma centralizada.
- **Guard SaaS para Multi-Listas:** `SettingController` valida que la licencia incluya `multiple_prices` antes de habilitar el feature toggle.
- **GestiÃ³n de Marcas (Brands):** CRUD completo de marcas de producto para organizaciÃ³n del catÃ¡logo.
- **Caja RÃ¡pida:** Soporte de endpoints para el modo Fast POS.

---

[1.4.0]: https://github.com/GustavoGines/pos-base-api/compare/v1.3.9...v1.4.0
[1.3.9]: https://github.com/GustavoGines/pos-base-api/compare/v1.3.8...v1.3.9
[1.3.8]: https://github.com/GustavoGines/pos-base-api/compare/v1.3.0...v1.3.8
[1.3.0]: https://github.com/GustavoGines/pos-base-api/compare/v1.2.4...v1.3.0
[1.2.4]: https://github.com/GustavoGines/pos-base-api/compare/v1.1.0...v1.2.4
[1.1.0]: https://github.com/GustavoGines/pos-base-api/releases/tag/v1.1.0
