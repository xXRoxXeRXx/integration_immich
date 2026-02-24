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

			<!-- Virtual-scroll flat grid -->
			<div v-else
				ref="scrollContainer"
				class="place-detail__scroll"
				@scroll="onScroll">
				<div class="place-detail__runway" :style="{ height: totalHeight + 'px' }">
					<div v-for="rowIndex in visibleRowRange"
						:key="rowIndex"
						class="place-detail__row"
						:style="{ transform: `translateY(${rowIndex * ROW_HEIGHT}px)` }">
						<div v-for="(asset, colIndex) in rows[rowIndex]"
							:key="asset.id"
							class="place-detail__item"
							@click="onPhotoClick(rowIndex, colIndex)">
							<img :src="getThumbnailUrl(asset.id)"
								loading="lazy"
								class="place-detail__image">
						</div>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getThumbnailUrl } from '../services/api.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import MapMarkerIcon from 'vue-material-design-icons/MapMarker.vue'

const props = defineProps({
	field: { type: String, required: true },   // e.g. "exifInfo.city"
	value: { type: String, required: true },   // e.g. "Berlin"
})

const store = useImmichStore()
const router = useRouter()

// Map fieldName → marker property key
function fieldToKey(field) {
	const map = {
		'exifInfo.city': 'city',
		'exifInfo.country': 'country',
		'exifInfo.state': 'state',
	}
	return map[field] ?? field.split('.').pop()
}

// --- Filtered assets from already-loaded mapMarkers (no extra API call) ---
const filteredAssets = computed(() => {
	const key = fieldToKey(props.field)
	return store.mapMarkers.filter(m => m[key] === props.value)
})

// --- Virtual scroll constants ---
const ITEMS_PER_ROW = 5
const ROW_HEIGHT = 210
const OVERSCAN = 4 // extra rows above/below viewport

const scrollContainer = ref(null)
const scrollTop = ref(0)
const viewportHeight = ref(800)

// Split flat asset list into rows of ITEMS_PER_ROW
const rows = computed(() => {
	const result = []
	const assets = filteredAssets.value
	for (let i = 0; i < assets.length; i += ITEMS_PER_ROW) {
		result.push(assets.slice(i, i + ITEMS_PER_ROW))
	}
	return result
})

const totalHeight = computed(() => rows.value.length * ROW_HEIGHT)

// Array of row indices that should be rendered (in/near viewport)
const visibleRowRange = computed(() => {
	const firstRow = Math.max(0, Math.floor((scrollTop.value - OVERSCAN * ROW_HEIGHT) / ROW_HEIGHT))
	const lastRow = Math.min(
		rows.value.length - 1,
		Math.ceil((scrollTop.value + viewportHeight.value + OVERSCAN * ROW_HEIGHT) / ROW_HEIGHT),
	)
	const result = []
	for (let i = firstRow; i <= lastRow; i++) result.push(i)
	return result
})

// --- Scroll handler ---
let scrollRaf = null
function onScroll() {
	if (scrollRaf) return
	scrollRaf = requestAnimationFrame(() => {
		if (scrollContainer.value) {
			scrollTop.value = scrollContainer.value.scrollTop
			viewportHeight.value = scrollContainer.value.clientHeight
		}
		scrollRaf = null
	})
}

// --- Lightbox ---
function onPhotoClick(rowIndex, colIndex) {
	const globalIndex = rowIndex * ITEMS_PER_ROW + colIndex
	store.openLightbox(filteredAssets.value, globalIndex)
}

// --- Load map markers if not yet available ---
const loadingMarkers = ref(false)
onMounted(async () => {
	if (store.mapMarkers.length === 0) {
		loadingMarkers.value = true
		await store.fetchMapMarkers()
		loadingMarkers.value = false
	}
	if (scrollContainer.value) {
		viewportHeight.value = scrollContainer.value.clientHeight
	}
})

onBeforeUnmount(() => {
	if (scrollRaf) cancelAnimationFrame(scrollRaf)
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
	position: relative;
}

.place-detail__runway {
	position: relative;
	width: 100%;
}

.place-detail__row {
	position: absolute;
	top: 0;
	left: 52px;
	right: 0;
	height: v-bind('ROW_HEIGHT + "px"');
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: 4px;
	padding: 2px 16px 2px 0;
}

.place-detail__item {
	aspect-ratio: 1;
	overflow: hidden;
	border-radius: 4px;
	cursor: pointer;
	background: var(--color-background-dark);
}

.place-detail__item:hover {
	opacity: 0.85;
}

.place-detail__image {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
</style>
