<!--
  - SPDX-FileCopyrightText: 202					<NcButton v-if="totalCount > 0"
						variant="secondary"
						@click="showPicker = true">arcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="album-detail">
		<NcLoadingIcon v-if="store.loading && !store.currentAlbum && store.albumBuckets.length === 0"
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
				<!-- Top row: Breadcrumb + action buttons -->
				<div class="album-detail__header-row">
					<NcBreadcrumbs>
						<NcBreadcrumb :name="t('integration_immich', 'Albums')" @click="goBack" />
						<NcBreadcrumb :name="store.currentAlbum.albumName" :title="store.currentAlbum.albumName" />
					</NcBreadcrumbs>

					<!-- Desktop: Buttons direkt sichtbar -->
					<div class="album-detail__actions-desktop">
						<NcButton v-if="canAdmin" variant="tertiary" @click="startRename">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('integration_immich', 'Rename') }}
						</NcButton>
						<NcButton v-if="canEdit && store.currentAlbum.assets && store.currentAlbum.assets.length > 0"
							variant="secondary"
							@click="showPicker = true">
							<template #icon>
								<ImagePlusIcon :size="20" />
							</template>
							{{ t('integration_immich', 'Add photos') }}
						</NcButton>
					</div>

					<!-- Mobile: NcActions dropdown -->
					<div class="album-detail__actions-mobile">
						<NcActions :aria-label="t('integration_immich', 'More actions')">
							<NcActionButton v-if="canAdmin" @click="startRename">
								<template #icon>
									<PencilIcon :size="20" />
								</template>
								{{ t('integration_immich', 'Rename') }}
							</NcActionButton>
							<NcActionButton v-if="canEdit && totalCount > 0"
								@click="showPicker = true">
								<template #icon>
									<ImagePlusIcon :size="20" />
								</template>
								{{ t('integration_immich', 'Add photos') }}
							</NcActionButton>
						</NcActions>
					</div>
				</div>
				<!-- Photo count + layout toggle below breadcrumb -->
				<div class="album-detail__meta-row">
					<span class="album-detail__count">
						{{ n('integration_immich', '{count} photo', '{count} photos', totalCount, { count: totalCount }) }}
					</span>
					<div class="album-detail__layout-toggle">
						<button
							class="album-detail__layout-btn"
							:class="{ 'album-detail__layout-btn--active': store.gridLayout === 'grid' }"
							:title="t('integration_immich', 'Square grid')"
							:aria-label="t('integration_immich', 'Square grid')"
							@click="store.setGridLayout('grid')">
							<ViewGridIcon :size="16" />
						</button>
						<button
							class="album-detail__layout-btn"
							:class="{ 'album-detail__layout-btn--active': store.gridLayout === 'masonry' }"
							:title="t('integration_immich', 'Masonry grid')"
							:aria-label="t('integration_immich', 'Masonry grid')"
							@click="store.setGridLayout('masonry')">
							<ViewQuiltIcon :size="16" />
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
			<NcEmptyContent v-if="store.albumBuckets.length === 0 && !store.loading"
				:name="t('integration_immich', 'Album is empty')"
				:description="t('integration_immich', 'This album does not contain any photos yet.')">
				<template #icon>
					<ImageIcon :size="64" />
				</template>
				<template #action>
					<NcButton v-if="canEdit" variant="primary" @click="showPicker = true">
						<template #icon>
							<ImagePlusIcon :size="20" />
						</template>
						{{ t('integration_immich', 'Add photos') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<div v-else
				ref="scrollContainer"
				class="album-detail__scroll"
				@scroll="onScroll">
				<!-- Sticky date bar -->
				<div class="album-detail__sticky-date">
					<span class="album-detail__sticky-label">{{ currentBucketLabel }}</span>
					<span v-if="currentBucketCount" class="album-detail__sticky-count">{{ currentBucketCount }}</span>
				</div>

				<div class="album-detail__runway" :style="{ height: totalHeight + 'px' }">
					<div v-for="index in windowIndices"
						:key="store.albumBuckets[index].timeBucket"
						class="album-detail__bucket"
						:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
						<NcLoadingIcon v-if="loadingSet.has(store.albumBuckets[index].timeBucket)"
							:size="32"
							class="album-detail__bucket-loading" />
						<PhotoGrid v-else-if="store.albumBucketAssets[store.albumBuckets[index].timeBucket]"
							:assets="store.albumBucketAssets[store.albumBuckets[index].timeBucket]"
							:selectable="true"
							:layout="store.gridLayout"
							@click="(_, idx) => openLightboxFromBucket(idx, index)" />
						<div v-else
							class="album-detail__bucket-placeholder"
							:style="{ height: (bucketHeights[index] - HEADER_HEIGHT) + 'px' }" />
					</div>
				</div>
			</div>

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
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcDialog, NcTextField, NcActions, NcActionButton, NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useImmichStore } from '../store/immich.js'
import { addAssetsToAlbum as apiAddAssetsToAlbum, renameAlbum as apiRenameAlbum } from '../services/api.js'
import PhotoGrid from './PhotoGrid.vue'
import AssetPickerModal from './AssetPickerModal.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import ImagePlusIcon from 'vue-material-design-icons/ImagePlus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewQuiltIcon from 'vue-material-design-icons/ViewQuilt.vue'

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

/**
 * The current Immich user's role in this album.
 * We find the entry whose user.id matches the logged-in user.
 * Fallback when the user is not in albumUsers (old Immich / unshared album):
 * unshared albums are always owned by the current user, shared ones fall back
 * to 'viewer' to err on the side of restricting write access.
 * Falls back to 'owner' when currentUserId is not yet known so buttons remain
 * visible until the check resolves.
 */
const myRole = computed(() => {
	if (!store.currentUserId || !store.currentAlbum?.albumUsers) return 'owner'
	const entry = store.currentAlbum.albumUsers.find(u => u.user?.id === store.currentUserId)
	if (entry !== undefined) return entry.role
	// User not found in albumUsers (older Immich API or empty array):
	// unshared albums belong to the current user → 'owner'.
	return store.currentAlbum.shared ? 'viewer' : 'owner'
})

const canEdit  = computed(() => myRole.value === 'owner' || myRole.value === 'editor')
const canAdmin = computed(() => myRole.value === 'owner')

// --- Constants (same pattern as PersonDetailView) ---
const HEADER_HEIGHT = 40
const GRID_MIN_ITEM = 180
const GRID_GAP = 3
const BUCKET_PADDING_LR = 32
const OVERSCAN = 800
const MAX_CONCURRENT = 2
const MAX_LOADED_BUCKETS = 12
const MASONRY_DEFAULT_RATIO = 1.0

// --- Reactive state ---
const scrollContainer = ref(null)
const scrollTop = ref(0)
const viewportHeight = ref(800)
const containerWidth = ref(0)
const loadingSet = ref(new Set())

let activeRequests = 0
const pendingQueue = []
let resizeObserver = null

// --- Computed ---
const totalCount = computed(() =>
	store.albumBuckets.reduce((sum, b) => sum + (b.count || 0), 0),
)

// IDs der bereits im Album enthaltenen Assets → werden im Picker grau markiert
const existingAssetIds = computed(() =>
	new Set((store.currentAlbum?.assets ?? []).map(a => a.id))
)

function estimateBucketHeight(count) {
	const available = Math.max(GRID_MIN_ITEM, containerWidth.value - BUCKET_PADDING_LR)
	const cols = Math.max(1, Math.floor((available + GRID_GAP) / (GRID_MIN_ITEM + GRID_GAP)))
	const colWidth = (available - (cols - 1) * GRID_GAP) / cols
	const rows = Math.ceil(count / cols)
	return HEADER_HEIGHT + rows * colWidth + (rows - 1) * GRID_GAP
}

function estimateBucketHeightMasonry(count, assets = null) {
	const available = Math.max(GRID_MIN_ITEM, containerWidth.value - BUCKET_PADDING_LR)
	const cols = Math.max(1, Math.floor((available + GRID_GAP) / (GRID_MIN_ITEM + GRID_GAP)))
	const colWidth = (available - (cols - 1) * GRID_GAP) / cols

	if (assets && assets.length > 0) {
		const columnHeights = new Array(cols).fill(0)
		for (const asset of assets) {
			const ratio = (asset.ratio > 0) ? asset.ratio : MASONRY_DEFAULT_RATIO
			const itemHeight = colWidth / ratio
			let minIdx = 0
			for (let i = 1; i < columnHeights.length; i++) {
				if (columnHeights[i] < columnHeights[minIdx]) minIdx = i
			}
			columnHeights[minIdx] += itemHeight + GRID_GAP
		}
		return HEADER_HEIGHT + Math.max(...columnHeights)
	}

	const itemHeight = colWidth / MASONRY_DEFAULT_RATIO
	const rows = Math.ceil(count / cols)
	return HEADER_HEIGHT + rows * (itemHeight + GRID_GAP)
}

const bucketHeights = computed(() =>
	store.albumBuckets.map(b => {
		if (store.gridLayout === 'masonry') {
			return estimateBucketHeightMasonry(b.count, store.albumBucketAssets[b.timeBucket])
		}
		return estimateBucketHeight(b.count)
	}),
)

const bucketOffsets = computed(() => {
	const offsets = []
	let cumulative = 0
	for (let i = 0; i < bucketHeights.value.length; i++) {
		offsets.push(cumulative)
		cumulative += bucketHeights.value[i]
	}
	return offsets
})

const totalHeight = computed(() => {
	const h = bucketHeights.value
	if (h.length === 0) return 0
	return bucketOffsets.value[h.length - 1] + h[h.length - 1]
})

const windowIndices = computed(() => {
	if (store.albumBuckets.length === 0) return []
	const top = scrollTop.value - OVERSCAN
	const bottom = scrollTop.value + viewportHeight.value + OVERSCAN
	const indices = []
	for (let i = 0; i < store.albumBuckets.length; i++) {
		const bTop = bucketOffsets.value[i]
		const bBottom = bTop + bucketHeights.value[i]
		if (bBottom >= top && bTop <= bottom) indices.push(i)
		if (bTop > bottom) break
	}
	return indices
})

// --- Scroll handler ---
let scrollRaf = null
function onScroll() {
	if (scrollRaf) return
	scrollRaf = requestAnimationFrame(() => {
		if (scrollContainer.value) {
			scrollTop.value = scrollContainer.value.scrollTop
			viewportHeight.value = scrollContainer.value.clientHeight
		}
		scrollRaf = null
	})
}

// --- Sticky date label (which bucket is at top of viewport) ---
const currentBucket = computed(() => {
	if (store.albumBuckets.length === 0) return null
	let idx = 0
	for (let i = 0; i < bucketOffsets.value.length; i++) {
		if (bucketOffsets.value[i] <= scrollTop.value) idx = i
		else break
	}
	return store.albumBuckets[idx]
})

const currentBucketLabel = computed(() =>
	currentBucket.value ? formatBucketDate(currentBucket.value.timeBucket) : ''
)

const currentBucketCount = computed(() =>
	currentBucket.value?.count ?? 0
)

// --- Lazy bucket loading ---
async function loadBucket(timeBucket) {
	if (store.albumBucketAssets[timeBucket] || loadingSet.value.has(timeBucket)) return

	if (activeRequests >= MAX_CONCURRENT) {
		return new Promise(resolve => {
			pendingQueue.push(() => loadBucket(timeBucket).then(resolve))
		})
	}

	activeRequests++
	loadingSet.value = new Set([...loadingSet.value, timeBucket])
	try {
		await store.fetchAlbumBucketAssets(props.id, timeBucket)
	} finally {
		loadingSet.value = new Set([...loadingSet.value].filter(b => b !== timeBucket))
		activeRequests--
		if (pendingQueue.length > 0) pendingQueue.shift()()
	}
}

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(store.albumBucketAssets)
	if (loadedKeys.length <= MAX_LOADED_BUCKETS) return
	const visibleKeys = new Set(currentIndices.map(i => store.albumBuckets[i].timeBucket))
	for (const key of loadedKeys) {
		if (visibleKeys.has(key)) continue
		if (Object.keys(store.albumBucketAssets).length <= MAX_LOADED_BUCKETS) break
		store.unloadAlbumBucketAsset(key)
	}
}

