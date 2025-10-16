<?php
// utils.php

function jsonError($msg, $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(["estado" => "Error", "mensaje" => $msg], $extra));
    exit;
}

function jsonOk($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fechaFolioUTC($fechaLocal = null) {
    $date = $fechaLocal
        ? new DateTime($fechaLocal, new DateTimeZone('America/Bogota'))
        : new DateTime('now', new DateTimeZone('America/Bogota'));
    $date->setTimezone(new DateTimeZone('UTC'));
    return $date->format('Y-m-d\TH:i:s.000\Z');
}

function debugLog($msg, $data = null) {
    $log = "[DEBUG " . date('H:i:s') . "] " . $msg;
    if ($data !== null) $log .= " | DATA: " . print_r($data, true);
    error_log($log);
}