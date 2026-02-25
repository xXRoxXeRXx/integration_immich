<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Teleport to="body">
		<div
			v-if="store.lightbox.visible"
			class="ic-lb"
			:class="{ 'ic-lb--info': showInfo }"
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
						class="ic-lb-btn"
						:class="{ 'ic-lb-btn--active': showInfo }"
						title="Info"
						@click.stop="showInfo = !showInfo"
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
				@click.stop="store.lightboxPrev()"
			>
				<svg viewBox="0 0 24 24" aria-hidden="true">
					<path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6z" />
				</svg>
			</button>

			<!-- Media -->
			<div class="ic-lb-stage" @click.stop>
				<img
					v-if="currentAsset && currentAsset.isImage !== false"
					:src="previewSrc"
					:alt="currentAsset.originalFileName || ''"
					class="ic-lb-img"
				/>
				<video
					v-else-if="currentAsset"
					:src="videoSrc"
					controls
					class="ic-lb-video"
				/>
			</div>

			<!-- Next arrow -->
			<button
				v-if="currentIndex < assets.length - 1"
				class="ic-lb-arrow ic-lb-arrow--next"
				title="Nächste"
				@click.stop="store.lightboxNext()"
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
					<div v-for="[label, val] in infoRows" :key="label" class="ic-lb-info__row">
						<span class="ic-lb-info__label">{{ label }}</span>
						<span class="ic-lb-info__val">{{ val }}</span>
					</div>
					<p v-if="!infoRows.length" class="ic-lb-info__empty">Keine Metadaten verfügbar</p>
				</div>
			</Transition>
		</div>
	</Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useImmichStore } from '../store/immich.js'
import { getPreviewUrl, getVideoUrl, getAssetInfo } from '../services/api.js'

const store = useImmichStore()
const overlayEl = ref(null)
const showInfo = ref(false)
const fetchingInfo = ref(false)

const assets = computed(() => store.lightbox.assets ?? [])
const currentIndex = computed(() => store.lightbox.currentIndex ?? 0)
const currentAsset = computed(() => assets.value[currentIndex.value] ?? null)
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
			store.lightbox.assets[currentIndex.value] = { ...asset, ...full }
		}
	} catch {
		// silently ignore — info panel just shows "Keine Metadaten"
	} finally {
		fetchingInfo.value = false
	}
}

function close() {
	showInfo.value = false
	store.closeLightbox()
}

function onKey(e) {
	if (e.key === 'Escape') close()
	else if (e.key === 'ArrowLeft') store.lightboxPrev()
	else if (e.key === 'ArrowRight') store.lightboxNext()
}

watch(() => store.lightbox.visible, (visible) => {
	if (visible) {
		showInfo.value = false
		nextTick(() => overlayEl.value?.focus())
	}
})

// Fetch full exifInfo when lightbox opens or asset changes (so info panel is instant)
watch([() => store.lightbox.visible, currentIndex], ([visible]) => {
	if (visible) ensureExifInfo()
})
</script>

<!-- Kein scoped: das Overlay liegt via Teleport direkt im <body>,
     außerhalb jedes Nextcloud-Stacking-Contexts.
     all:unset auf Buttons löscht NC-Globalstyles komplett. -->
<style>
.ic-lb {
	position: fixed;
	inset: 0;
	z-index: 99000;
	background: #000;
	display: flex;
	align-items: center;
	justify-content: center;
	outline: none;
}

/* ---- Top bar ---- */
.ic-lb-bar {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 8px;
	background: linear-gradient(rgba(0, 0, 0, 0.55), transparent);
	z-index: 10;
}

.ic-lb-counter {
	color: rgba(255, 255, 255, 0.75);
	font-size: 13px;
	line-height: 1;
}

.ic-lb-bar-end {
	display: flex;
	gap: 2px;
}

/* ---- Buttons (all:unset löscht NC-Stile komplett) ---- */
.ic-lb-btn {
	all: unset;
	box-sizing: border-box;
	cursor: pointer;
	width: 40px;
	height: 40px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 4px;
	color: #fff;
	transition: background 0.15s;
}

.ic-lb-btn svg {
	width: 22px;
	height: 22px;
	fill: currentColor;
	pointer-events: none;
	display: block;
}

.ic-lb-btn:hover {
	background: rgba(255, 255, 255, 0.15);
}

.ic-lb-btn--active {
	background: rgba(255, 255, 255, 0.2);
}

/* ---- Nav arrows ---- */
.ic-lb-arrow {
	all: unset;
	box-sizing: border-box;
	cursor: pointer;
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	width: 48px;
	height: 72px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.35);
	border-radius: 4px;
	color: #fff;
	z-index: 10;
	transition: background 0.15s, right 0.25s, left 0.25s;
}

.ic-lb-arrow svg {
	width: 28px;
	height: 28px;
	fill: currentColor;
	pointer-events: none;
	display: block;
}

.ic-lb-arrow:hover {
	background: rgba(0, 0, 0, 0.6);
}

.ic-lb-arrow--prev { left: 8px; }
.ic-lb-arrow--next { right: 8px; }

/* Arrow rückt weg wenn Info-Panel offen */
.ic-lb--info .ic-lb-arrow--next {
	right: calc(8px + 280px);
}

/* ---- Media stage ---- */
.ic-lb-stage {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 44px 64px 60px;
	box-sizing: border-box;
}

.ic-lb--info .ic-lb-stage {
	padding-right: calc(64px + 280px);
	transition: padding-right 0.25s ease;
}

.ic-lb-img {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	user-select: none;
	display: block;
}

.ic-lb-video {
	max-width: 100%;
	max-height: 100%;
	outline: none;
	border-radius: 4px;
	display: block;
}

/* ---- Caption ---- */
.ic-lb-caption {
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	padding: 20px 64px 14px 24px;
	background: linear-gradient(transparent, rgba(0, 0, 0, 0.55));
	color: #fff;
	display: flex;
	flex-direction: column;
	gap: 3px;
	pointer-events: none;
	z-index: 5;
}

.ic-lb-caption__name {
	font-size: 14px;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.ic-lb-caption__date {
	font-size: 12px;
	opacity: 0.72;
}

/* ---- Info panel ---- */
.ic-lb-info {
	position: absolute;
	top: 44px;
	right: 0;
	bottom: 0;
	width: 280px;
	background: rgba(14, 14, 14, 0.95);
	backdrop-filter: blur(12px);
	-webkit-backdrop-filter: blur(12px);
	color: #fff;
	padding: 20px 18px;
	overflow-y: auto;
	z-index: 10;
	border-left: 1px solid rgba(255, 255, 255, 0.1);
}

.ic-lb-slide-enter-active,
.ic-lb-slide-leave-active {
	transition: transform 0.25s ease;
}

.ic-lb-slide-enter-from,
.ic-lb-slide-leave-to {
	transform: translateX(100%);
}

.ic-lb-info__empty {
	font-size: 13px;
	opacity: 0.5;
	margin: 0;
	padding: 4px 0;
}

.ic-lb-info__row {
	display: flex;
	flex-direction: column;
	gap: 3px;
	margin-bottom: 16px;
}

.ic-lb-info__label {
	font-size: 10px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.07em;
	opacity: 0.45;
}

.ic-lb-info__val {
	font-size: 13px;
	line-height: 1.45;
	word-break: break-word;
}
</style>
