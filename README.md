# Inteligencia Objetiva 2.0

Actualización del sitio de Inteligencia Objetiva sobre PHP y MySQL. Incluye la nueva portada y tienda, biblioteca del alumno y un panel de administración propuesto.

- [Demo interactiva pública](https://inteligencia-objetiva-2-demo.espacio-de-l-4242.chatgpt.site/recorrido) con datos ficticios.
- [Guía de despliegue](GUIA-DESPLIEGUE.md) e [inventario de integraciones](docs/inventario-integraciones.md).

## Instalación

Configura el servidor, la base de datos y la sesión según la guía. Completa la aceptación en staging con las cuentas de prueba del cliente antes de publicar.

Para generar un ZIP de instalación en una carpeta de entregables independiente, prepara la instancia local de MySQL de pruebas indicada en la guía y ejecuta desde este checkout:

```powershell
python modulo-visualizacion/bin/empaquetar.py --salida ../entregables/paquetes
```

## Agente de integración

El [inventario de integraciones](docs/inventario-integraciones.md) cubre identidad, perfiles, roles, catálogo, accesos, pasarela, operación y proveedores. Cada ficha incluye el punto de adaptación, las pruebas y la reversión.

En GitHub Copilot, selecciona **Integración de Inteligencia Objetiva** para pedir una explicación de un módulo o un plan de adaptación. También puedes pedir al agente principal que consulte este perfil y use sus hallazgos antes de editar. Para otros agentes, comparte [AGENTS.md](AGENTS.md) y el [perfil de integración](.github/agents/integracion-io.agent.md).

Ejemplo: «Revisa las 25 integraciones contra el checkout original. Entrega al agente implementador una matriz con responsables, dependencias, pruebas en staging y pasos para revertir cada fase».
