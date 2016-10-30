<?php

/*
    API AUTHENTICATION ENDPOINT
     - description: manage authentication of users
     - required_vars: $api, $db
*/

$api['auth'] = [
    // [GET] authenticate token and respond with user
    '_GET' => function ($get, $authEP) use (&$api, $db) {
        // authenticate
        $token = authenticate();
        // search students table for user
        $data = rest($api, 'students/' . $token['id'], 'get', [ ]);
        // if student found or error ocurred, respond
        if ($data[0] === true) return [ true, $data[1] ];
        elseif ($data[0] != HTTP_NOT_FOUND) return $data;
        // if student not found, search tutors table
        $data = rest($api, 'tutors/' . $token['id'], 'get', [ ]);
        // if tutor found or error ocurred, respond
        if ($data[0] === true) return [ true, $data[1] ];
        elseif ($data[0] != HTTP_NOT_FOUND) return $data;
        // if student/tutor not found respond with failure
        return [ HTTP_NOT_FOUND, 'User not found' ];
    },
    // [POST] authenticate student/tutor username + password and respond with user + token
    '_POST' => function ($post, $authEP) use (&$api, $db) {
        // check username and password validity
        $username = @$post['username'];
        if (!isset($username) || !is_string($username) || !ctype_alnum($username))
            return [ HTTP_BAD_REQUEST, 'Invalid Parameter: username' ];
        $username = strtolower($username);
        $password = @$post['password'];
        if (!isset($password) || !is_string($password) || !ctype_alnum($password))
            return [ HTTP_BAD_REQUEST, 'Invalid Parameter: password' ];

        // check username and password against database
        $password = hash2($password);
        // search students table for user
        $type = 'student';
        $user = $db->get('students', [
            'username' => $username,
            'password' => $password
        ], [ 'id', 'username' ]);
        if ($user === false)
            return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while searching for student' ];
        // if student not found
        elseif ($user == null) {
            $type = 'tutor';
            // check tutors table for user
            $user = $db->get('tutors', [
                'username' => $username,
                'password' => $password
            ], [ 'id', 'username' ]);
            if ($user === false)
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while searching for tutor' ];
        }

        // fail if username or password not found in either table
        if ($user == null) return [ HTTP_NOT_FOUND, 'User not found' ];

        // get full user data
        $old_auth = @$_SERVER['HTTP_AUTHORIZATION'];
        $token = createToken($user['id'], $user['username']);
        $_SERVER['HTTP_AUTHORIZATION'] = $token;
        $fullUser = rest($api, 'auth', 'get', [ ]);
        $_SERVER['HTTP_AUTHORIZATION'] = @$old_auth;
        if ($fullUser[0] != true) return $fullUser;

        // respond with token and user data
        return [ true, [
            'token' => $token,
            'data' => $fullUser[1]
        ]];
    }
];

?>
