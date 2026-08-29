# Changelog 📝 Sistema POS (Backend)
Todos los cambios notables del servidor de base de datos y API están documentados aquí.

## [1.7.4] - 2026-08-29
### Novedades de esta Gran Actualización (desde v1.4.4)
- **Soporte Oficial para App Móvil:** Lanzamiento del nuevo módulo de API para soporte de lectura y escritura desde la aplicación Android. 
- **Nuevo Perfil de Usuario:** Se añadió el perfil predeterminado "Admin Móvil" con clave de acceso rápido 5678 para usar exclusivamente desde la app.
- **Canal de WebSocket Integrado:** Soporte completo de sincronización de eventos en tiempo real usando Laravel Reverb, garantizando que cuando vendes desde la caja, la app móvil se actualice en un segundo.
- **Soporte de Cloudflare Tunnels (Acceso Remoto):** Nueva arquitectura de ruteo para permitir conectar el celular al sistema de tu negocio usando datos móviles o 4G desde cualquier lugar del mundo.
- **Correcciones Críticas de Base de Datos:** Los comandos de emergencia del sistema ahora están configurados silenciosamente para reparar las tablas tras una actualización masiva.
- **Estabilidad de Validaciones:** El servicio de validación de licencias ya no arrojará errores de conexión genéricos en pantalla cuando el servidor principal demore en responder (Cold Start timeout fijo a 120s).

---

## [1.4.4] - 2026-08-25
### Mejoras
- Versión inicial estable del servidor de infraestructura POS.
