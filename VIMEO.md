# Vimeo y la plataforma: cómo se conectan

Guía para el dueño y el equipo de Inteligencia Objetiva. Explica qué hace Vimeo, qué hace la plataforma y cómo dejar cada video listo.

## En una frase

**Vimeo guarda los videos y decide *dónde* se pueden reproducir. La plataforma decide *quién* puede verlos y *por cuánto tiempo*.**

Las dos partes se necesitan. Vimeo solo debe permitir que el video se reproduzca dentro de inteligenciaobjetiva.com. Dentro del sitio, la plataforma solo muestra el reproductor a quien compró el contenido y mientras su acceso siga vigente.

## Qué controla cada uno

| Vimeo (en vimeo.com) | La plataforma (en el backoffice) |
|---|---|
| Guarda el video y lo transmite con buena calidad. | Sabe quién compró cada contenido. |
| **Privacidad:** el video queda *oculto de Vimeo*, así que no se puede ver en vimeo.com. | **Vigencia:** 48 horas desde la primera reproducción, acceso permanente o visualización única, según el contenido. |
| **Dominio:** solo se puede insertar en inteligenciaobjetiva.com. | **Una reproducción a la vez** por alumno, con señales cada 15 segundos para confirmar que sigue viendo. |
| **Descargas de Vimeo:** desactivadas, para que Vimeo no ofrezca el archivo. | **Revocar o extender** un acceso desde *Accesos*. Todo queda en la bitácora. |

### Cómo se relacionan

1. El alumno compra y la plataforma le da acceso.
2. Cuando pulsa *Reproducir*, la plataforma revisa que el acceso siga vigente. Solo entonces le entrega al reproductor el video de Vimeo.
3. Vimeo comprueba que la página sea de inteligenciaobjetiva.com y reproduce el video.
4. Si el acceso vence o se revoca, la plataforma deja de entregar el video y detiene la reproducción en el siguiente aviso, en menos de un minuto.

Si en Vimeo el video fuera público, cualquiera podría verlo gratis en vimeo.com, aunque la plataforma esté bien configurada. Por eso hay que revisar las dos partes.

> Límite conocido: una vez que el video empezó a reproducirse en el navegador del alumno, el sitio no puede borrarlo de esa página al instante. Por eso la plataforma revisa el acceso continuamente y Vimeo limita el video al dominio del sitio.

## Vincular la cuenta de Vimeo (una sola vez)

Así el backoffice puede leer los datos de cada video, revisar su privacidad y corregirla con un botón. Toma unos cinco minutos.

1. Entra a **developer.vimeo.com/apps** con la cuenta de Vimeo donde están los videos de los cursos.
2. Pulsa **Create an app** (Crear una app). Nombre: *Inteligencia Objetiva*. Cuando pregunte si otras personas usarán la app, elige **No** (solo tú). Acepta los términos.
3. Dentro de la app, ve a **Generate an access token** (Generar un token de acceso):
   - Elige **Authenticated (you)**.
   - Marca **solo** estos permisos: **Public**, **Private** y **Edit**.
   - Pulsa **Generate**.
4. **Copia el token** en ese momento, porque Vimeo lo muestra completo una sola vez.
5. Envíalo de forma segura a quien administra el servidor (no por correo ni por chat). Esa persona abre el archivo `config.php` del módulo, en `/io/config.php`, y cambia esta línea:

   ```php
   'vimeo_token' => '',
   ```

   por esta:

   ```php
   'vimeo_token' => 'aquí-va-el-token-copiado',
   ```

   En la misma sección confirma que el dominio sea el del sitio:

   ```php
   'dominio' => 'https://inteligenciaobjetiva.com',
   ```

6. En el backoffice, abre **Contenido**, luego cualquier contenido, la pestaña **Video** y el enlace **Cuenta de Vimeo**. Debe decir **Vinculada**, mostrar el nombre de la cuenta y los tres permisos con ✓.

**Para qué sirve cada permiso**

- **Public** y **Private**: leer el título, la duración, la miniatura y la privacidad de tus videos, incluidos los ocultos.
- **Edit**: aplicar la configuración recomendada desde el backoffice. Sin este permiso todo lo demás funciona, pero los ajustes de privacidad se hacen a mano en Vimeo.

**Seguridad:** el token funciona como una llave de la cuenta. Si alguien más lo llega a ver, bórralo en la misma app de developer.vimeo.com y genera otro.

**Plan de Vimeo:** ocultar videos de Vimeo y limitarlos a un dominio requiere un plan de pago (Plus o superior). El plan gratuito no lo permite.

## Conectar un video nuevo

1. Sube el video a Vimeo como siempre.
2. Copia su enlace: botón **Compartir** del video, o la dirección que aparece en el navegador. Sirve cualquiera de estas formas:
   - `https://vimeo.com/123456789`
   - `https://vimeo.com/123456789/abcdef1234` (video no listado)
   - `https://player.vimeo.com/video/123456789?h=abcdef1234`
   - `https://vimeo.com/manage/videos/123456789`
