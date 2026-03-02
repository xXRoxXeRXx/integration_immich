<!--
  - SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<Teleport to="body">
		<div class="asset-picker-overlay" @click.self="$emit('cancel')">
		<div class="asset-picker-modal">
			<!-- Header -->
			<div class="asset-picker-modal__header">
				<h3 class="asset-picker-modal__title">
					{{ t('integration_immich', 'Select photos for "{name}"', { name: albumName }) }}
				</h3>
				<span class="asset-picker-modal__hint">
					{{ selectedIds.size > 0
						? t('integration_immich', '{count} selected', { count: selectedIds.size })
						: t('integration_immich', 'Tap photos to select') }}
				</span>
			</div>

			<!-- Virtual scrolling body -->
			<div class="asset-picker-modal__body">
				<div v-if="initialLoading" class="asset-picker-modal__loading">
					<NcLoadingIcon :size="64" />
				</div>
				<div v-else-if="buckets.length === 0" class="asset-picker-modal__empty">
					{{ t('integration_immich', 'No photos found') }}
				</div>
				<div v-else
					ref="scrollContainer"
					class="asset-picker-modal__scroll"
					@scroll="onScroll">
					<!-- Sticky date label -->
					<div class="asset-picker-modal__sticky-date">
						{{ currentBucketLabel }}
					</div>
					<!-- Virtual runway -->
					<div class="asset-picker-modal__runway" :style="{ height: totalHeight + 'px' }">
						<div v-for="index in windowIndices"
							:key="buckets[index].timeBucket"
							class="asset-picker-modal__bucket"
							:style="{ transform: `translateY(${bucketOffsets[index]}px)` }">
							<NcLoadingIcon v-if="loadingSet.has(buckets[index].timeBucket)"
								:size="32"
								class="asset-picker-modal__bucket-loading" />
							<div v-else-if="assetsCache[buckets[index].timeBucket]"
								class="asset-picker-modal__grid">
								<div v-for="asset in assetsCache[buckets[index].timeBucket]"
									:key="asset.id"
									class="asset-picker-modal__item"
									:class="{
										'asset-picker-modal__item--selected': selectedIds.has(asset.id),
										'asset-picker-modal__item--existing': existingAssetIds.has(asset.id),
									}"
									@click="toggleAsset(asset.id)">
									<img :src="getThumbnailUrl(asset.id)"
										:alt="asset.originalFileName || ''"
										loading="lazy"
										class="asset-picker-modal__image">
									<!-- Video badge -->
									<div v-if="asset.isImage === false" class="asset-picker-modal__video-badge">
										<PlayIcon :size="16" />
									</div>
									<!-- Already in album badge -->
									<div v-if="existingAssetIds.has(asset.id)" class="asset-picker-modal__existing-badge">
										<CheckAllIcon :size="14" />
									</div>
									<!-- Selection checkbox -->
									<div v-else
										class="asset-picker-modal__checkbox"
										:class="{ 'asset-picker-modal__checkbox--checked': selectedIds.has(asset.id) }">
										<CheckIcon v-if="selectedIds.has(asset.id)" :size="14" />
									</div>
								</div>
							</div>
							<div v-else
								class="asset-picker-modal__bucket-placeholder"
								:style="{ height: bucketHeights[index] + 'px' }" />
						</div>
					</div>
				</div>
			</div>

			<!-- Footer -->
			<div class="asset-picker-modal__footer">
				<NcButton variant="tertiary" @click="$emit('cancel')">
					{{ t('integration_immich', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="creating || selectedIds.size === 0"
					@click="$emit('confirm', [...selectedIds])">
					<template #icon>
						<NcLoadingIcon v-if="creating" :size="20" />
						<CheckIcon v-else :size="20" />
					</template>
					{{ t('integration_immich', 'Add ({count})', { count: selectedIds.size }) }}
				</NcButton>
			</div>
		</div>
	</div>
	</Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { getTimeline, getThumbnailUrl } from '../services/api.js'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'

const props = defineProps({
	albumName: { type: String, required: true },
	creating: { type: Boolean, default: false },
	existingAssetIds: { type: Object, default: () => new Set() },
})

defineEmits(['confirm', 'cancel'])

// --- Selection state (local, no global store) ---
const selectedIds = ref(new Set())

function toggleAsset(id) {
	if (props.existingAssetIds.has(id)) return  // already in album, not selectable
	const next = new Set(selectedIds.value)
	if (next.has(id)) {
		next.delete(id)
	} else {
		next.add(id)
	}
	selectedIds.value = next
}

// --- Virtual scroll state ---
const buckets = ref([])          // [{timeBucket, count}]
const assetsCache = ref({})      // timeBucket → asset[]
const loadingSet = ref(new Set())
const initialLoading = ref(true)
const scrollContainer = ref(null)
const scrollTop = ref(0)
const viewportHeight = ref(600)

const GRID_MIN_ITEM = 130   // matches .asset-picker-modal__grid minmax(130px, 1fr)
const GRID_GAP = 4           // matches gap: 4px
const BUCKET_PADDING_LR = 16 // .asset-picker-modal__bucket padding: 0 8px → 8×2
const OVERSCAN = 600
const MAX_CONCURRENT = 2
const MAX_LOADED = 10

const containerWidth = ref(0)
let activeRequests = 0
const pendingQueue = []
let resizeObserver = null

// --- Height / offset calculations ---
function estimateHeight(count) {
	const available = Math.max(GRID_MIN_ITEM, containerWidth.value - BUCKET_PADDING_LR)
	const cols = Math.max(1, Math.floor((available + GRID_GAP) / (GRID_MIN_ITEM + GRID_GAP)))
	const colWidth = (available - (cols - 1) * GRID_GAP) / cols
	const rows = Math.ceil(count / cols)
	return rows * colWidth + (rows - 1) * GRID_GAP
}

const bucketHeights = computed(() =>
	buckets.value.map(b => estimateHeight(b.count))
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
	if (!h.length) return 0
	return bucketOffsets.value[h.length - 1] + h[h.length - 1]
})

// --- Sliding window ---
const windowIndices = computed(() => {
	const indices = []
	const top = scrollTop.value - OVERSCAN
	const bottom = scrollTop.value + viewportHeight.value + OVERSCAN
	for (let i = 0; i < buckets.value.length; i++) {
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

		// Load visible buckets
		for (const i of windowIndices.value) {
			const tb = buckets.value[i]?.timeBucket
			if (tb && !assetsCache.value[tb] && !loadingSet.value.has(tb)) {
				loadBucket(tb)
			}
		}
		evict()
	})
}

// --- Bucket loading ---
async function loadBucket(timeBucket) {
	if (assetsCache.value[timeBucket] || loadingSet.value.has(timeBucket)) return

	if (activeRequests >= MAX_CONCURRENT) {
		return new Promise(resolve => {
			pendingQueue.push(() => loadBucket(timeBucket).then(resolve))
		})
	}

	activeRequests++
	loadingSet.value = new Set([...loadingSet.value, timeBucket])

	try {
		const res = await getTimeline({ timeBucket, size: 'MONTH' })
		assetsCache.value = { ...assetsCache.value, [timeBucket]: Array.isArray(res.data) ? res.data : [] }
	} finally {
		loadingSet.value = new Set([...loadingSet.value].filter(b => b !== timeBucket))
		activeRequests--
		if (pendingQueue.length > 0) pendingQueue.shift()()
	}
}

function evict() {
	const loaded = Object.keys(assetsCache.value)
	if (loaded.length <= MAX_LOADED) return
	const visible = new Set(windowIndices.value.map(i => buckets.value[i].timeBucket))
	const next = { ...assetsCache.value }
	for (const key of loaded) {
		if (Object.keys(next).length <= MAX_LOADED) break
		if (!visible.has(key)) delete next[key]
	}
	assetsCache.value = next
}

// --- Date label ---
function formatBucket(timeBucket) {
	return new Date(timeBucket).toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
}

const currentBucketIndex = computed(() => {
	for (let i = buckets.value.length - 1; i >= 0; i--) {
		if (bucketOffsets.value[i] <= scrollTop.value) return i
	}
	return 0
})

const currentBucketLabel = computed(() =>
	buckets.value.length ? formatBucket(buckets.value[currentBucketIndex.value].timeBucket) : ''
)

// --- Init ---
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
	try {
		const res = await getTimeline({ size: 'MONTH' })
		buckets.value = Array.isArray(res.data) ? res.data : []
		// Immediately load the first visible buckets
		for (const i of windowIndices.value) {
			loadBucket(buckets.value[i].timeBucket)
		}
	} finally {
		initialLoading.value = false
	}
})

