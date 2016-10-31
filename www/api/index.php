<?php

$base_path = '../..';

// wrap MySQL DB with Dolphin
require("$base_path/database/database.php"); // load DB credentials and token salt
require("$base_path/database/dolphin.php"); // load DB wrapper library Dolphin
$db = new Dolphin($credentials);

// load JWT library from composer
require("$base_path/vendor/autoload.php");

// load API endpoints from files
$api = [];
$api_endpoint_files = [
    'init.php', // initialize convenience functions/constants
    'auth.php', // authentication endpoint
    'students.php', // students endpoint
    'tutors.php', // tutors endpoint
    'sessions.php', // sessions endpoint
    'images.php' // images endpoint
];
foreach ($api_endpoint_files as $f => $file)
    require("$base_path/rest/$file");

// load and correct request URI, method, and data
$request_uri = $_SERVER['REQUEST_URI'];
if (strpos($request_uri, '?') !== false)
    $request_uri = substr($request_uri, 0, strpos($request_uri, '?'));
$request_uri_last = substr($request_uri, strlen($request_uri) - 1);
if ($request_uri_last == '?' || $request_uri_last == '#' || $request_uri_last == '/')
    $request_uri = substr($request_uri, 0, strlen($request_uri) - 1);
$request_uri = explode('/', $request_uri);
for ($n = 0; $n < 5; $n++) {
    $r = @$request_uri[0];
    if (!isset($r) || $r == '' || $r == 'sites' || $r == 'p2p' || $r == 'www' || $r == 'rest' || $r == 'api')
        $request_uri = array_slice($request_uri, 1);
}
$request_method = strtolower($_SERVER['REQUEST_METHOD']);
$request_data = [ ];
switch ($request_method) {
    case 'get':
        $request_data = $_GET;
        break;
    case 'post':
        $request_data = $_POST;
        break;
    case 'put':
    case 'delete':
    case 'patch':
    default:
        emit(HTTP_NOT_IMPLEMENTED, strtoupper($request_method) . ' requests are not supported at this time');
        break;
}

// connect Dolphin DB
$db->connect();

// base endpoint - respond with success
if (count($request_uri) <= 0 || $request_uri[0] == '') {
    emit(true, strtoupper($request_method) . ' request to `api/` successful');
}
// all other endpoints - use rest function
else {
    // run rest function to interact with API
    $response = rest($api, $request_uri, $request_method, $request_data);
    // respond with returned data
    emit($response[0], $response[1]);
}

?>
