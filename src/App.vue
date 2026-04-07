<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="integration_immich">
		<Navigation />
		<NcAppContent>
			<div class="view-content">
				<!-- Sticky toolbar — inside scroll container like NC Photos -->
				<div class="view-toolbar">
					<!-- Normal mode: title + optional select button -->
					<template v-if="!store.isSelectionMode">
						<h2 class="view-toolbar__title">{{ pageTitle }}</h2>
						<NcButton v-if="isPhotoView"
							variant="tertiary"
							class="view-toolbar__select-btn"
							@click="store.enterSelectionMode()">
							<template #icon>
								<CheckboxMultipleOutlineIcon :size="20" />
							</template>
							{{ t('integration_immich', 'Select') }}
						</NcButton>
					</template>

					<!-- Selection mode: count + action buttons -->
					<template v-else>
						<span class="view-toolbar__selection-count">
							{{ t('integration_immich', '{count} selected', { count: store.selectedAssetIds.size }) }}
						</span>
						<div class="view-toolbar__selection-actions">
							<!-- Primary action: always visible -->
							<NcButton variant="primary"
								:disabled="store.selectedAssetIds.size === 0 || saving"
								@click="saveToNextcloud">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSaveIcon v-else :size="20" />
								</template>
								<span class="selection-btn-label">{{ t('integration_immich', 'Save to Nextcloud') }}</span>
							</NcButton>

							<!-- Secondary actions: visible on desktop, collapsed on mobile -->
							<div class="selection-actions-desktop">
								<NcButton variant="secondary"
									:disabled="store.selectedAssetIds.size === 0 || downloading"
									@click="downloadSelected">
									<template #icon>
										<NcLoadingIcon v-if="downloading" :size="20" />
										<DownloadIcon v-else :size="20" />
									</template>
									{{ t('integration_immich', 'Download') }}
								</NcButton>
								<NcButton v-if="isAlbumDetailView"
									variant="error"
									:disabled="store.selectedAssetIds.size === 0 || removingFromAlbum"
									@click="removeFromCurrentAlbum">
									<template #icon>
										<NcLoadingIcon v-if="removingFromAlbum" :size="20" />
										<FolderRemoveIcon v-else :size="20" />
									</template>
									{{ t('integration_immich', 'Remove from album') }}
								</NcButton>
								<NcButton v-else
									variant="secondary"
									:disabled="store.selectedAssetIds.size === 0 || addingToAlbum || showAlbumPicker"
									@click="showAlbumPicker = true">
									<template #icon>
										<FolderPlusIcon :size="20" />
									</template>
									{{ t('integration_immich', 'Add to album') }}
								</NcButton>
								<NcButton variant="secondary"
									:disabled="store.selectedAssetIds.size === 0 || togglingFavorite"
									@click="toggleFavoritesSelection">
									<template #icon>
										<NcLoadingIcon v-if="togglingFavorite" :size="20" />
										<HeartIcon v-else-if="selectedAllFavorited" :size="20" />
										<HeartOutlineIcon v-else :size="20" />
									</template>
									{{ selectedAllFavorited
										? t('integration_immich', 'Remove from favorites')
										: t('integration_immich', 'Add to favorites') }}
								</NcButton>
								<NcButton v-if="!isAlbumDetailView"
									variant="error"
									:disabled="store.selectedAssetIds.size === 0 || deleting"
									@click="deleteSelectedAssets">
									<template #icon>
										<NcLoadingIcon v-if="deleting" :size="20" />
										<DeleteIcon v-else :size="20" />
									</template>
									{{ t('integration_immich', 'Delete') }}
								</NcButton>
							</div>

							<!-- 3-Punkte-Menü: nur auf Mobile sichtbar -->
							<div class="selection-actions-mobile" :class="{ 'selection-actions-mobile--open': mobileMenuOpen }">
								<button class="selection-kebab" @click.stop="mobileMenuOpen = !mobileMenuOpen" :aria-label="t('integration_immich', 'More actions')">
									<DotsVerticalIcon :size="20" />
								</button>
								<div v-if="mobileMenuOpen" class="selection-kebab-menu" @click="mobileMenuOpen = false">
									<button class="selection-kebab-menu__item"
										:disabled="store.selectedAssetIds.size === 0 || downloading"
										@click="downloadSelected">
										<DownloadIcon :size="18" />
										{{ t('integration_immich', 'Download') }}
									</button>
									<button v-if="isAlbumDetailView"
										class="selection-kebab-menu__item selection-kebab-menu__item--danger"
										:disabled="store.selectedAssetIds.size === 0 || removingFromAlbum"
										@click="removeFromCurrentAlbum">
										<FolderRemoveIcon :size="18" />
										{{ t('integration_immich', 'Remove from album') }}
									</button>
									<button v-else
										class="selection-kebab-menu__item"
										:disabled="store.selectedAssetIds.size === 0 || addingToAlbum"
										@click="showAlbumPicker = true">
										<FolderPlusIcon :size="18" />
										{{ t('integration_immich', 'Add to album') }}
									</button>
									<button class="selection-kebab-menu__item"
										:disabled="store.selectedAssetIds.size === 0 || togglingFavorite"
										@click="toggleFavoritesSelection">
										<HeartIcon v-if="selectedAllFavorited" :size="18" />
										<HeartOutlineIcon v-else :size="18" />
										{{ selectedAllFavorited
											? t('integration_immich', 'Remove from favorites')
											: t('integration_immich', 'Add to favorites') }}
									</button>
									<button v-if="!isAlbumDetailView"
										class="selection-kebab-menu__item selection-kebab-menu__item--danger"
										:disabled="store.selectedAssetIds.size === 0 || deleting"
										@click="deleteSelectedAssets">
										<DeleteIcon :size="18" />
										{{ t('integration_immich', 'Delete') }}
									</button>
								</div>
							</div>

							<NcButton variant="tertiary" @click="store.clearSelection()">
								{{ t('integration_immich', 'Cancel') }}
							</NcButton>
						</div>
					</template>
				</div>
				<div class="view-page">
					<router-view />
				</div>
			</div>
		</NcAppContent>
	</NcContent>
	<LightboxView />
	<NcDialog v-if="showAlbumPicker"
		:name="t('integration_immich', 'Select album')"
		@closing="showAlbumPicker = false">
		<div class="album-picker">
			<NcLoadingIcon v-if="loadingAlbums" :size="32" class="album-picker__loading" />
			<template v-else>
				<div v-if="albums.length === 0" class="album-picker__empty">
					{{ t('integration_immich', 'No albums available') }}
				</div>
				<div v-for="album in albums"
					:key="album.id"
					class="album-picker__item"
					@click="addToAlbum(album.id)">
					{{ album.albumName }}
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { NcContent, NcAppContent, NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError, getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import { useImmichStore } from './store/immich.js'
import { saveAssetsToNextcloud, downloadAssets, addAssetsToAlbum, removeAssetsFromAlbum, getAlbums, updateAsset, deleteAssets } from './services/api.js'
import Navigation from './components/Navigation.vue'
import LightboxView from './components/LightboxView.vue'
import CheckboxMultipleOutlineIcon from 'vue-material-design-icons/CheckboxMultipleOutline.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import FolderRemoveIcon from 'vue-material-design-icons/FolderMinus.vue'
import HeartOutlineIcon from 'vue-material-design-icons/HeartOutline.vue'
import HeartIcon from 'vue-material-design-icons/Heart.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DotsVerticalIcon from 'vue-material-design-icons/DotsVertical.vue'

const store = useImmichStore()
const route = useRoute()
const saving = ref(false)
const downloading = ref(false)
const addingToAlbum = ref(false)
const removingFromAlbum = ref(false)
const togglingFavorite = ref(false)
const deleting = ref(false)
const showAlbumPicker = ref(false)
const albums = ref([])
const loadingAlbums = ref(false)
const mobileMenuOpen = ref(false)

// True when ALL selected assets are already favorited → show "remove" action
const selectedAllFavorited = computed(() => {
	if (store.selectedAssetIds.size === 0) return false
	const map = store.allLoadedAssetsMap
	for (const id of store.selectedAssetIds) {
		const asset = map[id]
		if (!asset || !asset.isFavorite) return false
	}
	return true
})

// True when we are inside an album detail view → album button becomes "remove"
const isAlbumDetailView = computed(() => route.name === 'album-detail')

const pageTitles = {
	'timeline': t('integration_immich', 'All media'),
	'photos': t('integration_immich', 'Photos'),
	'videos': t('integration_immich', 'Videos'),
	'favorites': t('integration_immich', 'Favorites'),
	'albums': t('integration_immich', 'Albums'),
	'album-detail': t('integration_immich', 'Albums'),
	'people': t('integration_immich', 'People'),
	'person-detail': t('integration_immich', 'People'),
	'map': t('integration_immich', 'Map'),
	'explore': t('integration_immich', 'Explore'),
	'place-detail': t('integration_immich', 'Explore'),
}

// Views that contain individual selectable assets (photos/videos)
const photoViews = new Set(['timeline', 'photos', 'videos', 'favorites', 'album-detail', 'person-detail', 'place-detail'])

const pageTitle = computed(() => pageTitles[route.name] ?? 'Immich')
const isPhotoView = computed(() => photoViews.has(route.name))

// Clear selection when navigating to a different view
watch(() => route.name, () => {
	if (store.isSelectionMode) {
		store.clearSelection()
	}
	mobileMenuOpen.value = false
})

async function saveToNextcloud() {
	const picker = getFilePickerBuilder(t('integration_immich', 'Choose save location in Nextcloud'))
		.setMultiSelect(false)
		.allowDirectories(true)
		.addButton({
			label: t('integration_immich', 'Save here'),
			type: 'primary',
			callback: () => {},
		})
		.build()

	let path
	try {
		path = await picker.pick()
	} catch (e) {
		if (!(e instanceof FilePickerClosed)) {
			showError(t('integration_immich', 'Error opening folder dialog'))
		}
		return
	}

	if (!path) return

	saving.value = true
	try {
		const assetIds = [...store.selectedAssetIds]
		const response = await saveAssetsToNextcloud(assetIds, path)
		const { saved, failed } = response.data

		if (failed === 0) {
			showSuccess(t('integration_immich', '{count} file(s) saved to Nextcloud', { count: saved }))
		} else if (saved > 0) {
			showError(t('integration_immich', '{saved} saved, {failed} failed', { saved, failed }))
		} else {
			showError(t('integration_immich', 'Save failed'))
		}
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error saving: {msg}', { msg: e.message }))
	} finally {
		saving.value = false
	}
}

async function downloadSelected() {
	const assetIds = [...store.selectedAssetIds]
	if (assetIds.length === 0) return

	downloading.value = true
	try {
		const response = await downloadAssets(assetIds)

		// Derive filename from Content-Disposition or fall back to a default
		const disposition = response.headers?.['content-disposition'] ?? ''
		let fileName = assetIds.length === 1 ? 'immich-download.bin' : `immich-download-${new Date().toISOString().slice(0, 10)}.zip`
		const match = disposition.match(/filename="?([^";\n]+)"?/)
		if (match) {
			fileName = match[1]
		}

		// Trigger browser download
		const url = URL.createObjectURL(response.data)
		const a = document.createElement('a')
		a.href = url
		a.download = fileName
		document.body.appendChild(a)
		a.click()
		document.body.removeChild(a)
		URL.revokeObjectURL(url)

		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error downloading: {msg}', { msg: e.message }))
	} finally {
		downloading.value = false
	}
}

