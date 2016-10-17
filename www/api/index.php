<?php

// load libraries
$reqpath = '../../';
require($reqpath . 'database.php');
require($reqpath . 'dolphin.php');
require($reqpath . 'vendor/autoload.php');
use Namshi\JOSE\SimpleJWS;

// get and correct request data
$request_uri = $_SERVER['REQUEST_URI'];
if (strpos($request_uri, '?') !== false)
    $request_uri = substr($request_uri, 0, strpos($request_uri, '?'));
$request_uri_last = substr($request_uri, strlen($request_uri) - 1);
if ($request_uri_last == '?' || $request_uri_last == '#' || $request_uri_last == '/')
    $request_uri = substr($request_uri, 0, strlen($request_uri) - 1);
$endpoint = explode('/', $request_uri);
$method = strtolower($_SERVER['REQUEST_METHOD']);

// correct request endpoint
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

// connect to MySQL database with Dolphin
$db = new Dolphin($credentials);
$db->connect();

// handle request based on endpoints
if (count($endpoint) <= 0 || $endpoint[0] == '') {
    emit(true, [ 'message' => strtoupper($method) . ' request to `api/` successful' ]);
}
// login endpoint - authorize student/tutor username+password and respond with userdata+token
elseif ($endpoint[0] == 'auth') {
    // only allow posts
    if ($method != 'post')
        emit(405, [ 'message' => 'Please use `api/auth` with POST' ]);

    // check username and password validity
    $username = @$_POST['username'];
    if (!isset($username) || !is_string($username) || !ctype_alnum($username))
        emit(500, [ 'message' => 'Invalid Username']);
    $username = strtolower($username);
    $password = @$_POST['password'];
    if (!isset($password) || !is_string($password) || !ctype_alnum($password))
        emit(500, [ 'message' => 'Invalid Password']);

    // function to run on success
    $succeed = function ($id, $username, $fullname) {
        emit(true, [
            'token' => createToken($id, $username),
            'data' => [
                'id' => $id,
                'username' => $username,
                'fullname' => $fullname
            ]
        ]);
    };

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
}
// students endpoint - manage students
elseif ($endpoint[0] == 'students') {
    // invalid endpoint
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/') {
        emit(404, [ 'message' => 'Please use `api/students/create` or `api/students/{{id}}`' ]);
    }
    // create endpoint - create new student user and respond with userdata+token
    elseif ($endpoint[1] == 'create') {
        // only allow posts
        if ($method != 'post')
            emit(405, [ 'message' => 'Please use `api/students/create` with POST' ]);

        // check username and password validity
        $username = @$_POST['username'];
        if (!isset($username) || !is_string($username) || !ctype_alnum($username))
            emit(500, [ 'message' => 'Invalid Username']);
        $username = strtolower($username);
        $password = @$_POST['password'];
        if (!isset($password) || !is_string($password) || !ctype_alnum($password))
            emit(500, [ 'message' => 'Invalid Password']);
        $fullname = @$_POST['fullname'];
        if (!isset($fullname) || !is_string($fullname) || !ctype_alnum(str_replace([ ' ', '-', '.', ',' ], '', $fullname)))
            emit(500, [ 'message' => 'Invalid Full Name']);

        // add user to database
        $password = hash2($password);
        // check if username taken
        $exists = $db->get('students', [ 'username' => $username ], [ 'id' ]);
        if ($exists != false && $exists != null)
            emit(500, [ 'message' => 'Username Not Available']);
        $exists = $db->get('tutors', [ 'username' => $username ], [ 'id' ]);
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
        else emit(true, [
            'token' => createToken($id, $username),
            'data' => [
                'id' => $id,
                'username' => $username,
                'fullname' => $fullname
            ]
        ]);
    } else {
        // authorize
        auth();

        // only allow gets
        if ($method != 'get')
            emit(405, [ 'message' => 'Please use `api/students/{{id}}` with GET' ]);

        // validate and check for id in database
        $id = $endpoint[1];
        if (!isset($id) || !is_string($id) || !ctype_alnum($id))
            emit(500, [ 'message' => 'Invalid ID']);
        $student = $db->get('students', $id);
        if ($student == null || $student == false || !is_array($student))
            emit(500, [ 'message' => 'User not found']);
        // respond with user data
        emit(true, [
            'data' => [
                'id' => $student['id'],
                'username' => $student['username'],
                'fullname' => $student['fullname']
            ]
        ]);
    }
}
// tutors endpoint - manage tutors
elseif($endpoint[0] == 'tutors') {
    // invalid endpoint
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/') {
        emit(404, [ 'message' => 'Please use `api/tutors/create` or `api/tutors/{{id}}`' ]);
    }
    // create endpoint - create new tutor user and respond with userdata+token
    if ($endpoint[1] == 'create') {
        // only allow posts
        if ($method != 'post')
            emit(405, [ 'message' => 'Please use `api/tutors/create` with POST' ]);

        // check username and password validity
        $username = @$_POST['username'];
        if (!isset($username) || !is_string($username) || !ctype_alnum($username))
            emit(500, [ 'message' => 'Invalid Username']);
        $username = strtolower($username);
        $password = @$_POST['password'];
        if (!isset($password) || !is_string($password) || !ctype_alnum($password))
            emit(500, [ 'message' => 'Invalid Password']);
        $fullname = @$_POST['fullname'];
        if (!isset($fullname) || !is_string($fullname) || !ctype_alnum(str_replace([ ' ', '-', '.', ',' ], '', $fullname)))
            emit(500, [ 'message' => 'Invalid Full Name']);
        $school = @$_POST['school'];
        if (!isset($school) || !is_string($school) || !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')' ], '', $school)))
            emit(500, [ 'message' => 'Invalid School']);
        $bio = @$_POST['bio'];
        if (!isset($bio) || !is_string($bio) /*|| !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')', ';', ':', "'", '"', '!', '?' ], '', $bio))*/)
            emit(500, [ 'message' => 'Invalid Bio']);
        $subjects = @$_POST['subjects'];
        if (!isset($subjects) || !is_string($subjects) || !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')' ], '', $subjects)))
            emit(500, [ 'message' => 'Invalid Subjects']);

        // add user to database
        $password = hash2($password);
        // check if username taken
        $exists = $db->get('students', [ 'username' => $username ], [ 'id' ]);
        if ($exists != false && $exists != null)
            emit(500, [ 'message' => 'Username Not Available']);
        $exists = $db->get('tutors', [ 'username' => $username ], [ 'id' ]);
        if ($exists != false && $exists != null)
            emit(500, [ 'message' => 'Username Not Available']);
        // push user to students table
        $id = $db->push('tutors', [
            'username' => $username,
            'password' => $password,
            'fullname' => $fullname,
            'school' => $school,
            'bio' => [
                'val' => $bio,
                'type' => 'text(400)'
            ],
            'subjects' => $subjects,
            'stars' => [
                'val' => 0,
                'type' => 'int(50)'
            ]
        ]);
        // check if push fails
        if ($id === false)
            emit(500, [ 'message' => 'Could not push to database: ' . $db->error() ]);
        // succeed if push works
        else emit(true, [
            'token' => createToken($id, $username),
            'data' => [
                'id' => $id,
                'username' => $username,
                'fullname' => $fullname,
                'school' => $school,
                'bio' => $bio,
                'subjects' => $subjects,
                'stars' => 0
            ]
        ]);
    } else {
        // authorize
        auth();

        // only allow gets
        if ($method != 'get')
            emit(405, [ 'message' => 'Please use `api/tutors/{{id}}` with GET' ]);

        // validate and check for id in database
        $id = $endpoint[1];
        if (!isset($id) || !is_string($id) || !ctype_alnum($id))
            emit(500, [ 'message' => 'Invalid ID']);
        $tutor = $db->get('tutors', $id);
        if ($tutor == null || $tutor == false || !is_array($tutor))
            emit(500, [ 'message' => 'User not found']);
        // respond with user data
        emit(true, [
            'data' => [
                'id' => $tutor['id'],
                'username' => $tutor['username'],
                'fullname' => $tutor['fullname'],
                'school' => $tutor['school'],
                'bio' => $tutor['bio'],
                'subjects' => $tutor['subjects'],
                'stars' => $tutor['stars']
            ]
        ]);
    }
}
// invalid endpoint - respond with 404 error
else emit(404, [ 'message' => 'Server endpoint not found' ]);


