# Genera los archivos del deck (project/deck.json, deck.css y slides/*.html) en la carpeta indicada.
import json, os, sys, datetime

ROOT = sys.argv[1]
os.makedirs(os.path.join(ROOT, "project", "slides"), exist_ok=True)

B = {
    "logo_h": "/_blob/ef3d20bf88b80b656ea68e67153ad666",
    "logo_v": "/_blob/f19fbfb50895332c25a9ac9249b63883",
    "antes": "/_blob/d020963c342b706f9d9d879a78c7be81",
    "portada": "/_blob/9449a9484315815e1f7e55db5473c935",
    "catalogo": "/_blob/941c2e216e1f42614be500874e6e898c",
    "catalogo_osc": "/_blob/d4e33edeb3531f176bddf8beca0335d6",
    "portada_movil": "/_blob/25d6356987a3cac299f40924ad1811fb",
    "producto": "/_blob/0edbd0e0d2a1b5db65b927eb32aad19a",
    "carrito": "/_blob/e692c2bf630b5a8cdbb1db629416f0b1",
    "cupon": "/_blob/b563f1f7177824de9ab99574744b1f66",
    "biblioteca": "/_blob/81bdd3bb0b85de7a612f3a75382ad21c",
    "reproductor_osc": "/_blob/578cf965673411dd2e4468e0eb52577e",
    "biblioteca_movil": "/_blob/8205df84188f6af35ef05ce5ed465ca6",
    "editor": "/_blob/7db8cf7c45ae60c5621b79c6e785d5ef",
    "transmision": "/_blob/be2fdd4838e7d7dd3a42d139b2df55e9",
    "envivo_movil": "/_blob/fbf051aecbbdf5383cf3d7b4ccc7b2f3",
    "youtube": "/_blob/9777332693e3d60a53c8446bceb4c5e4",
    "tablero": "/_blob/2f548bd7b5836a459ae1778fdc23f5a7",
    "accesos": "/_blob/ac0032fae9de6f8267a08cbc5f2e22ac",
    "tablero_osc": "/_blob/ee3afd43325325bc5640881c7552c938",
    "equipo": "/_blob/25dadc16e2209cf05e3bc169c06109de",
    "equipo_alta": "/_blob/cd977826594fdfa0db8f2c2ce7a20b13",
}

