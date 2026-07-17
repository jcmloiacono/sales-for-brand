# Brand Discounts Manager

Plugin para WordPress + WooCommerce que permite gestionar descuentos personalizados e individuales por marca (taxonomía `product_brand` de WooCommerce Brands).

## Descripción

Este plugin añade una página de administración en **WooCommerce → Sconti per Marchio** donde puedes:

- Ver todas las marcas registradas en la tienda con su cantidad de productos.
- Asignar un porcentaje de descuento único a cada marca.
- Activar o desactivar el descuento por marca individualmente.
- Recalcular manualmente todos los descuentos activos con un solo clic.
- Programar un cron diario para recalcular descuentos automáticamente a una hora configurable.

## Requisitos

- WordPress 5.0+
- WooCommerce 5.0+
- Plugin [WooCommerce Brands](https://woocommerce.com/products/brands/)

## Instalación

1. Descarga el plugin y súbelo a `/wp-content/plugins/brand-discounts-manager/`.
2. Actívalo desde el panel de administración de WordPress.
3. Ve a **WooCommerce → Sconti per Marchio** para configurar los descuentos.

## Funcionalidades

### Gestión de descuentos por marca
- Cada marca puede tener un descuento en porcentaje (1%–90%).
- Los descuentos se aplican directamente sobre el precio regular (`regular_price`) del producto, estableciendo el precio de venta (`sale_price`).
- Compatible con productos simples y variables (aplica el descuento a cada variación individual).

### Recalculo automático (Cron diario)
- Se puede configurar la hora exacta del día (0–23) para que el plugin ejecute un recálculo automático de todos los descuentos activos.
- Por defecto está programado a las 3:00 AM.
- Al cambiar la hora, el cron se reprograma automáticamente.

### Recalculo manual
- El botón **Ricalcola tutti** en la barra de herramientas ejecuta el recálculo de todos los descuentos activos al instante vía AJAX.

### Panel de administración
- Tres tarjetas de resumen: total de marcas, marcas con descuento activo y productos en oferta.
- Buscador en tiempo real para filtrar marcas por nombre.
- Filtros rápido: **Todas**, **Activas**, **Inactivas**.
- Cada fila de marca muestra: nombre, ID, cantidad de productos, badge de estado (descuento activo o sin descuento), campo de entrada para el % de descuento y botones **Applica** / **Rimuovi**.

## Cron

- **Hook:** `bdm_daily_recalculate`
- **Hora configurable:** Se almacena en `wp_options` con la clave `bdm_cron_hour` (valor por defecto: `3`).
- Al activar el plugin se programa el cron automáticamente. Al desactivarlo se elimina la programación.

## Almacenamiento de datos

| Dato | Clave en `wp_options` | Formato |
|------|----------------------|---------|
| Hora del cron | `bdm_cron_hour` | Entero (0–23), default: `3` |
| Configuración de descuentos | `bdm_discounts` | Array asociativo: `{ term_id => { discount, active, updated } }` |

**Nota:** Al eliminar el plugin los datos **no** se limpian automáticamente (no hay `uninstall.php` ni hook de desinstalación).

## AJAX API

| Acción | Método | Descripción |
|--------|--------|-------------|
| `bdm_get_brands` | GET | Obtiene todas las marcas con su descuento y estado |
| `bdm_apply_discounts` | POST | Aplica o revierte el descuento de una marca individual |
| `bdm_recalculate_all` | POST | Recalcula todos los descuentos activos |
| `bdm_set_cron_hour` | POST | Actualiza la hora del cron diario |

## Desarrollo

El plugin es un archivo único (`brand-discounts-manager.php`) sin dependencias externas ni build steps. El CSS y JavaScript están embebidos en el archivo principal.

### Hooks propios

- **`bdm_daily_recalculate`** — Acción de cron para el recálculo diario.

## Comportamiento

- Al aplicar un descuento se limpian las fechas `date_on_sale_from` y `date_on_sale_to` (sin programación de ofertas).
- Se llama a `wc_delete_product_transients()` para refrescar las cachés de WooCommerce.
- Si `WP_DEBUG` está activo, las ejecuciones del cron se registran con `error_log()`.

## Licencia

GPL v2 o posterior.

## version Update   
- v1.0.0 - 2026-03-10 - Versión inicial
- v1.0.1 - 2026-05-12 - Corrección de errores menores y mejoras en la interfaz
- v1.0.20 - 2026-06-15 - Agregado botón de recalculo manual y optimización del cron diario
- v1.0.30 - 2026-07-17 - Mejoras en la paginacion  
