/* global OC, OCP, t */
(function() {
	const root = document.getElementById('scantopdf-settings')
	const tr = (text, vars) => t('scantopdf', text, vars)
	const url = (path) => OC.generateUrl('/apps/scantopdf' + path)

	async function api(method, path, body) {
		const response = await fetch(url(path), {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error(data.message ? tr(data.message) : tr('The request failed'))
		}
		return data
	}

	function el(tag, props = {}, ...children) {
		const node = document.createElement(tag)
		Object.entries(props).forEach(([key, value]) => {
			if (key === 'class') {
				node.className = value
			} else if (key.startsWith('on')) {
				node.addEventListener(key.slice(2), value)
			} else {
				node[key] = value
			}
		})
		children.forEach((child) => child !== null && node.append(child))
		return node
	}

	const field = (label, control) => el('label', { class: 'scantopdf-field' }, el('span', { textContent: label }), control)

	function select(options, value) {
		const node = el('select', {}, ...options.map(([id, label]) => el('option', { value: id, textContent: label })))
		node.value = options.some(([id]) => String(id) === String(value)) ? value : options[0][0]
		return node
	}

	function statusRow(ok, label, detail, command) {
		return el('div', { class: 'scantopdf-status ' + (ok ? 'scantopdf-ok' : 'scantopdf-missing') },
			el('span', { class: 'scantopdf-dot', ariaHidden: 'true' }),
			el('div', {},
				el('strong', { textContent: label }),
				el('div', { textContent: detail }),
				command ? el('code', { textContent: command }) : null,
			),
		)
	}

	function slider(value, min = -100, max = 100, neutral = 0) {
		const input = el('input', { type: 'range', min, max, step: 5, value })
		const shown = el('span', { class: 'scantopdf-value', textContent: value })
		input.addEventListener('input', () => { shown.textContent = input.value })
		const reset = () => {
			input.value = neutral
			shown.textContent = neutral
		}
		return { input, reset, node: el('div', { class: 'scantopdf-range' }, input, shown) }
	}

	function jobTable(jobs) {
		if (jobs.length === 0) {
			return el('p', { class: 'scantopdf-hint', textContent: tr('No scans yet.') })
		}
		const labels = { done: tr('Done'), error: tr('Error'), scanning: tr('Scanning'), waiting: tr('Waiting') }
		const kinds = { text: tr('Text'), photo: tr('Image') }
		return el('table', { class: 'scantopdf-jobs' },
			el('thead', {}, el('tr', {}, ...[tr('Time'), tr('User'), tr('Type'), tr('Pages'), tr('Result'), tr('File or error')].map((title) => el('th', { textContent: title })))),
			el('tbody', {}, ...jobs.map((job) => el('tr', { class: job.status === 'error' ? 'scantopdf-job-error' : '' },
				el('td', { textContent: new Date(job.created * 1000).toLocaleString() }),
				el('td', { textContent: job.userId }),
				el('td', { textContent: kinds[job.kind] ?? job.kind }),
				el('td', { textContent: job.pages }),
				el('td', { textContent: labels[job.status] ?? job.status }),
				el('td', { textContent: job.message || (job.fileName ? (job.folder ? job.folder + '/' : '') + job.fileName : '') }),
			))),
		)
	}

	const resolutions = () => [75, 150, 300, 600].map((dpi) => [dpi, tr('{dpi} dpi', { dpi })])

	function folderDialog(user, startPath, onChosen) {
		const title = el('h2', { textContent: tr('Choose a folder') })
		const location = el('p', { class: 'scantopdf-location' })
		const list = el('div', { class: 'scantopdf-folders' })
		const up = el('button', { type: 'button', textContent: tr('Up one level') })
		const choose = el('button', { type: 'button', class: 'primary', textContent: tr('Choose this folder') })
		const cancel = el('button', { type: 'button', textContent: tr('Cancel') })
		const dialog = el('div', { class: 'scantopdf-dialog', role: 'dialog' }, title, location, list, el('div', { class: 'scantopdf-buttons' }, up, cancel, choose))
		const backdrop = el('div', { class: 'scantopdf-backdrop' }, dialog)
		let current = ''

		const close = () => {
			document.removeEventListener('keydown', onKey)
			backdrop.remove()
		}
		const onKey = (event) => event.key === 'Escape' && close()

		const open = async (path, fallback) => {
			try {
				const result = await api('GET', '/folders?user=' + encodeURIComponent(user) + '&path=' + encodeURIComponent(path))
				current = result.path
				location.textContent = '/' + current
				up.disabled = current === ''
				list.replaceChildren(...(result.folders.length > 0
					? result.folders.map((name) => el('button', { type: 'button', class: 'scantopdf-folder', textContent: name, onclick: () => open(current === '' ? name : current + '/' + name) }))
					: [el('p', { class: 'scantopdf-hint', textContent: tr('No subfolders') })]))
			} catch (error) {
				// A saved path may no longer exist
				if (fallback) {
					open('')
				} else {
					OCP.Toast.error(error.message)
				}
			}
		}

		up.addEventListener('click', () => open(current.includes('/') ? current.slice(0, current.lastIndexOf('/')) : ''))
		cancel.addEventListener('click', close)
		choose.addEventListener('click', () => {
			onChosen(current)
			close()
		})
		backdrop.addEventListener('click', (event) => event.target === backdrop && close())
		document.addEventListener('keydown', onKey)
		document.body.append(backdrop)
		open(startPath, true)
	}

	async function render(data, devices, users) {
		const settings = data.settings
		const device = select([['', tr('Automatic, first scanner found')], ...devices.map((item) => [item.id, item.name + ' (' + item.id + ')'])], settings.device)
		const source = el('select')
		const deskew = el('input', { type: 'checkbox', checked: settings.deskew === 1 })
		const skipBlank = el('input', { type: 'checkbox', checked: settings.skipBlank === 1 })
		const buttonStatus = el('div')
		const buttonEnabled = el('input', { type: 'checkbox', checked: settings.buttonEnabled === 1 })
		const buttonUser = el('select')
		const knownUsers = users.some((user) => user.id === settings.buttonUser) || settings.buttonUser === ''
			? users
			: [...users, { id: settings.buttonUser, name: settings.buttonUser }]
		buttonUser.replaceChildren(el('option', { value: '', textContent: tr('Please choose') }), ...knownUsers.map((user) => el('option', { value: user.id, textContent: user.name === user.id ? user.id : user.name + ' (' + user.id + ')' })))
		buttonUser.value = settings.buttonUser
		const buttonFolder = el('input', { type: 'text', value: settings.buttonFolder, placeholder: tr('Main folder') })
		// A path belongs to one account, so it cannot carry over to another
		buttonUser.addEventListener('change', () => { buttonFolder.value = '' })
		const chooseFolder = el('button', {
			type: 'button',
			textContent: tr('Choose'),
			onclick: (event) => {
				event.preventDefault()
				if (buttonUser.value === '') {
					OCP.Toast.error(tr('Please choose a user first'))
					return
				}
				folderDialog(buttonUser.value, buttonFolder.value, (path) => { buttonFolder.value = path })
			},
		})
		const buttonKind = select([['text', tr('Text')], ['photo', tr('Image')]], settings.buttonKind)
		const buttonKindDouble = select([['', tr('Off')], ['text', tr('Text')], ['photo', tr('Image')]], settings.buttonKindDouble)
		const buttonWindow = select([3, 4, 5, 6, 8, 10, 15].map((seconds) => [seconds, tr('{seconds} seconds', { seconds })]), settings.buttonWindow)
		const jobList = el('div')
		const loadJobs = () => api('GET', '/jobs').then((jobs) => jobList.replaceChildren(jobTable(jobs))).catch((error) => OCP.Toast.error(error.message))
		const deskewField = field(tr('Straighten pages'), deskew)
		const skipBlankField = field(tr('Remove blank pages'), skipBlank)

		const loadCapabilities = async () => {
			const chosen = device.value || devices[0]?.id || ''
			const capabilities = chosen ? await api('GET', '/capabilities?device=' + encodeURIComponent(chosen)) : { sources: [] }
			const wanted = source.value || settings.source
			source.replaceChildren(el('option', { value: '', textContent: tr('Automatic') }), ...capabilities.sources.map((item) => el('option', { value: item, textContent: item })))
			source.value = capabilities.sources.includes(wanted) ? wanted : ''
			// Only some scanner drivers can do this
			deskewField.hidden = !capabilities.deskew
			skipBlankField.hidden = !capabilities.skipBlank
			buttonStatus.replaceChildren(...[
				statusRow(data.watcherRunning, tr('Button service'), data.watcherRunning ? tr('Running') : tr('Not set up. An administrator has to run this once on the server:'), data.watcherRunning ? null : data.setupCommand),
				statusRow(capabilities.button === true, tr('Button on the scanner'), capabilities.button ? tr('Reported by the driver') : tr('This scanner does not report a button to SANE, so it cannot be used.')),
			].filter(Boolean))
		}
		device.addEventListener('change', () => loadCapabilities().catch((error) => OCP.Toast.error(error.message)))

		const side = select([['front', tr('Front')], ['back', tr('Back')], ['both', tr('Both sides')]], settings.side)
		const paper = select([['auto', tr('Automatic')], ['a4', 'A4'], ['a5', 'A5'], ['a6', 'A6'], ['letter', 'Letter'], ['legal', 'Legal']], settings.paper)
		const textDpi = select(resolutions(), settings.textDpi)
		const photoDpi = select(resolutions(), settings.photoDpi)
		const quality = slider(settings.quality, 10, 100, 90)
		const brightness = slider(settings.brightness)
		const contrast = slider(settings.contrast)

		root.replaceChildren(...[
			el('h2', { textContent: tr('Scan to PDF') }),
			el('p', { class: 'scantopdf-hint', textContent: tr('These settings apply to all users of the Scan to PDF entry in the New menu in Files.') }),
			data.available
				? statusRow(true, 'sane-utils', tr('Installed'))
				: statusRow(false, 'sane-utils', tr('Not installed. An administrator has to run this on the server:'), 'sudo apt install sane-utils'),
			data.available
				? statusRow(devices.length > 0, tr('Scanner'), devices.length > 0 ? tr('{count} found', { count: devices.length }) : tr('None found. Check that the scanner is switched on and connected.'))
				: null,
			field(tr('Scanner'), device),
			field(tr('Scan source'), source),
			field(tr('Scan sides'), side),
			field(tr('Page size'), paper),
			field(tr('Text resolution'), textDpi),
			field(tr('Image resolution'), photoDpi),
			field(tr('Quality'), quality.node),
			el('p', { class: 'scantopdf-hint scantopdf-note', textContent: tr('Applies to images. Higher values give better pages and larger files.') }),
			field(tr('Brightness'), brightness.node),
			field(tr('Contrast'), contrast.node),
			el('div', { class: 'scantopdf-reset' }, el('button', {
				type: 'button',
				textContent: tr('Reset brightness and contrast'),
				onclick: () => {
					brightness.reset()
					contrast.reset()
				},
			})),
			deskewField,
			skipBlankField,
			el('h3', { textContent: tr('Button on the scanner') }),
			el('p', { class: 'scantopdf-hint', textContent: tr('Optional. Pressing the scan button on the scanner saves a PDF to this folder without opening Nextcloud.') }),
			buttonStatus,
			field(tr('Enable'), buttonEnabled),
			field(tr('User'), buttonUser),
			field(tr('Folder'), el('div', { class: 'scantopdf-row' }, buttonFolder, chooseFolder)),
			field(tr('Press once'), buttonKind),
			field(tr('Press twice'), buttonKindDouble),
			field(tr('Time for the second press'), buttonWindow),
			el('p', { class: 'scantopdf-hint', textContent: tr('The scan only starts after this time. After the first press the scanner needs a moment before it notices the next one, so leave a short pause between presses.') }),
			el('div', { class: 'scantopdf-actions scantopdf-save' },
				el('button', {
					type: 'button',
					class: 'primary',
					textContent: tr('Save'),
					onclick: () => api('PUT', '/settings', {
						device: device.value,
						source: source.value,
						side: side.value,
						paper: paper.value,
						textDpi: Number(textDpi.value),
						photoDpi: Number(photoDpi.value),
						quality: Number(quality.input.value),
						brightness: Number(brightness.input.value),
						contrast: Number(contrast.input.value),
						deskew: deskew.checked,
						skipBlank: skipBlank.checked,
						buttonEnabled: buttonEnabled.checked,
						buttonUser: buttonUser.value,
						buttonFolder: buttonFolder.value,
						buttonKind: buttonKind.value,
						buttonKindDouble: buttonKindDouble.value,
						buttonWindow: Number(buttonWindow.value),
					}).then(() => OCP.Toast.success(tr('Saved'))).catch((error) => OCP.Toast.error(error.message)),
				}),
			),
			el('h3', { textContent: tr('Latest scans') }),
			jobList,
			el('div', { class: 'scantopdf-actions' },
				el('button', {
					type: 'button',
					textContent: tr('Clear log'),
					onclick: () => api('DELETE', '/jobs').then(() => {
						OCP.Toast.success(tr('Log cleared'))
						return loadJobs()
					}).catch((error) => OCP.Toast.error(error.message)),
				}),
			),
		].filter(Boolean))
		loadJobs()
		await loadCapabilities()
	}

	root.textContent = tr('Looking for scanners …')
	Promise.all([api('GET', '/settings'), api('GET', '/devices'), api('GET', '/users')])
		.then(([data, devices, users]) => render(data, devices, users))
		.catch((error) => OCP.Toast.error(error.message))
})()
