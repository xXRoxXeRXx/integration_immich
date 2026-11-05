<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="locked-folder">
		<NcLoadingIcon v-if="state === 'loading'" :size="64" class="locked-folder__loading" />

		<NcEmptyContent v-else-if="state === 'error'"
			:name="t('integration_immich', 'Error')"
			:description="error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else-if="state === 'unlocked'">
			<div class="locked-folder__bar">
				<span class="locked-folder__bar-text">
					{{ t('integration_immich', 'The Locked Folder is open. It locks itself after a while without use.') }}
				</span>
				<NcButton variant="secondary" :disabled="busy" @click="lock">
					<template #icon>
						<LockIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Lock') }}
				</NcButton>
			</div>
			<TimelineView visibility="locked" />
		</template>

		<NcEmptyContent v-else
			:name="t('integration_immich', 'Locked Folder')"
			:description="description">
			<template #icon>
				<LockIcon :size="64" />
			</template>
			<template #action>
				<div class="locked-folder__actions">
					<NcButton v-if="state !== 'no-pin'" variant="primary" @click="openDialog">
						<template #icon>
							<LockOpenIcon :size="20" />
						</template>
						{{ t('integration_immich', 'Unlock') }}
					</NcButton>
					<NcButton v-else variant="primary" @click="refresh">
						{{ t('integration_immich', 'Check again') }}
					</NcButton>
					<NcButton v-if="status.signedIn" variant="tertiary" :disabled="busy" @click="signOut">
						{{ t('integration_immich', 'Sign out of Immich') }}
					</NcButton>
				</div>
			</template>
		</NcEmptyContent>

		<NcDialog v-if="dialog === 'sign-in'"
			:name="t('integration_immich', 'Sign in to Immich')"
			size="small"
			@closing="closeDialog">
			<div class="locked-folder__dialog">
				<p>{{ t('integration_immich', 'Immich opens the Locked Folder only to a sign-in with your PIN, not to the API key. Sign in once; afterwards your PIN is enough until you sign out of Nextcloud.') }}</p>
				<form v-if="status.passwordLogin" class="locked-folder__form" @submit.prevent="signIn">
					<NcTextField v-model="email"
						type="email"
						autocomplete="username"
						:label="t('integration_immich', 'E-mail')" />
					<NcPasswordField v-model="password"
						autocomplete="current-password"
						:label="t('integration_immich', 'Password')" />
					<NcButton type="submit" variant="primary" :disabled="busy || !email || !password">
						{{ t('integration_immich', 'Sign in') }}
					</NcButton>
				</form>
				<template v-if="status.oauth">
					<NcButton :variant="status.passwordLogin ? 'secondary' : 'primary'" :disabled="busy" @click="signInWithOAuth">
						{{ t('integration_immich', 'Sign in with single sign-on') }}
					</NcButton>
					<p v-if="waitingForOAuth">
						{{ t('integration_immich', 'Finish the sign-in in the window that opened.') }}
					</p>
					<p class="locked-folder__hint">
						{{ t('integration_immich', 'Single sign-on needs this address among the redirect URIs your identity provider allows for Immich: {url}', { url: status.callbackUrl }) }}
					</p>
				</template>
				<NcNoteCard v-if="!status.passwordLogin && !status.oauth" type="warning">
					{{ t('integration_immich', 'Your Immich server offers no sign-in by password or single sign-on.') }}
				</NcNoteCard>
				<NcNoteCard v-if="message" type="error">
					{{ message }}
				</NcNoteCard>
			</div>
		</NcDialog>

		<NcDialog v-if="dialog === 'pin'"
			:name="t('integration_immich', 'Enter your PIN')"
			size="small"
			@closing="closeDialog">
			<form class="locked-folder__form" @submit.prevent="unlock">
				<NcPasswordField v-model="pin"
					inputmode="numeric"
					autocomplete="off"
					maxlength="6"
					:label="t('integration_immich', 'Immich PIN')" />
				<NcNoteCard v-if="message" type="error">
					{{ message }}
				</NcNoteCard>
				<NcButton type="submit" variant="primary" :disabled="busy || !/^\d{4,6}$/.test(pin)">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
						<LockOpenIcon v-else :size="20" />
					</template>
					{{ t('integration_immich', 'Unlock') }}
				</NcButton>
			</form>
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getLockedStatus, lockedLogin, lockedOAuthStart, lockedUnlock, lockedLock, lockedSignOut } from '../services/api.js'
import { expiryDelay } from '../services/lockedExpiry.js'
import TimelineView from './TimelineView.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import LockIcon from 'vue-material-design-icons/LockOutline.vue'
import LockOpenIcon from 'vue-material-design-icons/LockOpenVariantOutline.vue'

// How often the view asks whether the OAuth window signed in, and for how long.
const OAUTH_POLL_MS = 1500
const OAUTH_POLL_LIMIT_MS = 10 * 60 * 1000

const store = useImmichStore()
const status = ref({})
const loaded = ref(false)
const error = ref('')
const dialog = ref(null)
const message = ref('')
const busy = ref(false)
const email = ref('')
const password = ref('')
const pin = ref('')
const waitingForOAuth = ref(false)
let oauthTimer = null
let expiryTimer = null

