<?php
session_start();
$root = __DIR__;
foreach (array(
    $root . '/config/config.php',
    $root . '/config.php',
    $root . '/includes/bootstrap.php',
    $root . '/includes/config.php',
    $root . '/functions.php'
) as $bootstrap) {
    if (is_file($bootstrap)) {
        try { require_once $bootstrap; } catch (Throwable $e) {}
    }
}
require_once $root . '/includes/expediente360_v11.php';
$pdo = e360v11_db();
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    exit('No fue posible iniciar la conexión PDO.');
}

$done = array();
$warnings = array();
$errors = array();

function e360v11_step(PDO $pdo, $sql, $label, &$done, &$errors) {
    try {
        $pdo->exec($sql);
        $done[] = $label;
        return true;
    } catch (Throwable $e) {
        $errors[] = $label . ': ' . $e->getMessage();
        return false;
    }
}

function e360v11_add_column(PDO $pdo, $table, $column, $definition, &$done, &$errors) {
    if (!e360v11_table_exists($pdo, $table)) return;
    if (e360v11_has_column($pdo, $table, $column)) return;
    e360v11_step($pdo, 'ALTER TABLE ' . e360v11_ident($table) . ' ADD COLUMN ' . e360v11_ident($column) . ' ' . $definition, 'Columna ' . $table . '.' . $column, $done, $errors);
}

function e360v11_backup_and_patch($root, $relative, $backupDir, &$done, &$warnings) {
    $file = $root . '/' . $relative;
    if (!is_file($file)) {
        $warnings[] = 'No se encontró ' . $relative . '. No fue modificado.';
        return;
    }
    $content = file_get_contents($file);
    if ($content === false) {
        $warnings[] = 'No se pudo leer ' . $relative . '.';
        return;
    }
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
    @copy($file, $backupDir . '/' . basename($file));
    $css = '<link rel="stylesheet" href="assets/css/expediente360_v11.css?v=2026.07.23.11">';
    $js = '<script src="assets/js/expediente360_v11.js?v=2026.07.23.11" defer></script>';
    if (strpos($content, 'expediente360_v11.css') === false) {
        $pos = stripos($content, '</head>');
        $content = $pos !== false ? substr($content, 0, $pos) . "\n" . $css . "\n" . substr($content, $pos) : $css . "\n" . $content;
    }
    if (strpos($content, 'expediente360_v11.js') === false) {
        $pos = strripos($content, '</body>');
        $content = $pos !== false ? substr($content, 0, $pos) . "\n" . $js . "\n" . substr($content, $pos) : $content . "\n" . $js . "\n";
    }
    if (@file_put_contents($file, $content) !== false) $done[] = 'Integración aplicada en ' . $relative;
    else $warnings[] = 'No fue posible escribir ' . $relative . '. Revisa permisos.';
}

function e360v11_patch_menu($root, $backupDir, &$done, &$warnings) {
    $candidates = array();
    foreach (array($root . '/includes', $root . '/partials', $root . '/layout', $root . '/templates', $root) as $dir) {
        if (!is_dir($dir)) continue;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
                $name = strtolower($file->getFilename());
                if (!preg_match('/(sidebar|menu|nav|layout|header)/', $name)) continue;
                if ($file->getSize() > 1000000) continue;
                $candidates[$file->getPathname()] = true;
            }
        } catch (Throwable $e) {}
    }
    foreach (array_keys($candidates) as $file) {
        $content = @file_get_contents($file);
        if ($content === false || stripos($content, 'activistas.php') === false) continue;
        $original = $content;
        $content = preg_replace_callback('/<a\b[^>]*href=["\'][^"\']*activistas\.php[^"\']*["\'][^>]*>.*?<\/a>/is', function($match) {
            return preg_replace('/Activistas(?:\s+e\s+Influencia)?/iu', 'Expediente 360°', $match[0]);
        }, $content);
        $content = preg_replace('/<a\b[^>]*href=["\'][^"\']*expediente360\.php[^"\']*["\'][^>]*>.*?<\/a>/is', '', $content);
        if ($content !== $original) {
            if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
            @copy($file, $backupDir . '/menu_' . basename($file));
            if (@file_put_contents($file, $content) !== false) $done[] = 'Menú actualizado en ' . str_replace($root . '/', '', $file);
            else $warnings[] = 'No fue posible actualizar el menú en ' . $file;
        }
    }
}

function e360v11_archive_wrong_v10($root, &$done) {
    foreach (array('expediente360.php','expediente360_detalle.php') as $name) {
        $file = $root . '/' . $name;
        if (!is_file($file)) continue;
        $content = @file_get_contents($file);
        if ($content === false) continue;
        if (strpos($content, 'Una persona · una cédula') === false && strpos($content, 'expediente360_helpers.php') === false) continue;
        $target = $file . '.obsoleto_v10';
        if (@rename($file, $target)) $done[] = $name . ' V10 archivado; se conserva el módulo real activistas.php.';
    }
}