watch(windowIndices, (indices) => {
	for (const i of indices) {
		const bucket = store.albumBuckets[i]
		if (bucket && !store.albumBucketAssets[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket)
		}
	}
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const allAssets = []
	let globalIdx = 0
	for (let i = 0; i < store.albumBuckets.length; i++) {
		const bucketAssets = store.albumBucketAssets[store.albumBuckets[i].timeBucket]
		if (!bucketAssets) continue
		if (i === bucketIndex) globalIdx = allAssets.length + localIdx
		allAssets.push(...bucketAssets)
	}
	store.openLightbox(allAssets, globalIdx)
}

function formatBucketDate(timeBucket) {
	const date = new Date(timeBucket)
	return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
}

function goBack() {
	router.push({ name: 'albums' })
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
			showSuccess(n('integration_immich', '{count} photo added to album', '{count} photos added to album', succeeded, { count: succeeded }))
		} else if (succeeded > 0) {
			showError(t('integration_immich', '{succeeded} added, {failed} failed', { succeeded, failed }))
		} else {
			showError(t('integration_immich', 'Error adding to album'))
		}
		await Promise.all([store.fetchAlbum(props.id), store.fetchAlbumBuckets(props.id)])
	} catch (e) {
		showError(t('integration_immich', 'Error adding: {msg}', { msg: e.message }))
	} finally {
		addingAssets.value = false
	}
}

