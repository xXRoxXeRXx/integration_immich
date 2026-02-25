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
							{{ t('integration_immich', 'Auswählen') }}
						</NcButton>
					</template>

					<!-- Selection mode: count + action buttons -->
					<template v-else>
						<span class="view-toolbar__selection-count">
							{{ t('integration_immich', '{count} ausgewählt', { count: store.selectedAssetIds.size }) }}
						</span>
						<div class="view-toolbar__selection-actions">
							<NcButton variant="primary"
								:disabled="store.selectedAssetIds.size === 0 || saving"
								@click="saveToNextcloud">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSaveIcon v-else :size="20" />
								</template>
								{{ t('integration_immich', 'In Nextcloud speichern') }}
							</NcButton>
							<NcButton variant="tertiary" @click="store.clearSelection()">
								{{ t('integration_immich', 'Abbrechen') }}
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
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { NcContent, NcAppContent, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError, getFilePickerBuilder, FilePickerClosed } from '@nextcloud/dialogs'
import { useImmichStore } from './store/immich.js'
import { saveAssetsToNextcloud } from './services/api.js'
import Navigation from './components/Navigation.vue'
import LightboxView from './components/LightboxView.vue'
import CheckboxMultipleOutlineIcon from 'vue-material-design-icons/CheckboxMultipleOutline.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'

const route = useRoute()
const store = useImmichStore()
const saving = ref(false)

const pageTitles = {
	'timeline': t('integration_immich', 'Alle Bilder'),
	'albums': t('integration_immich', 'Alben'),
	'album-detail': t('integration_immich', 'Alben'),
	'people': t('integration_immich', 'Personen'),
	'person-detail': t('integration_immich', 'Personen'),
	'map': t('integration_immich', 'Karte'),
	'explore': t('integration_immich', 'Erkunden'),
	'place-detail': t('integration_immich', 'Erkunden'),
}

// Views that contain individual selectable assets (photos/videos)
const photoViews = new Set(['timeline', 'album-detail', 'person-detail', 'place-detail'])

const pageTitle = computed(() => pageTitles[route.name] ?? 'Immich')
const isPhotoView = computed(() => photoViews.has(route.name))

// Clear selection when navigating to a different view
watch(() => route.name, () => {
	if (store.isSelectionMode) {
		store.clearSelection()
	}
})

async function saveToNextcloud() {
	const picker = getFilePickerBuilder(t('integration_immich', 'Speicherort in Nextcloud wählen'))
		.setMultiSelect(false)
		.allowDirectories(true)
		.addButton({
			label: t('integration_immich', 'Hier speichern'),
			type: 'primary',
			callback: () => {},
		})
		.build()

	let path
	try {
		path = await picker.pick()
	} catch (e) {
		if (!(e instanceof FilePickerClosed)) {
			showError(t('integration_immich', 'Fehler beim Öffnen des Ordner-Dialogs'))
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
			showSuccess(t('integration_immich', '{count} Datei(en) in Nextcloud gespeichert', { count: saved }))
		} else if (saved > 0) {
			showError(t('integration_immich', '{saved} gespeichert, {failed} fehlgeschlagen', { saved, failed }))
		} else {
			showError(t('integration_immich', 'Speichern fehlgeschlagen'))
		}
		store.clearSelection()
	} catch (e) {
		showError(t('integration_immich', 'Fehler beim Speichern: {msg}', { msg: e.message }))
	} finally {
		saving.value = false
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

/* View container — fills remaining space below toolbar */
.view-page {
	flex: 1;
	min-height: 0;
	overflow: hidden;
}
</style>
