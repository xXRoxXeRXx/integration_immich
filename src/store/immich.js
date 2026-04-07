/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import { getTimeline, getAlbums, getAlbum, getPeople, getMapMarkers, getExplore } from '../services/api.js'

export const useImmichStore = defineStore('immich', {
	state: () => ({
		// Timeline
		timelineBuckets: [],
		timelineAssets: {},
		// Filtered timelines (keyed by assetType: 'IMAGE' | 'VIDEO')
		filteredBuckets: { IMAGE: [], VIDEO: [] },
		filteredAssets: { IMAGE: {}, VIDEO: {} },
		// Favorites
		favoriteBuckets: [],
		favoriteAssets: {},
		// Albums
		albums: [],
		currentAlbum: null,
		// People list
		people: [],
		// Person detail — lazy-loaded buckets (same pattern as timeline)
		currentPersonId: null,
		personBuckets: [],
		personBucketAssets: {},
		// Map
		mapMarkers: [],
		// Explore
		exploreData: [],
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
	}),

	actions: {
		// ---- Timeline ----

		async fetchTimelineBuckets() {
			this.loading = true
			this.error = null
			try {
				const response = await getTimeline()
				this.timelineBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchTimelineBucket(timeBucket, signal = null) {
			if (this.timelineAssets[timeBucket]) {
				return
			}
			try {
				const response = await getTimeline({ timeBucket }, signal)
				this.timelineAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadTimelineBucket(timeBucket) {
			delete this.timelineAssets[timeBucket]
		},

		// ---- Filtered timelines (Fotos / Videos) ----

		async fetchFilteredBuckets(assetType) {
			this.loading = true
			this.error = null
			try {
				// Immich's timeline/buckets endpoint does not support assetType filtering.
				// Reuse the main timeline bucket structure; PHP will filter the asset content.
				if (this.timelineBuckets.length === 0) {
					const response = await getTimeline()
					this.timelineBuckets = Array.isArray(response.data) ? response.data : []
				}
				this.filteredBuckets[assetType] = this.timelineBuckets.map(b => {
					const copy = { ...b }
					const loaded = this.filteredAssets[assetType][b.timeBucket]
					if (loaded) copy.count = loaded.length
					return copy
				})
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchFilteredBucket(assetType, timeBucket, signal = null) {
			if (this.filteredAssets[assetType][timeBucket]) return
			try {
				// Pass assetType so PHP backend can filter the returned assets by type
				const response = await getTimeline({ assetType, timeBucket }, signal)
				const assets = Array.isArray(response.data) ? response.data : []
				this.filteredAssets[assetType][timeBucket] = assets
				// Update bucket count to match actual filtered asset count for correct height estimation
				const bucket = this.filteredBuckets[assetType].find(b => b.timeBucket === timeBucket)
				if (bucket) {
					bucket.count = assets.length
				}
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
				this.error = e.response?.data?.error || e.message
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
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchFavoriteBucket(timeBucket, signal = null) {
			if (this.favoriteAssets[timeBucket]) return
			try {
				const response = await getTimeline({ isFavorite: true, timeBucket }, signal)
				this.favoriteAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				if (e?.name === 'AbortError' || e?.code === 'ERR_CANCELED') return
				this.error = e.response?.data?.error || e.message
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
			this.loading = true
			this.error = null
			try {
				const response = await getAlbum(id)
				this.currentAlbum = response.data
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
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
			this.loading = true
			this.error = null
			this.currentPersonId = id
			this.personBuckets = []
			this.personBucketAssets = {}
			try {
				const response = await getTimeline({ personId: id })
				this.personBuckets = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},

		async fetchPersonBucketAsset(personId, timeBucket) {
			if (this.personBucketAssets[timeBucket]) {
				return
			}
			try {
				const response = await getTimeline({ personId, timeBucket })
				this.personBucketAssets[timeBucket] = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e.response?.data?.error || e.message
			}
		},

		unloadPersonBucketAsset(timeBucket) {
			delete this.personBucketAssets[timeBucket]
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

			// Remove from album assets
			if (this.currentAlbum && this.currentAlbum.assets) {
				this.currentAlbum.assets = this.currentAlbum.assets.filter(a => a.id !== assetId)
			}

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
			const updated = new Set(this.selectedAssetIds)
			if (updated.has(id)) {
				updated.delete(id)
			} else {
				updated.add(id)
			}
			this.selectedAssetIds = updated
		},

		clearSelection() {
			this.selectedAssetIds = new Set()
			this.isSelectionMode = false
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
			// Album detail
			if (this.currentAlbum?.assets) {
				patchList(this.currentAlbum.assets)
			}
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
			// Album detail
			if (state.currentAlbum?.assets) {
				for (const a of state.currentAlbum.assets) map[a.id] = a
			}
			return map
		},
	},
})
