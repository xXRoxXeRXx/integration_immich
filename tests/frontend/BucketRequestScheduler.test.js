import { describe, expect, it, vi } from 'vitest'
import { BucketRequestScheduler } from '../../src/services/BucketRequestScheduler.js'

function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

describe('BucketRequestScheduler', () => {
	it('bounds concurrency and starts queued work by priority', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
		scheduler.setDataset('timeline')
		scheduler.setVisibleKeys(['a', 'b', 'c', 'd'])
		const work = Object.fromEntries(['a', 'b', 'c', 'd'].map(key => [key, deferred()]))
		const started = []
		const load = key => signal => {
			started.push({ key, signal })
			return work[key].promise
		}

		const requests = [
			scheduler.request('a', load('a')),
			scheduler.request('b', load('b')),
			scheduler.request('c', load('c'), { priority: 3 }),
			scheduler.request('d', load('d'), { priority: 1 }),
		]
		await vi.waitFor(() => expect(started).toHaveLength(2))
		expect(scheduler.activeCount).toBe(2)
		expect(started.map(item => item.key)).toEqual(['a', 'b'])

		work.a.resolve()
		await vi.waitFor(() => expect(started).toHaveLength(3))
		expect(started[2].key).toBe('d')
		work.b.resolve()
		await vi.waitFor(() => expect(started).toHaveLength(4))
		expect(started[3].key).toBe('c')
		work.d.resolve()
		work.c.resolve()
		expect(await Promise.all(requests)).toEqual([true, true, true, true])
		expect(scheduler.activeCount).toBe(0)
		scheduler.destroy()
	})

	it('deduplicates requests within a dataset', async () => {
		const scheduler = new BucketRequestScheduler()
		scheduler.setDataset('album:one')
		scheduler.setVisibleKeys(['2026-01'])
		const work = deferred()
		const load = vi.fn(() => work.promise)
		const first = scheduler.request('2026-01', load)
		const duplicate = scheduler.request('2026-01', load, { retain: true })

		expect(duplicate).toBe(first)
		await vi.waitFor(() => expect(load).toHaveBeenCalledTimes(1))
		scheduler.setVisibleKeys([])
		expect(scheduler.entries.values().next().value.retain).toBe(true)
		work.resolve()
		expect(await first).toBe(true)
		scheduler.destroy()
	})

	it('reorders queued requests as the visible-window priority changes', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 1 })
		scheduler.setDataset('timeline')
		scheduler.setVisibleKeys(['active', 'edge', 'center'])
		const activeWork = deferred()
		const edgeWork = deferred()
		const centerWork = deferred()
		const started = []
		const active = scheduler.request('active', () => {
			started.push('active')
			return activeWork.promise
		})
		const edge = scheduler.request('edge', () => {
			started.push('edge')
			return edgeWork.promise
		}, { priority: 0 })
		const center = scheduler.request('center', () => {
			started.push('center')
			return centerWork.promise
		}, { priority: 2 })
		scheduler.request('edge', () => edgeWork.promise, { priority: 4 })

		activeWork.resolve()
		await vi.waitFor(() => expect(started).toHaveLength(2))
		expect(started[1]).toBe('center')
		centerWork.resolve()
		await vi.waitFor(() => expect(started).toHaveLength(3))
		expect(started[2]).toBe('edge')
		edgeWork.resolve()
		expect(await Promise.all([active, edge, center])).toEqual([true, true, true])
		scheduler.destroy()
	})

	it('prunes invisible queued work and aborts invisible active work', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 1 })
		scheduler.setDataset('person:one')
		scheduler.setVisibleKeys(['active', 'queued'])
		const activeWork = deferred()
		let activeSignal
		const active = scheduler.request('active', signal => {
			activeSignal = signal
			return activeWork.promise
		})
		const queued = scheduler.request('queued', vi.fn())
		await vi.waitFor(() => expect(activeSignal).toBeDefined())

		scheduler.setVisibleKeys(['active'])
		expect(await queued).toBe(false)
		scheduler.setVisibleKeys([])
		expect(activeSignal.aborted).toBe(true)
		expect(scheduler.loadingKeys.value.size).toBe(0)
		expect(await active).toBe(false)
		activeWork.resolve()
		await vi.waitFor(() => expect(scheduler.activeCount).toBe(0))
		scheduler.destroy()
	})

	it('does not let an old canceled finalizer clear loading for a restarted bucket job', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
		scheduler.setDataset('timeline')
		scheduler.setVisibleKeys(['bucket'])
		const oldWork = deferred()
		const newWork = deferred()
		let oldSignal
		const oldRequest = scheduler.request('bucket', signal => {
			oldSignal = signal
			return oldWork.promise
		})
		await vi.waitFor(() => expect(oldSignal).toBeDefined())

		scheduler.setVisibleKeys([])
		expect(oldSignal.aborted).toBe(true)
		scheduler.setVisibleKeys(['bucket'])
		const newRequest = scheduler.request('bucket', () => newWork.promise)
		await vi.waitFor(() => expect(scheduler.loadingKeys.value.has('bucket')).toBe(true))

		oldWork.resolve()
		expect(await oldRequest).toBe(false)
		await vi.waitFor(() => expect(scheduler.activeCount).toBe(1))
		expect(scheduler.loadingKeys.value.has('bucket')).toBe(true)
		newWork.resolve()
		expect(await newRequest).toBe(true)
		expect(scheduler.loadingKeys.value.has('bucket')).toBe(false)
		scheduler.destroy()
	})

	it('isolates dataset changes from late completions for the same bucket key', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
		scheduler.setDataset('person:old')
		scheduler.setVisibleKeys(['same-key'])
		const oldWork = deferred()
		let oldSignal
		const oldRequest = scheduler.request('same-key', signal => {
			oldSignal = signal
			return oldWork.promise
		})
		await vi.waitFor(() => expect(oldSignal).toBeDefined())

		scheduler.setDataset('person:new')
		expect(oldSignal.aborted).toBe(true)
		scheduler.setVisibleKeys(['same-key'])
		const newWork = deferred()
		const newRequest = scheduler.request('same-key', () => newWork.promise)
		await vi.waitFor(() => expect(scheduler.loadingKeys.value.has('same-key')).toBe(true))

		oldWork.resolve()
		expect(await oldRequest).toBe(false)
		await vi.waitFor(() => expect(scheduler.activeCount).toBe(1))
		expect(scheduler.loadingKeys.value.has('same-key')).toBe(true)
		newWork.resolve()
		expect(await newRequest).toBe(true)
		expect(scheduler.loadingKeys.value.size).toBe(0)
		scheduler.destroy()
	})

	it('isolates A→B→A generations when an older A request finishes last', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 2 })
		const oldAWork = deferred()
		const newAWork = deferred()
		let oldASignal
		scheduler.setDataset('A')
		scheduler.setVisibleKeys(['same-key'])
		const oldA = scheduler.request('same-key', signal => {
			oldASignal = signal
			return oldAWork.promise
		})
		await vi.waitFor(() => expect(oldASignal).toBeDefined())

		scheduler.setDataset('B')
		scheduler.setDataset('A')
		scheduler.setVisibleKeys(['same-key'])
		const newA = scheduler.request('same-key', () => newAWork.promise)
		await vi.waitFor(() => expect(scheduler.loadingKeys.value.has('same-key')).toBe(true))
		oldAWork.resolve()
		expect(await oldA).toBe(false)
		await vi.waitFor(() => expect(scheduler.activeCount).toBe(1))
		expect(scheduler.loadingKeys.value.has('same-key')).toBe(true)
		newAWork.resolve()
		expect(await newA).toBe(true)
		scheduler.destroy()
	})

	it('does not start a canceled loader after a synchronous dataset switch', async () => {
		const scheduler = new BucketRequestScheduler()
		scheduler.setDataset('old')
		scheduler.setVisibleKeys(['bucket'])
		const load = vi.fn()
		const request = scheduler.request('bucket', load)
		scheduler.setDataset('new')

		expect(await request).toBe(false)
		await vi.waitFor(() => expect(scheduler.activeCount).toBe(0))
		expect(load).not.toHaveBeenCalled()
		scheduler.destroy()
	})

	it('keeps the concurrency bound until an aborted adapter settles', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 1 })
		scheduler.setDataset('old')
		scheduler.setVisibleKeys(['old-bucket'])
		const oldWork = deferred()
		const newWork = deferred()
		let oldSignal
		const newLoad = vi.fn(() => newWork.promise)
		const oldRequest = scheduler.request('old-bucket', signal => {
			oldSignal = signal
			return oldWork.promise
		})
		await vi.waitFor(() => expect(oldSignal).toBeDefined())

		scheduler.setDataset('new')
		scheduler.setVisibleKeys(['new-bucket'])
		const newRequest = scheduler.request('new-bucket', newLoad)
		expect(oldSignal.aborted).toBe(true)
		expect(newLoad).not.toHaveBeenCalled()
		expect(scheduler.activeCount).toBe(1)

		oldWork.resolve()
		await vi.waitFor(() => expect(newLoad).toHaveBeenCalledTimes(1))
		expect(await oldRequest).toBe(false)
		newWork.resolve()
		expect(await newRequest).toBe(true)
		scheduler.destroy()
	})

	it('reports loader failures and releases the scheduler slot', async () => {
		const onError = vi.fn()
		const scheduler = new BucketRequestScheduler({ onError })
		scheduler.setDataset('timeline')
		scheduler.setVisibleKeys(['bucket'])
		const request = scheduler.request('bucket', async () => {
			throw new Error('request failed')
		})

		expect(await request).toBe(false)
		expect(onError).toHaveBeenCalledWith(expect.any(Error), 'bucket', 'timeline')
		expect(scheduler.activeCount).toBe(0)
		scheduler.destroy()
	})

	it('settles active and queued jobs on destroy without starting queued work', async () => {
		const scheduler = new BucketRequestScheduler({ maxConcurrent: 1 })
		scheduler.setDataset('picker')
		scheduler.setVisibleKeys(['active', 'queued'])
		const activeWork = deferred()
		let activeSignal
		const queuedLoad = vi.fn()
		const active = scheduler.request('active', signal => {
			activeSignal = signal
			return activeWork.promise
		})
		const queued = scheduler.request('queued', queuedLoad)
		await vi.waitFor(() => expect(activeSignal).toBeDefined())

		scheduler.destroy()
		expect(activeSignal.aborted).toBe(true)
		expect(await Promise.all([active, queued])).toEqual([false, false])
		activeWork.resolve()
		await vi.waitFor(() => expect(scheduler.activeCount).toBe(0))
		expect(queuedLoad).not.toHaveBeenCalled()
	})
})
