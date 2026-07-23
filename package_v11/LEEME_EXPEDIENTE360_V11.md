# VPNACIONAL · Expediente 360° V11

Corrección acumulativa que trabaja directamente sobre los módulos existentes:

- `activistas.php`
- `activista_detalle.php`
- `actividad_asistencia.php`

No crea un dashboard paralelo.

## Funciones

- Responsive móvil con la línea visual del módulo Actividades.
- Dashboard único de personas por cédula.
- Clasificación **ACTIVISTA** cuando existe una vinculación activa en Estructuras, Redes Populares o equipos de Centros de Votación.
- Clasificación **SIMPATIZANTE** cuando no existe ninguna vinculación organizativa activa.
- Una sola vinculación organizativa activa por persona.
- Historial ilimitado de cargos, retiros y traslados.
- Registro de asistentes por cédula y teléfono.
- Consulta del REP en el servidor.
- Almacenamiento del registro completo de `electores` en JSON, sin campos ocultos manipulables.
- Timeline automático por participación en actividades.
- Registro de asistentes adicionales por encima de la cantidad declarada.
- Detección de inconsistencias heredadas.

## Instalación

1. Respalda los archivos y la base de datos.
2. Extrae el ZIP dentro de `/public_html/vpnacional/`, combinando los archivos.
3. Abre `/vpnacional/instalar_expediente360_v11.php`.
4. Pulsa **Aplicar corrección V11** una sola vez.
5. Abre `/vpnacional/activistas.php`.
6. Prueba una actividad desde `/vpnacional/actividad_asistencia.php?id=ID`.
7. En móvil realiza una recarga completa o borra la caché del navegador.
8. Elimina `instalar_expediente360_v11.php` cuando termines de verificar.

El instalador crea una copia de los PHP modificados dentro de `backups/expediente360_v11_FECHA/`.

## Nota sobre vinculaciones múltiples existentes

Los casos anteriores donde una cédula aparece activa en más de una instancia no se eliminan automáticamente. Se registran en `expediente360_inconsistencias`. Las nuevas asignaciones quedan protegidas por la regla de una sola vinculación activa.
