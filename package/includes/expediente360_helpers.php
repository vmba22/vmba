<?php
/** VPNACIONAL · Expediente 360 V10 */
declare(strict_types=1);

if (!function_exists('e360_norm_cedula')) {
    function e360_norm_cedula($value): string {
        return preg_replace('/\D+/', '', (string)$value) ?? '';
    }
}

if (!function_exists('e360_table_exists')) {
    function e360_table_exists(PDO $pdo, string $table): bool {
        static $cache = [];
        $key = spl_object_hash($pdo).':'.$table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
            $q->execute([$table]);
            return $cache[$key]=(bool)$q->fetchColumn();
        } catch (Throwable $e) { return $cache[$key]=false; }
    }
}

if (!function_exists('e360_columns')) {
    function e360_columns(PDO $pdo, string $table): array {
        static $cache=[];
        $key=spl_object_hash($pdo).':'.$table;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $q=$pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $q->execute([$table]);
            return $cache[$key]=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN), true);
        } catch(Throwable $e){ return $cache[$key]=[]; }
    }
}

if (!function_exists('e360_pick')) {
    function e360_pick(array $row, array $keys, $default=null) {
        foreach ($keys as $k) if (array_key_exists($k,$row) && $row[$k]!==null && $row[$k]!=='') return $row[$k];
        return $default;
    }
}

if (!function_exists('e360_rep_pdo')) {
    function e360_rep_pdo(PDO $main): PDO {
        foreach (['pdoElectores','pdo_electores','electoresPdo','repPdo','pdo_rep'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) return $GLOBALS[$name];
        }
        return $main;
    }
}

if (!function_exists('e360_find_elector')) {
    function e360_find_elector(PDO $main, string $cedula): ?array {
        $num=e360_norm_cedula($cedula); if ($num==='') return null;
        $pdo=e360_rep_pdo($main);
        foreach (['electores','rep','elector'] as $table) {
            try {
                $cols=e360_columns($pdo,$table); if (!$cols) continue;
                $candidate=null;
                foreach (['CEDULA_IDENTIDAD','cedula_identidad','cedula','CEDULA','documento'] as $c) if(isset($cols[$c])){$candidate=$c;break;}
                if(!$candidate) continue;
                $sql="SELECT * FROM `{$table}` WHERE CAST(REPLACE(REPLACE(REPLACE(`{$candidate}`,'.',''),'V',''),'E','') AS UNSIGNED)=CAST(? AS UNSIGNED) LIMIT 1";
                $q=$pdo->prepare($sql); $q->execute([$num]); $r=$q->fetch(PDO::FETCH_ASSOC);
                if($r){$r['_e360_fuente']=$table;return $r;}
            } catch(Throwable $e) { continue; }
        }
        return null;
    }
}

if (!function_exists('e360_person_data_from_rep')) {
    function e360_person_data_from_rep(array $r): array {
        $pn=(string)e360_pick($r,['P_NOMBRE','PRI_NOMBRE','pri_nombre','primer_nombre','nombre'],'');
        $sn=(string)e360_pick($r,['S_NOMBRE','SEG_NOMBRE','seg_nombre','segundo_nombre'],'');
        $pa=(string)e360_pick($r,['P_APELLIDO','PRI_APELLIDO','pri_apellido','primer_apellido','apellido'],'');
        $sa=(string)e360_pick($r,['S_APELLIDO','SEG_APELLIDO','seg_apellido','segundo_apellido'],'');
        $full=trim(implode(' ',array_filter([$pn,$sn,$pa,$sa])));
        if($full==='') $full=(string)e360_pick($r,['NOMBRE_COMPLETO','nombre_completo','NOMBRE'],'');
        return [
            'pri_nombre'=>$pn,'seg_nombre'=>$sn,'pri_apellido'=>$pa,'seg_apellido'=>$sa,'nombre_completo'=>$full,
            'sexo'=>(string)e360_pick($r,['SEXO','sexo','GENERO','genero'],''),
            'fecha_nacimiento'=>e360_pick($r,['FECHA_NACIMIENTO','fecha_nacimiento','F_NACIMIENTO'],null),
            'cod_estado'=>e360_pick($r,['COD_ESTADO','cod_estado','COD_ENTIDAD','cod_entidad'],null),
            'estado'=>e360_pick($r,['ESTADO','estado','ENTIDAD','entidad'],null),
            'cod_municipio'=>e360_pick($r,['COD_MUNICIPIO','cod_municipio'],null),
            'municipio'=>e360_pick($r,['MUNICIPIO','municipio'],null),
            'cod_parroquia'=>e360_pick($r,['COD_PARROQUIA','cod_parroquia'],null),
            'parroquia'=>e360_pick($r,['PARROQUIA','parroquia'],null),
            'cod_centro'=>e360_pick($r,['COD_CENTRO','cod_centro','CODIGO_CENTRO'],null),
            'centro'=>e360_pick($r,['CENTRO','centro','NOMBRE_CENTRO'],null),
            'direccion_centro'=>e360_pick($r,['DIRECCION_CENTRO','direccion_centro','DIRECCION'],null),
        ];
    }
}

