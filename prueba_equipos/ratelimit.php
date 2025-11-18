<?php
// ratelimit.php - bloquea si > N req en WINDOW segundos
function ratelimit_check($key, $limit = 20, $window = 60){
    $dir = __DIR__ . '/tmp_rate';
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    $file = $dir . '/' . md5($key) . '.log';
    $now = time();
    $times = [];
    if (file_exists($file)) $times = json_decode(file_get_contents($file), true) ?? [];
    // limpiar viejos
    $times = array_filter($times, function($t) use ($now, $window){ return ($now - $t) < $window;});
    if (count($times) >= $limit) return false;
    $times[] = $now;
    file_put_contents($file, json_encode($times));
    return true;
}
?>