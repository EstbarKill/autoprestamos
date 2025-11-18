<?php
// jwt.php - HS256 simple

function b64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode($payload, $secret){
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];

    $h64 = b64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE));
    $b64 = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));

    $signature = hash_hmac('sha256', "$h64.$b64", $secret, true);
    $s64 = b64url_encode($signature);

    return "$h64.$b64.$s64";
}

function jwt_decode($token, $secret){
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$h64, $b64, $s64] = $parts;

    $header  = json_decode(b64url_decode($h64), true);
    $payload = json_decode(b64url_decode($b64), true);
    $sig     = b64url_decode($s64);

    if (!$header || !$payload) return null;

    $check = hash_hmac('sha256', "$h64.$b64", $secret, true);
    if (!hash_equals($check, $sig)) return null;

    // Validar expiración
    if (isset($payload['exp']) && time() > $payload['exp']) return null;

    return $payload;
}
?>
