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

				<div class="partner-timeline-setting">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="includePartnerAssets"
						:disabled="savingPartnerSetting"
						@update:model-value="savePartnerSetting">
						{{ t('integration_immich', 'Show partner photos in the main timeline') }}
					</NcCheckboxRadioSwitch>
					<p class="partner-timeline-setting__description">
						{{ t('integration_immich', 'When enabled, Immich partner photos are included according to each partner’s timeline setting.') }}
					</p>
					<NcNoteCard v-if="partnerSettingMessage" :type="partnerSettingMessageType">
						{{ partnerSettingMessage }}
					</NcNoteCard>
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
				<NcNoteCard v-if="missingPermissions.length > 0" type="warning">
					{{ t('integration_immich', 'Connection successful, but the API key is missing required permissions. The following features may not work:') }}
					<ul style="margin: 8px 0 0 16px;">
						<li v-for="perm in missingPermissions" :key="perm">
							<code>{{ perm }}</code>
						</li>
					</ul>
					{{ t('integration_immich', 'Please edit the API key in Immich (Account Settings → API Keys) and enable all required permissions, or create a new key with full permissions.') }}
				</NcNoteCard>
				<NcNoteCard v-if="localAccessBlocked" type="error">
					{{ t('integration_immich', 'Nextcloud is blocking the connection because the Immich server address is a private/local IP. A Nextcloud administrator can allow this by running:') }}
					<br><br>
					<code>php occ config:system:set allow_local_remote_servers --value=true --type=boolean</code>
					<br><br>
					{{ t('integration_immich', 'Alternatively, use a public hostname for your Immich server instead of a local IP address.') }}
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
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { getConfig, setConfig } from './services/api.js'

const serverUrl = ref('')
const apiKey = ref('')
const apiKeySet = ref(false)
const saving = ref(false)
const testing = ref(false)
const message = ref('')
const messageType = ref('success')
const localAccessBlocked = ref(false)
const missingPermissions = ref([])
const includePartnerAssets = ref(true)
const savingPartnerSetting = ref(false)
const partnerSettingMessage = ref('')
const partnerSettingMessageType = ref('success')

onMounted(() => {
	try {
		const state = loadState('integration_immich', 'admin-config')
		serverUrl.value = state.server_url || ''
		apiKeySet.value = state.api_key_set || false
		includePartnerAssets.value = state.include_partner_assets !== false
	} catch (e) {
		loadConfig()
	}
})

async function loadConfig() {
	try {
		const response = await getConfig()
		serverUrl.value = response.data.server_url || ''
		apiKeySet.value = response.data.api_key_set || false
		includePartnerAssets.value = response.data.include_partner_assets !== false
	} catch (e) {
		message.value = t('integration_immich', 'Error loading configuration')
		messageType.value = 'error'
	}
}

async function savePartnerSetting(enabled) {
	savingPartnerSetting.value = true
	partnerSettingMessage.value = ''
	try {
		await setConfig({ include_partner_assets: enabled })
		includePartnerAssets.value = enabled
		partnerSettingMessage.value = t('integration_immich', 'Settings saved')
		partnerSettingMessageType.value = 'success'
	} catch (e) {
		partnerSettingMessage.value = e.response?.data?.error || t('integration_immich', 'Error saving settings')
		partnerSettingMessageType.value = 'error'
	} finally {
		savingPartnerSetting.value = false
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
	localAccessBlocked.value = false
	missingPermissions.value = []
	try {
		const config = { server_url: serverUrl.value, validate: true }
		if (apiKey.value) {
			config.api_key = apiKey.value
		}
		const response = await setConfig(config)
		missingPermissions.value = response.data?.missing_permissions ?? []
		if (missingPermissions.value.length === 0) {
			message.value = t('integration_immich', 'Connection successful!')
			messageType.value = 'success'
		} else {
			// Show success but let the warning card below explain the missing permissions
			message.value = t('integration_immich', 'Connected, but some permissions are missing (see below).')
			messageType.value = 'warning'
		}
	} catch (e) {
		const data = e.response?.data
		localAccessBlocked.value = data?.local_access_blocked === true
		if (localAccessBlocked.value) {
			message.value = t('integration_immich', 'Connection failed: local IP blocked by Nextcloud')
		} else {
			message.value = data?.detail || data?.error || t('integration_immich', 'Connection failed')
		}
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

.partner-timeline-setting {
	margin: 20px 0;
}

.partner-timeline-setting__description {
	margin: 4px 0 0 34px;
	color: var(--color-text-maxcontrast);
}

.actions {
	display: flex;
	gap: 8px;
	margin: 16px 0;
}
</style>
