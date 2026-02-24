/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

const app = createApp(AdminSettings)
app.mount('#immich-admin-settings')
