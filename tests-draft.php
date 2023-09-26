<?php

// Нужно подключить этот файл (require_once) из другого,
// где задана переменная $auth:
//$auth = [
//    'host' => '.', // . - pipe в Windows
//    'user' => '',
//    'password' => '',
//    'database' => ''
//];

require_once __DIR__ . '/MySQL.php';

$db = new \One234ru\MySQL($auth);

// SELECTS:

//$select_normal = "
//    SELECT 3 as id, 30 as value
//    UNION
//    SELECT 5, 50
//";
//
//$select_empty = "SELECT 1 WHERE 0";
//
//$data = $db->getCell($sql);

$row_with_json = [
    'j' => [
        'r' => rand(100, 200)
    ]
];

//$db->insert('t2', $row);

//$sql = "
//    SELECT 3 as id, 30 as value
//    UNION
//    SELECT 5, 50
//";
//
//$sql = "SELECT 1 WHERE 0";
//t
//$data = $db->getCell($sql);
//var_export($data);

//$row = [];
//var_export($db->insertRow('t2', $row));
//$row = ['id' => 5];
//var_export($db->insertRowAndReturnId('t2', $row));

//$data = [ 'j' => json_encode(/*81*/rand(1, 999)) ];
//$unique_keys = [ 'id' => 5 ];
////$unique_keys = [];
//var_export($db->update('t2', $data, $unique_keys));
////$data = [ 'id' => 53 ];
//var_export($db->updateRowById('t2', $data, 5));

