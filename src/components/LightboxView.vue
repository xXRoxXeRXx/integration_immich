<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Teleport to="body">
		<div
			v-if="store.lightbox.visible"
			class="ic-lb"
			:class="{ 'ic-lb--info': showInfo, 'ic-lb--hidden': pickerOpen }"
			ref="overlayEl"
			tabindex="-1"
			@keydown="onKey"
			@click.self="close"
		>
			<!-- Top bar -->
			<div class="ic-lb-bar">
				<span class="ic-lb-counter">{{ currentIndex + 1 }}&thinsp;/&thinsp;{{ assets.length }}</span>
				<div class="ic-lb-bar-end">
					<button
						v-if="currentAsset"
						class="ic-lb-btn"
						:class="{ 'ic-lb-btn--loading': savingToNc }"
						title="In Nextcloud speichern"
						:disabled="savingToNc"
						@click.stop="saveCurrentToNextcloud"
					>
						<svg v-if="!savingToNc" viewBox="0 0 24 24" aria-hidden="true">
							<path d="M15 9H5V5h10m-3 14a3 3 0 0 1-3-3 3 3 0 0 1 3-3 3 3 0 0 1 3 3 3 3 0 0 1-3 3m5-16H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7z" />
						</svg>
						<svg v-else viewBox="0 0 24 24" aria-hidden="true" class="ic-lb-spin">
							<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" />
						</svg>
					</button>
					<button
						v-if="currentAsset"
						class="ic-lb-btn"
						:class="{ 'ic-lb-btn--loading': downloadingAsset }"
						title="Herunterladen"
						:disabled="downloadingAsset"
						@click.stop="downloadCurrent"
					>
						<svg v-if="!downloadingAsset" viewBox="0 0 24 24" aria-hidden="true">
							<path d="M5 20h14v-2H5m14-9h-4V3H9v6H5l7 7z" />
						</svg>
						<svg v-else viewBox="0 0 24 24" aria-hidden="true" class="ic-lb-spin">
							<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" />
						</svg>
					</button>
					<button
						v-if="currentAsset"
						class="ic-lb-btn"
						:class="{ 'ic-lb-btn--active': isFavorite }"
						:title="isFavorite ? t('integration_immich', 'Aus Favoriten entfernen') : t('integration_immich', 'Zu Favoriten hinzufügen')"
						:disabled="togglingFavorite"
						@click.stop="toggleFavorite"
					>
						<svg v-if="!togglingFavorite" viewBox="0 0 24 24" aria-hidden="true">
							<path v-if="isFavorite" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54z" fill="currentColor" />
							<path v-else d="M16.5 3c-1.74 0-3.41.81-4.5 2.09C10.91 3.81 9.24 3 7.5 3 4.42 3 2 5.42 2 8.5c0 3.78 3.4 6.86 8.55 11.54L12 21.35l1.45-1.32C18.6 15.36 22 12.28 22 8.5 22 5.42 19.58 3 16.5 3zm-4.4 15.55l-.1.1-.1-.1C7.14 14.24 4 11.39 4 8.5 4 6.5 5.5 5 7.5 5c1.54 0 3.04.99 3.57 2.36h1.87C13.46 5.99 14.96 5 16.5 5c2 0 3.5 1.5 3.5 3.5 0 2.89-3.14 5.74-7.9 10.05z" fill="currentColor" />
						</svg>
						<svg v-else viewBox="0 0 24 24" aria-hidden="true" class="ic-lb-spin">
							<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" fill="currentColor" />
						</svg>
					</button>
					<button
						v-if="currentAsset"
						class="ic-lb-btn"
						:class="{ 'ic-lb-btn--active': showAlbumPanel }"
						title="Zu Album hinzufügen"
						:disabled="addingToAlbum"
						@click.stop="toggleAlbumPanel()"
					>
						<svg v-if="!addingToAlbum" viewBox="0 0 24 24" aria-hidden="true">
							<path d="M20 6h-8l-2-2H4c-1.11 0-2 .89-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2m-1 8h-3v3h-2v-3h-3v-2h3V9h2v3h3z" />
						</svg>
						<svg v-else viewBox="0 0 24 24" aria-hidden="true" class="ic-lb-spin">
							<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" />
						</svg>
					</button>
					<button
						class="ic-lb-btn"
						:class="{ 'ic-lb-btn--active': showInfo }"
						title="Info"
						@click.stop="showAlbumPanel = false; showInfo = !showInfo"
					>
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M13 9h-2V7h2m0 10h-2v-6h2m-1-9A10 10 0 0 0 2 12a10 10 0 0 0 10 10 10 10 0 0 0 10-10A10 10 0 0 0 12 2z" />
						</svg>
					</button>
					<button class="ic-lb-btn" title="Schließen" @click.stop="close">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
						</svg>
					</button>
				</div>
			</div>

			<!-- Prev arrow -->
			<button
				v-if="currentIndex > 0"
				class="ic-lb-arrow ic-lb-arrow--prev"
				title="Vorherige"
				@click.stop="navigate(-1)"
			>
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z" />
				</svg>
			</button>

			<!-- Media -->
			<div
				class="ic-lb-stage"
				@click.stop
				@dblclick.stop="onDoubleTap"
				@wheel.prevent="onWheel"
				@touchstart.passive="onTouchStart"
				@touchmove.passive="onTouchMove"
				@touchend.passive="onTouchEnd"
			>
				<!-- Preload neighbours -->
				<link v-if="prevAsset && prevAsset.isImage !== false" rel="prefetch" :href="getPreviewUrl(prevAsset.id)" as="image">
				<link v-if="nextAsset && nextAsset.isImage !== false" rel="prefetch" :href="getPreviewUrl(nextAsset.id)" as="image">

				<img
					v-if="currentAsset && currentAsset.isImage !== false"
					:src="previewSrc"
					:alt="currentAsset.originalFileName || ''"
					class="ic-lb-img"
					:style="zoomStyle"
					draggable="false"
					@mousedown.prevent="onImgMouseDown"
				/>
				<video
					v-else-if="currentAsset"
					:src="videoSrc"
					controls
					autoplay
					class="ic-lb-video"
				/>
			</div>

			<!-- Next arrow -->
			<button
				v-if="currentIndex < assets.length - 1"
				class="ic-lb-arrow ic-lb-arrow--next"
				title="Nächste"
				@click.stop="navigate(1)"
			>
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z" />
				</svg>
			</button>

			<!-- Caption -->
			<div v-if="currentAsset" class="ic-lb-caption">
				<span v-if="currentAsset.originalFileName" class="ic-lb-caption__name">{{ currentAsset.originalFileName }}</span>
				<span v-if="captionDate" class="ic-lb-caption__date">{{ captionDate }}</span>
			</div>

			<!-- Info panel -->
			<Transition name="ic-lb-slide">
				<div v-if="showInfo" class="ic-lb-info" @click.stop>
					<!-- Loading spinner while EXIF is being fetched -->
					<div v-if="fetchingInfo" class="ic-lb-album-panel__loading">
						<svg viewBox="0 0 24 24" class="ic-lb-spin ic-lb-album-panel__spinner" aria-hidden="true">
							<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" fill="currentColor" />
						</svg>
					</div>
					<template v-else>
						<div v-for="[label, val] in infoRows" :key="label" class="ic-lb-info__row">
							<span class="ic-lb-info__label">{{ label }}</span>
							<span class="ic-lb-info__val">{{ val }}</span>
						</div>
						<p v-if="!infoRows.length" class="ic-lb-info__empty">Keine Metadaten verfügbar</p>
					</template>
				</div>
			</Transition>

			<!-- Album picker panel -->
			<Transition name="ic-lb-slide">
				<div v-if="showAlbumPanel" class="ic-lb-info ic-lb-album-panel" @click.stop>
					<p class="ic-lb-album-panel__title">Zu Album hinzufügen</p>
					<div v-if="loadingAlbums" class="ic-lb-album-panel__loading">
						<svg viewBox="0 0 24 24" class="ic-lb-spin ic-lb-album-panel__spinner" aria-hidden="true">
							<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" fill="currentColor" />
						</svg>
					</div>
					<template v-else>
						<!-- Neues Album erstellen -->
						<div v-if="!creatingAlbum"
							class="ic-lb-album-panel__item ic-lb-album-panel__item--new"
							@click.stop="creatingAlbum = true">
							<svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;flex-shrink:0" aria-hidden="true">
								<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z" />
							</svg>
							Neues Album
						</div>
						<div v-else class="ic-lb-album-panel__new-form">
							<input
								ref="newAlbumInputEl"
								v-model="newAlbumName"
								class="ic-lb-album-panel__new-input"
								placeholder="Albumname …"
								@keyup.enter="createAndAdd"
								@keyup.escape="creatingAlbum = false"
							>
							<button class="ic-lb-album-panel__new-btn"
								:disabled="!newAlbumName.trim() || creatingNewAlbum"
								@click.stop="createAndAdd">
								<svg v-if="!creatingNewAlbum" viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor" aria-hidden="true">
									<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
								</svg>
								<svg v-else viewBox="0 0 24 24" class="ic-lb-spin" style="width:16px;height:16px;fill:currentColor" aria-hidden="true">
									<path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" />
								</svg>
							</button>
						</div>
						<p v-if="albums.length === 0" class="ic-lb-info__empty">Keine Alben vorhanden</p>
						<div v-for="album in albums"
							:key="album.id"
							class="ic-lb-album-panel__item"
							:class="{ 'ic-lb-album-panel__item--in-album': currentAssetAlbumIds.has(album.id) }"
							:title="currentAssetAlbumIds.has(album.id) ? 'Bereits in diesem Album' : ''"
							@click.stop="currentAssetAlbumIds.has(album.id) ? null : addCurrentToAlbum(album.id)">
							<svg v-if="currentAssetAlbumIds.has(album.id)"
								viewBox="0 0 24 24"
								style="width:14px;height:14px;fill:currentColor;flex-shrink:0;opacity:0.55"
								aria-hidden="true">
								<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
							</svg>
							{{ album.albumName }}
						</div>
					</template>
				</div>
			</Transition>
		</div>
	</Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useImmichStore } from '../store/immich.js'
