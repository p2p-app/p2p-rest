<?php

/*
    API STUDENTS ENDPOINT
     - description: manage students
     - required_vars: $api, $db
*/

$api['students'] = [
    // create - create new student
    'create' => [
        // [POST] create new student and respond with student + token
        '_POST' => function ($post, $createEP) use (&$api, $db) {
            // check username and password validity
            $username = @$post['username'];
            if (!isset($username) || !is_string($username) || !ctype_alnum($username))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: username' ];
            $username = strtolower($username);
            $password = @$post['password'];
            if (!isset($password) || !is_string($password) || !ctype_alnum($password))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: password' ];
            $fullname = @$post['fullname'];
            if (!isset($fullname) || !is_string($fullname) || !ctype_alnum(str_replace([ ' ', '-', '.', ',' ], '', $fullname)))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: fullname' ];

            // add user to database
            $password = hash2($password);
            // check if username taken
            $exists = $db->get('students', [
                'username' => $username
            ], [ 'id' ]);
            if ($exists === false)
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while checking username availability' ];
            elseif ($exists != null)
                return [ HTTP_CONFLICT, 'Username Not Available' ];
            $exists = $db->get('tutors', [
                'username' => $username
            ], [ 'id' ]);
            if ($exists === false)
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while checking username availability' ];
            elseif ($exists != null)
                return [ HTTP_CONFLICT, 'Username Not Available' ];
            // push student to students table
            $new_student_id = $db->push('students', [
                'username' => $username,
                'password' => $password,
                'fullname' => $fullname
            ]);
            // check if push fails
            if ($new_student_id === false)
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while creating user' ];
            // respond with token and student data
            else return [ true, [
                'token' => createToken($new_student_id, $username),
                'data' => [
                    'id' => $new_student_id,
                    'username' => $username,
                    'fullname' => $fullname,
                    'profile' => 'null'
                ]
            ] ];
        }
    ],
    // :student_id - manage individual student
    '*' => [
        '__this' => [
            // endpoint initialization
            '__init' => function (&$wildcardEP, $student_id) use (&$api, $db) {
                // authenticate
                $token = authenticate();
                // validate and check for id in database
                if (!isset($student_id) || !is_string($student_id) || !ctype_alnum($student_id))
                    return [ HTTP_BAD_REQUEST, 'Invalid :student_id in URI' ];
                $student = $db->get('students', $student_id);
                if ($student === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving student' ];
                elseif ($student == null)
                    return [ HTTP_NOT_FOUND, 'Student not found' ];

                $wildcardEP['student'] = $student;
                $wildcardEP['token'] = $token;
                return [ true ];
            }
        ],
        // [GET] get student data by id
        '_GET' => function ($get, $wildcardEP) use (&$api, $db) {
            $student = $wildcardEP['student'];
            $profile = 'null';
            if (profilePicture($student['id']))
                $profile = "/images/{$student['id']}";
            // respond with student data
            return [ true, [
                'id' => $student['id'],
                'username' => $student['username'],
                'fullname' => $student['fullname'],
                'profile' => $profile
            ] ];
        }
    ]
];

?>
