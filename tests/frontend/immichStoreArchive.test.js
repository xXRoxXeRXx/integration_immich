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
const storage = vi.hoisted(() => ({ getItem: () => null, setItem: vi.fn(), removeItem: vi.fn() }))

vi.mock('../../src/services/api.js', () => apiMocks)
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({}) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('../../src/services/storage.js', () => ({ appStorage: storage }))

import { useImmichStore } from '../../src/store/immich.js'

beforeEach(() => {
	setActivePinia(createPinia())
	for (const mock of Object.values(apiMocks)) mock.mockReset()
	storage.removeItem.mockReset()
})

describe('archive', () => {
	it('loads the archive by visibility', async () => {
		const store = useImmichStore()
		apiMocks.getTimeline
			.mockResolvedValueOnce({ data: [{ timeBucket: '2026-01-01', count: 1 }] })
			.mockResolvedValueOnce({ data: [{ id: 'a1' }] })

		await store.fetchVisibilityBuckets('archive')
		await store.fetchVisibilityBucket('archive', '2026-01-01')

		expect(apiMocks.getTimeline).toHaveBeenNthCalledWith(1, { visibility: 'archive' })
		expect(apiMocks.getTimeline).toHaveBeenNthCalledWith(2, { visibility: 'archive', timeBucket: '2026-01-01' }, null)
		expect(store.visibilityBuckets.archive).toEqual([{ timeBucket: '2026-01-01', count: 1 }])
		expect(store.allLoadedAssetsMap.a1).toEqual({ id: 'a1' })
	})

	it('drops moved assets everywhere and reloads the views they may move to', () => {
		const store = useImmichStore()
		store.timelineBuckets = [{ timeBucket: '2026-01-01', count: 2 }]
		store.timelineAssets = { '2026-01-01': [{ id: 'a1' }, { id: 'a2' }] }
		store.visibilityBuckets.archive = [{ timeBucket: '2025-01-01', count: 1 }]
		store.visibilityAssets.archive = { '2025-01-01': [{ id: 'b1' }] }
		store.favoriteAssets = { '2026-01-01': [{ id: 'a1' }] }

		store.assetsMoved(['a1'])

		expect(store.allLoadedAssetsMap.a1).toBeUndefined()
		expect(store.timelineBuckets).toEqual([])
		expect(store.visibilityBuckets.archive).toEqual([])
		expect(store.favoriteBuckets).toEqual([])
		expect(store.assetsMovedGeneration).toBe(1)
		expect(storage.removeItem).toHaveBeenCalledTimes(2)
	})
})

describe('locked folder', () => {
	it('reads the Locked Folder with its session and marks its assets', async () => {
		const store = useImmichStore()
		apiMocks.getTimeline
			.mockResolvedValueOnce({ data: [{ timeBucket: '2026-01-01', count: 1 }] })
			.mockResolvedValueOnce({ data: [{ id: 'l1' }] })

		await store.fetchVisibilityBuckets('locked')
		await store.fetchVisibilityBucket('locked', '2026-01-01')

		expect(apiMocks.getTimeline).toHaveBeenNthCalledWith(1, { visibility: 'locked', locked: '1' })
		expect(apiMocks.getTimeline).toHaveBeenNthCalledWith(2, { visibility: 'locked', locked: '1', timeBucket: '2026-01-01' }, null)
		expect(store.visibilityAssets.locked['2026-01-01']).toEqual([{ id: 'l1', visibility: 'locked' }])
	})

	it('asks for the PIN again when the folder answers that it is locked', async () => {
		const store = useImmichStore()
		store.visibilityAssets.locked = { '2026-01-01': [{ id: 'l1', visibility: 'locked' }] }
		store.lightbox = { visible: true, assets: [{ id: 'l1', visibility: 'locked' }], currentIndex: 0 }
		apiMocks.getTimeline.mockRejectedValueOnce({ response: { status: 423, data: { locked: true } } })

		await store.fetchVisibilityBuckets('locked')

		expect(store.lockedFolderNeedsUnlock).toBe(true)
		expect(store.error).toBeNull()
		expect(store.visibilityAssets.locked).toEqual({})
		expect(store.lightbox.visible).toBe(false)
	})

	it('keeps the unsaved files selected when a save stops at a locked folder', () => {
		const store = useImmichStore()
		store.visibilityAssets.locked = { '2026-01-01': [{ id: 'l1', visibility: 'locked' }] }
		store.selectedAssetIds = new Set(['l1', 'l2', 'l3'])

		const saved = store.lockedSaveAnswer({ response: { status: 423, data: { locked: true, saved: 1, savedIds: ['l1'] } } })

		expect(saved).toBe(1)
		expect([...store.selectedAssetIds]).toEqual(['l2', 'l3'])
		expect(store.lockedFolderNeedsUnlock).toBe(true)
		expect(store.visibilityAssets.locked).toEqual({})
	})

	it('leaves other save errors to the caller', () => {
		const store = useImmichStore()
		store.selectedAssetIds = new Set(['l1'])

		expect(store.lockedSaveAnswer({ response: { status: 500, data: {} } })).toBeNull()
		expect([...store.selectedAssetIds]).toEqual(['l1'])
		expect(store.lockedFolderNeedsUnlock).toBe(false)
	})
})