import { getPreviewUrl, getVideoUrl, getAssetInfo, downloadAssets, saveAssetsToNextcloud, getAlbums, addAssetsToAlbum, updateAsset, createAlbum } from '../services/api.js'
import { showSuccess, showError, getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

const store = useImmichStore()
const overlayEl = ref(null)
const showInfo = ref(false)
const fetchingInfo = ref(false)
const downloadingAsset = ref(false)
const savingToNc = ref(false)
const pickerOpen = ref(false)
const showAlbumPanel = ref(false)
const albums = ref([])
const loadingAlbums = ref(false)
const addingToAlbum = ref(false)
const togglingFavorite = ref(false)

// New album creation inside panel
const creatingAlbum = ref(false)
const newAlbumName = ref('')
const newAlbumInputEl = ref(null)
const creatingNewAlbum = ref(false)

// IDs of albums the current asset is already in (populated when panel opens)
const currentAssetAlbumIds = ref(new Set())

// --- Zoom/Pan state ---
const zoomLevel = ref(1)
const panX = ref(0)
const panY = ref(0)
const isPanning = ref(false)
let panStart = { x: 0, y: 0, px: 0, py: 0 }

const zoomStyle = computed(() => {
	if (zoomLevel.value === 1) return {}
	return {
		transform: `scale(${zoomLevel.value}) translate(${panX.value / zoomLevel.value}px, ${panY.value / zoomLevel.value}px)`,
		cursor: isPanning.value ? 'grabbing' : 'grab',
		transition: isPanning.value ? 'none' : 'transform 0.2s ease',
	}
})

function resetZoom() {
	zoomLevel.value = 1
	panX.value = 0
	panY.value = 0
}

function onDoubleTap(e) {
	if (currentAsset.value?.isImage === false) return
	if (zoomLevel.value > 1) {
		resetZoom()
	} else {
		zoomLevel.value = 2.5
	}
}

function onWheel(e) {
	if (currentAsset.value?.isImage === false) return
	const delta = e.deltaY > 0 ? -0.2 : 0.2
	zoomLevel.value = Math.max(1, Math.min(5, zoomLevel.value + delta))
	if (zoomLevel.value === 1) { panX.value = 0; panY.value = 0 }
}


// Mouse pan
function onImgMouseDown(e) {
	if (zoomLevel.value <= 1) return
	isPanning.value = true
	panStart = { x: e.clientX, y: e.clientY, px: panX.value, py: panY.value }
	window.addEventListener('mousemove', onImgMouseMove)
	window.addEventListener('mouseup', onImgMouseUp)
}
function onImgMouseMove(e) {
	if (!isPanning.value) return
	panX.value = panStart.px + (e.clientX - panStart.x)
	panY.value = panStart.py + (e.clientY - panStart.y)
}
function onImgMouseUp() {
	isPanning.value = false
	window.removeEventListener('mousemove', onImgMouseMove)
	window.removeEventListener('mouseup', onImgMouseUp)
}

// --- Touch / Swipe ---
let touchStartX = 0
let touchStartY = 0
let touchStartDist = 0
let touchZoomBase = 1

function onTouchStart(e) {
	if (e.touches.length === 1) {
		touchStartX = e.touches[0].clientX
		touchStartY = e.touches[0].clientY
	} else if (e.touches.length === 2) {
		touchStartDist = Math.hypot(
			e.touches[0].clientX - e.touches[1].clientX,
			e.touches[0].clientY - e.touches[1].clientY
		)
		touchZoomBase = zoomLevel.value
	}
}

function onTouchMove(e) {
	if (e.touches.length === 2) {
		const dist = Math.hypot(
			e.touches[0].clientX - e.touches[1].clientX,
			e.touches[0].clientY - e.touches[1].clientY
		)
		zoomLevel.value = Math.max(1, Math.min(5, touchZoomBase * (dist / touchStartDist)))
	}
}

function onTouchEnd(e) {
	if (e.changedTouches.length === 1 && zoomLevel.value <= 1) {
		const dx = e.changedTouches[0].clientX - touchStartX
		const dy = e.changedTouches[0].clientY - touchStartY
		if (Math.abs(dx) > 40 && Math.abs(dy) < 60) {
			if (dx < 0) navigate(1)
			else navigate(-1)
		}
	}
	if (zoomLevel.value <= 1) { panX.value = 0; panY.value = 0 }
}

// --- Navigation ---
function navigate(dir) {
	resetZoom()
	if (dir < 0) store.lightboxPrev()
	else store.lightboxNext()
}

const isFavorite = computed(() => currentAsset.value?.isFavorite === true)

const assets = computed(() => store.lightbox.assets ?? [])
const currentIndex = computed(() => store.lightbox.currentIndex ?? 0)
const currentAsset = computed(() => assets.value[currentIndex.value] ?? null)
const prevAsset = computed(() => currentIndex.value > 0 ? assets.value[currentIndex.value - 1] : null)
const nextAsset = computed(() => currentIndex.value < assets.value.length - 1 ? assets.value[currentIndex.value + 1] : null)
const previewSrc = computed(() => currentAsset.value ? getPreviewUrl(currentAsset.value.id) : '')
const videoSrc = computed(() => currentAsset.value ? getVideoUrl(currentAsset.value.id) : '')

function formatDate(asset) {
	const raw = asset?.localDateTime || asset?.fileCreatedAt || asset?.exifInfo?.dateTimeOriginal
	if (!raw) return ''
	try {
		return new Date(raw).toLocaleDateString('de-DE', {
			year: 'numeric', month: 'long', day: 'numeric',
			hour: '2-digit', minute: '2-digit',
		})
	} catch { return '' }
}

const captionDate = computed(() => formatDate(currentAsset.value))

const infoRows = computed(() => {
	const asset = currentAsset.value
	if (!asset) return []
	const e = asset.exifInfo || {}
	const rows = []
	const date = formatDate(asset)
	if (date) rows.push(['Datum', date])
	const camera = [e.make, e.model].filter(Boolean).join(' ')
	if (camera) rows.push(['Kamera', camera])
	if (e.lensModel) rows.push(['Objektiv', e.lensModel])
	const exposure = [
		e.fNumber ? `f/${e.fNumber}` : null,
		e.exposureTime ? `${e.exposureTime}s` : null,
		e.iso ? `ISO\u00a0${e.iso}` : null,
		e.focalLength ? `${e.focalLength}\u00a0mm` : null,
	].filter(Boolean)
	if (exposure.length) rows.push(['Belichtung', exposure.join('  ·  ')])
	const location = [e.city, e.state, e.country].filter(Boolean).join(', ')
	if (location) rows.push(['Ort', location])
	if (e.fileSizeInByte) rows.push(['Größe', (e.fileSizeInByte / 1024 / 1024).toFixed(1) + '\u00a0MB'])
	if (asset.originalFileName) rows.push(['Dateiname', asset.originalFileName])
	return rows
})

async function ensureExifInfo() {
	const asset = currentAsset.value
	if (!asset || asset.exifInfo || fetchingInfo.value) return
	fetchingInfo.value = true
	try {
		const response = await getAssetInfo(asset.id)
		const full = response.data
		if (full && full.exifInfo) {
			// Use store action to avoid direct mutation
			store.patchLightboxAsset(currentIndex.value, { ...asset, ...full })
		}
	} catch {
		// silently ignore — info panel shows "Keine Metadaten"
	} finally {
		fetchingInfo.value = false
	}
}

async function downloadCurrent() {
	if (!currentAsset.value || downloadingAsset.value) return
	downloadingAsset.value = true
	try {
		const response = await downloadAssets([currentAsset.value.id])
		const disposition = response.headers?.['content-disposition'] ?? ''
		let fileName = currentAsset.value.originalFileName || 'immich-download.bin'
		const match = disposition.match(/filename="?([^";\n]+)"?/)
		if (match) fileName = match[1]
		const url = URL.createObjectURL(response.data)
		const a = document.createElement('a')
		a.href = url
		a.download = fileName
		document.body.appendChild(a)
		a.click()
		document.body.removeChild(a)
		URL.revokeObjectURL(url)
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Herunterladen: {msg}', { msg: e.message }))
	} finally {
		downloadingAsset.value = false
	}
}

