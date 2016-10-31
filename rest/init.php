<?php

/*
    API INITIALIZATION SCRIPT
     - description: convenience functions/constants
     - required_vars: $db, $salt, $request_uri, $base_path
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

// checks if profile picture exists
$images_dir = "./../../uploads/images";
function profilePicture($user_id) {
    global $images_dir;
    return file_exists("$images_dir/$user_id.png");
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
        emit(HTTP_UNAUTHORIZED, 'Invalid Authorization Header');
    // remove non-token data
    if (substr($token, 0, 8) == 'Bearer: ' || substr($token, 0, 8) == 'Base64: ')
        $token = substr($token, 8);
    elseif (substr($token, 0, 7) == 'Basic: ' || substr($token, 0, 7) == 'Bearer ' || substr($token, 0, 7) == 'Base64 ')
        $token = substr($token, 7);
    elseif (substr($token, 0, 6) == 'Basic ')
        $token = substr($token, 6);
    // validate/verify token
    $payload = decodeToken($token);
    if ($payload === false || $payload == null) {
        var_dump('hi');
        debug_print_backtrace();
        die();
        emit(HTTP_UNAUTHORIZED, 'Invalid Authorization Token');
    }
    // check if username and id form payload match database
    $type = 'student';
    $validStudent = $db->get('students', [
        'id' => $payload['id'],
        'username' => $payload['username']
    ], [ 'id' ]);
    if ($validStudent === false) {
        $type = 'tutor';
        $validTutor = $db->get('tutors', [
            'id' => $payload['id'],
            'username' => $payload['username']
        ], [ 'id' ]);
        if ($validTutor === false)
            emit(HTTP_UNAUTHORIZED, 'Invalid username or user_id in Authorization Token');
    }
    // return payload on success
    return [
        'id' => $payload['id'],
        'username' => $payload['username'],
        'type' => $type
    ];
}

// recursively interacts with API
function rest($endpoint, $uri, $method, $data, $wildcard_data = false) {
    // get amount of URI elements
    if (!is_array($uri) && is_string($uri))
        $uri = explode('/', $uri);
    $uriCount = is_array($uri) ? count($uri) : 0;
    // get HTTP verb method
    $method = strtoupper($method);
    // respond to invalid URI
    if ($uriCount < 0)
        return [ HTTP_BAD_REQUEST, 'Invalid URI' ];
    // correct endpoint data
    if (!isset($endpoint['__this']) || !is_array($endpoint['__this'])) $endpoint['__this'] = [ ];

    // if last endpoint in tree reached, run API procedure
    if ($uriCount == 0) {
        // check if API procedure exists in current endpoint for current HTTP method
        $endpoint_procedure = @$endpoint["_$method"];
        if (!isset($endpoint_procedure) || !is_callable($endpoint_procedure))
            // respond to unimplemented HTTP method request
            return [ HTTP_METHOD_NOT_ALLOWED, "Endpoint cannot be accessed with $method" ];
        // run API procedure with HTTP data and current endpoint data, and respond with returned data
        return $endpoint_procedure($data, $endpoint['__this']);
    }

    // if there are more endpoints in the tree to traverse, recurse
    else {
        // check if next endpoint in tree exists in current endpoint
        $next_endpoint = @$endpoint[$uri[0]];
        // if next endpoint does not explicitly exist
        if (!isset($next_endpoint) || !is_array($next_endpoint)) {
            // check for wildcard endpoint
            $next_endpoint = @$endpoint['*'];
            if (!isset($next_endpoint) || !is_array($next_endpoint))
                // respond to lack of next endpoint/wildcard endpoint
                return [ HTTP_NOT_FOUND, 'Endpoint not found' ];
            // set wildcard data to URI element
            else $wildcard_data = $uri[0];
        }
        // correct next endoint data
        if (!isset($next_endpoint['__this']) || !is_array($next_endpoint['__this'])) $next_endpoint['__this'] = [ ];
        $next_endpoint['__this']['__parent'] = &$endpoint['__this'];
        // check if next endpoint initialization function exists
        if (isset($next_endpoint['__this']['__init']) && is_callable($next_endpoint['__this']['__init'])) {
            $next_endpoint_init_data = null;
            // run next endpoint initialization function with current endpoint
            if ($wildcard_data === false)
                $next_endpoint_init_data = $next_endpoint['__this']['__init']($next_endpoint['__this']);
            // include wildcard data if it exists
            else $next_endpoint_init_data = $next_endpoint['__this']['__init']($next_endpoint['__this'], $wildcard_data);
            // if initialization fails, respond with failure
            if (is_array($next_endpoint_init_data) && @$next_endpoint_init_data[0] !== true) return $next_endpoint_init_data;
        }
        // recurse, with next endpoint, sliced URI, same method, same data, and same wildcard data
        return rest($next_endpoint, array_slice($uri, 1), $method, $data, $wildcard_data);
    }
}

?>
