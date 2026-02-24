<template>
	<div class="photo-grid">
		<div v-for="(asset, index) in assets"
			:key="asset.id"
			class="photo-grid__item"
			@click="$emit('click', asset, index)">
			<img :src="getThumbnailUrl(asset.id)"
				:alt="asset.originalFileName || ''"
				loading="lazy"
				class="photo-grid__image">
			<div v-if="asset.isImage === false" class="photo-grid__video-badge">
				<VideoIcon :size="16" />
			</div>
		</div>
	</div>
</template>

<script setup>
import { getThumbnailUrl } from '../services/api.js'
import VideoIcon from 'vue-material-design-icons/Play.vue'

defineProps({
	assets: {
		type: Array,
		required: true,
	},
})

defineEmits(['click'])
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

.photo-grid__image {
	width: 100%;
	height: 100%;
	object-fit: cover;
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
</style>
