<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timeline-view">
		<NcEmptyContent v-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcLoadingIcon v-else-if="store.loading && buckets.length === 0"
			:size="64"
			class="timeline-view__loading" />

		<NcEmptyContent v-else-if="buckets.length === 0"
			:name="t('integration_immich', 'No photos')"
			:description="t('integration_immich', 'Your Immich library does not contain any photos yet.')">
			<template #icon>
				<ImageIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Virtual scroll container -->
		<div v-else
			ref="scrollContainer"
			class="timeline-view__scroll"
			@scroll="onScroll">
			<!-- Sticky date overlay -->
			<div ref="stickyDate" class="timeline-view__sticky-date">
				<span class="timeline-view__sticky-label">{{ currentBucketLabel }}</span>
				<span class="timeline-view__sticky-count">{{ currentBucketCount }}</span>
				<!-- Layout toggle: square grid vs. masonry -->
				<div class="timeline-view__layout-toggle">
					<button
						class="timeline-view__layout-btn"
						:class="{ 'timeline-view__layout-btn--active': store.gridLayout === 'grid' }"
						:title="t('integration_immich', 'Square grid')"
						:aria-label="t('integration_immich', 'Square grid')"
						@click="setLayout('grid')">
						<ViewGridIcon :size="16" />
					</button>
					<button
						class="timeline-view__layout-btn"
						:class="{ 'timeline-view__layout-btn--active': store.gridLayout === 'masonry' }"
						:title="t('integration_immich', 'Masonry grid')"
						:aria-label="t('integration_immich', 'Masonry grid')"
						@click="setLayout('masonry')">
						<ViewQuiltIcon :size="16" />
					</button>
				</div>
			</div>
			<!-- Scroll-to-top button -->
			<Transition name="timeline-fab">
				<button v-if="scrollTop > 600"
					class="timeline-view__fab"
					:title="t('integration_immich', 'Scroll to top')"
					@click="scrollToTop">
					<svg viewBox="0 0 24 24" aria-hidden="true">
						<path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z" />
					</svg>
				</button>
			</Transition>
			<!-- Spacer to create full scrollable height -->
			<div class="timeline-view__runway" :style="{ height: totalHeight + 'px' }">
				<!-- Only render buckets in the sliding window -->
				<div v-for="index in windowIndices"
					:key="buckets[index].timeBucket"
					class="timeline-view__bucket"
					:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
					<div class="timeline-view__bucket-header">
						<span class="timeline-view__bucket-label">{{ formatBucketDate(buckets[index].timeBucket) }}</span>
						<span class="timeline-view__bucket-count">{{ buckets[index].count }}</span>
						<button v-if="!props.userId"
							class="timeline-view__select-bucket"
							:title="t('integration_immich', 'Select all photos in this month')"
							:aria-label="t('integration_immich', 'Select all photos in this month')"
							@click.stop="selectBucket(index)">
							<CheckboxMultipleOutlineIcon :size="18" />
						</button>
					</div>
					<NcLoadingIcon v-if="loadingSet.has(buckets[index].timeBucket)"
						:size="32"
						class="timeline-view__bucket-loading" />
					<PhotoGrid v-else-if="assetsCache[buckets[index].timeBucket]"
						:assets="assetsCache[buckets[index].timeBucket]"
						:selectable="!props.userId"
						:layout="store.gridLayout"
					@click="(_, idx) => openLightboxFromBucket(idx, index)" />
					<div v-else
						class="timeline-view__bucket-placeholder"
						:style="{ height: Math.max(0, bucketHeights[index] - bucketGeometry.verticalChrome) + 'px' }" />
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { useVirtualScroll } from '../composables/useVirtualScroll.js'
import { BucketRequestScheduler } from '../services/BucketRequestScheduler.js'
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
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewQuiltIcon from 'vue-material-design-icons/ViewQuilt.vue'
import CheckboxMultipleOutlineIcon from 'vue-material-design-icons/CheckboxMultipleOutline.vue'

const props = defineProps({
	assetType: { type: String, default: null },
	isFavorite: { type: Boolean, default: false },
	userId: { type: String, default: null },
})

const store = useImmichStore()

// Point to the right store state based on assetType / isFavorite prop
const buckets = computed(() => {
	if (props.userId) return store.partnerBuckets[props.userId] || []
	if (props.isFavorite) return store.favoriteBuckets
	if (props.assetType) return store.filteredBuckets[props.assetType]
	return store.timelineBuckets
})
const assetsCache = computed(() => {
	if (props.userId) return store.partnerAssets[props.userId] || {}
	if (props.isFavorite) return store.favoriteAssets
	if (props.assetType) return store.filteredAssets[props.assetType]
	return store.timelineAssets
})

