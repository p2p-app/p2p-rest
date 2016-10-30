<?php

/*
    API TUTORS ENDPOINT
     - description: manage tutors
     - required_vars: $api, $db
*/

$api['tutors'] = [
    // [GET] get tutors based on location and subjects
    '_GET' => function ($get, $tutorsEP) use (&$api, $db) {
        // authenticate
        $token = authenticate();

        // check data validity
        $lat = @$get['lat'];
        if (!isset($lat) || !is_string($lat) || !is_numeric($lat) || floatval($lat) > 90 || floatval($lat) < -90)
            return [ HTTP_BAD_REQUEST, 'Invalid Parameter: lat' ];
        $lat = floatval($lat);
        $long = @$get['long'];
        if (!isset($long) || !is_string($long) || !is_numeric($long) || floatval($long) > 180 || floatval($long) < -180)
            return [ HTTP_BAD_REQUEST, 'Invalid Parameter: long' ];
        $long = floatval($long);
        $range = @$get['range'];
        if (!isset($range) || !is_string($range) || !is_numeric($range))
            $range = 0.0001;
        $range = floatval($range);
        $latrange = @$get['latrange'];
        if (!isset($latrange) || !is_string($latrange) || !is_numeric($latrange))
            $latrange = $range;
        $latrange = floatval($latrange);
        $longrange = @$get['longrange'];
        if (!isset($longrange) || !is_string($longrange) || !is_numeric($longrange))
            $longrange = $range;
        $longrange = floatval($longrange);
        $subjects = @$get['subjects'];
        if (!isset($subjects) || !is_string($subjects) || strlen($subjects) > 200)
            return [ HTTP_BAD_REQUEST, 'Invalid Parameter: subjects' ];
        $subjects = explode(',', strtolower($subjects));

        // get tutors with DB condition of lat/long
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
            return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving tutors' ];
        elseif ($tutors == null || count($tutors) == 0)
            $tutors = [];
        elseif (isAssoc($tutors))
            $tutors = [ $tutors ];

        // reorganize data and match subjects
        $modTutors = [];
        foreach ($tutors as $j => $tutor) {
            $tutorSubjects = explode(',', $tutor['subjects']);
            foreach ($tutorSubjects as $k => $tutorSubject) {
                if ($subjects[0] == 'all' || in_array($tutorSubject, $subjects)) {
                    $profile = null;
                    if (profilePicture($tutor['id']))
                        $profile = "/images/{$tutor['id']}";
                    array_push($modTutors, [
                        'id' => $tutor['id'],
                        'username' => $tutor['username'],
                        'fullname' => $tutor['fullname'],
                        'school' => $tutor['school'],
                        'bio' => $tutor['bio'],
                        'subjects' => $tutor['subjects'],
                        'city' => $tutor['city'],
                        'stars' => ($tutor['stars'] > 5 ? null : $tutor['stars']),
                        'hours' => $tutor['hours'],
                        'location' => [
                            (isset($tutor['latitude']) ? $tutor['latitude'] : null),
                            (isset($tutor['longitude']) ? $tutor['longitude'] : null)
                        ],
                        'profile' => $profile,
                        'type' => 'tutor'
                    ]);
                    break;
                }
            }
        }

        // send back data
        return [ true, $modTutors ];
    },
    // create - create new tutor
    'create' => [
        // [POST] create new tutor and respond with tutor + token
        '_POST' => function ($post, $createEP) use (&$api, $db) {
            // check data validity
            $username = @$post['username'];
            if (!isset($username) || !is_string($username) || !ctype_alnum($username))
                return [HTTP_BAD_REQUEST, 'Invalid Username' ];
            $username = strtolower($username);
            $password = @$post['password'];
            if (!isset($password) || !is_string($password) || !ctype_alnum($password))
                return [ HTTP_BAD_REQUEST, 'Invalid Password' ];
            $fullname = @$post['fullname'];
            if (!isset($fullname) || !is_string($fullname) || !ctype_alnum(str_replace([ ' ', '-', '.', ',' ], '', $fullname)))
                return [ HTTP_BAD_REQUEST, 'Invalid Full Name' ];
            $school = @$post['school'];
            if (!isset($school) || !is_string($school) || !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')' ], '', $school)))
                return [ HTTP_BAD_REQUEST, 'Invalid School' ];
            $bio = @$post['bio'];
            if (!isset($bio) || !is_string($bio) /*|| !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')', ';', ':', "'", '"', '!', '?' ], '', $bio))*/)
                return [ HTTP_BAD_REQUEST, 'Invalid Bio' ];
            $subjects = @$post['subjects'];
            if (!isset($subjects) || !is_string($subjects) || !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')' ], '', $subjects)))
                return [ HTTP_BAD_REQUEST, 'Invalid Subjects' ];
            $subjects = strtolower($subjects);
            $city = @$post['city'];
            if (!isset($city) || !is_string($city) || !ctype_alnum(str_replace([ ' ', '-', '.', ',' ], '', $city)))
                return [ HTTP_BAD_REQUEST, 'Invalid City' ];

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
            // push tutor to tutors table
            $new_tutor_id = $db->push('tutors', [
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
            if ($new_tutor_id === false)
                 return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while creating tutor' ];
            // respond with token and tutor data
            else return [ true, [
                'token' => createToken($new_tutor_id, $username),
                'data' => [
                    'id' => $new_tutor_id,
                    'username' => $username,
                    'fullname' => $fullname,
                    'school' => $school,
                    'bio' => $bio,
                    'subjects' => $subjects,
                    'stars' => null,
                    'hours' => 0,
                    'city' => $city,
                    'location' => [ null, null ],
                    'profile' => null,
                    'type' => 'tutor'
                ]
            ] ];
        }
    ],
    // :tutor_id - manage individual tutor
    '*' => [
        '__this' => [
            // endpoint initialization
            '__init' => function (&$wildcardEP, $tutor_id) use (&$api, $db) {
                // authenticate
                $token = authenticate();
                // validate and check for id in database
                if (!isset($tutor_id) || !is_string($tutor_id) || !ctype_alnum($tutor_id))
                    return [ HTTP_BAD_REQUEST, 'Invalid :tutor_id in URI' ];
                $tutor = $db->get('tutors', $tutor_id);
                if ($tutor === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving tutor' ];
                elseif ($tutor == null)
                    return [ HTTP_NOT_FOUND, 'Tutor not found' ];
                $wildcardEP['tutor'] = $tutor;
                $wildcardEP['token'] = $token;
                return [ true ];
            }
        ],
        // [GET] get tutor data by id
        '_GET' => function ($get, $wildcardEP) use (&$api, $db) {
            $tutor = $wildcardEP['tutor'];
            $profile = null;
            if (profilePicture($tutor['id']))
                $profile = "/images/{$tutor['id']}";
            $data = [
                'id' => $tutor['id'],
                'username' => $tutor['username'],
                'fullname' => $tutor['fullname'],
                'school' => $tutor['school'],
                'bio' => $tutor['bio'],
                'subjects' => $tutor['subjects'],
                'city' => (!isset($tutor['city']) ? null : $tutor['city']),
                'stars' => ($tutor['stars'] > 5 ? null : $tutor['stars']),
                'hours' => $tutor['hours'],
                'location' => [
                    (isset($tutor['latitude']) && !(floatval($tutor['latitude']) > 199) ? floatval($tutor['latitude']) : null),
                    (isset($tutor['longitude']) && !(floatval($tutor['longitude']) > 199) ? floatval($tutor['longitude']) : null)
                ],
                'profile' => $profile,
                'type' => 'tutor'
            ];
            if (@$get['reviewData'] == true || @$get['reviewData'] === 'true') {
                $reviews = rest($api, 'tutors/' . $tutor['id'] . '/reviews', 'get', [ ]);
                if ($reviews[0] === true)
                    $data['reviews'] = $reviews[1];
                else return $reviews;
            }
            // respond with tutor data
            return [ true, $data ];
        },
        // reviews - manage tutor reviews
        'reviews' => [
            // [GET] get all reviews for tutor
            '_GET' => function ($get, $reviewsEP) use (&$api, $db) {
                $tutor = $reviewsEP['__parent']['tutor'];
                $reviews = $db->get('reviews', [
                    'forTutor' => $tutor['id']
                ]);
                if ($reviews === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving reviews' ];
                elseif ($reviews == null || count($reviews) == 0)
                    return [ true, [ ] ];

                if (isAssoc($reviews))
                    $reviews = [ $reviews ];
                foreach ($reviews as $i => $review)
                    $reviews[$i] = [
                        'id' => $review['id'],
                        'from' => $review['fromStudent'],
                        'stars' => $review['stars'],
                        'text' => $review['reviewText'],
                        'created' => date(DateTime::ISO8601, $review['created'])
                    ];

                return [ true, $reviews ];
            },
            // create - create new reviews for tutor
            'create' => [
                // [POST] - create new review for user and respond with review data
                '_POST' => function ($post, $createEP) use (&$api, $db) {
                    $tutor = $createEP['__parent']['__parent']['tutor'];
                    // check data validity
                    $from = @$post['from'];
                    if (!isset($from) || !is_string($from) || !ctype_alnum($from))
                        return [ HTTP_BAD_REQUEST, 'Invalid Parameter: from' ];
                    $stars = @$post['stars'];
                    if (!isset($stars) || !is_numeric($stars) || floatval($stars) > 5 || floatval($stars) < 0)
                        return [ HTTP_BAD_REQUEST, 'Invalid Parameter: stars' ];
                    else $stars = floatval($stars);
                    $text = @$post['text'];
                    if (!isset($text) || !is_string($text) || strlen($text) <= 0 /*|| !ctype_alnum(str_replace([ ' ', '-', '.', ',', '(', ')', ';', ':', "'", '"', '!', '?' ], '', $text))*/)
                        return [ HTTP_BAD_REQUEST, 'Invalid Parameter: text' ];
                    $created = time();

                    // check for "from" user in database
                    $fromUser = $db->get('students', $from);
                    if ($fromUser === false)
                        return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while searching for student with "from" ID' ];
                    elseif ($fromUser == null)
                        return [ HTTP_NOT_FOUND, 'Student with "from" ID not found' ];

                    // average stars
                    $oldstars = $tutor['stars'];
                    $totalreviews = $db->get('reviews', [
                        'forTutor' => $tutor['id']
                    ], [ 'stars' ]);
                    if ($totalreviews === false)
                        return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while calculating average of stars' ];
                    if ($totalreviews > 0) {
                        if ($oldstars === 'null' || $oldstars == null || (is_numeric($oldstars) && floatval($oldstars) > 5)) {
                            foreach ($totalreviews as $i => $pastreview) {
                                if (is_array($pastreview) && isset($pastreview['stars']) && is_numeric($pastreview['stars']))
                                    $stars += floatval($pastreview['stars']);
                                elseif (is_numeric($pastreview))
                                    $stars += floatval($pastreview);
                            }
                        } else $stars += floatval($oldstars) * count($totalreviews);
                        $stars /= count($totalreviews) + 1;
                    }
                    $averaged = $db->set('tutors', $tutor['id'], [
                        'stars' => $stars
                    ]);
                    if ($averaged === false)
                        return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while updating average of stars' ];

                    // push review to reviews table
                    $review = $db->push('reviews', [
                        'forTutor' => $tutor['id'],
                        'fromStudent' => $from,
                        'stars' => [
                            'val' => $stars,
                            'type' => 'integer(100)'
                        ],
                        'reviewText' => [
                            'val' => $text,
                            'type' => 'text'
                        ],
                        'created' => [
                            'val' => $created,
                            'type' => 'integer'
                        ]
                    ]);
                    // check if push failed
                    if ($review === false)
                        return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while creating review' ];
                    // respond with review data
                    else return [ true, [
                        'id' => $review,
                        'from' => $from,
                        'stars' => $stars,
                        'text' => $text,
                        'created' => date(DateTime::ISO8601, $created)
                    ] ];
                }
            ],
            // :review_id - manage individual reviews
            '*' => [
                '__this' => [
                    // endpoint initialization
                    '__init' => function (&$wildcardEP, $review_id) use (&$api, $db) {
                        // validate and check for id in database
                        if (!isset($review_id) || !is_string($review_id) || !ctype_alnum($review_id))
                            return [ HTTP_BAD_REQUEST, 'Invalid :review_id in URI' ];

                        $tutor_id = $wildcardEP['__parent']['__parent']['tutor']['id'];
                        $review = $db->get('reviews', [
                            'id' => $review_id,
                            'forTutor' => $tutor_id
                        ]);
                        if ($review === false)
                            return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving review' ];
                        elseif ($review == null)
                            return [ HTTP_NOT_FOUND, 'Review not found' ];

                        $wildcardEP['review'] = $review;
                        return [ true ];
                    }
                ],
                // [GET] get review by id
                '_GET' => function ($get, $wildcardEP) use (&$api, $db) {
                    $review = $wildcardEP['review'];
                    return [ true, [
                        'id' => $review['id'],
                        'from' => $review['fromStudent'],
                        'stars' => $review['stars'],
                        'text' => $review['reviewText'],
                        'created' => date(DateTime::ISO8601, $review['created'])
                    ] ];
                }
            ]
        ],
        // hours - manage tutor hours
        'hours' => [
            // [GET] get tutor hours
            '_GET' => function ($get, $hoursEP) use (&$api, $db) {
                $tutor = $hoursEP['__parent']['tutor'];
                $hours = $tutor['hours'];
                // send back data
                return [ true, [
                    'hours' => $hours
                ] ];
            },
            // [POST] update/add hours and respond with updated hours
            '_POST' => function ($post, $hoursEP) use (&$api, $db) {
                $tutor = $hoursEP['__parent']['tutor'];
                // check data validity
                $add = @$post['add'];
                $hours = @$post['hours'];
                if (!isset($hours) || !is_string($hours) || !is_numeric($hours))
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Invalid parameter: hours' ];
                $hours = intval($hours);

                // add hours to existing if desired
                if (@$add === true || @$add === 'true') {
                    $oldhours = $tutor['hours'];
                    if (is_string($oldhours))
                        $oldhours = intval($oldhours);
                    $hours += $oldhours;
                }

                // set hours in database
                if ($db->set('tutors', $tutor['id'], [
                    'hours' => [
                        'val' => $hours,
                        'type' => 'integer'
                    ]
                ]) === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, [ 'message' => 'Error while updating hours' ] ];

                // send back data
                return [ true, [
                    'hours' => $hours
                ] ];
            }
        ],
        // location - manage tutor location
        'location' => [
            // [POST] update tutor location and respond with updated location
            '_POST' => function ($post, $locationEP) use (&$api, $db) {
                // check user authenticity (if user ID in token matches tutor ID)
                $tutor_id = $locationEP['__parent']['tutor']['id'];
                $token_id = $locationEP['__parent']['token']['id'];
                if ($tutor_id != $token_id)
                    return [ HTTP_FORBIDDEN, "User in token is not authorized to edit this tutor's location" ];

                // check data validity
                $lat = @$_POST['lat'];
                if (!isset($lat) || !is_string($lat) || (strtolower($lat) != 'null' && (!is_numeric($lat) || (floatval($lat) > 90) || (floatval($lat) < -90))))
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Invalid parameter: lat' ];
                if ($lat == null || strtolower($lat) == 'null') $lat = null;
                else $lat = floatval($lat);
                $long = @$_POST['long'];
                if (!isset($long) || !is_string($long) || (strtolower($long) != 'null' && (!is_numeric($long) || (floatval($long) > 180) || (floatval($long) < -180))))
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Invalid Parameter: long' ];
                $long = strtolower($long);
                if ($long == null || strtolower($long) == 'null') $long = null;
                else $long = floatval($long);

                // set location in database
                if ($db->set('tutors', $tutor_id, [
                    'latitude' => [
                        'val' => ($lat == 'null' || $lat == null) ? 200 : $lat,
                        'type' => 'double'
                    ],
                    'longitude' => [
                        'val' => ($long == 'null' || $long == null) ? 200 : $long,
                        'type' => 'double'
                    ],
                ]) === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while updating location' ];

                // send back data
                return [ true, [
                    'lat' => $lat,
                    'long' => $long
                ] ];
            },
            // [GET] get tutor location
            '_GET' => function ($get, $locationEP) use (&$api, $db) {
                $lat = @$locationEP['__parent']['tutor']['latitude'];
                $long = @$locationEP['__parent']['tutor']['longitude'];
                if (!isset($lat) || !isset($long))
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving location' ];
                $lat = (is_string($lat) && strtolower($lat) === 'null') || floatval($lat) > 199 || $lat == null ? null : floatval($lat);
                $long = (is_string($long) && strtolower($long) === 'null') || floatval($long) > 199 || $long == null ? null : floatval($long);

                // send back data
                return [ true, [
                    'lat' => $lat,
                    'long' => $long
                ] ];
            }
        ]
    ]
];

?>
