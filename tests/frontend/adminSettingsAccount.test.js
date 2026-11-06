/** @vitest-environment jsdom */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const apiMocks = vi.hoisted(() => ({
	getConfig: vi.fn(),
	setConfig: vi.fn(),
	getAccountFeatures: vi.fn(),
	accountLogin: vi.fn(),
	accountOAuthStart: vi.fn(),
	accountSignOut: vi.fn(),
}))
const initialState = vi.hoisted(() => ({ value: {} }))

vi.mock('../../src/services/api.js', () => apiMocks)
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => initialState.value }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, name) => vars[name] ?? `{${name}}`),
}))
vi.mock('@nextcloud/vue', () => {
	const Stub = { template: '<div><slot /><slot name="icon" /></div>' }
	const Field = {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	}
	return {
		NcSettingsSection: Stub,
		NcTextField: Field,
		NcPasswordField: Field,
		NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
		NcNoteCard: { template: '<div class="note"><slot /></div>' },
		NcLoadingIcon: Stub,
		NcCheckboxRadioSwitch: {
			props: ['modelValue', 'value', 'type'],
			emits: ['update:modelValue'],
			template: '<label :class="[\'choice\', type]" @click="$emit(\'update:modelValue\', value ?? !modelValue)"><slot /></label>',
		},
	}
})

import AdminSettings from '../../src/AdminSettings.vue'

const signedIn = { server_url: 'https://photos.example.com', api_key_set: false, uses_account: true, account_email: 'anna@example.org' }

beforeEach(() => {
	for (const mock of Object.values(apiMocks)) mock.mockReset()
	apiMocks.getAccountFeatures.mockResolvedValue({ data: { passwordLogin: true, oauth: false } })
})

describe('Settings: sign-in with the Immich account', () => {
	it('shows the signed-in account instead of the choice and the API key', async () => {
		initialState.value = signedIn
		const wrapper = mount(AdminSettings)
		await flushPromises()

		expect(wrapper.text()).toContain('Signed in to Immich as anna@example.org.')
		expect(wrapper.find('.choice.radio').exists()).toBe(false)
		expect(wrapper.find('input[aria-label="API key"]').exists()).toBe(false)
	})

	it('signs in with e-mail and password on the server in the form', async () => {
		initialState.value = { server_url: 'https://photos.example.com', api_key_set: false }
		apiMocks.accountLogin.mockResolvedValue({ data: { email: 'anna@example.org' } })
		apiMocks.getConfig.mockResolvedValue({ data: signedIn })
		const wrapper = mount(AdminSettings)
		await flushPromises()

		const account = wrapper.findAll('.choice').find(choice => choice.text() === 'Immich account')
		await account.trigger('click')
		await wrapper.find('input[aria-label="E-mail"]').setValue('anna@example.org')
		await wrapper.find('input[aria-label="Password"]').setValue('secret')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(apiMocks.accountLogin).toHaveBeenCalledWith('https://photos.example.com', 'anna@example.org', 'secret')
		expect(wrapper.text()).toContain('Signed in to Immich as anna@example.org.')
	})

	it('shows the choice again after signing out', async () => {
		initialState.value = signedIn
		apiMocks.accountSignOut.mockResolvedValue({ data: { email: '' } })
		const wrapper = mount(AdminSettings)
		await flushPromises()

		const signOut = wrapper.findAll('button').find(button => button.text() === 'Sign out of Immich')
		await signOut.trigger('click')
		await flushPromises()

		expect(apiMocks.accountSignOut).toHaveBeenCalled()
		expect(wrapper.find('.choice.radio').exists()).toBe(true)
	})
})
