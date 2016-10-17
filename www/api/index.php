<?php

$method = strtolower($_SERVER['REQUEST_METHOD']);
if ($method == 'get') {
    echo 'P2P API';
} else header('Location: api');

?>
