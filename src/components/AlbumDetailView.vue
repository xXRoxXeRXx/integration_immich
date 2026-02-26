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
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else-if="store.currentAlbum">
			<div class="album-detail__header">
				<NcButton variant="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Zurück') }}
				</NcButton>
				<div class="album-detail__title">
					<h2>{{ store.currentAlbum.albumName }}</h2>
					<span class="album-detail__count">
						{{ t('integration_immich', '{count} Bilder', { count: store.currentAlbum.assets?.length || 0 }) }}
					</span>
				</div>
				<NcButton variant="tertiary" @click="startRename">
					<template #icon>
						<PencilIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Umbenennen') }}
				</NcButton>
				<NcButton v-if="store.currentAlbum.assets && store.currentAlbum.assets.length > 0"
					variant="secondary"
					@click="showPicker = true">
					<template #icon>
						<ImagePlusIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Bilder hinzufügen') }}
				</NcButton>
			</div>

			<!-- Rename Dialog -->
			<NcDialog v-if="showRenameDialog"
				:name="t('integration_immich', 'Album umbenennen')"
				@closing="showRenameDialog = false">
				<div style="padding: 8px 0; min-width: 300px;">
					<NcTextField
						:label="t('integration_immich', 'Neuer Albumname')"
						v-model="renameValue"
						@keyup.enter="confirmRename" />
				</div>
				<template #actions>
					<NcButton variant="tertiary" @click="showRenameDialog = false">
						{{ t('integration_immich', 'Abbrechen') }}
					</NcButton>
					<NcButton variant="primary"
						:disabled="!renameValue.trim() || renaming"
						@click="confirmRename">
						<template #icon>
							<NcLoadingIcon v-if="renaming" :size="20" />
							<CheckIcon v-else :size="20" />
						</template>
						{{ t('integration_immich', 'Speichern') }}
					</NcButton>
				</template>
			</NcDialog>

			<NcEmptyContent v-if="!store.currentAlbum.assets || store.currentAlbum.assets.length === 0"
				:name="t('integration_immich', 'Album leer')"
				:description="t('integration_immich', 'Dieses Album enthält noch keine Bilder.')">
				<template #icon>
					<ImageIcon :size="64" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="showPicker = true">
						<template #icon>
							<ImagePlusIcon :size="20" />
						</template>
						{{ t('integration_immich', 'Bilder hinzufügen') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<PhotoGrid v-else
				:assets="store.currentAlbum.assets"
				:selectable="true"
				@click="(asset, idx) => store.openLightbox(store.currentAlbum.assets, idx)" />

			<!-- Asset Picker Overlay für "Bilder hinzufügen" -->
			<AssetPickerModal v-if="showPicker"
				:album-name="store.currentAlbum.albumName"
				:creating="addingAssets"
				:existing-asset-ids="existingAssetIds"
				@confirm="addAssetsToAlbum"
				@cancel="showPicker = false" />
		</template>
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
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import ImagePlusIcon from 'vue-material-design-icons/ImagePlus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

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
		showSuccess(t('integration_immich', 'Album umbenannt'))
		await Promise.all([store.fetchAlbum(props.id), store.fetchAlbums()])
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Umbenennen: {msg}', { msg: e.message }))
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
			showSuccess(t('integration_immich', '{count} Bilder zum Album hinzugefügt', { count: succeeded }))
		} else if (succeeded > 0) {
			showError(t('integration_immich', '{succeeded} hinzugefügt, {failed} fehlgeschlagen', { succeeded, failed }))
		} else {
			showError(t('integration_immich', 'Fehler beim Hinzufügen zum Album'))
		}
		await store.fetchAlbum(props.id)
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Hinzufügen: {msg}', { msg: e.message }))
	} finally {
		addingAssets.value = false
	}
}

onMounted(() => {
	loadAlbum()
})

watch(() => props.id, () => {
	loadAlbum()
})
</script>

<style scoped>
.album-detail {
	height: 100%;
	overflow-y: auto;
	box-sizing: border-box;
	padding: 16px 16px 16px 52px;
}

.album-detail__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.album-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 16px;
	padding: 0 8px;
}

.album-detail__title {
	flex: 1;
}

.album-detail__title h2 {
	margin: 0;
	font-size: 22px;
}

.album-detail__count {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}
</style>