function e360v11_source_new_active($cols) {
    $parts = array();
    if (isset($cols['activo'])) $parts[] = 'COALESCE(NEW.`activo`,1)=1';
    if (isset($cols['estatus'])) $parts[] = "UPPER(COALESCE(NEW.`estatus`,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE','ELIMINADO')";
    if (isset($cols['estado_registro'])) $parts[] = "UPPER(COALESCE(NEW.`estado_registro`,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE','ELIMINADO')";
    return $parts ? implode(' AND ', $parts) : '1=1';
}

function e360v11_source_old_active($cols) {
    $parts = array();
    if (isset($cols['activo'])) $parts[] = 'COALESCE(OLD.`activo`,1)=1';
    if (isset($cols['estatus'])) $parts[] = "UPPER(COALESCE(OLD.`estatus`,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE','ELIMINADO')";
    if (isset($cols['estado_registro'])) $parts[] = "UPPER(COALESCE(OLD.`estado_registro`,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE','ELIMINADO')";
    return $parts ? implode(' AND ', $parts) : '1=1';
}

function e360v11_new_value($cols, $candidates, $default='NULL') {
    foreach ($candidates as $candidate) if (isset($cols[$candidate])) return 'NEW.' . e360v11_ident($candidate);
    return $default;
}

function e360v11_old_value($cols, $candidates, $default='NULL') {
    foreach ($candidates as $candidate) if (isset($cols[$candidate])) return 'OLD.' . e360v11_ident($candidate);
    return $default;
}