async function saveCurrentToNextcloud() {
	if (!currentAsset.value || savingToNc.value) return

	const picker = getFilePickerBuilder(t('integration_immich', 'Speicherort in Nextcloud wählen'))
		.setMultiSelect(false)
		.allowDirectories(true)
		.addButton({
			label: t('integration_immich', 'Hier speichern'),
			type: 'primary',
			callback: () => {},
		})
		.build()

	let path
	pickerOpen.value = true
	try {
		path = await picker.pick()
	} catch (e) {
		pickerOpen.value = false
		if (!(e instanceof FilePickerClosed)) {
			showError(t('integration_immich', 'Fehler beim Öffnen des Ordner-Dialogs'))
		}
		return
	}
	pickerOpen.value = false

	if (!path) return

	savingToNc.value = true
	try {
		const response = await saveAssetsToNextcloud([currentAsset.value.id], path)
		const { saved, failed } = response.data
		if (failed === 0) {
			showSuccess(t('integration_immich', 'Datei in Nextcloud gespeichert'))
		} else {
			showError(t('integration_immich', 'Speichern fehlgeschlagen'))
		}
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Speichern: {msg}', { msg: e.message }))
	} finally {
		savingToNc.value = false
	}
}

async function toggleFavorite() {
	if (!currentAsset.value || togglingFavorite.value) return
	togglingFavorite.value = true
	const newVal = !currentAsset.value.isFavorite
	try {
		await updateAsset(currentAsset.value.id, { isFavorite: newVal })
		store.patchAssetFavorite([currentAsset.value.id], newVal)
		showSuccess(newVal
			? t('integration_immich', 'Zu Favoriten hinzugefügt')
			: t('integration_immich', 'Aus Favoriten entfernt'))
	} catch (e) {
		showError(t('integration_immich', 'Fehler: {msg}', { msg: e.message }))
	} finally {
		togglingFavorite.value = false
	}
}

