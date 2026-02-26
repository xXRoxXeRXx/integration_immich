<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="photo-grid">
		<div v-for="(asset, index) in assets"
			:key="asset.id"
			class="photo-grid__item"
			:class="{
				'photo-grid__item--selected': selectable && store.selectedAssetIds.has(asset.id),
				'photo-grid__item--selection-mode': selectable && store.isSelectionMode,
			}"
			@click="handleClick(asset, index)">

			<!-- Skeleton shown until image loads -->
			<div class="photo-grid__skeleton" />

			<img :src="getThumbnailUrl(asset.id)"
				:alt="asset.originalFileName || ''"
				loading="lazy"
				class="photo-grid__image"
				@load="onLoad"
			>

			<!-- Hover overlay with date -->
			<div class="photo-grid__overlay">
				<span v-if="asset.localDateTime || asset.fileCreatedAt" class="photo-grid__overlay-date">
					{{ formatDate(asset) }}
				</span>
			</div>

			<!-- Video badge — pill bottom-right -->
			<div v-if="asset.isImage === false" class="photo-grid__video-badge">
				<VideoIcon :size="12" />
				<span>Video</span>
			</div>

			<!-- Favorite heart — bottom-left -->
			<div v-if="asset.isFavorite" class="photo-grid__favorite-badge">
				<HeartIcon :size="13" />
			</div>

			<!-- Checkbox: always rendered when selectable, hint on hover, full in selection-mode -->
			<div v-if="selectable"
				class="photo-grid__checkbox"
				:class="{ 'photo-grid__checkbox--checked': store.selectedAssetIds.has(asset.id) }"
				@click.stop="onCheckboxClick(asset)">
				<CheckIcon v-if="store.selectedAssetIds.has(asset.id)" :size="13" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { getThumbnailUrl } from '../services/api.js'
import { useImmichStore } from '../store/immich.js'
import VideoIcon from 'vue-material-design-icons/Play.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import HeartIcon from 'vue-material-design-icons/Heart.vue'

const props = defineProps({
	assets: {
		type: Array,
		required: true,
	},
	selectable: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['click'])
const store = useImmichStore()

function handleClick(asset, index) {
	if (props.selectable && store.isSelectionMode) {
		store.toggleAssetSelection(asset.id)
	} else {
		emit('click', asset, index)
	}
}

function onCheckboxClick(asset) {
	if (!props.selectable) return
	if (!store.isSelectionMode) {
		store.enterSelectionMode()
	}
	store.toggleAssetSelection(asset.id)
}

function onLoad(e) {
	const img = e.target
	img.classList.add('photo-grid__image--loaded')
	const skeleton = img.previousElementSibling
	if (skeleton?.classList.contains('photo-grid__skeleton')) {
		skeleton.style.display = 'none'
	}
}

function formatDate(asset) {
	const raw = asset?.localDateTime || asset?.fileCreatedAt
	if (!raw) return ''
	try {
		return new Date(raw).toLocaleDateString('de-DE', {
			day: '2-digit', month: 'short', year: 'numeric',
		})
	} catch { return '' }
}
</script>

<style scoped>
/* ---- Grid ---- */
.photo-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 3px;
	padding: 0;
}

@media (max-width: 480px) {
	.photo-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

/* ---- Item ---- */
.photo-grid__item {
	position: relative;
	aspect-ratio: 1;
	overflow: hidden;
	border-radius: 6px;
	cursor: pointer;
	background: var(--color-background-dark);
	/* smooth selection border */
	box-shadow: inset 0 0 0 0px var(--color-primary);
	transition: box-shadow 0.15s ease, transform 0.18s ease;
}

.photo-grid__item:hover {
	transform: scale(1.015);
	z-index: 1;
}

/* Selected: inset border + slight scale */
.photo-grid__item--selected {
	box-shadow: inset 0 0 0 3px var(--color-primary);
}

/* ---- Skeleton ---- */
.photo-grid__skeleton {
	position: absolute;
	inset: 0;
	background: linear-gradient(
		90deg,
		var(--color-background-dark) 25%,
		var(--color-background-hover) 50%,
		var(--color-background-dark) 75%
	);
	background-size: 200% 100%;
	animation: pg-shimmer 1.4s ease-in-out infinite;
}

@keyframes pg-shimmer {
	0%   { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

/* ---- Image — fade in on load ---- */
.photo-grid__image {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
	opacity: 0;
	transition: opacity 0.25s ease;
}

.photo-grid__image--loaded {
	opacity: 1;
}

/* ---- Hover overlay ---- */
.photo-grid__overlay {
	position: absolute;
	inset: 0;
	background: linear-gradient(
		to top,
		rgba(0,0,0,0.55) 0%,
		transparent 45%
	);
	opacity: 0;
	transition: opacity 0.2s ease;
	display: flex;
	align-items: flex-end;
	padding: 8px;
	pointer-events: none;
}

.photo-grid__item:hover .photo-grid__overlay {
	opacity: 1;
}

.photo-grid__overlay-date {
	font-size: 11px;
	color: rgba(255,255,255,0.9);
	font-weight: 500;
	letter-spacing: 0.02em;
	line-height: 1;
	/* push away from favorite badge */
	padding-left: 22px;
}

/* ---- Video badge — pill bottom-right ---- */
.photo-grid__video-badge {
	position: absolute;
	bottom: 7px;
	right: 7px;
	background: rgba(0,0,0,0.62);
	backdrop-filter: blur(4px);
	color: #fff;
	border-radius: 20px;
	padding: 3px 7px 3px 5px;
	display: flex;
	align-items: center;
	gap: 3px;
	font-size: 10px;
	font-weight: 600;
	letter-spacing: 0.04em;
	line-height: 1;
	pointer-events: none;
}

/* ---- Favorite badge ---- */
.photo-grid__favorite-badge {
	position: absolute;
	bottom: 8px;
	left: 8px;
	color: #ff4d6d;
	filter: drop-shadow(0 1px 3px rgba(0,0,0,0.8));
	pointer-events: none;
	line-height: 0;
	transition: transform 0.15s ease;
}

.photo-grid__item:hover .photo-grid__favorite-badge {
	transform: scale(1.15);
}

/* ---- Checkbox ---- */
.photo-grid__checkbox {
	position: absolute;
	top: 8px;
	left: 8px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	border: 2px solid rgba(255,255,255,0.9);
	background: rgba(0,0,0,0.25);
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
	z-index: 2;
	cursor: pointer;
	/* hidden by default — shown on hover or in selection-mode */
	opacity: 0;
	transform: scale(0.7);
	transition: opacity 0.15s ease, transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}

/* Show hint on hover (non-selection-mode) */
.photo-grid__item:hover .photo-grid__checkbox {
	opacity: 0.7;
	transform: scale(1);
}

/* Always visible in selection-mode */
.photo-grid__item--selection-mode .photo-grid__checkbox {
	opacity: 1;
	transform: scale(1);
}

.photo-grid__checkbox--checked {
	background: var(--color-primary) !important;
	border-color: var(--color-primary) !important;
	opacity: 1 !important;
	transform: scale(1) !important;
}
</style>
