/** @vitest-environment node */
import { describe, expect, it } from 'vitest'
import { expiryDelay } from '../../src/services/lockedExpiry.js'

const now = Date.parse('2026-11-05T12:00:00Z')

describe('expiryDelay', () => {
	it('waits until just after the reported expiry', () => {
		expect(expiryDelay('2026-11-05T12:15:00.000Z', now)).toBe(15 * 60 * 1000 + 2000)
	})

	it('asks at once when the expiry has passed', () => {
		expect(expiryDelay('2026-11-05T11:00:00.000Z', now)).toBe(2000)
	})

	it('waits no longer than a timer can', () => {
		expect(expiryDelay('2027-11-05T12:00:00.000Z', now)).toBe(2 ** 31 - 1)
	})

	it('schedules nothing without an expiry', () => {
		expect(expiryDelay(null, now)).toBeNull()
		expect(expiryDelay('not a date', now)).toBeNull()
	})
})
