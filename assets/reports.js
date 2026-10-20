(function () {
	'use strict';

	function $(s, root) { return (root || document).querySelector(s); }
	function $$(s, root) { return Array.from((root || document).querySelectorAll(s)); }

	const dataEl = $('#es-reports-data');
	if (!dataEl) return;

	let series;
	try { series = JSON.parse(dataEl.textContent); } catch (e) { return; }
	if (!Array.isArray(series) || series.length === 0) return;

	$$('.es-chart[data-es-chart]').forEach(host => {
		let metrics;
		try { metrics = JSON.parse(host.getAttribute('data-es-chart')); }
		catch (e) { metrics = [{ key: 'conversions', label: 'Conversions', color: '#2271b1', area: 'rgba(34,113,177,0.10)' }]; }
		render(host, series, metrics);
	});

	function render(host, points, metrics) {
		const W = host.clientWidth || 800;
		const H = 240;
		const padL = 36, padR = 12, padT = 18, padB = 38;

		let maxY = 1;
		metrics.forEach(m => {
			points.forEach(p => { maxY = Math.max(maxY, Number(p[m.key] || 0)); });
		});
		const niceMax = niceCeil(maxY + 1);

		const xStep = (W - padL - padR) / Math.max(1, points.length - 1);
		const yScale = (H - padT - padB) / niceMax;
		const xs = points.map((_, i) => padL + i * xStep);

		let ticks;
		if (niceMax <= 6) {
			ticks = [];
			for (let i = 0; i <= niceMax; i++) ticks.push(i);
		} else {
			ticks = [0, Math.round(niceMax / 2), niceMax];
		}
		const gridLines = ticks.map(t => {
			const y = H - padB - t * yScale;
			return `<line x1="${padL}" y1="${y.toFixed(1)}" x2="${W - padR}" y2="${y.toFixed(1)}" stroke="#e5e5e5" stroke-width="1" />
			        <text x="${padL - 6}" y="${(y + 4).toFixed(1)}" text-anchor="end" font-size="10" fill="#777">${t}</text>`;
		}).join('');

		const lastIdx = points.length - 1;
		const xLabelIdx = [0, Math.floor(points.length / 2), lastIdx];
		const xLabels = xLabelIdx.map(i => {
			const p = points[i];
			if (!p) return '';
			const anchor = i === 0 ? 'start' : (i === lastIdx ? 'end' : 'middle');
			return `<text x="${xs[i].toFixed(1)}" y="${H - 16}" text-anchor="${anchor}" font-size="10" fill="#777">${formatDate(p.date)}</text>`;
		}).join('');

		const seriesSvg = metrics.map(m => {
			const ys = points.map(p => H - padB - Number(p[m.key] || 0) * yScale);
			let d = ''; points.forEach((_, i) => { d += (i === 0 ? 'M' : 'L') + xs[i].toFixed(1) + ',' + ys[i].toFixed(1) + ' '; });
			let area = 'M' + xs[0].toFixed(1) + ',' + (H - padB);
			points.forEach((_, i) => { area += 'L' + xs[i].toFixed(1) + ',' + ys[i].toFixed(1); });
			area += 'L' + xs[xs.length - 1].toFixed(1) + ',' + (H - padB) + 'Z';
			const dots = points.map((p, i) => {
				const tip = `${formatDate(p.date)} — ${m.label}: ${p[m.key] || 0}`;
				return `<circle cx="${xs[i].toFixed(1)}" cy="${ys[i].toFixed(1)}" r="3" fill="${m.color}" />`
					+ `<circle class="es-chart-dot" cx="${xs[i].toFixed(1)}" cy="${ys[i].toFixed(1)}" r="8" fill="transparent" data-tip="${escapeAttr(tip)}" />`;
			}).join('');
			return `
				<path d="${area}" fill="${m.area}" />
				<path d="${d}" fill="none" stroke="${m.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
				${dots}
			`;
		}).join('');

		const legend = metrics.length > 1
			? `<g>${metrics.map((m, i) => `
				<g transform="translate(${padL + i * 110}, 4)">
					<rect x="0" y="0" width="10" height="10" fill="${m.color}" />
					<text x="14" y="9" font-size="10" fill="#444">${m.label}</text>
				</g>`).join('')}</g>`
			: '';

		host.innerHTML = `
			<svg viewBox="0 0 ${W} ${H}" width="100%" height="${H}" preserveAspectRatio="none" role="img">
				${legend}
				${gridLines}
				${seriesSvg}
				${xLabels}
			</svg>
		`;

		attachTooltip(host);
	}

	function attachTooltip(host) {
		let tip = host.querySelector('.es-chart-tip');
		if (!tip) {
			tip = document.createElement('div');
			tip.className = 'es-chart-tip';
			tip.setAttribute('aria-hidden', 'true');
			host.appendChild(tip);
		}

		const show = (e) => {
			const node = e.target.closest ? e.target.closest('[data-tip]') : null;
			if (!node) return;
			tip.textContent = node.getAttribute('data-tip');
			const rect = host.getBoundingClientRect();
			let x = e.clientX - rect.left + 12;
			let y = e.clientY - rect.top - 12;
			x = Math.min(x, host.clientWidth - tip.offsetWidth - 4);
			x = Math.max(4, x);
			y = Math.max(4, y);
			tip.style.left = x + 'px';
			tip.style.top = y + 'px';
			tip.classList.add('is-visible');
		};
		const hide = (e) => {
			const to = e && e.relatedTarget;
			if (to && to.closest && to.closest('[data-tip]')) return;
			tip.classList.remove('is-visible');
		};

		host.addEventListener('mouseover', show);
		host.addEventListener('mousemove', show);
		host.addEventListener('mouseout', hide);
	}

	function escapeAttr(s) {
		return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	function niceCeil(n) {
		if (n <= 1) return 1;
		const p = Math.pow(10, Math.floor(Math.log10(n)));
		const r = n / p;
		const m = r <= 1 ? 1 : r <= 2 ? 2 : r <= 5 ? 5 : 10;
		return m * p;
	}

	function formatNum(n) { return Number.isInteger(n) ? String(n) : n.toFixed(1); }

	function formatDate(iso) {
		if (iso.length > 10) {
			const d = new Date(iso);
			return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false });
		}
		const d = new Date(iso + 'T00:00:00');
		return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
	}
})();