watch(scrollContainer, (el) => {
	resizeObserver?.disconnect()
	resizeObserver = null
	if (el) {
		containerWidth.value = el.clientWidth
		viewportHeight.value = el.clientHeight
		resizeObserver = new ResizeObserver(([entry]) => {
			containerWidth.value = entry.contentRect.width
			viewportHeight.value = entry.contentRect.height
		})
		resizeObserver.observe(el)
	}
})

async function load() {
	await Promise.all([
		store.fetchAlbum(props.id),
		store.fetchAlbumBuckets(props.id),
	])
}

onMounted(() => {
	load()
})

watch(() => props.id, () => {
	if (scrollContainer.value) scrollContainer.value.scrollTop = 0
	scrollTop.value = 0
	pendingQueue.length = 0
	activeRequests = 0
	load()
})

onBeforeUnmount(() => {
	if (scrollRaf) cancelAnimationFrame(scrollRaf)
	resizeObserver?.disconnect()
	pendingQueue.length = 0
	activeRequests = 0
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
	flex-direction: column;
	padding: 8px 16px 6px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
}

.album-detail__header-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.album-detail__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding-left: 4px;
	margin-top: 2px;
}

/* Row combining photo count + layout toggle */
.album-detail__meta-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
}

.album-detail__layout-toggle {
	margin-left: auto;
	display: flex;
	gap: 2px;
}

