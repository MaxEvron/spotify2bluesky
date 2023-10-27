#!/usr/bin/php
<?php

// Go to https://developer.spotify.com/dashboard/create to create your app, check Web API, then copy-paste your client_id, client_secret and redirect_uri bellow
define('SPOT_CLIENT_ID', '<< client_id >>');
define('SPOT_CLIENT_SECRET', '<< client_secret >>');
define('SPOT_REDIRECT_URI', '<< redirect_uri >>');

// Go to https://bsky.app/settings/app-passwords to create an app password, then copy-paste your handle (without @) and app password
define('BLUE_HANDLE', '<< handle_without_@ >>');
define('BLUE_APP_PASSWORD', '<< app_password >>');

function is_cli()
{
    return (defined('STDIN'))
        || (php_sapi_name() === 'cli')
        || (array_key_exists('SHELL', $_ENV))
        || (empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0)
        || (!array_key_exists('REQUEST_METHOD', $_SERVER));
}

function curlCheckError($resCurl)
{
    if (($curlErrNo = curl_errno($resCurl)) > 0) {
        die ("cURL Error ($curlErrNo): " . curl_error($resCurl) . "\n");
    } else {
        return true;
    }
}

function generateRandomString($length = 64)
{
    $charactersLength = strlen($characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

function doSpotifyAuth()
{
    // Prepare query string
    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => SPOT_CLIENT_ID,
        'scope' => 'user-read-private user-library-read',
        'redirect_uri' => SPOT_REDIRECT_URI,
        'state' => $state = generateRandomString()
    ]);

    // Store state value, preventing x-crossing forgery
    file_put_contents(__DIR__ . '/rw/state.dat', $state);

    // Request user to authenticate
    echo ("Authentication needed, please visit:\nhttps://accounts.spotify.com/authorize?{$query}\n");

    // Loop for maximum 30 seconds, until user authenticates and grants access
    $i = 0;
    while ($i < 15) {
        sleep(2);
        echo ".";

        if (FALSE !== $s2b_code = @file_get_contents(__DIR__ . '/rw/code.dat')) {
            $resCurl = curl_init();

            curl_setopt_array($resCurl, [
                CURLOPT_URL => 'https://accounts.spotify.com/api/token',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode(SPOT_CLIENT_ID . ':' . SPOT_CLIENT_SECRET)
                ],
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_RETURNTRANSFER => TRUE,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $s2b_code,
                    'redirect_uri' => SPOT_REDIRECT_URI
                ])
            ]);

            $token_return = @curl_exec($resCurl);

            if (curlCheckError($resCurl)) {
                $token_data = @json_decode($token_return, true);

                if (!is_array($token_data)) {
                    die ("Spotify authentication exception: unexpected data.\n{$token_return}\n");
                } elseif (array_key_exists('error', $token_data)) {
                    if (array_key_exists('error_description', $token_data)) {
                        die ("Spotify authentication error: {$token_data['error_description']}.\n");
                    } else {
                        die ("Spotify authentication error:\n{$token_return}\n");
                    }
                } elseif (!array_key_exists('access_token', $token_data)
                    || !array_key_exists('token_type', $token_data)
                    || !array_key_exists('refresh_token', $token_data)
                    || ($token_data['token_type'] !== 'Bearer')
                ) {
                    die ("Spotify authentication error, missing access_token, token_type or token_type.\n{$token_return}\n");
                } else {
                    $token_data['expires_at'] = (new DateTime())
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->add(new DateInterval('PT' . $token_data['expires_in'] - 300  . 'S'))
                        ->format('Y-m-d\TH:i:s\Z');

                    $refresh_token = $token_data['refresh_token'];

                    unset($token_data['refresh_token']);
                    unset($token_data['expires_in']);

                    file_put_contents(__DIR__ . '/rw/token.json', json_encode($token_data, JSON_PRETTY_PRINT));
                    file_put_contents(__DIR__ . '/rw/refresh_token.dat', $refresh_token);
                    @unlink(__DIR__ . '/rw/code.dat');
                    @unlink(__DIR__ . '/rw/state.dat');
                    echo "\n";
                    $i = 15;
                }
            }
            $i++;
        }
    }
}

