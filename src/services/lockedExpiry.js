/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Asked a moment after the reported expiry, so that Immich has locked the session by then.
const MARGIN_MS = 2000
// The longest delay setTimeout takes; a later expiry is asked about then and scheduled again.
const MAX_DELAY_MS = 2 ** 31 - 1

/**
 * How long to wait before asking whether the unlocked Locked Folder is still open: until just
 * after the PIN's expiry that Immich reported (expiresAt, an ISO date), or null when it reported
 * none. Immich extends the PIN while the session is used, so the answer then may be a later one.
 */
export function expiryDelay(expiresAt, now = Date.now()) {
	const at = Date.parse(expiresAt ?? '')
	if (Number.isNaN(at)) return null
	return Math.min(Math.max(at - now, 0) + MARGIN_MS, MAX_DELAY_MS)
}
