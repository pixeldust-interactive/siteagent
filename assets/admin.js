(() => {
	'use strict';

	const cfg = window.SiteAgentAdmin || {};
	const rootUrl = String(cfg.restUrl || '').replace(/\/+$/, '');
	let conversationId = '';
	let proposalSequence = 0;

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
		if (!container) return null;
		const wrap = el('div', `site-agent-message ${who === 'You' ? 'is-user' : 'is-agent'}`);
		wrap.append(el('div', 'site-agent-message-label', who));
		wrap.append(el('div', 'site-agent-message-body', text));
		container.append(wrap);
		wrap.scrollIntoView({ block: 'end', behavior: 'smooth' });
		return wrap;
	}

	function appendWorkingMessage(container) {
		const wrap = appendMessage(container, 'Site Agent', 'Working on your request');
		if (!wrap) return null;
		wrap.classList.add('is-working');
		wrap.setAttribute('aria-label', 'Site Agent is working on your request');
		const body = wrap.querySelector('.site-agent-message-body');
		const dots = el('span', 'site-agent-working-dots');
		dots.setAttribute('aria-hidden', 'true');
		for (let index = 0; index < 3; index += 1) dots.append(el('span'));
		body?.append(dots);
		return wrap;
	}

	function appendFailure(container, message, onRetry, onEdit) {
		const wrap = appendMessage(container, 'Site Agent', message);
		if (!wrap) return null;
		wrap.classList.add('is-error');
		wrap.setAttribute('role', 'alert');
		const recovery = el('div', 'site-agent-recovery');
		const retry = el('button', 'button site-agent-retry', 'Try again');
		retry.type = 'button';
		retry.addEventListener('click', onRetry, { once: true });
		const edit = el('button', 'button', 'Edit request');
		edit.type = 'button';
		edit.addEventListener('click', onEdit);
		recovery.append(retry, edit);
		wrap.append(recovery);
		return wrap;
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

	function technicalDetails(payload) {
		const details = el('details', 'site-agent-technical-details');
		const summary = el('summary', '', 'Technical details');
		summary.addEventListener('keydown', (event) => {
			if (!event.repeat && ['Enter', ' '].includes(event.key)) {
				event.stopPropagation();
			}
		});
		details.append(summary);
		details.append(el('pre', 'site-agent-args', JSON.stringify(payload || {}, null, 2)));
		return details;
	}

	function plainSettingName(option) {
		return {
			blogname: 'site title',
			blogdescription: 'site tagline',
			timezone_string: 'site time zone',
			date_format: 'date format',
			time_format: 'time format',
			start_of_week: 'start of the week',
			show_on_front: 'homepage display',
			page_on_front: 'homepage',
			page_for_posts: 'posts page',
			posts_per_page: 'posts shown per page',
			blog_public: 'search-engine visibility',
		}[String(option || '')] || 'site setting';
	}

	function plainSettingValue(value) {
		if (typeof value === 'boolean') return value ? 'on' : 'off';
		if (typeof value !== 'string' && typeof value !== 'number') return 'a new value';
		const text = String(value).trim();
		return text ? text.slice(0, 160) : 'an empty value';
	}

	function actionPreview(action) {
		const args = action?.args || {};
		if (action?.name === 'option.update') {
			return `Change the ${plainSettingName(args.option)} to “${plainSettingValue(args.value)}”.`;
		}
		return String(action?.preview || 'Make the proposed change.');
	}

	function confirmationLabel(actions, highestRisk) {
		if (actions.length === 1) {
			const action = actions[0];
			const labels = {
				'post.create': 'Create this draft',
				'post.update': 'Update this page or post',
				'post.trash': 'Move this item to Trash',
				'post.meta.update': 'Update this page detail',
				'seo.update': 'Update this search information',
				'plugin.activate': 'Activate this plugin',
				'plugin.deactivate': 'Deactivate this plugin',
				'transients.delete_expired': 'Delete expired temporary data',
				'rollback.perform': 'Roll back this change',
			};
			if (action?.name === 'option.update') return `Change the ${plainSettingName(action.args?.option)}`;
			if (labels[action?.name]) return labels[action.name];
		}
		const count = actions.length || 1;
		return highestRisk === 'high'
			? `Approve ${count} high-impact change${count === 1 ? '' : 's'}`
			: `Make ${count} change${count === 1 ? '' : 's'}`;
	}

	function renderExecutionOutcome(card, result) {
		const results = Array.isArray(result?.results) ? result.results : [];
		const outcome = el('section', 'site-agent-execution-outcome');
		outcome.setAttribute('role', 'status');
		outcome.append(el('h4', '', results.length === 1 ? 'Change complete' : `${results.length || 1} changes complete`));
		outcome.append(el('p', '', results.length === 1 ? 'The requested change was completed.' : 'The requested changes were completed.'));
		if (cfg.changesUrl) {
			const ledger = el('a', 'button', 'View Change Ledger');
			ledger.href = String(cfg.changesUrl);
			outcome.append(ledger);
		}
		outcome.append(technicalDetails(result));
		card.append(outcome);
	}

	function renderProposal(container, proposal) {
		if (!container || !proposal?.plan || !proposal.approval_token) return;
		const tokenBox = { value: String(proposal.approval_token) };
		const actions = Array.isArray(proposal.plan.actions) ? proposal.plan.actions : [];
		proposalSequence += 1;
		const titleId = `site-agent-proposal-title-${proposalSequence}`;
		const card = el('section', `site-agent-proposal risk-${proposal.plan.highest_risk || 'high'}`);
		card.setAttribute('aria-labelledby', titleId);
		const title = el('h3', '', 'Review the proposed change');
		title.id = titleId;
		card.append(title);
		if (proposal.plan.reason) card.append(el('p', 'site-agent-proposal-reason', proposal.plan.reason));
		card.append(el('p', 'site-agent-proposal-intro', 'Review only — nothing changes until you choose the action below.'));

		const list = el('ol', 'site-agent-proposal-actions');
		actions.forEach((action) => {
			const item = el('li');
			item.append(el('strong', '', actionPreview(action)));
			item.append(el('span', `site-agent-risk is-${action.risk}`, String(action.risk || 'high').toUpperCase()));
			item.append(technicalDetails({ action: action.name || '', details: action.args || {} }));
			list.append(item);
		});
		card.append(list);
		card.append(el('p', 'site-agent-proposal-note', 'Cancel leaves the site unchanged. After completion, rollback is offered in Change Ledger only when a verified snapshot supports it.'));

		const controls = el('div', 'site-agent-proposal-controls');
		const button = el('button', 'button button-primary', confirmationLabel(actions, proposal.plan.highest_risk));
		button.type = 'button';
		const status = el('span', 'site-agent-status');
		const cancel = el('button', 'button', 'Cancel');
		cancel.type = 'button';
		cancel.addEventListener('click', () => {
			tokenBox.value = '';
			card.remove();
		});
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
				renderExecutionOutcome(card, result);
				loadChanges();
			} catch (error) {
				tokenBox.value = '';
				setStatus(status, error.message, true);
				button.remove();
				if (error.data?.results) {
					card.append(technicalDetails({ completed_steps: error.data.results }));
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
		const conversationList = document.getElementById('site-agent-conversation-list');
		let failedRequest = '';
		const showConversation = (conversation) => {
			conversationId = conversation.conversation_id || '';
			clear(chat);
			clear(proposals);
			(conversation.messages || []).forEach((message) => {
				appendMessage(chat, message.role === 'user' ? 'You' : 'Site Agent', message.content || '');
			});
			prompt.value = '';
			failedRequest = '';
			setStatus(status, 'Conversation opened.');
			prompt.focus();
		};
		const loadConversationHistory = async () => {
			if (!conversationList) return;
			try {
				const result = await api('/conversations?limit=12');
				clear(conversationList);
				if (!result.enabled || !result.conversations?.length) {
					conversationList.append(el('p', 'description', 'No saved conversations yet.'));
					return;
				}
				result.conversations.forEach((conversation) => {
					const row = el('div', 'site-agent-conversation-row');
					row.setAttribute('role', 'listitem');
					const item = el('button', 'site-agent-conversation-item');
					item.type = 'button';
					item.append(el('strong', '', conversation.title || 'Untitled conversation'));
					item.append(el('span', '', `${Number(conversation.message_count || 0)} messages`));
					item.addEventListener('click', async () => {
						item.disabled = true;
						setStatus(status, 'Opening conversation…');
						try {
							showConversation(await api(`/conversations/${encodeURIComponent(conversation.id)}`));
						} catch (error) {
							setStatus(status, error.message, true);
						} finally {
							item.disabled = false;
						}
					});
					row.append(item);
					conversationList.append(row);
				});
			} catch (error) {
				clear(conversationList);
				conversationList.append(el('p', 'site-agent-status is-error', 'Recent conversations could not be loaded.'));
			}
		};
		const activateStarter = (starter) => {
			if (!starter) return;
			prompt.value = starter.dataset.prompt || '';
			prompt.dispatchEvent(new Event('input', { bubbles: true }));
			prompt.focus();
		};

		newChat?.addEventListener('click', () => {
			conversationId = '';
			failedRequest = '';
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
			if (!event.target?.closest || event.target.closest('summary, textarea, input, select, a, [contenteditable="true"]')) return;
			const starter = event.target.closest('button.site-agent-starter[data-prompt]');
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
			const text = String(prompt.value || failedRequest || '').trim();
			if (!text) return;
			const isRetry = form.dataset.retrying === '1';
			delete form.dataset.retrying;
			form.dataset.pending = '1';
			chat?.querySelectorAll('.site-agent-message.is-error').forEach((node) => node.remove());
			if (!isRetry) appendMessage(chat, 'You', text);
			prompt.value = '';
			failedRequest = '';
			clear(proposals);
			setStatus(status, cfg.strings?.working || 'Working…');
			form.querySelector('button[type="submit"]').disabled = true;
			const working = appendWorkingMessage(chat);
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
				working?.remove();
				conversationId = result.conversation_id || conversationId;
				appendMessage(chat, 'Site Agent', result.answer || 'No answer was returned.');
				renderSources(chat, result.sources || []);
				if (result.notice) appendMessage(chat, 'System', result.notice);
				renderProposal(chat, result.proposal);
				if (result.completion_token) {
					try {
						await api('/chat/rendered', { method: 'POST', body: { completion_token: result.completion_token } });
					} catch (receiptError) {
						setStatus(status, 'The answer is visible, but Site Agent could not record the completion receipt.', true);
						return;
					}
				}
				setStatus(status, '');
				loadConversationHistory();
			} catch (error) {
				working?.remove();
				failedRequest = text;
				appendFailure(
					chat,
					error.message,
					() => {
						form.dataset.retrying = '1';
						form.requestSubmit();
					},
					() => {
						prompt.value = failedRequest;
						prompt.focus();
					}
				);
				setStatus(status, 'Request failed. Your draft is ready to retry or edit.', true);
				prompt.value = text;
			} finally {
				form.dataset.pending = '0';
				form.querySelector('button[type="submit"]').disabled = false;
				prompt.focus();
			}
		});

		loadConversationHistory();
	}

	function setupIndex() {
		const button = document.getElementById('site-agent-rebuild-index');
		if (!button) return;
		const status = document.getElementById('site-agent-index-status');
		const progress = document.getElementById('site-agent-index-progress');
		const total = document.getElementById('site-agent-index-total');
		const updated = document.getElementById('site-agent-index-updated');
		const heading = document.getElementById('site-agent-index-heading');
		const guidance = document.getElementById('site-agent-index-guidance');
		const callout = button.closest('.site-agent-index-callout');

		button.addEventListener('click', async () => {
			button.disabled = true;
			progress.hidden = false;
			progress.removeAttribute('value');
			setStatus(status, 'Preparing the site scan…');
			try {
				let state = await api('/index/start', { method: 'POST', body: {} });
				let calls = 0;
				do {
					state = await api('/index/batch', { method: 'POST', body: { state } });
					calls += 1;
					progress.value = Number(state.processed || 0);
					progress.max = Math.max(Number(state.processed || 0) + 100, 1);
					setStatus(status, `Scanning your site: ${Number(state.processed || 0).toLocaleString()} items found.`);
					if (calls > 100000) throw new Error('Index safety stop: too many batches.');
				} while (!state.done);
				progress.value = 1;
				progress.max = 1;
				setStatus(status, `Site knowledge is ready. ${Number(state.processed || 0).toLocaleString()} items are available to Site Agent.`);
				if (total) total.textContent = Number(state.processed || 0).toLocaleString();
				if (updated) updated.textContent = 'Just now';
				if (heading) heading.textContent = 'Site knowledge is ready';
				if (guidance) guidance.textContent = 'Refresh after important content, plugin, or settings changes.';
				button.textContent = 'Refresh site knowledge';
				callout?.classList.remove('is-needed');
				callout?.classList.add('is-ready');
			} catch (error) {
				setStatus(status, error.message, true);
			} finally {
				button.disabled = false;
				window.setTimeout(() => { progress.hidden = true; }, 1500);
			}
		});
	}

	function knowledgeTypeLabel(item) {
		const type = String(item.type || '').toLowerCase();
		const subtype = String(item.subtype || '').replace(/[_-]+/g, ' ').trim();
		if (type === 'post') {
			if (subtype === 'page') return 'Page';
			if (subtype === 'post') return 'Post';
			return subtype ? subtype.replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Content';
		}
		const labels = {
			plugin: 'Plugin',
			theme: 'Theme',
			form: 'Form',
			builder: 'Editing system',
			role: 'WordPress role',
			cron: 'Scheduled task',
			option: 'Site setting',
			database: 'Database information',
			system: 'WordPress information',
		};
		return labels[type] || (type ? type.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Site information');
	}

	function readableKnowledgeText(value) {
		return String(value || '')
			.replace(/<(script|style|nav|footer|aside)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
			.replace(/<!--[\s\S]*?-->/g, ' ')
			.replace(/<\/?(?:address|article|blockquote|br|dd|div|dl|dt|fieldset|figcaption|figure|h[1-6]|hr|li|main|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>/gi, ' ')
			.replace(/<[^>]*>/g, ' ')
			.replace(/&nbsp;/gi, ' ')
			.replace(/&amp;/gi, '&')
			.replace(/&quot;/gi, '"')
			.replace(/&#039;/gi, "'")
			.replace(/\s+/g, ' ')
			.trim();
	}

	function knowledgeQueryTerms(query) {
		return String(query || '')
			.toLowerCase()
			.replace(/[^a-z0-9_-]+/g, ' ')
			.trim()
			.split(/\s+/)
			.filter((term) => term.length >= 3);
	}

	function boundedKnowledgeExcerpt(text, matchIndex = -1, matchLength = 0) {
		const limit = 260;
		if (text.length <= limit) return text;
		let start = matchIndex >= 0 ? Math.max(0, matchIndex - 105) : 0;
		let end = Math.min(text.length, start + limit - 2);
		if (matchIndex >= 0 && matchIndex + matchLength > end) {
			end = Math.min(text.length, matchIndex + matchLength + 105);
			start = Math.max(0, end - (limit - 2));
		}
		if (start > 0) {
			const firstSpace = text.indexOf(' ', start);
			if (firstSpace >= 0 && firstSpace < end) start = firstSpace + 1;
		}
		if (end < text.length) {
			const lastSpace = text.lastIndexOf(' ', end);
			if (lastSpace > start) end = lastSpace;
		}
		return `${start > 0 ? '…' : ''}${text.slice(start, end).trim()}${end < text.length ? '…' : ''}`;
	}

	function knowledgeExcerpt(summary, query) {
		const candidates = [];
		if (typeof summary === 'string') candidates.push(readableKnowledgeText(summary));
		if (summary && typeof summary === 'object') {
			['excerpt', 'description', 'content', 'answer', 'label', 'name', 'value'].forEach((key) => {
				if (typeof summary[key] === 'string') candidates.push(readableKnowledgeText(summary[key]));
			});
			Object.entries(summary)
				.filter(([key, value]) => !['url', 'id', 'object_id', 'status'].includes(key) && typeof value === 'string')
				.forEach(([, value]) => candidates.push(readableKnowledgeText(value)));
		}
		const readable = candidates.filter(Boolean);
		const terms = knowledgeQueryTerms(query);
		for (const text of readable) {
			const lower = text.toLowerCase();
			let matchIndex = -1;
			let matchTerm = '';
			for (const term of terms) {
				const index = lower.indexOf(term);
				if (index >= 0 && (matchIndex < 0 || index < matchIndex)) {
					matchIndex = index;
					matchTerm = term;
				}
			}
			if (matchIndex >= 0) return boundedKnowledgeExcerpt(text, matchIndex, matchTerm.length);
		}
		if (terms.length) {
			const phrase = String(query || '').trim().slice(0, 80);
			return `This item matched by its title or site metadata for “${phrase}”. Open Technical details for more information.`;
		}
		if (readable.length) return boundedKnowledgeExcerpt(readable[0]);
		return 'Site Agent has technical information for this item. Open Technical details to review it.';
	}

	function knowledgeTechnicalDetails(item) {
		const details = el('details', 'site-agent-technical-details');
		details.append(el('summary', '', 'Technical details'));
		const list = el('dl', 'site-agent-technical-list');
		[
			['Record type', item.type || 'Not available'],
			['Content subtype', item.subtype || 'Not available'],
			['Internal ID', item.object_id ?? 'Not available'],
			['Last indexed', item.modified_gmt || 'Not available'],
			...(item.raw_title ? [['Stored title', item.raw_title]] : []),
		].forEach(([label, value]) => {
			list.append(el('dt', '', label), el('dd', '', value));
		});
		details.append(list);
		const raw = el('details', 'site-agent-raw-details');
		raw.append(el('summary', '', 'Raw indexed data'));
		raw.append(el('pre', '', JSON.stringify(item.summary || {}, null, 2)));
		details.append(raw);
		return details;
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
					output.append(el('p', '', 'Site Agent does not know anything matching that yet. Try another phrase or refresh site knowledge.'));
					return;
				}
				const list = el('div', 'site-agent-result-list');
				result.results.forEach((item) => {
					const card = el('article', 'site-agent-result-card');
					const displayTitle = item.title || '(untitled)';
					card.append(el('h3', '', displayTitle));
					card.append(el('p', 'site-agent-result-meta', knowledgeTypeLabel(item)));
					const url = typeof item.summary?.url === 'string' ? item.summary.url.trim() : '';
					if (/^https?:\/\//i.test(url)) {
						const link = el('a', 'site-agent-result-link', url);
						link.href = url;
						link.setAttribute('aria-label', `Open ${displayTitle}`);
						card.append(link);
					}
					card.append(el('p', 'site-agent-result-excerpt', knowledgeExcerpt(item.summary, query.value)));
					card.append(knowledgeTechnicalDetails(item));
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
			output.append(el('p', 'site-agent-status', 'Checking where this plugin is used…'));
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
				const findings = el('details', 'site-agent-technical-details site-agent-impact-details');
				findings.append(el('summary', '', 'Technical findings'));
				findings.append(el('pre', 'site-agent-result', JSON.stringify({
					evidence: result.evidence,
					coverage: result.coverage,
					caveats: result.caveats,
				}, null, 2)));
				output.append(findings);
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
