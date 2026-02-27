<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="immich-admin-settings">
		<NcSettingsSection :name="t('integration_immich', 'Immich Integration')"
			:description="t('integration_immich', 'Connect Nextcloud with your Immich server')">
			<div class="immich-settings-form">
				<div class="field">
					<NcTextField id="immich-server-url"
						v-model="serverUrl"
						:label="t('integration_immich', 'Immich server URL')"
						placeholder="https://immich.example.com" />
				</div>

				<div class="field">
					<NcPasswordField id="immich-api-key"
						v-model="apiKey"
						:label="t('integration_immich', 'API key')"
						:placeholder="apiKeySet ? t('integration_immich', 'API key is set') : t('integration_immich', 'Enter Immich API key')" />
				</div>

				<div class="actions">
					<NcButton type="secondary"
						:disabled="testing || !serverUrl"
						@click="testConnection">
						<template #icon>
							<NcLoadingIcon v-if="testing" :size="20" />
						</template>
						{{ t('integration_immich', 'Test connection') }}
					</NcButton>

					<NcButton type="primary"
						:disabled="saving || !serverUrl"
						@click="saveSettings">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ t('integration_immich', 'Save') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="message" :type="messageType">
					{{ message }}
				</NcNoteCard>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import {
	NcSettingsSection,
	NcTextField,
	NcPasswordField,
	NcButton,
	NcNoteCard,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { getConfig, setConfig } from './services/api.js'

const serverUrl = ref('')
const apiKey = ref('')
const apiKeySet = ref(false)
const saving = ref(false)
const testing = ref(false)
const message = ref('')
const messageType = ref('success')

onMounted(() => {
	try {
		const state = loadState('integration_immich', 'admin-config')
		serverUrl.value = state.server_url || ''
		apiKeySet.value = state.api_key_set || false
	} catch (e) {
		loadConfig()
	}
})

async function loadConfig() {
	try {
		const response = await getConfig()
		serverUrl.value = response.data.server_url || ''
		apiKeySet.value = response.data.api_key_set || false
	} catch (e) {
		message.value = t('integration_immich', 'Error loading configuration')
		messageType.value = 'error'
	}
}

async function saveSettings() {
	saving.value = true
	message.value = ''
	try {
		const config = { server_url: serverUrl.value }
		if (apiKey.value) {
			config.api_key = apiKey.value
		}
		await setConfig(config)
		apiKey.value = ''
		apiKeySet.value = true
		message.value = t('integration_immich', 'Settings saved')
		messageType.value = 'success'
	} catch (e) {
		message.value = e.response?.data?.error || t('integration_immich', 'Error saving settings')
		messageType.value = 'error'
	} finally {
		saving.value = false
	}
}

async function testConnection() {
	testing.value = true
	message.value = ''
	try {
		const config = { server_url: serverUrl.value, validate: true }
		if (apiKey.value) {
			config.api_key = apiKey.value
		}
		await setConfig(config)
		message.value = t('integration_immich', 'Connection successful!')
		messageType.value = 'success'
	} catch (e) {
		message.value = e.response?.data?.error || t('integration_immich', 'Connection failed')
		messageType.value = 'error'
	} finally {
		testing.value = false
	}
}
</script>

<style scoped>
.immich-settings-form {
	max-width: 600px;
}

.field {
	margin-bottom: 16px;
}

.actions {
	display: flex;
	gap: 8px;
	margin: 16px 0;
}
</style>