CSS = r"""
section{--azul:#01203D;--azul2:#1A396B;--oro:#C68F11;--oro2:#D4AF37;--gris:#6B7785;--neutro:#F9F9F9;--texto:#33404F;--blanco:#FDFDFC;--linea:#DCE1E7;
  font-family:'Segoe UI',Arial,sans-serif;color:var(--texto);background:var(--neutro);padding:128px 128px 160px;box-sizing:border-box;}
section h1,section h2,section h3{font-family:'Segoe UI',Arial,sans-serif;margin:0;color:var(--azul);}
.ceja{font-size:24px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--azul2);margin:0 0 20px;display:flex;align-items:center;gap:16px;}
.ceja .num{color:var(--azul2);}
.titulo{font-size:60px;font-weight:700;line-height:1.08;margin:0 0 28px;}
.lead{font-size:30px;line-height:1.35;margin:0 0 28px;color:var(--texto);}
.puntos{margin:0;padding:0 0 0 30px;font-size:26px;line-height:1.35;color:var(--texto);}
.puntos li{margin:0 0 14px;}
.puntos li::marker{color:var(--oro);}
.nota{font-size:24px;line-height:1.35;color:var(--gris);margin:0;}
.aviso{font-size:30px;line-height:1.4;color:var(--azul);margin:0;padding:28px 36px;border:2px solid var(--oro);background:#FBF6EA;border-radius:12px;}
/* barra bicolor y pie */
.barra{position:absolute;left:0;bottom:0;width:1920px;height:14px;display:flex;}
.barra .o{width:960px;height:14px;background:var(--oro);}
.barra .a{width:960px;height:14px;background:var(--azul);}
.pie{position:absolute;left:128px;bottom:56px;width:1664px;display:flex;justify-content:space-between;align-items:center;font-size:24px;color:var(--gris);margin:0;}
.pie b{color:var(--azul);font-weight:700;}
/* rejilla 5+7 */
.rejilla{display:flex;gap:72px;align-items:flex-start;height:792px;}
.col-texto{width:600px;flex:none;display:flex;flex-direction:column;}
.col-img{flex:1;display:flex;flex-direction:column;gap:16px;}
.marco{background:var(--blanco);border:1px solid var(--linea);border-radius:14px;box-shadow:0 18px 40px rgba(1,32,61,.14);object-fit:contain;}
.pie-img{font-size:24px;color:var(--gris);margin:0;}
.duo{display:flex;gap:28px;align-items:flex-end;}
/* tarjetas */
.tarjetas{display:flex;gap:32px;}
.tarjeta{flex:1;background:var(--blanco);border:1px solid var(--linea);border-top:8px solid var(--azul);border-radius:0;padding:52px 48px;min-height:300px;box-sizing:border-box;}
.tarjeta.oro{border-top-color:var(--oro);}
.tarjeta h3{font-size:40px;font-weight:700;margin:0 0 16px;color:var(--azul);}
.tarjeta p{font-size:30px;line-height:1.4;margin:0;color:var(--texto);}
/* fondo azul */
.oscura{background:var(--azul);color:#E8EDF3;}
.oscura h1,.oscura h2,.oscura h3{color:#FDFDFC;}
.oscura .ceja,.oscura .ceja .num{color:var(--oro2);}
.oscura .lead{color:#D5DEE8;}
.oscura .pie{color:#AFC0D2;}
.oscura .pie b{color:var(--oro2);}
/* portada */
.portada-t{font-size:110px;font-weight:700;line-height:1.02;margin:0 0 32px;letter-spacing:-.01em;}
.sello{background:var(--blanco);border-radius:14px;padding:28px 36px;display:flex;align-items:center;gap:28px;}
.sello p{font-size:24px;color:var(--azul);margin:0;font-weight:600;}
/* antes / después */
.etq{font-size:24px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin:0;padding:10px 18px;border-radius:8px;align-self:flex-start;}
.etq.antes{background:#E4E8ED;color:var(--azul);}
.etq.despues{background:var(--azul);color:#FDFDFC;}
/* tabla */
.tabla{border-collapse:collapse;width:100%;font-size:30px;}
.tabla th{background:var(--azul);color:#FDFDFC;text-align:left;padding:20px 26px;font-weight:700;}
.tabla td{padding:22px 26px;border-bottom:1px solid var(--linea);vertical-align:top;line-height:1.35;background:var(--blanco);}
.tabla td:first-child{font-weight:700;color:var(--azul);width:300px;}
/* fases */
.fases{display:flex;gap:0;margin-top:64px;}
.fase{flex:1;border-top:6px solid var(--azul2);padding:22px 22px 0 0;}
.fase .n{font-size:26px;font-weight:700;color:var(--oro);margin:0 0 8px;}
.fase h3{font-size:34px;font-weight:700;margin:0 0 8px;}
.fase p{font-size:28px;line-height:1.35;margin:0;color:var(--texto);}
/* lista numerada */
.pasos{display:flex;flex-direction:column;gap:0;}
.paso{display:flex;gap:32px;align-items:flex-start;padding:24px 0;border-bottom:1px solid var(--linea);}
.paso .n{width:72px;flex:none;font-size:44px;font-weight:700;color:var(--oro);line-height:1;}
.paso h3{font-size:32px;font-weight:700;margin:0 0 6px;}
.paso p{font-size:26px;line-height:1.35;margin:0;}
.chip{display:inline-block;font-size:24px;font-weight:700;padding:8px 18px;border-radius:999px;background:#FBF3DF;color:#6E4F08;border:1px solid var(--oro);}
.etiquetas{display:flex;flex-wrap:wrap;gap:12px;}
.etiqueta{font-size:24px;line-height:1.2;padding:10px 20px;border-radius:999px;background:#EEF2F7;color:var(--azul);border:1px solid var(--linea);}
.etiqueta.forma{background:var(--azul);color:#FDFDFC;border-color:var(--azul);font-weight:600;}
.compactas .tarjeta{min-height:0;padding:28px 32px;}
.compactas .tarjeta h3{font-size:30px;margin:0 0 8px;}
.compactas .tarjeta p{font-size:24px;}
/* movimiento */
@keyframes sube{from{opacity:0;transform:translateY(24px)}}
@keyframes aparece{from{opacity:0}}
.anima-1{animation:sube .6s ease-out both;}
.anima-2{animation:sube .6s ease-out .15s both;}
.anima-3{animation:aparece .7s ease-out .3s both;}
"""