watch(showAlbumPicker, async (val) => {
	if (val) {
		loadingAlbums.value = true
		try {
			const response = await getAlbums()
			albums.value = response.data ?? []
		} catch (e) {
			showError(t('integration_immich', 'Could not load albums'))
			showAlbumPicker.value = false
		} finally {
			loadingAlbums.value = false
		}
	}
})

async function addToAlbum(albumId) {
	addingToAlbum.value = true
	showAlbumPicker.value = false
	try {
		const assetIds = [...store.selectedAssetIds]
		const response = await addAssetsToAlbum(albumId, assetIds)
		const results = response.data ?? []
		const succeeded = results.filter(r => r.success).length
		const failed = results.filter(r => !r.success).length

		if (failed === 0) {
			showSuccess(t('integration_immich', '{count} asset(s) added to album', { count: succeeded }))
		} else if (succeeded > 0) {
			showError(t('integration_immich', '{succeeded} added, {failed} failed', { succeeded, failed }))
		} else {
			showError(t('integration_immich', 'Error adding to album'))
		}
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error adding: {msg}', { msg: e.message }))
	} finally {
		addingToAlbum.value = false
	}
}

async function removeFromCurrentAlbum() {
	if (store.selectedAssetIds.size === 0 || removingFromAlbum.value) return
	const albumId = route.params.id
	if (!albumId) return
	removingFromAlbum.value = true
	try {
		const assetIds = [...store.selectedAssetIds]
		const response = await removeAssetsFromAlbum(albumId, assetIds)
		const results = response.data ?? []
		const succeeded = results.filter(r => r.success !== false).length
		const failed = results.length - succeeded
		if (failed === 0) {
			showSuccess(t('integration_immich', '{count} asset(s) removed from album', { count: succeeded }))
		} else if (succeeded > 0) {
			showError(t('integration_immich', '{succeeded} removed, {failed} failed', { succeeded, failed }))
		} else {
			showError(t('integration_immich', 'Error removing from album'))
		}
		store.clearSelection()
		await store.fetchAlbum(albumId)
	} catch (e) {
		showError(t('integration_immich', 'Error removing: {msg}', { msg: e.message }))
	} finally {
		removingFromAlbum.value = false
	}
}

