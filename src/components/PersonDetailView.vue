<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="person-detail">
		<!-- Loading state: initial bucket-list fetch -->
		<NcLoadingIcon v-if="store.loading && store.personBuckets.length === 0"
			:size="64"
			class="person-detail__loading" />

		<NcEmptyContent v-else-if="store.error"
			:name="t('integration_immich', 'Fehler')"
			:description="store.error">
			<template #icon>
				<AlertIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Sticky header with back button + person info -->
			<div class="person-detail__header">
				<NcButton variant="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('integration_immich', 'Zurück') }}
				</NcButton>
				<div class="person-detail__title">
					<img :src="getPersonThumbnailUrl(props.id)"
						class="person-detail__avatar"
						:alt="personName">
					<div>
						<h2>{{ personName }}</h2>
						<span class="person-detail__count">
							{{ t('integration_immich', '{count} Bilder', { count: totalCount }) }}
						</span>
					</div>
				</div>
			</div>

			<NcEmptyContent v-if="store.personBuckets.length === 0 && !store.loading"
				:name="t('integration_immich', 'Keine Bilder')"
				:description="t('integration_immich', 'Keine Bilder für diese Person gefunden.')">
				<template #icon>
					<AccountIcon :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Virtual scroll — same pattern as TimelineView -->
			<div v-else
				ref="scrollContainer"
				class="person-detail__scroll"
				@scroll="onScroll">
				<div class="person-detail__runway" :style="{ height: totalHeight + 'px' }">
					<div v-for="index in windowIndices"
						:key="store.personBuckets[index].timeBucket"
						class="person-detail__bucket"
						:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
						<h3 class="person-detail__bucket-header">
							{{ formatBucketDate(store.personBuckets[index].timeBucket) }}
							<span class="person-detail__bucket-count">
								({{ store.personBuckets[index].count }})
							</span>
						</h3>
						<NcLoadingIcon v-if="loadingSet.has(store.personBuckets[index].timeBucket)"
							:size="32"
							class="person-detail__bucket-loading" />
						<PhotoGrid v-else-if="store.personBucketAssets[store.personBuckets[index].timeBucket]"
							:assets="store.personBucketAssets[store.personBuckets[index].timeBucket]"
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
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useImmichStore } from '../store/immich.js'
import { getPersonThumbnailUrl } from '../services/api.js'
import PhotoGrid from './PhotoGrid.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'

const props = defineProps({
	id: { type: String, required: true },
})

const store = useImmichStore()
const router = useRouter()

// --- Constants (match TimelineView) ---
const HEADER_HEIGHT = 40
const ROW_HEIGHT = 210
const ITEMS_PER_ROW = 5
const OVERSCAN = 800
const MAX_CONCURRENT = 2
const MAX_LOADED_BUCKETS = 12

// --- Reactive state ---
const scrollContainer = ref(null)
const scrollTop = ref(0)
const viewportHeight = ref(800)
const loadingSet = ref(new Set())

let activeRequests = 0
const pendingQueue = []

// --- Computed ---
const personName = computed(() => {
	const p = store.people.find(p => p.id === props.id)
	return p?.name || t('integration_immich', 'Unbekannt')
})

const totalCount = computed(() =>
	store.personBuckets.reduce((sum, b) => sum + (b.count || 0), 0),
)

function estimateBucketHeight(count) {
	const rows = Math.ceil(count / ITEMS_PER_ROW)
	return HEADER_HEIGHT + rows * ROW_HEIGHT
}

const bucketHeights = computed(() =>
	store.personBuckets.map(b => estimateBucketHeight(b.count)),
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
	if (scrollContainer.value) {
		viewportHeight.value = scrollContainer.value.clientHeight
	}
}

onMounted(load)
watch(() => props.id, load)

onBeforeUnmount(() => {
	if (scrollRaf) cancelAnimationFrame(scrollRaf)
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
	align-items: center;
	gap: 16px;
	padding: 8px 16px 8px 52px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.person-detail__title {
	display: flex;
	align-items: center;
	gap: 12px;
}

.person-detail__avatar {
	width: 48px;
	height: 48px;
	border-radius: 50%;
	object-fit: cover;
}

.person-detail__title h2 {
	margin: 0;
	font-size: 20px;
}

.person-detail__count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.person-detail__scroll {
	flex: 1;
	overflow-y: auto;
	position: relative;
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
	padding: 0 16px 0 52px;
}

.person-detail__bucket-header {
	font-size: 16px;
	font-weight: bold;
	margin: 8px 0 4px;
	color: var(--color-main-text);
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	z-index: 1;
}

.person-detail__bucket-count {
	font-size: 13px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

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
</style>
