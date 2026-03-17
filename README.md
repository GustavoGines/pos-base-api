# 🚀 Sistema POS - Backend API (Laravel)

Este repositorio contiene el **Backend API** del Sistema POS, desarrollado con **Laravel (PHP)** y una arquitectura de base de datos relacional para soportar operaciones de Punto de Venta distribuidas en red local (Cliente-Servidor).

## ✨ Características Principales (Arquitectura Core)

### 🛡️ Transacciones Atómicas (ACID)
La emisión de una venta normal, una anulación de ticket o el cobro de una Preventa (Order Recall) operan íntegramente bajo `DB::transaction`. Esto asegura que de fallar el cálculo o alteración de inventario, ningún ticket "fantasma" sin stock trazado será guardado.

### 🔄 Order Recall y Sistema Múltiple
- Endpoints diseñados para recibir tickets `status='pending'` (Previo Armado).
- Cuando el POS solicita el cobro de una de éstas, la API (`PUT /sales/{id}/pay`) hace un `Merge / Diff` iterando el carrito original del pedido vs el ticket final pagado. 
- Restituye stock atómicamente en cascada a perchas a través de `StockMovements` con firma si mermaron ítems entre espera y pago.

### 🧮 Cierres Transaccionales (Turnos)
Ventas, retiros y aperturas interconectadas a un `cash_register_shift_id`. Las queries maestras de "Cierre Z" sumarizan automáticamente solo totales en estatus `completed` de la jornada actual y se las envían al cliente cerrando con timestamps la sesión del empleado sin fisuras lógicas (`ON DELETE SET NULL` para cajeros desactivados).

### 📦 Inventario Integral
- Gestión de `products`, `categories`, `brands`.
- Auditoría viva de toda la cadena de mercadería usando el modelo `StockMovement`.
- Permiso granular de Anulaciones de tickets (`/void`), restituyendo de forma transparente mercadería restada en transacciones fallidas de usuarios.

## 🛠 Instalación y Configuración Local

### Requisitos previos
- PHP 8.1+
- Composer
- Motor de base de datos (MySQL / PostgreSQL / SQLite)

### Pasos
1. Clonar el repositorio.
2. Instalar dependencias backend:
   ```bash
   composer install
   ```
3. Copiar archivo de entorno:
   ```bash
   cp .env.example .env
   ```
4. Generar la llave de la aplicación:
   ```bash
   php artisan key:generate
   ```
5. Configurar conexión en `.env`:
   Asigna tus credenciales `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Ten precaución de definir `APP_TIMEZONE` a tu región local (ejemplo `America/Argentina/Buenos_Aires`) para compatibilizar la aritmética de facturación.
6. Correr las migraciones y seeders iniciales:
   *(Este backend incluye Seeders masivos con usuarios pre-configurados [PIN 1234/5678] y Catálogos robustos listos)*
   ```bash
   php artisan migrate --seed
   ```
7. Lanzar el servidor local:
   ```bash
   php artisan serve
   ```
   *El backend despachará las peticiones HTTP seguras en `http://127.0.0.1:8000/api`.*

---
*Diseñado proveyendo APIs JSON RESTful seguras, optimizadas e imperativas para el Frontend Dart POS.*