(function () {
	'use strict';

	var inputs = document.querySelectorAll('.es-dp-input');
	if (!inputs.length) return;

	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DOW    = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

	function pad(n) { return n < 10 ? '0' + n : '' + n; }
	function toISO(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function parseISO(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
		return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
	}
	function fmtDisplay(d) { return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + '/' + d.getFullYear(); }
	function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
	function sameDay(a, b) {
		return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}
	function monthStart(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }

	Array.prototype.forEach.call(inputs, setup);

	var quickRange = document.getElementById('es-quick-range');
	if (quickRange) {
		quickRange.addEventListener('change', function () {
			if (this.value) { window.location.href = this.value; }
		});
	}

	function setup(input) {
		var minDate = parseISO(input.getAttribute('min'));
		var tipText = input.getAttribute('data-tip') || '';
		var today   = startOfDay(new Date());

		var wrap = document.createElement('div');
		wrap.className = 'es-dp';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);
		input.classList.add('es-dp-native');
		input.setAttribute('tabindex', '-1');
		input.setAttribute('aria-hidden', 'true');

		var trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'es-dp-trigger';
		trigger.setAttribute('aria-haspopup', 'dialog');
		trigger.setAttribute('aria-expanded', 'false');
		trigger.innerHTML = '<span class="es-dp-value"></span>'
			+ '<svg class="es-dp-cal" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">'
			+ '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="8" y1="2.5" x2="8" y2="6"/><line x1="16" y1="2.5" x2="16" y2="6"/></svg>';
		wrap.appendChild(trigger);

		var pop = document.createElement('div');
		pop.className = 'es-dp-pop';
		pop.setAttribute('role', 'dialog');
		pop.hidden = true;
		wrap.appendChild(pop);

		var tip = document.createElement('div');
		tip.className = 'es-dp-tip';
		tip.setAttribute('aria-hidden', 'true');

		var valueEl = trigger.querySelector('.es-dp-value');
		var selected = parseISO(input.value);
		var view = monthStart(selected || today);

		function syncTrigger() {
			valueEl.textContent = selected ? fmtDisplay(selected) : 'Select date';
			trigger.classList.toggle('is-empty', !selected);
		}
		syncTrigger();

		function isDisabled(d) { return minDate && d < minDate; }

		function render() {
			var y = view.getFullYear(), mo = view.getMonth();
			var startDow = new Date(y, mo, 1).getDay();
			var days = new Date(y, mo + 1, 0).getDate();

			var cells = '';
			for (var i = 0; i < startDow; i++) cells += '<span class="es-dp-pad"></span>';
			for (var day = 1; day <= days; day++) {
				var d = new Date(y, mo, day);
				var cls = 'es-dp-day';
				if (isDisabled(d))         cls += ' is-disabled';
				if (sameDay(d, selected))  cls += ' is-selected';
				if (sameDay(d, today))     cls += ' is-today';
				cells += '<button type="button" class="' + cls + '" data-iso="' + toISO(d) + '"'
					+ (isDisabled(d) ? ' data-disabled="1" aria-disabled="true"' : '') + '>' + day + '</button>';
			}

			pop.innerHTML =
				'<div class="es-dp-head">'
				+   '<button type="button" class="es-dp-nav es-dp-prev" aria-label="Previous month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>'
				+   '<span class="es-dp-title">' + MONTHS[mo] + ' ' + y + '</span>'
				+   '<button type="button" class="es-dp-nav es-dp-next" aria-label="Next month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>'
				+ '</div>'
				+ '<div class="es-dp-dows">' + DOW.map(function (n) { return '<span>' + n + '</span>'; }).join('') + '</div>'
				+ '<div class="es-dp-grid">' + cells + '</div>'
				+ '<div class="es-dp-foot"><button type="button" class="es-dp-today">Today</button></div>';

			pop.appendChild(tip);
		}

		function open() {
			view = monthStart(selected || today);
			render();
			pop.hidden = false;
			trigger.setAttribute('aria-expanded', 'true');
			document.addEventListener('mousedown', onDocDown, true);
			document.addEventListener('keydown', onKey, true);
		}
		function close() {
			pop.hidden = true;
			tip.classList.remove('is-visible');
			trigger.setAttribute('aria-expanded', 'false');
			document.removeEventListener('mousedown', onDocDown, true);
			document.removeEventListener('keydown', onKey, true);
		}
		function onDocDown(e) { if (!wrap.contains(e.target)) close(); }
		function onKey(e) { if (e.key === 'Escape') { close(); trigger.focus(); } }

		trigger.addEventListener('click', function () { pop.hidden ? open() : close(); });

		pop.addEventListener('click', function (e) {
			var nav = e.target.closest('.es-dp-nav');
			if (nav) {
				view = new Date(view.getFullYear(), view.getMonth() + (nav.classList.contains('es-dp-next') ? 1 : -1), 1);
				render();
				return;
			}
			if (e.target.closest('.es-dp-today')) {
				view = monthStart(today);
				render();
				return;
			}
			var cell = e.target.closest('.es-dp-day');
			if (cell && !cell.getAttribute('data-disabled')) {
				selected = parseISO(cell.getAttribute('data-iso'));
				input.value = cell.getAttribute('data-iso');
				input.dispatchEvent(new Event('change', { bubbles: true }));
				syncTrigger();
				close();
			}
		});

		pop.addEventListener('mousemove', function (e) {
			var cell = e.target.closest ? e.target.closest('.es-dp-day[data-disabled]') : null;
			if (!cell || !tipText) { tip.classList.remove('is-visible'); return; }
			tip.textContent = tipText;
			var r = pop.getBoundingClientRect();
			var x = e.clientX - r.left + 14;
			var y = e.clientY - r.top + 16;
			x = Math.max(4, Math.min(x, pop.clientWidth - tip.offsetWidth - 4));
			tip.style.left = x + 'px';
			tip.style.top = y + 'px';
			tip.classList.add('is-visible');
		});
		pop.addEventListener('mouseleave', function () { tip.classList.remove('is-visible'); });
	}
})();