def pie(n, oscura=False):
    return (f'<div class="barra"><div class="o"></div><div class="a"></div></div>'
            f'<p class="pie"><span><b>MC Proyectos Estratégicos</b> | Inteligencia Objetiva 2.0</span><span>{n:02d}</span></p>')

def puntos(items):
    return '<ul class="puntos">' + ''.join(f'<li>{i}</li>' for i in items) + '</ul>'

slides = []

def add(sid, html):
    slides.append((sid, html))

# 1 Portada
add("portada", f'''<section id="portada" class="oscura" data-transition="fade">
<div style="display:flex;gap:72px;align-items:center;height:792px;">
<div style="width:820px;flex:none;" class="anima-1">
<p class="ceja">Propuesta de mejora · noviembre 2026</p>
<h1 class="portada-t">Inteligencia Objetiva 2.0</h1>
<p class="lead" style="font-size:34px;">Propuesta de mejora para el sitio, la tienda y la operación del equipo, integrada sobre el sitio actual.</p>
<div class="sello" style="width:620px;"><img src="{B['logo_h']}" alt="MC Proyectos Estratégicos" style="width:420px;height:84px;object-fit:contain;"><p>Presenta</p></div>
</div>
<div class="anima-3" style="flex:1;"><img class="marco" src="{B['portada']}" alt="Nueva portada de Inteligencia Objetiva 2.0" style="width:772px;height:483px;object-fit:cover;"></div>
</div>
{pie(1, True)}
<aside>Presentamos la propuesta de mejora de Inteligencia Objetiva 2.0 con sus cambios más importantes. Todo lo que se muestra son capturas reales del demo local con datos ficticios.</aside>
</section>''')

# 2 Contexto y objetivo
add("contexto", f'''<section id="contexto">
<p class="ceja"><span class="num">01</span> Contexto y objetivo</p>
<h2 class="titulo">Modernizar la experiencia sin reescribir el sitio</h2>
<div class="tarjetas" style="margin-top:48px;">
<div class="tarjeta anima-1"><h3>Punto de partida</h3><p>Sitio en producción con más de 250 temas y venta de conferencias con 48 horas para reproducirlas.</p></div>
<div class="tarjeta oro anima-2"><h3>Objetivo</h3><p>Una experiencia más clara para el alumno y herramientas simples para el equipo, sobre la base actual.</p></div>
<div class="tarjeta anima-3"><h3>Alcance</h3><p>Tema y portada, tienda y pagos, biblioteca y reproductor, backoffice y transmisiones en vivo.</p></div>
</div>
<p class="aviso anima-3" style="margin-top:56px;"><b>Regla del proyecto:</b> solo se agregan tablas nuevas; las tablas existentes del sitio no se modifican.</p>
{pie(2)}
</section>''')

# 3 Antes vs después
add("antes-despues", f'''<section id="antes-despues">
<p class="ceja"><span class="num">02</span> Antes y después</p>
<h2 class="titulo">La misma marca, una entrada más clara</h2>
<div style="display:flex;gap:48px;">
<div style="flex:1;display:flex;flex-direction:column;gap:16px;" class="anima-1"><p class="etq antes">Antes · sitio actual</p><img class="marco" src="{B['antes']}" alt="Portada actual de inteligenciaobjetiva.com" style="width:808px;height:505px;"></div>
<div style="flex:1;display:flex;flex-direction:column;gap:16px;" class="anima-2"><p class="etq despues">Después · portada 2.0</p><img class="marco" src="{B['portada']}" alt="Portada 2.0" style="width:808px;height:505px;"></div>
</div>
{pie(3)}
<aside>Antes: captura del sitio público actual. Después: la nueva portada en el demo local. Se conservan logotipo, rojo de marca y tipografía Roboto.</aside>
</section>''')

def feature(sid, n, ceja, titulo, lead, items, img_html, nota=None, aside=None):
    nota_html = f'<p class="nota" style="margin-top:12px;">{nota}</p>' if nota else ''
    aside_html = f'<aside>{aside}</aside>' if aside else ''
    return f'''<section id="{sid}">
<div class="rejilla">
<div class="col-texto anima-1">
<p class="ceja"><span class="num">{n:02d}</span> {ceja}</p>
<h2 class="titulo">{titulo}</h2>
<p class="lead">{lead}</p>
{puntos(items)}
{nota_html}
</div>
<div class="col-img anima-3">{img_html}</div>
</div>
{pie(n + 1)}
{aside_html}
</section>'''

