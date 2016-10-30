<?php

/*
    API INITIALIZATION SCRIPT
     - description: convenience functions/constants
     - required_vars: $db, $salt, $request_uri
*/

// HTTP status code constants
const HTTP_OK = 200;
const HTTP_CREATED = 201;
const HTTP_BAD_REQUEST = 400;
const HTTP_UNAUTHORIZED = 401;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_METHOD_NOT_ALLOWED = 405;
const HTTP_CONFLICT = 409;
const HTTP_INTERNAL_SERVER_ERROR = 500;
const HTTP_NOT_IMPLEMENTED = 501;

// responds to and closes request
function emit($code, $data) {
    global $request_uri, $db;
    $db->disconnect();
    if (is_string($data))
        $data = [
            'message' => $data,
            'uri' => '/' . implode('/', $request_uri)
        ];
    if ($code !== true && is_int($code))
        http_response_code($code);
    header('Content-Type: application/json', true);
    if (@$_GET['format'] == 'json_pretty')
        $response = json_encode($data, JSON_PRETTY_PRINT);
    else $response = json_encode($data);
    echo $response;
    die();
}

// hashes data with custom algorithm sequence
function hash2($raw) {
    $hash = md5(hash('sha256', $raw));
    return $hash;
}

// checks if array is associative
function isAssoc($array) {
    return (array_keys($array) !== range(0, count($array) - 1));
}

// validates/verifies JWT token and extractes payload
function decodeToken($token) {
    global $salt;
    try {
        $jws = Namshi\JOSE\SimpleJWS::load($token);
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

// creates JWT token with user ID and username as payload
function createToken($id, $username) {
    global $salt;
    $jws  = new Namshi\JOSE\SimpleJWS([ 'alg' => 'HS256' ]);
    $jws->setPayload([
        'iat' => time(),
        'id' => $id,
        'username' => $username
    ]);
    $jws->sign($salt);
    return $jws->getTokenString();
}

// authenticates tokens
function authenticate() {
    global $db;
    // get token from header
    $token = @$_SERVER['HTTP_AUTHORIZATION'];
    if (!isset($token) || !is_string($token))
        emit(HTTP_UNAUTHORIZED, [ 'message' => 'Invalid Authorization Header' ]);
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
        emit(HTTP_UNAUTHORIZED, [ 'message' => 'Invalid Authorization Token' ]);
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
            emit(HTTP_UNAUTHORIZED, [ 'message' => 'Invalid username/user_id in Authorization Token' ]);
    }
    // return payload on success
    return [
        'id' => $payload['id'],
        'username' => $payload['username']
    ];
}



?>
