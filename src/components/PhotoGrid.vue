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
				'photo-grid__item--selectable': selectable && store.isSelectionMode,
				'photo-grid__item--selected': selectable && store.selectedAssetIds.has(asset.id),
			}"
			@click="handleClick(asset, index)">
			<img :src="getThumbnailUrl(asset.id)"
				:alt="asset.originalFileName || ''"
				loading="lazy"
				class="photo-grid__image">
			<div v-if="asset.isImage === false" class="photo-grid__video-badge">
				<VideoIcon :size="16" />
			</div>
			<!-- Selection checkbox overlay — visible when in selection mode -->
			<div v-if="selectable && store.isSelectionMode"
				class="photo-grid__checkbox"
				:class="{ 'photo-grid__checkbox--checked': store.selectedAssetIds.has(asset.id) }">
				<CheckIcon v-if="store.selectedAssetIds.has(asset.id)" :size="14" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { getThumbnailUrl } from '../services/api.js'
import { useImmichStore } from '../store/immich.js'
import VideoIcon from 'vue-material-design-icons/Play.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

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
</script>

<style scoped>
.photo-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 4px;
	padding: 8px;
}

.photo-grid__item {
	position: relative;
	aspect-ratio: 1;
	overflow: hidden;
	border-radius: 4px;
	cursor: pointer;
	background-color: var(--color-background-dark);
}

.photo-grid__item:hover {
	opacity: 0.85;
}

.photo-grid__item--selected {
	opacity: 1;
	outline: 3px solid var(--color-primary);
	outline-offset: -3px;
}

.photo-grid__image {
	width: 100%;
	height: 100%;
	object-fit: cover;
	cursor: pointer;
}

.photo-grid__video-badge {
	position: absolute;
	top: 8px;
	right: 8px;
	background: rgba(0, 0, 0, 0.6);
	color: white;
	border-radius: 50%;
	width: 28px;
	height: 28px;
	display: flex;
	align-items: center;
	justify-content: center;
}

/* Circular checkbox overlay — top-left corner */
.photo-grid__checkbox {
	position: absolute;
	top: 8px;
	left: 8px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	border: 2px solid white;
	background: rgba(0, 0, 0, 0.35);
	pointer-events: none;
	display: flex;
	align-items: center;
	justify-content: center;
	color: white;
	z-index: 2;
}

.photo-grid__checkbox--checked {
	background: var(--color-primary);
	border-color: var(--color-primary);
}
</style>
