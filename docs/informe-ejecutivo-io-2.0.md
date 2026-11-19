# Informe ejecutivo · Inteligencia Objetiva 2.0

19 de noviembre de 2026 · Versión en línea: https://claude.ai/artifact/LY2Qhb8sQSgpdkUsit5nGW

## Resumen ejecutivo

La versión 2.0 convierte inteligenciaobjetiva.com de un sitio con catálogo en una plataforma de aprendizaje con tienda propia, biblioteca del alumno, transmisiones en vivo y un panel de control para el equipo. Todo se instala encima del sitio actual, por fases reversibles, sin reescribirlo y sin tocar su información.

**Qué cambia**

- **Para el alumno:** una biblioteca personal con todo lo que compró, un reproductor que recuerda dónde se quedó, modo oscuro, una portada nueva y una página de transmisiones en vivo con calendario y recordatorios.
- **Para ventas:** carrito y pago en línea con tarjeta y OXXO (Stripe), cupones y 2×1, membresías recurrentes, factura electrónica (CFDI 4.0) que el cliente solicita solo, y devoluciones controladas.
- **Para el equipo:** una propuesta de backoffice donde se da de alta un curso pegando el enlace de Vimeo, se arrastra la miniatura, se otorgan cortesías, se programan transmisiones y se define qué puede hacer cada persona del equipo, con registro de quién hizo qué y por qué.
- **Para la marca:** la identidad roja, marino y grafito con tipografía Roboto, aplicada de forma uniforme en todo el sitio.

**Por qué importa al negocio**

1. **Más ventas con menos fricción.** El pago se confirma de forma automática y el acceso aparece al instante en la biblioteca; sin esperar una validación manual.
2. **Control del contenido de pago.** Cada curso puede venderse como acceso permanente, por ventana de tiempo o de visualización única, y el sistema lo hace cumplir.
3. **Operación más ligera.** Altas, cortesías, cupones y directos se resuelven desde un solo panel, con bitácora para auditoría.
4. **Riesgo bajo de adopción.** Cada fase se puede revertir en minutos y solo se agregan tablas nuevas a la base de datos.

**Qué falta para salir a producción:** configurar las cuentas reales (Stripe, Facturapi, Vimeo, YouTube), adaptar dos consultas de importación a las tablas reales del sitio, levantar el backoffice que hoy usa el equipo y aceptar los recorridos de compra en un entorno de pruebas. La propuesta de equipo, roles y perfiles ya está desarrollada.

## Supuestos

Todavía no conocemos el backoffice que hoy usa el equipo, así que todo lo relativo al backoffice en este informe es una propuesta de mejora y no una comparación con lo existente. Partimos de estos supuestos, que deben confirmarse:

- El equipo administra hoy contenidos, accesos y ventas con herramientas limitadas o con pasos manuales.
- No hay roles diferenciados por función.
- La propuesta se adaptará una vez que revisemos el backoffice real.

**Recomendación:** antes de implementar, levantar el backoffice actual: un recorrido con las personas que lo usan a diario, acceso de solo lectura para revisar sus pantallas y flujos, y una lista acordada de lo que se conserva, se sustituye o se integra.

## Mejoras de la versión 2.0 frente a la anterior

La 2.0 automatiza el ciclo de compra y acceso, y propone herramientas nuevas para el alumno y el equipo. La tabla resume cada mejora por quién la aprovecha; la columna «Antes» describe lo que se ve en el sitio público y, en lo que toca al backoffice, dice «Por levantar» porque aún no lo conocemos.

