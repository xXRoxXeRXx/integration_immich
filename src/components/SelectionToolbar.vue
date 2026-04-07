<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Transition name="toolbar-slide">
		<div v-if="store.isSelectionMode" class="selection-toolbar">
			<div class="selection-toolbar__content">
				<button class="selection-toolbar__btn selection-toolbar__btn--close" @click="cancel">
					<svg viewBox="0 0 24 24" aria-hidden="true">
						<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
					</svg>
				</button>
				<span class="selection-toolbar__count">{{ t('integration_immich', '{count} selected', { count: store.selectedAssetIds.size }) }}</span>
				<div class="selection-toolbar__actions">
					<button
						v-if="!hideAlbum"
						class="selection-toolbar__btn"
						:title="t('integration_immich', 'Add to album')"
						:disabled="addingToAlbum"
						@click="addToAlbum">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M20 6h-8l-2-2H4c-1.11 0-2 .89-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2m-1 8h-3v3h-2v-3h-3v-2h3V9h2v3h3z" />
						</svg>
						<span v-if="addingToAlbum" class="selection-toolbar__spinner"></span>
					</button>
					<button
						v-if="!hideFavorite"
						class="selection-toolbar__btn"
						:title="t('integration_immich', 'Add to favorites')"
						:disabled="togglingFavorite"
						@click="toggleFavorites">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54z" fill="currentColor" />
						</svg>
						<span v-if="togglingFavorite" class="selection-toolbar__spinner"></span>
					</button>
					<button
						class="selection-toolbar__btn"
						:title="t('integration_immich', 'Save to Nextcloud')"
						:disabled="saving"
						@click="saveToNextcloud">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M15 9H5V5h10m-3 14a3 3 0 0 1-3-3 3 3 0 0 1 3-3 3 3 0 0 1 3 3 3 3 0 0 1-3 3m5-16H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7z" />
						</svg>
						<span v-if="saving" class="selection-toolbar__spinner"></span>
					</button>
					<button
						class="selection-toolbar__btn"
						:title="t('integration_immich', 'Download')"
						:disabled="downloading"
						@click="download">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M5 20h14v-2H5m14-9h-4V3H9v6H5l7 7z" />
						</svg>
						<span v-if="downloading" class="selection-toolbar__spinner"></span>
					</button>
					<button
						v-if="showRemoveFromAlbum"
						class="selection-toolbar__btn selection-toolbar__btn--danger"
						:title="t('integration_immich', 'Remove from album')"
						:disabled="removingFromAlbum"
						@click="removeFromAlbum">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3M7 6h10v13H7z" />
						</svg>
						<span v-if="removingFromAlbum" class="selection-toolbar__spinner"></span>
					</button>
					<button
						v-if="!showRemoveFromAlbum"
						class="selection-toolbar__btn selection-toolbar__btn--danger"
						:title="t('integration_immich', 'Delete')"
						:disabled="deleting"
						@click="deleteAssets">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<path d="M19 4h-3.5l-1-1h-5l-1 1H5v2h14M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6z" />
						</svg>
						<span v-if="deleting" class="selection-toolbar__spinner"></span>
					</button>
				</div>
			</div>
		</div>
	</Transition>
</template>