.album-detail__layout-btn {
	all: unset;
	box-sizing: border-box;
	width: 28px;
	height: 28px;
	border-radius: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: color 0.15s ease, background 0.15s ease;
}

.album-detail__layout-btn:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.album-detail__layout-btn:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.album-detail__layout-btn--active {
	color: var(--color-primary);
	background: var(--color-primary-element-light);
}

.album-detail__scroll {
	flex: 1;
	overflow-y: auto;
	position: relative;
}

.album-detail__sticky-date {
	position: sticky;
	top: 0;
	z-index: 10;
	padding: 7px 16px;
	display: flex;
	align-items: center;
	gap: 8px;
	background: var(--color-main-background);
	pointer-events: none;
	border-bottom: 1px solid var(--color-border-dark);
}

.album-detail__sticky-label {
	font-size: 13px;
	font-weight: 600;
	letter-spacing: 0.01em;
	color: var(--color-main-text);
}

.album-detail__sticky-count {
	font-size: 11px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	border-radius: 20px;
	padding: 1px 7px;
}

.album-detail__runway {
	position: relative;
	width: 100%;
}

.album-detail__bucket {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	padding: 15px 16px 0;
}

.album-detail__bucket-loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.album-detail__bucket-placeholder {
	background: var(--color-background-dark);
	border-radius: 8px;
	opacity: 0.15;
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

@media (max-width: 680px) {
	.album-detail__header {
		padding: 8px 8px 6px;
	}

	.album-detail__bucket {
		padding: 0 8px;
	}

	.album-detail__actions-desktop {
		display: none;
	}

	.album-detail__actions-mobile {
		display: block;
	}
}
</style>
