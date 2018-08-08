<?php

require_once __DIR__ . '/vendor/autoload.php';

$emailList = json_decode(file_get_contents(__DIR__ . "/data/email.252774.json"), true);
arsort($emailList);
//file_put_contents(__DIR__ . "/data/email.252774.json", json_encode($emailList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo implode(';', array_keys($emailList));die("\n");
