---
name: desplegar-io
description: Arma el paquete de despliegue de Inteligencia Objetiva 2.0 (tema, portada y módulo /io/) en dist/, verificando sintaxis, pruebas, imágenes y que no viajen credenciales.
disable-model-invocation: true
---

# Desplegar IO 2.0

Ejecuta en orden y detente ante cualquier fallo:

1. **Sintaxis**: `php -l` en cada `.php` de `modulo-visualizacion/` (excepto `tests/`). Cero errores.
2. **Pruebas**: con la base temporal en el puerto 3307 activa, `php modulo-visualizacion/tests/prueba-flujo.php` debe decir `0 fallidas`.
3. **Imágenes**: todo `portada/img/*.png` debe tener su `.webp` (máx. 2000 px de ancho, calidad 82). Generar con Pillow si falta.
4. **Paquete** en `dist/io2-AAAAMMDD/`:
   - `tema/` completo
   - `portada/` sin PNG originales
   - `io/` = `modulo-visualizacion/` **sin** `config.php`, `tests/`
   - `GUIA-DESPLIEGUE.md`
5. **Verificación de secretos**: `grep -rEi "clave'\s*=>\s*'[^']+'|password|token'\s*=>\s*'[^']+'" dist/` no debe encontrar valores reales.
6. Comprimir `dist/io2-AAAAMMDD.zip` y listar su contenido.
7. Resumir: archivos incluidos, resultado de pruebas, pasos manuales pendientes (SQL, config.php, crear admin, Vimeo).
