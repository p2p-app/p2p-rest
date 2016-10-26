<?php

// load libraries
$reqpath = '../../';
require($reqpath . 'db/database.php');
require($reqpath . 'db/dolphin.php');
require($reqpath . 'vendor/autoload.php');
use Namshi\JOSE\SimpleJWS;

// connect to MySQL database with Dolphin
$db = new Dolphin($credentials);
$db->connect();

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
if ($endpoint[0] == '') $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'sites') $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'p2p') $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'www') $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'rest') $endpoint = array_slice($endpoint, 1);
if ($endpoint[0] == 'api') $endpoint = array_slice($endpoint, 1);

/* REST API ENDPOINTS */

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
            emit(405, [ 'message' => 'Please use `api/auth` with POST' ]);

        // [POST] authorize student/tutor username + password and respond with user + token
        if ($method == 'post') {
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
            else emit(401, [ 'message' => 'Username/password not found' ]);
        }
        // [GET] authenticate token and respond with user
        elseif ($method == 'get') {
            // authenticate
            $token = authenticate();
            // check students table for user
            $user = $db->get('students', [ 'id' => $token['id'], 'username' => $token['username'] ], [ 'id', 'username', 'fullname' ]);
            // check tutors table for user
            if ($user == false || $user == null)
                $user = $db->get('tutors', [ 'id' => $token['id'], 'username' => $token['username'] ], [ 'id', 'username', 'fullname' ]);
            // if user found, send data
            if ($user != false) emit(true, $user);
            // fail if username not found in either table
            else emit(401, [ 'message' => 'Username/password not found' ]);
        }
    }
    // 'auth/:*' wildcard endpoint - unknown/invalid - respond with 404
    else emit(404, [ 'message' => 'Please use `api/auth`' ]);
}
// 'api/students' endpoint - manage students
elseif ($endpoint[0] == 'students') {
    // 'api/students' base endpoint - invalid - respond with 404
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/')
        emit(404, [ 'message' => 'Please use `api/students/create` or `api/students/:student_id`' ]);
    // 'api/students/create' endpoint - [POST] - create new student and respond with student + token
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
            emit(409, [ 'message' => 'Username Not Available']);
        $exists = $db->get('tutors', [ 'username' => $username ], [ 'id' ]);
        if ($exists != false && $exists != null)
            emit(409, [ 'message' => 'Username Not Available']);
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
    }
    // 'api/students/:student_id' endpoint - [GET][AUTH] - respond with student
    else {
        // authenticate
        $token = authenticate();

        // validate and check for id in database
        $id = $endpoint[1];
        if (!isset($id) || !is_string($id) || !ctype_alnum($id))
            emit(500, [ 'message' => 'Invalid ID']);
        $student = $db->get('students', $id);
        if ($student == null || $student == false || !is_array($student))
            emit(500, [ 'message' => 'Student not found']);

        // only allow gets
        if ($method != 'get')
            emit(405, [ 'message' => 'Please use `api/students/:student_id` with GET' ]);

        // respond with user data
        emit(true, [
            'id' => $student['id'],
            'username' => $student['username'],
            'fullname' => $student['fullname']
        ]);
    }
}
// 'api/tutors' endpoint - manage tutors
elseif($endpoint[0] == 'tutors') {
    // 'api/tutors' base endpoint - [GET][AUTH] - respond with tutors based on location and subject
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/') {
        // authenticate
        authenticate();

        // only allow gets
        if ($method != 'get')
            emit(405, [ 'message' => 'Please use `api/tutors` with GET' ]);

        // check data validity
        $lat = @$_GET['lat'];
        if (!isset($lat) || !is_string($lat) || !is_numeric($lat) || floatval($lat) > 90 || floatval($lat) < -90)
            emit(500, [ 'message' => 'Invalid latitude' ]);
        $lat = floatval($lat);
        $long = @$_GET['long'];
        if (!isset($long) || !is_string($long) || !is_numeric($long) || floatval($long) > 180 || floatval($long) < -180)
            emit(500, [ 'message' => 'Invalid longitude' ]);
        $long = floatval($long);
        $range = @$_GET['range'];
        if (!isset($range) || !is_string($range) || !is_numeric($range))
            $range = 0.0001;
        $range = floatval($range);
        $latrange = @$_GET['latrange'];
        if (!isset($latrange) || !is_string($latrange) || !is_numeric($latrange))
            $latrange = $range;
        $latrange = floatval($latrange);
        $longrange = @$_GET['longrange'];
        if (!isset($longrange) || !is_string($longrange) || !is_numeric($longrange))
            $longrange = $range;
        $longrange = floatval($longrange);
        $subjects = @$_GET['subjects'];
        if (!isset($subjects) || !is_string($subjects) || strlen($subjects) > 200)
            emit(500, [ 'message' => 'Invalid subjects' ]);
        $subjects = explode(',', $subjects);

        // get tutors with db condition of lat/long
        $tutors = $db->get('tutors', [
            'latitude' => [
                'condition' => 'between ? and ?',
                'expected' => [ $lat - $latrange, $lat + $latrange ],
                'nextOperator' => 'AND'
            ],
            'longitude' => [
                'condition' => 'between ? and ?',
                'expected' => [ $long - $longrange, $long + $longrange ],
            ]
        ]);

        // correct returned array
        if ($tutors === false)
            emit(500, [ 'message' => 'Error while fetching tutors' ]);
        elseif ($tutors == null || count($tutors) == 0)
            $tutors = [];
        elseif (array_keys($tutors) !== range(0, count($tutors) - 1)) {
            $tutors = [ $tutors ];
        }

        // reorganize data and match subjects
        $modTutors = [];
        foreach ($tutors as $j => $tutor) {
            $tutorSubjects = explode(',', $tutor['subjects']);
            foreach ($tutorSubjects as $k => $tutorSubject) {
                if ($subjects[0] == 'all' || in_array($tutorSubject, $subjects)) {
                    array_push($modTutors, [
                        'id' => $tutor['id'],
                        'username' => $tutor['username'],
                        'fullname' => $tutor['fullname'],
                        'school' => $tutor['school'],
                        'bio' => $tutor['bio'],
                        'subjects' => $tutor['subjects'],
                        'city' => $tutor['city'],
                        'stars' => ($tutor['stars'] > 5 ? 'null' : $tutor['stars']),
                        'hours' => $tutor['hours'],
                        'location' => [
                            (isset($tutor['latitude']) ? $tutor['latitude'] : 'null'),
                            (isset($tutor['longitude']) ? $tutor['longitude'] : 'null')
                        ]
                    ]);
                    break;
                }
            }
        }

        emit(true, $modTutors);
    }
    // 'api/tutors/create' endpoint - [POST] - create new tutor user and respond with tutor + token
    elseif ($endpoint[1] == 'create') {
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
        $city = @$_POST['city'];
        if (!isset($city) || !is_string($city) || !ctype_alnum(str_replace([ ' ', '-', '.', ',' ], '', $city)))
            emit(500, [ 'message' => 'Invalid City']);

        // add user to database
        $password = hash2($password);
        // check if username taken
        $exists = $db->get('students', [ 'username' => $username ], [ 'id' ]);
        if ($exists != false && $exists != null)
            emit(409, [ 'message' => 'Username Not Available']);
        $exists = $db->get('tutors', [ 'username' => $username ], [ 'id' ]);
        if ($exists != false && $exists != null)
            emit(409, [ 'message' => 'Username Not Available']);
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
            'city' => $city,
            'stars' => [
                'val' => 10,
                'type' => 'double'
            ],
            'hours' => [
                'val' => 0,
                'type' => 'integer'
            ],
            'latitude' => [
                'val' => 200,
                'type' => 'integer'
            ],
            'longitude' => [
                'val' => 200,
                'type' => 'integer'
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
                'stars' => 'null',
                'hours' => 0,
                'city' => $city,
                'location' => [ 'null', 'null' ]
            ]
        ]);
    }
    // 'api/tutors/:tutor_id' endpoint - manage tutor
    else {
        // authenticate
        $token = authenticate();

        // validate and check for id in database
        $id = $endpoint[1];
        if (!isset($id) || !is_string($id) || !ctype_alnum($id))
            emit(500, [ 'message' => 'Invalid ID']);
        $tutor = $db->get('tutors', $id);
        if ($tutor == null || $tutor == false || !is_array($tutor))
            emit(500, [ 'message' => 'Tutor not found']);

        // 'api/tutors/:tutor_id/reviews' endpoint - manage tutor reviews
        if (@$endpoint[2] == 'reviews') {
            // 'api/tutors/:tutor_id/reviews' base endpoint - [GET][AUTH] - respond with tutor reviews
            if (!isset($endpoint[3]) || $endpoint[3] == '?' || $endpoint[3] == '#' || $endpoint[3] == '/') {
                // only allow gets
                if ($method != 'get')
                    emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/reviews` with GET' ]);

                $reviews = $db->get('reviews', [ 'forTutor' => $id ]);
                if ($reviews === false)
                    emit(500, [ 'message' => 'Error while fetching reviews' ]);
                elseif ($reviews == null || count($reviews) == 0)
                    emit(true, [ ]);
                elseif (array_keys($reviews) !== range(0, count($reviews) - 1)) {
                    emit(true, [ $reviews ]);
                } else emit(true, $reviews);
            }
            // 'api/tutors/:tutor_id/reviews/create' endpoint - [POST][AUTH] - create review for tutor
            elseif ($endpoint[3] == 'create') {
                // only allow posts
                if ($method != 'post')
                    emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/reviews/create` with POST' ]);

                // check data validity
                $from = @$_POST['from'];
                if (!isset($from) || !is_string($from) || !ctype_alnum($from))
                    emit(500, [ 'message' => 'Invalid "from" user id']);
                $stars = @$_POST['stars'];
                if (!isset($stars) || !is_numeric($stars) || floatval($stars) > 5 || floatval($stars) < 0)
                    emit(500, [ 'message' => 'Invalid stars']);
                else $stars = floatval($stars);
                $text = @$_POST['text'];
                if (!isset($text) || !is_string($text) /*|| !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')', ';', ':', "'", '"', '!', '?' ], '', $text))*/)
                    emit(500, [ 'message' => 'Invalid text']);

                // check for "from" user in database
                $fromUser = $db->get('students', $from);
                if ($fromUser === false || $fromUser == null)
                    emit(500, [ 'message' => '"From" user not found' ]);

                // average stars
                $newstars = $stars;
                $oldstars = $tutor['stars'];
                $totalreviews = $db->get('reviews', [
                    'forTutor' => $id
                ], [ 'stars' ]);
                if ($totalreviews === false)
                    emit(500, [ 'message' => 'Could not calculate star average' ]);
                if ($totalreviews <= 0)
                    $db->set('tutors', $id, [ 'stars' => $stars ]);
                else {
                    if ($oldstars === 'null' || floatval($oldstars) > 5) {
                        foreach ($totalreviews as $i => $pastreview) {
                            if (is_array($pastreview) && isset($pastreview['stars']) && is_numeric($pastreview['stars']))
                                $newstars += floatval($pastreview['stars']);
                            elseif (is_numeric($pastreview))
                                $newstars += floatval($pastreview);
                        }
                    } else $newstars += floatval($oldstars) * count($totalreviews);
                    $newstars /= count($totalreviews) + 1;
                    if ($db->set('tutors', $id, [
                        'stars' => [
                            'val' => $newstars,
                            'type' => 'd'
                        ]
                    ]) === false)
                        emit(500, [ 'message' => 'Could not set star average in database' . $db->error() ]);
                }

                // push review to database
                $review = $db->push('reviews', [
                    'forTutor' => $id,
                    'fromStudent' => $from,
                    'stars' => [
                        'val' => $stars,
                        'type' => 'integer(100)'
                    ],
                    'reviewText' => [
                        'val' => $text,
                        'type' => 'text'
                    ]
                ]);
                // check if push failed
                if ($review === false)
                    emit(500, [ 'message' => 'Error while creating review' ]);
                // succeed with review data
                else emit(true, [
                    'id' => $review,
                    'from' => $from,
                    'stars' => $stars,
                    'text' => $text
                ]);
            }
            // 'api/tutors/:tutor_id/reviews/:review_id' endpoint - [GET][AUTH] - respond with review
            else {
                // only allow gets
                if ($method != 'get')
                    emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/reviews/:review_id` with GET' ]);

                $review = $db->get('reviews', [
                    'id' => $endpoint[3],
                    'forTutor' => $id
                ]);
                if ($review === false)
                    emit(500, [ 'message' => 'Error while fetching review' ]);
                elseif ($review == null || (is_array($review) && count($review) == 0))
                    emit(500, [ 'message' => 'Review not found' ]);
                else emit(true, [ $review ]);
            }
        }
        // 'api/tutors/:tutor_id/hours' endpoint - [GET/POST][AUTH] - manage tutor hours
        elseif (@$endpoint[2] == 'hours') {
            // only allow gets/posts
            if ($method != 'post' && $method != 'get')
                emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id/location` with GET/POST' ]);

            // [POST] set tutor hours
            if ($method == 'post') {
                // check user authenticity (if user ID in token matches tutor ID)
                if ($id != $token['id'])
                    emit(403, [ 'message' => "User in token is not authorized to edit this tutor's hours" ]);

                // check data validity
                $add = @$_POST['add'];
                $hours = @$_POST['hours'];
                if (!isset($hours) || !is_string($hours) || !is_numeric($hours))
                    emit(500, [ 'message' => 'Invalid hours' ]);
                $hours = intval($hours);

                // add hours to existing if desired
                if (@$add === true) {
                    $oldhours = $tutor['hours'];
                    if (is_string($oldhours))
                        $oldhours = intval($oldhours);
                    $hours += $oldhours;
                }

                // set hours in database
                if ($db->set('tutors', $id, [
                    'hours' => [
                        'val' => $hours,
                        'type' => 'integer'
                    ]
                ]) === false)
                    emit(500, [ 'message' => 'Could not set session location in database' ]);

                // send back data
                emit(true, [
                    'hours' => $hours
                ]);
            }
            // [GET] get tutor hours
            else if ($method == 'get') {
                $hours = $tutor['hours'];

                // send back data
                emit(true, [
                    'hours' => $hours
                ]);
            }
        }
        // 'api/tutor/:tutor_id/location' endpoint - [GET/POST][AUTH] - manage session location
        if (@$endpoint[2] == 'location') {
            // only allow gets/posts
            if ($method != 'post' && $method != 'get')
                emit(405, [ 'message' => 'Please use `api/tutor/:tutor_id/location` with GET/POST' ]);

            // [POST] set tutor location
            if ($method == 'post') {
                // check user authenticity (if user ID in token matches tutor ID)
                if ($id != $token['id'])
                    emit(403, [ 'message' => "User in token is not authorized to edit this tutor's location" ]);

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
                if ($db->set('tutors', $id, [
                    'latitude' => [
                        'val' => ($lat == 'null') ? 200 : $lat,
                        'type' => 'double'
                    ],
                    'longitude' => [
                        'val' => ($long == 'null') ? 200 : $long,
                        'type' => 'double'
                    ],
                ]) === false)
                    emit(500, [ 'message' => 'Could not set tutor location in database' ]);

                // send back data
                emit(true, [
                    'lat' => $lat,
                    'long' => $long
                ]);
            }
            // [GET] get tutor location
            else if ($method == 'get') {
                $lat = @$session['latitude'];
                $long = @$session['longitude'];
                if (!isset($lat) || !isset($long) || (is_string($lat) && strtolower($lat) == 'null') || (is_string($long) && strtolower($long) == 'null'))
                    emit(500, [ 'message' => 'Tutor has not shared location' ]);
                $lat = (floatval($lat) > 199) ? 'null' : floatval($lat);
                $long = (floatval($long) > 199) ? 'null' : floatval($long);

                // send back data
                emit(true, [
                    'lat' => $lat,
                    'long' => $long
                ]);
            }
        }
        // 'api/tutors/:tutor_id' endpoint - [GET][AUTH] - respond with tutor
        else {
            // only allow gets
            if ($method != 'get')
                emit(405, [ 'message' => 'Please use `api/tutors/:tutor_id` with GET' ]);

            // respond with tutor data
            emit(true, [
                'id' => $tutor['id'],
                'username' => $tutor['username'],
                'fullname' => $tutor['fullname'],
                'school' => $tutor['school'],
                'bio' => $tutor['bio'],
                'subjects' => $tutor['subjects'],
                'city' => $tutor['city'],
                'stars' => ($tutor['stars'] > 5 ? 'null' : $tutor['stars']),
                'hours' => $tutor['hours'],
                'location' => [
                    (isset($tutor['latitude']) ? $tutor['latitude'] : 'null'),
                    (isset($tutor['longitude']) ? $tutor['longitude'] : 'null')
                ]
            ]);
        }
    }
}
// api/sessions endpoint - manage sessions
elseif($endpoint[0] == 'sessions') {
    // authenticate
    $token = authenticate();

    // 'api/sessions' base endpoint - invalid - respond with 404
    if (!isset($endpoint[1]) || $endpoint[1] == '?' || $endpoint[1] == '#' || $endpoint[1] == '/')
        emit(404, [ 'message' => 'Please use `api/sessions/create` or `api/sessions/:session_id`' ]);
    // 'api/sessions/create' endpoint - [POST][AUTH] - create new session and respond with session
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
    // 'api/sessions/:session_id' endpoint - manage session
    else {
        // validate and check for id in database
        $id = $endpoint[1];
        if (!isset($id) || !is_string($id) || !ctype_alnum($id))
            emit(500, [ 'message' => 'Invalid ID']);
        $session = $db->get('sessions', $id);
        if ($session == null || $session == false || !is_array($session))
            emit(500, [ 'message' => 'Session not found']);

        // 'api/sessions/:session_id/location' endpoint - [GET/POST][AUTH] - manage session location
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
        // 'api/sessions/:session_id/state' endpoint - [GET/POST][AUTH] - manage session state
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
        // 'api/sessions/:session_id' endpoint - [GET][AUTH] - respond with session
        else {
            // only allow gets
            if ($method != 'get')
                emit(405, [ 'message' => 'Please use `api/sessions/:session_id` with GET' ]);

            // check user authenticity (if user ID in token matches session student/tutor ID)
            if ($token['id'] != $session['student'] && $token['id'] != $session['tutor'])
                emit(403, [ 'message' => "User in token is not authorized to view this session" ]);

            // check data existence
            $lat = @$session['latitude'];
            if (!isset($lat)) $lat = 'null';
            $long = @$session['longitude'];
            if (!isset($long)) $long = 'null';

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


/* CONVENIENCE FUNCTIONS */

// function for responding to/closing request
function emit($code, $data) {
    global $endpoint, $db;
    $db->disconnect();
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
    $jws  = new SimpleJWS([ 'alg' => 'HS256' ]);
    $jws->setPayload([
        'iat' => time(),
        'id' => $id,
        'username' => $username
    ]);
    $jws->sign($salt);
    return $jws->getTokenString();
}

// function for authenticating tokens
function authenticate() {
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
            emit(401, [ 'message' => 'Invalid username/user_id in Authorization Token' ]);
    }
    // return payload on success
    return [
        'id' => $payload['id'],
        'username' => $payload['username']
    ];
}

?>