onBeforeUnmount(() => {
	if (scrollRaf) cancelAnimationFrame(scrollRaf)
	resizeObserver?.disconnect()
})
</script>

<style scoped>
.asset-picker-overlay {
	position: fixed;
	inset: 0;
	z-index: 9999;
	background: rgba(0, 0, 0, 0.65);
	display: flex;
	align-items: center;
	justify-content: center;
}

.asset-picker-modal {
	background: var(--color-main-background);
	border-radius: 12px;
	display: flex;
	flex-direction: column;
	width: min(90vw, 960px);
	height: min(85vh, 720px);
	overflow: hidden;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
}

.asset-picker-modal__header {
	display: flex;
	align-items: baseline;
	gap: 12px;
	padding: 16px 24px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.asset-picker-modal__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.asset-picker-modal__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	white-space: nowrap;
}

.asset-picker-modal__body {
	flex: 1;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.asset-picker-modal__loading,
.asset-picker-modal__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	color: var(--color-text-maxcontrast);
}

.asset-picker-modal__scroll {
	flex: 1;
	overflow-y: auto;
	position: relative;
}

.asset-picker-modal__sticky-date {
	position: sticky;
	top: 0;
	z-index: 10;
	padding: 4px 8px;
	font-size: 15px;
	font-weight: bold;
	background: var(--color-main-background);
	pointer-events: none;
}

