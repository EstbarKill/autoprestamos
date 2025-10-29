<?php
// URL del endpoint authn/login-with-expiry
$url = 'https://okapi-unisimonbolivar.folio.ebsco.com/authn/login-with-expiry';

// Datos de autenticación
$dataa = [
    'username' => 'byron.freile@unisimon.edu.co',
    'password' => 'Cra28a#15-74'
];

// Inicializar cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);  // Captura headers (cookies)
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Okapi-Tenant: fs00001168',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataa));

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Error de conexión: ' . curl_error($ch)]);
    exit;
}

$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
list($headers, $body) = explode("\r\n\r\n", $response, 2);
curl_close($ch);

if ($httpcode !== 200 && $httpcode !== 201) {
    echo json_encode(['error' => 'Error de autenticación. Código HTTP: ' . $httpcode]);
    exit;
}

// Buscar el AccessToken en las cookies
preg_match_all('/Set-Cookie:\s*([^;]*)/i', $headers, $cookies);
$token = null;

foreach ($cookies[1] as $cookie) {
    if (str_starts_with($cookie, 'folioAccessToken=')) {
        $token = substr($cookie, strlen('folioAccessToken='));
        break;
    }
}

// Mostrar el token en formato JSON en el navegador
header('Content-Type: application/json');

if ($token) {
    echo json_encode(['token' => $token], JSON_PRETTY_PRINT);
} else {
    echo json_encode(['error' => 'No se encontró el token en la respuesta.']);
}
?>
