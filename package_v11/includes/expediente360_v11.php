<?php
/**
 * VPNACIONAL · Expediente 360 V11
 * Trabaja sobre activistas.php y activista_detalle.php.
 */

if (!function_exists('e360v11_db')) {
    function e360v11_db() {
        foreach (array('pdo', 'db', 'conexion', 'conn') as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                return $GLOBALS[$name];
            }
        }
        foreach (array('db', 'pdo', 'getPDO', 'conexion') as $fn) {
            if (function_exists($fn)) {
                try {
                    $value = $fn();
                    if ($value instanceof PDO) return $value;
                } catch (Throwable $e) {}
            }
        }
        return null;
    }
}

if (!function_exists('e360v11_norm_cedula')) {
    function e360v11_norm_cedula($value) {
        return preg_replace('/\D+/', '', (string)$value) ?: '';
    }
}

if (!function_exists('e360v11_ident')) {
    function e360v11_ident($value) {
        $value = (string)$value;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new RuntimeException('Identificador SQL inválido.');
        }
        return '`' . $value . '`';
    }
}

if (!function_exists('e360v11_table_exists')) {
    function e360v11_table_exists(PDO $pdo, $table) {
        static $cache = array();
        $key = spl_object_hash($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
            $st->execute(array($table));
            return $cache[$key] = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('e360v11_columns')) {
    function e360v11_columns(PDO $pdo, $table) {
        static $cache = array();
        $key = spl_object_hash($pdo) . ':' . $table;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $st->execute(array($table));
            $out = array();
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $column) $out[$column] = true;
            return $cache[$key] = $out;
        } catch (Throwable $e) {
            return $cache[$key] = array();
        }
    }
}

if (!function_exists('e360v11_has_column')) {
    function e360v11_has_column(PDO $pdo, $table, $column) {
        $cols = e360v11_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('e360v11_pick')) {
    function e360v11_pick(array $row, array $keys, $default = null) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') return $row[$key];
        }
        return $default;
    }
}

if (!function_exists('e360v11_rep_pdo')) {
    function e360v11_rep_pdo(PDO $main) {
        foreach (array('pdoElectores', 'pdo_electores', 'electoresPdo', 'repPdo', 'pdo_rep') as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) return $GLOBALS[$name];
        }
        return $main;
    }
}

if (!function_exists('e360v11_find_elector')) {
    function e360v11_find_elector(PDO $main, $cedula) {
        $cedula = e360v11_norm_cedula($cedula);
        if ($cedula === '') return null;
        $pdo = e360v11_rep_pdo($main);
        foreach (array('electores', 'rep', 'elector') as $table) {
            try {
                $cols = e360v11_columns($pdo, $table);
                if (!$cols) continue;
                $cedulaCol = null;
                foreach (array('cedula', 'CEDULA', 'cedula_identidad', 'CEDULA_IDENTIDAD', 'documento') as $candidate) {
                    if (isset($cols[$candidate])) { $cedulaCol = $candidate; break; }
                }
                if (!$cedulaCol) continue;
                $id = e360v11_ident($cedulaCol);
                $sql = 'SELECT * FROM ' . e360v11_ident($table) . " WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER($id),'.',''),'-',''),'V',''),'E','') AS UNSIGNED)=CAST(? AS UNSIGNED) LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute(array($cedula));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['_e360_fuente'] = $table;
                    return $row;
                }
            } catch (Throwable $e) {}
        }
        return null;
    }
}

