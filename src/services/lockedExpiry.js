/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Asked a moment after the expiry, so that Immich has locked the session by then: a check that
// came early would itself extend the PIN (Immich extends it on any use in its last minutes).
const MARGIN_MS = 5000
// The longest delay setTimeout takes; a later expiry is asked about then and scheduled again.
const MAX_DELAY_MS = 2 ** 31 - 1

/**
 * How long to wait before asking whether the unlocked Locked Folder is still open: until just
 * after the PIN's expiry, given as the seconds left by the server's clock (expiresIn; the
 * browser's own clock may be off), or null when there is none. Immich extends the PIN while the
 * session is used, so the answer then may be a later expiry.
 */
export function expiryDelay(expiresIn) {
	if (typeof expiresIn !== 'number' || !Number.isFinite(expiresIn)) return null
	return Math.min(Math.max(expiresIn, 0) * 1000 + MARGIN_MS, MAX_DELAY_MS)
}
