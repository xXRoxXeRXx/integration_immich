<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

return [
    'routes' => [
        // Main page (Vue SPA)
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Admin Config
        ['name' => 'config#setConfig', 'url' => '/api/v1/config', 'verb' => 'PUT'],
        ['name' => 'config#getConfig', 'url' => '/api/v1/config', 'verb' => 'GET'],
        ['name' => 'config#me',        'url' => '/api/v1/me',     'verb' => 'GET'],

        // Sign-in with the Immich account instead of an API key
        ['name' => 'account#features', 'url' => '/api/v1/account/features', 'verb' => 'GET'],
        ['name' => 'account#login',    'url' => '/api/v1/account/login',    'verb' => 'POST'],
        ['name' => 'account#oauthStart', 'url' => '/api/v1/account/oauth',  'verb' => 'POST'],
        ['name' => 'account#signOut',  'url' => '/api/v1/account/signout',  'verb' => 'POST'],

        // Assets / Timeline
        ['name' => 'assets#timeline',        'url' => '/api/v1/timeline',                    'verb' => 'GET'],
        ['name' => 'assets#partners',       'url' => '/api/v1/partners',                     'verb' => 'GET'],
        ['name' => 'assets#downloadAssets',  'url' => '/api/v1/download',                    'verb' => 'POST'],
        ['name' => 'assets#saveToNextcloud', 'url' => '/api/v1/assets/save',                 'verb' => 'POST'],
        ['name' => 'assets#deleteAssets',    'url' => '/api/v1/assets/delete',               'verb' => 'POST'],
        ['name' => 'assets#visibility',      'url' => '/api/v1/assets/visibility',           'verb' => 'POST'],
        ['name' => 'assets#update',          'url' => '/api/v1/assets/{id}/update',          'verb' => 'POST'],
        ['name' => 'assets#show',            'url' => '/api/v1/assets/{id}/info',            'verb' => 'GET'],
        ['name' => 'assets#thumbnail',       'url' => '/api/v1/assets/{id}/thumbnail',       'verb' => 'GET'],
        ['name' => 'assets#original',        'url' => '/api/v1/assets/{id}/original',        'verb' => 'GET'],
        ['name' => 'assets#videoStream',     'url' => '/api/v1/assets/{id}/video',           'verb' => 'GET'],
        ['name' => 'assets#mapMarkers',      'url' => '/api/v1/map/markers',                 'verb' => 'GET'],
        ['name' => 'assets#searchLocation',  'url' => '/api/v1/search/location',             'verb' => 'GET'],
        ['name' => 'assets#explore',         'url' => '/api/v1/explore',                     'verb' => 'GET'],

        // Albums
        ['name' => 'albums#index',        'url' => '/api/v1/albums',                         'verb' => 'GET'],
        ['name' => 'albums#create',       'url' => '/api/v1/albums/create',                  'verb' => 'POST'],
        ['name' => 'albums#show',         'url' => '/api/v1/albums/{id}/show',               'verb' => 'GET'],
        ['name' => 'albums#delete',       'url' => '/api/v1/albums/{id}/delete',             'verb' => 'POST'],
        ['name' => 'albums#addAssets',    'url' => '/api/v1/albums/{id}/assets/add',         'verb' => 'POST'],
        ['name' => 'albums#removeAssets', 'url' => '/api/v1/albums/{id}/assets/remove',      'verb' => 'POST'],
        ['name' => 'albums#rename',       'url' => '/api/v1/albums/{id}/rename',             'verb' => 'POST'],
        ['name' => 'albums#thumbnail',    'url' => '/api/v1/albums/{id}/thumbnail',          'verb' => 'GET'],

        // People / Personen
        ['name' => 'people#index', 'url' => '/api/v1/people', 'verb' => 'GET'],
        ['name' => 'people#assets', 'url' => '/api/v1/people/{id}/assets', 'verb' => 'GET'],
        ['name' => 'people#thumbnail', 'url' => '/api/v1/people/{id}/thumbnail', 'verb' => 'GET'],

        // Folders
        ['name' => 'folders#content', 'url' => '/api/v1/folders/content', 'verb' => 'GET'],

        // Locked Folder: sign in to Immich, unlock with the PIN, lock again
        ['name' => 'locked#status',        'url' => '/api/v1/locked/status',         'verb' => 'GET'],
        ['name' => 'locked#login',         'url' => '/api/v1/locked/login',          'verb' => 'POST'],
        ['name' => 'locked#oauthStart',    'url' => '/api/v1/locked/oauth',          'verb' => 'POST'],
        ['name' => 'locked#oauthCallback', 'url' => '/api/v1/locked/oauth/callback', 'verb' => 'GET'],
        ['name' => 'locked#unlock',        'url' => '/api/v1/locked/unlock',         'verb' => 'POST'],
        ['name' => 'locked#lock',          'url' => '/api/v1/locked/lock',           'verb' => 'POST'],
        ['name' => 'locked#signOut',       'url' => '/api/v1/locked/signout',        'verb' => 'POST'],

        // Upload NC → Immich
        ['name' => 'upload#upload', 'url' => '/api/v1/upload', 'verb' => 'POST'],
    ],
];
