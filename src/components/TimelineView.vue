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
			<div class="timeline-view__sticky-date">
				<span class="timeline-view__sticky-label">{{ currentBucketLabel }}</span>
				<span class="timeline-view__sticky-count">{{ currentBucketCount }}</span>
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
					<NcLoadingIcon v-if="loadingSet.has(buckets[index].timeBucket)"
						:size="32"
						class="timeline-view__bucket-loading" />
					<PhotoGrid v-else-if="assetsCache[buckets[index].timeBucket]"
						:assets="assetsCache[buckets[index].timeBucket]"
						:selectable="true"
					@click="(_, idx) => openLightboxFromBucket(idx, index)" />
					<div v-else
						class="timeline-view__bucket-placeholder"
						:style="{ height: bucketHeights[index] + 'px' }" />
				</div>
			</div>
		</div>

		<!-- Selection Toolbar -->
		<SelectionToolbar @assets-deleted="handleAssetsDeleted" />
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import PhotoGrid from './PhotoGrid.vue'
import SelectionToolbar from './SelectionToolbar.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'

const props = defineProps({
	assetType: { type: String, default: null },
	isFavorite: { type: Boolean, default: false },
})

const store = useImmichStore()

// Point to the right store state based on assetType / isFavorite prop
const buckets = computed(() => {
	if (props.isFavorite) return store.favoriteBuckets
	if (props.assetType) return store.filteredBuckets[props.assetType]
	return store.timelineBuckets
})
const assetsCache = computed(() => {
	if (props.isFavorite) return store.favoriteAssets
	if (props.assetType) return store.filteredAssets[props.assetType]
	return store.timelineAssets
})

// --- Constants ---
const OVERSCAN = 800 // px above/below viewport to pre-render
const MAX_CONCURRENT = 2
const MAX_LOADED_BUCKETS = 12 // max buckets with loaded assets in memory

// PhotoGrid uses: grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 3px
// Each item has aspect-ratio: 1. Bucket has padding: 15px 16px 0 (top=15, lr=16).
const GRID_MIN_ITEM = 180 // minmax min value
const GRID_GAP = 3
const BUCKET_PADDING_TOP = 15
const BUCKET_PADDING_LR = 32 // 16px left + 16px right

// --- Reactive state ---
const scrollContainer = ref(null)
const scrollTop = ref(0)
const viewportHeight = ref(800)
const containerWidth = ref(0) // measured width of the scroll container
const loadingSet = ref(new Set())

let activeRequests = 0
const pendingQueue = []
// AbortController per timeBucket for in-flight requests
const abortControllers = new Map()

// --- Height estimation ---
// Derives column count and row height from the actual container width, matching
// the CSS grid in PhotoGrid.vue (auto-fill, minmax(180px, 1fr), gap: 3px, aspect-ratio:1).
function estimateBucketHeight(count) {
	const available = Math.max(GRID_MIN_ITEM, containerWidth.value - BUCKET_PADDING_LR)
	const cols = Math.max(1, Math.floor((available + GRID_GAP) / (GRID_MIN_ITEM + GRID_GAP)))
	const colWidth = (available - (cols - 1) * GRID_GAP) / cols
	const rows = Math.ceil(count / cols)
	return BUCKET_PADDING_TOP + rows * colWidth + (rows - 1) * GRID_GAP
}

// Track actual measured heights (initially estimated)
const bucketHeights = computed(() => {
	// containerWidth is read inside estimateBucketHeight, establishing reactivity.
	return buckets.value.map(b => estimateBucketHeight(b.count))
})

// Cumulative offsets for each bucket
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
	const heights = bucketHeights.value
	if (heights.length === 0) return 0
	return bucketOffsets.value[heights.length - 1] + heights[heights.length - 1]
})