async function toggleFavoritesSelection() {
	if (store.selectedAssetIds.size === 0) return
	const removing = selectedAllFavorited.value
	togglingFavorite.value = true
	try {
		const assetIds = [...store.selectedAssetIds]
		await Promise.all(assetIds.map(id => updateAsset(id, { isFavorite: !removing })))
		// Patch isFavorite in all loaded caches immediately so the UI updates
		store.patchAssetFavorite(assetIds, !removing)
		if (removing) {
			showSuccess(
				t('integration_immich', '{count} asset(s) removed from favorites', { count: assetIds.length }),
			)
		} else {
			showSuccess(
				t('integration_immich', '{count} asset(s) added to favorites', { count: assetIds.length }),
			)
		}
		// Invalidate favorites cache and immediately reload if currently on favorites view
		store.favoriteBuckets = []
		store.favoriteAssets = {}
		if (route.name === 'favorites') {
			await store.fetchFavoriteBuckets()
		}
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error updating favorites: {msg}', { msg: e.message }))
	} finally {
		togglingFavorite.value = false
	}
}

async function deleteSelectedAssets() {
	if (store.selectedAssetIds.size === 0 || deleting.value) return

	const confirmed = await new Promise((resolve) => {
		OC.dialogs.confirm(
			t('integration_immich', 'Are you sure you want to delete {count} asset(s)? If trash is enabled in Immich, they will be moved to trash, otherwise they will be permanently deleted.', { count: store.selectedAssetIds.size }),
			t('integration_immich', 'Delete assets'),
			(result) => resolve(result),
			true
		)
	})

	if (!confirmed) return

	deleting.value = true
	const ids = Array.from(store.selectedAssetIds)
	try {
		await deleteAssets(ids)
		showSuccess(t('integration_immich', '{count} asset(s) deleted', { count: ids.length }))
		// Remove from all caches
		ids.forEach(id => store.removeAssetFromAllCaches(id))
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error deleting assets: {msg}', { msg: e.response?.data?.error || e.message }))
	} finally {
		deleting.value = false
	}
}
</script>