if (!function_exists('e360_active_links')) {
    function e360_active_links(PDO $pdo,string $cedula): array {
        $num=e360_norm_cedula($cedula); $out=[]; if($num==='') return $out;
        $defs=[
            ['ESTRUCTURA','estructuras_miembros','cedula',"activo=1 AND COALESCE(estatus,'ACTIVO') NOT IN ('INACTIVO','RETIRADO','FINALIZADO')",'cargo_id'],
            ['RED_POPULAR','redes_populares_miembros','cedula',"COALESCE(estatus,'ACTIVO') NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE')",'cargo'],
            ['CENTRO_VOTACION','centros_votacion_miembros','cedula',"COALESCE(activo,1)=1 AND COALESCE(estatus,'ACTIVO') NOT IN ('INACTIVO','RETIRADO','FINALIZADO')",'cargo'],
            ['CENTRO_VOTACION','centros_miembros','cedula',"COALESCE(activo,1)=1 AND COALESCE(estatus,'ACTIVO') NOT IN ('INACTIVO','RETIRADO','FINALIZADO')",'cargo'],
            ['CENTRO_VOTACION','equipos_centros_miembros','cedula',"COALESCE(activo,1)=1 AND COALESCE(estatus,'ACTIVO') NOT IN ('INACTIVO','RETIRADO','FINALIZADO')",'cargo'],
        ];
        foreach($defs as [$tipo,$table,$cc,$where,$cargo]){
            if(!e360_table_exists($pdo,$table)) continue;
            $cols=e360_columns($pdo,$table); if(!isset($cols[$cc])) continue;
            $safeWhere=[];
            if(isset($cols['activo'])) $safeWhere[]='COALESCE(activo,1)=1';
            if(isset($cols['estatus'])) $safeWhere[]="UPPER(COALESCE(estatus,'ACTIVO')) NOT IN ('INACTIVO','RETIRADO','FINALIZADO','VACANTE')";
            $w=$safeWhere?implode(' AND ',$safeWhere):'1=1';
            try{
                $q=$pdo->prepare("SELECT * FROM `{$table}` WHERE CAST(REPLACE(REPLACE(REPLACE(`{$cc}`,'.',''),'V',''),'E','') AS UNSIGNED)=CAST(? AS UNSIGNED) AND {$w}");
                $q->execute([$num]);
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
                    $out[]=['tipo'=>$tipo,'tabla'=>$table,'registro_id'=>$r['id']??null,'cargo'=>$r[$cargo]??null,'datos'=>$r];
                }
            }catch(Throwable $e){}
        }
        return $out;
    }
}

if (!function_exists('e360_classification')) {
    function e360_classification(PDO $pdo,string $cedula): array {
        $links=e360_active_links($pdo,$cedula);
        return ['clasificacion'=>$links?'ACTIVISTA':'SIMPATIZANTE','es_activista'=>$links?1:0,'vinculaciones'=>$links,'inconsistencia'=>count($links)>1];
    }
}

