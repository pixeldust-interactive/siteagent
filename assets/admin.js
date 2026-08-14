(() => {
	'use strict';

	const cfg = window.SiteAgentAdmin || {};
	const rootUrl = String(cfg.restUrl || '').replace(/\/+$/, '');
	let conversationId = '';

	const el = (tag, className, text) => {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	};

	const clear = (node) => {
		if (node) node.replaceChildren();
	};

	const setStatus = (node, text, isError = false) => {
		if (!node) return;
		node.textContent = text || '';
		node.classList.toggle('is-error', Boolean(isError));
	};

	async function api(path, options = {}) {
		const method = options.method || 'GET';
		const timeoutMs = Number(options.timeoutMs || 0);
		const controller = timeoutMs > 0 && typeof AbortController === 'function' ? new AbortController() : null;
		const timeout = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
		const headers = {
			'X-WP-Nonce': cfg.nonce,
			'Accept': 'application/json',
		};
		const request = {
			method,
			headers,
			credentials: 'same-origin',
			cache: 'no-store',
			redirect: 'error',
			signal: controller?.signal,
		};
		if (options.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			request.body = JSON.stringify(options.body);
		}
		let response;
		try {
			response = await fetch(rootUrl + path, request);
		} catch (error) {
			if (controller?.signal.aborted) {
				throw new Error('Site Agent stopped waiting before the hosting request limit. Nothing was changed. Try again or ask a shorter question.');
			}
			throw error;
		} finally {
			if (timeout) clearTimeout(timeout);
		}
		let data = {};
		try {
			data = await response.json();
		} catch (error) {
			data = { message: 'The server returned an unreadable response.' };
		}
		if (!response.ok) {
			const err = new Error(data.message || cfg.strings?.error || 'Request failed.');
			err.code = data.code || 'request_failed';
			err.data = data.data || {};
			throw err;
		}
		return data;
	}

	function appendMessage(container, who, text) {
		if (!container) return;
		const wrap = el('div', `site-agent-message ${who === 'You' ? 'is-user' : 'is-agent'}`);
		wrap.append(el('div', 'site-agent-message-label', who));
		wrap.append(el('div', 'site-agent-message-body', text));
		container.append(wrap);
		wrap.scrollIntoView({ block: 'end', behavior: 'smooth' });
	}

	function appendWelcome(container) {
		appendMessage(container, 'Site Agent', 'What would you like to know or change?');
		const starters = el('div', 'site-agent-starters');
		[
			'What changed on my site this week?',
			'Is anything on my site unhealthy?',
			'Which pages need attention?',
			'What could break if I remove a plugin?',
		].forEach((text) => {
			const button = el('button', 'site-agent-starter', text);
			button.type = 'button';
			button.dataset.prompt = text;
			starters.append(button);
		});
		container?.append(starters);
	}

	function renderSources(container, sources) {
		if (!container || !Array.isArray(sources) || !sources.length) return;
		const details = el('details', 'site-agent-sources');
		details.append(el('summary', '', `Local evidence used (${sources.length})`));
		const list = el('ul');
		sources.forEach((source) => {
			const item = el('li');
			item.append(el('strong', '', source.title || '(untitled)'));
			item.append(document.createTextNode(` — ${source.type || 'item'} #${source.object_id || ''}`));
			list.append(item);
		});
		details.append(list);
		container.append(details);
	}

	function renderProposal(container, proposal) {
		if (!container || !proposal?.plan || !proposal.approval_token) return;
		const tokenBox = { value: String(proposal.approval_token) };
		const card = el('section', `site-agent-proposal risk-${proposal.plan.highest_risk || 'high'}`);
		card.append(el('h3', '', 'Review the proposed change'));
		if (proposal.plan.reason) card.append(el('p', 'site-agent-proposal-reason', proposal.plan.reason));

		const list = el('ol');
		(proposal.plan.actions || []).forEach((action) => {
			const item = el('li');
			item.append(el('strong', '', action.preview || action.name));
			item.append(el('span', `site-agent-risk is-${action.risk}`, String(action.risk || 'high').toUpperCase()));
			const args = el('pre', 'site-agent-args', JSON.stringify(action.args || {}, null, 2));
			item.append(args);
			list.append(item);
		});
		card.append(list);

		const controls = el('div', 'site-agent-proposal-controls');
		const button = el(
			'button',
			'button button-primary',
			proposal.plan.highest_risk === 'high' ? `Approve ${proposal.plan.actions?.length || 1} high-impact change${proposal.plan.actions?.length === 1 ? '' : 's'}` : `Make ${proposal.plan.actions?.length || 1} change${proposal.plan.actions?.length === 1 ? '' : 's'}`
		);
		button.type = 'button';
		const status = el('span', 'site-agent-status');
		const cancel = el('button', 'button', 'Cancel');
		cancel.type = 'button';
		cancel.addEventListener('click', () => card.remove());
		controls.append(button, cancel, status);
		card.append(controls);
		container.append(card);

		button.addEventListener('click', async () => {
			if (!tokenBox.value) return;
			if (proposal.plan.highest_risk === 'high' && !window.confirm(cfg.strings?.confirmHigh || 'Execute this high-risk plan?')) {
				return;
			}
			button.disabled = true;
			setStatus(status, cfg.strings?.working || 'Working…');
			try {
				const result = await api('/actions/execute', {
					method: 'POST',
					body: {
						approval_token: tokenBox.value,
						plan_hash: proposal.plan_hash || '',
					},
				});
				tokenBox.value = '';
				setStatus(status, cfg.strings?.complete || 'Completed.');
				button.remove();
				const output = el('pre', 'site-agent-result', JSON.stringify(result, null, 2));
				card.append(output);
				loadChanges();
			} catch (error) {
				tokenBox.value = '';
				setStatus(status, error.message, true);
				button.remove();
				if (error.data?.results) {
					card.append(el('pre', 'site-agent-result', JSON.stringify(error.data.results, null, 2)));
				}
			}
		});
	}

	function setupChat() {
		const form = document.getElementById('site-agent-chat-form');
		if (!form) return;
		const chat = document.getElementById('site-agent-chat');
		const prompt = document.getElementById('site-agent-prompt');
		const role = document.getElementById('site-agent-role');
		const status = document.getElementById('site-agent-chat-status');
		const proposals = document.getElementById('site-agent-proposals');
		const newChat = document.getElementById('site-agent-new-chat');
		const activateStarter = (starter) => {
			if (!starter) return;
			prompt.value = starter.dataset.prompt || '';
			prompt.dispatchEvent(new Event('input', { bubbles: true }));
			prompt.focus();
		};

		newChat?.addEventListener('click', () => {
			conversationId = '';
			clear(chat);
			clear(proposals);
			appendWelcome(chat);
			prompt.focus();
		});

		chat?.addEventListener('click', (event) => {
			const starter = event.target.closest('[data-prompt]');
			if (!starter) return;
			activateStarter(starter);
		});

		chat?.addEventListener('keydown', (event) => {
			if (event.repeat || !['Enter', ' '].includes(event.key)) return;
			const starter = event.target.closest('[data-prompt]');
			if (!starter) return;
			event.preventDefault();
			event.stopPropagation();
			activateStarter(starter);
		});

		prompt?.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
				event.preventDefault();
				form.requestSubmit();
			}
		});

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (form.dataset.pending === '1') return;
			const text = String(prompt.value || '').trim();
			if (!text) return;
			const isRetry = form.dataset.retrying === '1';
			delete form.dataset.retrying;
			form.dataset.pending = '1';
			form.querySelectorAll('.site-agent-recovery').forEach((node) => node.remove());
			if (!isRetry) appendMessage(chat, 'You', text);
			prompt.value = '';
			clear(proposals);
			setStatus(status, cfg.strings?.working || 'Working…');
			form.querySelector('button[type="submit"]').disabled = true;
			try {
				const result = await api('/chat', {
					method: 'POST',
					timeoutMs: 38000,
					body: {
						prompt: text,
						conversation_id: conversationId,
						role: role?.value || 'site_administrator',
					},
				});
				conversationId = result.conversation_id || conversationId;
				appendMessage(chat, 'Site Agent', result.answer || 'No answer was returned.');
				renderSources(chat, result.sources || []);
				if (result.notice) appendMessage(chat, 'System', result.notice);
				renderProposal(proposals, result.proposal);
				if (result.completion_token) {
					try {
						await api('/chat/rendered', { method: 'POST', body: { completion_token: result.completion_token } });
					} catch (receiptError) {
						setStatus(status, 'The answer is visible, but Site Agent could not record the completion receipt.', true);
						return;
					}
				}
				setStatus(status, '');
			} catch (error) {
				appendMessage(chat, 'System', error.message);
				setStatus(status, error.message, true);
				prompt.value = text;
				const recovery = el('span', 'site-agent-recovery');
				const retry = el('button', 'button site-agent-retry', 'Retry');
				retry.type = 'button';
				retry.addEventListener('click', () => {
					form.dataset.retrying = '1';
					form.requestSubmit();
				}, { once: true });
				const edit = el('button', 'button', 'Edit request');
				edit.type = 'button';
				edit.addEventListener('click', () => prompt.focus());
				recovery.append(retry, edit);
				status.after(recovery);
			} finally {
				form.dataset.pending = '0';
				form.querySelector('button[type="submit"]').disabled = false;
				prompt.focus();
			}
		});
	}

	function setupIndex() {
		const button = document.getElementById('site-agent-rebuild-index');
		if (!button) return;
		const status = document.getElementById('site-agent-index-status');
		const progress = document.getElementById('site-agent-index-progress');
		const total = document.getElementById('site-agent-index-total');

		button.addEventListener('click', async () => {
			button.disabled = true;
			progress.hidden = false;
			progress.removeAttribute('value');
			setStatus(status, 'Starting a new index generation…');
			try {
				let state = await api('/index/start', { method: 'POST', body: {} });
				let calls = 0;
				do {
					state = await api('/index/batch', { method: 'POST', body: { state } });
					calls += 1;
					progress.value = Number(state.processed || 0);
					progress.max = Math.max(Number(state.processed || 0) + 100, 1);
					setStatus(status, `${state.message} Processed: ${Number(state.processed || 0).toLocaleString()}`);
					if (calls > 100000) throw new Error('Index safety stop: too many batches.');
				} while (!state.done);
				progress.value = 1;
				progress.max = 1;
				setStatus(status, `Knowledge index rebuilt. Processed ${Number(state.processed || 0).toLocaleString()} records.`);
				if (total) total.textContent = Number(state.processed || 0).toLocaleString();
			} catch (error) {
				setStatus(status, error.message, true);
			} finally {
				button.disabled = false;
				window.setTimeout(() => { progress.hidden = true; }, 1500);
			}
		});
	}

	function setupSearch() {
		const form = document.getElementById('site-agent-search-form');
		if (!form) return;
		const query = document.getElementById('site-agent-search-query');
		const output = document.getElementById('site-agent-search-results');
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			clear(output);
			output.append(el('p', 'site-agent-status', cfg.strings?.working || 'Working…'));
			try {
				const result = await api(`/search?q=${encodeURIComponent(query.value || '')}&limit=20`);
				clear(output);
				if (!result.results?.length) {
					output.append(el('p', '', 'No matching indexed evidence.'));
					return;
				}
				const list = el('div', 'site-agent-result-list');
				result.results.forEach((item) => {
					const card = el('article', 'site-agent-result-card');
					card.append(el('h3', '', item.title || '(untitled)'));
					card.append(el('p', 'site-agent-result-meta', `${item.type} #${item.object_id} · ${item.subtype || ''}`));
					card.append(el('pre', '', JSON.stringify(item.summary || {}, null, 2)));
					list.append(card);
				});
				output.append(list);
			} catch (error) {
				clear(output);
				output.append(el('p', 'site-agent-status is-error', error.message));
			}
		});
	}

	function setupImpact() {
		const form = document.getElementById('site-agent-impact-form');
		if (!form) return;
		const plugin = document.getElementById('site-agent-impact-plugin');
		const output = document.getElementById('site-agent-impact-results');
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			clear(output);
			output.append(el('p', 'site-agent-status', 'Running bounded local scan…'));
			try {
				const result = await api('/plugin-impact', {
					method: 'POST',
					body: { plugin: plugin.value },
				});
				clear(output);
				const summary = el('div', `site-agent-impact-summary score-${result.score || 1}`);
				summary.append(el('strong', '', `Impact ${result.score || 1}/10`));
				summary.append(el('p', '', result.answer || 'No answer.'));
				output.append(summary);
				output.append(el('pre', 'site-agent-result', JSON.stringify({
					evidence: result.evidence,
					coverage: result.coverage,
					caveats: result.caveats,
				}, null, 2)));
			} catch (error) {
				clear(output);
				output.append(el('p', 'site-agent-status is-error', error.message));
			}
		});
	}

	function diagnosticCard(item) {
		const card = el('article', `site-agent-diagnostic is-${item.status || 'gray'}`);
		card.append(el('h3', '', item.label || item.id));
		card.append(el('strong', '', item.value === null ? 'Not available' : `${item.value} ${item.unit || ''}`));
		card.append(el('p', '', item.evidence || ''));
		return card;
	}

	function setupDiagnostics() {
		const button = document.getElementById('site-agent-run-diagnostics');
		if (!button) return;
		const output = document.getElementById('site-agent-diagnostics');
		button.addEventListener('click', async () => {
			button.disabled = true;
			clear(output);
			output.append(el('p', 'site-agent-status', cfg.strings?.working || 'Working…'));
			try {
				const result = await api('/diagnostics');
				clear(output);
				const summary = el('div', 'site-agent-diagnostic-summary');
				['red', 'yellow', 'green', 'gray'].forEach((state) => {
					const box = el('div', `is-${state}`);
					box.append(el('strong', '', result.summary?.[state] || 0));
					box.append(el('span', '', state));
					summary.append(box);
				});
				output.append(summary);
				const grid = el('div', 'site-agent-diagnostic-grid');
				(result.items || []).forEach((item) => grid.append(diagnosticCard(item)));
				output.append(grid);
				const tables = el('details', 'site-agent-sources');
				tables.append(el('summary', '', 'Largest database tables'));
				tables.append(el('pre', '', JSON.stringify(result.largest_tables || [], null, 2)));
				output.append(tables);
				const limits = el('ul');
				(result.limitations || []).forEach((text) => limits.append(el('li', '', text)));
				output.append(limits);
			} catch (error) {
				clear(output);
				output.append(el('p', 'site-agent-status is-error', error.message));
			} finally {
				button.disabled = false;
			}
		});
	}

	async function loadChanges() {
		const table = document.getElementById('site-agent-changes-table');
		if (!table) return;
		const body = table.querySelector('tbody');
		const status = document.getElementById('site-agent-changes-status');
		clear(body);
		setStatus(status, cfg.strings?.working || 'Working…');
		try {
			const result = await api('/changes?limit=200');
			(result.changes || []).forEach((change) => {
				const row = el('tr');
				row.append(el('td', '', change.created_gmt));
				row.append(el('td', '', change.action));
				row.append(el('td', '', `${change.object_type} ${change.object_id}`));
				const riskCell = el('td');
				riskCell.append(el('span', `site-agent-risk is-${change.risk}`, String(change.risk || '').toUpperCase()));
				row.append(riskCell);
				row.append(el('td', '', change.source));
				const actionCell = el('td');
				if (change.reversible && cfg.canRollback) {
					const button = el('button', 'button button-small', 'Review rollback');
					button.type = 'button';
					button.dataset.ledgerId = String(change.id);
					actionCell.append(button);
				} else {
					actionCell.textContent = '—';
				}
				row.append(actionCell);
				body.append(row);
			});
			setStatus(status, `${(result.changes || []).length} recent changes.`);
		} catch (error) {
			setStatus(status, error.message, true);
		}
	}

	function setupChanges() {
		const table = document.getElementById('site-agent-changes-table');
		if (!table) return;
		const proposalContainer = document.getElementById('site-agent-rollback-proposal');
		document.getElementById('site-agent-refresh-changes')?.addEventListener('click', loadChanges);
		table.addEventListener('click', async (event) => {
			const button = event.target.closest('button[data-ledger-id]');
			if (!button) return;
			button.disabled = true;
			clear(proposalContainer);
			proposalContainer.append(el('p', 'site-agent-status', 'Preparing conflict-aware rollback plan…'));
			try {
				const proposal = await api('/rollback/propose', {
					method: 'POST',
					body: { ledger_id: Number(button.dataset.ledgerId), force: false },
				});
				clear(proposalContainer);
				renderProposal(proposalContainer, proposal);
			} catch (error) {
				clear(proposalContainer);
				proposalContainer.append(el('p', 'site-agent-status is-error', error.message));
			} finally {
				button.disabled = false;
			}
		});
		loadChanges();
	}

	setupChat();
	setupIndex();
	setupSearch();
	setupImpact();
	setupDiagnostics();
	setupChanges();
})();
