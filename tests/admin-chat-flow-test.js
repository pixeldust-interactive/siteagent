'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class TestNode {
	constructor(tag = 'div') {
		this.tagName = tag.toUpperCase();
		this.children = [];
		this.listeners = {};
		this.attributes = {};
		this.className = '';
		this.dataset = {};
		this.disabled = false;
		this.parentNode = null;
		this.textContent = '';
		this.type = '';
		this.value = '';
		this.focusCount = 0;
		this.classList = {
			add: (...names) => {
				const classes = new Set(this.className.split(/\s+/).filter(Boolean));
				names.forEach((name) => classes.add(name));
				this.className = [...classes].join(' ');
			},
			remove: (...names) => {
				const remove = new Set(names);
				this.className = this.className.split(/\s+/).filter((name) => name && !remove.has(name)).join(' ');
			},
			toggle: (name, force) => force ? this.classList.add(name) : this.classList.remove(name),
		};
	}

	addEventListener(type, handler) { this.listeners[type] = handler; }
	append(...children) {
		children.forEach((child) => {
			if (child && typeof child === 'object') child.parentNode = this;
			this.children.push(child);
		});
	}
	replaceChildren(...children) {
		this.children.forEach((child) => { if (child && typeof child === 'object') child.parentNode = null; });
		this.children = [];
		this.append(...children);
	}
	remove() {
		if (!this.parentNode) return;
		this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
		this.parentNode = null;
	}
	setAttribute(name, value) { this.attributes[name] = String(value); }
	focus() { this.focusCount += 1; }
	scrollIntoView() {}
	dispatchEvent() {}
	querySelector(selector) { return descendants(this).find((item) => matches(item, selector)) || null; }
	querySelectorAll(selector) { return descendants(this).filter((item) => matches(item, selector)); }
	closest(selector) { return matches(this, selector) ? this : this.parentNode?.closest?.(selector) || null; }
}

function descendants(parent) {
	return (parent.children || []).flatMap((child) => child && typeof child === 'object' ? [child, ...descendants(child)] : []);
}

function matches(item, selector) {
	if (!item || typeof item !== 'object') return false;
	if (selector === 'button[type="submit"]') return item.tagName === 'BUTTON' && item.type === 'submit';
	if (selector === '[data-prompt]') return Object.prototype.hasOwnProperty.call(item.dataset || {}, 'prompt');
	if (selector.startsWith('.')) return selector.slice(1).split('.').every((name) => item.className.split(/\s+/).includes(name));
	return item.tagName === selector.toUpperCase();
}

function deferred() {
	let resolve;
	const promise = new Promise((done) => { resolve = done; });
	return { promise, resolve };
}

const response = (data, ok = true) => ({ ok, json: async () => data });
const form = new TestNode('form');
const chat = new TestNode('div');
const prompt = new TestNode('textarea');
const role = new TestNode('select');
const status = new TestNode('p');
const newChat = new TestNode('button');
const conversationList = new TestNode('div');
const send = new TestNode('button');
send.type = 'submit';
form.append(send);
role.value = 'site_administrator';

const elements = {
	'site-agent-chat-form': form,
	'site-agent-chat': chat,
	'site-agent-prompt': prompt,
	'site-agent-role': role,
	'site-agent-chat-status': status,
	'site-agent-new-chat': newChat,
	'site-agent-conversation-list': conversationList,
};

let pendingChat = null;
let chatMode = 'success';
const chatBodies = [];
const fetchCalls = [];
const fetch = async (url, options = {}) => {
	fetchCalls.push({ url, options });
	if (url.endsWith('/conversations?limit=12')) {
		return response({ enabled: true, conversations: [{ id: '11111111-1111-4111-8111-111111111111', title: 'Check my homepage', message_count: 2 }] });
	}
	if (url.endsWith('/conversations/11111111-1111-4111-8111-111111111111')) {
		return response({ conversation_id: '11111111-1111-4111-8111-111111111111', messages: [
			{ role: 'user', content: 'Check my homepage' },
			{ role: 'assistant', content: 'Your homepage is available.' },
		] });
	}
	if (url.endsWith('/chat/rendered')) return response({ recorded: true });
	if (url.endsWith('/chat')) {
		chatBodies.push(JSON.parse(options.body));
		if (pendingChat) return pendingChat.promise;
		if (chatMode === 'failure') return response({ message: 'The provider did not answer.' }, false);
		return response({ conversation_id: '11111111-1111-4111-8111-111111111111', answer: 'The retry worked.', sources: [] });
	}
	throw new Error(`Unexpected request: ${url}`);
};

