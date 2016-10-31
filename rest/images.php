<?php

/*
    API IMAGES ENDPOINT
     - description: manage images (profile pictures)
     - required_vars: $api, $db, $images_dir
*/

$api['images'] = [
    // upload - upload profile picture
    'upload' => [
        '__this' => [
            // endpoint initialization
            '__init' => function (&$imagesEP) use (&$api, $db) {
                // authenticate
                $token = authenticate();
                $imagesEP['token'] = $token;
            }
        ],
        // [POST] accept new profile picture and update
        '_POST' => function ($post, $uploadEP) use (&$api, $db, $images_dir) {
            // load image upload data
            $image = @$_FILES['profile'];
            $token = $uploadEP['token'];
            $user_id = $token['id'];
            if (!isset($image) || !is_array($image))
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: profile - incorrectly named/empty image submitted' ];
            switch (@$image['error']) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return [ HTTP_BAD_REQUEST, 'Invalid Parameter: profile - image too large' ];
                    break;
                case UPLOAD_ERR_PARTIAL:
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while uploading file - file was only partially uploaded' ];
                    break;
                default:
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while uploading file - unknown' ];
                    break;
            }
            if (@$image['type'] != 'image/png')
                return [ HTTP_BAD_REQUEST, 'Invalid Parameter: profile - image not a PNG (Portable Network Graphics) type image' ];

            // save image
            if (!move_uploaded_file($image['tmp_name'], "$images_dir/$user_id.png"))
                return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while saving uploaded image' ];
            // respond with success
            return [ true, [
                'message' => 'Image uploaded successfully',
                'image' => "/images/$user_id"
            ] ];
        }
    ],
    // :user_id - manage individual users profile pictures
    '*' => [
        '__this' => [
            '__init' => function (&$wildcardEP, $user_id) use (&$api, $db) {
                if (!isset($user_id) || !is_string($user_id) || !ctype_alnum($user_id))
                    return [ HTTP_BAD_REQUEST, 'Invalid :user_id in URI' ];
                $user = $db->get('students', $user_id, [ 'id', 'username' ]);
                if ($user === false)
                    return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving student' ];
                elseif ($user == null) {
                    $user = $db->get('tutors', $user_id, [ 'id', 'username' ]);
                    if ($user === false)
                        return [ HTTP_INTERNAL_SERVER_ERROR, 'Error while retrieving tutor' ];
                    elseif ($user == null)
                        return [ HTTP_NOT_FOUND, 'User not found' ];
                }
                $wildcardEP['user_id'] = $user['id'];
            }
        ],
        // get profile picture by ID
        '_GET' => function ($get, $wildcardEP) use (&$api, $db, $images_dir) {
            $user_id = $wildcardEP['user_id'];
            if (profilePicture($user_id)) {
                header('Content-type: image/png');
                readfile("$images_dir/$user_id.png");
                die();
            } else return [ HTTP_NOT_FOUND, 'User has no profile picture' ];
        }
    ]
];

?>
