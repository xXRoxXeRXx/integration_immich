<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="explore-view">
		<NcLoadingIcon v-if="store.loading && store.exploreData.length === 0"
			:size="64"
			class="explore-view__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="store.exploreData.length === 0 && !store.loading"
			:name="t('integration_immich', 'Nichts zu erkunden')"
			:description="t('integration_immich', 'Immich hat noch keine Orte oder Themen erkannt.')">
			<template #icon>
				<CompassIcon :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else class="explore-view__sections">
			<section v-for="section in sortedExploreData"
				:key="section.fieldName"
				class="explore-view__section">
				<h2 class="explore-view__section-title">{{ sectionLabel(section.fieldName) }}</h2>
				<div class="explore-view__grid">
					<div v-for="item in section.items"
						:key="item.value"
						class="explore-view__item"
						@click="openPlace(section, item)">
						<div class="explore-view__cover">
							<img :src="getThumbnailUrl(item.data.id)"
								:alt="item.value"
								loading="lazy"
								class="explore-view__cover-img">
						</div>
						<span class="explore-view__label">{{ item.value }}</span>
					</div>
				</div>
			</section>
		</div>
	</div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getThumbnailUrl } from '../services/api.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import CompassIcon from 'vue-material-design-icons/Compass.vue'

const store = useImmichStore()
const router = useRouter()

const sectionOrder = ['exifInfo.country', 'exifInfo.state', 'exifInfo.city']

const sortedExploreData = computed(() =>
	[...store.exploreData].sort((a, b) => {
		const ai = sectionOrder.indexOf(a.fieldName)
		const bi = sectionOrder.indexOf(b.fieldName)
		return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi)
	}),
)

function sectionLabel(fieldName) {
	const labels = {
		'exifInfo.city': t('integration_immich', 'Städte'),
		'exifInfo.country': t('integration_immich', 'Länder'),
		'exifInfo.state': t('integration_immich', 'Bundesländer'),
		'smartInfo.objects': t('integration_immich', 'Objekte'),
		'smartInfo.tags': t('integration_immich', 'Tags'),
	}
	return labels[fieldName] ?? fieldName
}

function openPlace(section, item) {
	router.push({
		name: 'place-detail',
		params: { field: section.fieldName, value: item.value },
	})
}

onMounted(() => {
	store.fetchExplore()
})
</script>

<style scoped>
.explore-view {
	padding: 16px 16px 16px 52px;
	overflow-y: auto;
	height: 100%;
}

.explore-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.explore-view__sections {
	display: flex;
	flex-direction: column;
	gap: 32px;
}

.explore-view__section-title {
	font-size: 20px;
	font-weight: bold;
	margin: 0 0 16px;
	color: var(--color-main-text);
}

.explore-view__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 12px;
}

.explore-view__item {
	cursor: pointer;
	border-radius: 8px;
	overflow: hidden;
	background: var(--color-background-dark);
	transition: box-shadow 0.2s;
	position: relative;
}

.explore-view__item:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.explore-view__cover {
	aspect-ratio: 1;
	overflow: hidden;
}

.explore-view__cover-img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.explore-view__label {
	display: block;
	padding: 8px 10px;
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	color: var(--color-main-text);
}
</style>