| Mejora | Antes | Ahora | Beneficio principal |
| --- | --- | --- | --- |
| Tema y modo oscuro | Un solo tema claro; menú con poco contraste, desplazamiento horizontal en celular | Botón sol/luna que respeta la preferencia del equipo del usuario, sin parpadeo; banner de ofertas compacto con contador | Alumno y marca |
| Nueva portada | Portada anterior | Portada 2.0 con imágenes propias ligeras, video de presentación que carga solo al pulsarlo y animaciones que respetan a quien prefiere menos movimiento | Marca y ventas |
| Tienda y pago con Stripe | Carrito y pasarelas actuales; flujo de confirmación por levantar | Catálogo, ficha, carrito y pago con tarjeta u OXXO; el acceso se activa solo cuando Stripe confirma el pago | Ventas |
| Cupones y 2×1 | Por levantar | Cupones de porcentaje, monto fijo o 2×1 con fechas, productos y cupo; se crean en una ventana con resumen en lenguaje claro | Ventas |
| Membresías, facturas y devoluciones | Membresías Premium y Platinum en el sitio; facturación y devoluciones por levantar | Membresías mensuales o anuales, CFDI 4.0 de autoservicio y devoluciones que retiran el acceso al confirmarse | Ventas y equipo |
| Biblioteca del alumno | Enlace «Mi contenido» del perfil | Biblioteca con todo lo comprado, vigencias claras y reproductor que retoma donde se quedó | Alumno |
| Visualización única | No identificada en el sitio público | Cursos que se ven una sola vez, con periodo de gracia si se cierra la pestaña y reanudaciones configurables | Ventas y alumno |
| Editor de contenido estilo estudio | Por levantar | Tres pestañas (Detalles, Precio y venta, Acceso); pegar el enlace de Vimeo llena título, duración y miniatura; vista previa de la tarjeta del catálogo; carga masiva desde hoja de cálculo | Equipo |
| Miniaturas con arrastre | Por levantar | Se arrastra la imagen, el navegador la recorta a 16:9 y el servidor solo acepta imágenes reales | Equipo |
| Altas en ventana (modal) | Por levantar | Cupones, cortesías, transmisiones y conexión del canal de YouTube se dan de alta en ventanas cortas, sin salir de la pantalla | Equipo |
| Transmisiones en vivo | Calendario de conferencias vía Zoom; administración por levantar | Página /en-vivo y calendario para YouTube, Facebook, Vimeo y Zoom; sala de espera 15 minutos antes; recordatorio para el calendario; aviso «En vivo» en todo el sitio; acceso público, con cuenta, para compradores o para miembros | Alumno y ventas |
| Detección automática del canal de YouTube | Por levantar | Al conectar el canal una vez, cada directo público aparece solo en el sitio en menos de un minuto, con chat | Equipo y alumno |
| Bitácora y reportes | Por levantar | Cada cambio de acceso exige un motivo y queda registrado con quién y cuándo | Equipo y dirección |
| Roles y perfiles del equipo | Por levantar | **Propuesta ya desarrollada.** Página Equipo para dar de alta y editar al personal en ventanas cortas; cinco roles base (Dirección, Contenido, Atención a alumnos, Ventas y finanzas, Solo lectura) con permisos configurables; perfil propio para cambiar nombre y contraseña; nadie puede quitarse el permiso de equipo ni dejar el sistema sin un administrador total; desactivar a alguien cierra sus sesiones; cada cambio exige motivo y queda en la bitácora | Equipo y dirección |
| Identidad de marca | Estilos dispersos | Rojo, marino y grafito definidos una sola vez y aplicados en todo el sitio, con Roboto en todos los textos | Marca |

Todo lo relativo al backoffice (editor de contenido, miniaturas, cupones en ventana, transmisiones, canal de YouTube, equipo, roles y perfiles) es una propuesta de mejora: ya está desarrollada, pero se adaptará al backoffice real del equipo después del levantamiento.

**Lectura por beneficio**

- **Alumno:** compra y empieza a ver en minutos, encuentra todo en un solo lugar, recibe aviso de los directos y puede leer cómodamente de noche.
- **Equipo interno:** con el backoffice propuesto publicaría un curso en minutos, sin conocimientos técnicos, y resolvería cortesías y aclaraciones desde el panel con respaldo en la bitácora.
- **Ventas:** más medios de pago, promociones con fecha de inicio y fin, membresías y una ficha por producto que se puede compartir.
- **Marca:** una sola identidad visual en portada, tienda, biblioteca y panel.

## Compatibilidad con lo existente y migración

La 2.0 se monta sobre el sitio actual: no reemplaza sus páginas, no modifica sus tablas y cada fase se apaga quitando unas líneas. Lo comprobamos en el código, no solo en la guía.

**Qué verificamos**

