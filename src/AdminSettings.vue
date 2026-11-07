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

				<!-- Signed in, the account is used: signing out shows the choice again. -->
				<fieldset v-if="!accountEmail" class="field auth-method">
					<legend>{{ t('integration_immich', 'Sign in with') }}</legend>
					<NcCheckboxRadioSwitch v-model="authMethod"
						type="radio"
						name="immich-auth-method"
						value="account">
						{{ t('integration_immich', 'Immich account') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="authMethod"
						type="radio"
						name="immich-auth-method"
						value="api_key">
						{{ t('integration_immich', 'API key') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<div v-if="accountEmail" class="field account">
					<NcNoteCard type="success">
						{{ t('integration_immich', 'Signed in to Immich as {email}. Your account is used instead of an API key, and the Locked Folder asks only for your PIN.', { email: accountEmail }) }}
					</NcNoteCard>
					<NcButton type="secondary" :disabled="accountBusy" @click="signOutAccount">
						{{ t('integration_immich', 'Sign out of Immich') }}
					</NcButton>
				</div>

				<div v-else-if="authMethod === 'account'" class="field account">
					<p class="account__description">
						{{ t('integration_immich', 'Sign in once with your Immich account. Nextcloud keeps the sign-in (encrypted), never your password; Immich lists it among your devices.') }}
					</p>
					<form v-if="accountFeatures.passwordLogin !== false" class="account__form" @submit.prevent="signInAccount">
						<NcTextField v-model="accountLoginEmail"
							type="email"
							autocomplete="username"
							:label="t('integration_immich', 'E-mail')" />
						<NcPasswordField v-model="accountPassword"
							autocomplete="current-password"
							:label="t('integration_immich', 'Password')" />
						<NcButton type="submit"
							variant="primary"
							:disabled="accountBusy || !serverUrl || !accountLoginEmail || !accountPassword">
							<template #icon>
								<NcLoadingIcon v-if="accountBusy" :size="20" />
							</template>
							{{ t('integration_immich', 'Sign in') }}
						</NcButton>
					</form>
					<template v-if="accountFeatures.oauth">
						<NcButton :variant="accountFeatures.passwordLogin ? 'secondary' : 'primary'"
							:disabled="accountBusy || !serverUrl"
							@click="signInAccountWithOAuth">
							{{ t('integration_immich', 'Sign in with single sign-on') }}
						</NcButton>
						<p v-if="waitingForOAuth">
							{{ t('integration_immich', 'Finish the sign-in in the window that opened.') }}
						</p>
						<p class="account__hint">
							{{ t('integration_immich', 'Single sign-on needs this address among the redirect URIs your identity provider allows for Immich: {url}', { url: accountFeatures.callbackUrl }) }}
						</p>
					</template>
					<NcNoteCard v-if="accountFeatures.passwordLogin === false && !accountFeatures.oauth" type="warning">
						{{ t('integration_immich', 'Your Immich server offers no sign-in by password or single sign-on.') }}
					</NcNoteCard>
				</div>

				<div v-if="authMethod === 'api_key' && !accountEmail" class="field">
					<NcPasswordField id="immich-api-key"
						v-model="apiKey"
						:label="t('integration_immich', 'API key')"
						:placeholder="apiKeySet ? t('integration_immich', 'API key is set') : t('integration_immich', 'Enter Immich API key')" />
				</div>

				<NcNoteCard v-if="accountMessage" type="error">
					{{ accountMessage }}
				</NcNoteCard>

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
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
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
import { getConfig, setConfig, getAccountFeatures, accountLogin, accountOAuthStart, accountSignOut } from './services/api.js'

// How often the settings ask whether the OAuth window signed in, and for how long.
const OAUTH_POLL_MS = 1500
const OAUTH_POLL_LIMIT_MS = 10 * 60 * 1000

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
// account | api_key: which sign-in the form shows; the account's, when there is one, is used.
const authMethod = ref('api_key')
const accountEmail = ref('')
const accountFeatures = ref({})
const accountLoginEmail = ref('')
const accountPassword = ref('')
const accountBusy = ref(false)
const accountMessage = ref('')
const waitingForOAuth = ref(false)
let oauthTimer = null
let featuresTimer = null

function applyConfig(config) {
	serverUrl.value = config.server_url || ''
	apiKeySet.value = config.api_key_set || false
	accountEmail.value = config.uses_account ? (config.account_email || '') : ''
	includePartnerAssets.value = config.include_partner_assets !== false
	if (config.uses_account) authMethod.value = 'account'
}

onMounted(() => {
	try {
		applyConfig(loadState('integration_immich', 'admin-config'))
	} catch (e) {
		loadConfig()
	}
})

async function loadConfig() {
	try {
		applyConfig((await getConfig()).data)
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
		if (apiKey.value) apiKeySet.value = true
		apiKey.value = ''
		// Another server ends the account's sign-in, which belongs to the one it was made on.
		await loadConfig()
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

// Which sign-ins the server in the form offers, asked again shortly after the address changes.
async function loadAccountFeatures() {
	accountFeatures.value = {}
	if (authMethod.value !== 'account' || accountEmail.value || !serverUrl.value) return
	try {
		accountFeatures.value = (await getAccountFeatures(serverUrl.value)).data ?? {}
	} catch {
		// Unknown: the password form shows, and its sign-in tells what is wrong.
	}
}

watch([serverUrl, authMethod, accountEmail], () => {
	if (featuresTimer) clearTimeout(featuresTimer)
	featuresTimer = setTimeout(loadAccountFeatures, 500)
})

function errorText(e) {
	return e.response?.data?.error || e.message
}

async function signInAccount() {
	accountBusy.value = true
	accountMessage.value = ''
	try {
		await accountLogin(serverUrl.value, accountLoginEmail.value, accountPassword.value)
		accountPassword.value = ''
		await loadConfig()
	} catch (e) {
		accountMessage.value = errorText(e)
	} finally {
		accountBusy.value = false
	}
}

function stopOAuthPolling() {
	if (oauthTimer) clearInterval(oauthTimer)
	oauthTimer = null
	waitingForOAuth.value = false
}

async function signInAccountWithOAuth() {
	accountMessage.value = ''
	// Opened in the click itself, else the browser blocks it; the address follows.
	const popup = window.open('', 'integration_immich_sign_in', 'popup,width=520,height=720')
	accountBusy.value = true
	let url
	try {
		url = (await accountOAuthStart(serverUrl.value)).data.url
	} catch (e) {
		popup?.close()
		accountMessage.value = errorText(e)
		return
	} finally {
		accountBusy.value = false
	}
	if (popup) {
		popup.location.href = url
	} else {
		window.open(url, '_blank', 'noopener')
	}

	stopOAuthPolling()
	waitingForOAuth.value = true
	const started = Date.now()
	oauthTimer = setInterval(async () => {
		const closed = !popup || popup.closed
		try {
			const config = (await getConfig()).data
			if (config.uses_account) {
				stopOAuthPolling()
				popup?.close()
				applyConfig(config)
				return
			}
		} catch {
			// Asked again at the next tick.
		}
		if ((closed && popup) || Date.now() - started > OAUTH_POLL_LIMIT_MS) {
			stopOAuthPolling()
		}
	}, OAUTH_POLL_MS)
}

async function signOutAccount() {
	accountBusy.value = true
	accountMessage.value = ''
	try {
		await accountSignOut()
		accountEmail.value = ''
	} catch (e) {
		accountMessage.value = errorText(e)
	} finally {
		accountBusy.value = false
	}
}

onBeforeUnmount(() => {
	stopOAuthPolling()
	if (featuresTimer) clearTimeout(featuresTimer)
})
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

.auth-method {
	border: none;
	margin: 0 0 16px;
	padding: 0;
}

.auth-method legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.account,
.account__form {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.account__form {
	align-self: stretch;
}

.account__description,
.account__hint {
	color: var(--color-text-maxcontrast);
}

.account__hint {
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}

.actions {
	display: flex;
	gap: 8px;
	margin: 16px 0;
}
</style>