if (!function_exists('e360v11_person_from_rep')) {
    function e360v11_person_from_rep(array $row) {
        $priNom = trim((string)e360v11_pick($row, array('pri_nom','PRI_NOM','pri_nombre','PRI_NOMBRE','P_NOMBRE','primer_nombre','nombre'), ''));
        $segNom = trim((string)e360v11_pick($row, array('seg_nom','SEG_NOM','seg_nombre','SEG_NOMBRE','S_NOMBRE','segundo_nombre'), ''));
        $priApe = trim((string)e360v11_pick($row, array('pri_ape','PRI_APE','pri_apellido','PRI_APELLIDO','P_APELLIDO','primer_apellido','apellido'), ''));
        $segApe = trim((string)e360v11_pick($row, array('seg_ape','SEG_APE','seg_apellido','SEG_APELLIDO','S_APELLIDO','segundo_apellido'), ''));
        $nombre = trim(implode(' ', array_filter(array($priNom, $segNom, $priApe, $segApe))));
        if ($nombre === '') $nombre = trim((string)e360v11_pick($row, array('nombre_completo','NOMBRE_COMPLETO','NOMBRE'), ''));
        return array(
            'nacionalidad' => e360v11_pick($row, array('nac','NAC','nacionalidad'), null),
            'pri_nombre' => $priNom,
            'seg_nombre' => $segNom,
            'pri_apellido' => $priApe,
            'seg_apellido' => $segApe,
            'nombre_completo' => $nombre,
            'sexo' => e360v11_pick($row, array('sexo','SEXO','gen','GEN','genero'), null),
            'fecha_nacimiento' => e360v11_pick($row, array('fecha_nac','FECHA_NAC','fecha_nacimiento','FECHA_NACIMIENTO'), null),
            'cod_estado' => e360v11_pick($row, array('cod_edo','COD_EDO','cod_estado','COD_ESTADO'), null),
            'estado' => e360v11_pick($row, array('estado','ESTADO','edo','EDO'), null),
            'cod_municipio' => e360v11_pick($row, array('cod_mun','COD_MUN','cod_municipio','COD_MUNICIPIO'), null),
            'municipio' => e360v11_pick($row, array('municipio','MUNICIPIO','mun','MUN'), null),
            'cod_parroquia' => e360v11_pick($row, array('cod_par','COD_PAR','cod_parroquia','COD_PARROQUIA'), null),
            'parroquia' => e360v11_pick($row, array('parroquia','PARROQUIA','par','PAR'), null),
            'cod_centro' => e360v11_pick($row, array('cod_centro','COD_CENTRO','codigo_centro'), null),
            'centro' => e360v11_pick($row, array('centro','CENTRO','centro_nuevo','CENTRO_NUEVO'), null),
            'direccion_centro' => e360v11_pick($row, array('direccion','DIRECCION','direccion_centro'), null)
        );
    }
}

if (!function_exists('e360v11_active_expression')) {
    function e360v11_active_expression(array $cols, $alias = '') {
        $p = $alias !== '' ? e360v11_ident($alias) . '.' : '';
        $parts = array();
        if (isset($cols['activo'])) $parts[] = 'COALESCE(' . $p . '`activo`,1)=1';
        if (isset($cols['estatus'])) $parts[] = "UPPER(COALESCE(" . $p . "`estatus`,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE','ELIMINADO')";
        if (isset($cols['estado_registro'])) $parts[] = "UPPER(COALESCE(" . $p . "`estado_registro`,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE','ELIMINADO')";
        return $parts ? implode(' AND ', $parts) : '1=1';
    }
}

if (!function_exists('e360v11_center_tables')) {
    function e360v11_center_tables(PDO $pdo) {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = array();
        try {
            $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (LOWER(TABLE_NAME) LIKE '%centro%' OR LOWER(TABLE_NAME) LIKE '%votacion%' OR LOWER(TABLE_NAME) LIKE '%testigo%')";
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if (in_array($table, array('redes_populares_centros','centros','centros_votacion','electores'), true)) continue;
                $cols = e360v11_columns($pdo, $table);
                $cedulaCol = null;
                foreach (array('cedula','cedula_identidad','documento') as $candidate) if (isset($cols[$candidate])) { $cedulaCol = $candidate; break; }
                if (!$cedulaCol || !isset($cols['id'])) continue;
                $cache[] = array('tipo'=>'CENTRO_VOTACION','table'=>$table,'cedula'=>$cedulaCol,'cols'=>$cols);
            }
        } catch (Throwable $e) {}
        return $cache;
    }
}

