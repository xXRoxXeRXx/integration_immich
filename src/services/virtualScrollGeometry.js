/**
 * Pure geometry helpers shared by bucket-based virtualized views.
 * Layout-specific values are supplied by the caller so view differences stay explicit.
 */

export function getGridMetrics(containerWidth, {
	minItemWidth = 180,
	gap = 3,
	horizontalPadding = 32,
	forcedColumns = null,
} = {}) {
	const availableWidth = forcedColumns
		? Math.max(0, containerWidth - horizontalPadding)
		: Math.max(minItemWidth, containerWidth - horizontalPadding)
	const columns = forcedColumns || Math.max(1, Math.floor((availableWidth + gap) / (minItemWidth + gap)))
	const itemWidth = Math.max(0, (availableWidth - (columns - 1) * gap) / columns)
	return { availableWidth, columns, itemWidth }
}

export function getResponsiveBucketGeometry({
	containerWidth,
	viewportWidth,
	gridBreakpoint = 480,
	forcedMobileColumns = 2,
	bucketPaddingBreakpoint = null,
	desktopHorizontalPadding = 32,
	mobileHorizontalPadding = 16,
	desktopVerticalChrome = 55,
	mobileVerticalChrome = 40,
}) {
	const narrowBucket = bucketPaddingBreakpoint !== null && viewportWidth <= bucketPaddingBreakpoint
	return {
		containerWidth,
		horizontalPadding: narrowBucket ? mobileHorizontalPadding : desktopHorizontalPadding,
		verticalChrome: narrowBucket ? mobileVerticalChrome : desktopVerticalChrome,
		forcedColumns: viewportWidth <= gridBreakpoint ? forcedMobileColumns : null,
	}
}

export function estimateGridBucketHeight(count, {
	containerWidth,
	minItemWidth = 180,
	gap = 3,
	horizontalPadding = 32,
	forcedColumns = null,
	verticalChrome = 0,
}) {
	const { columns, itemWidth } = getGridMetrics(containerWidth, {
		minItemWidth,
		gap,
		horizontalPadding,
		forcedColumns,
	})
	const rows = Math.ceil(Math.max(0, count) / columns)
	const gridHeight = rows > 0 ? rows * itemWidth + (rows - 1) * gap : 0
	return verticalChrome + gridHeight
}

export function estimateMasonryBucketHeight(count, assets, {
	containerWidth,
	minItemWidth = 180,
	gap = 3,
	horizontalPadding = 32,
	forcedColumns = null,
	verticalChrome = 0,
	defaultRatio = 1,
} = {}) {
	const { columns, itemWidth } = getGridMetrics(containerWidth, {
		minItemWidth,
		gap,
		horizontalPadding,
		forcedColumns,
	})

	if (assets?.length) {
		const columnHeights = new Array(columns).fill(0)
		for (const asset of assets) {
			const ratio = asset?.ratio > 0 ? asset.ratio : defaultRatio
			const columnIndex = columnHeights.indexOf(Math.min(...columnHeights))
			columnHeights[columnIndex] += itemWidth / ratio + gap
		}
		return verticalChrome + Math.max(...columnHeights)
	}

	const rows = Math.ceil(Math.max(0, count) / columns)
	const gridHeight = rows * (itemWidth / defaultRatio + gap)
	return verticalChrome + gridHeight
}

export function getCumulativeOffsets(heights) {
	const offsets = []
	let total = 0
	for (const height of heights) {
		offsets.push(total)
		total += height
	}
	return offsets
}

export function getTotalHeight(heights, offsets = getCumulativeOffsets(heights)) {
	if (heights.length === 0) return 0
	const lastIndex = heights.length - 1
	return offsets[lastIndex] + heights[lastIndex]
}

export function getVirtualWindowIndices(heights, offsets, scrollTop, viewportHeight, overscan = 800, contentOffset = 0) {
	const contentScrollTop = scrollTop - contentOffset
	const top = contentScrollTop - overscan
	const bottom = contentScrollTop + viewportHeight + overscan
	const indices = []

	for (let i = 0; i < heights.length; i++) {
		const bucketTop = offsets[i]
		const bucketBottom = bucketTop + heights[i]
		if (bucketBottom >= top && bucketTop <= bottom) indices.push(i)
		if (bucketTop > bottom) break
	}

	return indices
}

export function getBucketIndexAtOffset(offsets, scrollTop, contentOffset = 0) {
	const contentScrollTop = scrollTop - contentOffset
	let index = 0
	for (let i = 0; i < offsets.length; i++) {
		if (offsets[i] <= contentScrollTop) index = i
		else break
	}
	return index
}

export function getWindowPriority(index, visibleIndices) {
	if (visibleIndices.length === 0) return 0
	const center = (visibleIndices[0] + visibleIndices[visibleIndices.length - 1]) / 2
	return Math.abs(index - center)
}

export function evictDistantBuckets(loadedKeys, protectedKeys, maxLoadedBuckets, unload) {
	if (loadedKeys.length <= maxLoadedBuckets) return []

	const evicted = []
	let remaining = loadedKeys.length
	for (const key of loadedKeys) {
		if (remaining <= maxLoadedBuckets) break
		if (protectedKeys.has(key)) continue
		unload(key)
		evicted.push(key)
		remaining--
	}
	return evicted
}

export function getLightboxAssetsInBucketOrder(buckets, assetsByBucket, activeBucketIndex, localIndex, getBucketKey = bucket => bucket.key) {
	const assets = []
	let index = 0
	for (let i = 0; i < buckets.length; i++) {
		const bucketAssets = assetsByBucket[getBucketKey(buckets[i])]
		if (!bucketAssets) continue
		if (i === activeBucketIndex) index = assets.length + localIndex
		assets.push(...bucketAssets)
	}
	return { assets, index }
}