function toggleAlbumPanel() {
	if (showAlbumPanel.value) {
		showAlbumPanel.value = false
		creatingAlbum.value = false
		newAlbumName.value = ''
	} else {
		openAlbumPanel()
	}
}

async function refreshAssetAlbumIds() {
	const assetId = currentAsset.value?.id
	if (!assetId) { currentAssetAlbumIds.value = new Set(); return }
	try {
		const response = await getAlbums({ assetId })
		currentAssetAlbumIds.value = new Set((response.data ?? []).map(a => a.id))
	} catch {
		currentAssetAlbumIds.value = new Set()
	}
}

async function openAlbumPanel() {
	showInfo.value = false
	showAlbumPanel.value = true
	creatingAlbum.value = false
	newAlbumName.value = ''
	loadingAlbums.value = true
	try {
		const [allRes] = await Promise.all([getAlbums(), refreshAssetAlbumIds()])
		albums.value = allRes.data ?? []
	} catch (e) {
		showError(t('integration_immich', 'Alben konnten nicht geladen werden'))
		showAlbumPanel.value = false
	} finally {
		loadingAlbums.value = false
	}
}

async function addCurrentToAlbum(albumId) {
	if (!currentAsset.value || addingToAlbum.value) return
	addingToAlbum.value = true
	showAlbumPanel.value = false
	try {
		const response = await addAssetsToAlbum(albumId, [currentAsset.value.id])
		const results = response.data ?? []
		const failed = results.filter(r => !r.success).length
		if (failed === 0) {
			showSuccess(t('integration_immich', 'Zum Album hinzugefügt'))
		} else {
			showError(t('integration_immich', 'Fehler beim Hinzufügen zum Album'))
		}
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Hinzufügen: {msg}', { msg: e.message }))
	} finally {
		addingToAlbum.value = false
	}
}

