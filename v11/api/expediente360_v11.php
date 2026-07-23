<?php
/** VPNACIONAL · API Expediente 360 V11 */
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$base = dirname(__DIR__);
foreach ([$base.'/config/config.php', $base.'/config.php'] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Conexión PDO no disponible.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$helper = $base.'/includes/expediente360_helpers.php';
if (!is_file($helper)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Helper de Expediente 360 no instalado.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $helper;

function e360v11_out(array $data, int $status=200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function e360v11_person(PDO $pdo, string $cedula): ?array {
    $num=e360_norm_cedula($cedula);
    if ($num==='') return null;
    $q=$pdo->prepare("SELECT * FROM personas WHERE cedula_normalizada=? OR CAST(cedula_numero AS UNSIGNED)=CAST(? AS UNSIGNED) LIMIT 1");
    $q->execute([$num,$num]);
    $r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function e360v11_safe_count(PDO $pdo,string $sql,array $params=[]): int {
    try{$q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();}catch(Throwable $e){return 0;}
}

$action=(string)($_GET['action']??$_POST['action']??'summary');
try {
    if ($action==='summary') {
        $total=e360v11_safe_count($pdo,"SELECT COUNT(*) FROM personas WHERE COALESCE(activo,1)=1");
        $activistas=e360v11_safe_count($pdo,"SELECT COUNT(*) FROM personas WHERE COALESCE(activo,1)=1 AND (clasificacion_actual='ACTIVISTA' OR es_activista=1)");
        $simpatizantes=max(0,$total-$activistas);
        $noRep=e360v11_safe_count($pdo,"SELECT COUNT(*) FROM personas WHERE COALESCE(activo,1)=1 AND COALESCE(rep_encontrado,0)=0");
        $recientes=e360v11_safe_count($pdo,"SELECT COUNT(*) FROM personas WHERE COALESCE(activo,1)=1 AND ultima_actividad_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
        $incons=e360_table_exists($pdo,'expediente360_inconsistencias')?e360v11_safe_count($pdo,"SELECT COUNT(*) FROM expediente360_inconsistencias WHERE estatus='PENDIENTE'"):0;
        e360v11_out(['ok'=>true,'data'=>compact('total','activistas','simpatizantes','noRep','recientes','incons')]);
    }

    if ($action==='classify_batch') {
        $raw=file_get_contents('php://input');
        $body=$raw?json_decode($raw,true):null;
        $cedulas=is_array($body)&&isset($body['cedulas'])&&is_array($body['cedulas'])?$body['cedulas']:($_POST['cedulas']??[]);
        $cedulas=array_slice(array_values(array_unique(array_filter(array_map('e360_norm_cedula',(array)$cedulas)))),0,60);
        $items=[];
        foreach($cedulas as $cedula){
            $class=e360_classification($pdo,$cedula);
            $person=e360v11_person($pdo,$cedula);
            if($person){
                try{$pdo->prepare("UPDATE personas SET es_activista=?,clasificacion_actual=?,clasificacion_general=?,tipo_vinculacion_actual=?,updated_at=NOW() WHERE id=?")
                    ->execute([$class['es_activista'],$class['clasificacion'],$class['clasificacion'],$class['vinculaciones'][0]['tipo']??null,$person['id']]);}catch(Throwable $e){}
            }
            $items[$cedula]=[
                'clasificacion'=>$class['clasificacion'],
                'es_activista'=>(int)$class['es_activista'],
                'inconsistencia'=>(bool)$class['inconsistencia'],
                'vinculaciones'=>array_map(static function($v){return ['tipo'=>$v['tipo']??'', 'cargo'=>$v['cargo']??'', 'tabla'=>$v['tabla']??'', 'registro_id'=>$v['registro_id']??null];},$class['vinculaciones'])
            ];
        }
        e360v11_out(['ok'=>true,'items'=>$items]);
    }

    if ($action==='detail') {
        $cedula=e360_norm_cedula((string)($_GET['cedula']??$_POST['cedula']??''));
        if($cedula==='') e360v11_out(['ok'=>false,'error'=>'Cédula requerida.'],422);
        $person=e360v11_person($pdo,$cedula);
        $class=e360_classification($pdo,$cedula);
        $activities=[];
        try{
            $q=$pdo->prepare("SELECT aa.actividad_id,aa.asistencia,aa.clasificacion_al_registro,aa.created_at,a.titulo,a.fecha_inicio,a.estado,a.municipio,a.parroquia FROM actividad_asistencias aa LEFT JOIN actividades a ON a.id=aa.actividad_id WHERE CAST(REPLACE(REPLACE(REPLACE(aa.cedula,'.',''),'V',''),'E','') AS UNSIGNED)=CAST(? AS UNSIGNED) ORDER BY COALESCE(a.fecha_inicio,aa.created_at) DESC LIMIT 20");
            $q->execute([$cedula]);$activities=$q->fetchAll(PDO::FETCH_ASSOC);
        }catch(Throwable $e){}
        e360v11_out(['ok'=>true,'persona'=>$person,'clasificacion'=>$class['clasificacion'],'inconsistencia'=>(bool)$class['inconsistencia'],'vinculaciones'=>$class['vinculaciones'],'actividades'=>$activities]);
    }

    if ($action==='list') {
        $qText=trim((string)($_GET['q']??''));
        $class=strtoupper(trim((string)($_GET['clasificacion']??'')));
        $state=trim((string)($_GET['estado']??''));
        $page=max(1,(int)($_GET['page']??1));
        $per=max(10,min(100,(int)($_GET['per_page']??50)));
        $where=["COALESCE(activo,1)=1"];$params=[];
        if($qText!==''){$where[]="(cedula_normalizada LIKE ? OR nombre_completo LIKE ? OR telefono_principal LIKE ?)";$like='%'.$qText.'%';array_push($params,$like,$like,$like);}
        if(in_array($class,['ACTIVISTA','SIMPATIZANTE'],true)){$where[]="clasificacion_actual=?";$params[]=$class;}
        if($state!==''){$where[]="estado=?";$params[]=$state;}
        $w=implode(' AND ',$where);
        $cq=$pdo->prepare("SELECT COUNT(*) FROM personas WHERE $w");$cq->execute($params);$total=(int)$cq->fetchColumn();
        $offset=($page-1)*$per;
        $sql="SELECT id,cedula_normalizada,nombre_completo,telefono_principal,estado,municipio,parroquia,rep_encontrado,clasificacion_actual,tipo_vinculacion_actual,ultima_actividad_at,activo FROM personas WHERE $w ORDER BY COALESCE(ultima_actividad_at,created_at) DESC,nombre_completo ASC LIMIT $per OFFSET $offset";
        $lq=$pdo->prepare($sql);$lq->execute($params);$rows=$lq->fetchAll(PDO::FETCH_ASSOC);
        e360v11_out(['ok'=>true,'total'=>$total,'page'=>$page,'per_page'=>$per,'rows'=>$rows]);
    }

    e360v11_out(['ok'=>false,'error'=>'Acción no reconocida.'],404);
} catch(Throwable $e) {
    e360v11_out(['ok'=>false,'error'=>$e->getMessage()],500);
}
