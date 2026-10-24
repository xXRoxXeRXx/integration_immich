<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="partners-view">
		<NcLoadingIcon v-if="store.loading && store.partners.length === 0"
			:size="64"
			class="partners-view__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon><AlertIcon :size="64" /></template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="store.partners.length === 0"
			:name="t('integration_immich', 'No partners')"
			:description="t('integration_immich', 'No one has shared an Immich library with you yet.')">
			<template #icon><AccountMultipleIcon :size="64" /></template>
		</NcEmptyContent>

		<div v-else class="partners-view__grid">
			<button v-for="partner in store.partners"
				:key="partner.id"
				class="partners-view__card"
				@click="openPartner(partner.id)">
				<span class="partners-view__avatar"><AccountIcon :size="32" /></span>
				<span class="partners-view__identity">
					<strong>{{ partner.name || partner.email || t('integration_immich', 'Unknown') }}</strong>
					<span v-if="partner.name && partner.email">{{ partner.email }}</span>
				</span>
			</button>
		</div>
	</div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'

const store = useImmichStore()
const router = useRouter()

function openPartner(id) {
	router.push({ name: 'partner-detail', params: { id } })
}

onMounted(() => store.fetchPartners())
</script>

<style scoped>
.partners-view {
	height: 100%;
	overflow-y: auto;
	padding: 24px 16px 16px;
	box-sizing: border-box;
}

.partners-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.partners-view__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 12px;
}

.partners-view__card {
	display: flex;
	align-items: center;
	gap: 14px;
	min-height: 84px;
	padding: 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: left;
	cursor: pointer;
}

.partners-view__card:hover {
	background: var(--color-background-hover);
}

.partners-view__avatar {
	display: grid;
	place-items: center;
	width: 52px;
	height: 52px;
	flex: 0 0 52px;
	border-radius: 50%;
	background: var(--color-background-dark);
}

.partners-view__identity {
	display: flex;
	flex-direction: column;
	min-width: 0;
	gap: 4px;
}

.partners-view__identity strong,
.partners-view__identity span {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.partners-view__identity span {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}
</style>