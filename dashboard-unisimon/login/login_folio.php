<?php
// =====================================
// 🔐 LOGIN FOLIO - VALIDACIÓN STAFF
// =====================================
include '../../prueba_equipos/tokenByron.php';
header('Content-Type: application/json');
session_start();

// Configuración FOLIO
$folioUrl = "https://okapi-unisimonbolivar.folio.ebsco.com";
$tenant   = "fs00001168";

// Leer JSON del body
$input = json_decode(file_get_contents("php://input"), true);
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan credenciales"]);
    exit;
}

// === LOGIN EN FOLIO ===
$loginUrl = "$url/authn/login";
$headers = [
    "Content-Type: application/json",
    "Accept: application/json",
    "X-Okapi-Tenant: $tenant",
    "User-Agent: autoprestamos-dashboard",
    "X-Forwarded-For: 127.0.0.1"
];

$body = json_encode([
    "username" => $username,
    "password" => $password,
    "tenant"   => $tenant
]);

$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo json_encode(["status" => "error", "message" => curl_error($ch)]);
    curl_close($ch);
    exit;
}

// Extraer token
preg_match('/x-okapi-token:\s*(.+)/i', $response, $matches);
$token = $matches[1] ?? null;

curl_close($ch);

// Validar resultado
if (!$token || $httpCode !== 201) {
    echo json_encode(["status" => "error", "message" => "Credenciales incorrectas o FOLIO no disponible"]);
    exit;
}

// === Obtener info del usuario ===
$userInfoUrl = "$url/users?query=username==$username";
$headers = [
    "X-Okapi-Tenant: $tenant",
    "X-Okapi-Token: $token",
    "Accept: application/json"
];

$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userData = json_decode(curl_exec($ch), true);
curl_close($ch);

$user = $userData['users'][0] ?? null;
if (!$user) {
    echo json_encode(["status" => "error", "message" => "No se encontró el usuario en FOLIO"]);
    exit;
}

// === Validar si es STAFF ===
$isStaff = false;
if (!empty($user['departments'])) {
    foreach ($user['departments'] as $dep) {
        if (stripos($dep, 'staff') !== false || stripos($dep, 'biblioteca') !== false) {
            $isStaff = true;
            break;
        }
    }
}

if (!$isStaff) {
    echo json_encode(["status" => "denied", "message" => "Acceso restringido: solo personal STAFF"]);
    exit;
}

// === Guardar sesión local ===
$_SESSION['folio_user'] = [
    'username' => $username,
    'user_id'  => $user['id'] ?? '',
    'name'     => $user['personal']['firstName'] . ' ' . $user['personal']['lastName'],
    'token'    => $token,
    'is_staff' => $isStaff
];

echo json_encode([
    "status" => "ok",
    "message" => "Login exitoso",
    "user" => $_SESSION['folio_user']
]);
