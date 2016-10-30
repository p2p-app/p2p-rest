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
    'tutors.php' // tutors endpoint
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
} else {
    $response = rest($api, $request_uri, $request_method, $request_data);
    emit($response[0], $response[1]);
}

// function for interacting with API (recursive)
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
            return [ HTTP_METHOD_NOT_ALLOWED, "Resource cannot be accessed with $method" ];
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
                return [ HTTP_NOT_FOUND, 'Resource not found' ];
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

/*

// 'api' base endpoint - [*] - respond with 200
if (count($endpoint) <= 0 || $endpoint[0] == '') {
    emit(true, [ 'message' => strtoupper($method) . ' request to `api/` successful' ]);
}
// 'api/auth' endpoint - manage authorization/authentication
elseif ($endpoint[0] == 'auth') {
    // 'auth' base endpoint - [GET/POST] - token authorization and authentication
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/') {
        // only allow posts and gets
        if ($method != 'post' && $method != 'get')
            emit(405, [ 'message' => 'Please use `api/auth` with GET/POST' ]);


        if ($method == 'post') {

        }

        elseif ($method == 'get') {

        }
    }
    // 'auth/:*' wildcard endpoint - unknown/invalid - respond with 404
    else emit(404, [ 'message' => 'Please use `api/auth`' ]);
}
// 'api/students' endpoint - manage students
elseif ($endpoint[0] == 'students') {
    // 'students' base endpoint - invalid - respond with 404
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/')
        emit(404, [ 'message' => 'Please use `api/students/create` or `api/students/:student_id`' ]);
    // 'students/create' endpoint - [POST] - create new student and respond with student + token
    elseif ($endpoint[1] == 'create') {
        // only allow posts
        if ($method != 'post')
            emit(405, [ 'message' => 'Please use `api/students/create` with POST' ]);

        // stuff
    }
    // 'students/:student_id' endpoint - [GET][AUTH] - respond with student
    else {

    }
}
// 'api/tutors' endpoint - manage tutors
elseif($endpoint[0] == 'tutors') {
    // 'tutors' base endpoint - [GET][AUTH] - respond with tutors based on location and subject
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/') {

    }
    // 'tutors/create' endpoint - [POST] - create new tutor user and respond with tutor + token
    elseif ($endpoint[1] == 'create') {
        // only allow posts
        if ($method != 'post')
            emit(405, [ 'message' => 'Please use `api/tutors/create` with POST' ]);


    }
    // 'tutors/:tutor_id' endpoint - manage tutor
    else {

        // ':tutor_id/reviews' endpoint - manage tutor reviews
        if (@$endpoint[2] == 'reviews') {
            // 'reviews' base endpoint - [GET][AUTH] - respond with tutor reviews
            if (!isset($endpoint[3]) || $endpoint[3] == '?' || $endpoint[3] == '#' || $endpoint[3] == '/') {
                // only allow gets
                if ($method != 'get')
                    emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/reviews` with GET' ]);


            }
            // 'reviews/create' endpoint - [POST][AUTH] - create review for tutor
            elseif ($endpoint[3] == 'create') {
                // only allow posts
                if ($method != 'post')
                    emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/reviews/create` with POST' ]);

            }
            // 'reviews/:review_id' endpoint - [GET][AUTH] - respond with review
            else {
                // only allow gets
                if ($method != 'get')
                    emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/reviews/:review_id` with GET' ]);


            }
        }
        // ':tutor_id/hours' endpoint - [GET/POST][AUTH] - manage tutor hours
        elseif (@$endpoint[2] == 'hours') {
            // only allow gets/posts
            if ($method != 'post' && $method != 'get')
                emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/hours` with GET/POST' ]);

            // [POST] set tutor hours
            if ($method == 'post') {
                // check user authenticity (if user ID in token matches tutor ID)
                if ($id != $token['id'])
                    emit(403, [ 'message' => "User in token is not authorized to edit this tutor's hours" ]);


            }
            // [GET] get tutor hours
            else if ($method == 'get') {

            }
        }
        // ':tutor_id/location' endpoint - [GET/POST][AUTH] - manage session location
        if (@$endpoint[2] == 'location') {
            // only allow gets/posts
            if ($method != 'post' && $method != 'get')
                emit(405, [ 'message' => 'Please use `api/tutor/:tutor_id/location` with GET/POST' ]);

            // [POST] set tutor location
            if ($method == 'post') {

            }
            // [GET] get tutor location
            else if ($method == 'get') {
                
            }
        }
        // ':tutor_id' endpoint - [GET][AUTH] - respond with tutor
        else {
            // only allow gets
            if ($method != 'get')
                emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id` with GET' ]);


        }
    }
}
// 'api/sessions' endpoint - manage sessions
elseif($endpoint[0] == 'sessions') {
    // authenticate
    $token = authenticate();

    // 'sessions' base endpoint - invalid - respond with 404
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/')
        emit(404, [ 'message' => 'Please use `api/sessions/create` or `api/sessions/:session_id`' ]);
    // 'sessions/create' endpoint - [POST][AUTH] - create new session and respond with session
    elseif ($endpoint[1] == 'create') {
        // only allow gets
        if ($method != 'post')
            emit(405, [ 'message' => 'Please use `api/sessions/create` with POST' ]);

        // validate data
        $student = $token['id'];
        // only students can create session
        $exists = $db->get('students', $student, [ 'id' ]);
        if ($exists == false || $exists == null)
            emit(500, [ 'message' => 'User in token does not exist or is not a student' ]);
        $exists = null;
        $tutor = @$_POST['tutor'];
        if (!isset($tutor) || !is_string($tutor) || !ctype_alnum($tutor))
            emit(500, [ 'message' => 'Invalid Tutor ID']);
        $exists = $db->get('tutors', $tutor, [ 'id' ]);
        if ($exists == false || $exists == null)
            emit(500, [ 'message' => 'Tutor does not exist' ]);
        $exists = null;
        $lat = @$_POST['lat'];
        if (!isset($lat) || !is_string($lat) || (strtolower($lat) != 'null' && (!is_numeric($lat) || (floatval($lat) > 90) || (floatval($lat) < -90))))
            emit(500, [ 'message' => 'Invalid latitude' ]);
        $lat = strtolower($lat);
        $long = @$_POST['long'];
        if (!isset($long) || !is_string($long) || (strtolower($long) != 'null' && (!is_numeric($long) || (floatval($long) > 180) || (floatval($long) < -180))))
            emit(500, [ 'message' => 'Invalid longitude' ]);
        $long = strtolower($long);
        $created = time();

        $session = $db->push('sessions', [
            'student' => $student,
            'tutor' => $tutor,
            'latitude' => [
                'val' => ($lat == 'null') ? 200 : $lat,
                'type' => 'double'
            ],
            'longitude' => [
                'val' => ($long == 'null') ? 200 : $long,
                'type' => 'double'
            ],
            'created' => [
                'val' => $created,
                'type' => 'integer'
            ],
            'state' => 'pending'
        ]);
        if ($session === false)
            emit(500, [ 'message' => 'Could not add session to database' ]);

        // send back data
        emit(true, [
            'id' => $session,
            'student' => $student,
            'tutor' => $tutor,
            'location' => [ $lat, $long ],
            'created' => date(DateTime::ISO8601, $created),
            'state' => 'pending'
        ]);
    }
    // 'sessions/:session_id' endpoint - manage session
    else {
        // validate and check for id in database
        $id = $endpoint[1];
        if (!isset($id) || !is_string($id) || !ctype_alnum($id))
            emit(500, [ 'message' => 'Invalid ID']);
        $session = $db->get('sessions', $id);
        if ($session == null || $session == false || !is_array($session))
            emit(500, [ 'message' => 'Session not found']);

        // ':session_id/location' endpoint - [GET/POST][AUTH] - manage session location
        if (@$endpoint[2] == 'location') {
            // only allow gets/posts
            if ($method != 'post' && $method != 'get')
                emit(405, [ 'message' => 'Please use `api/sessions/:session_id/location` with GET/POST' ]);

            // [POST] set session location
            if ($method == 'post') {
                // check user authenticity (if user ID in token matches session student ID)
                if ($session['student'] != $token['id'])
                    emit(403, [ 'message' => "User in token is not authorized to edit this session's location" ]);

                // check data validity
                $lat = @$_POST['lat'];
                if (!isset($lat) || !is_string($lat) || (strtolower($lat) != 'null' && (!is_numeric($lat) || (floatval($lat) > 90) || (floatval($lat) < -90))))
                    emit(500, [ 'message' => 'Invalid latitude' ]);
                $lat = strtolower($lat);
                $long = @$_POST['long'];
                if (!isset($long) || !is_string($long) || (strtolower($long) != 'null' && (!is_numeric($long) || (floatval($long) > 180) || (floatval($long) < -180))))
                    emit(500, [ 'message' => 'Invalid longitude' ]);
                $long = strtolower($long);

                // set location in database
                if ($db->set('sessions', $id, [
                    'latitude' => [
                        'val' => ($lat == 'null') ? 200 : $lat,
                        'type' => 'double'
                    ],
                    'longitude' => [
                        'val' => ($long == 'null') ? 200 : $long,
                        'type' => 'double'
                    ],
                ]) === false)
                    emit(500, [ 'message' => 'Could not set session location in database' ]);

                // send back data
                emit(true, [
                    'lat' => $lat,
                    'long' => $long
                ]);
            }
            // [GET] get session location
            else if ($method == 'get') {
                // check user authenticity (if user ID in token matches session student/tutor ID)
                if ($token['id'] != $session['student'] || $token['id'] != $session['tutor'])
                    emit(403, [ 'message' => "User in token is not authorized to view this session's location" ]);

                $lat = @$session['latitude'];
                $long = @$session['longitude'];
                if (!isset($lat) || !isset($long) || (is_string($lat) && strtolower($lat) == 'null') || (is_string($long) && strtolower($long) == 'null'))
                    emit(500, [ 'message' => 'Student has not shared location' ]);
                $lat = (floatval($lat) > 199) ? 'null' : floatval($lat);
                $long = (floatval($long) > 199) ? 'null' : floatval($long);

                // send back data
                emit(true, [
                    'lat' => $lat,
                    'long' => $long
                ]);
            }
        }
        // ':session_id/state' endpoint - [GET/POST][AUTH] - manage session state
        elseif (@$endpoint[2] == 'state') {
            // only allow gets/posts
            if ($method != 'post' && $method != 'get')
                emit(405, [ 'message' => 'Please use `api/students/:student_id/location` with GET/POST' ]);

            // [POST] set session state
            if ($method == 'post') {
                // check action validity based on user and current state
                $action = @$_POST['action'];
                if (@$action == 'confirm') {
                    // only tutors can confirm
                    // check user authenticity (if user ID in token matches session tutor ID)
                    if ($session['tutor'] != $token['id'])
                        emit(403, [ 'message' => "User in token is not authorized to confirm this session" ]);
                    if (@$session['state'] != 'pending')
                        emit(500, [ 'message' => 'Session is not in "pending" state and cannot be confirmed' ]);
                    $suffix = 'ed';
                } elseif (@$action == 'commence') {
                    // only students can commence
                    // check user authenticity (if user ID in token matches session student ID)
                    if ($session['student'] != $token['id'])
                        emit(403, [ 'message' => "User in token is not authorized to commence this session" ]);
                    if (@$session['state'] != 'confirmed')
                        emit(500, [ 'message' => 'Session is not in "confirmed" state and cannot be commenced' ]);
                    $suffix = 'd';
                } elseif (@$action == 'complete') {
                    // only tutors can complete
                    // check user authenticity (if user ID in token matches session student ID)
                    if ($session['student'] != $token['id'])
                        emit(403, [ 'message' => "User in token is not authorized to complete this session" ]);
                    if (@$session['state'] != 'commenced')
                        emit(500, [ 'message' => 'Session is not in "commenced" state and cannot be completed' ]);
                    $suffix = 'd';
                } else emit(500, [ 'message' => 'Invalid action' ]);

                // update new state in db
                $update = $db->set('sessions', $id, [
                    'state' => $action . $suffix
                ]);
                if ($update === false)
                    emit(500, [ 'message' => 'Could not set session state in database' ]);

                // send back data on success
                emit(true, [
                    'state' => $action . $suffix
                ]);
            }
            // [GET] get session state
            else if ($method == 'get') {
                // check user authenticity (if user ID in token matches session student/tutor ID)
                if ($token['id'] != $session['student'] && $token['id'] != $session['tutor'])
                    emit(403, [ 'message' => "User in token is not authorized to view this session's state" ]);

                // send back state
                emit(true, [
                    'state' => $session['state']
                ]);
            }
        }
        // ':session_id' endpoint - [GET][AUTH] - respond with session
        else {
            // only allow gets
            if ($method != 'get')
                emit(405, [ 'message' => 'Please use `api/sessions/:session_id` with GET' ]);

            // check user authenticity (if user ID in token matches session student/tutor ID)
            if ($token['id'] != $session['student'] && $token['id'] != $session['tutor'])
                emit(403, [ 'message' => "User in token is not authorized to view this session" ]);

            // check data existence
            $lat = @$session['latitude'];
            if (!isset($lat) || floatval($lat) > 180) $lat = 'null';
            else $lat = floatval($lat);
            $long = @$session['longitude'];
            if (!isset($long) || floatval($long) > 180) $long = 'null';
            else $long = floatval($long);

            // get tutor object from tutor id
            $tutor = $db->get('tutors', $session['tutor']);

            emit(true, [
                'id' => $session['id'],
                'location' => [ $lat, $long ],
                'student' => $session['student'],
                'tutor' => $session['tutor'],
                'created' => date(DateTime::ISO8601, $session['created']),
                'state' => $session['state']
            ]);
        }
    }
}
// 'api/:*' wildcard endpoint - unknown/invalid - respond with 404
else emit(404, [ 'message' => 'Server endpoint not found' ]);

*/


?>