// declare convenience functions

// function for responding to/closing request
function emit($code, $data) {
    global $endpoint;
    if ($code !== true && is_int($code)) {
        $data['uri'] = implode('/', $endpoint);
        http_response_code($code);
    }
    header('Content-Type: application/json', true);
    if (@$_GET['format'] == 'json_pretty')
        $response = json_encode($data, JSON_PRETTY_PRINT);
    else $response = json_encode($data);
    echo $response;
    die();
}

// function for custom hashing data
function hash2($raw) {
    $hash = md5(hash('sha256', $raw));
    return $hash;
}

// function for validating/verifying JWT token and extracting payload
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
    else return false;
    if (isset($payload['id']) && isset($payload['username']))
        return [
            'id' => $payload['id'],
            'username' => $payload['username']
        ];
    else return false;
}

// function for creating JWT token with user ID and username as payload
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

// function for authenticating tokens
function auth() {
    global $db;
    // get token from header
    $token = @$_SERVER['HTTP_AUTHORIZATION'];
    if (!isset($token) || !is_string($token))
        emit(401, [ 'message' => 'Invalid Authorization Header' ]);
    // remove non-token data
    if (substr($token, 0, 8) == 'Bearer: ' || substr($token, 0, 8) == 'Base64: ')
        $token = substr($token, 8);
    elseif (substr($token, 0, 7) == 'Basic: ' || substr($token, 0, 7) == 'Bearer ' || substr($token, 0, 7) == 'Base64 ')
        $token = substr($token, 7);
    elseif (substr($token, 0, 6) == 'Basic ')
        $token = substr($token, 6);
    // validate/verify token
    $payload = decodeToken($token);
    if ($payload === false || $payload == null)
        emit(401, [ 'message' => 'Invalid Authorization Token' ]);
    // check if username and id form payload match database
    $validStudent = $db->get('students', [
        'id' => $payload['id'],
        'username' => $payload['username']
    ], [ 'id' ]);
    if ($validStudent === false) {
        $validTutor = $db->get('tutors', [
            'id' => $payload['id'],
            'username' => $payload['username']
        ], [ 'id' ]);
        if ($validTutor === false)
            emit(401, [ 'message' => 'Invalid username/id in Authorization Token' ]);
    }
    return true;
}

?>
