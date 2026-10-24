import { ref } from 'vue'

/**
 * Dataset-scoped, bounded scheduler for visible bucket requests.
 * The caller owns cache lookup, fetching, and eviction through its adapter.
 */
export class BucketRequestScheduler {
	constructor({ maxConcurrent = 2, onError = null } = {}) {
		if (!Number.isInteger(maxConcurrent) || maxConcurrent < 1) {
			throw new RangeError('maxConcurrent must be a positive integer')
		}
		this.maxConcurrent = maxConcurrent
		this.onError = onError
		this.datasetId = null
		this.datasetGeneration = 0
		this.visibleKeys = new Set()
		this.entries = new Map()
		this.queue = []
		this.activeCount = 0
		this.sequence = 0
		this.destroyed = false
		this.loadingKeys = ref(new Set())
	}

	setDataset(datasetId) {
		if (this.datasetId === datasetId) return
		this.cancelAll()
		this.datasetId = datasetId
		this.datasetGeneration++
		this.visibleKeys.clear()
	}

	invalidateDataset(datasetId = this.datasetId) {
		if (this.destroyed) return
		this.cancelAll()
		this.datasetId = datasetId
		this.datasetGeneration++
	}

	getDatasetToken() {
		return `${String(this.datasetId)}#${this.datasetGeneration}`
	}

	setVisibleKeys(keys) {
		this.visibleKeys = new Set(keys)
		for (const entry of this.entries.values()) {
			if (!entry.retain && !this.visibleKeys.has(entry.key)) {
				this.cancelEntry(entry)
			}
		}
		this.pump()
	}

	request(key, load, { priority = 0, retain = false } = {}) {
		if (this.destroyed || this.datasetId === null || this.datasetId === undefined) {
			return Promise.resolve(false)
		}

		const requestDatasetGeneration = this.datasetGeneration
		const id = this.entryId(this.datasetId, requestDatasetGeneration, key)
		const existing = this.entries.get(id)
		if (existing) {
			existing.retain ||= retain
			const nextPriority = Number(priority) || 0
			existing.priority = existing.retain
				? Math.min(existing.priority, nextPriority)
				: nextPriority
			if (existing.status === 'queued') this.sortQueue()
			this.pump()
			return existing.promise
		}

		let resolvePromise
		const promise = new Promise(resolve => {
			resolvePromise = resolve
		})
		const entry = {
			id,
			datasetId: this.datasetId,
			datasetGeneration: requestDatasetGeneration,
			key,
			load,
			priority: Number(priority) || 0,
			sequence: this.sequence++,
			retain,
			status: 'queued',
			controller: new AbortController(),
			promise,
			resolve: resolvePromise,
			settled: false,
		}
		this.entries.set(id, entry)
		this.queue.push(entry)
		this.sortQueue()
		this.pump()
		return promise
	}

	cancelAll() {
		for (const entry of [...this.entries.values()]) {
			this.cancelEntry(entry)
		}
		this.queue.length = 0
		this.visibleKeys.clear()
		this.loadingKeys.value = new Set()
	}

	destroy() {
		this.destroyed = true
		this.cancelAll()
	}

	entryId(datasetId, datasetGeneration, key) {
		return `${String(datasetId)}\u0000${datasetGeneration}\u0000${String(key)}`
	}

	sortQueue() {
		this.queue.sort((a, b) => a.priority - b.priority || a.sequence - b.sequence)
	}

	setLoading(key, loading) {
		const next = new Set(this.loadingKeys.value)
		if (loading) next.add(key)
		else next.delete(key)
		this.loadingKeys.value = next
	}

	cancelEntry(entry) {
		if (entry.settled) return
		entry.settled = true
		if (this.entries.get(entry.id) === entry) this.entries.delete(entry.id)
		if (entry.status === 'queued') {
			this.queue = this.queue.filter(queued => queued !== entry)
		} else {
			// Keep the slot occupied until the abort-aware loader settles, preserving the concurrency bound.
			entry.controller.abort()
			if (entry.datasetId === this.datasetId) this.setLoading(entry.key, false)
		}
		entry.resolve(false)
	}

	pump() {
		if (this.destroyed) return
		while (this.activeCount < this.maxConcurrent && this.queue.length > 0) {
			const entry = this.queue.shift()
			if (entry.settled || this.entries.get(entry.id) !== entry) continue

			entry.status = 'active'
			this.activeCount++
			if (entry.datasetId === this.datasetId) this.setLoading(entry.key, true)
			Promise.resolve()
				.then(() => {
					if (entry.settled || entry.controller.signal.aborted) return undefined
					return entry.load(entry.controller.signal)
				})
				.then(result => this.finish(entry, result !== false, null))
				.catch(error => this.finish(entry, false, error))
		}
	}

	finish(entry, succeeded, error = null) {
		this.activeCount--
		const isCurrentEntry = this.entries.get(entry.id) === entry
		const isCurrentDataset = entry.datasetId === this.datasetId
			&& entry.datasetGeneration === this.datasetGeneration
		if (isCurrentEntry) {
			this.entries.delete(entry.id)
		}
		if (isCurrentEntry && isCurrentDataset) {
			this.setLoading(entry.key, false)
		}

		if (!entry.settled) {
			entry.settled = true
			if (succeeded && isCurrentDataset) {
				entry.resolve(true)
			} else {
				if (error && !entry.controller.signal.aborted) {
					try {
						this.onError?.(error, entry.key, entry.datasetId)
					} catch {
						// A reporting callback must not corrupt scheduler accounting.
					}
				}
				entry.resolve(false)
			}
		}
		this.pump()
	}
}
