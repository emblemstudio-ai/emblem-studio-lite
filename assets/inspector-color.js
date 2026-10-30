(function () {
	'use strict';

	function clamp01(n) { return n < 0 ? 0 : n > 1 ? 1 : n; }
	function clamp255(n) { n = Math.round(n); return n < 0 ? 0 : n > 255 ? 255 : n; }
	function to2(n) { var s = clamp255(n).toString(16); return s.length === 1 ? '0' + s : s; }

	function hsvToRgb(h, s, v) {
		h = (h % 360 + 360) % 360;
		var c = v * s;
		var x = c * (1 - Math.abs((h / 60) % 2 - 1));
		var m = v - c;
		var r = 0, g = 0, b = 0;
		if (h < 60) { r = c; g = x; }
		else if (h < 120) { r = x; g = c; }
		else if (h < 180) { g = c; b = x; }
		else if (h < 240) { g = x; b = c; }
		else if (h < 300) { r = x; b = c; }
		else { r = c; b = x; }
		return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
	}
	function rgbToHsv(r, g, b) {
		r /= 255; g /= 255; b /= 255;
		var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
		var h = 0;
		if (d !== 0) {
			if (max === r) h = 60 * (((g - b) / d) % 6);
			else if (max === g) h = 60 * ((b - r) / d + 2);
			else h = 60 * ((r - g) / d + 4);
		}
		if (h < 0) h += 360;
		var s = max === 0 ? 0 : d / max;
		return { h: h, s: s, v: max };
	}
	function hslToRgb(h, s, l) {
		h = (h % 360 + 360) % 360; s = clamp01(s); l = clamp01(l);
		var c = (1 - Math.abs(2 * l - 1)) * s;
		var x = c * (1 - Math.abs((h / 60) % 2 - 1));
		var m = l - c / 2;
		var r = 0, g = 0, b = 0;
		if (h < 60) { r = c; g = x; }
		else if (h < 120) { r = x; g = c; }
		else if (h < 180) { g = c; b = x; }
		else if (h < 240) { g = x; b = c; }
		else if (h < 300) { r = x; b = c; }
		else { r = c; b = x; }
		return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
	}

	var _ctx = null;
	function normalizeViaCanvas(str) {
		try {
			if (!_ctx) _ctx = document.createElement('canvas').getContext('2d');
			_ctx.fillStyle = '#000';
			_ctx.fillStyle = str;
			var a = _ctx.fillStyle;
			_ctx.fillStyle = '#fff';
			_ctx.fillStyle = str;
			var b = _ctx.fillStyle;
			return a === b ? a : null;
		} catch (e) { return null; }
	}
	function chan(tok) {
		tok = tok.trim();
		if (tok.charAt(tok.length - 1) === '%') return clamp255(parseFloat(tok) * 2.55);
		return clamp255(parseFloat(tok));
	}
	function pct(tok) { return clamp01(parseFloat(tok) / 100); }

	function parse(str) {
		if (!str && str !== 0) return null;
		str = String(str).trim();
		var m;

		m = /^#([0-9a-fA-F]{3,8})$/.exec(str);
		if (m) {
			var h = m[1];
			function dup(c) { return parseInt(c + c, 16); }
			if (h.length === 3) return { r: dup(h[0]), g: dup(h[1]), b: dup(h[2]), a: 1 };
			if (h.length === 4) return { r: dup(h[0]), g: dup(h[1]), b: dup(h[2]), a: dup(h[3]) / 255 };
			if (h.length === 6) return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16), a: 1 };
			if (h.length === 8) return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16), a: parseInt(h.slice(6, 8), 16) / 255 };
			return null;
		}
		m = /^rgba?\(([^)]+)\)$/i.exec(str);
		if (m) {
			var p = m[1].split(/[,/]/);
			if (p.length >= 3) return { r: chan(p[0]), g: chan(p[1]), b: chan(p[2]), a: p.length > 3 ? clamp01(parseFloat(p[3])) : 1 };
		}
		m = /^hsla?\(([^)]+)\)$/i.exec(str);
		if (m) {
			var q = m[1].split(/[,/]/);
			if (q.length >= 3) {
				var rgb = hslToRgb(parseFloat(q[0]), pct(q[1]), pct(q[2]));
				return { r: rgb.r, g: rgb.g, b: rgb.b, a: q.length > 3 ? clamp01(parseFloat(q[3])) : 1 };
			}
		}
		var norm = normalizeViaCanvas(str);
		if (norm && norm.toLowerCase() !== str.toLowerCase()) return parse(norm);
		return null;
	}

	function format(c, original, mode) {
		var r = clamp255(c.r), g = clamp255(c.g), b = clamp255(c.b), a = clamp01(c.a);
		if (mode === 'rgba') {
			return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + (Math.round(a * 1000) / 1000) + ')';
		}
		if (mode === 'hex') {
			var hx = '#' + to2(r) + to2(g) + to2(b);
			return a >= 1 ? hx : hx + to2(a * 255);
		}
		var orig = String(original || '').trim().toLowerCase();
		var isRgb = orig.indexOf('rgb') === 0;
		if (a >= 1) {
			if (isRgb) return 'rgb(' + r + ', ' + g + ', ' + b + ')';
			return '#' + to2(r) + to2(g) + to2(b);
		}
		var av = Math.round(a * 1000) / 1000;
		if (orig.charAt(0) === '#') return '#' + to2(r) + to2(g) + to2(b) + to2(a * 255);
		return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + av + ')';
	}

	var NAMED = ('transparent currentcolor aliceblue antiquewhite aqua aquamarine azure beige bisque black ' +
		'blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue ' +
		'cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta ' +
		'darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray ' +
		'darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick ' +
		'floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey ' +
		'honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue ' +
		'lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon ' +
		'lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen ' +
		'linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen ' +
		'mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose ' +
		'moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen ' +
		'paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red ' +
		'rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue ' +
		'slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white ' +
		'whitesmoke yellow yellowgreen').trim().split(/\s+/);

	var COLOR_RE = new RegExp(
		'(?:rgba?|hsla?)\\([^)]*\\)' +
		'|#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})' +
		'|\\b(?:' + NAMED.join('|') + ')\\b',
		'gi'
	);

	function detect(text) {
		var out = [], m;
		COLOR_RE.lastIndex = 0;
		while ((m = COLOR_RE.exec(text)) !== null) {
			out.push({ start: m.index, end: m.index + m[0].length, value: m[0] });
		}
		return out;
	}

	var active = null;

	function closeActive() {
		if (!active) return;
		var a = active;
		active = null;
		document.removeEventListener('mousedown', onDocDown, true);
		document.removeEventListener('keydown', onDocKey, true);
		if (a.el && a.el.parentNode) a.el.parentNode.removeChild(a.el);
		if (a.onClose) a.onClose();
	}
	function onDocDown(e) {
		if (active && active.el && !active.el.contains(e.target) && e.target !== active.anchor) closeActive();
	}
	function onDocKey(e) { if (e.key === 'Escape') { e.preventDefault(); closeActive(); } }

	function emit() {
		if (!active) return;
		var rgb = hsvToRgb(active.h, active.s, active.v);
		var str = format({ r: rgb.r, g: rgb.g, b: rgb.b, a: active.a }, active.original, active.mode);
		active.current = str;
		if (active.onChange) active.onChange(str);
	}

	function updateUI() {
		if (!active) return;
		var rgb = hsvToRgb(active.h, active.s, active.v);
		var hueColor = 'hsl(' + active.h + ',100%,50%)';
		active.sv.style.background =
			'linear-gradient(to top, #000, rgba(0,0,0,0)), ' +
			'linear-gradient(to right, #fff, rgba(255,255,255,0)), ' + hueColor;
		active.svThumb.style.left = (active.s * 100) + '%';
		active.svThumb.style.top = ((1 - active.v) * 100) + '%';
		active.hueThumb.style.left = (active.h / 360 * 100) + '%';
		active.alpha.style.background =
			'linear-gradient(to right, rgba(' + clamp255(rgb.r) + ',' + clamp255(rgb.g) + ',' + clamp255(rgb.b) + ',0), ' +
			'rgb(' + clamp255(rgb.r) + ',' + clamp255(rgb.g) + ',' + clamp255(rgb.b) + ')), ' +
			'repeating-conic-gradient(#ccc 0% 25%, #fff 0% 50%) 50% / 10px 10px';
		active.alphaThumb.style.left = (active.a * 100) + '%';
		var preview = 'rgba(' + clamp255(rgb.r) + ',' + clamp255(rgb.g) + ',' + clamp255(rgb.b) + ',' + active.a + ')';
		active.swatch.style.background = preview;
		if (document.activeElement !== active.text) active.text.value = active.current || '';
	}

	function dragValue(el, ev, fn) {
		function move(e) {
			var r = el.getBoundingClientRect();
			var x = clamp01((e.clientX - r.left) / r.width);
			var y = clamp01((e.clientY - r.top) / r.height);
			fn(x, y);
			updateUI();
			emit();
		}
		move(ev);
		function up() {
			document.removeEventListener('mousemove', move, true);
			document.removeEventListener('mouseup', up, true);
		}
		document.addEventListener('mousemove', move, true);
		document.addEventListener('mouseup', up, true);
	}

	function openPicker(opts) {
		closeActive();
		var parsed = parse(opts.value) || { r: 0, g: 0, b: 0, a: 1 };
		var hsv = rgbToHsv(parsed.r, parsed.g, parsed.b);

		var pop = document.createElement('div');
		pop.className = 'es-color-popover';
		pop.innerHTML =
			'<div class="es-color-sv"><span class="es-color-sv-thumb"></span></div>' +
			'<div class="es-color-sliders">' +
				'<span class="es-color-swatch"></span>' +
				'<div class="es-color-tracks">' +
					'<div class="es-color-hue"><span class="es-color-hue-thumb"></span></div>' +
					'<div class="es-color-alpha"><span class="es-color-alpha-thumb"></span></div>' +
				'</div>' +
			'</div>' +
			'<div class="es-color-row">' +
				'<input type="text" class="es-color-text" spellcheck="false">' +
				'<div class="es-color-modes">' +
					'<button type="button" class="es-color-mode" data-mode="hex">HEX</button>' +
					'<button type="button" class="es-color-mode" data-mode="rgba">RGBA</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild(pop);

		active = {
			el: pop,
			anchor: opts.anchor || null,
			onChange: opts.onChange,
			onClose: opts.onClose,
			original: opts.value,
			current: opts.value,
			mode: /^\s*(rgb|hsl)/i.test(String(opts.value || '')) ? 'rgba' : 'hex',
			h: hsv.h, s: hsv.s, v: hsv.v, a: parsed.a,
			sv: pop.querySelector('.es-color-sv'),
			svThumb: pop.querySelector('.es-color-sv-thumb'),
			hue: pop.querySelector('.es-color-hue'),
			hueThumb: pop.querySelector('.es-color-hue-thumb'),
			alpha: pop.querySelector('.es-color-alpha'),
			alphaThumb: pop.querySelector('.es-color-alpha-thumb'),
			swatch: pop.querySelector('.es-color-swatch'),
			text: pop.querySelector('.es-color-text')
		};

		var ar = (opts.anchor && opts.anchor.getBoundingClientRect) ? opts.anchor.getBoundingClientRect() : { left: 100, bottom: 100 };
		var top = ar.bottom + 6, left = ar.left;
		pop.style.visibility = 'hidden';
		pop.style.left = '0px'; pop.style.top = '0px';
		var pr = pop.getBoundingClientRect();
		if (left + pr.width > window.innerWidth - 8) left = window.innerWidth - pr.width - 8;
		if (top + pr.height > window.innerHeight - 8) top = ar.top - pr.height - 6;
		pop.style.left = Math.max(8, left) + 'px';
		pop.style.top = Math.max(8, top) + 'px';
		pop.style.visibility = '';

		active.sv.addEventListener('mousedown', function (e) {
			e.preventDefault();
			dragValue(active.sv, e, function (x, y) { active.s = x; active.v = 1 - y; });
		});
		active.hue.addEventListener('mousedown', function (e) {
			e.preventDefault();
			dragValue(active.hue, e, function (x) { active.h = x * 360; });
		});
		active.alpha.addEventListener('mousedown', function (e) {
			e.preventDefault();
			dragValue(active.alpha, e, function (x) { active.a = x; });
		});
		active.text.addEventListener('input', function () {
			var p = parse(active.text.value);
			if (!p) return;
			var hv = rgbToHsv(p.r, p.g, p.b);
			active.h = hv.h; active.s = hv.s; active.v = hv.v; active.a = p.a;
			active.original = active.text.value;
			active.mode = /^\s*(rgb|hsl)/i.test(active.text.value) ? 'rgba' : 'hex';
			reflectMode();
			updateUI();
			if (active.onChange) active.onChange(active.text.value);
		});

		var modeBtns = pop.querySelectorAll('.es-color-mode');
		function reflectMode() {
			for (var mi = 0; mi < modeBtns.length; mi++) {
				modeBtns[mi].classList.toggle('is-active', modeBtns[mi].getAttribute('data-mode') === active.mode);
			}
		}
		for (var mb = 0; mb < modeBtns.length; mb++) {
			modeBtns[mb].addEventListener('click', function (e) {
				active.mode = e.currentTarget.getAttribute('data-mode');
				reflectMode();
				emit();
				updateUI();
			});
		}
		reflectMode();

		updateUI();
		document.addEventListener('mousedown', onDocDown, true);
		document.addEventListener('keydown', onDocKey, true);
	}

	window.ESInspectorColor = { parse: parse, format: format, detect: detect, openPicker: openPicker, close: closeActive };
})();
