<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="album-detail">
		<NcLoadingIcon v-if="!currentAlbumDetails && !store.error"
			:size="64"
			class="album-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else-if="currentAlbumDetails">
			<!-- Sticky Header -->
			<div class="album-detail__header">
				<!-- Top row: Breadcrumb + action buttons -->
				<div class="album-detail__header-row">
					<NcBreadcrumbs>
						<NcBreadcrumb :name="t('integration_immich', 'Albums')" @click="goBack" />
						<NcBreadcrumb :name="currentAlbumDetails.albumName" :title="currentAlbumDetails.albumName" />
					</NcBreadcrumbs>

					<!-- Desktop: Buttons direkt sichtbar -->
					<div class="album-detail__actions-desktop">
						<NcButton v-if="canAdmin" variant="tertiary" @click="startRename">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('integration_immich', 'Rename') }}
						</NcButton>
						<NcButton v-if="canEdit && totalCount > 0"
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
			<NcEmptyContent v-if="store.albumBuckets.length === 0 && !store.albumBucketsLoading"
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
				<div ref="stickyDate" class="album-detail__sticky-date">
					<span class="album-detail__sticky-label">{{ currentBucketLabel }}</span>
					<span v-if="currentBucketCount" class="album-detail__sticky-count">{{ currentBucketCount }}</span>
				</div>

				<div class="album-detail__runway" :style="{ height: totalHeight + 'px' }">
					<div v-for="index in windowIndices"
						:key="store.albumBuckets[index].timeBucket"
						class="album-detail__bucket"
						:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
						<div class="album-detail__bucket-header">
							<span class="album-detail__bucket-label">{{ formatBucketDate(store.albumBuckets[index].timeBucket) }}</span>
							<span class="album-detail__bucket-count">{{ store.albumBuckets[index].count }}</span>
							<button class="album-detail__select-bucket"
								:title="t('integration_immich', 'Select all photos in this month')"
								:aria-label="t('integration_immich', 'Select all photos in this month')"
								@click.stop="selectBucket(index)">
								<CheckboxMultipleOutlineIcon :size="18" />
							</button>
						</div>
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
							:style="{ height: Math.max(0, bucketHeights[index] - bucketGeometry.verticalChrome) + 'px' }" />
					</div>
				</div>
			</div>

			<!-- Asset Picker Overlay für "Bilder hinzufügen" -->
			<AssetPickerModal v-if="showPicker"
				:album-name="currentAlbumDetails.albumName"
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
import { useVirtualScroll } from '../composables/useVirtualScroll.js'
import { BucketRequestScheduler } from '../services/BucketRequestScheduler.js'
import { addAssetsToAlbum as apiAddAssetsToAlbum, renameAlbum as apiRenameAlbum } from '../services/api.js'
import {
	estimateGridBucketHeight,
	estimateMasonryBucketHeight,
	getBucketIndexAtOffset,
	getCumulativeOffsets,
	evictDistantBuckets as evictLoadedBuckets,
	getLightboxAssetsInBucketOrder,
	getResponsiveBucketGeometry,
	getTotalHeight,
	getWindowPriority,
	getVirtualWindowIndices,
} from '../services/virtualScrollGeometry.js'
import PhotoGrid from './PhotoGrid.vue'
import AssetPickerModal from './AssetPickerModal.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import ImagePlusIcon from 'vue-material-design-icons/ImagePlus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckboxMultipleOutlineIcon from 'vue-material-design-icons/CheckboxMultipleOutline.vue'
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
const currentAlbumDetails = computed(() =>
	store.currentAlbumDetailId === props.id ? store.currentAlbum : null,
)

/**
 * The current Immich user's role in this album.
 * v2: owner is identified via album.ownerId / album.owner.id (never in albumUsers).
 * v3: ownerId/owner were removed; the owner is now in albumUsers with role 'owner'.
 */
const myRole = computed(() => {
	if (!store.currentUserId || !currentAlbumDetails.value) return 'viewer'
	const album = currentAlbumDetails.value
	// v2: check ownerId / owner.id directly
	if (album.ownerId === store.currentUserId
		|| album.owner?.id === store.currentUserId) return 'owner'
	// v3 (and v2 fallback): role is in albumUsers
	const entry = album.albumUsers?.find(u => u.user?.id === store.currentUserId)
	if (entry !== undefined) return entry.role
	return 'viewer'
})

const canEdit  = computed(() => myRole.value === 'owner' || myRole.value === 'editor')
const canAdmin = computed(() => myRole.value === 'owner')

// --- View-specific cache policy ---
const MAX_LOADED_BUCKETS = 12

// --- Reactive state ---
const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
scheduler.setDataset(`album:${props.id}`)
const loadingSet = scheduler.loadingKeys
const stickyDate = ref(null)
const {
	scrollContainer,
	scrollTop,
	viewportHeight,
	containerWidth,
	viewportWidth,
	stickyOffset,
	onScroll,
	resetScroll,
} = useVirtualScroll({ stickyElement: stickyDate })

// --- Computed ---
const totalCount = computed(() =>
	store.albumBuckets.reduce((sum, b) => sum + (b.count || 0), 0),
)

