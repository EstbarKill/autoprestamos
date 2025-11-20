<?php
/**
 * jwt.php
 * JWT UNIFICADO PARA DASHBOARD Y EQUIPOS
 * - Genera y valida tokens sin librerías externas
 * - Tipos: dashboard / equipo
 * - Firma HMAC-SHA256
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'UNISIMON_SUPER_SECRET_2025');
}

/* helpers base64url */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/* Generar token genérico */
function generarToken($tipo, $data = []) {
    if (!in_array($tipo, ['dashboard','equipo'])) {
        throw new Exception("Tipo de token inválido");
    }

    $header = base64url_encode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT',
        'rol' => $tipo
    ]));

    $payloadBase = [
        'rol' => $tipo,
        'iat' => time(),
        'exp' => time() + 3600 // 1 hora por defecto
    ];

    $payload = base64url_encode(json_encode(array_merge($payloadBase, $data)));

    $firma = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

    return "$header.$payload.$firma";
}

/* Validar token genérico */
function validarToken($token) {
    if (!$token || !is_string($token)) return null;

    $partes = explode('.', $token);
    if (count($partes) !== 3) return null;

    list($headerB64, $payloadB64, $firmaB64) = $partes;

    $firmaCheck = base64url_encode(hash_hmac('sha256', "$headerB64.$payloadB64", JWT_SECRET, true));
    if (!hash_equals($firmaCheck, $firmaB64)) return null;

    $payloadJson = base64url_decode($payloadB64);
    $payload = json_decode($payloadJson, true);
    if (!$payload) return null;

    if (isset($payload['exp']) && $payload['exp'] < time()) return null;

    return $payload;
}

/* Wrappers simples */
function generarTokenDashboard($usuario, $sede) {
    return generarToken("dashboard", [
        "usuario" => $usuario,
        "sede"    => $sede
    ]);
}

function validarTokenDashboard($token) {
    $payload = validarToken($token);
    return ($payload && ($payload["rol"] ?? '') === "dashboard") ? $payload : null;
}

function generarTokenEquipo($hostname, $mac, $sede) {
    return generarToken("equipo", [
        "equipo" => $hostname,
        "mac"    => $mac,
        "sede"   => $sede
    ]);
}

function validarTokenEquipo($token) {
    $payload = validarToken($token);
    return ($payload && ($payload["rol"] ?? '') === "equipo") ? $payload : null;
}
?>
