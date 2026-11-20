# Inteligencia Objetiva 2.0

Actualización del sitio de Inteligencia Objetiva sobre PHP y MySQL. Incluye la nueva portada y tienda, biblioteca del alumno y un panel de administración propuesto.

- [Demo interactiva pública](https://inteligencia-objetiva-2-demo.espacio-de-l-4242.chatgpt.site/recorrido) con datos ficticios.
- [Mejoras de la versión 2.0](docs/lista-de-mejoras-io-2.0.md) y [revisión técnica](docs/informe-tecnico-io-2.0.md).
- [Entrega para configuración](ENTREGA-CLIENTE.md) y [guía de despliegue](GUIA-DESPLIEGUE.md).

## Instalación

El paquete ZIP y su suma SHA-256 están en la [release de entrega](https://github.com/OokamiKuoriMx/Inteligencia-Objetiva/releases/tag/io2-entrega-20261119). La instalación requiere configurar el servidor, la base de datos y la sesión del sitio. La aceptación con las cuentas de prueba del cliente se hace en staging antes de publicar.

## Agente de integración

En GitHub Copilot, selecciona **Integración de Inteligencia Objetiva** para pedir una explicación de un módulo o un plan de adaptación. También puedes pedir al agente principal que consulte este perfil y use sus hallazgos antes de editar. Para otros agentes, comparte [AGENTS.md](AGENTS.md) y el [perfil de integración](.github/agents/integracion-io.agent.md).

Ejemplo: «Explica cómo enlazar la biblioteca con el perfil actual y deja al agente implementador un plan con pruebas en staging y pasos para revertir el cambio».
