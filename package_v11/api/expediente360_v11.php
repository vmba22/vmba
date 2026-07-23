<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$root = dirname(__DIR__);
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
    echo json_encode(array('ok'=>false,'error'=>'No fue posible iniciar la conexión con la base de datos.'));
    exit;
}

function e360v11_json_input() {
    $data = $_POST;
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) $data = array_merge($data, $json);
    }
    return $data;
}

function e360v11_out($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $input = e360v11_json_input();
    $action = strtolower(trim((string)($input['action'] ?? $_GET['action'] ?? 'stats')));

    if ($action === 'stats') {
        e360v11_out(array('ok'=>true,'stats'=>e360v11_stats($pdo)));
    }

    if ($action === 'list') {
        $filters = array_merge($_GET, $input);
        e360v11_out(array('ok'=>true,'data'=>e360v11_list_people($pdo, $filters)));
    }

    if ($action === 'detail') {
        $cedula = (string)($input['cedula'] ?? $_GET['cedula'] ?? '');
        $detail = e360v11_detail($pdo, $cedula);
        if (!$detail) e360v11_out(array('ok'=>false,'error'=>'Expediente no encontrado.'), 404);
        e360v11_out(array('ok'=>true,'data'=>$detail));
    }

    if ($action === 'classify') {
        $cedulas = $input['cedulas'] ?? array($input['cedula'] ?? $_GET['cedula'] ?? '');
        if (!is_array($cedulas)) $cedulas = array($cedulas);
        $result = array();
        foreach (array_slice($cedulas, 0, 100) as $cedula) {
            $normalized = e360v11_norm_cedula($cedula);
            if ($normalized === '') continue;
            $result[$normalized] = e360v11_classification($pdo, $normalized);
        }
        e360v11_out(array('ok'=>true,'data'=>$result));
    }

    if ($action === 'lookup_rep') {
        $cedula = e360v11_norm_cedula($input['cedula'] ?? $_GET['cedula'] ?? '');
        if ($cedula === '') throw new RuntimeException('Ingrese una cédula válida.');
        $rep = e360v11_find_elector($pdo, $cedula);
        if (!$rep) e360v11_out(array('ok'=>true,'found'=>false,'nombre'=>''));
        $person = e360v11_person_from_rep($rep);
        e360v11_out(array('ok'=>true,'found'=>true,'nombre'=>$person['nombre_completo']));
    }

    if ($action === 'prepare_attendee') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') e360v11_out(array('ok'=>false,'error'=>'Método no permitido.'), 405);
        $result = e360v11_prepare_attendee(
            $pdo,
            (int)($input['actividad_id'] ?? $input['id'] ?? 0),
            (string)($input['cedula'] ?? ''),
            (string)($input['telefono'] ?? $input['telefono_reportado'] ?? ''),
            (string)($input['nombre'] ?? $input['nombre_completo'] ?? '')
        );
        e360v11_out(array('ok'=>true,'data'=>$result));
    }

    e360v11_out(array('ok'=>false,'error'=>'Acción no reconocida.'), 400);
} catch (Throwable $e) {
    e360v11_out(array('ok'=>false,'error'=>$e->getMessage()), 422);
}
