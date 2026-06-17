/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getBuilder } from '@nextcloud/browser-storage'

/**
 * Persistent storage instance scoped to this app.
 * Keys are automatically prefixed with `integration_immich_`.
 */
export const appStorage = getBuilder('integration_immich').persist().build()
