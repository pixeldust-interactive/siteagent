'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function node(tag = 'div') {
	return {
		tagName: tag.toUpperCase(),
		children: [],
		listeners: {},
		attributes: {},
		className: '',
		textContent: '',
		value: '',
		classList: {
			add() {},
			remove() {},
			toggle() {},
		},
		addEventListener(type, handler) { this.listeners[type] = handler; },
		append(...children) { this.children.push(...children); },
		replaceChildren(...children) { this.children = children; },
		setAttribute(name, value) { this.attributes[name] = String(value); },
		closest() { return null; },
		querySelector() { return null; },
		querySelectorAll() { return []; },
	};
}

function descendants(parent) {
	return parent.children.flatMap((child) => [child, ...descendants(child)]);
}

const form = node('form');
const query = node('input');
const output = node('div');
query.value = 'homepage';

const elements = {
	'site-agent-search-form': form,
	'site-agent-search-query': query,
	'site-agent-search-results': output,
};

const response = {
	results: [
		{
			title: 'AI Research & Publish',
			raw_title: 'AI Research &#038; Publish',
			type: 'post',
			subtype: 'page',
			object_id: 9,
			modified_gmt: '2026-08-14 06:14:38',
			summary: {
				url: 'https://maisy.wpenginepowered.com/',
				content: '<header><nav>WP MAISYHomeGuideSite AgentSite SignalOutcome Watchdog</nav></header><main><div>Eight focused WordPress tools help visitors choose the right next step.</div><section>The homepage helps visitors understand the available tools and choose a useful next step.</section></main><footer>WP MAISYHomeGuideSite AgentSite SignalOutcome Watchdog</footer>',
			},
		},
		{
			title: 'Homepage settings',
			type: 'option',
			object_id: 'page_on_front',
			modified_gmt: '2026-08-14 06:14:38',
			summary: {
				value: '42',
			},
		},
	],
};

const context = {
	window: { SiteAgentAdmin: { restUrl: 'https://example.test/wp-json/site-agent/v1' } },
	document: {
		getElementById(id) { return elements[id] || null; },
		createElement(tag) { return node(tag); },
		createTextNode(text) { return { tagName: '#TEXT', children: [], textContent: String(text) }; },
	},
	fetch: async () => ({ ok: true, json: async () => response }),
	setTimeout,
	clearTimeout,
};

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin.js'), 'utf8');
vm.runInNewContext(source, context, { filename: 'assets/admin.js' });

assert.strictEqual(typeof form.listeners.submit, 'function', 'knowledge search behavior is registered');

(async () => {
	await form.listeners.submit({ preventDefault() {} });

	const nodes = descendants(output);
	const card = nodes.find((item) => item.className === 'site-agent-result-card');
	assert.ok(card, 'a readable result card is rendered');
	assert.strictEqual(card.children[0].textContent, 'AI Research & Publish', 'decoded readable title is primary');
	assert.strictEqual(card.children[1].textContent, 'Page', 'content type is translated');
	assert.strictEqual(card.children[2].tagName, 'A', 'safe URL is visible');
	assert.strictEqual(card.children[2].textContent, 'https://maisy.wpenginepowered.com/', 'URL is readable');
	assert.match(card.children[3].textContent, /homepage helps visitors/i, 'excerpt is centered on the query match');
	assert.doesNotMatch(card.children[3].textContent, /WP MAISYHomeGuide/, 'navigation and footer boilerplate are excluded');
	assert.ok(card.children[3].textContent.length <= 260, 'excerpt remains bounded');
	assert.strictEqual(card.children[4].tagName, 'DETAILS', 'technical fields use native progressive disclosure');
	assert.strictEqual(card.children.some((item) => item.tagName === 'PRE'), false, 'raw data is not primary card content');

	const raw = descendants(card.children[4]).find((item) => item.tagName === 'PRE');
	assert.ok(raw?.textContent.includes('"content"'), 'raw indexed data remains available inside technical details');
	assert.ok(descendants(card.children[4]).some((item) => item.textContent === 'AI Research &#038; Publish'), 'stored title is available only inside technical details');
	assert.match(source, /card\.append\(el\('h3', '', displayTitle\)\)/, 'display title is inserted with textContent through the safe element helper');

	const cards = nodes.filter((item) => item.className === 'site-agent-result-card');
	assert.match(cards[1].children[2].textContent, /matched by its title or site metadata for “homepage”/i, 'metadata-only matches use a calm explanatory fallback');

	const php = fs.readFileSync(path.join(__dirname, '..', 'includes', 'class-admin.php'), 'utf8');
	const css = fs.readFileSync(path.join(__dirname, '..', 'assets', 'admin.css'), 'utf8');
	assert.match(php, /Site Agent scans your pages, plugins, and settings/, 'knowledge purpose is explained');
	assert.match(php, /Refresh site knowledge before using chat/, 'empty or stale knowledge has a direct action');
	assert.match(php, /Preview what Site Agent knows/, 'evidence jargon is replaced');
	assert.doesNotMatch(php, /apocalypse fan fiction/, 'unprofessional copy is removed');
	assert.match(php, /<details class="site-agent-help">/, 'help uses a native disclosure control');
	assert.match(css, /\.site-agent-help:hover[\s\S]*\.site-agent-help:focus-within/, 'help supports hover and keyboard focus');
	assert.match(css, /height:\s*40px[\s\S]*width:\s*40px/, 'help target meets the minimum touch size');
	assert.match(css, /\.site-agent-title-with-help\s*\{[\s\S]*position:\s*relative/, 'help content is anchored to the full title row');
	assert.match(css, /\.site-agent-help-content\s*\{[\s\S]*box-sizing:\s*border-box/, 'help content width includes padding on narrow screens');

	console.log('PASS Site Knowledge uses plain language, readable results, and progressive disclosure');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
