<?php

$reqpath = '../../';
require($reqpath . 'database.php');
require($reqpath . 'dolphin.php');
require($reqpath . 'vendor/autoload.php');
use Namshi\JOSE\SimpleJWS;

$endpoint = explode('/', $_SERVER['REQUEST_URI']);
$method = strtolower($_SERVER['REQUEST_METHOD']);

if ($endpoint[0] == '')
    $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'sites')
    $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'p2p')
    $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'www')
    $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'api')
    $endpoint = array_slice($endpoint, 1);

function emit($code, $data) {
    $response = json_encode($data);
    if ($code !== true && is_int($code))
        http_response_code($code);
    echo json_encode($response);
    die();
}

function hash2($raw) {
    $hash = md5(hash('sha256', $raw));
    return $hash;
}

function decodeToken($token) {
    global $salt;
    try {
        $jws = SimpleJWS::load($token);
    } catch (InvalidArgumentException $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
    if ($jws->isValid($salt, 'HS256'))
        $payload = $jws->getPayload();
    return $payload;
}

function createToken($id, $username) {
    global $salt;
    $jws  = new SimpleJWS(['alg' => 'HS256']);
    $jws->setPayload([
        'iat' => time(),
        'id' => $id,
        'username' => $username
    ]);
    $jws->sign($salt);
    return $jws->getTokenString();
}

$db = new Dolphin($credentials);
$db->connect();

if ($endpoint[0] == 'login') {
    // only allow posts
    if ($method != 'post')
        emit(405, [ 'message' => 'Please use "api/login" with POST' ]);

    // function to run on success
    $succeed = function ($id, $username, $fullname) {
        emit(true, [
            'token' => createToken($id, $username),
            'id' => $id,
            'username' => $username,
            'fullname' => $fullname
        ]);
    };

    // check username and password validity
    $username = @$_POST['username'];
    if (!isset($username) || !is_string($username) || !ctype_alnum($username))
        emit(500, [ 'message' => 'Invalid Username']);
    $username = strtolower($username);
    $password = @$_POST['password'];
    if (!isset($password) || !is_string($password) || !ctype_alnum($password))
        emit(500, [ 'message' => 'Invalid Password']);

    // check username and password against database
    $password = hash2($password);
    // check students table and succeed
    $user = $db->get('students', [ 'username' => $username, 'password' => $password ], [ 'id', 'username', 'fullname' ]);
    if ($user != false) $succeed($user['id'], $user['username'], $user['fullname']);
    // check tutors table and succeed
    else $user = $db->get('tutors', [ 'username' => $username, 'password' => $password ], [ 'id', 'username', 'fullname' ]);
    if ($user != false) $succeed($user['id'], $user['username'], $user['fullname']);
    // fail if username or password not found in either table
    else emit(500, [ 'message' => 'Username/password not found' ]);
} elseif ($endpoint[0] == 'create') {
    // only allow posts
    if ($method != 'post')
        emit(405, [ 'message' => 'Please use "api/create" with POST' ]);

    // function to run on success
    $succeed = function ($id, $username, $fullname) {
        emit(true, [
            'token' => createToken($id, $username),
            'id' => $id,
            'username' => $username,
            'fullname' => $fullname
        ]);
    };

    // check username and password validity
    $username = @$_POST['username'];
    if (!isset($username) || !is_string($username) || !ctype_alnum($username))
        emit(500, [ 'message' => 'Invalid Username']);
    $username = strtolower($username);
    $password = @$_POST['password'];
    if (!isset($password) || !is_string($password) || !ctype_alnum($password))
        emit(500, [ 'message' => 'Invalid Password']);
    $fullname = @$_POST['fullname'];
    if (!isset($fullname) || !is_string($fullname) /*|| !ctype_alnum($password)*/)
        emit(500, [ 'message' => 'Invalid Full Name']);

    // add user to database
    $password = hash2($password);
    // check if username taken
    $exists = $db->get('students', [ 'username' => $username ], [ 'id' ]);
    if ($exists != false && $exists != null)
        emit(500, [ 'message' => 'Username Not Available']);
    // push user to students table
    $id = $db->push('students', [
        'username' => $username,
        'password' => $password,
        'fullname' => $fullname
    ]);
    // check if push fails
    if ($id === false)
        emit(500, [ 'message' => 'Could not push to database: ' . $db->error() ]);
    // succeed if push works
    else $succeed($id, $username, $fullname);
} else emit(404, [ 'message' => 'Server endpoint not found' ]);

?>
