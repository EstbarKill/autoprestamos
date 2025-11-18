<?php
// validation.php - funciones simples de saneamiento y validación
function sanear_str($s){ 
    if ($s === null) return null;
    return trim(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
}
function is_int_id($v){
    return (filter_var($v, FILTER_VALIDATE_INT) !== false);
}
function to_int_or_null($v){
    return is_int_id($v) ? (int)$v : null;
}
?>