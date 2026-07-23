# VPNACIONAL · Expediente 360 y Registro de Asistentes V10

## Incluye
- Registro de asistentes por cédula y teléfono.
- Consulta del REP desde el servidor.
- Nombre autocompletado; edición manual solo para No REP.
- Snapshot JSON completo de todas las columnas devueltas por `electores`.
- Un solo expediente por cédula.
- Timeline automático por participación.
- Clasificación actual: ACTIVISTA o SIMPATIZANTE.
- ACTIVISTA solo cuando posee vinculación activa en Estructuras, Redes Populares o Centros de Votación.
- SIMPATIZANTE cuando no posee ninguna vinculación organizativa activa.
- Posibilidad de registrar más asistentes que la cantidad declarada.
- Dashboard responsive y ficha individual de Expediente 360°.
- Detección de dobles vinculaciones activas heredadas.

## Instalación
1. Respaldar archivos y base de datos.
2. Extraer el ZIP en `/public_html/vpnacional/`, combinando y reemplazando.
3. Abrir `/vpnacional/instalar_expediente360_v10.php`.
4. Pulsar **Aplicar actualización V10** una sola vez.
5. Abrir `/vpnacional/expediente360.php`.
6. Eliminar el instalador después de verificar.

## Regla organizativa
Una persona puede conservar un historial ilimitado de cargos y traslados, pero solo puede tener una vinculación organizativa activa. Los registros históricos dobles no se eliminan automáticamente: se colocan en la bandeja de inconsistencias para revisión.

## Seguridad
Los datos completos del REP no viajan como campos ocultos del formulario. PHP consulta `SELECT *` en el servidor y guarda el snapshot directamente en `persona_rep_snapshots`.
