<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('CLI only.');
require_once dirname(__DIR__).'/app/Core.php';
$efsTestDatabases=[];$efsLastTestConfig=[];
function test_database_connection(): PDO {
    global $efsTestDatabases,$efsLastTestConfig;
    $config=['host'=>getenv('EFS_TEST_HOST')?:'127.0.0.1','port'=>(int)(getenv('EFS_TEST_PORT')?:3306),'database'=>'efs_check_'.Petrol\uid(),'username'=>getenv('EFS_TEST_USER')?:'root','password'=>getenv('EFS_TEST_PASSWORD')?:''];
    $server=Petrol\Db::connect($config,false);
    $server->exec('CREATE DATABASE `'.$config['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $efsTestDatabases[]=$config;$efsLastTestConfig=$config;
    return Petrol\Db::connect($config);
}
function test_last_config(): array {global $efsLastTestConfig;return $efsLastTestConfig;}
function test_database(): Petrol\Db {
    $pdo=test_database_connection();$pdo->exec(file_get_contents(dirname(__DIR__).'/mysql/efs.sql'));
    return new Petrol\Db(test_last_config());
}
register_shutdown_function(function(){
    global $efsTestDatabases;
    foreach($efsTestDatabases as $config){
        if(!preg_match('/^efs_check_[a-f0-9]{32}$/D',$config['database']))continue;
        try{Petrol\Db::connect($config,false)->exec('DROP DATABASE `'.$config['database'].'`');}
        catch(Throwable $e){fwrite(STDERR,'Test cleanup: '.$e->getMessage().PHP_EOL);}
    }
});
