<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="place-detail">
		<NcLoadingIcon v-if="loadingMarkers"
			:size="64"
			class="place-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Sticky header -->
			<div class="place-detail__header">
				<NcButton variant="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Zurück') }}
				</NcButton>
				<div class="place-detail__title">
					<h2>{{ props.value }}</h2>
					<span class="place-detail__count">
						{{ t('integration_immich', '{count} Fotos', { count: filteredAssets.length }) }}
					</span>
				</div>
			</div>

			<NcEmptyContent v-if="filteredAssets.length === 0"
				:name="t('integration_immich', 'Keine Bilder')"
				:description="t('integration_immich', 'Keine Fotos für diesen Ort gefunden.')">
				<template #icon>
					<MapMarkerIcon :size="64" />
				</template>
			</NcEmptyContent>

			<div v-else class="place-detail__scroll">
				<PhotoGrid
					:assets="filteredAssets"
					@click="(_, idx) => store.openLightbox(filteredAssets, idx)" />
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'

const props = defineProps({
	field: { type: String, required: true },   // e.g. "exifInfo.city"
	value: { type: String, required: true },   // e.g. "Berlin"
})

const store = useImmichStore()
const router = useRouter()

function fieldToKey(field) {
	const map = {
		'exifInfo.city': 'city',
		'exifInfo.country': 'country',
		'exifInfo.state': 'state',
	}
	return map[field] ?? field.split('.').pop()
}

const filteredAssets = computed(() => {
	const key = fieldToKey(props.field)
	return store.mapMarkers.filter(m => m[key] === props.value)
})

const loadingMarkers = ref(false)
onMounted(async () => {
	if (store.mapMarkers.length === 0) {
		loadingMarkers.value = true
		await store.fetchMapMarkers()
		loadingMarkers.value = false
	}
})

function goBack() {
	router.push({ name: 'explore' })
}
</script>

<style scoped>
.place-detail {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.place-detail__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.place-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 8px 16px 8px 52px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.place-detail__title {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.place-detail__title h2 {
	margin: 0;
	font-size: 20px;
}

.place-detail__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.place-detail__scroll {
	flex: 1;
	overflow-y: auto;
}
</style>