- **Base de datos.** Las diez migraciones (001 a 010) solo crean 24 tablas nuevas, todas con prefijo `io_`, y ninguna altera, borra o vincula por llave foránea las tablas del sitio. Todas usan «crear si no existe», así que repetir una migración no daña nada.
- **Datos del sitio.** El módulo solo **lee** las tablas actuales (catálogo, compras y clientes) mediante consultas configurables; nunca escribe en ellas.
- **Cuentas de alumnos.** No se duplican: el módulo reconoce al alumno con la misma sesión de inicio del sitio actual y reutiliza sus ventanas de inicio de sesión y registro.
- **Servidor.** Funciona con PHP 7.4 en adelante (se recomienda 8.x) y con MySQL 5.7 y 8.0; el código evita a propósito instrucciones que solo existen en una de las dos versiones. Requiere HTTPS y acceso por terminal.
- **Direcciones web.** La tienda nueva responde en las mismas direcciones (`/nosotros`, `/producto/...`, `/carrito`) y las fichas antiguas redirigen de forma permanente a las nuevas.
- **Cambios al sitio actual.** Son mínimos y puntuales: tres líneas en la plantilla para el tema, el contenido central de la portada, una llamada en el punto donde hoy se confirma un pago, el enlace «Mi contenido» y, al final, un bloque de reglas en `.htaccess`.

**Backoffice actual.** La integración con el backoffice que hoy usa el equipo se confirmará después del levantamiento. Mientras tanto, el panel propuesto en `/io/` se plantea como complemento independiente, con sus propios usuarios, y no reemplaza ni modifica ninguna herramienta existente.

**Plan de migración de la información**

1. **Respaldo completo.** Base de datos y archivos del sitio, más la carpeta de miniaturas en adelante. Sin respaldo verificado no se avanza.
2. **Entorno de pruebas.** Instalar todo primero en un staging con HTTPS y llaves de prueba de Stripe; si no existe, publicar en horario de bajo tráfico.
3. **Preparar Vimeo.** Cada video de pago en «Ocultar de Vimeo», inserción solo en inteligenciaobjetiva.com y descargas desactivadas.
4. **Crear las tablas nuevas.** Ejecutar `sql/001` a `sql/010` en orden. Solo agregan tablas `io_*`.
5. **Conectar la sesión.** Ajustar en la configuración cómo se lee al cliente con sesión (identificador, correo y nombre) y probarlo con una cuenta real.
6. **Crear el primer administrador total del panel** con `bin/crear-admin.php`.
7. **Importar cursos y sus videos.** `bin/importar.php productos --simular` muestra lo que haría sin escribir; después se ejecuta en firme. Toma de la tabla actual el título, el video de Vimeo, la categoría, el precio con IVA, la descripción y la imagen, y asigna la regla de acceso por categoría. No duplica si se repite.
8. **Importar compras vigentes.** `bin/importar.php compras --simular` y luego en firme. Crea el acceso de cada compra pagada y vigente, respeta la fecha original de compra y no duplica si se repite. Los alumnos no se copian: se vinculan por su identificador actual.
9. **Conectar los pagos nuevos.** En el punto donde hoy se confirma un pago, agregar la llamada que otorga el acceso; si falla, el pago no se interrumpe y el error queda registrado.
10. **Verificar.** `bin/diagnostico.php` revisa versión de PHP, extensiones, configuración, tablas 001–010, que exista un administrador total activo y llaves sin mostrarlas ni crear cargos; los puntos marcados «MANUAL» se revisan a mano. Después, una prueba de punta a punta con una cuenta interna y tres compras de prueba (tarjeta aprobada, rechazada y OXXO).
11. **Ventana de cambio.** Activar las direcciones públicas de la tienda en horario de bajo tráfico, con el equipo disponible y las llaves de Stripe en vivo ya probadas.
12. **Plan de regreso.** Cada fase se revierte por separado:

| Fase | Qué se publica | Cómo se regresa |
| --- | --- | --- |
| 1 | Tema y modo oscuro | Quitar tres líneas de la plantilla |
| 2 | Portada 2.0 | Volver a la portada anterior |
| 3 | Biblioteca, reproductor y panel en `/io/` | Ocultar el enlace «Mi biblioteca» y retirar `/io/` |
| 4 | Compras conectadas y accesos migrados | Borrar solo los accesos con origen «migración» |
| 5 | Tienda nueva y pago con Stripe | Comentar el bloque de reglas en `.htaccess` |

**Huecos y datos que no migran solos**

