# VPNACIONAL · Expediente 360 V11

Esta versión trabaja sobre los módulos existentes:

- `activistas.php`
- `activista_detalle.php`
- `actividad_asistencia.php`

## Incluye

- Responsive móvil con la línea visual azul oscuro, blanco y naranja de Actividades.
- Tabla convertida en fichas móviles.
- Pestañas horizontales y tarjetas en una sola columna en teléfonos.
- Clasificación automática `ACTIVISTA` / `SIMPATIZANTE`.
- Activista únicamente con vinculación activa en Estructuras, Redes Populares o Centros de Votación.
- Una sola vinculación organizativa activa por cédula.
- Detección de inconsistencias antiguas sin eliminar registros.
- Registro de asistentes con consulta REP, snapshot completo y trazabilidad.
- Cantidad declarada superable con asistentes adicionales.
- Menú enlazado a `activistas.php` como Expediente 360°.

## Instalación

1. Respaldar archivos y base de datos.
2. Extraer el ZIP dentro de `/public_html/vpnacional/` combinando archivos.
3. Abrir `instalar_expediente360_v11.php`.
4. Pulsar **Aplicar corrección V11** una vez.
5. Probar `activistas.php`, `activista_detalle.php?cedula=...` y `actividad_asistencia.php?id=...`.
6. Recargar con Ctrl + F5.
7. Eliminar el instalador después de verificar.

El instalador crea respaldos de los PHP modificados en `/backups/expediente360_v11_FECHA_HORA/`.