async function createAndAdd() {
	const name = newAlbumName.value.trim()
	if (!name || creatingNewAlbum.value) return
	creatingNewAlbum.value = true
	try {
		const res = await createAlbum(name)
		const albumId = res.data?.id
		if (albumId && currentAsset.value) {
			const addRes = await addAssetsToAlbum(albumId, [currentAsset.value.id])
			const failed = (addRes.data ?? []).filter(r => !r.success).length
			if (failed === 0) {
				showSuccess(t('integration_immich', 'Album erstellt und Bild hinzugefügt'))
			} else {
				showError(t('integration_immich', 'Album erstellt, aber Fehler beim Hinzufügen'))
			}
		} else {
			showSuccess(t('integration_immich', 'Album erstellt'))
		}
		showAlbumPanel.value = false
		creatingAlbum.value = false
		newAlbumName.value = ''
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Erstellen: {msg}', { msg: e.message }))
	} finally {
		creatingNewAlbum.value = false
	}
}

// Auto-focus new album input when form opens
watch(creatingAlbum, (val) => {
	if (val) nextTick(() => newAlbumInputEl.value?.focus())
})

function close() {
	showInfo.value = false
	showAlbumPanel.value = false
	resetZoom()
	store.closeLightbox()
}

function onKey(e) {
	if (e.key === 'Escape') {
		if (zoomLevel.value > 1) { resetZoom(); return }
		close()
	} else if (e.key === 'ArrowLeft') navigate(-1)
	else if (e.key === 'ArrowRight') navigate(1)
}