.asset-picker-modal__runway {
	position: relative;
	width: 100%;
}

.asset-picker-modal__bucket {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	padding: 0 8px;
}

.asset-picker-modal__bucket-loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.asset-picker-modal__bucket-placeholder {
	background: var(--color-background-dark);
	border-radius: 8px;
	opacity: 0.15;
}

.asset-picker-modal__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 4px;
}

.asset-picker-modal__item {
	position: relative;
	aspect-ratio: 1;
	overflow: hidden;
	border-radius: 4px;
	cursor: pointer;
	border: 3px solid transparent;
	transition: border-color 0.1s;
}

.asset-picker-modal__item--selected {
	border-color: var(--color-primary);
}

.asset-picker-modal__image {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.asset-picker-modal__checkbox {
	position: absolute;
	top: 5px;
	left: 5px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	border: 2px solid #fff;
	background: rgba(0, 0, 0, 0.35);
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
	transition: background 0.1s;
}

.asset-picker-modal__checkbox--checked {
	background: var(--color-primary);
	border-color: var(--color-primary);
}

.asset-picker-modal__item--existing {
	opacity: 0.55;
	cursor: default;
}

.asset-picker-modal__existing-badge {
	position: absolute;
	top: 5px;
	left: 5px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	background: var(--color-success);
	border: 2px solid var(--color-success);
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
}

.asset-picker-modal__video-badge {
	position: absolute;
	bottom: 6px;
	right: 6px;
	background: rgba(0, 0, 0, 0.6);
	border-radius: 3px;
	padding: 2px 4px;
	color: #fff;
	display: flex;
	align-items: center;
}

.asset-picker-modal__footer {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 8px;
	padding: 12px 24px;
	border-top: 1px solid var(--color-border);
	flex-shrink: 0;
}
</style>
