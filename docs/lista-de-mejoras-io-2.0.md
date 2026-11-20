# Lista de mejoras · Inteligencia Objetiva 2.0

20 de noviembre de 2026

Mejoras que incorpora la versión 2.0 de inteligenciaobjetiva.com frente a la versión actual. Todo se monta sobre el sitio existente, sin reescribirlo ni modificar sus tablas. El detalle técnico de cada punto está en `informe-tecnico-io-2.0.md`.

Los puntos marcados como **(propuesta)** forman parte del backoffice: ya están desarrollados, pero como todavía no conocemos el backoffice que hoy usa el equipo, se adaptarán después de revisarlo.

## Mejoras implementadas

### Imagen y experiencia del sitio

1. Colores de marca unificados (rojo, marino y grafito) y tipografía Roboto en todo el sitio.
2. Modo oscuro con botón sol/luna que recuerda la preferencia de cada visitante y no parpadea al cargar.
3. Portada nueva por secciones, más clara y fácil de actualizar.
4. Imágenes ligeras (WebP) y video de presentación que solo carga al pulsarlo, para que el sitio abra más rápido.
5. Animaciones que respetan la opción «reducir movimiento» del dispositivo.
6. Sin desplazamiento horizontal en celular y mejor contraste de textos.
7. Banner de ofertas más compacto y aviso «En vivo» en todas las páginas cuando hay transmisión.

### Tienda y compra

8. Página Nosotros, catálogo y ficha de cada contenido renovados.
9. Catálogo con filtros por categoría, tema, tipo de acceso, búsqueda y orden.
10. Migas de pan para saber siempre dónde está uno dentro del sitio.
11. Las direcciones de siempre siguen funcionando: las fichas antiguas redirigen a las nuevas.
12. Pago con tarjeta y en OXXO mediante Stripe.
13. El pedido solo se confirma cuando Stripe avisa que el pago entró; el precio se valida en el servidor.
14. El acceso al contenido se otorga solo al confirmarse el pago.
15. Cupones de porcentaje, de monto fijo y 2×1, con fechas, productos y cupo.
16. Campañas de ofertas con fechas de inicio y fin que solo anuncian rebajas reales.
17. Membresías con cobro recurrente, renovación automática y portal para que el cliente las administre o cancele.
18. Facturación electrónica CFDI 4.0 desde «Mis pedidos»: el cliente la solicita y descarga su PDF y XML.
19. Devoluciones desde el panel, con retiro automático del acceso al confirmarse.

### Biblioteca del alumno y reproductor

20. Biblioteca «Mi contenido» con todo lo que el alumno compró, integrable en su perfil.
21. Reproductor de Vimeo dentro del sitio, sin salir a otra página.
22. Reglas de acceso por contenido: por horas desde la compra o desde la primera reproducción, permanente o de visualización única.
23. Reanudaciones y periodo de gracia configurables para no castigar cortes de internet.
24. Una sola sesión de reproducción por alumno a la vez.
25. Marca de agua con el correo del alumno sobre el video.
26. Videos de pago ocultos en Vimeo y con inserción permitida solo en el dominio del sitio.

### Backoffice para el equipo (propuesta)

27. Editor de contenido por pestañas (Detalles, Precio y venta, Acceso): al pegar el enlace de Vimeo se llenan solos el título, la duración y la miniatura.
28. Miniaturas con recorte 16:9 y arrastrar y soltar.
29. Altas en ventanas emergentes, sin cambiar de página.
30. Carga masiva de contenidos desde una hoja de cálculo (CSV), que crea borradores para revisar.
31. Otorgar cortesías buscando al alumno y el contenido; extender, reiniciar o revocar accesos.
32. Transmisiones en vivo por YouTube, Facebook, Vimeo o Zoom, con acceso público, para usuarios con cuenta, para compradores o para miembros.
33. Inicio y cierre automáticos de cada transmisión, sala de espera, recordatorio para el calendario y grabación enlazada al terminar.
34. Detección automática de los directos públicos del canal de YouTube: aparecen solos en el sitio en menos de un minuto.
35. Equipo, roles y perfiles: cinco roles base, nueve permisos configurables y perfil propio para cada persona.
36. Siempre queda al menos un administrador total, y desactivar a alguien cierra sus sesiones al momento.
37. Bitácora: todo cambio de acceso exige motivo y queda registrado junto con quién lo hizo.
38. Reporte de uso por contenido: accesos otorgados, reproducciones y accesos que vencieron sin usarse.

### Seguridad y confiabilidad

39. Protección contra peticiones falsificadas y freno a intentos repetidos de inicio de sesión.
40. Contraseñas del equipo de al menos 12 caracteres y permisos revisados en cada acción.
41. Si un cambio no se puede registrar en la bitácora, el cambio se deshace.
42. Carpetas internas (código, base de datos, configuración) bloqueadas al público.
43. Las credenciales nunca viajan en el paquete de entrega.
44. 21 suites de pruebas automáticas que se corren antes de cada entrega.

### Migración y compatibilidad

45. Funciona con PHP 7.4 en adelante y con MySQL 5.7 y 8.0.
46. Solo agrega tablas nuevas (prefijo `io_`); no modifica ni borra las del sitio.
47. Importación de cursos y compras vigentes con modo de simulación, sin duplicar si se repite.
48. Los alumnos no se duplican: se reconocen con la misma sesión del sitio actual.
49. Publicación por fases, cada una con su forma de regresar a la versión anterior.
50. Diagnóstico automático del servidor antes de abrir la tienda.

### Buscadores y agentes de IA

51. `llms.txt` y `llms-full.txt`: resumen para ChatGPT, Claude, Perplexity, Gemini y otros asistentes, con quién es Martín Aparicio, sus temas (entre otros, de manera enunciativa y no limitativa: autoconocimiento, la mente y la conciencia, las emociones, espiritualidad y filosofía de vida) y todos sus contenidos.
52. `robots.txt` que da la bienvenida a los rastreadores de buscadores y de IA y excluye las áreas privadas.
53. Mapa del sitio con portada, catálogo, temas, fichas y transmisiones públicas.
54. Datos estructurados de organización, fundador, cursos, transmisiones y búsqueda del sitio.
55. Página propia para cada tema del catálogo.
56. De forma enunciativa, mejoras de SEO: dirección canónica, descripciones propias, etiquetas para compartir en redes y redirecciones que conservan el posicionamiento.

## Mejoras recomendadas para una siguiente etapa

Lo siguiente no está incluido en la 2.0 y se propone para después de publicarla:

1. Página propia de Martín Aparicio con su obra y enlaces oficiales.
2. Páginas por concepto y glosario del método (eneagrama, ley de siete, conciencia de sí, entre otros).
3. Fichas públicas de diplomados, programas y libros.
4. Página permanente para cada grabación pública después de transmitirse.
5. Datos estructurados de videos públicos, libros y preguntas frecuentes.
6. Correo saliente del sitio: avisos de compra, recordatorios de transmisiones y recuperación de contraseña del equipo.
7. Monitor de disponibilidad y caché de las páginas públicas.
8. Integración con el backoffice actual una vez revisado con el equipo.
9. Historial de reproducción de los alumnos migrados, si se decide conservarlo.