watch(() => store.lightbox.visible, (visible) => {
	if (visible) {
		showInfo.value = false
		showAlbumPanel.value = false
		resetZoom()
		nextTick(() => overlayEl.value?.focus())
	}
})

// Fetch EXIF when asset changes, reset zoom, refresh album membership
watch([() => store.lightbox.visible, currentIndex], ([visible]) => {
	if (visible) {
		resetZoom()
		ensureExifInfo()
		if (showAlbumPanel.value) refreshAssetAlbumIds()
	}
})
</script>

<!-- Kein scoped: das Overlay liegt via Teleport direkt im <body>,
     außerhalb jedes Nextcloud-Stacking-Contexts.
     all:unset auf Buttons löscht NC-Globalstyles komplett. -->
<style>
/* ============================================================
   LIGHTBOX — Modern Dark Glass UI
   ============================================================ */

/* ---- Base overlay ---- */
.ic-lb {
	position: fixed;
	inset: 0;
	z-index: 99000;
	background: #080808;
	display: flex;
	align-items: center;
	justify-content: center;
	outline: none;
}

.ic-lb--hidden {
	visibility: hidden;
}

/* ---- Top bar ---- */
.ic-lb-bar {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 12px 0 20px;
	background: linear-gradient(180deg, rgba(0,0,0,0.72) 0%, transparent 100%);
	z-index: 10;
}

