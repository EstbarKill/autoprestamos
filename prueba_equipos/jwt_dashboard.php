<?php
require_once __DIR__ . '/../servers/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Definir clave si no existe aún
if (!defined('JWT_SECRET_DASHBOARD')) {
    define('JWT_SECRET_DASHBOARD', $_ENV['JWT_SECRET'] ?? 'DEFAULT_SECRET');
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * =============================
 *   GENERAR TOKEN DASHBOARD
 * =============================
 */
function generarTokenDashboardRaw($usuario, $sede) {

    $header = base64url_encode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT'
    ]));

    $payload = base64url_encode(json_encode([
        'usuario' => $usuario,
        'sede'    => $sede,
        'iat'     => time(),
        'exp'     => time() + 3600
    ]));

    $firma = base64url_encode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET_DASHBOARD, true)
    );

    return "$header.$payload.$firma";
}

/**
 * =============================
 *   VALIDAR TOKEN DASHBOARD
 * =============================
 */
function validarTokenDashboard($token) {

    if (!$token || !is_string($token)) {
        return null;
    }

    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return null;
    }

    list($headerB64, $payloadB64, $firmaB64) = $partes;

    // verificar firma
    $firmaCheck = base64url_encode(
        hash_hmac('sha256', "$headerB64.$payloadB64", JWT_SECRET_DASHBOARD, true)
    );

    if (!hash_equals($firmaCheck, $firmaB64)) {
        return null;
    }

    // decodificar payload
    $payloadJson = base64url_decode($payloadB64);
    $payload = json_decode($payloadJson, true);

    if (!$payload) {
        return null;
    }

    // validar expiración
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}
?>
