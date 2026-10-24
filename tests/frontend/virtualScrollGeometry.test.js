import { describe, expect, it } from 'vitest'
import {
	estimateGridBucketHeight,
	estimateMasonryBucketHeight,
	getCumulativeOffsets,
	getBucketIndexAtOffset,
	evictDistantBuckets,
	getGridMetrics,
	getLightboxAssetsInBucketOrder,
	getResponsiveBucketGeometry,
	getTotalHeight,
	getWindowPriority,
	getVirtualWindowIndices,
} from '../../src/services/virtualScrollGeometry.js'

describe('virtual scroll geometry', () => {
	it('returns only the configured header and padding for empty buckets', () => {
		expect(estimateGridBucketHeight(0, { containerWidth: 900, verticalChrome: 55 })).toBe(55)
		expect(estimateMasonryBucketHeight(0, [], { containerWidth: 900, verticalChrome: 55 })).toBe(55)
	})

	it('calculates grid columns and square rows from responsive available width', () => {
		expect(getGridMetrics(390, { horizontalPadding: 16 })).toEqual({
			availableWidth: 374,
			columns: 2,
			itemWidth: 185.5,
		})
		expect(estimateGridBucketHeight(3, {
			containerWidth: 390,
			horizontalPadding: 16,
			verticalChrome: 40,
		})).toBe(414)
	})

	it('honors PhotoGrid’s forced two-column mobile layout', () => {
		expect(getGridMetrics(300, { horizontalPadding: 32, forcedColumns: 2 })).toEqual({
			availableWidth: 268,
			columns: 2,
			itemWidth: 132.5,
		})
	})

	it('keeps view-specific CSS breakpoints explicit in shared geometry options', () => {
		expect(getResponsiveBucketGeometry({
			containerWidth: 640,
			viewportWidth: 640,
			bucketPaddingBreakpoint: 680,
	})).toEqual({
			containerWidth: 640,
			horizontalPadding: 16,
			verticalChrome: 40,
			forcedColumns: null,
		})
		expect(getResponsiveBucketGeometry({
			containerWidth: 640,
			viewportWidth: 640,
			bucketPaddingBreakpoint: 480,
		})).toMatchObject({
			horizontalPadding: 32,
			verticalChrome: 55,
		})
		expect(getResponsiveBucketGeometry({
			containerWidth: 390,
			viewportWidth: 390,
			bucketPaddingBreakpoint: 480,
		})).toMatchObject({
			horizontalPadding: 16,
			verticalChrome: 40,
			forcedColumns: 2,
		})
		expect(getResponsiveBucketGeometry({
			containerWidth: 390,
			viewportWidth: 390,
			bucketPaddingBreakpoint: null,
			desktopVerticalChrome: 53,
			mobileVerticalChrome: 53,
		})).toMatchObject({
			horizontalPadding: 32,
			verticalChrome: 53,
			forcedColumns: 2,
		})
	})

	it('estimates loaded masonry buckets using asset ratios and a default for invalid ratios', () => {
		const height = estimateMasonryBucketHeight(2, [{ ratio: 2 }, { ratio: 0 }], {
			containerWidth: 390,
			horizontalPadding: 16,
			verticalChrome: 40,
		})
		expect(height).toBe(228.5)
	})

	it('uses square placeholders for unloaded masonry buckets', () => {
		expect(estimateMasonryBucketHeight(3, null, {
			containerWidth: 390,
			horizontalPadding: 16,
			verticalChrome: 40,
		})).toBe(417)
	})

	it('calculates cumulative offsets for no, one, and multiple buckets', () => {
		expect(getCumulativeOffsets([])).toEqual([])
		expect(getCumulativeOffsets([100])).toEqual([0])
		expect(getCumulativeOffsets([100, 250, 50])).toEqual([0, 100, 350])
		expect(getTotalHeight([])).toBe(0)
		expect(getTotalHeight([100, 250, 50], [0, 100, 350])).toBe(400)
	})

	it('finds the bucket at a scroll offset', () => {
		expect(getBucketIndexAtOffset([], 500)).toBe(0)
		expect(getBucketIndexAtOffset([0, 100, 350], 99)).toBe(0)
		expect(getBucketIndexAtOffset([0, 100, 350], 350)).toBe(2)
	})

	it('prioritizes requests nearest the center of the visible index range', () => {
		expect(getWindowPriority(41, [40, 41, 42])).toBe(0)
		expect(getWindowPriority(40, [40, 41, 42])).toBe(1)
		expect(getWindowPriority(3, [])).toBe(0)
	})

	it('evicts far buckets up to the configured cap while protecting the visible set', () => {
		const unloaded = []
		const evicted = evictDistantBuckets(
			['a', 'b', 'c', 'd'],
			new Set(['c']),
			2,
			key => unloaded.push(key),
		)
		expect(evicted).toEqual(['a', 'b'])
		expect(unloaded).toEqual(['a', 'b'])
		expect(evictDistantBuckets(['a'], new Set(), 2, () => {})).toEqual([])
	})

	it('includes buckets touching the overscanned window boundary', () => {
		expect(getVirtualWindowIndices([100, 200, 100], [0, 100, 300], 100, 100, 0)).toEqual([0, 1])
		expect(getVirtualWindowIndices([100, 200, 100], [0, 100, 300], 100, 100, 100)).toEqual([0, 1, 2])
		expect(getVirtualWindowIndices([], [], 0, 500)).toEqual([])
	})

	it('converts scroll-container positions to runway-relative positions', () => {
		expect(getBucketIndexAtOffset([0, 100], 100, 50)).toBe(0)
		expect(getBucketIndexAtOffset([0, 100], 150, 50)).toBe(1)
		expect(getVirtualWindowIndices([100, 100], [0, 100], 100, 0, 0, 50)).toEqual([0])
	})

	it('preserves loaded bucket order when constructing lightbox assets', () => {
		const buckets = [{ key: 'first' }, { key: 'missing' }, { key: 'active' }]
		const result = getLightboxAssetsInBucketOrder(buckets, {
			first: ['a', 'b'],
			active: ['c', 'd'],
		}, 2, 1)
		expect(result).toEqual({ assets: ['a', 'b', 'c', 'd'], index: 3 })
	})
})