- **Consultas de importación por adaptar.** Las de ejemplo suponen tablas llamadas cursos, compras y clientes; el técnico debe ajustarlas a los nombres reales antes de importar.
- **Historial de reproducción.** El importador trae compras, no reproducciones, así que en accesos que cuentan «desde la primera reproducción» o de visualización única el alumno migrado empieza de cero. Decisión pendiente: aceptarlo o asignar a esos alumnos acceso permanente.
- **Compras vencidas** no se importan; solo las pagadas y vigentes.
- **Pedidos anteriores** no aparecen en «Mis pedidos», por lo que la factura de autoservicio solo aplica a compras hechas en la tienda nueva.
- **Membresías, cupones y facturas previas**, si existen fuera del sitio, no se importan; las membresías nuevas se crean en Stripe.
- **Redirecciones antiguas.** La ficha nueva se identifica con el título más el número del producto; una dirección antigua solo llega a su ficha si el sitio actual la forma igual. Hay que probar la lista completa de direcciones antes de activar la fase 5.
- **Categorías.** «Introspecciones» y «Documentales» comparten la misma página de catálogo.
- **Videos nuevos.** La carga masiva conecta videos que ya están en Vimeo; no sube archivos de video.
- **Documentos desfasados.** La entrega al cliente pedía las migraciones 001–007 y la guía mencionaba alertas en el panel; ambos documentos ya se corrigieron (001–010, sin alertas).

## Acceso de agentes de IA y disponibilidad del contenido

Cada vez más personas preguntan a ChatGPT, Claude, Perplexity o Gemini sobre los temas que trabaja Martín Aparicio. Para que esos asistentes encuentren, lean y citen a Inteligencia Objetiva, cada tema y cada contenido público necesita una página propia que se lea sin iniciar sesión ni ejecutar código. Esto aplica, entre otros, de manera enunciativa y no limitativa, a todos sus cursos, conferencias, documentales, introspecciones, audiolibros, transmisiones, libros y conceptos.

**Temas y contenidos que deben quedar al alcance de los agentes** (lista enunciativa y no limitativa, tomada del catálogo del repositorio y de las páginas públicas del sitio revisadas el 19 de noviembre de 2026):

- **Categorías del catálogo:** conferencias en línea (20 páginas de listado), cursos en línea (8 páginas), documentales, introspecciones, audiolibro y conferencias especiales en vivo vía Zoom.
- **Temas del catálogo:** relación de pareja, economía, educación a los hijos, entorno laboral, desarrollo personal y superación.
- **Programas:** diplomados en Comunicación humana; Conciencia, cuerpo y nutrición; Sistema diagnóstico aplicado; y Neodisciplina. Programa de Entrenamiento y Desarrollo Mental (niveles 1 y 2) y su Propedéutico, Curso de Autoconocimiento y Desarrollo Mental, y MECA.
- **Documentales e introspecciones:** adicciones, arrepentimiento, autoestima, calidad de vida, conciencia, creencias, depresión, felicidad, presupuesto de vida y superación de sí.
- **Audiolibro:** «Conciencia de sí».
- **Conceptos y disciplinas:** autoconocimiento, conciencia de sí, filosofía (por ejemplo, «Filosofía viva»), psicología (por ejemplo, «Psicología de la autodestrucción», neuropsicología del miedo y psicología financiera), frecuencia y fortaleza mental, ansiedad, miedo, emociones, toma de decisiones, espiritualidad y tradiciones de sabiduría (Buda, Sócrates y Cristo; budismo y Dzogchen en «Nosotros»).
- **Eneagrama y ley de siete:** conceptos del método señalados por la dirección. No aparecen en las páginas públicas revisadas, así que hoy un agente no puede encontrarlos ni citarlos.
- **Transmisiones y medios:** conferencias en vivo del calendario, Televisión Objetiva y los canales de YouTube @martinaparicio-io e inteligenciaobjetiva.
- **Libros:** el sitio no tiene una sección de libros. Si existen, cada uno necesita su ficha pública.

La portada habla de más de 250 temas en línea. De cada listado solo se revisó la primera página, así que el inventario completo debe salir del catálogo al importarlo.

**Qué está hecho y qué se recomienda**

