<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="timeline-view">
		<NcEmptyContent v-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<NcLoadingIcon v-else-if="store.loading && store.timelineBuckets.length === 0"
			:size="64"
			class="timeline-view__loading" />

		<NcEmptyContent v-else-if="store.timelineBuckets.length === 0"
			:name="t('integration_immich', 'Keine Bilder')"
			:description="t('integration_immich', 'In deiner Immich Bibliothek sind noch keine Bilder vorhanden.')">
			<template #icon>
				<ImageIcon :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Virtual scroll container -->
		<div v-else
			ref="scrollContainer"
			class="timeline-view__scroll"
			@scroll="onScroll">
			<!-- Spacer to create full scrollable height -->
			<div class="timeline-view__runway" :style="{ height: totalHeight + 'px' }">
				<!-- Only render buckets in the sliding window -->
				<div v-for="index in windowIndices"
					:key="store.timelineBuckets[index].timeBucket"
					class="timeline-view__bucket"
					:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
					<h2 class="timeline-view__bucket-header">
						{{ formatBucketDate(store.timelineBuckets[index].timeBucket) }}
						<span class="timeline-view__bucket-count">
							({{ store.timelineBuckets[index].count }})
						</span>
					</h2>
					<NcLoadingIcon v-if="loadingSet.has(store.timelineBuckets[index].timeBucket)"
						:size="32"
						class="timeline-view__bucket-loading" />
					<PhotoGrid v-else-if="store.timelineAssets[store.timelineBuckets[index].timeBucket]"
						:assets="store.timelineAssets[store.timelineBuckets[index].timeBucket]"
						@click="(_, idx) => openLightboxFromBucket(idx, index)" />
					<div v-else
						class="timeline-view__bucket-placeholder"
						:style="{ height: (bucketHeights[index] - HEADER_HEIGHT) + 'px' }" />
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
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ImageIcon from 'vue-material-design-icons/Image.vue'

const store = useImmichStore()

// --- Constants ---
const HEADER_HEIGHT = 48
const ROW_HEIGHT = 210
const ITEMS_PER_ROW = 5
const OVERSCAN = 800 // px above/below viewport to pre-render
const MAX_CONCURRENT = 2
const MAX_LOADED_BUCKETS = 12 // max buckets with loaded assets in memory

// --- Reactive state ---
const scrollContainer = ref(null)
const scrollTop = ref(0)
const viewportHeight = ref(800)
const loadingSet = ref(new Set())

let activeRequests = 0
const pendingQueue = []

// --- Height estimation ---
function estimateBucketHeight(count) {
	const rows = Math.ceil(count / ITEMS_PER_ROW)
	return HEADER_HEIGHT + rows * ROW_HEIGHT
}

// Track actual measured heights (initially estimated)
const bucketHeights = computed(() => {
	return store.timelineBuckets.map(b => estimateBucketHeight(b.count))
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
	if (store.timelineBuckets.length === 0) return []

	const top = scrollTop.value - OVERSCAN
	const bottom = scrollTop.value + viewportHeight.value + OVERSCAN
	const indices = []

	for (let i = 0; i < store.timelineBuckets.length; i++) {
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

// --- Load / unload bucket data ---
async function loadBucket(timeBucket) {
	if (store.timelineAssets[timeBucket] || loadingSet.value.has(timeBucket)) {
		return
	}

	if (activeRequests >= MAX_CONCURRENT) {
		return new Promise(resolve => {
			pendingQueue.push(() => loadBucket(timeBucket).then(resolve))
		})
	}

	activeRequests++
	loadingSet.value = new Set([...loadingSet.value, timeBucket])

	try {
		await store.fetchTimelineBucket(timeBucket)
	} finally {
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

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(store.timelineAssets)
	if (loadedKeys.length <= MAX_LOADED_BUCKETS) return

	const visibleKeys = new Set(
		currentIndices.map(i => store.timelineBuckets[i].timeBucket),
	)

	// Evict buckets that are loaded but far from current view
	for (const key of loadedKeys) {
		if (visibleKeys.has(key)) continue
		if (loadedKeys.length <= MAX_LOADED_BUCKETS) break
		store.unloadTimelineBucket(key)
	}
}

// Watch visible window and trigger load/unload
watch(windowIndices, (indices) => {
	// Load visible buckets
	for (const i of indices) {
		const bucket = store.timelineBuckets[i]
		if (bucket && !store.timelineAssets[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket)
		}
	}

	// Evict far-away buckets from memory
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const allAssets = []
	let globalIdx = 0
	for (let i = 0; i < store.timelineBuckets.length; i++) {
		const bucketAssets = store.timelineAssets[store.timelineBuckets[i].timeBucket]
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

// --- Lifecycle ---
onMounted(async () => {
	if (scrollContainer.value) {
		viewportHeight.value = scrollContainer.value.clientHeight
	}
	await store.fetchTimelineBuckets()
})

onBeforeUnmount(() => {
	if (scrollRaf) {
		cancelAnimationFrame(scrollRaf)
	}
})
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
	padding: 0 16px 0 52px;
}

.timeline-view__bucket-header {
	font-size: 18px;
	font-weight: bold;
	margin: 8px 0;
	padding: 4px 0;
	color: var(--color-main-text);
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	z-index: 1;
}

.timeline-view__bucket-count {
	font-size: 13px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
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