if (!function_exists('e360_upsert_person')) {
    function e360_upsert_person(PDO $pdo,string $cedula,string $telefono='',?array $rep=null,string $manualName='',string $origin='ACTIVIDAD'): int {
        $num=e360_norm_cedula($cedula); if($num==='') throw new RuntimeException('Cédula inválida.');
        $data=$rep?e360_person_data_from_rep($rep):['nombre_completo'=>trim($manualName)];
        $class=e360_classification($pdo,$num);
        $q=$pdo->prepare("SELECT id,telefono_principal FROM personas WHERE cedula_normalizada=? LIMIT 1"); $q->execute([$num]); $existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            $sets=['nombre_completo=COALESCE(NULLIF(?,\'\'),nombre_completo)','telefono_principal=COALESCE(NULLIF(?,\'\'),telefono_principal)','rep_encontrado=?','rep_checked_at=NOW()','es_asistente_general=1','es_activista=?','clasificacion_general=?','ultima_actividad_at=NOW()','updated_at=NOW()'];
            $vals=[$data['nombre_completo']??'',$telefono,$rep?1:0,$class['es_activista'],$class['clasificacion']];
            foreach(['pri_nombre','seg_nombre','pri_apellido','seg_apellido','sexo','fecha_nacimiento','cod_estado','estado','cod_municipio','municipio','cod_parroquia','parroquia','cod_centro','centro','direccion_centro'] as $c){
                if(array_key_exists($c,$data) && $data[$c]!==null && $data[$c]!==''){$sets[]="`$c`=COALESCE(NULLIF(?,''),`$c`)";$vals[]=$data[$c];}
            }
            $vals[]=$existing['id']; $pdo->prepare('UPDATE personas SET '.implode(',',$sets).' WHERE id=?')->execute($vals); $id=(int)$existing['id'];
        }else{
            $cols=['cedula','cedula_numero','cedula_normalizada','nombre_completo','telefono_principal','rep_encontrado','rep_checked_at','es_asistente_general','es_activista','clasificacion_general','origen_primer_registro','primera_actividad_at','ultima_actividad_at','activo'];
            $vals=[$num,$num,$num,$data['nombre_completo']??$manualName,$telefono,$rep?1:0,date('Y-m-d H:i:s'),1,$class['es_activista'],$class['clasificacion'],$origin,date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),1];
            foreach(['pri_nombre','seg_nombre','pri_apellido','seg_apellido','sexo','fecha_nacimiento','cod_estado','estado','cod_municipio','municipio','cod_parroquia','parroquia','cod_centro','centro','direccion_centro'] as $c){if(array_key_exists($c,$data)&&$data[$c]!==null&&$data[$c]!==''){$cols[]=$c;$vals[]=$data[$c];}}
            $pdo->prepare('INSERT INTO personas (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute($vals); $id=(int)$pdo->lastInsertId();
        }
        if($telefono!==''){
            $q=$pdo->prepare("SELECT id FROM persona_contactos WHERE persona_id=? AND tipo='telefono' AND valor=? LIMIT 1");$q->execute([$id,$telefono]);
            if(!$q->fetchColumn())$pdo->prepare("INSERT INTO persona_contactos(persona_id,tipo,valor,es_principal,vigente,fuente,created_at) VALUES(?,'telefono',?,1,1,?,NOW())")->execute([$id,$telefono,$origin]);
        }
        return $id;
    }
}

if (!function_exists('e360_store_snapshot')) {
    function e360_store_snapshot(PDO $pdo,int $personaId,int $actividadId,string $cedula,?array $rep): void {
        if(!$rep)return; $json=json_encode($rep,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); $hash=hash('sha256',(string)$json);
        $pdo->prepare("INSERT INTO persona_rep_snapshots(persona_id,actividad_id,cedula_normalizada,fuente,fuente_version,snapshot_json,snapshot_hash,consultado_at) VALUES(?,?,?,?,?,?,?,NOW())")->execute([$personaId,$actividadId,e360_norm_cedula($cedula),$rep['_e360_fuente']??'electores',date('Y-m-d'),$json,$hash]);
    }
}

