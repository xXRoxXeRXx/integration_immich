<!--
  - SPDX-FileCopyright: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="person-detail">
		<!-- Loading state: initial bucket-list fetch -->
		<NcLoadingIcon v-if="store.loading && store.personBuckets.length === 0"
			:size="64"
			class="person-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Error')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Sticky header with back button + person info -->
			<div class="person-detail__header">
				<!-- Top row: Breadcrumb + avatar right-aligned -->
				<div class="person-detail__header-row">
					<NcBreadcrumbs>
						<NcBreadcrumb :name="t('integration_immich', 'People')" @click="goBack" />
						<NcBreadcrumb :name="personName" :title="personName" />
					</NcBreadcrumbs>
					<img :src="getPersonThumbnailUrl(props.id)"
						class="person-detail__avatar"
						:alt="personName">
				</div>
				<!-- Photo count + layout toggle below breadcrumb -->
				<div class="person-detail__meta-row">
					<span class="person-detail__count">
						{{ n('integration_immich', '{count} photo', '{count} photos', totalCount, { count: totalCount }) }}
					</span>
					<div class="person-detail__layout-toggle">
						<button
							class="person-detail__layout-btn"
							:class="{ 'person-detail__layout-btn--active': store.gridLayout === 'grid' }"
							:title="t('integration_immich', 'Square grid')"
							:aria-label="t('integration_immich', 'Square grid')"
							@click="store.setGridLayout('grid')">
							<ViewGridIcon :size="16" />
						</button>
						<button
							class="person-detail__layout-btn"
							:class="{ 'person-detail__layout-btn--active': store.gridLayout === 'masonry' }"
							:title="t('integration_immich', 'Masonry grid')"
							:aria-label="t('integration_immich', 'Masonry grid')"
							@click="store.setGridLayout('masonry')">
							<ViewQuiltIcon :size="16" />
						</button>
					</div>
				</div>
			</div>

			<NcEmptyContent v-if="store.personBuckets.length === 0 && !store.loading"
				:name="t('integration_immich', 'No photos')"
				:description="t('integration_immich', 'No photos found for this person.')">
				<template #icon>
					<AccountIcon :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Virtual scroll — same pattern as TimelineView -->
			<div v-else
				ref="scrollContainer"
				class="person-detail__scroll"
				@scroll="onScroll">

				<!-- Sticky date bar — direct child of scroll container so position:sticky works -->
				<div class="person-detail__sticky-date">
					<span class="person-detail__sticky-label">{{ currentBucketLabel }}</span>
					<span v-if="currentBucketCount" class="person-detail__sticky-count">{{ currentBucketCount }}</span>
				</div>

				<div class="person-detail__runway" :style="{ height: totalHeight + 'px' }">
					<div v-for="index in windowIndices"
						:key="store.personBuckets[index].timeBucket"
						class="person-detail__bucket"
						:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
						<div class="person-detail__bucket-header">
							<span class="person-detail__bucket-label">{{ formatBucketDate(store.personBuckets[index].timeBucket) }}</span>
							<span class="person-detail__bucket-count">{{ store.personBuckets[index].count }}</span>
							<button class="person-detail__select-bucket"
								:title="t('integration_immich', 'Select all photos in this month')"
								:aria-label="t('integration_immich', 'Select all photos in this month')"
								@click.stop="selectBucket(index)">
								<CheckboxMultipleOutlineIcon :size="18" />
							</button>
						</div>
						<NcLoadingIcon v-if="loadingSet.has(store.personBuckets[index].timeBucket)"
							:size="32"
							class="person-detail__bucket-loading" />
						<PhotoGrid v-else-if="store.personBucketAssets[store.personBuckets[index].timeBucket]"
							:assets="store.personBucketAssets[store.personBuckets[index].timeBucket]"
							:selectable="true"
							:layout="store.gridLayout"
						@click="(_, idx) => openLightboxFromBucket(idx, index)" />
						<div v-else
							class="person-detail__bucket-placeholder"
							:style="{ height: (bucketHeights[index] - HEADER_HEIGHT) + 'px' }" />
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { NcEmptyContent, NcLoadingIcon, NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getPersonThumbnailUrl } from '../services/api.js'
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewQuiltIcon from 'vue-material-design-icons/ViewQuilt.vue'
import CheckboxMultipleOutlineIcon from 'vue-material-design-icons/CheckboxMultipleOutline.vue'

const props = defineProps({
	id: { type: String, required: true },
})

const store = useImmichStore()
const router = useRouter()

// --- Constants ---
const HEADER_HEIGHT = 40
const GRID_MIN_ITEM = 180   // matches PhotoGrid minmax(180px, 1fr)
const GRID_GAP = 3          // matches PhotoGrid gap: 3px
const BUCKET_PADDING_LR = 32 // .person-detail__bucket padding: 15px 16px → 16×2
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
const personName = computed(() => {
	const p = store.people.find(p => p.id === props.id)
	return p?.name || t('integration_immich', 'Unknown')
})

