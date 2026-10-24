/** @vitest-environment node */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const apiMocks = vi.hoisted(() => ({
	getTimeline: vi.fn(),
	getPartners: vi.fn(),
	getAlbums: vi.fn(),
	getAlbum: vi.fn(),
	getPeople: vi.fn(),
	getMapMarkers: vi.fn(),
	searchByLocation: vi.fn(),
	getExplore: vi.fn(),
	getMe: vi.fn(),
	getFolderContent: vi.fn(),
}))

vi.mock('../../src/services/api.js', () => apiMocks)
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({}) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('../../src/services/storage.js', () => ({
	appStorage: { getItem: () => null, setItem: vi.fn() },
}))

import { useImmichStore } from '../../src/store/immich.js'

function deferred() {
	let resolve
	const promise = new Promise(res => {
		resolve = res
	})
	return { promise, resolve }
}

beforeEach(() => {
	setActivePinia(createPinia())
	for (const mock of Object.values(apiMocks)) mock.mockReset()
})

describe('bucket store request adapters', () => {
	it('passes AbortSignal and ignores stale person bucket data', async () => {
		const store = useImmichStore()
		store.currentPersonId = 'person-old'
		const response = deferred()
		apiMocks.getTimeline.mockReturnValueOnce(response.promise)
		const controller = new AbortController()

		const pending = store.fetchPersonBucketAsset('person-old', '2026-01', controller.signal)
		expect(apiMocks.getTimeline).toHaveBeenCalledWith(
			{ personId: 'person-old', timeBucket: '2026-01' },
			controller.signal,
		)
		store.currentPersonId = 'person-new'
		store.personBucketAssets = {}
		response.resolve({ data: [{ id: 'stale' }] })
		await pending

		expect(store.personBucketAssets).toEqual({})
	})

	it('does not let an older person bucket-list response replace the current dataset', async () => {
		const store = useImmichStore()
		const oldResponse = deferred()
		const newResponse = deferred()
		apiMocks.getTimeline
			.mockReturnValueOnce(oldResponse.promise)
			.mockReturnValueOnce(newResponse.promise)

		const oldRequest = store.fetchPersonBuckets('person-old')
		const newRequest = store.fetchPersonBuckets('person-new')
		newResponse.resolve({ data: [{ timeBucket: 'new', count: 1 }] })
		await newRequest
		oldResponse.resolve({ data: [{ timeBucket: 'old', count: 1 }] })
		await oldRequest

		expect(store.personBuckets).toEqual([{ timeBucket: 'new', count: 1 }])
		expect(store.loading).toBe(false)
	})

	it('does not let an older person bucket request repopulate a same-ID refresh', async () => {
		const store = useImmichStore()
		store.currentPersonId = 'person'
		store.personBucketsGeneration = 1
		const oldAssets = deferred()
		const refreshedBuckets = deferred()
		apiMocks.getTimeline
			.mockReturnValueOnce(oldAssets.promise)
			.mockReturnValueOnce(refreshedBuckets.promise)

		const oldRequest = store.fetchPersonBucketAsset('person', '2026-01')
		const refresh = store.fetchPersonBuckets('person')
		oldAssets.resolve({ data: [{ id: 'stale' }] })
		await oldRequest
		expect(store.personBucketAssets).toEqual({})
		refreshedBuckets.resolve({ data: [{ timeBucket: '2026-01', count: 1 }] })
		await refresh
	})

	it('isolates person bucket responses across A→B→A generations', async () => {
		const store = useImmichStore()
		const oldA = deferred()
		const bucketsB = deferred()
		const bucketsA = deferred()
		const newA = deferred()
		apiMocks.getTimeline
			.mockReturnValueOnce(oldA.promise)
			.mockReturnValueOnce(bucketsB.promise)
			.mockReturnValueOnce(bucketsA.promise)
			.mockReturnValueOnce(newA.promise)

		const firstARequest = store.fetchPersonBucketAsset('person-a', 'same-key')
		const loadB = store.fetchPersonBuckets('person-b')
		const loadA = store.fetchPersonBuckets('person-a')
		bucketsB.resolve({ data: [] })
		await loadB
		bucketsA.resolve({ data: [] })
		await loadA
		const newARequest = store.fetchPersonBucketAsset('person-a', 'same-key')
		newA.resolve({ data: [{ id: 'new-a' }] })
		await newA
		await newARequest
		oldA.resolve({ data: [{ id: 'old-a' }] })
		await firstARequest

		expect(store.personBucketAssets['same-key']).toEqual([{ id: 'new-a' }])
	})

	it('passes AbortSignal and ignores stale album bucket data', async () => {
		const store = useImmichStore()
		store.currentAlbumId = 'album-old'
		const response = deferred()
		apiMocks.getTimeline.mockReturnValueOnce(response.promise)
		const controller = new AbortController()

		const pending = store.fetchAlbumBucketAssets('album-old', '2026-01', controller.signal)
		expect(apiMocks.getTimeline).toHaveBeenCalledWith(
			{ albumId: 'album-old', timeBucket: '2026-01' },
			controller.signal,
		)
		store.currentAlbumId = 'album-new'
		store.albumBucketAssets = {}
		response.resolve({ data: [{ id: 'stale' }] })
		await pending

		expect(store.albumBucketAssets).toEqual({})
	})

	it('does not let an older album bucket request repopulate a same-ID refresh', async () => {
		const store = useImmichStore()
		store.currentAlbumId = 'album'
		store.albumBucketsGeneration = 1
		const oldAssets = deferred()
		const refreshedBuckets = deferred()
		apiMocks.getTimeline
			.mockReturnValueOnce(oldAssets.promise)
			.mockReturnValueOnce(refreshedBuckets.promise)

		const oldRequest = store.fetchAlbumBucketAssets('album', '2026-01')
		const refresh = store.fetchAlbumBuckets('album')
		oldAssets.resolve({ data: [{ id: 'stale' }] })
		await oldRequest
		expect(store.albumBucketAssets).toEqual({})
		refreshedBuckets.resolve({ data: [{ timeBucket: '2026-01', count: 1 }] })
		await refresh
	})

	it('keeps the newest album detail response when requests finish out of order', async () => {
		const store = useImmichStore()
		const oldResponse = deferred()
		const newResponse = deferred()
		apiMocks.getAlbum.mockReturnValueOnce(oldResponse.promise).mockReturnValueOnce(newResponse.promise)

		const oldRequest = store.fetchAlbum('album-old')
		const newRequest = store.fetchAlbum('album-new')
		newResponse.resolve({ data: { id: 'album-new' } })
		await newRequest
		oldResponse.resolve({ data: { id: 'album-old' } })
		await oldRequest

		expect(store.currentAlbum).toEqual({ id: 'album-new' })
		expect(store.currentAlbumDetailId).toBe('album-new')
	})

	it('tracks album detail and bucket-list loading independently', async () => {
		const store = useImmichStore()
		store.loading = true // Represents an unrelated list request still pending.
		const detail = deferred()
		const buckets = deferred()
		apiMocks.getAlbum.mockReturnValueOnce(detail.promise)
		apiMocks.getTimeline.mockReturnValueOnce(buckets.promise)

		const detailRequest = store.fetchAlbum('album')
		const bucketsRequest = store.fetchAlbumBuckets('album')
		detail.resolve({ data: { id: 'album' } })
		await detailRequest
		expect(store.albumDetailLoading).toBe(false)
		expect(store.albumBucketsLoading).toBe(true)
		expect(store.loading).toBe(true)
		buckets.resolve({ data: [] })
		await bucketsRequest
		expect(store.albumBucketsLoading).toBe(false)
		expect(store.loading).toBe(true)
	})
})
