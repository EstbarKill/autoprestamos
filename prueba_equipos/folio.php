<?php
// folio.php

function callFolio($url, $token, $method = 'GET', $payload = null) {
    $ch = curl_init($url);
    $headers = [
        "X-Okapi-Tenant: fs00001168",
        "X-Okapi-Token: $token",
        "Content-Type: application/json"
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        // devuelve estructura consistente
        return ['__error' => 'curl', 'message' => $curlErr, 'http' => $httpCode];
    }

    $json = json_decode($resp, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['__error' => 'json', 'message' => json_last_error_msg(), 'raw' => $resp];
    }

    // Si la respuesta es un string "manual-block-template not found" u otro plain text, devolver raw
    if (!is_array($json)) {
        return ['__error' => 'unexpected', 'raw' => $resp];
    }

    return $json;
}


function folioGetUser($username_full, $token) {
    $url = "https://okapi-unisimonbolivar.folio.ebsco.com/users?query=username=" . urlencode($username_full) . "&limit=1";
    $data = callFolio($url, $token);
    return empty($data['users']) ? null : $data['users'][0];
}

function folioManualBlock($userId, $token) {
    $url = "https://okapi-unisimonbolivar.folio.ebsco.com/manualblocks?query=userId==" . urlencode($userId) . "&limit=100";
    $data = callFolio($url, $token);

    // Manejo de errores en callFolio
    if (isset($data['__error'])) {
        // opcional: debugLog("folioManualBlock error", $data);
        return []; // no tratar esto como bloqueo, pero loguearlo en servidor
    }

    $blocks = $data['manualblocks'] ?? [];

    // Asegurarnos que sea array
    if (!is_array($blocks)) $blocks = [];

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $vigentes = [];

    foreach ($blocks as $b) {
        // Si hay expirationDate, parsear y comparar en UTC
        if (!empty($b['expirationDate'])) {
            try {
                // Normalizar posible formato con +00:00 o .000+00:00
                $exp = new DateTime($b['expirationDate']);
                // Forzar zona UTC para comparacion segura
                $exp->setTimezone(new DateTimeZone('UTC'));
                if ($exp > $nowUtc) {
                    $vigentes[] = $b;
                }
            } catch (Exception $e) {
                // Si hay problema parsing, considerar vigente y loguear
                $vigentes[] = $b;
                // debugLog("Error parsing expirationDate", $b);
            }
        } else {
            // sin fecha -> considerar activo
            $vigentes[] = $b;
        }
    }

    return $vigentes;
}




function folioAutoBlock($userId, $token) {
    $url = "https://okapi-unisimonbolivar.folio.ebsco.com/automated-patron-blocks?query=userId==" . urlencode($userId);
    $data = callFolio($url, $token);
    if (isset($data['__error'])) return [];
    $blocks = $data['automatedPatronBlocks'] ?? $data['automatedpatronblocks'] ?? $data['autoBlocks'] ?? [];
    return is_array($blocks) ? $blocks : [];
}


function folioGetServicePoint($token) {
    static $cache = null;
    if ($cache) return $cache;
    $url = "https://okapi-unisimonbolivar.folio.ebsco.com/service-points?limit=1";
    $data = callFolio($url, $token);
    $cache = $data['servicepoints'][0]['id'] ?? null;
    return $cache;
}

function folioCheckout($token, $itemBarcode, $userBarcode, $servicePointId) {
    $url = "https://okapi-unisimonbolivar.folio.ebsco.com/circulation/check-out-by-barcode";
    $payload = [
        "itemBarcode"    => $itemBarcode,
        "userBarcode"    => $userBarcode,
        "servicePointId" => $servicePointId
    ];
    return callFolio($url, $token, 'POST', $payload);
}

function folioCheckin($token, $itemBarcode, $servicePointId, $fechaLocal = null) {
    $folioUrl = "https://okapi-unisimonbolivar.folio.ebsco.com/circulation/check-in-by-barcode";
    
    $dt = $fechaLocal
        ? new DateTime($fechaLocal, new DateTimeZone(date_default_timezone_get()))
        : new DateTime('now', new DateTimeZone(date_default_timezone_get()));
    $dt->setTimezone(new DateTimeZone('UTC'));
    $checkInDate = $dt->format('Y-m-d\TH:i:s.000\Z');

    $payload = [
        "itemBarcode"    => $itemBarcode,
        "servicePointId" => $servicePointId,
        "checkInDate"    => $checkInDate
    ];
    
    $ch = curl_init($folioUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-Okapi-Tenant: fs00001168",
            "X-Okapi-Token: $token"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        "status" => $httpCode,
        "raw"    => $response ? json_decode($response, true) : null,
        "Error"  => $error
    ];
}

function loanExists($token, $itemBarcode) {
    $url = "https://okapi-unisimonbolivar.folio.ebsco.com/circulation/loans?query=item.barcode==" . urlencode($itemBarcode) . " and status.name==Open&limit=1";
    $resp = callFolio($url, $token);
    if ($resp && isset($resp['loans']) && count($resp['loans']) > 0) {
        return $resp['loans'][0];
    }
    return null;
}