<?php

/*
    API SESSIONS ENDPOINT
     - description: manage sessions
     - required_vars: $api, $db
*/

$api['sessions'] = [
    '__this' => [
        // endpoint initialization
        '__init' => function (&$sessionsEP) use (&$api, $db) {
            // authenticate
            $token = authenticate();
            // search students table for user
            $exists = $db->get('students', $token['id'], [ 'id' ]);
            if ($exists === false)
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while searching for student' ];
            $sessionsEP['type'] = $exists == null ? 'tutor' : 'student';
            $sessionsEP['token'] = $token;
        }
    ],
    // [GET] get authenticated user's sessions
    '_GET' => function ($get, $sessionsEP) use (&$api, $db) {
        // validate data
        $tutorData = @$get['tutorData'] == true || @$get['tutorData'] === 'true';
        $studentData = @$get['studentData'] == true || @$get['studentData'] === 'true';
        // get sessions of user
        $sessions = $db->get('sessions', [
            $sessionsEP['type'] => $sessionsEP['token']['id']
        ]);
        if ($sessions === false)
            return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving sessions' ];
        // reorganize sessions
        $data = null;
        if ($sessions == null)
            $data = [ ];
        if (isAssoc($sessions))
            $data = [ $sessions ];
        else $data = $sessions;
        foreach ($data as $i => $session) {
            // get tutor if desired
            $tutor = null;
            if ($tutorData) {
                $tutor = rest($api, 'tutors/' . $session['tutor'], 'get', [ ]);
                if ($tutor[0] == true)
                    $tutor = $tutor[1];
                else return $tutor;
            }
            // get student if desired
            $student = null;
            if ($studentData) {
                $student = rest($api, 'students/' . $session['student'], 'get', [ ]);
                if ($student[0] == true)
                    $student = $student[1];
                else return $student;
            }
            $data[$i] = [
                'id' => $session['id'],
                'student' => $student == null ? $session['student'] : $student,
                'tutor' => $tutor == null ? $session['tutor'] : $tutor,
                'location' => [ $session['latitude'], $session['longitude'] ],
                'created' => date(DateTime::ISO8601, $session['created']),
                'state' => $session['state']
            ];
        }

        // respond with sessions
        return [ true, $data ];
    },
    // create - create new session
    'create' => [
        // [POST] create new session and respond with session data
        '_POST' => function ($post, $createEP) use (&$api, $db) {
            // validate data
            if ($createEP['__parent']['type'] == 'tutor')
                // only students can create session
                return [ HTTP_FORBIDDEN, 'Authenticated user is not a student' ];
            $student = $createEP['__parent']['token']['id'];
            $tutor = @$post['tutor'];
            if (!isset($tutor) || !is_string($tutor) || !ctype_alnum($tutor))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: tutor' ];
            $exists = $db->get('tutors', $tutor, [ 'id' ]);
            if ($exists === false)
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while searching for tutor' ];
            if ($exists == null)
                return [ HTTP_BAD_REQUEST, 'Specified tutor does not exist' ];
            $exists = null;
            $lat = @$post['lat'];
            if (!isset($lat) || !is_string($lat) || !is_numeric($lat) || (floatval($lat) > 90) || (floatval($lat) < -90))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: lat' ];
            $lat = strtolower($lat);
            $long = @$post['long'];
            if (!isset($long) || !is_string($long) || !is_numeric($long) || (floatval($long) > 180) || (floatval($long) < -180))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: long' ];
            $long = strtolower($long);
            $created = time();

            // push session to sessions table
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
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while creating session' ];

            return [ true, [
                'id' => $session,
                'student' => $student,
                'tutor' => $tutor,
                'location' => [ $lat, $long ],
                'created' => date(DateTime::ISO8601, $created),
                'state' => 'pending'
            ] ];
        }
    ],
    // :session_id - manage individual session
    '*' => [
        '__this' => [
            // endpoint initialization
            '__init' => function (&$wildcardEP, $session_id) use (&$api, $db) {
                // validate and check for session in table
                if (!isset($session_id) || !is_string($session_id) || !ctype_alnum($session_id))
                    return [ HTTP_BAD_REQUEST, 'Invalid :session_id in URI' ];
                $session = $db->get('sessions', $session_id);
                if ($session == false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving session' ];
                if ($session == null)
                    return [ HTTP_NOT_FOUND, 'Session not found' ];
                $wildcardEP['session'] = $session;

                // check user authenticity (if user ID in token matches session student/tutor ID)
                $session = $wildcardEP['session'];
                if ($wildcardEP['__parent']['token']['id'] != $session['student'] && $wildcardEP['__parent']['token']['id'] != $session['tutor'])
                    return [ HTTP_FORBIDDEN, 'Authenticated user is not authorized to access this session' ];
            }
        ],
        // [GET] get session by id
        '_GET' => function ($get, $wildcardEP) use (&$api, $db) {
            $session = $wildcardEP['session'];
            // check data existence
            $lat = @$session['latitude'];
            if (!isset($lat) || floatval($lat) > 180) $lat = 'null';
            else $lat = floatval($lat);
            $long = @$session['longitude'];
            if (!isset($long) || floatval($long) > 180) $long = 'null';
            else $long = floatval($long);

            // get tutor if desired
            $tutor = null;
            if (@$get['tutorData'] === true || @$get['tutorData'] === 'true') {
                $tutor = rest($api, 'tutors/' . $session['tutor'], 'get', [ ]);
                if ($tutor[0] == true)
                    $tutor = $tutor[1];
                else return $tutor;
            }
            // get student if desired
            $student = null;
            if (@$get['studentData'] === true || @$get['studentData'] === 'true') {
                $student = rest($api, 'students/' . $session['student'], 'get', [ ]);
                if ($student[0] == true)
                    $student = $student[1];
                else return $student;
            }

            // succeed with session data
            return [ true, [
                'id' => $session['id'],
                'location' => [ $lat, $long ],
                'student' => $student == null ? $session['student'] : $student,
                'tutor' => $tutor == null ? $session['tutor'] : $tutor,
                'created' => date(DateTime::ISO8601, $session['created']),
                'state' => $session['state']
            ] ];
        },
        // location - manage session location
        'location' => [
            // [POST] update session location and respond with updated location
            '_POST' => function ($post, $locationEP) use (&$api, $db) {
                $session = $locationEP['__parent']['session'];
                // check data validity
                $lat = @$post['lat'];
                if (!isset($lat) || !is_string($lat) || (strtolower($lat) != 'null' && (!is_numeric($lat) || (floatval($lat) > 90) || (floatval($lat) < -90))))
                    return [ HTTP_BAD_REQUEST, 'Invalid parameter: lat' ];
                $lat = strtolower($lat);
                $long = @$post['long'];
                if (!isset($long) || !is_string($long) || (strtolower($long) != 'null' && (!is_numeric($long) || (floatval($long) > 180) || (floatval($long) < -180))))
                    return [ HTTP_BAD_REQUEST, 'Invalid parameter: long' ];
                $long = strtolower($long);

                // update location in sessions table
                if ($db->set('sessions', $session['id'], [
                    'latitude' => [
                        'val' => ($lat == 'null') ? 200 : $lat,
                        'type' => 'double'
                    ],
                    'longitude' => [
                        'val' => ($long == 'null') ? 200 : $long,
                        'type' => 'double'
                    ],
                ]) === false)
                    return [ HTTP_BAD_REQUEST, 'Error while update session location' ];

                // send back data
                return [ true, [
                    'lat' => $lat,
                    'long' => $long
                ] ];
            },
            // [GET] get session location
            '_GET' => function ($post, $locationEP) use (&$api, $db) {
                $session = $locationEP['__parent']['session'];
                $lat = @$session['latitude'];
                $long = @$session['longitude'];
                if (!isset($lat) || !isset($long) || (is_string($lat) && strtolower($lat) == 'null') || (is_string($long) && strtolower($long) == 'null'))
                    emit(500, [ 'message' => 'Student has not shared location' ]);
                $lat = (floatval($lat) > 199) ? 'null' : floatval($lat);
                $long = (floatval($long) > 199) ? 'null' : floatval($long);

                // send location data
                return [ true, [
                    'lat' => $lat,
                    'long' => $long
                ] ];
            }
        ],
        // state - manage session state
        'state' => [
            // [POST] update session state and respond with updated state
            '_POST' => function ($post, $stateEP) use (&$api, $db) {
                $session = $stateEP['__parent']['session'];
                $token = $stateEP['__parent']['__parent']['token'];
                // check action validity based on user and current state
                $action = @$post['action'];
                if (@$action == 'confirm') {
                    // only tutors can confirm
                    // check user authenticity (if user ID in token matches session tutor ID)
                    if ($session['tutor'] != $token['id'])
                        return [ HTTP_FORBIDDEN, 'Authenticated user is not authorized to confirm this session' ];
                    if (@$session['state'] != 'pending')
                        return [ HTTP_BAD_REQUEST, 'Session is not in "pending" state and cannot be confirmed' ];
                    $suffix = 'ed';
                } elseif (@$action == 'commence') {
                    // only students can commence
                    // check user authenticity (if user ID in token matches session student ID)
                    if ($session['student'] != $token['id'])
                        return [ HTTP_FORBIDDEN, 'Authenticated user is not authorized to commence this session' ];
                    if (@$session['state'] != 'confirmed')
                        return [ HTTP_BAD_REQUEST, 'Session is not in "confirmed" state and cannot be commenced' ];
                    $suffix = 'd';
                } elseif (@$action == 'complete') {
                    // only tutors can complete
                    // check user authenticity (if user ID in token matches session student ID)
                    if ($session['student'] != $token['id'])
                        return [ HTTP_FORBIDDEN, 'Authenticated user is not authorized to complete this session' ];
                    if (@$session['state'] != 'commenced')
                        return [ HTTP_BAD_REQUEST, 'Session is not in "commenced" state and cannot be completed' ];
                    $suffix = 'd';
                } else return [ HTTP_BAD_REQUEST, 'Invalid Parameter: action' ];

                // update new state in db
                $update = $db->set('sessions', $session['id'], [
                    'state' => $action . $suffix
                ]);
                if ($update === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while updating session state' ];

                // send state data on success
                return [ true, [
                    'state' => $action . $suffix
                ] ];
            },
            // [GET] get session state
            '_GET' => function ($get, $stateEP) use (&$api, $db) {
                $session = $stateEP['__parent']['session'];
                // send state data
                return [ true, [
                    'state' => $session['state']
                ] ];
            }
        ]
    ]
];

?>
