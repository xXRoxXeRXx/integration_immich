<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="place-detail">
		<NcLoadingIcon v-if="store.loading && store.placeAssets.length === 0"
			:size="64"
			class="place-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Sticky header -->
			<div class="place-detail__header">
				<NcBreadcrumbs>
					<NcBreadcrumb :name="t('integration_immich', 'Explore')" @click="goBack" />
					<NcBreadcrumb :name="props.value" :title="props.value" />
				</NcBreadcrumbs>
				<div class="place-detail__meta-row">
					<span class="place-detail__count">
						{{ t('integration_immich', '{count} photos', { count: store.placeAssets.length }) }}
					</span>
					<div class="place-detail__layout-toggle">
						<button
							class="place-detail__layout-btn"
							:class="{ 'place-detail__layout-btn--active': store.gridLayout === 'grid' }"
							:title="t('integration_immich', 'Square grid')"
							@click="store.setGridLayout('grid')">
							<ViewGridIcon :size="16" />
						</button>
						<button
							class="place-detail__layout-btn"
							:class="{ 'place-detail__layout-btn--active': store.gridLayout === 'masonry' }"
							:title="t('integration_immich', 'Masonry grid')"
							@click="store.setGridLayout('masonry')">
							<ViewQuiltIcon :size="16" />
						</button>
					</div>
				</div>
			</div>

			<NcEmptyContent v-if="store.placeAssets.length === 0 && !store.loading"
				:name="t('integration_immich', 'No photos')"
				:description="t('integration_immich', 'No photos found for this location.')">
				<template #icon>
					<MapMarkerIcon :size="64" />
				</template>
			</NcEmptyContent>

			<div v-else class="place-detail__scroll">
				<PhotoGrid
					:assets="store.placeAssets"
					:selectable="true"
					:layout="store.gridLayout"
				@click="(_, idx) => store.openLightbox(store.placeAssets, idx)" />
			</div>
		</template>
	</div>
</template>

<script setup>
import { onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon, NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewQuiltIcon from 'vue-material-design-icons/ViewQuilt.vue'

const props = defineProps({
	field: { type: String, required: true },   // e.g. "exifInfo.city"
	value: { type: String, required: true },   // e.g. "Berlin"
})

const store = useImmichStore()
const router = useRouter()

function load() {
	store.fetchPlaceAssets(props.field, props.value)
}

onMounted(() => {
	load()
})

watch(() => [props.field, props.value], load)

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
	flex-direction: column;
	padding: 8px 16px 6px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
}

.place-detail__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding-left: 4px;
	margin-top: 2px;
}

.place-detail__meta-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
}

.place-detail__layout-toggle {
	margin-left: auto;
	display: flex;
	gap: 2px;
}

.place-detail__layout-btn {
	all: unset;
	box-sizing: border-box;
	width: 28px;
	height: 28px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: color 0.15s ease, background 0.15s ease;
}

.place-detail__layout-btn:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.place-detail__layout-btn--active {
	color: var(--color-primary);
	background: var(--color-primary-element-light);
}

.place-detail__scroll {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	box-sizing: border-box;
}

@media (max-width: 480px) {
	.place-detail__header {
		padding: 8px 8px 6px;
	}

	.place-detail__scroll {
		padding: 8px;
	}
}
</style>