<style scoped>
/* Let NC handle app-content layout natively, only prevent outer scroll */
:deep(.app-content) {
	overflow: hidden;
}

/* Single scroll container — full height, scrolls internally */
.view-content {
	height: 100%;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
}

/* Sticky toolbar — same pattern as NC Photos HeaderNavigation */
.view-toolbar {
	position: sticky;
	top: 0;
	z-index: 20;
	flex-shrink: 0;
	min-height: var(--default-clickable-area, 44px);
	display: flex;
	align-items: center;
	gap: 8px;
	/* left: clear the nav toggle button; block: standard nav padding */
	padding-inline-start: calc(var(--default-clickable-area, 44px) + 2 * var(--app-navigation-padding, 4px));
	padding-inline-end: var(--app-navigation-padding, 4px);
	padding-block: var(--app-navigation-padding, 4px);
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
}

.view-toolbar__title {
	font-size: 20px;
	font-weight: 700;
	margin: 0;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	flex: 1;
}

.view-toolbar__select-btn {
	margin-inline-start: auto;
	flex-shrink: 0;
}

.view-toolbar__selection-count {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
	flex: 1;
	white-space: nowrap;
}

.view-toolbar__selection-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

/* Desktop: alle Buttons sichtbar */
.selection-actions-desktop {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Mobile: 3-Punkte-Menü sichtbar, Desktop-Buttons versteckt */
.selection-actions-mobile {
	display: none;
	position: relative;
}

.selection-kebab {
	all: unset;
	box-sizing: border-box;
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	cursor: pointer;
	color: var(--color-main-text);
	transition: background 0.15s;
}

.selection-kebab:hover {
	background: var(--color-background-hover);
}

.selection-kebab-menu {
	position: absolute;
	top: calc(100% + 4px);
	right: 0;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
	min-width: 220px;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.selection-kebab-menu__item {
	all: unset;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 14px;
	border-radius: var(--border-radius, 4px);
	font-size: 14px;
	cursor: pointer;
	color: var(--color-main-text);
	width: 100%;
	transition: background 0.15s;
}

.selection-kebab-menu__item:hover {
	background: var(--color-background-hover);
}

.selection-kebab-menu__item--danger {
	color: var(--color-error);
}

.selection-kebab-menu__item:disabled {
	opacity: 0.5;
	pointer-events: none;
}

/* Label im primary Button auf Mobile ausblenden → nur Icon */
@media (max-width: 680px) {
	.selection-actions-desktop {
		display: none;
	}

	.selection-actions-mobile {
		display: block;
	}

	.selection-btn-label {
		display: none;
	}
}

/* View container — fills remaining space below toolbar */
.view-page {
	flex: 1;
	min-height: 0;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

/* Make router-view children fill the view-page */
.view-page > :deep(*) {
	flex: 1;
	min-height: 0;
}

/* Album picker dialog */
.album-picker {
	min-width: 300px;
	min-height: 80px;
	max-height: 400px;
	overflow-y: auto;
	padding: 4px 0;
}

.album-picker__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.album-picker__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.album-picker__item {
	padding: 12px 16px;
	cursor: pointer;
	border-radius: var(--border-radius);
	font-size: 14px;
}

.album-picker__item:hover {
	background-color: var(--color-background-hover);
}
</style>
