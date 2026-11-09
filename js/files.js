/* global OC, t */
(function() {
	const icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6 2h9l5 5v5H6zm8 1.5V8h4.5zM3 13h18v2H3zm3 3h14v4a2 2 0 0 1-2 2H6zm2 2v2h8v-2z"/></svg>'
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

	function openDialog(folder) {
		let running = false
		let finished = false

		const close = () => {
			if (running) {
				return
			}
			document.removeEventListener('keydown', onKey)
			backdrop.remove()
			// The file list has no public refresh, so show the new file by reloading
			if (finished) {
				window.location.reload()
			}
		}
		const onKey = (event) => event.key === 'Escape' && close()

		const dialog = el('div', { class:'scantopdf-dialog', role: 'dialog' })
		const backdrop = el('div', { class:'scantopdf-backdrop', onclick: (event) => event.target === backdrop && close() }, dialog)
		document.addEventListener('keydown', onKey)
		document.body.append(backdrop)

		const buttons = (...items) => el('div', { class:'scantopdf-buttons' }, ...items)
		const closeButton = () => el('button', { type: 'button', textContent: tr('Close'), onclick: close })

		const showError = (message) => {
			running = false
			dialog.replaceChildren(
				el('h2', { textContent: tr('Scan to PDF') }),
				el('p', { class:'scantopdf-status scantopdf-error', textContent: message }),
				buttons(closeButton()),
			)
		}

		const showDone = (job) => {
			running = false
			finished = true
			dialog.replaceChildren(
				el('h2', { textContent: tr('Scan finished') }),
				el('p', { class:'scantopdf-status', textContent: tr('{pages} pages saved as {name}', { pages: job.pages, name: job.fileName }) }),
				buttons(
					closeButton(),
					el('a', { class:'button primary', href: OC.generateUrl('/f/{id}', { id: job.fileId }), textContent: tr('Open PDF') }),
				),
			)
		}

		const follow = (id, status) => {
			api('GET', '/job/' + id).then((job) => {
				if (job.status === 'done') {
					showDone(job)
				} else if (job.status === 'error') {
					showError(tr(job.message || 'The scan failed'))
				} else {
					status.textContent = job.pages > 0 ? tr('Scanning, {pages} pages so far …', { pages: job.pages }) : tr('Scanner is starting …')
					setTimeout(() => follow(id, status), 1000)
				}
			}).catch((error) => showError(error.message))
		}

		const start = (kind) => {
			running = true
			const status = el('p', { class:'scantopdf-status', textContent: tr('Scanner is starting …') })
			dialog.replaceChildren(el('h2', { textContent: tr('Scan to PDF') }), status)
			api('POST', '/scan', { folder: folder.path, kind, dpi }).then((job) => follow(job.id, status)).catch((error) => showError(error.message))
		}

		const choice = (kind, title, hint) => el('button', { type: 'button', onclick: () => start(kind) },
			el('strong', { textContent: title }), el('small', { textContent: hint }))

		// Nothing chosen means the server uses the saved resolution
		let dpi = 0
		const resolution = el('div', { class:'scantopdf-resolution' })
		const showResolutions = (values) => resolution.replaceChildren(
			el('span', { textContent: tr('Resolution') }),
			...values.map((value) => el('button', {
				type: 'button',
				class:value === dpi ? 'primary' : '',
				textContent: String(value),
				onclick: () => {
					dpi = value
					showResolutions(values)
				},
			})),
			el('span', { textContent: tr('dpi') }),
		)
		api('GET', '/defaults').then((defaults) => {
			dpi = defaults.dpi
			showResolutions(defaults.resolutions)
		}).catch(() => {})

		dialog.append(
			el('h2', { textContent: tr('Scan to PDF') }),
			el('div', { class:'scantopdf-choices' },
				choice('text', tr('Text'), tr('Letters and documents')),
				choice('photo', tr('Image'), tr('Photos and pictures, in color')),
			),
			resolution,
			buttons(OC.isUserAdmin() ? el('a', { class:'button', href: OC.generateUrl('/settings/admin/scantopdf'), textContent: tr('Settings') }) : null, closeButton()),
		)
	}

	window._nc_newfilemenu.registerEntry({
		id: 'scantopdf',
		displayName: tr('Scan to PDF'),
		iconSvgInline: icon,
		order: 50,
		enabled: (context) => (context.permissions & OC.PERMISSION_CREATE) !== 0,
		handler: (context) => openDialog(context),
	})
})()
