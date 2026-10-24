/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { getTimeline, getPartners, getAlbums, getAlbum, getPeople, getMapMarkers, searchByLocation, getExplore, getMe, getFolderContent } from '../services/api.js'
import { appStorage } from '../services/storage.js'

const BUCKET_CACHE_KEY = includePartners => `timeline_buckets_${includePartners ? 'with_partners' : 'without_partners'}`
const BUCKET_CACHE_TTL_MS = 5 * 60 * 1000 // 5 minutes
const LAYOUT_STORAGE_KEY = 'grid_layout'

export const useImmichStore = defineStore('immich', {
	state: () => ({
		// Timeline
		includePartnerAssets: loadState('integration_immich', 'user-config', {}).include_partner_assets !== false,
		timelineBuckets: [],
		timelineAssets: {},
		partnerBuckets: {},
		partnerAssets: {},
		partnerBucketGenerations: {},
		partners: [],
		currentPartnerId: null,
		// Filtered timelines (keyed by assetType: 'IMAGE' | 'VIDEO')
		filteredBuckets: { IMAGE: [], VIDEO: [] },
		filteredAssets: { IMAGE: {}, VIDEO: {} },
		// Favorites
		favoriteBuckets: [],
		favoriteAssets: {},
		// Albums
		albums: [],
		currentAlbum: null,
		currentAlbumDetailId: null,
		albumDetailLoading: false,
		// People list
		people: [],
		// Person detail — lazy-loaded buckets (same pattern as timeline)
		currentPersonId: null,
		personBuckets: [],
		personBucketAssets: {},
		personBucketsGeneration: 0,
		personBucketsLoading: false,
		// Album detail — lazy-loaded buckets
		currentAlbumId: null,
		albumBuckets: [],
		albumBucketAssets: {},
		albumBucketsGeneration: 0,
		albumBucketsLoading: false,
		albumDetailRequestGeneration: 0,
		// Map
		mapMarkers: [],
		// Place detail — full asset objects (with width/height) from metadata search
		placeAssets: [],
		currentPlaceField: null,
		currentPlaceValue: null,
		// Explore
		exploreData: [],
		// Folders
		currentFolderPath: '/',
		folderBasePath: '/',
		folderSubdirs: [],
		folderAssets: [],
		// UI
		loading: false,
		error: null,
		// Lightbox
		lightbox: {
			visible: false,
			assets: [],
			currentIndex: 0,
		},
		// Selection
		selectedAssetIds: new Set(),
		isSelectionMode: false,
		// Layout preference ('grid' | 'masonry') — persisted in localStorage
		gridLayout: appStorage.getItem(LAYOUT_STORAGE_KEY) ?? 'grid',
		// Current Immich user — used to determine album ownership
		currentUserId: null,
	}),

	actions: {
		// ---- Layout ----

		setGridLayout(layout) {
			this.gridLayout = layout
			appStorage.setItem(LAYOUT_STORAGE_KEY, layout)
		},

		// ---- Timeline ----

		async fetchTimelineBuckets() {
			this.loading = true
			this.error = null
			const bucketCacheKey = BUCKET_CACHE_KEY(this.includePartnerAssets)

			// Populate from cache immediately for instant display (stale-while-revalidate)
			try {
				const cached = appStorage.getItem(bucketCacheKey)
				if (cached) {
					const { buckets, ts } = JSON.parse(cached)
					if (Date.now() - ts < BUCKET_CACHE_TTL_MS && Array.isArray(buckets) && buckets.length > 0) {
						this.timelineBuckets = buckets
						this.loading = false
					}
				}
			} catch { /* ignore malformed cache */ }

			try {
				const response = await getTimeline({ withPartners: this.includePartnerAssets })
				this.timelineBuckets = Array.isArray(response.data) ? response.data : []
				// Persist fresh bucket list for next load
				try {
					appStorage.setItem(bucketCacheKey, JSON.stringify({ buckets: this.timelineBuckets, ts: Date.now() }))
				} catch { /* storage full or unavailable — non-fatal */ }
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchTimelineBucket(timeBucket, signal = null) {
			if (this.timelineAssets[timeBucket]) {
				return true
			}
			try {
				const response = await getTimeline({ timeBucket, withPartners: this.includePartnerAssets }, signal)
				this.timelineAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
				return true
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return false
				this.error = e.response?.data?.error || e.message
				throw e
			}
		},

		unloadTimelineBucket(timeBucket) {
			delete this.timelineAssets[timeBucket]
		},

		async fetchPartners() {
			this.loading = true
			this.error = null
			try {
				const response = await getPartners()
				this.partners = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchPartnerBuckets(partnerId) {
			const requestGeneration = (this.partnerBucketGenerations[partnerId] || 0) + 1
			this.partnerBucketGenerations[partnerId] = requestGeneration
			this.loading = true
			this.error = null
			this.currentPartnerId = partnerId
			if (!this.partnerAssets[partnerId]) this.partnerAssets[partnerId] = {}
			try {
				const response = await getTimeline({ userId: partnerId })
				if (this.currentPartnerId === partnerId && this.partnerBucketGenerations[partnerId] === requestGeneration) {
					this.partnerBuckets[partnerId] = Array.isArray(response.data) ? response.data : []
				}
			} catch (e) {
				if (this.currentPartnerId === partnerId && this.partnerBucketGenerations[partnerId] === requestGeneration) {
					this.error = e.response?.data?.error || e.message
				}
			} finally {
				if (this.currentPartnerId === partnerId && this.partnerBucketGenerations[partnerId] === requestGeneration) {
					this.loading = false
				}
			}
		},

		async fetchPartnerBucket(partnerId, timeBucket, signal = null) {
			const requestGeneration = this.partnerBucketGenerations[partnerId] || 0
			if (!this.partnerAssets[partnerId]) this.partnerAssets[partnerId] = {}
			if (this.partnerAssets[partnerId][timeBucket]) return true
			try {
				const response = await getTimeline({ userId: partnerId, timeBucket }, signal)
				if (this.currentPartnerId !== partnerId
					|| this.partnerBucketGenerations[partnerId] !== requestGeneration) return false
					this.partnerAssets[partnerId][timeBucket] = Array.isArray(response.data) ? response.data : []
					return true
			} catch (e) {
					if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return false
				if (this.currentPartnerId === partnerId
					&& this.partnerBucketGenerations[partnerId] === requestGeneration) {
					this.error = e.response?.data?.error || e.message
				}
					throw e
			}
		},

		unloadPartnerBucket(partnerId, timeBucket) {
			if (this.partnerAssets[partnerId]) delete this.partnerAssets[partnerId][timeBucket]
		},

		// ---- Filtered timelines (Fotos / Videos) ----

		async fetchFilteredBuckets(assetType) {
			this.loading = true
			this.error = null
			try {
				// Immich's timeline/buckets endpoint does not support assetType filtering.
				// Reuse the main timeline bucket structure; PHP will filter the asset content.
				if (this.timelineBuckets.length === 0) {
					const response = await getTimeline({ withPartners: this.includePartnerAssets })
					this.timelineBuckets = Array.isArray(response.data) ? response.data : []
				}
				this.filteredBuckets[assetType] = this.timelineBuckets.map(b => {
					const copy = { ...b }
					const loaded = this.filteredAssets[assetType][b.timeBucket]
					if (loaded) copy.count = loaded.length
					return copy
				})
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchFilteredBucket(assetType, timeBucket, signal = null) {
			if (this.filteredAssets[assetType][timeBucket]) return true
			try {
				// Pass assetType so PHP backend can filter the returned assets by type
				const response = await getTimeline({ assetType, timeBucket, withPartners: this.includePartnerAssets }, signal)
				const assets = Array.isArray(response.data) ? response.data : []
				this.filteredAssets[assetType][timeBucket] = assets
				// Update bucket count to match actual filtered asset count for correct height estimation
				const bucket = this.filteredBuckets[assetType].find(b => b.timeBucket === timeBucket)
				if (bucket) {
					bucket.count = assets.length
				}
				return true
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return false
				this.error = e.response?.data?.error || e.message
				throw e
			}
		},

		unloadFilteredBucket(assetType, timeBucket) {
			delete this.filteredAssets[assetType][timeBucket]
		},

		// ---- Favorites ----

		async fetchFavoriteBuckets() {
			this.loading = true
			this.error = null
			try {
				const response = await getTimeline({ isFavorite: true })
				this.favoriteBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		async fetchFavoriteBucket(timeBucket, signal = null) {
			if (this.favoriteAssets[timeBucket]) return true
			try {
				const response = await getTimeline({ isFavorite: true, timeBucket }, signal)
				this.favoriteAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
				return true
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return false
				this.error = e.response?.data?.error || e.message
				throw e
			}
		},

		unloadFavoriteBucket(timeBucket) {
			delete this.favoriteAssets[timeBucket]
		},

		// ---- Albums ----

		async fetchAlbums() {
			this.loading = true
			this.error = null
			try {
				const response = await getAlbums()
				this.albums = response.data
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchAlbum(id) {
			const requestGeneration = ++this.albumDetailRequestGeneration
			this.currentAlbumDetailId = null
			this.albumDetailLoading = true
			this.error = null
			try {
				const response = await getAlbum(id)
				if (requestGeneration === this.albumDetailRequestGeneration) {
					this.currentAlbum = response.data
					this.currentAlbumDetailId = id
				}
			} catch (e) {
				if (requestGeneration === this.albumDetailRequestGeneration) {
					this.error = e.response?.data?.error || e.message
				}
			} finally {
				if (requestGeneration === this.albumDetailRequestGeneration) {
					this.albumDetailLoading = false
				}
			}
		},

		// ---- Current Immich user ----

		async fetchCurrentUser() {
			try {
				const response = await getMe()
				this.currentUserId = response.data?.id ?? null
			} catch {
				// Non-fatal — album ownership checks will simply not work
				this.currentUserId = null
			}
		},

		// ---- People list ----

		async fetchPeople() {
			this.loading = true
			this.error = null
			try {
				const response = await getPeople()
				this.people = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Person detail (lazy buckets) ----

		async fetchPersonBuckets(id) {
			const requestGeneration = ++this.personBucketsGeneration
			this.personBucketsLoading = true
			this.error = null
			this.currentPersonId = id
			this.personBuckets = []
			this.personBucketAssets = {}
			try {
				const response = await getTimeline({ personId: id })
				if (this.currentPersonId === id && this.personBucketsGeneration === requestGeneration) {
					this.personBuckets = Array.isArray(response.data) ? response.data : []
				}
			} catch (e) {
				if (this.currentPersonId !== id || this.personBucketsGeneration !== requestGeneration) return
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				if (this.currentPersonId === id && this.personBucketsGeneration === requestGeneration) {
					this.personBucketsLoading = false
				}
			}
		},

		async fetchPersonBucketAsset(personId, timeBucket, signal = null) {
			const requestGeneration = this.personBucketsGeneration
			if (this.personBucketAssets[timeBucket]) {
				return true
			}
			try {
				const response = await getTimeline({ personId, timeBucket }, signal)
				if (this.currentPersonId === personId && this.personBucketsGeneration === requestGeneration) {
					this.personBucketAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
					return true
				}
				return false
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return false
				if (this.currentPersonId === personId && this.personBucketsGeneration === requestGeneration) {
					this.error = e.response?.data?.error || e.message
				}
				throw e
			}
		},

		unloadPersonBucketAsset(timeBucket) {
			delete this.personBucketAssets[timeBucket]
		},

		// ---- Album detail (lazy buckets) ----

		async fetchAlbumBuckets(albumId) {
			const requestGeneration = ++this.albumBucketsGeneration
			this.albumBucketsLoading = true
			this.error = null
			this.currentAlbumId = albumId
			this.albumBuckets = []
			this.albumBucketAssets = {}
			try {
				const response = await getTimeline({ albumId })
				if (this.currentAlbumId === albumId && this.albumBucketsGeneration === requestGeneration) {
					this.albumBuckets = Array.isArray(response.data) ? response.data : []
				}
			} catch (e) {
				if (this.currentAlbumId !== albumId || this.albumBucketsGeneration !== requestGeneration) return
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				if (this.currentAlbumId === albumId && this.albumBucketsGeneration === requestGeneration) {
					this.albumBucketsLoading = false
				}
			}
		},

		async fetchAlbumBucketAssets(albumId, timeBucket, signal = null) {
			const requestGeneration = this.albumBucketsGeneration
			if (this.albumBucketAssets[timeBucket]) return true
			try {
				const response = await getTimeline({ albumId, timeBucket }, signal)
				if (this.currentAlbumId === albumId && this.albumBucketsGeneration === requestGeneration) {
					this.albumBucketAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
					return true
				}
				return false
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return false
				if (this.currentAlbumId === albumId && this.albumBucketsGeneration === requestGeneration) {
					this.error = e.response?.data?.error || e.message
				}
				throw e
			}
		},

		unloadAlbumBucketAsset(timeBucket) {
			delete this.albumBucketAssets[timeBucket]
		},

		// ---- Map ----

		async fetchMapMarkers() {
			this.loading = true
			this.error = null
			try {
				const response = await getMapMarkers()
				this.mapMarkers = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Place detail ----

		async fetchPlaceAssets(field, value) {
			this.loading = true
			this.error = null
			this.currentPlaceField = field
			this.currentPlaceValue = value
			this.placeAssets = []
			try {
				const response = await searchByLocation(field, value)
				this.placeAssets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				const isTimeout = e.code === 'ECONNABORTED' || e.message?.includes('timeout')
				this.error = isTimeout
					? t('integration_immich', 'Request timed out — Immich may be slow or unreachable. Check your server connection.')
					: (e.response?.data?.error || e.message)
			} finally {
				this.loading = false
			}
		},

		// ---- Explore ----

		async fetchExplore() {
			this.loading = true
			this.error = null
			try {
				const response = await getExplore()
				this.exploreData = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Folders ----

		async fetchFolderContent(path = '/') {
			this.loading = true
			this.error = null
			this.currentFolderPath = path
			this.folderSubdirs = []
			this.folderAssets = []
			try {
				const response = await getFolderContent(path)
				const data = response.data || {}
				this.folderSubdirs = Array.isArray(data.folders) ? data.folders : []
				this.folderAssets = Array.isArray(data.assets) ? data.assets : []
				// Controller may return the auto-detected base path and the effective
				// path it actually used (e.g. when the caller passed '/')
				if (data.basePath) this.folderBasePath = data.basePath
				if (data.currentPath) this.currentFolderPath = data.currentPath
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		// ---- Lightbox ----

		openLightbox(assets, index = 0) {
			this.lightbox.assets = assets
			this.lightbox.currentIndex = index
			this.lightbox.visible = true
		},

		closeLightbox() {
			this.lightbox.visible = false
			this.lightbox.assets = []
			this.lightbox.currentIndex = 0
		},

		lightboxNext() {
			if (this.lightbox.currentIndex < this.lightbox.assets.length - 1) {
				this.lightbox.currentIndex++
			}
		},

		lightboxPrev() {
			if (this.lightbox.currentIndex > 0) {
				this.lightbox.currentIndex--
			}
		},

		patchLightboxAsset(index, assetData) {
			if (this.lightbox.assets && index >= 0 && index < this.lightbox.assets.length) {
				this.lightbox.assets[index] = assetData
			}
		},

		removeAssetFromLightbox(assetId) {
			const index = this.lightbox.assets.findIndex(a => a.id === assetId)
			if (index === -1) return

			// Remove from lightbox assets array
			this.lightbox.assets.splice(index, 1)

			// Also remove from all cached timeline/filtered/favorite data
			this.removeAssetFromAllCaches(assetId)

			// Adjust current index if needed
			if (this.lightbox.currentIndex >= this.lightbox.assets.length) {
				this.lightbox.currentIndex = Math.max(0, this.lightbox.assets.length - 1)
			}
		},

		removeAssetFromAllCaches(assetId) {
			// Remove from timeline assets
			for (const bucket in this.timelineAssets) {
				this.timelineAssets[bucket] = this.timelineAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from filtered assets (photos/videos)
			for (const type in this.filteredAssets) {
				for (const bucket in this.filteredAssets[type]) {
					this.filteredAssets[type][bucket] = this.filteredAssets[type][bucket].filter(a => a.id !== assetId)
				}
			}

			// Remove from favorite assets
			for (const bucket in this.favoriteAssets) {
				this.favoriteAssets[bucket] = this.favoriteAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from person bucket assets
			for (const bucket in this.personBucketAssets) {
				this.personBucketAssets[bucket] = this.personBucketAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from album bucket assets
			for (const bucket in this.albumBucketAssets) {
				this.albumBucketAssets[bucket] = this.albumBucketAssets[bucket].filter(a => a.id !== assetId)
			}

			// Remove from currentAlbum flat assets (used by AssetPicker)
			if (this.currentAlbum && this.currentAlbum.assets) {
				this.currentAlbum.assets = this.currentAlbum.assets.filter(a => a.id !== assetId)
			}

			// Remove from place assets
			this.placeAssets = this.placeAssets.filter(a => a.id !== assetId)

			// Remove from explore data
			this.exploreData.forEach(group => {
				if (group.assets) {
					group.assets = group.assets.filter(a => a.id !== assetId)
				}
			})
		},

		// ---- Selection ----

		enterSelectionMode() {
			this.isSelectionMode = true
		},

		toggleAssetSelection(id) {
			if (this.selectedAssetIds.has(id)) {
				this.selectedAssetIds.delete(id)
			} else {
				this.selectedAssetIds.add(id)
			}
		},

		toggleAssetsSelection(ids) {
			if (ids.length === 0) return
			const next = new Set(this.selectedAssetIds)
		const deselect = ids.every(id => next.has(id))
		for (const id of ids) {
			if (deselect) next.delete(id)
			else next.add(id)
		}
		this.selectedAssetIds = next
			this.isSelectionMode = true
		},

		clearSelection() {
			this.selectedAssetIds = new Set()
			this.isSelectionMode = false
		},

		// ---- Cache invalidation ----

		invalidateFavoriteCache() {
			this.favoriteBuckets = []
			this.favoriteAssets = {}
		},

		// ---- Asset patching ----

		// Update isFavorite in-place across ALL loaded caches so the UI reflects
		// the change immediately without a full reload.
		patchAssetFavorite(ids, isFavorite) {
			const idSet = new Set(ids)

			const patchList = (list) => {
				for (let i = 0; i < list.length; i++) {
					if (idSet.has(list[i].id)) {
						list[i] = { ...list[i], isFavorite }
					}
				}
			}

			// Timeline
			for (const key of Object.keys(this.timelineAssets)) {
				patchList(this.timelineAssets[key])
			}
			// Filtered (Fotos / Videos)
			for (const cache of Object.values(this.filteredAssets)) {
				for (const key of Object.keys(cache)) {
					patchList(cache[key])
				}
			}
			// Favorites
			for (const key of Object.keys(this.favoriteAssets)) {
				patchList(this.favoriteAssets[key])
			}
			// Person detail
			for (const key of Object.keys(this.personBucketAssets)) {
				patchList(this.personBucketAssets[key])
			}
			// Album bucket detail
			for (const key of Object.keys(this.albumBucketAssets)) {
				patchList(this.albumBucketAssets[key])
			}
			// Album flat assets (used by AssetPicker)
			if (this.currentAlbum?.assets) {
				patchList(this.currentAlbum.assets)
			}
			// Place detail
			patchList(this.placeAssets)
			// Lightbox assets
			patchList(this.lightbox.assets)
		},
	},

	getters: {
		// Flat map of all currently loaded assets (id → asset object) across all caches.
		// Used to check isFavorite status of selected assets without extra API calls.
		allLoadedAssetsMap(state) {
			const map = {}
			// Timeline
			for (const assets of Object.values(state.timelineAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Filtered (photos / videos)
			for (const cache of Object.values(state.filteredAssets)) {
				for (const assets of Object.values(cache)) {
					for (const a of assets) map[a.id] = a
				}
			}
			// Favorites
			for (const assets of Object.values(state.favoriteAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Person detail
			for (const assets of Object.values(state.personBucketAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Album bucket detail
			for (const assets of Object.values(state.albumBucketAssets)) {
				for (const a of assets) map[a.id] = a
			}
			// Album flat assets (AssetPicker)
			if (state.currentAlbum?.assets) {
				for (const a of state.currentAlbum.assets) map[a.id] = a
			}
			// Place detail
			for (const a of state.placeAssets) map[a.id] = a
			return map
		},
	},
})