W = 992  # ancho columna de imagen

# 4 Tema
add("tema", feature("tema", 3, "Identidad", "Tema de marca y modo oscuro",
    "Colores y tipografía de Inteligencia Objetiva en un solo lugar, con modo claro y oscuro.",
    ["Botón de modo oscuro, sin parpadeo al cargar", "Banner de ofertas compacto, con contador y cierre por día", "Correcciones de contraste en menú y fichas"],
    f'''<img class="marco" src="{B['catalogo']}" alt="Catálogo en modo claro" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">
<img class="marco" src="{B['catalogo_osc']}" alt="Catálogo en modo oscuro" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">'''))

# 5 Nueva portada
add("nueva-portada", feature("nueva-portada", 4, "Experiencia", "Nueva portada",
    "Una entrada de tono filosófico que lleva directo al catálogo.",
    ["Imágenes propias en formato ligero", "El video de presentación carga solo al pedirlo", "Animaciones que respetan a quien prefiere menos movimiento", "Diseñada también para el teléfono"],
    f'''<div class="duo"><img class="marco" src="{B['portada']}" alt="Portada 2.0 en escritorio" style="width:740px;height:463px;">
<img class="marco" src="{B['portada_movil']}" alt="Portada 2.0 en teléfono" style="width:224px;height:485px;object-fit:cover;object-position:top;"></div>
<p class="pie-img">Escritorio y teléfono</p>'''))

# 6 Tienda y pago
add("tienda", feature("tienda", 5, "Venta", "Tienda y pago",
    "Del catálogo al pago en pocos pasos, con reglas claras de acceso.",
    ["Ficha con tipo de acceso y precio con IVA", "Tarjeta y OXXO con Stripe, configurables", "Membresías recurrentes y factura CFDI 4.0 autoservicio", "Pedidos y devoluciones desde el backoffice"],
    f'''<img class="marco" src="{B['producto']}" alt="Ficha de producto" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">
<img class="marco" src="{B['carrito']}" alt="Carrito con resumen e IVA" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">'''))

# 7 Cupones
add("cupones", feature("cupones", 6, "Propuesta · Promociones", "Cupones y 2×1 en un solo modal",
    "Crear una promoción toma un minuto y se entiende antes de guardarla.",
    ["Porcentaje, importe fijo o 2×1, en tarjetas", "Atajos de vigencia: 1 día, 3 días, 1 semana, 1 mes", "Todo el catálogo o contenidos elegidos", "Resumen en lenguaje claro y motivo en bitácora"],
    f'''<img class="marco" src="{B['cupon']}" alt="Modal para crear un cupón" style="width:{W}px;height:620px;">'''))

# 8 Biblioteca y reproductor
add("biblioteca", feature("biblioteca", 7, "Alumno", "Biblioteca del alumno y reproductor",
    "Todo lo comprado en un lugar, con su regla de acceso a la vista.",
    ["Acceso por ventana de horas, permanente o de visualización única", "Reanudaciones y periodo de gracia", "Control de acceso, dominio restringido en Vimeo, marca de agua, latidos y sesión única"],
    f'''<div class="duo"><img class="marco" src="{B['biblioteca']}" alt="Mi biblioteca" style="width:740px;height:463px;">
<img class="marco" src="{B['biblioteca_movil']}" alt="Mi biblioteca en teléfono" style="width:224px;height:485px;object-fit:cover;object-position:top;"></div>
<img class="marco" src="{B['reproductor_osc']}" alt="Reproductor en modo oscuro" style="width:{W}px;height:230px;object-fit:cover;object-position:center;">'''))