3. En el backoffice abre **Contenido**, luego el contenido (o *Agregar contenido*) y la pestaña **Video**.
4. Pega el enlace en **Enlace del video en Vimeo**. Se llenan solos:
   - la **duración**, en horas, minutos y segundos;
   - la **miniatura**;
   - el **título**, si estaba vacío; si ya había uno, aparece un botón para usar el de Vimeo.
5. Revisa el recuadro **Privacidad en Vimeo**. Si algo no está en verde, pulsa **Aplicar configuración recomendada**.
6. Ve a **Publicación**, escribe el motivo y pulsa **Guardar cambios**.

El ID y el código del video aparecen en *Detalle técnico* solo como referencia. No hace falta escribirlos.

## Lista de verificación por video

En el recuadro *Privacidad en Vimeo* del editor, los tres puntos deben estar en verde:

- [ ] **Oculto de Vimeo**: la privacidad es *Ocultar de Vimeo*, no *Público* ni *Cualquiera con el enlace*.
- [ ] **Solo en el sitio**: la inserción está limitada a *dominios específicos* e incluye `inteligenciaobjetiva.com`.
- [ ] **Descargas de Vimeo**: desactivadas.

En el editor también revisa:

- [ ] La duración es correcta. Es obligatoria para la visualización única.
- [ ] La vista previa del video se reproduce en el editor.
- [ ] El tipo de acceso (48 horas, permanente o visualización única) es el que se vende.

### Si se hace a mano en Vimeo

Hazlo si el token no tiene el permiso *Edit*. En vimeo.com abre el video y entra a **Compartir** o a **Privacidad** (el nombre exacto puede cambiar según la versión de Vimeo):

1. **¿Quién puede ver?** → **Ocultar de Vimeo**.
2. **¿Dónde se puede insertar?** → **Dominios específicos** → agrega `inteligenciaobjetiva.com`. Vimeo incluye sus subdominios, como `www`.
3. **Permitir descargas** → **desactivado**.

Luego, en el editor del backoffice, pulsa **Leer de Vimeo** para confirmar que los tres puntos quedaron en verde.

## Si un video no se reproduce

Revisa en este orden:

1. **¿El alumno tiene acceso vigente?** Búscalo en *Accesos*. Si venció o se revocó, la plataforma no entrega el video. Desde ahí puedes extenderlo o volver a darlo, con motivo.
2. **¿El dominio está autorizado en Vimeo?** En el editor, el punto *Solo en el sitio* debe estar en verde. Si dice “Falta autorizar inteligenciaobjetiva.com”, pulsa *Aplicar configuración recomendada* o agrégalo a mano. Este es el caso más común: el reproductor muestra un aviso de que el video no se puede reproducir en este sitio.
3. **¿El enlace es el correcto?** Vuelve a pegar el enlace del video en el editor. Si Vimeo responde que no lo encuentra, el video pudo borrarse o pertenecer a otra cuenta.
4. **¿La cuenta sigue vinculada?** Abre *Cuenta de Vimeo*. Si dice que el token no funciona, genera uno nuevo con los pasos de arriba.
5. **¿Vimeo terminó de procesar el video?** Los videos recién subidos tardan unos minutos en estar disponibles.

Si después de esto sigue sin reproducirse, anota el contenido, el alumno y la hora, y revisa *Alertas* y *Bitácora* en el backoffice.

## Qué queda registrado

- Cada vez que alguien pulsa **Aplicar configuración recomendada**, la bitácora guarda quién lo hizo, el contenido, el motivo y la privacidad antes y después del cambio. Si Vimeo rechaza el cambio, no se registra nada, porque nada cambió.
- Los cambios en contenidos y en accesos también quedan en la bitácora, como siempre.

## Referencias técnicas

Las verificó el equipo técnico en la especificación oficial de la API de Vimeo (versión 3.4), en [github.com/vimeo/openapi](https://github.com/vimeo/openapi) (`api.yaml`), y en la [documentación de la API](https://developer.vimeo.com/api/reference):

- `GET /me`: datos de la cuenta del token.
- `GET /oauth/verify`: comprueba el token y lista sus permisos (`scope`).
- `GET /videos/{id}`: título (`name`), duración (`duration`), miniaturas (`pictures`) y privacidad (`privacy`).
- `PATCH /videos/{id}` (permiso `edit`): `privacy.view` = `disable` (*Ocultar de Vimeo*), `privacy.embed` = `whitelist` (solo dominios autorizados) y `privacy.download` = `false`.
- `GET /videos/{id}/privacy/domains` y `PUT /videos/{id}/privacy/domains/{dominio}` (permiso `edit`): consultar y agregar dominios autorizados.
- Permisos disponibles para tokens: `public`, `private`, `edit`, entre otros. La plataforma solo necesita esos tres.
- Sin token, el editor usa la consulta pública de Vimeo (`vimeo.com/api/oembed.json`). Para videos limitados a un dominio, la consulta envía el dominio del sitio, como pide Vimeo en [Usar oEmbed con videos privados](https://help.vimeo.com/hc/articles/12427906892689).
- Opciones de privacidad en la interfaz de Vimeo: [Cómo cambiar la privacidad de un video](https://help.vimeo.com/hc/en-us/articles/13806764653201).
- Según el centro de ayuda de Vimeo, al autorizar un dominio quedan incluidos sus subdominios.
