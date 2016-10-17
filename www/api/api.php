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

function emit($success, $data) {
    $response = json_encode($data);
    if ($success == false) http_response_code(500);
    elseif ($success == '404') http_response_code(404);
    echo json_encode($response);
    die();
}

function hash2($raw) {
    return md5(hash('sha256', $raw));
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
    if ($method != 'post') emit(false, [ 'message' => 'Please use "api/login" with POST' ]);
    // function to run on success
    $succeed = function ($id, $username) {
        emit(true, [
            'token' => createToken($id, $username),
            'id' => $id,
            'username' => $username
        ]);
    };

    // check username and password validity
    $username = @$_POST['username'];
    if (!isset($username) || !is_string($username) || !ctype_alnum($username))
        emit(false, [ 'message' => 'Invalid Username']);
    $password = @$_POST['password'];
    if (!isset($password) || !is_string($password) || !ctype_alnum($password))
    emit(false, [ 'message' => 'Invalid Password']);

    // check username and password against database
    $password = hash2($password);
    // check students table and succeed
    $user = $db->get('students', [ 'username' => $username, 'password' => $password ]);
    if ($user != false) $succeed($user['id'], $user['username']);
    // check tutors table and succeed
    else $user = $db->get('tutors', [ 'username' => $username, 'password' => $password ]);
    if ($user != false) $succeed($user['id'], $user['username']);
    // fail if username or password not found in either table
    else emit(false, [ 'message' => 'Username/password not found' ]);
} elseif ($endpoint[0] == 'create') {

} else emit('404', [ 'message' => 'Server endpoint not found' ]);

?>