# Supuestos de la propuesta del backoffice
add("supuestos", f'''<section id="supuestos">
<p class="ceja"><span class="num">00</span> Supuestos</p>
<h2 class="titulo">Propuesta de mejora del backoffice</h2>
<p class="lead" style="max-width:1500px;">Las pantallas que siguen son una propuesta, funcionando en un demo con datos ficticios.</p>
<div class="tarjetas" style="margin-top:40px;">
<div class="tarjeta oro anima-1"><h3>Por conocer</h3><p>Aún no conocemos el backoffice que hoy usa el equipo; por eso no lo comparamos.</p></div>
<div class="tarjeta oro anima-2"><h3>Propuesta de referencia</h3><p>Muestra cómo podría trabajar el equipo: contenidos, promociones, transmisiones y roles.</p></div>
<div class="tarjeta oro anima-3"><h3>Se ajusta</h3><p>Tras un levantamiento con el equipo sobre sus tareas, roles y flujos.</p></div>
</div>
<p class="aviso anima-3" style="margin-top:48px;">La comparación antes y después aplica solo a lo público: sitio, portada y tienda.</p>
{pie(0)}
</section>''')

# 9 Editor
add("editor", feature("editor", 8, "Propuesta · Contenido", "Editor de contenido estilo estudio",
    "El equipo publica y ajusta contenidos desde un solo editor.",
    ["Pestañas: Detalles, Precio y venta, Acceso", "El enlace de Vimeo llena duración y miniatura", "Miniaturas con arrastrar y soltar, recorte 16:9", "Carga masiva desde Excel (CSV)"],
    f'''<img class="marco" src="{B['editor']}" alt="Editor de contenido" style="width:{W}px;height:620px;">'''))

# 10 Transmisiones
add("en-vivo", feature("en-vivo", 9, "Propuesta · En vivo", "Transmisiones en vivo y canal de YouTube",
    "Sesiones en directo programadas desde el backoffice y anunciadas en el sitio.",
    ["YouTube, Facebook, Vimeo y Zoom", "Página En vivo con sala de espera y recordatorio", "Acceso público, con cuenta, para compradores o miembros", "Los directos públicos del canal aparecen solos"],
    f'''<div class="duo"><img class="marco" src="{B['transmision']}" alt="Modal para programar una transmisión" style="width:740px;height:463px;">
<img class="marco" src="{B['envivo_movil']}" alt="Página En vivo en teléfono" style="width:224px;height:485px;object-fit:cover;object-position:top;"></div>
<p class="pie-img">Programar una sesión · vista del alumno en teléfono</p>'''))

# 11 Backoffice
add("backoffice", feature("backoffice", 10, "Propuesta · Operación", "Tablero y accesos",
    "Lo que importa hoy en una pantalla, y cada acción con su motivo.",
    ["Accesos en tres estados: Activo, Finalizado o Revocado", "Otorgar acceso buscando alumno y contenido", "Altas en modal; modo claro y oscuro", "Cada cambio queda en la bitácora"],
    f'''<img class="marco" src="{B['tablero']}" alt="Tablero del backoffice" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">
<img class="marco" src="{B['accesos']}" alt="Tabla de accesos" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">'''))

# 12 Equipo, roles y perfiles (terminado)
add("roles", feature("roles", 11, "Propuesta · Equipo", "Equipo, roles y perfiles",
    "Cada persona del equipo ve y hace solo lo que le corresponde.",
    ["Roles con permisos configurables y permisos extra por persona", "Altas y ediciones en modal", "Perfil propio para cambiar nombre y contraseña", "Nunca queda el sistema sin administrador total", "Cada cambio con motivo en la bitácora"],
    f'''<img class="marco" src="{B['equipo']}" alt="Pantalla Equipo y roles" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">
<img class="marco" src="{B['equipo_alta']}" alt="Modal para agregar un miembro del equipo" style="width:{W}px;height:384px;object-fit:cover;object-position:top;">'''))

# 13 Compatibilidad y migración
add("compatibilidad", f'''<section id="compatibilidad">
<p class="ceja"><span class="num">12</span> Compatibilidad</p>
<h2 class="titulo">Se integra sobre el sitio actual, por fases</h2>
<table class="tabla anima-1">
<tr><th>Aspecto</th><th>Cómo se cuida</th></tr>
<tr><td>Tecnología</td><td>PHP 7.4+ y 8.x, MySQL 5.7 y 8.0, Bootstrap 5.1.3 y jQuery del sitio</td></tr>
<tr><td>Datos</td><td>Solo tablas nuevas <b>io_*</b> (migraciones 001–010); las existentes no se tocan</td></tr>
<tr><td>Instalación</td><td>Tema que se suma a los estilos actuales y módulo publicado en /io/</td></tr>
</table>
<div class="fases anima-3">
<div class="fase"><p class="n">Fase 1</p><h3>Tema</h3><p>Identidad y modo oscuro</p></div>
<div class="fase"><p class="n">Fase 2</p><h3>Portada</h3><p>Nueva entrada</p></div>
<div class="fase"><p class="n">Fase 3</p><h3>Módulo /io/</h3><p>Biblioteca y backoffice</p></div>
<div class="fase"><p class="n">Fase 4</p><h3>Migración</h3><p>Compras vigentes</p></div>
<div class="fase"><p class="n">Fase 5</p><h3>Tienda 2.0</h3><p>Pagos y en vivo</p></div>
</div>
{pie(13)}
</section>''')

