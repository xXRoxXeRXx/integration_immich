<!--
  - SPDX-FileCopyright: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="person-detail">
		<!-- Loading state: initial bucket-list fetch -->
		<NcLoadingIcon v-if="store.personBucketsLoading && store.personBuckets.length === 0"
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

			<NcEmptyContent v-if="store.personBuckets.length === 0 && !store.personBucketsLoading"
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
				<div ref="stickyDate" class="person-detail__sticky-date">
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
							:style="{ height: Math.max(0, bucketHeights[index] - bucketGeometry.verticalChrome) + 'px' }" />
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
const MAX_LOADED_BUCKETS = 12

// --- Reactive state ---
const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
scheduler.setDataset(`person:${props.id}`)
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
const personName = computed(() => {
	const p = store.people.find(p => p.id === props.id)
	return p?.name || t('integration_immich', 'Unknown')
})

const totalCount = computed(() =>
	store.personBuckets.reduce((sum, b) => sum + (b.count || 0), 0),
)

const bucketGeometry = computed(() => getResponsiveBucketGeometry({
	containerWidth: containerWidth.value,
	viewportWidth: viewportWidth.value,
	gridBreakpoint: 480,
	bucketPaddingBreakpoint: 480,
	desktopVerticalChrome: 55,
	mobileVerticalChrome: 40,
}))

const bucketHeights = computed(() =>
	store.personBuckets.map(b => {
		if (store.gridLayout === 'masonry') {
			return estimateMasonryBucketHeight(b.count, store.personBucketAssets[b.timeBucket], bucketGeometry.value)
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
	if (store.personBuckets.length === 0) return null
	return store.personBuckets[getBucketIndexAtOffset(bucketOffsets.value, scrollTop.value, stickyOffset.value)]
})

const currentBucketLabel = computed(() =>
	currentBucket.value ? formatBucketDate(currentBucket.value.timeBucket) : ''
)

const currentBucketCount = computed(() =>
	currentBucket.value?.count ?? 0
)

// --- Lazy bucket loading ---
function loadBucket(timeBucket, options = {}) {
	if (store.personBucketAssets[timeBucket]) return Promise.resolve(true)
	const personId = props.id
	return scheduler.request(
		timeBucket,
		signal => store.fetchPersonBucketAsset(personId, timeBucket, signal),
		options,
	)
}

async function selectBucket(index) {
	const bucket = store.personBuckets[index]
	if (!bucket) return
	const personId = props.id
	const datasetId = `person:${personId}`
	const loaded = await loadBucket(bucket.timeBucket, { priority: -1, retain: true })
	if (!loaded || props.id !== personId || scheduler.datasetId !== datasetId) return
	store.toggleAssetsSelection((store.personBucketAssets[bucket.timeBucket] || []).map(asset => asset.id))
}

function evictDistantBuckets(currentIndices) {
	const loadedKeys = Object.keys(store.personBucketAssets)
	const visibleKeys = new Set(currentIndices.map(i => store.personBuckets[i].timeBucket))
	evictLoadedBuckets(loadedKeys, visibleKeys, MAX_LOADED_BUCKETS, key => store.unloadPersonBucketAsset(key))
}

watch(windowIndices, (indices) => {
	if (containerWidth.value <= 0) {
		scheduler.setVisibleKeys([])
		return
	}
	scheduler.setVisibleKeys(indices.map(i => store.personBuckets[i]?.timeBucket).filter(key => key !== undefined))
	const prioritizedIndices = [...indices].sort((a, b) => getWindowPriority(a, indices) - getWindowPriority(b, indices))
	for (const i of prioritizedIndices) {
		const bucket = store.personBuckets[i]
		if (bucket && !store.personBucketAssets[bucket.timeBucket]) {
			loadBucket(bucket.timeBucket, { priority: getWindowPriority(i, indices) })
		}
	}
	evictDistantBuckets(indices)
}, { immediate: true })

function openLightboxFromBucket(localIdx, bucketIndex) {
	const { assets, index } = getLightboxAssetsInBucketOrder(
		store.personBuckets,
		store.personBucketAssets,
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
	router.push({ name: 'people' })
}

async function load() {
	await store.fetchPersonBuckets(props.id)
}

onMounted(() => {
	load()
	if (store.people.length === 0) {
		store.fetchPeople()
	}
})
watch(() => props.id, (id) => {
	scheduler.setDataset(`person:${id}`)
	resetScroll()
	load()
})

onBeforeUnmount(() => {
	scheduler.destroy()
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
