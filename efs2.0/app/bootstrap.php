<?php
declare(strict_types=1);
require_once __DIR__.'/Core.php';
require_once __DIR__.'/Recovery.php';
require_once __DIR__.'/Station.php';
require_once __DIR__.'/Remote.php';
$config=require dirname(__DIR__).'/config/default.php';
if(is_file(dirname(__DIR__).'/config/local.php'))$config=array_replace($config,require dirname(__DIR__).'/config/local.php');
$efsRecoveryLock=Petrol\recoveryGate($config['data_dir']);
if(!is_file(dirname(__DIR__).'/config/database.php'))throw new Petrol\Problem('Database setup is required. Open setup.php first.',503);
$config['database']=require dirname(__DIR__).'/config/database.php';
date_default_timezone_set('UTC');
$db=new Petrol\Db(array_merge($config['database'],['backup_dir'=>rtrim($config['data_dir'],'/\\').'/backups']));
$station=new Petrol\Station($db);
$licensing=new Petrol\Licensing($db,$config);
