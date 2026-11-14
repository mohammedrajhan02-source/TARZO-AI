<?php
return [
    'version'=>'2.0E-xampp',
    'timezone'=>'Asia/Colombo',
    'database'=>[
        'host'=>'127.0.0.1', 'port'=>3306, 'database'=>'efs',
        'username'=>'root', 'password'=>'',
    ],
    'data_dir'=>dirname(__DIR__).'/var',
    'allowed_hosts'=>['localhost','127.0.0.1','[::1]'],
    'license_required'=>false, 'license_public_key'=>'', 'license_url'=>'',
    'cloud_url'=>'', 'cloud_token'=>'', 'cloud_tenant'=>'',
    'session_minutes'=>60,
];