function e360v11_create_guard_triggers(PDO $pdo, array $source, &$done, &$warnings) {
    $table = $source['table'];
    $type = $source['tipo'];
    $cols = $source['cols'];
    $cedulaCol = $source['cedula'];
    if (!isset($cols['id']) || !isset($cols[$cedulaCol])) return;
    $hash = substr(md5($table), 0, 8);
    $names = array('bi'=>'e360_bi_'.$hash,'bu'=>'e360_bu_'.$hash,'ai'=>'e360_ai_'.$hash,'au'=>'e360_au_'.$hash,'ad'=>'e360_ad_'.$hash);
    foreach ($names as $name) {
        try { $pdo->exec('DROP TRIGGER IF EXISTS ' . e360v11_ident($name)); } catch (Throwable $e) {}
    }
    $newActive = e360v11_source_new_active($cols);
    $oldActive = e360v11_source_old_active($cols);
    $newCed = 'CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(NEW.' . e360v11_ident($cedulaCol) . "),'.',''),'-',''),'V',''),'E','') AS UNSIGNED)";
    $oldCed = 'CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(OLD.' . e360v11_ident($cedulaCol) . "),'.',''),'-',''),'V',''),'E','') AS UNSIGNED)";
    $newName = e360v11_new_value($cols, array('nombre','nombre_completo'), "''");
    $newPhone = e360v11_new_value($cols, array('telefono','telefono_principal'), "''");
    $newCargo = e360v11_new_value($cols, array('cargo','cargo_nombre','responsabilidad','cargo_id','rol'), "''");
    $newEstado = e360v11_new_value($cols, array('estado'), 'NULL');
    $newMunicipio = e360v11_new_value($cols, array('municipio'), 'NULL');
    $newParroquia = e360v11_new_value($cols, array('parroquia'), 'NULL');
    $oldCargo = e360v11_old_value($cols, array('cargo','cargo_nombre','responsabilidad','cargo_id','rol'), "''");
    $oldEstado = e360v11_old_value($cols, array('estado'), 'NULL');
    $oldMunicipio = e360v11_old_value($cols, array('municipio'), 'NULL');
    $oldParroquia = e360v11_old_value($cols, array('parroquia'), 'NULL');
    $message = 'La persona ya posee una vinculación organizativa activa. Debe realizar un traslado antes de asignar otro cargo.';

    $beforeInsert = "CREATE TRIGGER `{$names['bi']}` BEFORE INSERT ON " . e360v11_ident($table) . " FOR EACH ROW BEGIN DECLARE v_pid BIGINT DEFAULT NULL; DECLARE v_count INT DEFAULT 0; IF ($newActive) AND $newCed>0 THEN SET v_pid=(SELECT id FROM personas WHERE CAST(cedula_normalizada AS UNSIGNED)=$newCed LIMIT 1); IF v_pid IS NOT NULL THEN SET v_count=(SELECT COUNT(*) FROM persona_vinculacion_actual WHERE persona_id=v_pid); IF v_count>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='$message'; END IF; END IF; END IF; END";
    $beforeUpdate = "CREATE TRIGGER `{$names['bu']}` BEFORE UPDATE ON " . e360v11_ident($table) . " FOR EACH ROW BEGIN DECLARE v_pid BIGINT DEFAULT NULL; DECLARE v_count INT DEFAULT 0; IF ($newActive) AND $newCed>0 THEN SET v_pid=(SELECT id FROM personas WHERE CAST(cedula_normalizada AS UNSIGNED)=$newCed LIMIT 1); IF v_pid IS NOT NULL THEN SET v_count=(SELECT COUNT(*) FROM persona_vinculacion_actual WHERE persona_id=v_pid AND NOT(tabla_origen=" . $pdo->quote($table) . " AND registro_origen_id=OLD.id)); IF v_count>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='$message'; END IF; END IF; END IF; END";
    $afterInsert = "CREATE TRIGGER `{$names['ai']}` AFTER INSERT ON " . e360v11_ident($table) . " FOR EACH ROW BEGIN DECLARE v_pid BIGINT DEFAULT NULL; IF ($newActive) AND $newCed>0 THEN INSERT INTO personas(cedula,cedula_numero,cedula_normalizada,nombre_completo,telefono_principal,es_activista,clasificacion_general,clasificacion_actual,tipo_vinculacion_actual,origen_primer_registro,activo,created_at) VALUES(CAST($newCed AS CHAR),CAST($newCed AS CHAR),CAST($newCed AS CHAR),$newName,$newPhone,1,'ACTIVISTA','ACTIVISTA," . $pdo->quote($type) . "," . $pdo->quote($table) . ",1,NOW()) ON DUPLICATE KEY UPDATE nombre_completo=COALESCE(NULLIF(VALUES(nombre_completo),''),nombre_completo),telefono_principal=COALESCE(NULLIF(VALUES(telefono_principal),''),telefono_principal),es_activista=1,clasificacion_general='ACTIVISTA',clasificacion_actual='ACTIVISTA',tipo_vinculacion_actual=" . $pdo->quote($type) . ",updated_at=NOW(); SET v_pid=(SELECT id FROM personas WHERE CAST(cedula_normalizada AS UNSIGNED)=$newCed LIMIT 1); INSERT INTO persona_vinculacion_actual(persona_id,tipo_instancia,tabla_origen,registro_origen_id,cargo,estado,municipio,parroquia,fecha_inicio) VALUES(v_pid," . $pdo->quote($type) . "," . $pdo->quote($table) . ",NEW.id,CAST($newCargo AS CHAR),$newEstado,$newMunicipio,$newParroquia,NOW()) ON DUPLICATE KEY UPDATE tipo_instancia=VALUES(tipo_instancia),tabla_origen=VALUES(tabla_origen),registro_origen_id=VALUES(registro_origen_id),cargo=VALUES(cargo),estado=VALUES(estado),municipio=VALUES(municipio),parroquia=VALUES(parroquia),updated_at=NOW(); INSERT INTO persona_vinculaciones_historial(persona_id,tipo_instancia,tabla_origen,registro_origen_id,cargo,estado,municipio,parroquia,fecha_inicio,estatus,created_at) VALUES(v_pid," . $pdo->quote($type) . "," . $pdo->quote($table) . ",NEW.id,CAST($newCargo AS CHAR),$newEstado,$newMunicipio,$newParroquia,NOW(),'ACTIVO',NOW()); END IF; END";
    $afterUpdate = "CREATE TRIGGER `{$names['au']}` AFTER UPDATE ON " . e360v11_ident($table) . " FOR EACH ROW BEGIN DECLARE v_old_pid BIGINT DEFAULT NULL; DECLARE v_new_pid BIGINT DEFAULT NULL; IF ($oldActive) AND ((NOT($newActive)) OR $oldCed<>$newCed) THEN SET v_old_pid=(SELECT id FROM personas WHERE CAST(cedula_normalizada AS UNSIGNED)=$oldCed LIMIT 1); UPDATE persona_vinculaciones_historial SET fecha_fin=NOW(),estatus='FINALIZADO',motivo_fin='CAMBIO_O_RETIRO',updated_at=NOW() WHERE persona_id=v_old_pid AND tabla_origen=" . $pdo->quote($table) . " AND registro_origen_id=OLD.id AND estatus='ACTIVO'; DELETE FROM persona_vinculacion_actual WHERE persona_id=v_old_pid AND tabla_origen=" . $pdo->quote($table) . " AND registro_origen_id=OLD.id; UPDATE personas SET es_activista=IF(EXISTS(SELECT 1 FROM persona_vinculacion_actual WHERE persona_id=v_old_pid),1,0),clasificacion_actual=IF(EXISTS(SELECT 1 FROM persona_vinculacion_actual WHERE persona_id=v_old_pid),'ACTIVISTA','SIMPATIZANTE'),clasificacion_general=IF(EXISTS(SELECT 1 FROM persona_vinculacion_actual WHERE persona_id=v_old_pid),'ACTIVISTA','SIMPATIZANTE'),tipo_vinculacion_actual=(SELECT tipo_instancia FROM persona_vinculacion_actual WHERE persona_id=v_old_pid LIMIT 1),updated_at=NOW() WHERE id=v_old_pid; END IF; IF ($newActive) AND $newCed>0 THEN SET v_new_pid=(SELECT id FROM personas WHERE CAST(cedula_normalizada AS UNSIGNED)=$newCed LIMIT 1); IF v_new_pid IS NULL THEN INSERT INTO personas(cedula,cedula_numero,cedula_normalizada,nombre_completo,telefono_principal,es_activista,clasificacion_general,clasificacion_actual,tipo_vinculacion_actual,origen_primer_registro,activo,created_at) VALUES(CAST($newCed AS CHAR),CAST($newCed AS CHAR),CAST($newCed AS CHAR),$newName,$newPhone,1,'ACTIVISTA','ACTIVISTA'," . $pdo->quote($type) . "," . $pdo->quote($table) . ",1,NOW()); SET v_new_pid=LAST_INSERT_ID(); END IF; INSERT INTO persona_vinculacion_actual(persona_id,tipo_instancia,tabla_origen,registro_origen_id,cargo,estado,municipio,parroquia,fecha_inicio) VALUES(v_new_pid," . $pdo->quote($type) . "," . $pdo->quote($table) . ",NEW.id,CAST($newCargo AS CHAR),$newEstado,$newMunicipio,$newParroquia,NOW()) ON DUPLICATE KEY UPDATE tipo_instancia=VALUES(tipo_instancia),tabla_origen=VALUES(tabla_origen),registro_origen_id=VALUES(registro_origen_id),cargo=VALUES(cargo),estado=VALUES(estado),municipio=VALUES(municipio),parroquia=VALUES(parroquia),updated_at=NOW(); UPDATE personas SET es_activista=1,clasificacion_actual='ACTIVISTA',clasificacion_general='ACTIVISTA',tipo_vinculacion_actual=" . $pdo->quote($type) . ",updated_at=NOW() WHERE id=v_new_pid; END IF; END";
    $afterDelete = "CREATE TRIGGER `{$names['ad']}` AFTER DELETE ON " . e360v11_ident($table) . " FOR EACH ROW BEGIN DECLARE v_pid BIGINT DEFAULT NULL; IF ($oldActive) AND $oldCed>0 THEN SET v_pid=(SELECT id FROM personas WHERE CAST(cedula_normalizada AS UNSIGNED)=$oldCed LIMIT 1); UPDATE persona_vinculaciones_historial SET fecha_fin=NOW(),estatus='FINALIZADO',motivo_fin='REGISTRO_ELIMINADO',updated_at=NOW() WHERE persona_id=v_pid AND tabla_origen=" . $pdo->quote($table) . " AND registro_origen_id=OLD.id AND estatus='ACTIVO'; DELETE FROM persona_vinculacion_actual WHERE persona_id=v_pid AND tabla_origen=" . $pdo->quote($table) . " AND registro_origen_id=OLD.id; UPDATE personas SET es_activista=IF(EXISTS(SELECT 1 FROM persona_vinculacion_actual WHERE persona_id=v_pid),1,0),clasificacion_actual=IF(EXISTS(SELECT 1 FROM persona_vinculacion_actual WHERE persona_id=v_pid),'ACTIVISTA','SIMPATIZANTE'),clasificacion_general=IF(EXISTS(SELECT 1 FROM persona_vinculacion_actual WHERE persona_id=v_pid),'ACTIVISTA','SIMPATIZANTE'),tipo_vinculacion_actual=(SELECT tipo_instancia FROM persona_vinculacion_actual WHERE persona_id=v_pid LIMIT 1),updated_at=NOW() WHERE id=v_pid; END IF; END";

    foreach (array($beforeInsert,$beforeUpdate,$afterInsert,$afterUpdate,$afterDelete) as $sql) {
        try { $pdo->exec($sql); }
        catch (Throwable $e) { $warnings[] = 'No se instaló un control automático en ' . $table . ': ' . $e->getMessage(); return; }
    }
    $done[] = 'Regla de una sola vinculación activa instalada en ' . $table;
}

