/** @vitest-environment node */
import { describe, expect, it } from 'vitest'
import { expiryDelay } from '../../src/services/lockedExpiry.js'

describe('expiryDelay', () => {
	it('waits until just after the seconds left', () => {
		expect(expiryDelay(15 * 60)).toBe(15 * 60 * 1000 + 5000)
	})

	it('asks soon when no time is left', () => {
		expect(expiryDelay(0)).toBe(5000)
		expect(expiryDelay(-30)).toBe(5000)
	})

	it('waits no longer than a timer can', () => {
		expect(expiryDelay(365 * 24 * 3600)).toBe(2 ** 31 - 1)
	})

	it('schedules nothing without an expiry', () => {
		expect(expiryDelay(null)).toBeNull()
		expect(expiryDelay(undefined)).toBeNull()
		expect(expiryDelay('600')).toBeNull()
	})
})