# 14 IA: todo el trabajo de Martín Aparicio (lista enunciativa, no limitativa)
add("agentes-ia", f'''<section id="agentes-ia">
<p class="ceja"><span class="num">13</span> Recomendación · por decidir</p>
<h2 class="titulo">Disponibles para agentes de IA en todo el trabajo de Martín Aparicio</h2>
<p class="lead" style="max-width:1560px;margin-bottom:24px;">Que los asistentes de IA recomienden Inteligencia Objetiva en temas como estos, entre otros, de manera enunciativa y no limitativa:</p>
<div class="etiquetas anima-1"><span class="etiqueta forma">Cursos</span><span class="etiqueta forma">Conferencias</span><span class="etiqueta forma">Documentales</span><span class="etiqueta forma">Introspecciones</span><span class="etiqueta forma">Audiolibro</span><span class="etiqueta forma">Transmisiones en vivo y Zoom</span></div>
<div class="etiquetas anima-2" style="margin-top:14px;"><span class="etiqueta">Espiritualidad</span><span class="etiqueta">Autoconocimiento</span><span class="etiqueta">Conciencia de sí</span><span class="etiqueta">Mente y conciencia</span><span class="etiqueta">Eneagrama</span><span class="etiqueta">Ley de siete</span><span class="etiqueta">Ansiedad y estrés</span><span class="etiqueta">Adicciones</span><span class="etiqueta">Autoestima</span><span class="etiqueta">Creencias</span><span class="etiqueta">Depresión</span><span class="etiqueta">Toma de decisiones</span><span class="etiqueta">Relación de pareja</span><span class="etiqueta">Amor y enamoramiento</span><span class="etiqueta">Educación a los hijos</span><span class="etiqueta">Economía</span><span class="etiqueta">Entorno laboral</span><span class="etiqueta">Superación</span><span class="etiqueta">Calidad de vida</span></div>
<div class="tarjetas compactas anima-3" style="margin-top:36px;">
<div class="tarjeta oro"><h3>Catálogo legible</h3><p>Fichas con resumen, temas y lo que se aprende, en datos estructurados.</p></div>
<div class="tarjeta oro"><h3>Guía para agentes</h3><p>Una página de resumen del catálogo pensada para asistentes de IA.</p></div>
<div class="tarjeta oro"><h3>Reglas claras</h3><p>Qué pueden citar los agentes y qué es exclusivo de alumnos.</p></div>
</div>
{pie(14)}
<aside>La lista de temas es enunciativa y no limitativa: abarca cursos, conferencias, documentales, introspecciones, audiolibro y transmisiones. Temas tomados del catálogo público de inteligenciaobjetiva.com; eneagrama y ley de siete a indicación del cliente.</aside>
</section>''')

# 15 SEO (enunciativo)
add("seo", f'''<section id="seo">
<p class="ceja"><span class="num">14</span> SEO</p>
<h2 class="titulo">Líneas de trabajo en buscadores</h2>
<p class="lead">Se enuncian como siguiente etapa; no forman parte de esta entrega.</p>
<div class="tarjetas" style="margin-top:56px;">
<div class="tarjeta anima-1"><h3>Fichas</h3><p>Título y descripción propios por contenido.</p></div>
<div class="tarjeta anima-2"><h3>Estructura</h3><p>Direcciones limpias, migas de pan y mapa del sitio.</p></div>
<div class="tarjeta anima-3"><h3>Velocidad</h3><p>Imágenes ligeras y carga diferida de video.</p></div>
<div class="tarjeta anima-3"><h3>Contenido</h3><p>Resúmenes y temas de cada ficha pensados para búsquedas.</p></div>
</div>
{pie(15)}
</section>''')