<script setup>
import { ref } from 'vue'
import { useImmichStore } from '../store/immich.js'
import { downloadAssets as downloadAssetsApi, saveAssetsToNextcloud, updateAsset, removeAssetsFromAlbum as removeAssetsFromAlbumApi, deleteAssets as deleteAssetsApi } from '../services/api.js'
import { showSuccess, showError, getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

const props = defineProps({
	hideAlbum: { type: Boolean, default: false },
	hideFavorite: { type: Boolean, default: false },
	showRemoveFromAlbum: { type: Boolean, default: false },
	albumId: { type: String, default: null },
})

const emit = defineEmits(['album-picker', 'assets-deleted', 'assets-removed-from-album'])

const store = useImmichStore()
const addingToAlbum = ref(false)
const togglingFavorite = ref(false)
const saving = ref(false)
const downloading = ref(false)
const removingFromAlbum = ref(false)
const deleting = ref(false)

function cancel() {
	store.clearSelection()
}

function addToAlbum() {
	emit('album-picker')
}

async function toggleFavorites() {
	if (togglingFavorite.value || store.selectedAssetIds.size === 0) return
	togglingFavorite.value = true
	const ids = Array.from(store.selectedAssetIds)
	try {
		// For simplicity, always add to favorites (can be enhanced later)
		await Promise.all(ids.map(id => updateAsset(id, { isFavorite: true })))
		store.patchAssetFavorite(ids, true)
		showSuccess(t('integration_immich', '{count} asset(s) added to favorites', { count: ids.length }))
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error updating favorites: {msg}', { msg: e.message }))
	} finally {
		togglingFavorite.value = false
	}
}

async function saveToNextcloud() {
	if (saving.value || store.selectedAssetIds.size === 0) return

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
	const ids = Array.from(store.selectedAssetIds)
	try {
		const response = await saveAssetsToNextcloud(ids, path)
		const { saved, failed } = response.data
		if (failed === 0) {
			showSuccess(t('integration_immich', '{count} file(s) saved to Nextcloud', { count: saved }))
		} else {
			showError(t('integration_immich', '{saved} saved, {failed} failed', { saved, failed }))
		}
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error saving: {msg}', { msg: e.message }))
	} finally {
		saving.value = false
	}
}

async function download() {
	if (downloading.value || store.selectedAssetIds.size === 0) return
	downloading.value = true
	const ids = Array.from(store.selectedAssetIds)
	try {
		const response = await downloadAssetsApi(ids)
		const disposition = response.headers?.['content-disposition'] ?? ''
		let fileName = ids.length === 1 ? 'immich-download.bin' : 'immich-download.zip'
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
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error downloading: {msg}', { msg: e.message }))
	} finally {
		downloading.value = false
	}
}

async function removeFromAlbum() {
	if (removingFromAlbum.value || store.selectedAssetIds.size === 0 || !props.albumId) return

	const confirmed = await new Promise((resolve) => {
		OC.dialogs.confirm(
			t('integration_immich', 'Are you sure you want to remove {count} asset(s) from this album?', { count: store.selectedAssetIds.size }),
			t('integration_immich', 'Remove from album'),
			(result) => resolve(result),
			true
		)
	})

	if (!confirmed) return

	removingFromAlbum.value = true
	const ids = Array.from(store.selectedAssetIds)
	try {
		await removeAssetsFromAlbumApi(props.albumId, ids)
		showSuccess(t('integration_immich', '{count} asset(s) removed from album', { count: ids.length }))
		emit('assets-removed-from-album', ids)
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error removing: {msg}', { msg: e.message }))
	} finally {
		removingFromAlbum.value = false
	}
}

async function deleteAssets() {
	if (deleting.value || store.selectedAssetIds.size === 0) return

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
		await deleteAssetsApi(ids)
		showSuccess(t('integration_immich', '{count} asset(s) deleted', { count: ids.length }))
		emit('assets-deleted', ids)
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Error deleting assets: {msg}', { msg: e.response?.data?.error || e.message }))
	} finally {
		deleting.value = false
	}
}
</script>

<style scoped>
.selection-toolbar {
	position: fixed;
	bottom: 0;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
	box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
	z-index: 1000;
	padding: 12px 16px;
}

.selection-toolbar__content {
	max-width: 1200px;
	margin: 0 auto;
	display: flex;
	align-items: center;
	gap: 16px;
}

.selection-toolbar__btn {
	all: unset;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-background-hover);
	cursor: pointer;
	transition: background 0.15s, transform 0.15s;
	position: relative;
}

.selection-toolbar__btn:hover:not(:disabled) {
	background: var(--color-primary-element-light);
	transform: scale(1.05);
}

.selection-toolbar__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.selection-toolbar__btn--close {
	background: transparent;
}

.selection-toolbar__btn--danger:hover:not(:disabled) {
	background: var(--color-error);
	color: white;
}

.selection-toolbar__btn svg {
	width: 20px;
	height: 20px;
	fill: currentColor;
}

.selection-toolbar__count {
	font-weight: 600;
	font-size: 15px;
	color: var(--color-main-text);
	margin-right: auto;
}

.selection-toolbar__actions {
	display: flex;
	gap: 8px;
}

.selection-toolbar__spinner {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
}

.selection-toolbar__spinner::after {
	content: '';
	width: 20px;
	height: 20px;
	border: 2px solid transparent;
	border-top-color: currentColor;
	border-radius: 50%;
	animation: spin 0.6s linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.toolbar-slide-enter-active,
.toolbar-slide-leave-active {
	transition: transform 0.3s ease, opacity 0.3s ease;
}

.toolbar-slide-enter-from,
.toolbar-slide-leave-to {
	transform: translateY(100%);
	opacity: 0;
}
</style>