function getSpotifyToken () {
    if (FALSE === $token_file = @file_get_contents(__DIR__ . '/rw/token.json')) {
        // Try to read token file, else return false.
        return FALSE;
    } elseif (! is_array($token_data = @json_decode($token_file, true)) ) {
        // Try to decode token date, else die.
        die ("Spotify token exception: unexpected data.\n{$token_file}\n");
    } elseif (
        ! array_key_exists('access_token', $token_data)
        ||  ! array_key_exists('expires_at', $token_data)
    ) {
        // Check if expected data are present, else die.
        die ("Spotify token exception, missing access_token or expires_at in dataset.\n{$token_file}");
    } else {
        if ($token_data['expires_at'] < (new DateTime())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')) {
            // If saved token expired then try to renew

            if (FALSE == $refresh_token = file_get_contents(__DIR__ . '/rw/refresh_token.dat')) {
                die ('Spotify token exception: unable to get refresh token. Proceed with authentication again');
            } else {
                $resCurl = curl_init();

                curl_setopt_array($resCurl, [
                    CURLOPT_URL => 'https://accounts.spotify.com/api/token',
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Authorization: Basic ' . base64_encode(SPOT_CLIENT_ID . ':' . SPOT_CLIENT_SECRET)
                    ],
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_RETURNTRANSFER => TRUE,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refresh_token,
                    ])
                ]);

                $token_return = @curl_exec($resCurl);

                if (curlCheckError($resCurl)) {
                    // If no cURL error, proceed with decoding
                    $token_data = @json_decode($token_return, true);

                    if (!is_array($token_data)) {
                        // Check if data are well-formed, else die.
                        die ("Spotify token exception: unexpected data.\n{$token_return}\n");
                    } elseif (array_key_exists('error', $token_data)) {
                        // If an error occurred, die
                        if (array_key_exists('error_description', $token_data)) {
                            die ("Spotify token error: {$token_data['error_description']}.\n");
                        } else {
                            die ("Spotify token error:\n{$token_return}\n");
                        }
                    } elseif (!array_key_exists('access_token', $token_data)
                        || !array_key_exists('token_type', $token_data)
                        || ($token_data['token_type'] !== 'Bearer')
                    ) {
                        // Check if expected data are present, else die.
                        die ("Spotify token error, missing access_token or token_type.\n{$token_return}\n");
                    } else {
                        // Compute expiration date, then save token.
                        $token_data['expires_at'] = (new DateTime())
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->add(new DateInterval('PT' . $token_data['expires_in'] - 300 . 'S'))
                            ->format('Y-m-d\TH:i:s\Z');
                        unset($token_data['expires_in']);
                        file_put_contents(__DIR__ . '/rw/token.json', json_encode($token_data, true));
                    }
                }
            }
        }

        // Finally, return access token.
        return $token_data['access_token'];
    }
}