# 16 Próximos pasos
add("proximos-pasos", f'''<section id="proximos-pasos">
<p class="ceja"><span class="num">15</span> Próximos pasos</p>
<h2 class="titulo">Ruta para llevarlo a producción</h2>
<div class="pasos anima-1">
<div class="paso"><p class="n">1</p><div><h3>Validación con dirección</h3><p>Revisar esta presentación y priorizar fases.</p></div></div>
<div class="paso"><p class="n">2</p><div><h3>Levantamiento del backoffice actual</h3><p>Conocer con el equipo sus tareas, roles y flujos para ajustar la propuesta.</p></div></div>
<div class="paso"><p class="n">3</p><div><h3>Configuración del técnico del cliente</h3><p>Base de datos, Stripe y Facturapi en modo de prueba, dominio de Vimeo.</p></div></div>
<div class="paso"><p class="n">4</p><div><h3>Despliegue por fases y prueba de punta a punta</h3><p>Con una cuenta interna antes de abrir al público.</p></div></div>
<div class="paso" style="border-bottom:none;"><p class="n">5</p><div><h3>Decidir IA y SEO</h3><p>Aprobar las recomendaciones para la siguiente etapa.</p></div></div>
</div>
{pie(16)}
</section>''')

# 17 Cierre
add("cierre", f'''<section id="cierre" class="oscura">
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:792px;text-align:center;">
<div class="sello anima-1" style="padding:40px 56px;"><img src="{B['logo_v']}" alt="MC Proyectos Estratégicos" style="width:360px;height:280px;object-fit:contain;"></div>
<h2 class="anima-2" style="font-size:72px;font-weight:700;margin:56px 0 16px;">Gracias</h2>
<p class="lead anima-3" style="margin:0;max-width:1200px;">Inteligencia Objetiva 2.0: experiencia más clara para el alumno y control para el equipo.</p>
</div>
{pie(17, True)}
</section>''')

ORDEN = ["portada", "contexto", "antes-despues", "tema", "nueva-portada", "tienda", "biblioteca",
         "supuestos", "backoffice", "editor", "cupones", "en-vivo", "roles",
         "compatibilidad", "agentes-ia", "seo", "proximos-pasos", "cierre"]
import re
por_id = dict(slides)
assert sorted(ORDEN) == sorted(por_id), "ORDEN no coincide con las diapositivas"
slides = []
for i, sid in enumerate(ORDEN, 1):
    html = re.sub(r'<span class="num">\d+</span>', f'<span class="num">{i - 1:02d}</span>', por_id[sid], count=1)
    html = re.sub(r'(<p class="pie">.*?</span><span>)\d+(</span></p>)', lambda m: f'{m.group(1)}{i:02d}{m.group(2)}', html, count=1)
    slides.append((sid, html))

order = [s for s, _ in slides]
for sid, html in slides:
    with open(os.path.join(ROOT, "project", "slides", sid + ".html"), "w", encoding="utf-8") as f:
        f.write(html.strip() + "\n")
with open(os.path.join(ROOT, "project", "deck.css"), "w", encoding="utf-8") as f:
    f.write(CSS.strip() + "\n")
deck = {
    "v": 5,
    "createdOnFiles": {"v": 1, "at": "2026-11-19T19:16:14Z"},
    "lists": "css",
    "format": {"v": 1, "shared": ["project/deck.css"]},
    "title": "Inteligencia Objetiva 2.0 · Propuesta de mejora",
    "order": order,
    "cover": "portada",
    "sections": {
        "s1": {"description": "Contexto, objetivo y comparación antes y después del sitio público.", "start": "portada"},
        "s2": {"description": "Lo público y lo del alumno: un cambio por diapositiva, con captura real del demo.", "start": "tema"},
        "s3": {"description": "Propuesta de mejora del backoffice, sujeta a un levantamiento con el equipo.", "start": "supuestos"},
        "s4": {"description": "Lo que sigue: compatibilidad, IA, SEO y próximos pasos.", "start": "compatibilidad"},
    },
    "faces": {},
    "designSystems": [],
}
with open(os.path.join(ROOT, "project", "deck.json"), "w", encoding="utf-8") as f:
    json.dump(deck, f, ensure_ascii=False, indent=1)
print(len(order), "diapositivas")