| Medida | Estado | Qué implica |
| --- | --- | --- |
| Ficha pública de cada contenido del catálogo, legible sin JavaScript | Hecho (fase 5) | Título, resumen, descripción, «aprenderás» y precio llegan escritos desde el servidor para todo lo que se importe al catálogo |
| Ficha pública de diplomados, programas, libros y audiolibros | Recomendado | Hoy quedan fuera del catálogo 2.0; cada uno necesita su ficha con texto propio |
| Una página pública por tema y concepto | Recomendado | Una por cada tema de la lista anterior y los que se sumen: explicación en texto, preguntas frecuentes y enlaces a los cursos, conferencias, documentales y transmisiones relacionados |
| Glosario público del método | Recomendado | Definiciones breves y citables de cada concepto (conciencia de sí, eneagrama y ley de siete, entre otros) |
| Página del autor | Recomendado | Martín Aparicio: biografía, obra y enlaces oficiales a redes y canales, para que los agentes atribuyan bien las citas |
| Página por transmisión y por grabación pública | Parcial | La 2.0 ya tiene /en-vivo y /calendario. Falta una página permanente para cada directo público y su grabación |
| `robots.txt` | Parcial | Ya existe en producción y permite todo salvo áreas privadas. Conviene nombrar de forma explícita GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot y Google-Extended, y bloquear `/io/backoffice/`, `/io/api/` y `/io/public/`; la regla actual de `/api/` no cubre `/io/api/` |
| Mapa del sitio (`sitemap.xml`) | Parcial | Existe en producción con las fichas actuales. Debe actualizarse con las fichas nuevas (`/producto/...`), las páginas de tema y el calendario |
| `llms.txt` en la raíz | Recomendado | No existe. Un índice en texto: quién es Martín Aparicio, qué es Inteligencia Objetiva, la lista de temas y el enlace a cada página de tema y categoría |
| Datos estructurados `Course` y migas de pan | Hecho | Curso con proveedor y oferta en MXN, calificación solo con reseñas reales, y ruta Inicio › Catálogo › Categoría › Curso |
| `Organization` y `Person` | Recomendado | En la portada, «Nosotros» y la página del autor, con sus perfiles oficiales |
| `VideoObject`, `Event`, `Book` y `FAQPage` | Recomendado | Videos públicos y tráilers, transmisiones abiertas, libros y audiolibros, y preguntas frecuentes de cada tema; nunca para el contenido de pago |
| Descripción de la portada y redirecciones de fichas antiguas | Hecho | Resumen del sitio para buscadores y asistentes, y enlaces antiguos que siguen funcionando |
| Rendimiento y disponibilidad | Parcial | Ya hay imágenes ligeras y video que carga al pulsarlo. Falta un monitor de disponibilidad, caché de las páginas públicas y revisar que el cortafuegos del hosting no bloquee a estos rastreadores |

Los agentes solo deben ver lo público: portada, fichas, temas, glosario, autor y calendario. Las páginas de tema publican explicaciones y resúmenes; el contenido de pago sigue detrás del inicio de sesión y del control de acceso.

## SEO

De forma enunciativa, la 2.0 aporta o deja listo:

- Redirecciones permanentes de las fichas antiguas para conservar el posicionamiento.
- Descripción propia en cada ficha y en la portada.
- Datos estructurados de curso y migas de pan.
- Imágenes WebP ligeras y video bajo demanda, que mejoran la velocidad de carga.
- Sin desplazamiento horizontal en celular.
- Pendiente: actualizar el mapa del sitio existente con las direcciones nuevas, y agregar etiquetas para compartir en redes (Open Graph) y dirección canónica en cada página.

## Riesgos, próximos pasos y cronograma

El mayor riesgo no es técnico sino de configuración: cuentas, consultas de importación y direcciones antiguas deben probarse con datos reales antes de abrir la tienda, y la propuesta de backoffice debe validarse contra el backoffice real del equipo.