function e360v11_rebuild_links(PDO $pdo, &$done, &$warnings) {
    try {
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_e360v11_links');
        $pdo->exec("CREATE TEMPORARY TABLE tmp_e360v11_links(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,persona_id BIGINT UNSIGNED NOT NULL,cedula_normalizada VARCHAR(40) NOT NULL,tipo_instancia VARCHAR(60) NOT NULL,tabla_origen VARCHAR(120) NOT NULL,registro_origen_id BIGINT UNSIGNED DEFAULT NULL,cargo VARCHAR(220) DEFAULT NULL,estado VARCHAR(120) DEFAULT NULL,municipio VARCHAR(160) DEFAULT NULL,parroquia VARCHAR(180) DEFAULT NULL)");
        foreach (e360v11_sources($pdo) as $source) {
            $cols = $source['cols'];
            $table = $source['table'];
            $cedulaCol = $source['cedula'];
            if (!isset($cols['id']) || !isset($cols[$cedulaCol])) continue;
            $active = e360v11_active_expression($cols, 's');
            $cargo = 'NULL'; foreach (array('cargo','cargo_nombre','responsabilidad','cargo_id','rol') as $c) if (isset($cols[$c])) { $cargo='CAST(s.'.e360v11_ident($c).' AS CHAR)'; break; }
            $estado = isset($cols['estado']) ? 's.`estado`' : 'NULL';
            $municipio = isset($cols['municipio']) ? 's.`municipio`' : 'NULL';
            $parroquia = isset($cols['parroquia']) ? 's.`parroquia`' : 'NULL';
            $ced = 'CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(s.'.e360v11_ident($cedulaCol)."),'.',''),'-',''),'V',''),'E','') AS UNSIGNED)";
            $sql = "INSERT INTO tmp_e360v11_links(persona_id,cedula_normalizada,tipo_instancia,tabla_origen,registro_origen_id,cargo,estado,municipio,parroquia) SELECT p.id,p.cedula_normalizada,".$pdo->quote($source['tipo']).",".$pdo->quote($table).",s.id,$cargo,$estado,$municipio,$parroquia FROM ".e360v11_ident($table)." s JOIN personas p ON CAST(p.cedula_normalizada AS UNSIGNED)=$ced WHERE $active AND $ced>0";
            $pdo->exec($sql);
        }
        $pdo->exec('DELETE FROM persona_vinculacion_actual');
        $pdo->exec("INSERT INTO persona_vinculacion_actual(persona_id,tipo_instancia,tabla_origen,registro_origen_id,cargo,estado,municipio,parroquia,fecha_inicio) SELECT t.persona_id,t.tipo_instancia,t.tabla_origen,t.registro_origen_id,t.cargo,t.estado,t.municipio,t.parroquia,NOW() FROM tmp_e360v11_links t JOIN(SELECT persona_id,MIN(id) first_id FROM tmp_e360v11_links GROUP BY persona_id)x ON x.first_id=t.id");
        $pdo->exec("UPDATE personas p SET p.es_activista=IF(EXISTS(SELECT 1 FROM tmp_e360v11_links t WHERE t.persona_id=p.id),1,0),p.clasificacion_actual=IF(EXISTS(SELECT 1 FROM tmp_e360v11_links t WHERE t.persona_id=p.id),'ACTIVISTA','SIMPATIZANTE'),p.clasificacion_general=IF(EXISTS(SELECT 1 FROM tmp_e360v11_links t WHERE t.persona_id=p.id),'ACTIVISTA','SIMPATIZANTE'),p.tipo_vinculacion_actual=(SELECT tipo_instancia FROM persona_vinculacion_actual v WHERE v.persona_id=p.id LIMIT 1),p.cantidad_actividades=(SELECT COUNT(DISTINCT aa.actividad_id) FROM actividad_asistencias aa WHERE aa.persona_id=p.id AND aa.asistencia='asistio')");
        $pdo->exec("INSERT INTO expediente360_inconsistencias(cedula_normalizada,persona_id,tipo,detalle_json,estatus,detectado_at) SELECT t.cedula_normalizada,t.persona_id,'MULTIPLE_VINCULACION_ACTIVA',JSON_OBJECT('cantidad',COUNT(*),'fuentes',GROUP_CONCAT(CONCAT(t.tipo_instancia,':',t.tabla_origen,':',t.registro_origen_id) SEPARATOR ' | ')),'PENDIENTE',NOW() FROM tmp_e360v11_links t GROUP BY t.persona_id,t.cedula_normalizada HAVING COUNT(*)>1 ON DUPLICATE KEY UPDATE detalle_json=VALUES(detalle_json),detectado_at=NOW()");
        $pdo->exec("INSERT INTO persona_vinculaciones_historial(persona_id,tipo_instancia,tabla_origen,registro_origen_id,cargo,estado,municipio,parroquia,fecha_inicio,estatus,created_at) SELECT t.persona_id,t.tipo_instancia,t.tabla_origen,t.registro_origen_id,t.cargo,t.estado,t.municipio,t.parroquia,NOW(),'ACTIVO',NOW() FROM tmp_e360v11_links t WHERE NOT EXISTS(SELECT 1 FROM persona_vinculaciones_historial h WHERE h.persona_id=t.persona_id AND h.tabla_origen=t.tabla_origen AND h.registro_origen_id=t.registro_origen_id AND h.estatus='ACTIVO')");
        $done[] = 'Activistas y simpatizantes recalculados desde las vinculaciones reales.';
        $done[] = 'Vinculaciones múltiples existentes enviadas a la bandeja de inconsistencias.';
    } catch (Throwable $e) {
        $warnings[] = 'La reconciliación organizativa no pudo completarse: ' . $e->getMessage();
    }
}

