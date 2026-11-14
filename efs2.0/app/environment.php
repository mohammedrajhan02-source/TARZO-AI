<?php
// Keep this entry guard readable by old PHP, before loading PHP 7.4 source.
if(version_compare(PHP_VERSION,'7.4.0','<')||PHP_INT_SIZE<8){
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><meta charset="utf-8"><title>EFS · PHP requirements</title><body style="font-family:Segoe UI,Arial;padding:40px"><h1>EFS needs PHP 7.4 or newer, 64-bit</h1><p>Detected PHP: '.htmlspecialchars(PHP_VERSION,ENT_QUOTES,'UTF-8').'. Install a 64-bit XAMPP version with PHP 7.4 or PHP 8.x, then restart Apache.</p><p>PHP 8.2 or newer is recommended. PHP 5.x and PHP 7.0–7.3 cannot run this package.</p></body></html>';
    exit;
}
ini_set('display_errors','0');
ini_set('log_errors','1');
if(!headers_sent()){
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}
