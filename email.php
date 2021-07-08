<?php

require_once __DIR__ . '/vendor/autoload.php';

$headers = 'From: shockerli@upfor.club' . "\r\n" .
    'X-Mailer: PHP/' . phpversion();

$status = mail('731357343@qq.com', 'kkndme', '看到请回复，谢谢，急');
var_dump($status);