if (!function_exists('e360v11_sources')) {
    function e360v11_sources(PDO $pdo) {
        $sources = array();
        if (e360v11_table_exists($pdo, 'estructuras_miembros')) {
            $sources[] = array('tipo'=>'ESTRUCTURA','table'=>'estructuras_miembros','cedula'=>'cedula','cols'=>e360v11_columns($pdo,'estructuras_miembros'));
        }
        if (e360v11_table_exists($pdo, 'redes_populares_miembros')) {
            $sources[] = array('tipo'=>'RED_POPULAR','table'=>'redes_populares_miembros','cedula'=>'cedula','cols'=>e360v11_columns($pdo,'redes_populares_miembros'));
        }
        return array_merge($sources, e360v11_center_tables($pdo));
    }
}

if (!function_exists('e360v11_active_links')) {
    function e360v11_active_links(PDO $pdo, $cedula) {
        $cedula = e360v11_norm_cedula($cedula);
        if ($cedula === '') return array();
        $out = array();
        foreach (e360v11_sources($pdo) as $source) {
            $cols = $source['cols'];
            $table = $source['table'];
            if (!isset($cols[$source['cedula']])) continue;
            $cargoCol = null;
            foreach (array('cargo','cargo_nombre','responsabilidad','cargo_id','rol') as $candidate) if (isset($cols[$candidate])) { $cargoCol = $candidate; break; }
            $estadoCol = isset($cols['estado']) ? 'estado' : null;
            $municipioCol = isset($cols['municipio']) ? 'municipio' : null;
            $parroquiaCol = isset($cols['parroquia']) ? 'parroquia' : null;
            $ced = e360v11_ident($source['cedula']);
            $where = e360v11_active_expression($cols);
            try {
                $sql = 'SELECT * FROM ' . e360v11_ident($table) . " WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(UPPER($ced),'.',''),'-',''),'V',''),'E','') AS UNSIGNED)=CAST(? AS UNSIGNED) AND $where";
                $st = $pdo->prepare($sql);
                $st->execute(array($cedula));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out[] = array(
                        'tipo' => $source['tipo'],
                        'tabla' => $table,
                        'registro_id' => isset($row['id']) ? (int)$row['id'] : null,
                        'cargo' => $cargoCol ? (string)$row[$cargoCol] : '',
                        'estado' => $estadoCol ? (string)$row[$estadoCol] : '',
                        'municipio' => $municipioCol ? (string)$row[$municipioCol] : '',
                        'parroquia' => $parroquiaCol ? (string)$row[$parroquiaCol] : ''
                    );
                }
            } catch (Throwable $e) {}
        }
        return $out;
    }
}

if (!function_exists('e360v11_classification')) {
    function e360v11_classification(PDO $pdo, $cedula) {
        $links = e360v11_active_links($pdo, $cedula);
        return array(
            'clasificacion' => $links ? 'ACTIVISTA' : 'SIMPATIZANTE',
            'es_activista' => $links ? 1 : 0,
            'vinculaciones' => $links,
            'inconsistencia' => count($links) > 1,
            'vinculacion_actual' => count($links) === 1 ? $links[0] : null
        );
    }
}

if (!function_exists('e360v11_person_id')) {
    function e360v11_person_id(PDO $pdo, $cedula) {
        $cedula = e360v11_norm_cedula($cedula);
        $st = $pdo->prepare('SELECT id FROM personas WHERE cedula_normalizada=? LIMIT 1');
        $st->execute(array($cedula));
        $id = $st->fetchColumn();
        return $id ? (int)$id : 0;
    }
}