const totalCount = computed(() =>
	store.personBuckets.reduce((sum, b) => sum + (b.count || 0), 0),
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
	store.personBuckets.map(b => {
		if (store.gridLayout === 'masonry') {
			return estimateBucketHeightMasonry(b.count, store.personBucketAssets[b.timeBucket])
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
	if (store.personBuckets.length === 0) return []
	const top = scrollTop.value - OVERSCAN
	const bottom = scrollTop.value + viewportHeight.value + OVERSCAN
	const indices = []
	for (let i = 0; i < store.personBuckets.length; i++) {
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
	if (store.personBuckets.length === 0) return null
	let idx = 0
	for (let i = 0; i < bucketOffsets.value.length; i++) {
		if (bucketOffsets.value[i] <= scrollTop.value) idx = i
		else break
	}
	return store.personBuckets[idx]
})

const currentBucketLabel = computed(() =>
	currentBucket.value ? formatBucketDate(currentBucket.value.timeBucket) : ''
)

const currentBucketCount = computed(() =>
	currentBucket.value?.count ?? 0
)

// --- Lazy bucket loading ---
async function loadBucket(timeBucket) {
	if (store.personBucketAssets[timeBucket] || loadingSet.value.has(timeBucket)) return

	if (activeRequests >= MAX_CONCURRENT) {
		return new Promise(resolve => {
			pendingQueue.push(() => loadBucket(timeBucket).then(resolve))
		})
	}

	activeRequests++
	loadingSet.value = new Set([...loadingSet.value, timeBucket])
	try {
		await store.fetchPersonBucketAsset(props.id, timeBucket)
	} finally {
		loadingSet.value = new Set([...loadingSet.value].filter(b => b !== timeBucket))
		activeRequests--
		if (pendingQueue.length > 0) pendingQueue.shift()()
	}
}

async function selectBucket(index) {
	const bucket = store.personBuckets[index]
	if (!bucket) return
	await loadBucket(bucket.timeBucket)
	store.toggleAssetsSelection((store.personBucketAssets[bucket.timeBucket] || []).map(asset => asset.id))
}

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(store.personBucketAssets)
	if (loadedKeys.length <= MAX_LOADED_BUCKETS) return
	const visibleKeys = new Set(currentIndices.map(i => store.personBuckets[i].timeBucket))
	for (const key of loadedKeys) {
		if (visibleKeys.has(key)) continue
		if (Object.keys(store.personBucketAssets).length <= MAX_LOADED_BUCKETS) break
		store.unloadPersonBucketAsset(key)
	}
}

watch(windowIndices, (indices) => {
	for (const i of indices) {
		const bucket = store.personBuckets[i]
		if (bucket && !store.personBucketAssets[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket)
		}
	}
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const allAssets = []
	let globalIdx = 0
	for (let i = 0; i < store.personBuckets.length; i++) {
		const bucketAssets = store.personBucketAssets[store.personBuckets[i].timeBucket]
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
	router.push({ name: 'people' })
}

async function load() {
	await store.fetchPersonBuckets(props.id)
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

onMounted(() => {
	load()
	if (store.people.length === 0) {
		store.fetchPeople()
	}
})
watch(() => props.id, load)

onBeforeUnmount(() => {
	if (scrollRaf) cancelAnimationFrame(scrollRaf)
	resizeObserver?.disconnect()
	pendingQueue.length = 0
	activeRequests = 0
})
</script>

<style scoped>
.person-detail {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.person-detail__loading {
	display: flex;
	justify-content: center;
	margin-top: 64px;
}

.person-detail__header {
	display: flex;
	flex-direction: column;
	padding: 8px 16px 6px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
}

.person-detail__header-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.person-detail__avatar {
	width: 48px;
	height: 48px;
	border-radius: 8px;
	object-fit: cover;
	object-position: center top;
	flex-shrink: 0;
	margin-left: auto;
}

.person-detail__meta-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 2px;
}

.person-detail__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding-left: 4px;
}

.person-detail__scroll {
	flex: 1;
	overflow-y: auto;
	position: relative;
}

.person-detail__sticky-date {
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

.person-detail__sticky-label {
	font-size: 13px;
	font-weight: 600;
	letter-spacing: 0.01em;
	color: var(--color-main-text);
}

.person-detail__sticky-count {
	font-size: 11px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	border-radius: 20px;
	padding: 1px 7px;
}

.person-detail__runway {
	position: relative;
	width: 100%;
}

.person-detail__bucket {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	padding: 15px 16px 0;
}

.person-detail__bucket-header {
	height: 32px;
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	gap: 8px;
}

.person-detail__bucket-label { font-size: 13px; font-weight: 600; color: var(--color-main-text); }
.person-detail__bucket-count { font-size: 11px; color: var(--color-text-maxcontrast); }
.person-detail__select-bucket {
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
.person-detail__select-bucket:hover { color: var(--color-main-text); background: var(--color-background-hover); }
.person-detail__select-bucket:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

.person-detail__bucket-loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.person-detail__bucket-placeholder {
	background: var(--color-background-dark);
	border-radius: 8px;
	opacity: 0.15;
}

@media (max-width: 480px) {
	.person-detail__header {
		padding: 8px 8px 6px;
	}

	.person-detail__bucket {
		padding: 0 8px;
	}
}

/* ---- Layout toggle (shared style pattern) ---- */
.person-detail__layout-toggle {
	margin-left: auto;
	display: flex;
	gap: 2px;
}

.person-detail__layout-btn {
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

.person-detail__layout-btn:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.person-detail__layout-btn:focus-visible {
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.person-detail__layout-btn--active {
	color: var(--color-primary);
	background: var(--color-primary-element-light);
}
</style>