// --- Sliding window: which bucket indices are in/near the viewport ---
const windowIndices = computed(() => {
	if (buckets.value.length === 0) return []

	const top = scrollTop.value - OVERSCAN
	const bottom = scrollTop.value + viewportHeight.value + OVERSCAN
	const indices = []

	for (let i = 0; i < buckets.value.length; i++) {
		const bucketTop = bucketOffsets.value[i]
		const bucketBottom = bucketTop + bucketHeights.value[i]

		// Bucket overlaps with the visible range
		if (bucketBottom >= top && bucketTop <= bottom) {
			indices.push(i)
		}
		// Past the visible range, stop searching
		if (bucketTop > bottom) break
	}

	return indices
})

// --- Scroll handler (throttled) ---
let scrollRaf = null
let lastScrollTop = 0
function onScroll() {
	if (scrollRaf) return
	scrollRaf = requestAnimationFrame(() => {
		if (scrollContainer.value) {
			const newScrollTop = scrollContainer.value.scrollTop
			// Large jump detection: if the user dragged the scrollbar far,
			// flush the pending queue so stale out-of-view requests don't
			// block the newly visible buckets.
			if (Math.abs(newScrollTop - lastScrollTop) > viewportHeight.value * 2) {
				pendingQueue.length = 0
			}
			lastScrollTop = newScrollTop
			scrollTop.value = newScrollTop
			viewportHeight.value = scrollContainer.value.clientHeight
		}
		scrollRaf = null
	})
}

// --- Fetch/unload helpers depending on assetType / isFavorite ---
async function fetchBuckets() {
	if (props.isFavorite) {
		await store.fetchFavoriteBuckets()
	} else if (props.assetType) {
		await store.fetchFilteredBuckets(props.assetType)
	} else {
		await store.fetchTimelineBuckets()
	}
}

async function fetchBucket(timeBucket, signal) {
	if (props.isFavorite) {
		await store.fetchFavoriteBucket(timeBucket, signal)
	} else if (props.assetType) {
		await store.fetchFilteredBucket(props.assetType, timeBucket, signal)
	} else {
		await store.fetchTimelineBucket(timeBucket, signal)
	}
}

function unloadBucket(timeBucket) {
	if (props.isFavorite) {
		store.unloadFavoriteBucket(timeBucket)
	} else if (props.assetType) {
		store.unloadFilteredBucket(props.assetType, timeBucket)
	} else {
		store.unloadTimelineBucket(timeBucket)
	}
}

// --- Load / unload bucket data ---
async function loadBucket(timeBucket) {
	if (assetsCache.value[timeBucket] || loadingSet.value.has(timeBucket)) {
		return
	}

	if (activeRequests >= MAX_CONCURRENT) {
		return new Promise(resolve => {
			// Only enqueue if not already waiting
			const alreadyQueued = pendingQueue.some(fn => fn._bucket === timeBucket)
			if (!alreadyQueued) {
				const fn = () => loadBucket(timeBucket).then(resolve)
				fn._bucket = timeBucket
				pendingQueue.push(fn)
			} else {
				resolve()
			}
		})
	}

	const controller = new AbortController()
	abortControllers.set(timeBucket, controller)

	activeRequests++
	loadingSet.value = new Set([...loadingSet.value, timeBucket])

	try {
		await fetchBucket(timeBucket, controller.signal)
	} catch (e) {
		// Ignore abort errors — user just scrolled away
		if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') {
			return
		}
	} finally {
		abortControllers.delete(timeBucket)
		loadingSet.value = new Set(
			[...loadingSet.value].filter(b => b !== timeBucket),
		)
		activeRequests--
		if (pendingQueue.length > 0) {
			const next = pendingQueue.shift()
			next()
		}
	}
}