// --- Constants ---
const MAX_LOADED_BUCKETS = 12 // max buckets with loaded assets in memory

// --- Reactive state ---
const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
function getDatasetId() {
	if (props.userId) return `partner:${props.userId}`
	if (props.isFavorite) return 'favorites'
	if (props.assetType) return `filtered:${props.assetType}`
	return 'timeline'
}
scheduler.setDataset(getDatasetId())
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
} = useVirtualScroll({
	onLargeScrollJump: () => scheduler.setVisibleKeys([]),
	stickyElement: stickyDate,
})

// --- Layout toggle ---
function setLayout(layout) {
	store.setGridLayout(layout)
}

const bucketGeometry = computed(() => getResponsiveBucketGeometry({
	containerWidth: containerWidth.value,
	viewportWidth: viewportWidth.value,
	gridBreakpoint: 480,
	bucketPaddingBreakpoint: null,
	desktopHorizontalPadding: 32,
	desktopVerticalChrome: 53,
	mobileVerticalChrome: 53,
}))

// Track actual measured heights (initially estimated)
const bucketHeights = computed(() => {
	return buckets.value.map(b => {
		if (store.gridLayout === 'masonry') {
			return estimateMasonryBucketHeight(b.count, assetsCache.value[b.timeBucket], bucketGeometry.value)
		}
		return estimateGridBucketHeight(b.count, bucketGeometry.value)
	})
})

const bucketOffsets = computed(() => getCumulativeOffsets(bucketHeights.value))
const totalHeight = computed(() => getTotalHeight(bucketHeights.value, bucketOffsets.value))

// --- Sliding window: which bucket indices are in/near the viewport ---
const windowIndices = computed(() => getVirtualWindowIndices(
	bucketHeights.value,
	bucketOffsets.value,
	scrollTop.value,
	viewportHeight.value,
	800,
	stickyOffset.value,
))

// --- Fetch/unload helpers depending on assetType / isFavorite ---
async function fetchBuckets() {
	if (props.userId) {
		await store.fetchPartnerBuckets(props.userId)
	} else if (props.isFavorite) {
		await store.fetchFavoriteBuckets()
	} else if (props.assetType) {
		await store.fetchFilteredBuckets(props.assetType)
	} else {
		await store.fetchTimelineBuckets()
	}
}

async function fetchBucket(timeBucket, signal, context) {
	if (context.userId) {
		return store.fetchPartnerBucket(context.userId, timeBucket, signal)
	} else if (context.isFavorite) {
		return store.fetchFavoriteBucket(timeBucket, signal)
	} else if (context.assetType) {
		return store.fetchFilteredBucket(context.assetType, timeBucket, signal)
	} else {
		return store.fetchTimelineBucket(timeBucket, signal)
	}
}

function unloadBucket(timeBucket) {
	if (props.userId) {
		store.unloadPartnerBucket(props.userId, timeBucket)
	} else if (props.isFavorite) {
		store.unloadFavoriteBucket(timeBucket)
	} else if (props.assetType) {
		store.unloadFilteredBucket(props.assetType, timeBucket)
	} else {
		store.unloadTimelineBucket(timeBucket)
	}
}

// --- Load / unload bucket data ---
function loadBucket(timeBucket, options = {}) {
	if (assetsCache.value[timeBucket]) return Promise.resolve(true)
	const context = {
		userId: props.userId,
		isFavorite: props.isFavorite,
		assetType: props.assetType,
	}
	return scheduler.request(timeBucket, signal => fetchBucket(timeBucket, signal, context), options)
}

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(assetsCache.value)
	const visibleKeys = new Set(
		currentIndices.map(i => buckets.value[i].timeBucket),
	)
	evictLoadedBuckets(loadedKeys, visibleKeys, MAX_LOADED_BUCKETS, unloadBucket)
}

