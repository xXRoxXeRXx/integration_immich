<template>
	<div class="albums-view">
		<NcEmptyContent v-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcLoadingIcon v-else-if="store.loading && store.albums.length === 0"
			:size="64"
			class="albums-view__loading" />

		<NcEmptyContent v-else-if="store.albums.length === 0"
			:name="t('integration_immich', 'Keine Alben')"
			:description="t('integration_immich', 'In deiner Immich Bibliothek sind noch keine Alben vorhanden.')">
			<template #icon>
				<FolderIcon :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else class="albums-view__grid">
			<div v-for="album in store.albums"
				:key="album.id"
				class="albums-view__item"
				@click="openAlbum(album.id)">
				<div class="albums-view__cover">
					<img v-if="album.albumThumbnailAssetId"
						:src="getAlbumThumbnailUrl(album.id)"
						:alt="album.albumName"
						loading="lazy"
						class="albums-view__cover-image">
					<div v-else class="albums-view__cover-placeholder">
						<FolderIcon :size="48" />
					</div>
				</div>
				<div class="albums-view__info">
					<h3 class="albums-view__name">
						{{ album.albumName }}
					</h3>
					<span class="albums-view__count">
						{{ t('integration_immich', '{count} Bilder', { count: album.assetCount || 0 }) }}
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getAlbumThumbnailUrl } from '../services/api.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import FolderIcon from 'vue-material-design-icons/FolderMultipleImage.vue'

const store = useImmichStore()
const router = useRouter()

function openAlbum(id) {
	router.push({ name: 'album-detail', params: { id } })
}

onMounted(() => {
	store.fetchAlbums()
})
</script>

<style scoped>
.albums-view {
	padding: 16px 16px 16px 52px;
}

.albums-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.albums-view__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
	gap: 16px;
	padding: 8px;
}

.albums-view__item {
	cursor: pointer;
	border-radius: 8px;
	overflow: hidden;
	background-color: var(--color-background-dark);
	transition: box-shadow 0.2s ease;
}

.albums-view__item:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.albums-view__cover {
	aspect-ratio: 1;
	overflow: hidden;
}

.albums-view__cover-image {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.albums-view__cover-placeholder {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
}

.albums-view__info {
	padding: 12px;
}

.albums-view__name {
	font-size: 16px;
	font-weight: bold;
	margin: 0 0 4px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.albums-view__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
