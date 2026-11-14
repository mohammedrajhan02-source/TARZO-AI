<?php
require_once __DIR__.'/app/environment.php';
if(!is_file(__DIR__.'/config/database.php')){header('Location: setup.php');exit;}
try{require __DIR__.'/app/bootstrap.php';}
catch(Throwable $e){error_log((string)$e);header('Location: setup.php');exit;}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/app/templates/workspace.html');
