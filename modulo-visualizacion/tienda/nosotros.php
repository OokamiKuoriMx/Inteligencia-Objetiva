<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_migas.php';
encabezado('Nosotros', 'nosotros', 'Inteligencia Objetiva: más de 20 años de investigación sobre la mente y el autoconocimiento, fundada por Martín Aparicio.');
?>
<section class="iot-nos-hero">
  <div class="container">
    <?= migas([['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'Nosotros']]) ?>
    <span class="eyebrow">Una mirada al interior</span>
    <h1>Nosotros</h1>
    <p>Inteligencia Objetiva nace de más de dos décadas de investigación sobre la mente y el autoconocimiento, enriquecida con el diálogo con especialistas en filosofía, antropología, sociología y neurología.</p>
    <p>Su propósito es mejorar la vida de las personas con contenidos de conciencia y desarrollo personal que hoy llegan por Televisión Objetiva, televisión abierta, radio, este sitio y redes sociales.</p>
    <div class="iot-cifras">
      <div><b>20+</b><span>años de investigación</span></div>
      <div><b>250+</b><span>temas en línea</span></div>
      <div><b>4</b><span>caminos de estudio</span></div>
      <div><b>En vivo</b><span>conferencias vía Zoom</span></div>
    </div>
  </div>
</section>

<section class="iot-fundador">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-lg-5"><img src="/img/resource/7.png" alt="Martín Aparicio" onerror="this.onerror=null;this.src='/portada/img/introspecciones.webp'"></div>
      <div class="col-lg-7">
        <span class="eyebrow">Fundador</span>
        <h2>Martín Aparicio</h2>
        <div class="rol">Filósofo, conferencista, comunicador, periodista, investigador y escritor</div>
        <p>Lleva más de veinte años estudiando la mente y la conciencia de uno mismo. Su trabajo parte de una preocupación concreta por los problemas que más duelen en la sociedad: la desintegración familiar, las adicciones, la pobreza, el estrés laboral, la delincuencia y las dificultades en la pareja.</p>
        <p>Su conclusión es que buena parte de esos problemas tiene un origen común: perder el control de las emociones. El sistema de Inteligencia Objetiva busca que las decisiones dejen de tomarse desde la emoción y empiecen a tomarse desde la conciencia, para mejorar el autoconocimiento, la educación de los hijos, las relaciones y la economía de cada persona.</p>
        <div class="io2-que mt-4 p-0" style="background:none">
          <div class="video" data-vimeo="270546544" role="button" tabindex="0" aria-label="Ver la trayectoria de Martín Aparicio">
            <img src="/portada/img/conferencias.webp" alt="" loading="lazy">
            <span class="play"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="io2-voces">
  <div class="container">
    <div class="text-center mb-5"><span class="eyebrow">Testimonios</span><h2>Lo que cambia cuando comprendes</h2></div>
    <div class="row g-4">
      <div class="col-md-4"><div class="io2-voz"><p>Inteligencia Objetiva ha sido la pieza del rompecabezas que anduve buscando a través de mi experiencia de vida.</p><div class="quien"><span class="ini">LG</span><div><b>Liseeth García</b><span>Canadá</span></div></div></div></div>
      <div class="col-md-4"><div class="io2-voz"><p>Estoy entrenando mi mente para observar cada pensamiento e identificar errores que he cometido, y corregirlos con más velocidad.</p><div class="quien"><span class="ini">VM</span><div><b>Vera Tatiana Muñoz</b><span>Panamá</span></div></div></div></div>
      <div class="col-md-4"><div class="io2-voz"><p>Hacía mucho tiempo que buscaba cómo vivir mi vida y darle sentido. Entender el porqué.</p><div class="quien"><span class="ini">JF</span><div><b>Jordi Felip</b><span>España</span></div></div></div></div>
    </div>
  </div>
</section>

<section class="io2-cierre">
  <div class="container">
    <h2>Y tú, <em>¿qué buscas ser?</em></h2>
    <p class="lead-io mx-auto mb-4" style="max-width:32em">Empieza por el tema que hoy más te importa.</p>
    <a class="btn-io2 primario" href="<?= e(ruta('catalogo')) ?>">Explorar el catálogo</a>
  </div>
</section>
<?php pie();
