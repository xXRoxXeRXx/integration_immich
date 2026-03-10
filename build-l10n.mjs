/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Generates l10n/<locale>.js from l10n/<locale>.json for all locales.
 * Run via: node build-l10n.mjs  (or as part of npm run build)
 */

import { readFileSync, writeFileSync, readdirSync } from 'fs'
import { join, dirname } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const l10nDir = join(__dirname, 'l10n')

const jsonFiles = readdirSync(l10nDir).filter(f => f.endsWith('.json'))

for (const file of jsonFiles) {
	const locale = file.replace('.json', '')
	const jsonPath = join(l10nDir, file)
	const jsPath = join(l10nDir, `${locale}.js`)

	const { translations, pluralForm } = JSON.parse(readFileSync(jsonPath, 'utf8'))

	const entries = Object.entries(translations)
		.map(([k, v]) => `    ${JSON.stringify(k)}: ${JSON.stringify(v)}`)
		.join(',\n')

	const js = `OC.L10N.register(\n  "integration_immich",\n  {\n${entries}\n  },\n  ${JSON.stringify(pluralForm)}\n);\n`

	writeFileSync(jsPath, js, 'utf8')
	console.log(`✓ l10n/${locale}.js generated`)
}