| Riesgo | Impacto | Mitigación |
| --- | --- | --- |
| Consultas de importación mal adaptadas | Alumnos sin acceso a lo que compraron | Simular primero, revisar el conteo contra el sistema actual y probar con cuentas reales |
| Direcciones antiguas que no coinciden | Enlaces rotos y pérdida de posicionamiento | Probar la lista completa antes de la fase 5 |
| Pagos sin confirmación por configuración de Stripe | Pedidos pagados sin acceso | Tres compras de aceptación en modo prueba y luego en vivo |
| Alumnos migrados con ventana nueva | Acceso más amplio que el original | Definir la regla para migrados antes de importar |
| Enlace del reproductor reutilizado durante una visualización única | Una reproducción extra dentro del dominio permitido | Es un límite conocido: dominio restringido en Vimeo, marca de agua con el correo, latidos y sesión única. Por decisión del negocio no se agregan más capas |
| Hosting sin las reglas de protección de carpetas | Archivos internos expuestos | Comprobar que las rutas internas respondan «prohibido» al desplegar |
| Usuarios del panel nuevo al aplicar roles | Alguien con más o menos permisos de los que necesita | La migración 010 conserva los permisos de cada usuario; revisar la página Equipo y ajustar roles con motivo |

**Próximos pasos**

- [ ] Aprobar las reglas de acceso por categoría y la regla para alumnos migrados.
- [ ] Abrir o confirmar las cuentas de Stripe, Facturapi, Vimeo (token) y la llave de YouTube.
- [ ] Levantar el backoffice actual con el equipo (recorrido y acceso de solo lectura) y designar al técnico que adapte la sesión y las consultas de importación.
- [ ] Preparar el entorno de pruebas con HTTPS.
- [ ] Inventariar todos los temas, conceptos y contenidos del catálogo completo.
- [ ] Revisar en la página Equipo el rol y los permisos de cada persona tras la migración.
- [ ] Ampliar `robots.txt` y publicar `llms.txt`, la página del autor y las páginas de tema.

**Cronograma sugerido**

Siete semanas a partir de la aprobación, con una revisión entre fases; las semanas son estimaciones a validar con el técnico del sitio. La semana 1 incluye el levantamiento del backoffice actual, que puede ajustar la propuesta y el calendario.

| Semana | Actividad |
| --- | --- |
| 1 | Preparación: levantamiento del backoffice actual, respaldo, entorno de pruebas y cuentas |
| 2 | Fases 1 y 2: tema y portada |
| 3 | Fase 3: biblioteca, reproductor y panel en `/io/`, con equipo, roles y perfiles |
| 4 | Fase 4: importar cursos y compras |
| 5–6 | Fase 5: tienda y Stripe en modo prueba |
| Cierre de la 6 | Cambio a pago en vivo |
| 7 | Membresías, facturas, devoluciones y acceso de agentes de IA |

El pago en vivo se activa solo después de que el diagnóstico pase y las tres compras de aceptación funcionen en modo prueba; equipo, roles y perfiles se instala con la fase 3.

## Anexo técnico

- **Componentes:** `tema/` (estilos de marca y modo oscuro), `portada/` (portada 2.0) y `modulo-visualizacion/`, que se publica como `/io/` (biblioteca, reproductor, tienda, API y panel).
- **Requisitos:** PHP 7.4+ (8.x recomendado) con PDO MySQL, cURL y mbstring; MySQL 5.7 u 8.0; HTTPS; Apache con `mod_rewrite` y `AllowOverride All`, o reglas equivalentes en nginx.
- **Migraciones:** `sql/001` a `sql/010`; crean 24 tablas `io_*` (accesos, sesiones, políticas, panel, bitácora, productos, pedidos, membresías, cupones, facturas, reembolsos, transmisiones, ajustes, equipo y roles).
- **Scripts:** `bin/crear-admin.php` (primer usuario), `bin/importar.php productos|compras [--simular]` (importación idempotente), `bin/diagnostico.php [--stripe-prueba]` (solo lectura), `bin/limpiar-miniaturas.php` (diario).
- **Pagos:** Stripe con webhook firmado (versión de API 2024-06-20); el navegador nunca marca un pedido como pagado y cada evento se procesa una sola vez.
- **Control de acceso al video:** dominio restringido en Vimeo, marca de agua, latidos validados en el servidor y sesión única.
- **Pruebas:** `tests/prueba-flujo.php` y suites por módulo contra una base temporal; deben terminar en «0 fallidas»; al integrar roles se reportaron 20 suites y 658 pruebas en verde, con revisión de seguridad aplicada. Las pruebas locales no sustituyen la aceptación con las cuentas reales.
- **Referencias:** `GUIA-DESPLIEGUE.md`, `ENTREGA-CLIENTE.md`, `MEMBRESIAS.md`, `DESCUENTOS.md`, `FACTURACION.md`, `PEDIDOS.md`.