if (!function_exists('e360v11_upsert_person')) {
    function e360v11_upsert_person(PDO $pdo, $cedula, $telefono, $manualName, $rep, $origin) {
        $cedula = e360v11_norm_cedula($cedula);
        if ($cedula === '') throw new RuntimeException('La cédula no es válida.');
        $repData = $rep ? e360v11_person_from_rep($rep) : array('nombre_completo'=>trim((string)$manualName));
        if (trim((string)$repData['nombre_completo']) === '') throw new RuntimeException('La persona no aparece en el REP. Escriba nombre y apellido.');
        $class = e360v11_classification($pdo, $cedula);
        $columns = e360v11_columns($pdo, 'personas');
        $id = e360v11_person_id($pdo, $cedula);
        $data = array(
            'cedula' => $cedula,
            'cedula_numero' => $cedula,
            'cedula_normalizada' => $cedula,
            'telefono_principal' => trim((string)$telefono),
            'rep_encontrado' => $rep ? 1 : 0,
            'rep_checked_at' => date('Y-m-d H:i:s'),
            'rep_fuente_version' => $rep ? (string)($rep['_e360_fuente'] ?? 'electores') : null,
            'es_asistente_general' => 1,
            'es_activista' => $class['es_activista'],
            'clasificacion_general' => $class['clasificacion'],
            'clasificacion_actual' => $class['clasificacion'],
            'tipo_vinculacion_actual' => $class['vinculacion_actual']['tipo'] ?? null,
            'requiere_inscripcion_rep' => $rep ? 0 : 1,
            'origen_primer_registro' => $origin,
            'ultima_actividad_at' => date('Y-m-d H:i:s'),
            'activo' => 1
        );
        foreach ($repData as $key=>$value) if ($value !== null && $value !== '') $data[$key] = $value;
        if (!$id) {
            if (isset($columns['fecha_ingreso_sistema'])) $data['fecha_ingreso_sistema'] = date('Y-m-d H:i:s');
            if (isset($columns['primera_actividad_at'])) $data['primera_actividad_at'] = date('Y-m-d H:i:s');
            if (isset($columns['created_at'])) $data['created_at'] = date('Y-m-d H:i:s');
            $insert = array();
            foreach ($data as $key=>$value) if (isset($columns[$key])) $insert[$key] = $value;
            $sql = 'INSERT INTO personas (`' . implode('`,`', array_keys($insert)) . '`) VALUES (' . implode(',', array_fill(0, count($insert), '?')) . ')';
            $pdo->prepare($sql)->execute(array_values($insert));
            $id = (int)$pdo->lastInsertId();
        } else {
            $sets = array(); $values = array();
            foreach ($data as $key=>$value) {
                if (!isset($columns[$key])) continue;
                if ($value === null || $value === '') continue;
                $sets[] = e360v11_ident($key) . '=?';
                $values[] = $value;
            }
            if (isset($columns['updated_at'])) $sets[] = '`updated_at`=NOW()';
            $values[] = $id;
            if ($sets) $pdo->prepare('UPDATE personas SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);
        }
        if (e360v11_table_exists($pdo, 'persona_contactos') && trim((string)$telefono) !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM persona_contactos WHERE persona_id=? AND tipo='telefono' AND valor=? LIMIT 1");
                $st->execute(array($id, trim((string)$telefono)));
                if (!$st->fetchColumn()) {
                    $pdo->prepare("INSERT INTO persona_contactos(persona_id,tipo,valor,es_principal,vigente,fuente,created_at) VALUES(?,'telefono',?,1,1,?,NOW())")
                        ->execute(array($id, trim((string)$telefono), $origin));
                }
            } catch (Throwable $e) {}
        }
        return array('persona_id'=>$id,'nombre'=>$repData['nombre_completo'],'clasificacion'=>$class,'rep_encontrado'=>(bool)$rep);
    }
}