const context = {
	window: { SiteAgentAdmin: { restUrl: 'https://example.test/wp-json/site-agent/v1', nonce: 'nonce' } },
	document: {
		getElementById(id) { return elements[id] || null; },
		createElement(tag) { return new TestNode(tag); },
		createTextNode(text) { const item = new TestNode('#text'); item.textContent = String(text); return item; },
	},
	Event: class Event { constructor(type, options = {}) { this.type = type; this.bubbles = Boolean(options.bubbles); } },
	fetch,
	setTimeout,
	clearTimeout,
};

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin.js'), 'utf8');
vm.runInNewContext(source, context, { filename: 'assets/admin.js' });

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
form.requestSubmit = () => {
	form.lastSubmit = form.listeners.submit({ preventDefault() {} });
	return form.lastSubmit;
};

(async () => {
	await flush();
	const historyButton = conversationList.querySelector('.site-agent-conversation-item');
	assert.ok(historyButton, 'bounded recent conversation history is rendered');
	assert.strictEqual(historyButton.children[0].textContent, 'Check my homepage', 'history uses a readable title');
	await historyButton.listeners.click();
	assert.deepStrictEqual(chat.querySelectorAll('.site-agent-message').map((item) => item.children[0].textContent), ['You', 'Site Agent'], 'history restores turns in reading order');

	prompt.value = 'Improve the homepage summary';
	pendingChat = deferred();
	const activeSubmit = form.listeners.submit({ preventDefault() {} });
	await flush();
	assert.ok(chat.querySelector('.site-agent-message.is-working'), 'the active state appears inside the conversation');
	assert.strictEqual(send.disabled, true, 'Send is disabled while active');
	pendingChat.resolve(response({
		conversation_id: '11111111-1111-4111-8111-111111111111',
		answer: 'Here is the answer.',
		sources: [{ title: 'Homepage', type: 'page', object_id: 42 }],
		proposal: { approval_token: 'approval-token', plan_hash: 'plan-hash', plan: { highest_risk: 'low', actions: [{ name: 'post.update', preview: 'Update the homepage', risk: 'low', args: { id: 42 } }] } },
		completion_token: 'completion-token',
	}));
	await activeSubmit;
	pendingChat = null;
	assert.strictEqual(chat.querySelector('.site-agent-message.is-working'), null, 'the active state is removed after completion');
	assert.ok(chat.querySelector('.site-agent-sources'), 'sources remain attached to the conversation');
	assert.ok(chat.querySelector('.site-agent-proposal'), 'the proposal remains attached to the conversation');
	assert.strictEqual(prompt.value, '', 'successful sends clear the draft');

	prompt.value = 'This request will fail';
	chatMode = 'failure';
	await form.listeners.submit({ preventDefault() {} });
	const failure = chat.querySelector('.site-agent-message.is-error');
	assert.ok(failure, 'the failed-send state appears inside the conversation');
	assert.strictEqual(failure.attributes.role, 'alert', 'the failure is announced as an alert');
	assert.strictEqual(prompt.value, 'This request will fail', 'the failed draft remains editable');
	const retry = failure.querySelector('.site-agent-recovery').children[0];
	const userTurnsBeforeRetry = chat.querySelectorAll('.site-agent-message.is-user').length;
	chatMode = 'retry';
	retry.listeners.click();
	await flush();
	await form.lastSubmit;
	assert.strictEqual(chat.querySelectorAll('.site-agent-message.is-user').length, userTurnsBeforeRetry, 'Retry does not duplicate the user turn');
	assert.ok(chat.querySelectorAll('.site-agent-message-body').some((item) => item.textContent === 'The retry worked.'), 'Retry appends the recovered answer');
	assert.strictEqual(chatBodies.at(-1).prompt, 'This request will fail', 'Retry resubmits the preserved draft');

	const php = fs.readFileSync(path.join(__dirname, '..', 'includes', 'class-admin.php'), 'utf8');
	const css = fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin.css'), 'utf8');
	assert.match(php, /role="log" aria-live="polite" aria-relevant="additions"/, 'chat exposes screen-reader log semantics');
	assert.match(php, /Recent conversations/, 'history is available without crowding the chat');
	assert.match(css, /\.site-agent-chat\s*\{[\s\S]*max-height:\s*58vh/, 'long conversations scroll independently');
	assert.match(css, /overflow-wrap:\s*anywhere/, 'long content wraps without horizontal overflow');
	assert.match(css, /@media \(prefers-reduced-motion: reduce\)[\s\S]*site-agent-working-dots/, 'working animation respects reduced motion');
	assert.ok(fetchCalls.some((call) => call.url.endsWith('/chat/rendered')), 'visible completion records its receipt');

	console.log('PASS chat states, recovery, proposals, history, and accessibility remain in one conversation flow');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
