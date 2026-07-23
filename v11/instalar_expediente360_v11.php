<?php
/** VPNACIONAL · Instalador Expediente 360 V11 */
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$base=__DIR__;
foreach([$base.'/config/config.php',$base.'/config.php'] as $f){if(is_file($f)){require_once $f;break;}}
if(!isset($pdo)||!($pdo instanceof PDO)){http_response_code(500);exit('PDO no disponible. Verifique config/config.php.');}
$helper=$base.'/includes/expediente360_helpers.php';
if(is_file($helper))require_once $helper;
$done=[];$errors=[];$warnings=[];
function v11run(PDO $pdo,string $sql,string $label,array &$done,array &$errors):void{try{$pdo->exec($sql);$done[]=$label;}catch(Throwable $e){$errors[]=$label.': '.$e->getMessage();}}
function v11table(PDO $pdo,string $table):bool{try{$q=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");$q->execute([$table]);return(bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function v11col(PDO $pdo,string $table,string $column):bool{try{$q=$pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");$q->execute([$table,$column]);return(bool)$q->fetchColumn();}catch(Throwable $e){return false;}}
function v11add(PDO $pdo,string $table,string $column,string $definition,string $label,array &$done,array &$errors):void{if(v11col($pdo,$table,$column)){$done[]=$label.' (ya existía)';return;}v11run($pdo,"ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}",$label,$done,$errors);}
function v11normexpr(string $alias,string $column='cedula'):string{return "CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE({$alias}.`{$column}`,'')),'.',''),'-',''),' ',''),'V',''),'E','') AS UNSIGNED)";}
function v11backupAndInject(string $file,string $backup,string $cssTag,string $jsTag,array &$done,array &$errors):void{
 if(!is_file($file)){$errors[]='No se encontró '.basename($file);return;}
 @copy($file,$backup.'/'.basename($file));$txt=(string)file_get_contents($file);
 $txt=preg_replace('~\s*<link[^>]+expediente360_v11\.css[^>]*>~i','',$txt)??$txt;
 $txt=preg_replace('~\s*<script[^>]+expediente360_v11\.js[^>]*></script>~i','',$txt)??$txt;
 if(stripos($txt,'</head>')!==false)$txt=preg_replace('~</head>~i',$cssTag."\n</head>",$txt,1)??$txt;else$txt=$cssTag."\n".$txt;
 if(stripos($txt,'</body>')!==false)$txt=preg_replace('~</body>~i',$jsTag."\n</body>",$txt,1)??$txt;else$txt.="\n".$jsTag;
 if(file_put_contents($file,$txt)!==false)$done[]='Responsive V11 aplicado a '.basename($file);else$errors[]='No se pudo modificar '.basename($file);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 $stamp=date('Ymd_His');$backup=$base.'/backups/expediente360_v11_'.$stamp;if(!is_dir($backup)&&!@mkdir($backup,0755,true))$warnings[]='No se pudo crear el respaldo automático.';
 v11run($pdo,"CREATE TABLE IF NOT EXISTS persona_rep_snapshots (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,persona_id BIGINT UNSIGNED NOT NULL,actividad_id BIGINT UNSIGNED DEFAULT NULL,cedula_normalizada VARCHAR(40) NOT NULL,fuente VARCHAR(120) DEFAULT 'electores',fuente_version VARCHAR(80) DEFAULT NULL,snapshot_json LONGTEXT NOT NULL,snapshot_hash CHAR(64) NOT NULL,consultado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_snapshot_persona(persona_id),KEY idx_snapshot_actividad(actividad_id),KEY idx_snapshot_cedula(cedula_normalizada),KEY idx_snapshot_hash(snapshot_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",'Snapshots completos del REP',$done,$errors);
 v11run($pdo,"CREATE TABLE IF NOT EXISTS persona_vinculacion_actual (persona_id BIGINT UNSIGNED NOT NULL,tipo_instancia VARCHAR(60) NOT NULL,tabla_origen VARCHAR(120) NOT NULL,registro_origen_id BIGINT UNSIGNED DEFAULT NULL,cargo VARCHAR(220) DEFAULT NULL,estado VARCHAR(120) DEFAULT NULL,municipio VARCHAR(160) DEFAULT NULL,parroquia VARCHAR(180) DEFAULT NULL,fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(persona_id),KEY idx_vinc_tipo(tipo_instancia),KEY idx_vinc_territorio(estado,municipio,parroquia)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",'Vinculación organizativa única',$done,$errors);
 v11run($pdo,"CREATE TABLE IF NOT EXISTS persona_vinculaciones_historial (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,persona_id BIGINT UNSIGNED NOT NULL,tipo_instancia VARCHAR(60) NOT NULL,tabla_origen VARCHAR(120) NOT NULL,registro_origen_id BIGINT UNSIGNED DEFAULT NULL,cargo VARCHAR(220) DEFAULT NULL,estado VARCHAR(120) DEFAULT NULL,municipio VARCHAR(160) DEFAULT NULL,parroquia VARCHAR(180) DEFAULT NULL,fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,fecha_fin DATETIME DEFAULT NULL,estatus VARCHAR(40) NOT NULL DEFAULT 'ACTIVO',motivo_fin VARCHAR(220) DEFAULT NULL,datos_json LONGTEXT DEFAULT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_vh_persona(persona_id),KEY idx_vh_tipo(tipo_instancia),KEY idx_vh_estatus(estatus)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",'Historial de traslados y vinculaciones',$done,$errors);
 v11run($pdo,"CREATE TABLE IF NOT EXISTS expediente360_inconsistencias (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,cedula_normalizada VARCHAR(40) NOT NULL,persona_id BIGINT UNSIGNED DEFAULT NULL,tipo VARCHAR(100) NOT NULL DEFAULT 'DOBLE_VINCULACION_ACTIVA',detalle_json LONGTEXT DEFAULT NULL,estatus VARCHAR(40) NOT NULL DEFAULT 'PENDIENTE',detectado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resuelto_at DATETIME DEFAULT NULL,PRIMARY KEY(id),UNIQUE KEY uq_inc_activa(cedula_normalizada,tipo,estatus),KEY idx_inc_estado(estatus)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",'Bandeja de inconsistencias organizativas',$done,$errors);
 if(v11table($pdo,'actividad_asistencias')){
  v11add($pdo,'actividad_asistencias','snapshot_rep_id','BIGINT UNSIGNED DEFAULT NULL','Vínculo con snapshot REP',$done,$errors);
  v11add($pdo,'actividad_asistencias','clasificacion_al_registro',"VARCHAR(40) DEFAULT NULL",'Clasificación histórica de asistencia',$done,$errors);
  v11add($pdo,'actividad_asistencias','vinculacion_tipo_al_registro',"VARCHAR(60) DEFAULT NULL",'Vinculación histórica de asistencia',$done,$errors);
 }
 if(v11table($pdo,'personas')){
  v11add($pdo,'personas','clasificacion_actual',"VARCHAR(40) DEFAULT 'SIMPATIZANTE'",'Clasificación actual',$done,$errors);
  v11add($pdo,'personas','tipo_vinculacion_actual','VARCHAR(60) DEFAULT NULL','Tipo de vinculación actual',$done,$errors);
  v11add($pdo,'personas','cantidad_actividades','INT NOT NULL DEFAULT 0','Contador de actividades',$done,$errors);
 }
 // Recalcular clasificación usando las tablas organizativas realmente disponibles.
 try{
  $sources=[];
  $defs=[
   ['ESTRUCTURA','estructuras_miembros','cedula'],
   ['RED_POPULAR','redes_populares_miembros','cedula'],
   ['CENTRO_VOTACION','centros_votacion_miembros','cedula'],
   ['CENTRO_VOTACION','centros_miembros','cedula'],
   ['CENTRO_VOTACION','equipos_centros_miembros','cedula'],
   ['CENTRO_VOTACION','centro_votacion_miembros','cedula'],
   ['CENTRO_VOTACION','centros_equipo','cedula']
  ];
  foreach($defs as [$tipo,$table,$cc]){
   if(!v11table($pdo,$table)||!v11col($pdo,$table,$cc))continue;
   $conds=[];if(v11col($pdo,$table,'activo'))$conds[]="COALESCE(x.activo,1)=1";if(v11col($pdo,$table,'estatus'))$conds[]="UPPER(COALESCE(x.estatus,'ACTIVO')) NOT IN('INACTIVO','RETIRADO','FINALIZADO','VACANTE')";
   $sources[]=['tipo'=>$tipo,'table'=>$table,'cc'=>$cc,'where'=>$conds?implode(' AND ',$conds):'1=1'];
  }
  $exists=[];foreach($sources as $s){$exists[]="EXISTS(SELECT 1 FROM `{$s['table']}` x WHERE ".v11normexpr('x',$s['cc'])."=CAST(p.cedula_normalizada AS UNSIGNED) AND {$s['where']})";}
  $activeExpr=$exists?'('.implode(' OR ',$exists).')':'0';
  $pdo->exec("UPDATE personas p SET p.es_activista=IF({$activeExpr},1,0),p.clasificacion_actual=IF({$activeExpr},'ACTIVISTA','SIMPATIZANTE'),p.clasificacion_general=IF({$activeExpr},'ACTIVISTA','SIMPATIZANTE')");
  $pdo->exec("UPDATE personas p SET p.cantidad_actividades=(SELECT COUNT(*) FROM actividad_asistencias aa WHERE aa.persona_id=p.id OR ".v11normexpr('aa')."=CAST(p.cedula_normalizada AS UNSIGNED))");
  $done[]='Activistas y simpatizantes recalculados con las fuentes disponibles';
  // Detectar más de una asignación activa, incluso dentro del mismo módulo.
  if($sources){$parts=[];foreach($sources as $s){$parts[]="SELECT ".v11normexpr('x',$s['cc'])." cedula,'{$s['tipo']}' fuente FROM `{$s['table']}` x WHERE {$s['where']} AND ".v11normexpr('x',$s['cc']).">0";}$union=implode(' UNION ALL ',$parts);$sql="INSERT IGNORE INTO expediente360_inconsistencias(cedula_normalizada,persona_id,tipo,detalle_json,estatus,detectado_at) SELECT CAST(z.cedula AS CHAR),p.id,'DOBLE_VINCULACION_ACTIVA',JSON_OBJECT('asignaciones_activas',z.total),'PENDIENTE',NOW() FROM (SELECT cedula,COUNT(*) total FROM ({$union}) u GROUP BY cedula HAVING COUNT(*)>1) z LEFT JOIN personas p ON CAST(p.cedula_normalizada AS UNSIGNED)=z.cedula";$pdo->exec($sql);$done[]='Dobles vinculaciones activas detectadas sin eliminar información';}
 }catch(Throwable $e){$errors[]='Reclasificación organizativa: '.$e->getMessage();}
 // Aplicar recursos responsive sobre los módulos existentes.
 $css='<link rel="stylesheet" href="assets/css/expediente360_v11.css?v=2026.07.23.11">';
 $js='<script src="assets/js/expediente360_v11.js?v=2026.07.23.11" defer></script>';
 foreach(['activistas.php','activista_detalle.php'] as $name)v11backupAndInject($base.'/'.$name,$backup,$css,$js,$done,$errors);
 // Mantener el enlace real del menú en activistas.php.
 foreach([$base.'/includes/layout.php',$base.'/includes/sidebar.php',$base.'/includes/menu.php',$base.'/partials/sidebar.php',$base.'/sidebar.php'] as $menu){
  if(!is_file($menu))continue;@copy($menu,$backup.'/'.basename($menu));$txt=(string)file_get_contents($menu);$original=$txt;
  $txt=str_ireplace(['href="expediente360.php"','href=\'expediente360.php\''],['href="activistas.php"','href=\'activistas.php\''],$txt);
  $txt=preg_replace('~(<a[^>]+href=["\']activistas\.php[^>]*>.*?)(>\s*Activistas\s*<|>\s*Activistas y.*?<)~is','$1>Expediente 360°<',$txt)??$txt;
  if($txt!==$original&&file_put_contents($menu,$txt)!==false)$done[]='Menú enlazado al Expediente 360° existente';
  break;
 }
 // Marcar la versión sin borrar las páginas creadas previamente.
 @file_put_contents($base.'/includes/expediente360_v11.version','2026.07.23.11');
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalador Expediente 360 V11</title><style>body{font-family:Inter,Arial;background:#061d38;margin:0;color:#071c3a}.wrap{max-width:920px;margin:38px auto;padding:18px}.card{background:#fff;border-radius:24px;padding:30px;box-shadow:0 22px 70px #0005}.brand{color:#ff7a00;font-weight:900;letter-spacing:.08em}.btn{display:inline-block;background:#ff7a00;border:0;color:#fff;text-decoration:none;padding:14px 20px;border-radius:13px;font-weight:900;cursor:pointer}.ok,.err,.warn{padding:12px 14px;border-radius:12px;margin:8px 0}.ok{background:#edfff4;border:1px solid #9fe4bb}.err{background:#fff0f0;border:1px solid #f1a5a5}.warn{background:#fff7e8;border:1px solid #ffd392}code{background:#eef1f6;padding:3px 6px;border-radius:6px}@media(max-width:600px){.wrap{margin:0;padding:10px}.card{padding:20px;border-radius:18px}}</style></head><body><div class="wrap"><div class="card"><div class="brand">VOLUNTAD POPULAR · VPNACIONAL</div><h1>Expediente 360° existente · V11</h1><p>Actualiza directamente <code>activistas.php</code> y <code>activista_detalle.php</code>, aplica el responsive móvil, integra asistentes y clasifica correctamente Activistas y Simpatizantes.</p><?php foreach($done as $x):?><div class="ok">✓ <?=htmlspecialchars($x)?></div><?php endforeach;?><?php foreach($warnings as $x):?><div class="warn">⚠ <?=htmlspecialchars($x)?></div><?php endforeach;?><?php foreach($errors as $x):?><div class="err">✕ <?=htmlspecialchars($x)?></div><?php endforeach;?><?php if($_SERVER['REQUEST_METHOD']!=='POST'):?><form method="post"><button class="btn">Aplicar corrección V11</button></form><?php else:?><p><a class="btn" href="activistas.php">Abrir Expediente 360°</a></p><p>Actualiza con <b>Ctrl + F5</b> y después elimina <code>instalar_expediente360_v11.php</code>.</p><?php endif;?></div></div></body></html>
