<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="map-view">
		<NcLoadingIcon v-if="store.loading && store.mapMarkers.length === 0"
			:size="64"
			class="map-view__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="store.mapMarkers.length === 0 && !store.loading"
			:name="t('integration_immich', 'Keine Orte')"
			:description="t('integration_immich', 'Keine Fotos mit GPS-Daten gefunden.')">
			<template #icon>
				<MapIcon :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else ref="mapContainer" class="map-view__map" />

		<div v-if="store.mapMarkers.length > 0" class="map-view__info">
			{{ infoText }}
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getThumbnailUrl } from '../services/api.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import MapIcon from 'vue-material-design-icons/Map.vue'

const store = useImmichStore()
const mapContainer = ref(null)
let leafletMap = null
let L = null

// Spatial sampling: keep one representative marker per grid cell.
// Grid size in degrees (~5 km at equator). Reduces e.g. 35 000 → a few
// thousand unique geographic positions without losing coverage.
const GRID_DEG = 0.05

function spatialSample(markers) {
	const grid = new Map()
	for (const m of markers) {
		if (m.lat == null || m.lon == null) continue
		const key = `${Math.round(m.lat / GRID_DEG)}_${Math.round(m.lon / GRID_DEG)}`
		if (!grid.has(key)) {
			grid.set(key, m)
		}
	}
	return [...grid.values()]
}

const sampledMarkers = computed(() => spatialSample(store.mapMarkers))

const infoText = computed(() => {
	const total = store.mapMarkers.length
	const shown = sampledMarkers.value.length
	if (shown < total) {
		return t(
			'integration_immich',
			'{total} Fotos mit Standort – {shown} Orte angezeigt',
			{ total, shown },
		)
	}
	return t('integration_immich', '{count} Fotos mit Standort', { count: total })
})

async function initMap() {
	if (!mapContainer.value || sampledMarkers.value.length === 0) return

	if (!L) {
		L = (await import(/* webpackMode: "eager" */ 'leaflet')).default
	}

	if (leafletMap) {
		leafletMap.remove()
		leafletMap = null
	}

	// Canvas renderer: all markers drawn on one <canvas> element instead of
	// thousands of SVG nodes — orders of magnitude faster for large datasets.
	const canvasRenderer = L.canvas({ padding: 0.5 })
	leafletMap = L.map(mapContainer.value, { renderer: canvasRenderer })

	L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
		maxZoom: 19,
	}).addTo(leafletMap)

	const bounds = []

	for (const marker of sampledMarkers.value) {
		const latlng = [marker.lat, marker.lon]
		bounds.push(latlng)

		const circle = L.circleMarker(latlng, {
			radius: 6,
			fillColor: '#00b4f1',
			color: '#fff',
			weight: 1.5,
			opacity: 1,
			fillOpacity: 0.85,
			// Must explicitly pass renderer for canvas mode
			renderer: canvasRenderer,
		})

		// Tooltip is created lazily (only when first hovered) — no upfront DOM cost
		circle.bindTooltip(
			() => `<img src="${getThumbnailUrl(marker.id)}" style="width:80px;height:80px;object-fit:cover;border-radius:4px;">`,
			{ direction: 'top', offset: [0, -8] },
		)

		circle.on('click', () => {
			store.openLightbox([{ id: marker.id, isImage: true }], 0)
		})

		circle.addTo(leafletMap)
	}

	if (bounds.length > 0) {
		leafletMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 })
	}
}

onMounted(async () => {
	if (store.mapMarkers.length === 0) {
		await store.fetchMapMarkers()
	}
	await nextTick()
	await initMap()
})

onUnmounted(() => {
	if (leafletMap) {
		leafletMap.remove()
		leafletMap = null
	}
})
</script>

<style src="leaflet/dist/leaflet.css" />

<style scoped>
.map-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding-left: 52px;
}

.map-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.map-view__map {
	flex: 1;
	min-height: 0;
}

.map-view__info {
	padding: 8px 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}
</style>