// Watch visible window and trigger load/unload
watch(windowIndices, (indices) => {
	if (containerWidth.value <= 0) {
		scheduler.setVisibleKeys([])
		return
	}
	const visibleKeys = new Set(indices.map(i => buckets.value[i]?.timeBucket))
	scheduler.setVisibleKeys(visibleKeys)

	// Load visible buckets
	const prioritizedIndices = [...indices].sort((a, b) => getWindowPriority(a, indices) - getWindowPriority(b, indices))
	for (const i of prioritizedIndices) {
		const bucket = buckets.value[i]
		if (bucket && !assetsCache.value[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket, { priority: getWindowPriority(i, indices) })
		}
	}

	// Evict far-away buckets from memory
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const { assets, index } = getLightboxAssetsInBucketOrder(
		buckets.value,
		assetsCache.value,
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

async function selectBucket(index) {
	const bucket = buckets.value[index]
	if (!bucket) return
	const datasetId = getDatasetId()
	const loaded = await loadBucket(bucket.timeBucket, { priority: -1, retain: true })
	if (!loaded || datasetId !== getDatasetId()) return
	const assets = assetsCache.value[bucket.timeBucket] || []
	const userAssets = assets.filter(asset => !asset.ownerId || (store.currentUserId && asset.ownerId === store.currentUserId))
	store.toggleAssetsSelection(userAssets.map(asset => asset.id))
}

const currentBucketIndex = computed(() => {
	if (buckets.value.length === 0) return 0
	return getBucketIndexAtOffset(bucketOffsets.value, scrollTop.value, stickyOffset.value)
})

const currentBucketLabel = computed(() => {
	if (buckets.value.length === 0) return ''
	return formatBucketDate(buckets.value[currentBucketIndex.value].timeBucket)
})

const currentBucketCount = computed(() => {
	if (buckets.value.length === 0) return 0
	return buckets.value[currentBucketIndex.value].count
})

// Lifecycle ---
onMounted(async () => {
	await fetchBuckets()
})

// When navigating between timeline / photos / videos / favorites, Vue Router reuses this
// component instance — onMounted does NOT fire again. Watch the props instead.
watch([() => props.assetType, () => props.isFavorite, () => props.userId], async () => {
	scheduler.setDataset(getDatasetId())
	resetScroll()
	await fetchBuckets()
})

// When favorites are mutated externally (e.g. removed via toolbar), re-trigger
// the window watcher by resetting scroll — buckets ref already changed reactively.
watch(() => store.favoriteBuckets.length, (newLen, oldLen) => {
	if (props.isFavorite && newLen !== oldLen) {
		loadingSet.value = new Set()
	}
})

onBeforeUnmount(() => {
	scheduler.destroy()
})

function scrollToTop() {
	scrollContainer.value?.scrollTo({ top: 0, behavior: 'smooth' })
}
</script>

<style scoped>
.timeline-view {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.timeline-view__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.timeline-view__scroll {
	flex: 1;
	overflow-y: auto;
	position: relative;
}

.timeline-view__runway {
	position: relative;
	width: 100%;
}

.timeline-view__bucket {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	padding: 15px 16px 0;
}

.timeline-view__bucket-header {
	height: 32px;
	margin-bottom: 6px;
	display: flex;
	align-items: center;
	gap: 8px;
}

.timeline-view__bucket-label {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.timeline-view__bucket-count {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.timeline-view__select-bucket {
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

.timeline-view__select-bucket:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.timeline-view__select-bucket:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

/* ---- Sticky date — slim & elegant ---- */
.timeline-view__sticky-date {
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

/* ---- Layout toggle ---- */
.timeline-view__layout-toggle {
	margin-left: auto;
	pointer-events: auto; /* re-enable clicks, parent has pointer-events: none */
	display: flex;
	gap: 2px;
}

.timeline-view__layout-btn {
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

.timeline-view__layout-btn:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.timeline-view__layout-btn:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.timeline-view__layout-btn--active {
	color: var(--color-primary);
	background: var(--color-primary-element-light);
}

.timeline-view__sticky-label {
	font-size: 13px;
	font-weight: 600;
	letter-spacing: 0.01em;
	color: var(--color-main-text);
}

.timeline-view__sticky-count {
	font-size: 11px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	border-radius: 20px;
	padding: 1px 7px;
}

/* ---- Scroll-to-top FAB ---- */
.timeline-view__fab {
	all: unset;
	box-sizing: border-box;
	position: fixed;
	bottom: 28px;
	right: 28px;
	z-index: 50;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	box-shadow: 0 4px 16px rgba(0,0,0,0.18);
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--color-main-text);
	transition: box-shadow 0.2s ease, transform 0.2s ease, background 0.15s;
}

.timeline-view__fab svg {
	width: 22px;
	height: 22px;
	fill: currentColor;
	display: block;
}

.timeline-view__fab:hover {
	box-shadow: 0 6px 24px rgba(0,0,0,0.26);
	transform: translateY(-2px);
	background: var(--color-background-hover);
}

/* FAB enter/leave transition */
.timeline-fab-enter-active,
.timeline-fab-leave-active {
	transition: opacity 0.2s ease, transform 0.2s ease;
}
.timeline-fab-enter-from,
.timeline-fab-leave-to {
	opacity: 0;
	transform: translateY(12px);
}

.timeline-view__bucket-loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.timeline-view__bucket-placeholder {
	background: var(--color-background-dark);
	border-radius: 8px;
	opacity: 0.15;
}
</style>