function e360v11_create_attendance_triggers(PDO $pdo, &$done, &$warnings) {
    foreach (array('e360v11_bi_asistencia','e360v11_ai_asistencia') as $name) {
        try { $pdo->exec('DROP TRIGGER IF EXISTS ' . e360v11_ident($name)); } catch (Throwable $e) {}
    }
    $before = "CREATE TRIGGER `e360v11_bi_asistencia` BEFORE INSERT ON `actividad_asistencias` FOR EACH ROW BEGIN DECLARE v_persona BIGINT DEFAULT NULL; DECLARE v_nombre VARCHAR(255) DEFAULT NULL; DECLARE v_tel VARCHAR(60) DEFAULT NULL; DECLARE v_rep TINYINT DEFAULT 0; DECLARE v_class VARCHAR(40) DEFAULT NULL; DECLARE v_snapshot BIGINT DEFAULT NULL; DECLARE v_link VARCHAR(60) DEFAULT NULL; DECLARE v_meta INT DEFAULT 0; SET v_persona=(SELECT persona_id FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND cedula_normalizada=CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(NEW.cedula),'.',''),'-',''),'V',''),'E','') AS UNSIGNED) AND expires_at>NOW() ORDER BY id DESC LIMIT 1); IF v_persona IS NOT NULL THEN SET v_nombre=(SELECT nombre FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND persona_id=v_persona ORDER BY id DESC LIMIT 1); SET v_tel=(SELECT telefono FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND persona_id=v_persona ORDER BY id DESC LIMIT 1); SET v_rep=(SELECT rep_encontrado FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND persona_id=v_persona ORDER BY id DESC LIMIT 1); SET v_class=(SELECT clasificacion FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND persona_id=v_persona ORDER BY id DESC LIMIT 1); SET v_snapshot=(SELECT snapshot_rep_id FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND persona_id=v_persona ORDER BY id DESC LIMIT 1); SET v_link=(SELECT vinculacion_tipo FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND persona_id=v_persona ORDER BY id DESC LIMIT 1); SET NEW.persona_id=v_persona; SET NEW.nombre=COALESCE(NULLIF(NEW.nombre,''),v_nombre); SET NEW.telefono=COALESCE(NULLIF(NEW.telefono,''),v_tel); SET NEW.telefono_reportado=COALESCE(NULLIF(NEW.telefono_reportado,''),v_tel); SET NEW.rep_encontrado=v_rep; SET NEW.clasificacion_asistente=v_class; SET NEW.clasificacion_al_registro=v_class; SET NEW.es_activista_politico=IF(v_class='ACTIVISTA',1,0); SET NEW.snapshot_rep_id=v_snapshot; SET NEW.vinculacion_tipo_al_registro=v_link; END IF; SET v_meta=COALESCE((SELECT meta_asistencia FROM actividades WHERE id=NEW.actividad_id),0); IF v_meta>0 AND (SELECT COUNT(*) FROM actividad_asistencias WHERE actividad_id=NEW.actividad_id AND asistencia='asistio')>=v_meta THEN SET NEW.es_adicional=1; END IF; END";
    $after = "CREATE TRIGGER `e360v11_ai_asistencia` AFTER INSERT ON `actividad_asistencias` FOR EACH ROW BEGIN IF NEW.persona_id IS NOT NULL AND NEW.asistencia='asistio' THEN UPDATE personas SET es_asistente_general=1,primera_actividad_at=COALESCE(primera_actividad_at,NOW()),ultima_actividad_at=NOW(),cantidad_actividades=(SELECT COUNT(DISTINCT actividad_id) FROM actividad_asistencias WHERE persona_id=NEW.persona_id AND asistencia='asistio'),updated_at=NOW() WHERE id=NEW.persona_id; INSERT INTO persona_timeline(persona_id,tipo_evento,modulo,entidad,entidad_id,titulo,descripcion,datos_json,fecha_evento,created_at) SELECT NEW.persona_id,'ASISTENCIA_ACTIVIDAD','ACTIVIDADES','actividad_asistencias',NEW.id,CONCAT('Participó en ',a.titulo),'Registro de asistencia incorporado automáticamente al Expediente 360°.',JSON_OBJECT('actividad_id',a.id,'clasificacion_al_registro',NEW.clasificacion_al_registro,'telefono_reportado',NEW.telefono_reportado,'es_adicional',NEW.es_adicional),NOW(),NOW() FROM actividades a WHERE a.id=NEW.actividad_id AND NOT EXISTS(SELECT 1 FROM persona_timeline pt WHERE pt.entidad='actividad_asistencias' AND pt.entidad_id=NEW.id AND pt.tipo_evento='ASISTENCIA_ACTIVIDAD'); END IF; DELETE FROM actividad_asistencia_pre_sync WHERE actividad_id=NEW.actividad_id AND cedula_normalizada=CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(NEW.cedula),'.',''),'-',''),'V',''),'E','') AS UNSIGNED); END";
    try {
        $pdo->exec($before);
        $pdo->exec($after);
        $done[] = 'Registro de asistentes conectado al Expediente 360° y al snapshot completo del REP.';
    } catch (Throwable $e) {
        $warnings[] = 'No fue posible instalar los disparadores de asistencia: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stamp = date('Ymd_His');
    $backupDir = $root . '/backups/expediente360_v11_' . $stamp;

    e360v11_step($pdo, "CREATE TABLE IF NOT EXISTS persona_rep_snapshots(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,persona_id BIGINT UNSIGNED NOT NULL,actividad_id BIGINT UNSIGNED DEFAULT NULL,cedula_normalizada VARCHAR(40) NOT NULL,fuente VARCHAR(120) DEFAULT 'electores',fuente_version VARCHAR(80) DEFAULT NULL,snapshot_json LONGTEXT NOT NULL,snapshot_hash CHAR(64) NOT NULL,consultado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_snapshot_persona(persona_id),KEY idx_snapshot_actividad(actividad_id),KEY idx_snapshot_cedula(cedula_normalizada),KEY idx_snapshot_hash(snapshot_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", 'Tabla de snapshots completos del REP', $done, $errors);
    e360v11_step($pdo, "CREATE TABLE IF NOT EXISTS actividad_asistencia_pre_sync(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,actividad_id BIGINT UNSIGNED NOT NULL,cedula_normalizada VARCHAR(40) NOT NULL,persona_id BIGINT UNSIGNED NOT NULL,nombre VARCHAR(255) NOT NULL,telefono VARCHAR(60) NOT NULL,rep_encontrado TINYINT(1) NOT NULL DEFAULT 0,clasificacion VARCHAR(40) NOT NULL,vinculacion_tipo VARCHAR(60) DEFAULT NULL,snapshot_rep_id BIGINT UNSIGNED DEFAULT NULL,inconsistencia TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,expires_at DATETIME NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_pre_sync(actividad_id,cedula_normalizada),KEY idx_pre_persona(persona_id),KEY idx_pre_expira(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", 'Cola segura previa al registro de asistencia', $done, $errors);
    e360v11_step($pdo, "CREATE TABLE IF NOT EXISTS persona_vinculacion_actual(persona_id BIGINT UNSIGNED NOT NULL,tipo_instancia VARCHAR(60) NOT NULL,tabla_origen VARCHAR(120) NOT NULL,registro_origen_id BIGINT UNSIGNED DEFAULT NULL,cargo VARCHAR(220) DEFAULT NULL,estado VARCHAR(120) DEFAULT NULL,municipio VARCHAR(160) DEFAULT NULL,parroquia VARCHAR(180) DEFAULT NULL,fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(persona_id),KEY idx_vinc_tipo(tipo_instancia),KEY idx_vinc_origen(tabla_origen,registro_origen_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", 'Vinculación organizativa única', $done, $errors);
    e360v11_step($pdo, "CREATE TABLE IF NOT EXISTS persona_vinculaciones_historial(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,persona_id BIGINT UNSIGNED NOT NULL,tipo_instancia VARCHAR(60) NOT NULL,tabla_origen VARCHAR(120) NOT NULL,registro_origen_id BIGINT UNSIGNED DEFAULT NULL,cargo VARCHAR(220) DEFAULT NULL,estado VARCHAR(120) DEFAULT NULL,municipio VARCHAR(160) DEFAULT NULL,parroquia VARCHAR(180) DEFAULT NULL,fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,fecha_fin DATETIME DEFAULT NULL,estatus VARCHAR(40) NOT NULL DEFAULT 'ACTIVO',motivo_fin VARCHAR(220) DEFAULT NULL,datos_json LONGTEXT DEFAULT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_vh_persona(persona_id),KEY idx_vh_estatus(estatus),KEY idx_vh_origen(tabla_origen,registro_origen_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", 'Historial de cargos y traslados', $done, $errors);
    e360v11_step($pdo, "CREATE TABLE IF NOT EXISTS expediente360_inconsistencias(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,cedula_normalizada VARCHAR(40) NOT NULL,persona_id BIGINT UNSIGNED DEFAULT NULL,tipo VARCHAR(100) NOT NULL,detalle_json LONGTEXT DEFAULT NULL,estatus VARCHAR(40) NOT NULL DEFAULT 'PENDIENTE',detectado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resuelto_at DATETIME DEFAULT NULL,PRIMARY KEY(id),UNIQUE KEY uq_inc_activa(cedula_normalizada,tipo,estatus),KEY idx_inc_persona(persona_id),KEY idx_inc_estatus(estatus)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", 'Bandeja de inconsistencias organizativas', $done, $errors);

    e360v11_add_column($pdo,'personas','clasificacion_actual',"VARCHAR(40) DEFAULT 'SIMPATIZANTE'",$done,$errors);
    e360v11_add_column($pdo,'personas','tipo_vinculacion_actual','VARCHAR(60) DEFAULT NULL',$done,$errors);
    e360v11_add_column($pdo,'personas','cantidad_actividades','INT NOT NULL DEFAULT 0',$done,$errors);
    e360v11_add_column($pdo,'actividad_asistencias','snapshot_rep_id','BIGINT UNSIGNED DEFAULT NULL',$done,$errors);
    e360v11_add_column($pdo,'actividad_asistencias','clasificacion_al_registro','VARCHAR(40) DEFAULT NULL',$done,$errors);
    e360v11_add_column($pdo,'actividad_asistencias','vinculacion_tipo_al_registro','VARCHAR(60) DEFAULT NULL',$done,$errors);

    e360v11_rebuild_links($pdo,$done,$warnings);
    e360v11_create_attendance_triggers($pdo,$done,$warnings);
    foreach (e360v11_sources($pdo) as $source) e360v11_create_guard_triggers($pdo,$source,$done,$warnings);

    e360v11_backup_and_patch($root,'activistas.php',$backupDir,$done,$warnings);
    e360v11_backup_and_patch($root,'activista_detalle.php',$backupDir,$done,$warnings);
    e360v11_backup_and_patch($root,'actividad_asistencia.php',$backupDir,$done,$warnings);
    e360v11_patch_menu($root,$backupDir,$done,$warnings);
    e360v11_archive_wrong_v10($root,$done);
    $done[] = 'Respaldo de archivos modificado en ' . str_replace($root . '/', '', $backupDir);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Corrección Expediente 360° V11</title>
<style>
:root{--n:#071d35;--o:#ff7a00;--bg:#f4f1ec;--text:#102642}*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial;background:linear-gradient(145deg,var(--n),#123a60);color:var(--text);min-height:100vh}.wrap{max-width:980px;margin:auto;padding:34px 18px}.card{background:#fff;border-radius:26px;padding:28px;box-shadow:0 28px 70px #0004}.brand{color:var(--o);font-weight:950;letter-spacing:.08em}.lead{color:#65748a;line-height:1.6}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:14px;background:var(--o);color:#fff;min-height:48px;padding:0 20px;font-weight:950;text-decoration:none;cursor:pointer}.ok,.warn,.err{margin:9px 0;padding:13px 15px;border-radius:14px;font-weight:750}.ok{background:#eafbf1;color:#14683b;border:1px solid #b7e7c9}.warn{background:#fff5df;color:#805000;border:1px solid #ffd591}.err{background:#fff0f0;color:#922c2c;border:1px solid #f1b0b0}code{background:#eef2f6;padding:3px 7px;border-radius:7px}@media(max-width:650px){.wrap{padding:14px}.card{padding:20px;border-radius:20px}h1{font-size:27px}.btn{width:100%}}
</style>
</head>
<body><main class="wrap"><section class="card">
<div class="brand">VOLUNTAD POPULAR · VPNACIONAL</div>
<h1>Corrección Expediente 360° V11</h1>
<p class="lead">Esta versión conserva y actualiza directamente <code>activistas.php</code> y <code>activista_detalle.php</code>. No crea un dashboard paralelo. Integra asistentes, snapshot completo del REP, clasificación Activista/Simpatizante, una sola vinculación organizativa activa y versión móvil con la línea visual de Actividades.</p>
<?php foreach($done as $message): ?><div class="ok">✓ <?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endforeach; ?>
<?php foreach($warnings as $message): ?><div class="warn">⚠ <?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endforeach; ?>
<?php foreach($errors as $message): ?><div class="err">✕ <?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endforeach; ?>
<?php if($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
<form method="post"><button class="btn" type="submit">Aplicar corrección V11</button></form>
<?php else: ?>
<p><a class="btn" href="activistas.php">Abrir Expediente 360°</a></p>
<p class="lead">Comprueba también una actividad en <code>actividad_asistencia.php?id=ID</code>. Después de verificar, elimina este instalador.</p>
<?php endif; ?>
</section></main></body></html>