function getNewSpotifyTracks($sp_token)
{
    $items = [];

    // Try to get timestamp for latest track added.
    $last_run = @file_get_contents(__DIR__ . '/rw/last_run.dat');

    // Try to get user's saved tracks ('Your music')
    $resCurl = curl_init();

    curl_setopt_array($resCurl, [
        CURLOPT_URL => 'https://api.spotify.com/v1/me/tracks',
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$sp_token}"
        ],
        CURLOPT_POSTFIELDS => FALSE
    ]);

    $tracks_return = curl_exec($resCurl);
    if (curlCheckError($resCurl)) {
        // Try to decode data and check 'items' data is present
        if (!is_array($tracks_data = @json_decode($tracks_return, true))
            || !array_key_exists('items', $tracks_data)
        ) {
            die ("Spotify tracks exception: unexpected data.\n{$tracks_return}\n");
        } else {
            // If already ran, get tracks added since last time.
            if (FALSE !== $last_run) {
                // Keep only items added after last run.
                foreach ($tracks_data['items'] as $item) {
                    if ($item['added_at'] > $last_run) {
                        array_push($items, $item);
                    }
                }
            }

            // If tracks found and last_run differs from timestamp for the most recent
            // track added to library, then save this timestamp
            if (count($tracks_data['items']) && ($last_run !== $tracks_data['items'][0]['added_at'])) {
                @file_put_contents(__DIR__ . '/rw/last_run.dat', $tracks_data['items'][0]['added_at']);
            }
        }
    }

    return $items;
}

