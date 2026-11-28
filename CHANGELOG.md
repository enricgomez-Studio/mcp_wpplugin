# Registro de cambios de EG Studio (antes EG Publicación)

Historial del plugin EG Publicación, de lo más nuevo a lo más antiguo. Es el registro común de los cambios del repositorio: antes de cambiar o decidir nada sobre el plugin, léelo junto con la [última release](https://github.com/enricgomez-Studio/mcp_wpplugin/releases/latest), y añade aquí tu cambio en el mismo PR (ver «Cómo trabajamos en este repositorio» en el [README](README.md)).

Cada entrada indica la versión, la fecha en que se publicó la release (UTC), el PR, qué cambia y a qué tipología, módulo y webs afecta. Las versiones siguen `MAYOR.MENOR.PARCHE`: parche para correcciones, menor para funciones nuevas compatibles, mayor si algo deja de funcionar como antes.

## Sin publicar

Cambios ya fusionados en `main` que aún no tienen release (no han subido la versión). Al publicar la siguiente versión, se mueven a su entrada.

- Nada por ahora.

## 2.146.2 · 28/11/2026 · PR (Claude, a petición de Enric)

- Corrige `eg_pub_item_elementor_images`: la comprobación `is_int($node['id'])` fallaba cuando Elementor almacena el ID de adjunto como cadena (p. ej. `"id": "1234"` en lugar de `"id": 1234`). Ahora acepta tanto entero como cadena de dígitos.
- Gestión de la web (`fichas`). Webs: todas las que usen `elementor_images`.

## 2.146.1 · 28/11/2026 · PR (Claude, a petición de Enric)

- Corrige el retorno anticipado de `/item`: cuando solo se pasaba `elementor_images` (sin `set`, `acf`, `meta` ni `featured_image`) el manejador devolvía `dry_run: true` sin llegar a procesar las sustituciones. Ahora la condición de salida temprana también comprueba que `elementor_images` esté vacío.
- Gestión de la web (`fichas`). Webs: todas las que usen `elementor_images`.

## 2.146.0 · 28/11/2026 · PR (Claude, a petición de Enric)

- `/item elementor_images: [{old_url, new_id, partial}]`: nuevo parámetro que sustituye objetos de imagen en `_elementor_data` sin pasar por `wp_update_post` (compatible con WPML). Recorre el árbol de forma recursiva; identifica imágenes por URL exacta, parcial (`partial: true`) o por `old_id`. Compatible con el batch `items`.
- Gestión de la web (`fichas`). Webs: campingelfar.com (y cualquier web con Elementor).

## 2.144.0 · 24/11/2026 · PR (Claude, a petición de Enric)

- `/seo-tech ga4_options: {"purchase": true, "exclude_logged": true}`: con la etiqueta de GA4 de EG Studio, manda el evento `purchase` de WooCommerce una sola vez por pedido en la página de pedido recibido (número, importe, IVA, envío y productos) y deja fuera a los usuarios conectados. Es lo que hacía Site Kit, para poder quitarlo.