.ic-lb-counter {
	color: rgba(255,255,255,0.5);
	font-size: 12px;
	font-weight: 500;
	letter-spacing: 0.08em;
	font-variant-numeric: tabular-nums;
}

.ic-lb-bar-end {
	display: flex;
	gap: 2px;
}

/* ---- Icon buttons ---- */
.ic-lb-btn {
	all: unset;
	box-sizing: border-box;
	cursor: pointer;
	width: 40px;
	height: 40px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 8px;
	color: rgba(255,255,255,0.7);
	transition: color 0.15s, background 0.15s;
}

.ic-lb-btn svg {
	width: 20px;
	height: 20px;
	fill: currentColor;
	pointer-events: none;
	display: block;
}

.ic-lb-btn:hover {
	color: #fff;
	background: rgba(255,255,255,0.08);
}

.ic-lb-btn--active {
	color: #fff;
	background: rgba(255,255,255,0.12);
}

.ic-lb-btn:disabled {
	opacity: 0.3;
	cursor: default;
}

@keyframes ic-lb-spin {
	to { transform: rotate(360deg); }
}

.ic-lb-spin {
	animation: ic-lb-spin 0.75s linear infinite;
	transform-origin: center;
}

/* ---- Nav arrows ---- */
.ic-lb-arrow {
	all: unset;
	box-sizing: border-box;
	cursor: pointer;
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	width: 44px;
	height: 80px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(255,255,255,0.06);
	backdrop-filter: blur(8px);
	-webkit-backdrop-filter: blur(8px);
	border: 1px solid rgba(255,255,255,0.08);
	border-radius: 12px;
	color: rgba(255,255,255,0.65);
	z-index: 10;
	transition: background 0.2s, color 0.2s, border-color 0.2s, right 0.28s cubic-bezier(.4,0,.2,1), left 0.28s cubic-bezier(.4,0,.2,1);
}

.ic-lb-arrow svg {
	width: 24px;
	height: 24px;
	fill: currentColor;
	pointer-events: none;
	display: block;
}

.ic-lb-arrow:hover {
	background: rgba(255,255,255,0.14);
	border-color: rgba(255,255,255,0.18);
	color: #fff;
}

.ic-lb-arrow--prev { left: 16px; }
.ic-lb-arrow--next { right: 16px; }

.ic-lb--info .ic-lb-arrow--next {
	right: calc(16px + 300px);
}

/* ---- Media stage ---- */
.ic-lb-stage {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
}

.ic-lb--info .ic-lb-stage {
	padding-right: calc(300px);
	transition: padding-right 0.28s cubic-bezier(.4,0,.2,1);
}

.ic-lb-img {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	user-select: none;
	display: block;
	will-change: transform;
}

.ic-lb-video {
	max-width: 100%;
	max-height: 100%;
	outline: none;
	border-radius: 6px;
	display: block;
}

/* ---- Caption ---- */
.ic-lb-caption {
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	padding: 48px 80px 22px 24px;
	background: linear-gradient(transparent, rgba(0,0,0,0.7));
	color: #fff;
	display: flex;
	flex-direction: column;
	gap: 4px;
	pointer-events: none;
	z-index: 5;
}

.ic-lb-caption__name {
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	letter-spacing: 0.01em;
}

.ic-lb-caption__date {
	font-size: 11px;
	color: rgba(255,255,255,0.5);
	letter-spacing: 0.02em;
}