if (!function_exists('e360_timeline')) {
    function e360_timeline(PDO $pdo,int $personaId,string $tipo,string $titulo,string $descripcion,array $datos=[],?int $entidadId=null): void {
        $uid=$_SESSION['user_id']??$_SESSION['usuario_id']??null; $un=$_SESSION['nombre']??$_SESSION['usuario_nombre']??null;
        $pdo->prepare("INSERT INTO persona_timeline(persona_id,tipo_evento,modulo,entidad,entidad_id,titulo,descripcion,datos_json,registrado_por_user_id,registrado_por_nombre,fecha_evento,created_at) VALUES(?,?,'ACTIVIDADES','actividad_asistencias',?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$personaId,$tipo,$entidadId,$titulo,$descripcion,json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$uid,$un]);
    }
}

if (!function_exists('e360_register_attendee')) {
    function e360_register_attendee(PDO $pdo,int $actividadId,string $cedula,string $telefono,string $manualName=''): array {
        $num=e360_norm_cedula($cedula); if($num==='')throw new RuntimeException('Ingrese una cédula válida.'); if(trim($telefono)==='')throw new RuntimeException('El teléfono es obligatorio.');
        $q=$pdo->prepare("SELECT id,titulo,meta_asistencia,estado,municipio,parroquia,fecha_inicio FROM actividades WHERE id=? LIMIT 1");$q->execute([$actividadId]);$act=$q->fetch(PDO::FETCH_ASSOC);if(!$act)throw new RuntimeException('Actividad no encontrada.');
        $q=$pdo->prepare("SELECT id FROM actividad_asistencias WHERE actividad_id=? AND CAST(REPLACE(REPLACE(REPLACE(cedula,'.',''),'V',''),'E','') AS UNSIGNED)=CAST(? AS UNSIGNED) LIMIT 1");$q->execute([$actividadId,$num]);if($q->fetchColumn())throw new RuntimeException('Esta cédula ya está registrada en la actividad.');
        $rep=e360_find_elector($pdo,$num);$pd=$rep?e360_person_data_from_rep($rep):['nombre_completo'=>trim($manualName)]; if(trim((string)($pd['nombre_completo']??''))==='')throw new RuntimeException('La persona no aparece en REP. Escriba su nombre y apellido.');
        $class=e360_classification($pdo,$num);$personId=e360_upsert_person($pdo,$num,$telefono,$rep,$manualName,'ASISTENCIA_ACTIVIDAD');
        $q=$pdo->prepare("SELECT COUNT(*) FROM actividad_asistencias WHERE actividad_id=? AND asistencia='asistio'");$q->execute([$actividadId]);$before=(int)$q->fetchColumn();$meta=(int)($act['meta_asistencia']??0);$extra=($meta>0&&$before>=$meta)?1:0;
        $pdo->prepare("INSERT INTO actividad_asistencias(actividad_id,persona_id,cedula,nombre,telefono,telefono_reportado,estado,municipio,parroquia,asistencia,es_adicional,hora_registro,rep_encontrado,es_activista_politico,clasificacion_asistente,fuente_identificacion,registrado_at,origen_registro,ip_registro,user_agent_registro,created_at) VALUES(?,?,?,?,?,?,?,?,?,'asistio',?,CURTIME(),?,?,?,?,NOW(),'REGISTRO_ASISTENCIA_V10',?,?,NOW())")
            ->execute([$actividadId,$personId,$num,$pd['nombre_completo'],$telefono,$telefono,$act['estado']??null,$act['municipio']??null,$act['parroquia']??null,$extra,$rep?1:0,$class['es_activista'],$class['clasificacion'],$rep?'REP':'MANUAL',$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);
        $asistenciaId=(int)$pdo->lastInsertId(); e360_store_snapshot($pdo,$personId,$actividadId,$num,$rep);
        e360_timeline($pdo,$personId,'ASISTENCIA_ACTIVIDAD','Participó en '.$act['titulo'],'Registrado como asistente de la actividad.',['actividad_id'=>$actividadId,'actividad'=>$act['titulo'],'telefono_reportado'=>$telefono,'rep_encontrado'=>(bool)$rep,'clasificacion'=>$class['clasificacion'],'vinculacion_activa'=>$class['vinculaciones'][0]['tipo']??null],$asistenciaId);
        if(!$rep && e360_table_exists($pdo,'personas_no_rep')){
            try{$pdo->prepare("INSERT INTO personas_no_rep(persona_id,cedula_normalizada,nombre_completo,telefono,estado,municipio,parroquia,motivo,estatus,detectado_en_modulo,detectado_en_entidad_id,fecha_detectado,created_at) VALUES(?,?,?,?,?,?,?,'NO_APARECE_EN_REP','PENDIENTE_INSCRIPCION_REP','ACTIVIDADES',?,NOW(),NOW()) ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo),telefono=VALUES(telefono),updated_at=NOW()")
                ->execute([$personId,$num,$pd['nombre_completo'],$telefono,$act['estado']??null,$act['municipio']??null,$act['parroquia']??null,$actividadId]);}catch(Throwable $e){}
        }
        return ['id'=>$asistenciaId,'persona_id'=>$personId,'nombre'=>$pd['nombre_completo'],'clasificacion'=>$class['clasificacion'],'rep_encontrado'=>(bool)$rep,'es_adicional'=>(bool)$extra,'inconsistencia'=>$class['inconsistencia']];
    }
}
