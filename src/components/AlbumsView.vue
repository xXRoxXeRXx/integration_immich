<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
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

		<template v-else>
			<!-- Toolbar with create button -->
			<div class="albums-view__toolbar">
				<NcButton variant="primary" @click="showCreateDialog = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Album erstellen') }}
				</NcButton>
			</div>

			<NcEmptyContent v-if="store.albums.length === 0"
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
					<button class="albums-view__delete-btn"
						:title="t('integration_immich', 'Album löschen')"
						@click.stop="confirmDelete(album)">
						<TrashIcon :size="18" />
					</button>
				</div>
			</div>
		</template>

		<!-- Create Album Dialog -->
		<NcDialog v-if="showCreateDialog"
			:name="t('integration_immich', 'Album erstellen')"
			@closing="showCreateDialog = false; newAlbumName = ''">
			<div class="albums-view__dialog-body">
				<NcTextField :label="t('integration_immich', 'Albumname')"
					v-model="newAlbumName"
					:placeholder="t('integration_immich', 'Mein Album')"
					@keyup.enter="createAlbum" />
			</div>
			<template #actions>
				<NcButton variant="tertiary" @click="showCreateDialog = false">
					{{ t('integration_immich', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="secondary"
					:disabled="!newAlbumName.trim() || creating"
					@click="openAssetPicker">
					<template #icon>
						<ImagePlusIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Bilder auswählen') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!newAlbumName.trim() || creating"
					@click="createAlbum()">
					<template #icon>
						<NcLoadingIcon v-if="creating" :size="20" />
						<PlusIcon v-else :size="20" />
					</template>
					{{ t('integration_immich', 'Erstellen') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Asset Picker Overlay -->
		<AssetPickerModal v-if="showAssetPicker"
			:album-name="newAlbumName"
			:creating="creating"
			@confirm="createAlbum"
			@cancel="showAssetPicker = false" />

		<!-- Delete Confirmation Dialog -->
		<NcDialog v-if="albumToDelete"
			:name="t('integration_immich', 'Album löschen')"
			@closing="albumToDelete = null">
			<p style="padding: 8px 0">
				{{ t('integration_immich', 'Album „{name}" wirklich löschen?', { name: albumToDelete.albumName }) }}
			</p>
			<template #actions>
				<NcButton variant="tertiary" @click="albumToDelete = null">
					{{ t('integration_immich', 'Abbrechen') }}
				</NcButton>
				<NcButton variant="error"
					:disabled="deleting"
					@click="deleteAlbumConfirmed">
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="20" />
						<TrashIcon v-else :size="20" />
					</template>
					{{ t('integration_immich', 'Löschen') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon, NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useImmichStore } from '../store/immich.js'
import { getAlbumThumbnailUrl, createAlbum as apiCreateAlbum, deleteAlbum as apiDeleteAlbum } from '../services/api.js'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import FolderIcon from 'vue-material-design-icons/FolderMultipleImage.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TrashIcon from 'vue-material-design-icons/Delete.vue'
import ImagePlusIcon from 'vue-material-design-icons/ImagePlus.vue'
import AssetPickerModal from './AssetPickerModal.vue'

const store = useImmichStore()
const router = useRouter()

const showCreateDialog = ref(false)
const showAssetPicker = ref(false)
const newAlbumName = ref('')
const creating = ref(false)
const albumToDelete = ref(null)
const deleting = ref(false)

function openAlbum(id) {
	router.push({ name: 'album-detail', params: { id } })
}

function openAssetPicker() {
	if (!newAlbumName.value.trim()) return
	showCreateDialog.value = false
	showAssetPicker.value = true
}

async function createAlbum(assetIds = []) {
	if (!newAlbumName.value.trim() || creating.value) return
	creating.value = true
	try {
		const response = await apiCreateAlbum(newAlbumName.value.trim(), assetIds)
		const newAlbum = response.data
		newAlbumName.value = ''
		showCreateDialog.value = false
		showAssetPicker.value = false
		await store.fetchAlbums()
		if (newAlbum?.id) {
			router.push({ name: 'album-detail', params: { id: newAlbum.id } })
		}
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Erstellen: {msg}', { msg: e.message }))
	} finally {
		creating.value = false
	}
}


function confirmDelete(album) {
	albumToDelete.value = album
}

async function deleteAlbumConfirmed() {
	if (!albumToDelete.value || deleting.value) return
	deleting.value = true
	try {
		await apiDeleteAlbum(albumToDelete.value.id)
		showSuccess(t('integration_immich', 'Album gelöscht'))
		albumToDelete.value = null
		await store.fetchAlbums()
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Löschen: {msg}', { msg: e.message }))
	} finally {
		deleting.value = false
	}
}

onMounted(() => {
	store.fetchAlbums()
})
</script>

<style scoped>
.albums-view {
	height: 100%;
	overflow-y: auto;
	padding: 24px 16px 16px;
	box-sizing: border-box;
}

.albums-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.albums-view__toolbar {
	display: flex;
	justify-content: flex-end;
	padding: 0 8px 16px;
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
	position: relative;
}

.albums-view__item:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.albums-view__item:hover .albums-view__delete-btn {
	opacity: 1;
}

.albums-view__delete-btn {
	all: unset;
	box-sizing: border-box;
	position: absolute;
	top: 6px;
	right: 6px;
	width: 32px;
	height: 32px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: rgba(0, 0, 0, 0.55);
	color: #fff;
	cursor: pointer;
	opacity: 0;
	transition: opacity 0.15s, background 0.15s;
	z-index: 2;
}

.albums-view__delete-btn:hover {
	background: rgba(var(--color-error-rgb, 211, 47, 47), 0.85);
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

.albums-view__dialog-body {
	padding: 8px 0;
	min-width: 300px;
}

@media (max-width: 480px) {
	.albums-view {
		padding: 8px;
	}

	.albums-view__grid {
		grid-template-columns: repeat(2, 1fr);
		gap: 8px;
	}

	.albums-view__name {
		font-size: 13px;
	}

	.albums-view__info {
		padding: 8px;
	}
}

@media (hover: none) {
	.albums-view__delete-btn {
		opacity: 1;
	}
}
</style>
