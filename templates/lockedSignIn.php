<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


declare(strict_types=1);

/** @var array $_ */
/** @var \OCP\IL10N $l */

// The window the Locked Folder opened for the OAuth sign-in; Nextcloud's tab closes it.
$messages = [
    'signed-in' => $l->t('Signed in to Immich. You can close this window and enter your PIN in Nextcloud.'),
    'other-account' => $l->t('This Immich account is not the one of your API key. Close this window and sign in with your own account.'),
    'failed' => $l->t('The sign-in to Immich failed. Close this window and try again.'),
];
?>
<div class="guest-box">
	<h2><?php p($l->t('Immich Locked Folder')); ?></h2>
	<p><?php p($messages[$_['outcome']] ?? $messages['failed']); ?></p>
</div>