if (!function_exists('e360v11_store_snapshot')) {
    function e360v11_store_snapshot(PDO $pdo, $personaId, $actividadId, $cedula, $rep) {
        if (!$rep || !e360v11_table_exists($pdo, 'persona_rep_snapshots')) return null;
        $copy = $rep;
        unset($copy['_e360_fuente']);
        $json = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', (string)$json);
        $st = $pdo->prepare('INSERT INTO persona_rep_snapshots(persona_id,actividad_id,cedula_normalizada,fuente,fuente_version,snapshot_json,snapshot_hash,consultado_at) VALUES(?,?,?,?,?,?,?,NOW())');
        $st->execute(array($personaId,$actividadId,e360v11_norm_cedula($cedula),$rep['_e360_fuente'] ?? 'electores',date('Y-m-d'),$json,$hash));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('e360v11_prepare_attendee')) {
    function e360v11_prepare_attendee(PDO $pdo, $actividadId, $cedula, $telefono, $manualName) {
        $actividadId = (int)$actividadId;
        $cedula = e360v11_norm_cedula($cedula);
        $telefono = trim((string)$telefono);
        if ($actividadId < 1) throw new RuntimeException('Actividad inválida.');
        if ($cedula === '') throw new RuntimeException('Ingrese una cédula válida.');
        if ($telefono === '') throw new RuntimeException('El teléfono es obligatorio.');
        $st = $pdo->prepare('SELECT id,titulo,meta_asistencia,estado,municipio,parroquia FROM actividades WHERE id=? LIMIT 1');
        $st->execute(array($actividadId));
        $actividad = $st->fetch(PDO::FETCH_ASSOC);
        if (!$actividad) throw new RuntimeException('Actividad no encontrada.');
        $rep = e360v11_find_elector($pdo, $cedula);
        $result = e360v11_upsert_person($pdo, $cedula, $telefono, $manualName, $rep, 'ASISTENCIA_ACTIVIDAD');
        $snapshotId = e360v11_store_snapshot($pdo, $result['persona_id'], $actividadId, $cedula, $rep);
        $class = $result['clasificacion'];
        if (e360v11_table_exists($pdo, 'actividad_asistencia_pre_sync')) {
            $sql = "INSERT INTO actividad_asistencia_pre_sync(actividad_id,cedula_normalizada,persona_id,nombre,telefono,rep_encontrado,clasificacion,snapshot_rep_id,vinculacion_tipo,inconsistencia,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE persona_id=VALUES(persona_id),nombre=VALUES(nombre),telefono=VALUES(telefono),rep_encontrado=VALUES(rep_encontrado),clasificacion=VALUES(clasificacion),snapshot_rep_id=VALUES(snapshot_rep_id),vinculacion_tipo=VALUES(vinculacion_tipo),inconsistencia=VALUES(inconsistencia),created_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE)";
            $pdo->prepare($sql)->execute(array(
                $actividadId,$cedula,$result['persona_id'],$result['nombre'],$telefono,$result['rep_encontrado']?1:0,$class['clasificacion'],$snapshotId,
                $class['vinculacion_actual']['tipo'] ?? null,$class['inconsistencia']?1:0
            ));
        }
        if (!$rep && e360v11_table_exists($pdo, 'personas_no_rep')) {
            try {
                $sql = "INSERT INTO personas_no_rep(persona_id,cedula_normalizada,nombre_completo,telefono,estado,municipio,parroquia,motivo,estatus,detectado_en_modulo,detectado_en_entidad_id,fecha_detectado,created_at) VALUES(?,?,?,?,?,?,?,'NO_APARECE_EN_REP','PENDIENTE_INSCRIPCION_REP','ACTIVIDADES',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo),telefono=VALUES(telefono),updated_at=NOW()";
                $pdo->prepare($sql)->execute(array($result['persona_id'],$cedula,$result['nombre'],$telefono,$actividad['estado'] ?? null,$actividad['municipio'] ?? null,$actividad['parroquia'] ?? null,$actividadId));
            } catch (Throwable $e) {}
        }
        return array(
            'persona_id'=>$result['persona_id'],
            'nombre'=>$result['nombre'],
            'rep_encontrado'=>$result['rep_encontrado'],
            'clasificacion'=>$class['clasificacion'],
            'vinculacion_actual'=>$class['vinculacion_actual'],
            'inconsistencia'=>$class['inconsistencia'],
            'snapshot_rep_id'=>$snapshotId
        );
    }
}

if (!function_exists('e360v11_stats')) {
    function e360v11_stats(PDO $pdo) {
        $stats = array('total'=>0,'activistas'=>0,'simpatizantes'=>0,'no_rep'=>0,'actividad_7d'=>0,'inconsistencias'=>0);
        try {
            $row = $pdo->query("SELECT COUNT(*) total,SUM(COALESCE(clasificacion_actual,clasificacion_general)='ACTIVISTA') activistas,SUM(COALESCE(clasificacion_actual,clasificacion_general)='SIMPATIZANTE') simpatizantes,SUM(rep_encontrado=0) no_rep,SUM(ultima_actividad_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) actividad_7d FROM personas WHERE activo=1")->fetch(PDO::FETCH_ASSOC);
            foreach ($stats as $key=>$value) if (isset($row[$key])) $stats[$key] = (int)$row[$key];
        } catch (Throwable $e) {}
        try {
            $stats['inconsistencias'] = (int)$pdo->query("SELECT COUNT(*) FROM expediente360_inconsistencias WHERE estatus='PENDIENTE'")->fetchColumn();
        } catch (Throwable $e) {}
        return $stats;
    }
}

if (!function_exists('e360v11_list_people')) {
    function e360v11_list_people(PDO $pdo, array $filters) {
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int)($filters['per_page'] ?? 50)));
        $where = array('p.activo=1'); $args = array();
        $classification = strtoupper(trim((string)($filters['clasificacion'] ?? '')));
        if (in_array($classification, array('ACTIVISTA','SIMPATIZANTE'), true)) {
            $where[] = "COALESCE(p.clasificacion_actual,p.clasificacion_general,'SIMPATIZANTE')=?";
            $args[] = $classification;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.cedula_normalizada LIKE ? OR p.nombre_completo LIKE ? OR p.telefono_principal LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        $estado = trim((string)($filters['estado'] ?? ''));
        if ($estado !== '') { $where[] = 'p.estado=?'; $args[] = $estado; }
        $W = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM personas p WHERE $W");
        $count->execute($args);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT p.id,p.cedula_normalizada,p.nombre_completo,p.telefono_principal,p.estado,p.municipio,p.parroquia,p.centro,p.rep_encontrado,COALESCE(p.clasificacion_actual,p.clasificacion_general,'SIMPATIZANTE') clasificacion,p.tipo_vinculacion_actual,p.ultima_actividad_at,p.cantidad_actividades,(SELECT COUNT(*) FROM expediente360_inconsistencias i WHERE i.persona_id=p.id AND i.estatus='PENDIENTE') inconsistencias FROM personas p WHERE $W ORDER BY COALESCE(p.ultima_actividad_at,p.updated_at,p.created_at) DESC,p.nombre_completo ASC LIMIT $perPage OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return array('rows'=>$st->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage)));
    }
}

