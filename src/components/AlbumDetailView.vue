<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="album-detail">
		<NcLoadingIcon v-if="store.loading && !store.currentAlbum"
			:size="64"
			class="album-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else-if="store.currentAlbum">
			<!-- Sticky Header -->
			<div class="album-detail__header">
				<NcButton variant="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Back') }}
				</NcButton>
				<div class="album-detail__title">
					<h2>{{ store.currentAlbum.albumName }}</h2>
					<span class="album-detail__count">
						{{ t('integration_immich', '{count} photos', { count: store.currentAlbum.assets?.length || 0 }) }}
					</span>
				</div>

				<!-- Desktop: Buttons direkt sichtbar -->
				<div class="album-detail__actions-desktop">
					<NcButton variant="tertiary" @click="startRename">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('integration_immich', 'Rename') }}
					</NcButton>
					<NcButton v-if="store.currentAlbum.assets && store.currentAlbum.assets.length > 0"
						variant="secondary"
						@click="showPicker = true">
						<template #icon>
							<ImagePlusIcon :size="20" />
						</template>
						{{ t('integration_immich', 'Add photos') }}
					</NcButton>
				</div>

				<!-- Mobile: kebab menu -->
				<div class="album-detail__actions-mobile">
					<button class="album-detail__kebab" @click.stop="headerMenuOpen = !headerMenuOpen" :aria-label="t('integration_immich', 'More actions')">
						<DotsVerticalIcon :size="20" />
					</button>
					<div v-if="headerMenuOpen" class="album-detail__kebab-menu" @click="headerMenuOpen = false">
						<button class="album-detail__kebab-item" @click="startRename">
							<PencilIcon :size="18" />
							{{ t('integration_immich', 'Rename') }}
						</button>
						<button v-if="store.currentAlbum.assets && store.currentAlbum.assets.length > 0"
							class="album-detail__kebab-item"
							@click="showPicker = true">
							<ImagePlusIcon :size="18" />
							{{ t('integration_immich', 'Add photos') }}
						</button>
					</div>
				</div>
			</div>

			<!-- Rename Dialog -->
			<NcDialog v-if="showRenameDialog"
				:name="t('integration_immich', 'Rename album')"
				@closing="showRenameDialog = false">
				<div style="padding: 8px 0; min-width: 300px;">
					<NcTextField
						:label="t('integration_immich', 'New album name')"
						v-model="renameValue"
						@keyup.enter="confirmRename" />
				</div>
				<template #actions>
					<NcButton variant="tertiary" @click="showRenameDialog = false">
						{{ t('integration_immich', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary"
						:disabled="!renameValue.trim() || renaming"
						@click="confirmRename">
						<template #icon>
							<NcLoadingIcon v-if="renaming" :size="20" />
							<CheckIcon v-else :size="20" />
						</template>
						{{ t('integration_immich', 'Save') }}
					</NcButton>
				</template>
			</NcDialog>

			<!-- Scroll-Bereich -->
			<div class="album-detail__scroll">
				<NcEmptyContent v-if="!store.currentAlbum.assets || store.currentAlbum.assets.length === 0"
					:name="t('integration_immich', 'Album is empty')"
					:description="t('integration_immich', 'This album does not contain any photos yet.')">
					<template #icon>
						<ImageIcon :size="64" />
					</template>
					<template #action>
						<NcButton variant="primary" @click="showPicker = true">
							<template #icon>
								<ImagePlusIcon :size="20" />
							</template>
							{{ t('integration_immich', 'Add photos') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<PhotoGrid v-else
					:assets="store.currentAlbum.assets"
					:selectable="true"
					@click="(_, idx) => store.openLightbox(store.currentAlbum.assets, idx)" />
			</div>

			<!-- Asset Picker Overlay für "Bilder hinzufügen" -->
			<AssetPickerModal v-if="showPicker"
				:album-name="store.currentAlbum.albumName"
				:creating="addingAssets"
				:existing-asset-ids="existingAssetIds"
				@confirm="addAssetsToAlbum"
				@cancel="showPicker = false" />
		</template>

		<!-- Selection Toolbar for removing assets from album -->
		<SelectionToolbar
			:show-remove-from-album="true"
			:album-id="props.id"
			hide-album
			@assets-removed-from-album="handleAssetsRemovedFromAlbum" />
	</div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcDialog, NcTextField } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useImmichStore } from '../store/immich.js'
import { addAssetsToAlbum as apiAddAssetsToAlbum, renameAlbum as apiRenameAlbum } from '../services/api.js'
import PhotoGrid from './PhotoGrid.vue'
import AssetPickerModal from './AssetPickerModal.vue'
import SelectionToolbar from './SelectionToolbar.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import ImagePlusIcon from 'vue-material-design-icons/ImagePlus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DotsVerticalIcon from 'vue-material-design-icons/DotsVertical.vue'

const props = defineProps({
	id: {
		type: String,
		required: true,
	},
})

const store = useImmichStore()
const router = useRouter()
const showPicker = ref(false)
const addingAssets = ref(false)
const showRenameDialog = ref(false)
const renameValue = ref('')
const renaming = ref(false)
const headerMenuOpen = ref(false)

// IDs der bereits im Album enthaltenen Assets → werden im Picker grau markiert
const existingAssetIds = computed(() =>
	new Set((store.currentAlbum?.assets ?? []).map(a => a.id))
)

function goBack() {
	router.push({ name: 'albums' })
}

function loadAlbum() {
	store.fetchAlbum(props.id)
}

function startRename() {
	renameValue.value = store.currentAlbum?.albumName ?? ''
	showRenameDialog.value = true
}

async function confirmRename() {
	if (!renameValue.value.trim() || renaming.value) return
	renaming.value = true
	try {
		await apiRenameAlbum(props.id, renameValue.value.trim())
		showRenameDialog.value = false
		showSuccess(t('integration_immich', 'Album renamed'))
		await Promise.all([store.fetchAlbum(props.id), store.fetchAlbums()])
	} catch (e) {
		showError(t('integration_immich', 'Error renaming: {msg}', { msg: e.message }))
	} finally {
		renaming.value = false
	}
}

async function addAssetsToAlbum(assetIds) {
	if (!assetIds.length) {
		showPicker.value = false
		return
	}
	addingAssets.value = true
	try {
		const response = await apiAddAssetsToAlbum(props.id, assetIds)
		const results = response.data ?? []
		const succeeded = results.filter(r => r.success !== false).length
		const failed = results.length - succeeded
		showPicker.value = false
		if (failed === 0) {
			showSuccess(t('integration_immich', '{count} photos added to album', { count: succeeded }))
		} else if (succeeded > 0) {
			showError(t('integration_immich', '{succeeded} added, {failed} failed', { succeeded, failed }))
		} else {
			showError(t('integration_immich', 'Error adding to album'))
		}
		await store.fetchAlbum(props.id)
	} catch (e) {
		showError(t('integration_immich', 'Error adding: {msg}', { msg: e.message }))
	} finally {
		addingAssets.value = false
	}
}

function handleAssetsRemovedFromAlbum(removedIds) {
	// Refresh the album to show updated asset list
	store.fetchAlbum(props.id)
}

onMounted(() => {
	loadAlbum()
})

watch(() => props.id, () => {
	headerMenuOpen.value = false
	loadAlbum()
})
</script>

<style scoped>
.album-detail {
	height: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.album-detail__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.album-detail__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
}

.album-detail__title {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.album-detail__title h2 {
	margin: 0;
	font-size: 20px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.album-detail__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.album-detail__scroll {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	box-sizing: border-box;
}

/* Desktop: Buttons direkt sichtbar */
.album-detail__actions-desktop {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

/* Mobile: 3-Punkte-Menü versteckt auf Desktop */
.album-detail__actions-mobile {
	display: none;
	position: relative;
	flex-shrink: 0;
}

.album-detail__kebab {
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

.album-detail__kebab:hover {
	background: var(--color-background-hover);
}

.album-detail__kebab-menu {
	position: absolute;
	top: calc(100% + 4px);
	right: 0;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
	min-width: 200px;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.album-detail__kebab-item {
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

.album-detail__kebab-item:hover {
	background: var(--color-background-hover);
}

@media (max-width: 680px) {
	.album-detail__header {
		padding: 8px;
	}

	.album-detail__scroll {
		padding: 8px;
	}

	.album-detail__actions-desktop {
		display: none;
	}

	.album-detail__actions-mobile {
		display: block;
	}

	.album-detail__title h2 {
		font-size: 17px;
	}
}
</style>