// loading | error | signed-out | no-pin | locked | unlocked
const state = computed(() => {
	if (!loaded.value) return 'loading'
	if (error.value) return 'error'
	if (!status.value.signedIn) return 'signed-out'
	if (!status.value.pinCode) return 'no-pin'
	return status.value.unlocked ? 'unlocked' : 'locked'
})

const description = computed(() => ({
	'signed-out': t('integration_immich', 'Photos and videos in the Locked Folder are hidden everywhere else. Sign in to Immich and enter your PIN to see them.'),
	'no-pin': t('integration_immich', 'You have no PIN yet. Set one up in Immich (Account settings, PIN code), then check again.'),
	locked: t('integration_immich', 'Enter your Immich PIN to see the photos and videos in the Locked Folder.'),
}[state.value] ?? ''))

function errorText(e) {
	return e.response?.data?.error || e.message
}

async function refresh() {
	try {
		const response = await getLockedStatus()
		status.value = response.data ?? {}
		error.value = ''
	} catch (e) {
		error.value = errorText(e)
	} finally {
		loaded.value = true
	}
	// Nothing loaded from the Locked Folder stays in memory once it is no longer open.
	if (state.value !== 'unlocked') store.forgetLockedFolder()
	scheduleExpiryCheck()
}

function stopExpiryCheck() {
	if (expiryTimer) clearTimeout(expiryTimer)
	expiryTimer = null
}

// Immich locks the session when the PIN's time is up, but extends that time while the session
// is used. So the view asks once, just after the expiry it was told, instead of polling: still
// open (with a later expiry), or locked, which hides the photos and asks for the PIN again.
function scheduleExpiryCheck() {
	stopExpiryCheck()
	if (state.value !== 'unlocked') return
	const delay = expiryDelay(status.value.expiresAt)
	if (delay === null) return
	expiryTimer = setTimeout(async () => {
		expiryTimer = null
		await refresh()
		if (state.value !== 'unlocked') openDialog()
	}, delay)
}

function openDialog() {
	message.value = ''
	pin.value = ''
	if (state.value === 'signed-out') dialog.value = 'sign-in'
	else if (state.value === 'locked') dialog.value = 'pin'
}

function closeDialog() {
	dialog.value = null
	stopOAuthPolling()
}

async function afterSignIn() {
	password.value = ''
	await refresh()
	if (state.value === 'locked') {
		message.value = ''
		dialog.value = 'pin'
	} else {
		dialog.value = null
	}
}

async function signIn() {
	busy.value = true
	message.value = ''
	try {
		await lockedLogin(email.value, password.value)
		await afterSignIn()
	} catch (e) {
		message.value = errorText(e)
	} finally {
		busy.value = false
	}
}

function stopOAuthPolling() {
	if (oauthTimer) clearInterval(oauthTimer)
	oauthTimer = null
	waitingForOAuth.value = false
}

async function signInWithOAuth() {
	message.value = ''
	// Opened in the click itself, else the browser blocks it; the address follows.
	const popup = window.open('', 'integration_immich_sign_in', 'popup,width=520,height=720')
	busy.value = true
	let url
	try {
		url = (await lockedOAuthStart()).data.url
	} catch (e) {
		popup?.close()
		message.value = errorText(e)
		return
	} finally {
		busy.value = false
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
			const response = await getLockedStatus()
			if (response.data?.signedIn) {
				stopOAuthPolling()
				popup?.close()
				await afterSignIn()
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

async function unlock() {
	busy.value = true
	message.value = ''
	try {
		await lockedUnlock(pin.value)
		pin.value = ''
		dialog.value = null
		store.lockedFolderNeedsUnlock = false
		await refresh()
	} catch (e) {
		pin.value = ''
		if (e.response?.status === 423) {
			await refresh()
			openDialog()
		}
		message.value = errorText(e)
	} finally {
		busy.value = false
	}
}

async function lock() {
	busy.value = true
	try {
		await lockedLock()
	} catch (e) {
		message.value = errorText(e)
	} finally {
		store.forgetLockedFolder()
		await refresh()
		busy.value = false
	}
}

async function signOut() {
	busy.value = true
	try {
		await lockedSignOut()
	} finally {
		store.forgetLockedFolder()
		await refresh()
		busy.value = false
	}
}

// A request answered that the folder is locked again (the PIN lapsed, or a sign-out in Immich).
watch(() => store.lockedFolderNeedsUnlock, async (needed) => {
	if (!needed) return
	store.lockedFolderNeedsUnlock = false
	await refresh()
	openDialog()
})

onMounted(async () => {
	store.lockedFolderNeedsUnlock = false
	await refresh()
	openDialog()
})

onBeforeUnmount(() => {
	stopOAuthPolling()
	stopExpiryCheck()
})
</script>

<style scoped>
.locked-folder {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.locked-folder > :deep(.timeline-view) {
	flex: 1;
	min-height: 0;
}

.locked-folder__loading {
	margin-top: 20vh;
}

.locked-folder__bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
}

.locked-folder__bar-text {
	color: var(--color-text-maxcontrast);
}

.locked-folder__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: center;
}

.locked-folder__dialog,
.locked-folder__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 12px;
}

.locked-folder__hint {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}
</style>