if (!function_exists('e360v11_detail')) {
    function e360v11_detail(PDO $pdo, $cedula) {
        $cedula = e360v11_norm_cedula($cedula);
        $st = $pdo->prepare('SELECT * FROM personas WHERE cedula_normalizada=? LIMIT 1');
        $st->execute(array($cedula));
        $person = $st->fetch(PDO::FETCH_ASSOC);
        if (!$person) return null;
        $class = e360v11_classification($pdo, $cedula);
        $activities = array(); $timeline = array();
        try {
            $st = $pdo->prepare("SELECT aa.actividad_id,aa.clasificacion_al_registro,aa.asistencia,a.titulo,a.fecha_inicio,a.estado,a.municipio,a.parroquia FROM actividad_asistencias aa JOIN actividades a ON a.id=aa.actividad_id WHERE aa.persona_id=? ORDER BY a.fecha_inicio DESC LIMIT 100");
            $st->execute(array($person['id']));
            $activities = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare('SELECT tipo_evento,modulo,titulo,descripcion,fecha_evento FROM persona_timeline WHERE persona_id=? ORDER BY fecha_evento DESC,id DESC LIMIT 100');
            $st->execute(array($person['id']));
            $timeline = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        return array('persona'=>$person,'clasificacion'=>$class,'actividades'=>$activities,'timeline'=>$timeline);
    }
}