// Cancel all in-flight requests for buckets not in the given set.
// activeRequests is decremented by the finally-block in loadBucket — not here.
// loadingSet is cleared immediately so that re-scrolling back to the same bucket
// does not hit the early-return guard in loadBucket before finally can clean up.
function cancelInvisibleRequests(visibleKeys) {
	const toRemove = []
	for (const [bucket, controller] of abortControllers.entries()) {
		if (!visibleKeys.has(bucket)) {
			controller.abort()
			abortControllers.delete(bucket)
			toRemove.push(bucket)
		}
	}
	if (toRemove.length > 0) {
		const removed = new Set(toRemove)
		loadingSet.value = new Set([...loadingSet.value].filter(b => !removed.has(b)))
	}
}

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(assetsCache.value)
	if (loadedKeys.length <= MAX_LOADED_BUCKETS) return

	const visibleKeys = new Set(
		currentIndices.map(i => buckets.value[i].timeBucket),
	)

	// Evict buckets that are loaded but far from current view
	for (const key of loadedKeys) {
		if (visibleKeys.has(key)) continue
		if (loadedKeys.length <= MAX_LOADED_BUCKETS) break
		unloadBucket(key)
	}
}

// Watch visible window and trigger load/unload
watch(windowIndices, (indices) => {
	const visibleKeys = new Set(indices.map(i => buckets.value[i]?.timeBucket))

	// Cancel any in-flight HTTP requests for buckets no longer visible
	cancelInvisibleRequests(visibleKeys)

	// Remove from pending queue any buckets that are no longer visible
	for (let i = pendingQueue.length - 1; i >= 0; i--) {
		if (!visibleKeys.has(pendingQueue[i]._bucket)) {
			pendingQueue.splice(i, 1)
		}
	}

	// Load visible buckets
	for (const i of indices) {
		const bucket = buckets.value[i]
		if (bucket && !assetsCache.value[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket)
		}
	}

	// Evict far-away buckets from memory
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const allAssets = []
	let globalIdx = 0
	for (let i = 0; i < buckets.value.length; i++) {
		const bucketAssets = assetsCache.value[buckets.value[i].timeBucket]
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

function handleAssetsDeleted(deletedIds) {
	// Assets are already removed from store by SelectionToolbar
	// Nothing extra needed here as the store handles all cache cleanup
}

const currentBucketIndex = computed(() => {
	if (buckets.value.length === 0) return 0
	for (let i = buckets.value.length - 1; i >= 0; i--) {
		if (bucketOffsets.value[i] <= scrollTop.value) return i
	}
	return 0
})

const currentBucketLabel = computed(() => {
	if (buckets.value.length === 0) return ''
	return formatBucketDate(buckets.value[currentBucketIndex.value].timeBucket)
})

const currentBucketCount = computed(() => {
	if (buckets.value.length === 0) return 0
	return buckets.value[currentBucketIndex.value].count
})

// --- Lifecycle ---
let resizeObserver = null

// The scroll container is inside a v-else branch and only mounts after buckets
// are loaded. onMounted fires before that, so we watch the ref instead.
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

onMounted(async () => {
	await fetchBuckets()
})

// When navigating between timeline / photos / videos / favorites, Vue Router reuses this
// component instance — onMounted does NOT fire again. Watch the props instead.
watch([() => props.assetType, () => props.isFavorite], async () => {
	scrollTop.value = 0
	lastScrollTop = 0
	loadingSet.value = new Set()
	for (const controller of abortControllers.values()) {
		controller.abort()
	}
	abortControllers.clear()
	pendingQueue.length = 0
	activeRequests = 0
	if (scrollContainer.value) {
		scrollContainer.value.scrollTop = 0
	}
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
	resizeObserver?.disconnect()
	if (scrollRaf) {
		cancelAnimationFrame(scrollRaf)
	}
	// Abort all in-flight requests
	for (const controller of abortControllers.values()) {
		controller.abort()
	}
	abortControllers.clear()
	pendingQueue.length = 0
	activeRequests = 0
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

/* ---- Sticky date — slim & elegant ---- */
.timeline-view__sticky-date {
	position: sticky;
	top: 0;
	z-index: 10;
	padding: 7px 16px;
	display: flex;
	align-items: baseline;
	gap: 8px;
	background: var(--color-main-background);
	pointer-events: none;
	border-bottom: 1px solid var(--color-border-dark);
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