// IDs der bereits im Album enthaltenen Assets → werden im Picker grau markiert
// v2: album.assets was part of AlbumResponseDto (removed in v3).
// v3 fallback: collect IDs from the already-loaded album timeline bucket assets.
const existingAssetIds = computed(() => {
	if (currentAlbumDetails.value?.assets) {
		return new Set(currentAlbumDetails.value.assets.map(a => a.id))
	}
	const ids = new Set()
	for (const assets of Object.values(store.albumBucketAssets)) {
		for (const a of assets) ids.add(a.id)
	}
	return ids
})

const bucketGeometry = computed(() => getResponsiveBucketGeometry({
	containerWidth: containerWidth.value,
	viewportWidth: viewportWidth.value,
	gridBreakpoint: 480,
	bucketPaddingBreakpoint: 680,
	desktopVerticalChrome: 55,
	mobileVerticalChrome: 40,
}))

const bucketHeights = computed(() =>
	store.albumBuckets.map(b => {
		if (store.gridLayout === 'masonry') {
			return estimateMasonryBucketHeight(b.count, store.albumBucketAssets[b.timeBucket], bucketGeometry.value)
		}
		return estimateGridBucketHeight(b.count, bucketGeometry.value)
	}),
)

const bucketOffsets = computed(() => getCumulativeOffsets(bucketHeights.value))
const totalHeight = computed(() => getTotalHeight(bucketHeights.value, bucketOffsets.value))
const windowIndices = computed(() => getVirtualWindowIndices(
	bucketHeights.value,
	bucketOffsets.value,
	scrollTop.value,
	viewportHeight.value,
	800,
	stickyOffset.value,
))

// --- Sticky date label (which bucket is at top of viewport) ---
const currentBucket = computed(() => {
	if (store.albumBuckets.length === 0) return null
	return store.albumBuckets[getBucketIndexAtOffset(bucketOffsets.value, scrollTop.value, stickyOffset.value)]
})

const currentBucketLabel = computed(() =>
	currentBucket.value ? formatBucketDate(currentBucket.value.timeBucket) : ''
)

const currentBucketCount = computed(() =>
	currentBucket.value?.count ?? 0
)

// --- Lazy bucket loading ---
function loadBucket(timeBucket, options = {}) {
	if (store.albumBucketAssets[timeBucket]) return Promise.resolve(true)
	const albumId = props.id
	return scheduler.request(
		timeBucket,
		signal => store.fetchAlbumBucketAssets(albumId, timeBucket, signal),
		options,
	)
}

async function selectBucket(index) {
	const bucket = store.albumBuckets[index]
	if (!bucket) return
	const albumId = props.id
	const datasetId = `album:${albumId}`
	const loaded = await loadBucket(bucket.timeBucket, { priority: -1, retain: true })
	if (!loaded || props.id !== albumId || scheduler.datasetId !== datasetId) return
	store.toggleAssetsSelection((store.albumBucketAssets[bucket.timeBucket] || []).map(asset => asset.id))
}

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(store.albumBucketAssets)
	const visibleKeys = new Set(currentIndices.map(i => store.albumBuckets[i].timeBucket))
	evictLoadedBuckets(loadedKeys, visibleKeys, MAX_LOADED_BUCKETS, key => store.unloadAlbumBucketAsset(key))
}

watch(windowIndices, (indices) => {
	if (containerWidth.value <= 0) {
		scheduler.setVisibleKeys([])
		return
	}
	scheduler.setVisibleKeys(indices.map(i => store.albumBuckets[i]?.timeBucket).filter(key => key !== undefined))
	const prioritizedIndices = [...indices].sort((a, b) => getWindowPriority(a, indices) - getWindowPriority(b, indices))
	for (const i of prioritizedIndices) {
		const bucket = store.albumBuckets[i]
		if (bucket && !store.albumBucketAssets[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket, { priority: getWindowPriority(i, indices) })
		}
	}
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const { assets, index } = getLightboxAssetsInBucketOrder(
		store.albumBuckets,
		store.albumBucketAssets,
		bucketIndex,
		localIdx,
		bucket => bucket.timeBucket,
	)
	store.openLightbox(assets, index)
}

function formatBucketDate(timeBucket) {
	const date = new Date(timeBucket)
	return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
}

function goBack() {
	router.push({ name: 'albums' })
}

function startRename() {
	renameValue.value = currentAlbumDetails.value?.albumName ?? ''
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
		const albumId = props.id
		scheduler.invalidateDataset(`album:${albumId}`)
		await Promise.all([store.fetchAlbum(albumId), store.fetchAlbumBuckets(albumId)])
	} catch (e) {
		showError(t('integration_immich', 'Error adding: {msg}', { msg: e.message }))
	} finally {
		addingAssets.value = false
	}
}

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
	scheduler.setDataset(`album:${props.id}`)
	resetScroll()
	load()
})

onBeforeUnmount(() => {
	scheduler.destroy()
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

.album-detail__bucket-header {
	height: 32px;
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	gap: 8px;
}

.album-detail__bucket-label { font-size: 13px; font-weight: 600; color: var(--color-main-text); }
.album-detail__bucket-count { font-size: 11px; color: var(--color-text-maxcontrast); }
.album-detail__select-bucket {
	all: unset;
	box-sizing: border-box;
	width: 28px;
	height: 28px;
	margin-left: auto;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}
.album-detail__select-bucket:hover { color: var(--color-main-text); background: var(--color-background-hover); }
.album-detail__select-bucket:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

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