/* ---- Side panels (info + album) ---- */
.ic-lb-info {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	width: 300px;
	background: rgba(12,12,14,0.92);
	backdrop-filter: blur(24px) saturate(160%);
	-webkit-backdrop-filter: blur(24px) saturate(160%);
	color: #fff;
	padding: 72px 0 0;
	overflow-y: auto;
	z-index: 10;
	border-left: 1px solid rgba(255,255,255,0.06);
	scrollbar-width: thin;
	scrollbar-color: rgba(255,255,255,0.15) transparent;
}

.ic-lb-info::-webkit-scrollbar { width: 4px; }
.ic-lb-info::-webkit-scrollbar-track { background: transparent; }
.ic-lb-info::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 2px; }

/* slide animation */
.ic-lb-slide-enter-active,
.ic-lb-slide-leave-active {
	transition: transform 0.28s cubic-bezier(.4,0,.2,1), opacity 0.28s ease;
}

.ic-lb-slide-enter-from,
.ic-lb-slide-leave-to {
	transform: translateX(100%);
	opacity: 0;
}

/* ---- Info rows ---- */
.ic-lb-info__empty {
	font-size: 12px;
	color: rgba(255,255,255,0.35);
	margin: 0;
	padding: 0 20px;
}

.ic-lb-info__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 0 20px 18px;
}

.ic-lb-info__label {
	font-size: 9px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: rgba(255,255,255,0.3);
}

.ic-lb-info__val {
	font-size: 13px;
	line-height: 1.5;
	word-break: break-word;
	color: rgba(255,255,255,0.88);
}

/* ---- Album panel extras ---- */
.ic-lb-album-panel__title {
	font-size: 9px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: rgba(255,255,255,0.3);
	margin: 0 0 8px;
	padding: 0 20px;
}

.ic-lb-album-panel__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.ic-lb-album-panel__spinner {
	width: 24px;
	height: 24px;
}

.ic-lb-album-panel__item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 20px;
	cursor: pointer;
	font-size: 13px;
	line-height: 1.4;
	color: rgba(255,255,255,0.82);
	transition: background 0.15s, color 0.15s;
	border-radius: 0;
}

.ic-lb-album-panel__item:hover {
	background: rgba(255,255,255,0.07);
	color: #fff;
}

.ic-lb-album-panel__item--new {
	color: rgba(120,180,255,0.9);
	font-weight: 600;
	border-bottom: 1px solid rgba(255,255,255,0.06);
	margin-bottom: 4px;
	padding-bottom: 12px;
}

.ic-lb-album-panel__item--new:hover {
	background: rgba(120,180,255,0.06);
	color: rgba(160,210,255,1);
}

.ic-lb-album-panel__item--in-album {
	opacity: 0.35;
	cursor: default;
}

.ic-lb-album-panel__item--in-album:hover {
	background: transparent;
	color: rgba(255,255,255,0.82);
}

.ic-lb-album-panel__new-form {
	display: flex;
	gap: 6px;
	padding: 4px 20px 14px;
}

.ic-lb-album-panel__new-input {
	all: unset;
	box-sizing: border-box;
	flex: 1;
	background: rgba(255,255,255,0.06);
	border: 1px solid rgba(255,255,255,0.14);
	border-radius: 8px;
	padding: 8px 12px;
	color: #fff;
	font-size: 13px;
	transition: border-color 0.15s, background 0.15s;
}

.ic-lb-album-panel__new-input::placeholder {
	color: rgba(255,255,255,0.3);
}

.ic-lb-album-panel__new-input:focus {
	border-color: rgba(120,180,255,0.5);
	background: rgba(255,255,255,0.09);
	outline: none;
}

.ic-lb-album-panel__new-btn {
	all: unset;
	box-sizing: border-box;
	cursor: pointer;
	width: 36px;
	height: 36px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(120,180,255,0.15);
	border: 1px solid rgba(120,180,255,0.25);
	border-radius: 8px;
	color: rgba(160,210,255,0.9);
	transition: background 0.15s, border-color 0.15s;
	flex-shrink: 0;
}

.ic-lb-album-panel__new-btn:hover:not(:disabled) {
	background: rgba(120,180,255,0.25);
	border-color: rgba(120,180,255,0.45);
}

.ic-lb-album-panel__new-btn:disabled {
	opacity: 0.3;
	cursor: default;
}
</style>