function doPublishTracks($items) {

    if (count($items)) {
        $resCurl = curl_init();

        // Try to get BlueSky access token
        curl_setopt_array($resCurl, [
            CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.server.createSession',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POSTFIELDS => json_encode([
                'identifier' => BLUE_HANDLE,
                'password' => BLUE_APP_PASSWORD
            ])
        ]);

        $token_return = @curl_exec($resCurl);

        if (curlCheckError($resCurl)) {
            $token_data = @json_decode($token_return, true);
            if (!is_array($token_data) || !array_key_exists('did', $token_data) || !array_key_exists('accessJwt', $token_data)) {
                die ("BlueSkyauthentication exception: unexpected data.\n{$token_return}\n");
            } else {
                $bs_did = $token_data['did'];
                $bs_access_token = $token_data['accessJwt'];
                foreach ($items as $item) {
                    $artists = [];
                    $artist_formatted = '';
                    $facets = [];

                    foreach ($item['track']['artists'] as $artist) {
                        array_push($artists, [
                            'name' => $artist['name'],
                            'url' => $artist['external_urls']['spotify']
                        ]);
                    }

                    $track = [
                        'id' => $item['track']['id'],
                        'added_at' => $item['added_at'],
                        'name' => $item['track']['name'],
                        'artists' => $artists,
                        'url' => $item['track']['external_urls']['spotify'],
                        'preview_url' => $item['track']['preview_url'],
                        'release_date' => $item['track']['album']['release_date'],
                        'album_name' => $item['track']['album']['album_type'] !== 'single' ? $item['track']['album']['name'] : NULL,
                        'duration' => sprintf("%02s:%02s",
                            (int)(($item['track']['duration_ms'] % 3600000) / 60000),
                            (int)((($item['track']['duration_ms'] % 3600000) % 60000) / 1000)
                        )
                    ];

                    // Building text
                    $text = 'J\'ai un coup de cœur pour ';

                    // Link to track name
                    array_push($facets, [
                        'index' => [
                            'byteStart' => strlen($text),
                            'byteEnd' => strlen($text) + strlen($track['name'])
                        ],
                        'features' => [[
                            '$type' => 'app.bsky.richtext.facet#link',
                            'uri' => $track['url']
                        ]]
                    ]);

                    $text .= $track['name'] . ' par ';
                    foreach ($track['artists'] as $index => $artist) {
                        array_push($facets, [
                            'index' => [
                                'byteStart' => strlen($text),
                                'byteEnd' => strlen($text) + strlen($artist['name'])
                            ],
                            'features' => [[
                                '$type' => 'app.bsky.richtext.facet#link',
                                'uri' => $artist['url']
                            ]]
                        ]);

                        $text .= $artist['name'];
                        $artist_formatted .= $artist['name'];

                        if ($index < count($track['artists']) - 2) {
                            $text .= ', ';
                            $artist_formatted .= ', ';
                        } elseif ($index < count($track['artists']) - 1) {
                            $text .= ' et ';
                            $artist_formatted .= ' et ';
                        } else {
                            $text .= '. ';
                        }
                    }

                    $text .= "\nÀ retrouver en intégralité sur #Spotify.";

                    // Building card

                    if (count($item['track']['album']['images'])) {
                        curl_setopt_array($resCurl, [
                            CURLOPT_URL => $item['track']['album']['images'][0]['url'],
                            CURLOPT_HTTPHEADER => [],
                            CURLOPT_CUSTOMREQUEST => 'GET',
                            CURLOPT_FOLLOWLOCATION => TRUE,
                            CURLOPT_RETURNTRANSFER => TRUE,
                        ]);

                        $image = curl_exec($resCurl);

                        if (curlCheckError($resCurl) && (strlen($image) <= 1000000)) {
                            $track['image'] = [
                                'alt_text' => "{$item['track']['album']['name']} par {$artist_formatted}.",
                                'data' => $image
                            ];
                        }
                    }

                    // Publishing
                    if (array_key_exists('image', $track)) {
                        curl_setopt_array($resCurl, [
                            CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob',
                            CURLOPT_HTTPHEADER => [
                                'Content-Type: image/jpeg',
                                "Authorization: Bearer {$bs_access_token}"
                            ],
                            CURLOPT_CUSTOMREQUEST => 'POST',
                            CURLOPT_FOLLOWLOCATION => TRUE,
                            CURLOPT_RETURNTRANSFER => TRUE,
                            CURLOPT_POSTFIELDS => $track['image']['data']
                        ]);

                        $upload_data = curl_exec($resCurl);

                        if (curlCheckError($resCurl)) {
                            $upload_data = @json_decode($upload_data, true);
                            if (is_array($upload_data) && array_key_exists('blob', $upload_data)) {
                                $track['image']['blob'] = $upload_data['blob'];
                            }
                        } else {
                            echo "Failed to upload album image for {$track['url']}\n";
                        }
                    }

                    $post = [
                        '$type' => 'app.bsky.feed.post',
                        'text' => $text,
                        'langs' => ['FR'],
                        'createdAt' => $track['added_at'],
                        'facets' => $facets
                    ];

                    if (array_key_exists('image', $track) && array_key_exists('blob', $track['image'])) {

                        $description = "{$artist_formatted} • "
                            . (! empty($track['album_name']) ? "{$track['album_name']} • " : '')
                            . "{$track['release_date']} • "
                            . "{$track['duration']}";

                        $post['embed'] = [
                            '$type' => 'app.bsky.embed.external',
                            'external' => [
                                'uri' => $track['url'],
                                'title' => $track['name'],
                                'description' => $description,
                                'thumb' => $track['image']['blob']
                            ]
                        ];
                    }

                    curl_setopt_array($resCurl, [
                        CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.createRecord',
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            "Authorization: Bearer {$bs_access_token}"
                        ],
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_FOLLOWLOCATION => TRUE,
                        CURLOPT_RETURNTRANSFER => TRUE,
                        CURLOPT_POSTFIELDS => json_encode([
                            'repo' => $bs_did,
                            'collection' => 'app.bsky.feed.post',
                            'record' => $post
                        ])
                    ]);

                    @curl_exec($resCurl);

                    if (curlCheckError($resCurl)) {
                        echo("Post successfully created for {$track['url']}.\n");
                    }
                }
            }
        }
    }
}

function saveLastRun($last_run) {
    file_put_contents(__DIR__ . '/rw/last_run.dat', $last_run);
}

/********** MAIN **********/

// Check called for CLI
if (!is_cli()) {
    die();
}

// Try to get Spotify access token
if (FALSE === $sp_token = getSpotifyToken()) {
    // No access token available, proceed with spotify user authentication
    doSpotifyAuth();

    if (FALSE === $sp_token = getSpotifyToken()) {
        // At this point, if access token if not available, then die.
        die ('Unable to access Spotify, please try again.');
    }
}

// Access new tracks from Spotify and publish them to Bluesky
doPublishTracks(getNewSpotifyTracks($sp_token));

