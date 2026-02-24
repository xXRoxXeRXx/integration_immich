<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="people-view">
		<NcLoadingIcon v-if="store.loading && store.people.length === 0"
			:size="64"
			class="people-view__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="store.people.length === 0 && !store.loading"
			:name="t('integration_immich', 'Keine Personen')"
			:description="t('integration_immich', 'In deiner Immich Bibliothek wurden noch keine Personen erkannt.')">
			<template #icon>
				<AccountGroupIcon :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else class="people-view__grid">
			<div v-for="person in visiblePeople"
				:key="person.id"
				class="people-view__item"
				@click="openPerson(person.id)">
				<div class="people-view__face">
					<img :src="getPersonThumbnailUrl(person.id)"
						:alt="person.name || '?'"
						loading="lazy"
						class="people-view__face-img">
				</div>
				<span class="people-view__name">{{ person.name || t('integration_immich', 'Unbekannt') }}</span>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getPersonThumbnailUrl } from '../services/api.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'

const store = useImmichStore()
const router = useRouter()

// Hide people with isHidden flag
const visiblePeople = computed(() => store.people.filter(p => !p.isHidden))

function openPerson(id) {
	router.push({ name: 'person-detail', params: { id } })
}

onMounted(() => {
	store.fetchPeople()
})
</script>

<style scoped>
.people-view {
	padding: 16px 16px 16px 52px;
}

.people-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.people-view__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 16px;
}

.people-view__item {
	cursor: pointer;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border-radius: 8px;
	transition: background 0.15s;
}

.people-view__item:hover {
	background: var(--color-background-hover);
}

.people-view__face {
	width: 96px;
	height: 96px;
	border-radius: 50%;
	overflow: hidden;
	background: var(--color-background-dark);
}

.people-view__face-img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.people-view__name {
	font-size: 13px;
	text-align: center;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 120px;
	color: var(--color-main-text);
}
</style>
