'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

function node() {
	return {
		dataset: {},
		listeners: {},
		value: '',
		focusCount: 0,
		addEventListener(type, handler) { this.listeners[type] = handler; },
		querySelectorAll() { return []; },
		querySelector() { return submitButton; },
		dispatchEvent() {},
		focus() { this.focusCount += 1; },
	};
}

const form = node();
const chat = node();
const prompt = node();
const submitButton = node();
const elements = {
	'site-agent-chat-form': form,
	'site-agent-chat': chat,
	'site-agent-prompt': prompt,
	'site-agent-role': node(),
	'site-agent-chat-status': node(),
	'site-agent-proposals': node(),
};

const context = {
	window: { SiteAgentAdmin: {} },
	document: {
		getElementById(id) { return elements[id] || null; },
		createElement() { return node(); },
		createTextNode(text) { return { textContent: text }; },
	},
	Event: class Event {
		constructor(type, options = {}) { this.type = type; this.bubbles = Boolean(options.bubbles); }
	},
	fetch: async () => ({ ok: true, json: async () => ({}) }),
	setTimeout,
	clearTimeout,
};

vm.runInNewContext(
	fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin.js'), 'utf8'),
	context,
	{ filename: 'assets/admin.js' }
);

assert.strictEqual(typeof chat.listeners.keydown, 'function', 'chat keyboard handler is registered');
assert.strictEqual(typeof chat.listeners.click, 'function', 'chat pointer handler is registered');

for (const key of ['Enter', ' ']) {
	prompt.value = '';
	prompt.focusCount = 0;
	let prevented = 0;
	let stopped = 0;
	const starter = { dataset: { prompt: `Prompt activated by ${key === ' ' ? 'Space' : key}` } };
	chat.listeners.keydown({
		key,
		repeat: false,
		target: { closest: () => starter },
		preventDefault() { prevented += 1; },
		stopPropagation() { stopped += 1; },
	});
	assert.strictEqual(prompt.value, starter.dataset.prompt, `${key} fills the composer`);
	assert.strictEqual(prompt.focusCount, 1, `${key} moves focus to the composer once`);
	assert.strictEqual(prevented, 1, `${key} prevents a synthesized duplicate click`);
	assert.strictEqual(stopped, 1, `${key} does not bubble into the composer handler`);
}

prompt.value = '';
const pointerStarter = { dataset: { prompt: 'Pointer prompt' } };
chat.listeners.click({ target: { closest: () => pointerStarter } });
assert.strictEqual(prompt.value, 'Pointer prompt', 'pointer activation still fills the composer');

console.log('PASS starter prompts support Enter, Space, and pointer activation');
