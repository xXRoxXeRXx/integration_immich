<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="folders-view">
		<NcLoadingIcon v-if="store.loading && store.folderSubdirs.length === 0 && store.folderAssets.length === 0"
			:size="64"
			class="folders-view__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Sticky Header with Breadcrumbs -->
			<div class="folders-view__header">
				<NcBreadcrumbs>
					<NcBreadcrumb :name="t('integration_immich', 'Folders')"
						@click="goHome" />
					<NcBreadcrumb v-for="(crumb, index) in breadcrumbs"
						:key="crumb.path"
						:name="crumb.name"
						:title="crumb.name"
						@click="index < breadcrumbs.length - 1 ? navigateTo(crumb.path) : undefined" />
				</NcBreadcrumbs>
				<div class="folders-view__meta-row">
					<span class="folders-view__count">
						{{ summaryText }}
					</span>
					<div v-if="store.folderAssets.length > 0" class="folders-view__layout-toggle">
						<button
							class="folders-view__layout-btn"
							:class="{ 'folders-view__layout-btn--active': store.gridLayout === 'grid' }"
							:title="t('integration_immich', 'Square grid')"
							:aria-label="t('integration_immich', 'Square grid')"
							@click="store.setGridLayout('grid')">
							<ViewGridIcon :size="16" />
						</button>
						<button
							class="folders-view__layout-btn"
							:class="{ 'folders-view__layout-btn--active': store.gridLayout === 'masonry' }"
							:title="t('integration_immich', 'Masonry grid')"
							:aria-label="t('integration_immich', 'Masonry grid')"
							@click="store.setGridLayout('masonry')">
							<ViewQuiltIcon :size="16" />
						</button>
					</div>
				</div>
			</div>

			<NcEmptyContent v-if="store.folderSubdirs.length === 0 && store.folderAssets.length === 0 && !store.loading"
				:name="t('integration_immich', 'Empty folder')"
				:description="t('integration_immich', 'This folder does not contain any subfolders or photos.')">
				<template #icon>
					<FolderOpenIcon :size="64" />
				</template>
			</NcEmptyContent>

			<div v-else class="folders-view__scroll">
				<!-- Subfolders -->
				<div v-if="store.folderSubdirs.length > 0" class="folders-view__section">
					<div class="folders-view__grid">
						<div v-for="folder in store.folderSubdirs"
							:key="folder.id || folder.name"
							class="folders-view__item"
							@click="navigateTo(joinPath(store.currentFolderPath, folder.name))">
							<div class="folders-view__folder-icon">
								<FolderIcon :size="48" />
							</div>
							<span class="folders-view__folder-name">{{ folder.name }}</span>
						</div>
					</div>
				</div>

				<!-- Assets -->
				<div v-if="store.folderAssets.length > 0" class="folders-view__section">
					<PhotoGrid
						:assets="store.folderAssets"
						:selectable="true"
						:layout="store.gridLayout"
						@click="(_, idx) => store.openLightbox(store.folderAssets, idx)" />
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon, NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewQuiltIcon from 'vue-material-design-icons/ViewQuilt.vue'

const store = useImmichStore()
const route = useRoute()
const router = useRouter()

const currentPath = computed(() => {
	const p = route.query.path
	if (!p || typeof p !== 'string') return '/'
	return p.startsWith('/') ? p : '/' + p
})

/**
 * Path segments to display as breadcrumbs, relative to the auto-detected base path.
 * e.g. basePath=/usr/src/app/upload/library/user, currentPath=…/user/DCIM/Camera
 *      → [{name:'DCIM', path:'…/user/DCIM'}, {name:'Camera', path:'…/user/DCIM/Camera'}]
 */
const breadcrumbs = computed(() => {
	const current = store.currentFolderPath || currentPath.value
	const base    = store.folderBasePath    || '/'
	if (current === base) return []

	let relative = current
	if (base !== '/' && current.startsWith(base + '/')) {
		relative = current.slice(base.length) // starts with /
	}
	const parts = relative.split('/').filter(Boolean)
	const basePrefix = base === '/' ? '' : base
	return parts.map((name, i) => ({
		name,
		path: basePrefix + '/' + parts.slice(0, i + 1).join('/'),
	}))
})

const summaryText = computed(() => {
	const dirs   = store.folderSubdirs.length
	const assets = store.folderAssets.length
	if (dirs === 0 && assets === 0) return ''
	const parts = []
	if (dirs > 0)   parts.push(n('integration_immich', '{count} folder', '{count} folders', dirs,   { count: dirs }))
	if (assets > 0) parts.push(n('integration_immich', '{count} photo',  '{count} photos',  assets, { count: assets }))
	return parts.join(', ')
})

function joinPath(base, name) {
	const b = base === '/' ? '' : base
	return b + '/' + name
}

function navigateTo(path) {
	router.push({ name: 'folders', query: { path } })
}

function goHome() {
	navigateTo(store.folderBasePath || '/')
}

let skipNextLoad = false

async function load() {
	if (skipNextLoad) {
		skipNextLoad = false
		return
	}

	const path = currentPath.value

	// If we already know the base path and the user is at virtual root,
	// jump straight there without an extra network round-trip.
	if (path === '/' && store.folderBasePath && store.folderBasePath !== '/') {
		skipNextLoad = true
		router.replace({ name: 'folders', query: { path: store.folderBasePath } })
		return
	}

	await store.fetchFolderContent(path)

	// First-ever load: controller resolved the real base path → update URL silently.
	if (path === '/' && store.folderBasePath !== '/') {
		skipNextLoad = true
		router.replace({ name: 'folders', query: { path: store.folderBasePath } })
	}
}

onMounted(() => load())
watch(currentPath, load)
</script>

<style scoped>
.folders-view {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.folders-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.folders-view__header {
	display: flex;
	flex-direction: column;
	padding: 8px 16px 6px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
}

.folders-view__meta-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
	padding-left: 4px;
}

.folders-view__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.folders-view__layout-toggle {
	margin-left: auto;
	display: flex;
	gap: 2px;
}

.folders-view__layout-btn {
	all: unset;
	box-sizing: border-box;
	width: 28px;
	height: 28px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 4px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: background 0.15s, color 0.15s;
}

.folders-view__layout-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.folders-view__layout-btn--active {
	color: var(--color-primary-element);
}

.folders-view__scroll {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.folders-view__section {
	margin-bottom: 24px;
}

.folders-view__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 12px;
}

.folders-view__item {
	cursor: pointer;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 12px 8px;
	border-radius: 8px;
	transition: background 0.15s;
}

.folders-view__item:hover {
	background: var(--color-background-hover);
}

.folders-view__folder-icon {
	color: var(--color-primary-element);
	display: flex;
	align-items: center;
	justify-content: center;
}

.folders-view__folder-name {
	font-size: 13px;
	text-align: center;
	word-break: break-word;
	max-width: 100%;
}
</style>
