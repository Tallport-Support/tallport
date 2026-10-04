//#region src/js/toast.js
var e = [
	"neutral",
	"success",
	"danger"
];
function t(t, { tone: n = "neutral" } = {}) {
	if (!e.includes(n)) throw Error(`FruitUI toast tone must be one of: ${e.join(", ")}.`);
	window.dispatchEvent(new CustomEvent("fruit-toast", { detail: {
		message: t,
		tone: n
	} }));
}
function n({ duration: t = 4e3, message: n = null, tone: r = "neutral" } = {}) {
	if (!Number.isFinite(t) || t < 0) throw Error("FruitUI Toast duration must be a nonnegative number of milliseconds.");
	let i, a, o = t, s = new AbortController(), c = () => {
		clearTimeout(i), i = void 0;
	}, l = (e) => e === "danger" ? t * 2 : t;
	return {
		notice: "",
		init() {
			let e = { signal: s.signal };
			this.$el.classList.contains("f-toast") && typeof this.$el.showPopover == "function" && (this.$el.popover = "manual"), this.$el.setAttribute("aria-live", "polite"), window.addEventListener("fruit-toast", (e) => this.notify(e.detail?.message ?? "", e.detail?.tone), e);
			for (let t of ["mouseenter", "focusin"]) this.$el.addEventListener(t, () => this.pauseNotice(), e);
			for (let t of ["mouseleave", "focusout"]) this.$el.addEventListener(t, () => this.resumeNotice(), e);
			n && this.notify(n, r);
		},
		notify(t, n = "neutral") {
			c(), e.includes(n) || (n = "neutral"), this.$el.setAttribute("aria-live", n === "danger" ? "assertive" : "polite"), n === "neutral" ? delete this.$el.dataset.tone : this.$el.dataset.tone = n, this.notice = String(t), o = l(n), this.$el.popover && (this.$el.matches(":popover-open") && this.$el.hidePopover(), this.$el.showPopover()), this.resumeNotice();
		},
		dismissNotice() {
			c(), this.notice = "", o = 0, this.$el.popover && this.$el.matches(":popover-open") && this.$el.hidePopover();
		},
		pauseNotice() {
			i !== void 0 && (o = Math.max(0, o - (performance.now() - a)), c());
		},
		resumeNotice() {
			this.notice && t && i === void 0 && (a = performance.now(), i = setTimeout(() => this.dismissNotice(), o));
		},
		destroy() {
			c(), s.abort();
		}
	};
}
//#endregion
//#region src/js/confirm.js
var r = ["default", "danger"];
function i({ title: e, message: t = "", confirm: n, cancel: i, tone: a = "default" } = {}) {
	if (typeof e != "string" || !e.trim()) throw Error("FruitUI confirm needs a title.");
	if (!r.includes(a)) throw Error(`FruitUI confirm tone must be one of: ${r.join(", ")}.`);
	return new Promise((r) => {
		let o = {
			title: e,
			message: t,
			confirm: n,
			cancel: i,
			tone: a,
			resolve: r,
			handled: !1
		};
		window.dispatchEvent(new CustomEvent("fruit-confirm", { detail: o })), o.handled || r(window.confirm(t ? `${e}\n\n${t}` : e));
	});
}
function a() {
	let e = [], t = new AbortController(), n = null;
	return {
		request: {
			title: "",
			message: "",
			confirm: "",
			cancel: "",
			tone: "default"
		},
		init() {
			let r = this.$el, i = { signal: t.signal };
			window.addEventListener("fruit-confirm", (t) => {
				t.detail.handled = !0, e.push(t.detail), n || this.next();
			}, i), r.addEventListener("close", () => {
				let e = n;
				n = null, e?.resolve(r.returnValue === "confirm"), this.next();
			}, i);
		},
		next() {
			if (n = e.shift() ?? null, !n) return;
			let t = this.$el, r = t.dataset;
			this.request = {
				title: n.title,
				message: n.message,
				confirm: n.confirm || r.fruitConfirmLabel || "OK",
				cancel: n.cancel || r.fruitCancelLabel || "Cancel",
				tone: n.tone
			}, t.returnValue = "", this.$nextTick(() => {
				t.showModal(), t.querySelector(`button[value="${n.tone === "danger" ? "cancel" : "confirm"}"]`)?.focus();
			});
		},
		destroy() {
			t.abort();
			for (let t of [n, ...e]) t?.resolve(!1);
		}
	};
}
//#endregion
//#region src/js/dialog.js
function o(e = window) {
	let t = (e) => [...document.querySelectorAll("dialog[data-fruit-dialog]")].find((t) => t.dataset.fruitDialog === e), n = (e) => {
		let n = t(e.detail?.name);
		n && !n.open && n.showModal();
	}, r = (e) => {
		let n = t(e.detail?.name);
		n?.open && n.close(e.detail?.returnValue);
	};
	return e.addEventListener("fruit-dialog-open", n), e.addEventListener("fruit-dialog-close", r), () => {
		e.removeEventListener("fruit-dialog-open", n), e.removeEventListener("fruit-dialog-close", r);
	};
}
function s() {
	return {
		open: !1,
		init() {
			let e = this.$el, t = () => {
				this.open && !e.open ? e.showModal() : !this.open && e.open && e.close();
			};
			this.$watch("open", t), e.addEventListener("close", () => {
				this.open = !1;
			}), this.$nextTick(t);
		}
	};
}
//#endregion
//#region src/js/popup.js
var c = (e) => getComputedStyle(e).direction === "rtl";
function l(e, t, { stretch: n = !1, above: r = !1, point: i = null, start: a = !1 } = {}) {
	let o = typeof e.showPopover == "function", s = e.getAttribute("style"), l = !1;
	o && (e.popover = "manual");
	let u = () => {
		if (!l || !o) return;
		let s = t.getBoundingClientRect(), u = window.visualViewport, d = u?.offsetLeft || 0, f = u?.offsetTop || 0, p = u?.width || window.innerWidth, m = u?.height || window.innerHeight;
		e.style.position = "fixed", e.style.inset = "auto", e.style.margin = "0", e.style.transform = "none", e.style.maxWidth = `${Math.max(0, p - 16)}px`, e.style.maxHeight = `${Math.max(40, m - 16)}px`, e.style.overflowY = "auto", n && (e.style.width = `${Math.min(s.width, p - 16)}px`);
		let h = e.getBoundingClientRect(), g = i ? c(i) ? s.left - h.width : s.left : n || c(t) !== a ? s.left : s.right - h.width, _ = f + m - s.bottom - 8, v = s.top - f - 8, y = r && v >= h.height || _ < h.height && v > _, b = y ? v : _;
		e.style.maxHeight = `${Math.max(40, b)}px`;
		let x = e.getBoundingClientRect().height;
		e.style.left = `${Math.max(d + 8, Math.min(g, d + p - h.width - 8))}px`, e.style.top = `${Math.max(f + 8, Math.min(y ? s.top - x - 4 : s.bottom + 4, f + m - x - 8))}px`;
	}, d = () => {
		l = !0, o && (e.popover = "manual"), o && !e.matches(":popover-open") && e.showPopover(), u();
	}, f = () => {
		l = !1, o && e.matches(":popover-open") && e.hidePopover();
	};
	return window.addEventListener("resize", u), document.addEventListener("scroll", u, !0), window.visualViewport?.addEventListener("resize", u), window.visualViewport?.addEventListener("scroll", u), {
		show: d,
		hide: f,
		destroy() {
			f(), window.removeEventListener("resize", u), document.removeEventListener("scroll", u, !0), window.visualViewport?.removeEventListener("resize", u), window.visualViewport?.removeEventListener("scroll", u), o && e.removeAttribute("popover"), s === null ? e.removeAttribute("style") : e.setAttribute("style", s);
		}
	};
}
function u(e, t, { above: n = !1, owns: r = (t) => t?.closest("details") === e, onToggle: i } = {}) {
	let a = e.querySelector("summary"), o = l(t, a, { above: n }), s = new AbortController(), c = { signal: s.signal }, u = (t) => {
		e.open = !1, o.hide(), t && a.focus();
	};
	return e.addEventListener("toggle", (t) => {
		t.target === e && (e.open ? o.show() : o.hide(), i?.(e.open));
	}, c), document.addEventListener("pointerdown", (t) => {
		e.open && !e.contains(t.target) && u(!1);
	}, c), e.addEventListener("keydown", (t) => {
		t.key === "Escape" && e.open && r(t.target) && (t.preventDefault(), t.stopPropagation(), u(!0));
	}, c), e.open && o.show(), {
		trigger: a,
		show: () => o.show(),
		close: u,
		destroy() {
			s.abort(), o.destroy();
		}
	};
}
//#endregion
//#region src/js/messages.js
function d(e, t, n, r = {}) {
	return (e.getAttribute(`data-fruit-${t}`) ?? n).replace(/\{(\w+)\}/g, (e, t) => String(r[t] ?? e));
}
//#endregion
//#region src/js/splitter.js
function f({ pane: e, variable: t, min: n = 160, max: r = 420, reserve: i = 280, flexible: a, edge: o = "end" }) {
	if (!e || !a || e === a || !/^--f-[\w-]+$/.test(t) || !["start", "end"].includes(o) || ![
		n,
		r,
		i
	].every(Number.isFinite) || n <= 0 || r < n || i <= 0) throw Error("FruitUI splitter requires pane/flexible IDs, a --f- variable, positive bounds and start/end edge.");
	let s, l, u, f, p, m, h, g, _, v, y = (e) => !!e?.getClientRects().length && getComputedStyle(e).display !== "none";
	return {
		init() {
			if (s = this.$el, l = s.closest(".f-workspace"), u = l?.querySelector(`[id="${CSS.escape(e)}"]`), f = l?.querySelector(`[id="${CSS.escape(a)}"]`), !u || !f) throw Error("FruitUI splitter panes must belong to its workspace.");
			_ = y(u) ? u.getBoundingClientRect().width : void 0, s.setAttribute("data-ready", ""), s.setAttribute("data-edge", o), s.setAttribute("aria-controls", e), v = Object.fromEntries(Object.entries({
				pointerdown: this.start,
				pointermove: this.move,
				pointerup: this.end,
				pointercancel: this.cancel,
				lostpointercapture: this.end,
				keydown: this.key,
				dblclick: this.reset
			}).map(([e, t]) => [e, t.bind(this)]));
			for (let [e, t] of Object.entries(v)) s.addEventListener(e, t);
			p = new ResizeObserver(() => {
				this.describe(), this.schedule();
			}), p.observe(l), p.observe(u), p.observe(f), m = new MutationObserver(() => this.schedule()), m.observe(l, { attributes: !0 }), this.describe(), this.schedule();
		},
		bounds() {
			let e = u.getBoundingClientRect().width, t = e + f.getBoundingClientRect().width - i;
			return {
				width: e,
				upper: Math.max(n, Math.floor(Math.min(r, t)))
			};
		},
		schedule() {
			cancelAnimationFrame(h), h = requestAnimationFrame(() => this.update());
		},
		update() {
			if (!y(s) || !y(u) || !y(f)) {
				g && this.cancel();
				return;
			}
			let { width: e, upper: t } = this.bounds();
			_ ??= e, (e > t + 1 || e < n - 1) && this.set(e), this.describe();
		},
		describe() {
			if (!y(u) || !y(f)) return;
			let { width: e, upper: t } = this.bounds();
			s.setAttribute("aria-valuemin", Math.round(n)), s.setAttribute("aria-valuemax", Math.floor(t)), s.setAttribute("aria-valuenow", Math.round(e)), s.setAttribute("aria-valuetext", d(s, "value-text", "{count} pixels", { count: Math.round(e) }));
		},
		set(e) {
			let { upper: r } = this.bounds(), i = `${Math.round(Math.max(n, Math.min(r, e)))}px`;
			l.style.getPropertyValue(t) !== i && (l.style.setProperty(t, i), this.schedule());
		},
		start(e) {
			e.button === 0 && y(u) && y(f) && (e.preventDefault(), s.focus({ preventScroll: !0 }), g = {
				id: e.pointerId,
				x: e.clientX,
				width: u.getBoundingClientRect().width,
				previous: l.style.getPropertyValue(t)
			}, s.setPointerCapture(e.pointerId), s.setAttribute("data-resizing", ""), l.setAttribute("data-resizing", ""));
		},
		move(e) {
			if (!g || e.pointerId !== g.id) return;
			let t = (c(l) ? -1 : 1) * (o === "start" ? -1 : 1);
			this.set(g.width + (e.clientX - g.x) * t);
		},
		end() {
			if (!g) return;
			let e = g.id;
			g = void 0, s.removeAttribute("data-resizing"), l.removeAttribute("data-resizing"), s.hasPointerCapture(e) && s.releasePointerCapture(e);
		},
		cancel() {
			g && (g.previous ? l.style.setProperty(t, g.previous) : l.style.removeProperty(t), this.end(), this.schedule());
		},
		key(e) {
			if (e.key === "Escape" && g) {
				e.preventDefault(), e.stopPropagation(), this.cancel();
				return;
			}
			let { width: t, upper: r } = this.bounds(), i = (c(l) ? -1 : 1) * (o === "start" ? -1 : 1), a = e.shiftKey ? 32 : 8, s = {
				ArrowLeft: t - a * i,
				ArrowRight: t + a * i,
				Home: n,
				End: r
			};
			e.key in s && (e.preventDefault(), e.stopPropagation(), this.set(s[e.key]));
		},
		reset() {
			this.set(_);
		},
		destroy() {
			this.end(), p?.disconnect(), m?.disconnect(), cancelAnimationFrame(h);
			for (let [e, t] of Object.entries(v)) s.removeEventListener(e, t);
		}
	};
}
//#endregion
//#region src/js/control-bridge.js
var p = 0, m = (e) => `${e}-${++p}`;
function h(e, t, { commit: n = !0 } = {}) {
	e.value !== t && (e.value = t, e.dispatchEvent(new Event("input", { bubbles: !0 })), n && e.dispatchEvent(new Event("change", { bubbles: !0 })));
}
function g(e, t, n, r, { presentation: i = n, focusRoot: a = n } = {}) {
	let o = [], s = (e, t, n) => {
		e?.addEventListener(t, n), o.push(() => e?.removeEventListener(t, n));
	}, c = () => [...t.labels || []], l = i.className, u = i.getAttribute("style"), d = n.placeholder || "", f = t.ownerDocument.activeElement === t, p = t.value;
	s(t.ownerDocument, "click", (e) => {
		c().some((t) => t.contains(e.target)) && (e.target === t || !e.target.closest("button, a, input, select, textarea")) && (e.preventDefault(), n.focus());
	});
	let h = (e = "attributes") => {
		t.hidden ||= !0;
		let a = c();
		for (let e of a) e.id ||= m("fruit-label");
		for (let e of [
			"aria-label",
			"aria-labelledby",
			"aria-describedby",
			"aria-invalid",
			"aria-errormessage",
			"dir",
			"lang",
			"title",
			"spellcheck",
			"inputmode",
			"autocapitalize"
		]) t.hasAttribute(e) ? n.setAttribute(e, t.getAttribute(e)) : n.removeAttribute(e);
		!n.hasAttribute("aria-label") && !n.hasAttribute("aria-labelledby") && a.length && n.setAttribute("aria-labelledby", a.map((e) => e.id).join(" ")), n.setAttribute("aria-required", String(t.required)), n.tabIndex = t.matches(":disabled") ? -1 : t.tabIndex, "disabled" in n && (n.disabled = t.matches(":disabled")), "readOnly" in n && (n.readOnly = t.readOnly || !1), i.className = [...new Set(`${l} ${t.className.split(/\s+/).filter((e) => e !== "f-input" || i === n).join(" ")}`.split(/\s+/).filter(Boolean))].join(" ");
		let o = t.getAttribute("style") || u;
		o === null ? i.removeAttribute("style") : i.setAttribute("style", o), "placeholder" in n && (n.placeholder = t.getAttribute("placeholder") ?? d), r(e), p = t.value;
	}, g = () => {
		t.value !== p && (n.setCustomValidity?.(""), h("value"));
	};
	s(t, "input", g), s(t, "change", g), s(t, "invalid", (e) => {
		e.preventDefault(), n.focus(), n.setAttribute("aria-invalid", "true");
	}), s(a, "focusin", (e) => {
		a.contains(e.relatedTarget) || t.dispatchEvent(new FocusEvent("focus", { relatedTarget: e.relatedTarget }));
	}), s(a, "focusout", (e) => {
		a.contains(e.relatedTarget) || t.dispatchEvent(new FocusEvent("blur", { relatedTarget: e.relatedTarget }));
	});
	let _;
	s(t.ownerDocument, "reset", (e) => {
		e.target === t.form && (clearTimeout(_), _ = setTimeout(() => {
			e.defaultPrevented || (n.setCustomValidity?.(""), h("reset"));
		}, 0));
	});
	let v = new MutationObserver((e) => {
		let n = e.some((e) => e.type === "childList" || e.type === "characterData" || e.target !== t && t.contains(e.target));
		h(t.value === p ? n ? "options" : "attributes" : "value");
	});
	v.observe(t, {
		attributes: !0,
		childList: !0,
		characterData: !0,
		subtree: !0
	});
	for (let e = t.parentElement; e; e = e.parentElement) e.tagName === "FIELDSET" && v.observe(e, {
		attributes: !0,
		attributeFilter: ["disabled"]
	});
	return e.$nextTick(() => {
		t._x_model && o.push(e.$watch(() => t._x_model.get(), () => e.$nextTick(g))), h("initial"), (t.autofocus || f) && [t.ownerDocument.body, t].includes(t.ownerDocument.activeElement) && n.focus();
	}), h("initial"), () => {
		v.disconnect(), clearTimeout(_), o.forEach((e) => e?.());
	};
}
//#endregion
//#region src/js/autocomplete.js
function _() {
	let e, t, n, r, i, a, o, s = -1, c = [], u = null, f = () => e.dataset.fruitTrigger || "", p = () => [...e.querySelector("datalist")?.options ?? []].filter((e) => !e.disabled), g = () => {
		let e = t.selectionStart ?? t.value.length, n = t.value.slice(0, e), r = n.search(/[^\s,]*$/), i = n.slice(r), a = f();
		return a ? i.startsWith(a) ? {
			start: r,
			end: e,
			query: i.slice(a.length)
		} : null : i ? {
			start: r,
			end: e,
			query: i
		} : null;
	}, _ = () => {
		s = -1, c = [], n.hidden = !0, i.hide(), t.removeAttribute("aria-activedescendant");
	}, v = (e) => {
		s = e, [...n.children].forEach((e, t) => e.setAttribute("aria-selected", String(t === s)));
		let r = n.children[s];
		r ? (t.setAttribute("aria-activedescendant", r.id), r.scrollIntoView({ block: "nearest" })) : t.removeAttribute("aria-activedescendant");
	}, y = () => {
		if (u = g(), !u || t.disabled || t.readOnly) return _();
		let a = u.query.toLocaleLowerCase(), o = (e) => e.label || e.value, s = (e) => `${o(e)} ${e.value}`.toLocaleLowerCase().split(/[\s@:/#._-]+/);
		if (c = p().filter((e) => e.value.toLocaleLowerCase().startsWith(f() + a) || s(e).some((e) => e.startsWith(a))).sort((e, t) => Number(!o(e).toLocaleLowerCase().startsWith(a)) - Number(!o(t).toLocaleLowerCase().startsWith(a))).slice(0, 8), !c.length) return r.textContent = d(e, "no-suggestions", "No suggestions"), _();
		n.replaceChildren(...c.map((e, t) => {
			let r = document.createElement("li");
			if (r.id = `${n.id}-${t}`, r.className = "f-autocomplete__option", r.setAttribute("role", "option"), r.textContent = o(e), e.label && e.label !== e.value) {
				let t = document.createElement("span");
				t.className = "f-autocomplete__detail", t.textContent = e.value, r.append(t);
			}
			return r.addEventListener("pointerdown", (e) => e.preventDefault()), r.addEventListener("click", () => b(t)), r;
		})), n.hidden = !1, i.show(), r.textContent = d(e, "count-message", "{count} suggestions", { count: c.length }), v(0);
	}, b = (e) => {
		let n = c[e];
		if (!n || !u) return;
		let { start: r, end: i } = u, a = t.value, o = `${n.value}${f() ? " " : ""}`;
		h(t, a.slice(0, r) + o + a.slice(i));
		let s = r + o.length;
		t.setSelectionRange?.(s, s), t.focus(), _();
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input:not([type=\"hidden\"]), textarea"), !t) return;
			a = new AbortController();
			let u = (e, t, n) => e.addEventListener(t, n, { signal: a.signal });
			n = document.createElement("ul"), n.id = m("fruit-suggestions"), n.className = "f-autocomplete__options", n.setAttribute("role", "listbox"), n.setAttribute("aria-label", d(e, "label", "Suggestions")), n.hidden = !0, r = document.createElement("span"), r.className = "f-sr-only", r.setAttribute("role", "status"), (e.querySelector("[data-fruit-ui]") ?? e).append(n, r), i = l(n, t, { stretch: t.tagName === "INPUT" });
			let f = () => {
				t.setAttribute("aria-autocomplete", "list"), t.setAttribute("aria-haspopup", "listbox"), t.setAttribute("aria-controls", n.id);
			};
			f(), o = new MutationObserver(() => {
				(t.getAttribute("aria-controls") !== n.id || !t.hasAttribute("aria-haspopup")) && f();
			}), o.observe(t, {
				attributes: !0,
				attributeFilter: [
					"aria-autocomplete",
					"aria-haspopup",
					"aria-controls"
				]
			}), u(t, "input", (e) => {
				e.isComposing || y();
			}), u(t, "keydown", (e) => {
				e.isComposing || n.hidden || (e.key === "ArrowDown" || e.key === "ArrowUp" ? (e.preventDefault(), v((s + (e.key === "ArrowDown" ? 1 : -1) + c.length) % c.length)) : e.key === "Enter" || e.key === "Tab" ? (e.preventDefault(), e.stopPropagation(), b(s)) : e.key === "Escape" && (e.preventDefault(), e.stopPropagation(), _()));
			}), u(t, "blur", _), u(t, "click", () => {
				n.hidden || y();
			});
		},
		destroy() {
			o?.disconnect(), a?.abort(), i?.destroy(), n?.remove(), r?.remove();
			for (let e of [
				"aria-autocomplete",
				"aria-haspopup",
				"aria-controls",
				"aria-activedescendant"
			]) t?.removeAttribute(e);
		}
	};
}
//#endregion
//#region src/js/command-palette.js
function v() {
	let e, t, n, r, i, a, o = -1, s = () => [...n.querySelectorAll("[role=\"option\"]")].filter((e) => !e.hidden && !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true"), c = (e) => {
		let r = s();
		o = r.length ? (e + r.length) % r.length : -1;
		for (let e of n.querySelectorAll("[role=\"option\"]")) e.setAttribute("aria-selected", "false");
		let i = r[o];
		if (!i) return t.removeAttribute("aria-activedescendant");
		i.id ||= m("fruit-command"), i.setAttribute("aria-selected", "true"), t.setAttribute("aria-activedescendant", i.id), i.scrollIntoView({ block: "nearest" });
	}, l = () => {
		let e = t.value.trim().toLocaleLowerCase();
		for (let t of n.querySelectorAll("[role=\"option\"]")) t.hidden = !!e && !t.textContent.toLocaleLowerCase().includes(e);
		for (let e of n.querySelectorAll("[role=\"group\"]")) e.hidden = !e.querySelector("[role=\"option\"]:not([hidden])");
		r.hidden = s().length > 0, c(0);
	}, u = (t) => {
		t && (e.close(), t.click());
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("[role=\"combobox\"]"), n = e.querySelector("[role=\"listbox\"]"), r = e.querySelector(".f-command-palette__empty"), !t || !n || !r) return;
			i = new AbortController();
			let d = (e, t, n) => e.addEventListener(t, n, { signal: i.signal });
			d(t, "input", l), d(t, "keydown", (e) => {
				e.isComposing || (e.key === "ArrowDown" || e.key === "ArrowUp" ? (e.preventDefault(), c(o + (e.key === "ArrowDown" ? 1 : -1))) : e.key === "Enter" && (e.preventDefault(), u(s()[o])));
			}), d(n, "pointermove", (e) => {
				let t = e.target.closest("[role=\"option\"]");
				t && !t.hidden && c(s().indexOf(t));
			}), d(e, "mousedown", (e) => {
				e.target !== t && e.preventDefault();
			}), d(n, "click", (t) => {
				t.target.closest("[role=\"option\"]") && e.open && e.close();
			}), a = new MutationObserver(() => {
				e.open && (t.value = "", l(), t.focus());
			}), a.observe(e, {
				attributes: !0,
				attributeFilter: ["open"]
			});
			let f = e.dataset.fruitShortcut?.toLocaleLowerCase();
			f && d(document, "keydown", (t) => {
				(t.metaKey || t.ctrlKey) && !t.altKey && t.key.toLocaleLowerCase() === f && (t.preventDefault(), e.open ? e.close() : e.showModal());
			}), l();
		},
		destroy() {
			a?.disconnect(), i?.abort();
		}
	};
}
//#endregion
//#region src/js/dropzone.js
var y = (e, t) => {
	let n = (e.accept || "").split(",").map((e) => e.trim().toLowerCase()).filter(Boolean);
	if (!n.length) return !0;
	let r = t.name.toLowerCase(), i = (t.type || "").toLowerCase();
	return n.some((e) => e.startsWith(".") ? r.endsWith(e) : e.endsWith("/*") ? i.startsWith(e.slice(0, -1)) : i === e);
};
function b() {
	let e;
	return {
		init() {
			let t = this.$el, n = t.querySelector("input[type=\"file\"]");
			if (!n) return;
			e = new AbortController();
			let r = (n, r) => t.addEventListener(n, r, { signal: e.signal }), i = 0, a = (e) => [...e.dataTransfer?.types ?? []].includes("Files");
			r("dragenter", (e) => {
				a(e) && !n.disabled && (e.preventDefault(), i += 1, t.setAttribute("data-dragging", ""));
			}), r("dragover", (e) => {
				a(e) && !n.disabled && (e.preventDefault(), e.dataTransfer.dropEffect = "copy");
			}), r("dragleave", () => {
				i = Math.max(0, i - 1), i || t.removeAttribute("data-dragging");
			}), r("drop", (e) => {
				if (!a(e) || n.disabled) return;
				e.preventDefault(), i = 0, t.removeAttribute("data-dragging");
				let r = [...e.dataTransfer.files].filter((e) => y(n, e));
				if (!r.length) return;
				let o = new DataTransfer();
				for (let e of n.multiple ? r : r.slice(0, 1)) o.items.add(e);
				n.files = o.files, n.dispatchEvent(new Event("input", { bubbles: !0 })), n.dispatchEvent(new Event("change", { bubbles: !0 }));
			});
		},
		destroy() {
			e?.abort();
		}
	};
}
//#endregion
//#region src/js/pickers.js
var x = (e) => String(e).padStart(2, "0"), S = (e) => `${String(e.getFullYear()).padStart(4, "0")}-${x(e.getMonth() + 1)}-${x(e.getDate())}`, C = (e) => {
	let t = /^(\d{4,})-(\d{2})-(\d{2})/.exec(e || "");
	return t ? new Date(Number(t[1]), Number(t[2]) - 1, Number(t[3])) : null;
}, w = (e, t) => new Date(e.getFullYear(), e.getMonth(), e.getDate() + t), T = (e, t) => {
	let n = new Date(e.getFullYear(), e.getMonth() + t, 1), r = new Date(n.getFullYear(), n.getMonth() + 1, 0).getDate();
	return n.setDate(Math.min(e.getDate(), r)), n;
}, E = (e, t) => !!(e && t) && S(e) === S(t), D = (e) => e.closest("[lang]")?.lang || navigator.language || "en";
function O(e) {
	try {
		let t = new Intl.Locale(e), n = t.getWeekInfo?.() ?? t.weekInfo;
		if (n?.firstDay) return n.firstDay % 7;
	} catch {}
	return +!/^en(-US|-CA)?$/i.test(e);
}
function k(e, t, n, { onOpen: r, onFocus: i }) {
	let a = l(n, t, { start: !0 }), o = new AbortController(), s = (e, t, n) => e.addEventListener(t, n, { signal: o.signal }), c = () => !n.hidden, u = () => {
		t.setAttribute("aria-haspopup", "dialog"), t.setAttribute("aria-controls", n.id);
	}, d = (e) => {
		c() && (a.hide(), n.hidden = !0, u(), e && t.focus());
	}, f = (e) => {
		t.disabled || t.readOnly || (n.hidden = !1, r(), a.show(), u(), e && i());
	};
	u();
	let p = new MutationObserver(() => {
		(t.getAttribute("aria-controls") !== n.id || !t.hasAttribute("aria-haspopup")) && u();
	});
	return p.observe(t, {
		attributes: !0,
		attributeFilter: ["aria-haspopup", "aria-controls"]
	}), s(document, "pointerdown", (t) => {
		c() && !e.contains(t.target) && !n.contains(t.target) && d(!1);
	}), s(e, "focusout", (t) => {
		c() && t.relatedTarget && !e.contains(t.relatedTarget) && !n.contains(t.relatedTarget) && d(!1);
	}), s(n, "keydown", (e) => {
		e.key === "Escape" && (e.preventDefault(), e.stopPropagation(), d(!0));
	}), {
		listen: s,
		open: f,
		close: d,
		isOpen: c,
		destroy() {
			p.disconnect(), o.abort(), a.destroy();
		}
	};
}
function A() {
	let e, t, n, r, i, a, o = /* @__PURE__ */ new Date(), s = /* @__PURE__ */ new Date(), l = () => C(t.value), u = () => [C(t.min), C(t.max)], f = (e) => {
		let [t, n] = u();
		return !!(t && e < t || n && e > n);
	}, p = (e) => {
		let [t, n] = u();
		return t && e < t ? t : n && e > n ? n : e;
	}, g = () => {
		let e = D(t);
		r.textContent = new Intl.DateTimeFormat(e, {
			month: "long",
			year: "numeric"
		}).format(o);
		let n = w(o, -((o.getDay() - O(e) + 7) % 7)), a = new Intl.DateTimeFormat(e, { weekday: "narrow" }), c = new Intl.DateTimeFormat(e, { weekday: "long" }), u = new Intl.DateTimeFormat(e, { dateStyle: "full" }), d = document.createElement("tr");
		for (let e = 0; e < 7; e++) {
			let t = w(n, e), r = document.createElement("th");
			r.scope = "col", r.abbr = c.format(t), r.textContent = a.format(t), d.append(r);
		}
		let p = [];
		for (let e = 0; e < 6; e++) {
			let t = document.createElement("tr");
			for (let r = 0; r < 7; r++) {
				let i = w(n, e * 7 + r), a = document.createElement("td");
				a.setAttribute("aria-selected", String(E(i, l())));
				let c = document.createElement("button");
				c.type = "button", c.className = "f-calendar__day", c.tabIndex = E(i, s) ? 0 : -1, c.textContent = String(i.getDate()), c.dataset.date = S(i), c.setAttribute("aria-label", u.format(i)), i.getMonth() !== o.getMonth() && (c.dataset.outside = ""), E(i, /* @__PURE__ */ new Date()) && c.setAttribute("aria-current", "date"), f(i) && c.setAttribute("aria-disabled", "true"), a.append(c), t.append(a);
			}
			p.push(t);
		}
		i.tHead.replaceChildren(d), i.tBodies[0].replaceChildren(...p);
	}, _ = (e, t = !0) => {
		s = e, o = new Date(e.getFullYear(), e.getMonth(), 1), g(), t && i.querySelector(`[data-date="${S(e)}"]`)?.focus();
	}, v = (e) => {
		if (f(e)) return;
		let n = S(e);
		if (t.type === "datetime-local") {
			let e = /* @__PURE__ */ new Date();
			n += `T${t.value.split("T")[1] || `${x(e.getHours())}:${x(e.getMinutes())}`}`;
		}
		h(t, n), a.close(!0);
	}, y = (e, n) => {
		let r = c(t) ? -1 : 1;
		return {
			ArrowLeft: () => w(n, -r),
			ArrowRight: () => w(n, r),
			ArrowUp: () => w(n, -7),
			ArrowDown: () => w(n, 7),
			Home: () => w(n, -((n.getDay() - O(D(t)) + 7) % 7)),
			End: () => w(n, 6 - (n.getDay() - O(D(t)) + 7) % 7),
			PageUp: () => T(n, e.shiftKey ? -12 : -1),
			PageDown: () => T(n, e.shiftKey ? 12 : 1)
		}[e.key]?.();
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input[type=\"date\"], input[type=\"datetime-local\"]"), !t) return;
			n = document.createElement("div"), n.id = m("fruit-calendar"), n.className = "f-calendar", n.setAttribute("role", "dialog"), n.setAttribute("aria-label", d(e, "label", "Choose date")), n.hidden = !0;
			let c = document.createElement("div");
			c.className = "f-calendar__header", r = document.createElement("div"), r.className = "f-calendar__title", r.id = `${n.id}-title`, r.setAttribute("aria-live", "polite");
			let u = (t, n, r) => {
				let i = document.createElement("button");
				return i.type = "button", i.className = "f-calendar__nav", i.dataset.direction = t, i.setAttribute("aria-label", d(e, n, r)), i.addEventListener("click", () => {
					s = p(T(s, t === "next" ? 1 : -1)), o = new Date(s.getFullYear(), s.getMonth(), 1), g();
				}), i;
			};
			c.append(r, u("previous", "previous-label", "Previous month"), u("next", "next-label", "Next month")), i = document.createElement("table"), i.className = "f-calendar__grid", i.setAttribute("role", "grid"), i.setAttribute("aria-labelledby", r.id), i.append(document.createElement("thead"), document.createElement("tbody")), n.append(c, i), (e.querySelector("[data-fruit-ui]") ?? e).append(n), a = k(e, t, n, {
				onOpen: () => _(p(l() ?? /* @__PURE__ */ new Date()), !1),
				onFocus: () => i.querySelector(".f-calendar__day[tabindex=\"0\"]")?.focus()
			}), e.setAttribute("data-ready", ""), a.listen(t, "click", (e) => {
				e.preventDefault(), a.isOpen() || a.open(!1);
			}), a.listen(t, "keydown", (e) => {
				e.altKey && ["ArrowDown", "ArrowUp"].includes(e.key) ? (e.preventDefault(), e.key === "ArrowUp" ? a.close(!0) : a.isOpen() ? i.querySelector(".f-calendar__day[tabindex=\"0\"]")?.focus() : a.open(!0)) : e.key === "Escape" && a.isOpen() && (e.preventDefault(), e.stopPropagation(), a.close(!1));
			}), a.listen(t, "input", () => {
				let e = l();
				a.isOpen() && e && _(e, !1);
			}), a.listen(i, "click", (e) => {
				let t = e.target.closest(".f-calendar__day");
				t && v(C(t.dataset.date));
			}), a.listen(i, "keydown", (e) => {
				let t = e.target.closest(".f-calendar__day");
				if (!t) return;
				let n = y(e, C(t.dataset.date));
				n && (e.preventDefault(), _(n));
			});
		},
		destroy() {
			a?.destroy(), n?.remove(), e?.removeAttribute("data-ready");
		}
	};
}
var j = [
	["Red", "#ff3b30"],
	["Orange", "#ff9500"],
	["Yellow", "#ffcc00"],
	["Green", "#34c759"],
	["Mint", "#00c7be"],
	["Teal", "#30b0c7"],
	["Cyan", "#32ade6"],
	["Blue", "#007aff"],
	["Indigo", "#5856d6"],
	["Purple", "#af52de"],
	["Pink", "#ff2d55"],
	["Brown", "#a2845e"],
	["Gray", "#8e8e93"]
], M = 7, N = (e) => [
	1,
	3,
	5
].map((t) => parseInt(e.slice(t, t + 2), 16) / 255);
function P(e) {
	let [t, n, r] = N(e), i = Math.max(t, n, r), a = i - Math.min(t, n, r), o = 0;
	return a && (o = i === t ? (n - r) / a % 6 : i === n ? (r - t) / a + 2 : (t - n) / a + 4), [
		(o * 60 + 360) % 360,
		i ? a / i : 0,
		i
	];
}
function F(e, t, n) {
	let r = (r) => {
		let i = (r + e / 60) % 6;
		return n - n * t * Math.max(0, Math.min(i, 4 - i, 1));
	};
	return `#${[
		r(5),
		r(3),
		r(1)
	].map((e) => Math.round(e * 255).toString(16).padStart(2, "0")).join("")}`;
}
function I() {
	let e, t, n, r, i, a, o, s, l, u, f, p = [
		0,
		0,
		0
	], g = () => {
		let e = [...t.list?.options ?? []].filter((e) => /^#[0-9a-f]{6}$/i.test(e.value)).map((e) => [e.label || e.value, e.value.toLowerCase()]);
		return e.length ? e : j;
	}, _ = () => [...r.querySelectorAll("[role=\"option\"]")], v = () => {
		let e = t.value.toLowerCase();
		r.replaceChildren(...g().map(([t, n]) => {
			let r = document.createElement("div");
			r.className = "f-swatch", r.setAttribute("role", "option"), r.tabIndex = -1, r.title = t, r.dataset.value = n, r.style.setProperty("--f-swatch-color", n), r.setAttribute("aria-selected", String(n === e));
			let i = document.createElement("span");
			return i.className = "f-sr-only", i.textContent = t, r.append(i), r;
		}));
	}, y = () => {
		let [n, r, i] = p;
		a.style.setProperty("--f-picker-hue", F(n, 1, 1)), s.style.left = `${r * 100}%`, s.style.top = `${(1 - i) * 100}%`;
		let c = (e) => Math.round(e * 100);
		o.setAttribute("aria-valuenow", String(c(r))), o.setAttribute("aria-valuetext", d(e, "area-text", "Saturation {saturation}%, brightness {brightness}%", {
			saturation: c(r),
			brightness: c(i)
		})), l.value = String(Math.round(n)), document.activeElement !== u && (u.value = t.value);
	}, b = () => {
		let [e, n, r] = P(t.value);
		p = [
			n && r ? e : p[0],
			n,
			r
		], y();
	}, x = (e, n) => {
		p = e, h(t, F(...p), { commit: n }), y();
	}, S = (e) => {
		h(t, e.dataset.value), f.close(!0);
	}, C = (e, t, n = {}) => {
		let r = document.createElement(e);
		r.className = t;
		for (let [e, t] of Object.entries(n)) r.setAttribute(e, t);
		return r;
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input[type=\"color\"]"), !t) return;
			n = C("div", "f-color-palette", {
				id: m("fruit-colors"),
				role: "dialog",
				"aria-label": d(e, "label", "Choose color")
			}), n.hidden = !0, r = C("div", "f-color-palette__swatches", {
				role: "listbox",
				"aria-label": d(e, "colors-label", "Colors")
			}), r.style.setProperty("--f-swatch-columns", String(M)), i = C("button", "f-button f-button--ghost f-button--small f-color-palette__other", {
				type: "button",
				"aria-expanded": "false"
			}), i.textContent = d(e, "other-label", "Other…"), a = C("div", "f-color-editor"), a.hidden = !0, a.style.setProperty("--f-picker-black", "#000"), a.style.setProperty("--f-picker-white", "#fff"), a.style.setProperty("--f-picker-spectrum", "linear-gradient(90deg, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00)"), o = C("div", "f-color-editor__area", {
				role: "slider",
				tabindex: "0",
				"aria-label": d(e, "area-label", "Saturation and brightness"),
				"aria-valuemin": "0",
				"aria-valuemax": "100"
			}), s = C("span", "f-color-editor__thumb", { "aria-hidden": "true" }), o.append(s), l = C("input", "f-range f-color-editor__hue", {
				type: "range",
				min: "0",
				max: "359",
				"aria-label": d(e, "hue-label", "Hue")
			});
			let g = C("label", "f-color-editor__hex"), y = C("span", "f-label");
			y.textContent = d(e, "hex-label", "Hex"), u = C("input", "f-input", {
				type: "text",
				maxlength: "7",
				spellcheck: "false",
				autocomplete: "off"
			}), g.append(y, u), a.append(o, l, g), i.setAttribute("aria-controls", a.id = `${n.id}-editor`), n.append(r, i, a), v(), (e.querySelector("[data-fruit-ui]") ?? e).append(n), f = k(e, t, n, {
				onOpen: () => {
					v(), a.hidden = !0, i.hidden = !1, i.setAttribute("aria-expanded", "false");
				},
				onFocus: () => {
					let e = _();
					(e.find((e) => e.getAttribute("aria-selected") === "true") ?? e[0])?.focus();
				}
			}), e.setAttribute("data-ready", ""), f.listen(t, "click", (e) => {
				e.preventDefault(), f.isOpen() ? f.close(!1) : f.open(!0);
			}), f.listen(t, "input", () => {
				a.hidden || b();
			}), f.listen(r, "click", (e) => {
				let t = e.target.closest("[role=\"option\"]");
				t && S(t);
			}), f.listen(r, "keydown", (e) => {
				let n = _(), r = n.indexOf(document.activeElement);
				if (r < 0) return;
				let i = c(t) ? -1 : 1, a = {
					ArrowRight: r + i,
					ArrowLeft: r - i,
					ArrowDown: r + M,
					ArrowUp: r - M,
					Home: 0,
					End: n.length - 1
				};
				e.key in a ? (e.preventDefault(), n[Math.max(0, Math.min(n.length - 1, a[e.key]))].focus()) : (e.key === "Enter" || e.key === " ") && (e.preventDefault(), S(n[r]));
			}), f.listen(i, "click", () => {
				a.hidden = !1, i.hidden = !0, i.setAttribute("aria-expanded", "true"), b(), o.focus();
			});
			let w = (e, t) => {
				let n = o.getBoundingClientRect(), r = (e) => Math.max(0, Math.min(1, e));
				x([
					p[0],
					r((e.clientX - n.left) / n.width),
					1 - r((e.clientY - n.top) / n.height)
				], t);
			}, T = !1;
			f.listen(o, "pointerdown", (e) => {
				e.button === 0 && (e.preventDefault(), o.focus(), o.setPointerCapture(e.pointerId), T = !0, w(e, !1));
			}), f.listen(o, "pointermove", (e) => {
				T && w(e, !1);
			}), f.listen(o, "pointerup", (e) => {
				T && (T = !1, w(e, !0));
			}), f.listen(o, "keydown", (e) => {
				let t = e.shiftKey ? .1 : .01, n = {
					ArrowLeft: [-t, 0],
					ArrowRight: [t, 0],
					ArrowDown: [0, -t],
					ArrowUp: [0, t]
				}[e.key];
				if (!n) return;
				e.preventDefault();
				let r = (e) => Math.max(0, Math.min(1, e));
				x([
					p[0],
					r(p[1] + n[0]),
					r(p[2] + n[1])
				], !0);
			}), f.listen(l, "input", () => x([
				Number(l.value),
				p[1],
				p[2]
			], !1)), f.listen(l, "change", () => x([
				Number(l.value),
				p[1],
				p[2]
			], !0)), f.listen(u, "input", () => {
				let e = u.value.trim().toLowerCase(), n = /^#?[0-9a-f]{6}$/.test(e) ? `#${e.replace("#", "")}` : null;
				n && (h(t, n), b());
			}), f.listen(u, "blur", () => u.value = t.value), f.listen(u, "keydown", (e) => {
				e.key === "Enter" && (e.preventDefault(), f.close(!0));
			});
		},
		destroy() {
			f?.destroy(), n?.remove(), e?.removeAttribute("data-ready");
		}
	};
}
//#endregion
//#region src/js/editor-content.js
var L = Symbol.for("fruitui.editor");
function ee(e, t, { insert: n, set: r, commit: i }) {
	let a = new AbortController(), o = (e) => {
		let { html: a = "", target: o } = e.detail ?? {};
		(e.currentTarget !== window || o === t.id || o === t.name) && (t.matches(":disabled") || t.readOnly || ((e.type === "fruit-editor-set" ? r : n)(String(a)), i()));
	};
	for (let t of ["fruit-editor-insert", "fruit-editor-set"]) e.addEventListener(t, o, { signal: a.signal }), window.addEventListener(t, o, { signal: a.signal });
	return () => a.abort();
}
function R() {
	let e;
	return {
		init() {
			let t = this.$el.querySelector("textarea[data-fruit-control]");
			t && (e = ee(this.$el, t, {
				insert: (e) => {
					let n = t.selectionStart ?? t.value.length, r = t.selectionEnd ?? n;
					h(t, t.value.slice(0, n) + e + t.value.slice(r), { commit: !1 }), t.setSelectionRange?.(n + e.length, n + e.length);
				},
				set: (e) => h(t, e, { commit: !1 }),
				commit: () => t.dispatchEvent(new Event("change", { bubbles: !0 }))
			}));
		},
		destroy() {
			e?.();
		}
	};
}
//#endregion
//#region src/js/selection.js
function z() {
	let e, t, n, r, i, a, o, s, c, u = [], f = -1, p = !1, _ = () => t.selectedOptions[0]?.label || "", v = () => {
		p = !1, c.hide(), r.hidden = !0, n.setAttribute("aria-expanded", "false"), n.removeAttribute("aria-activedescendant");
	}, y = (e) => {
		f = e, [...r.children].forEach((e, t) => e.setAttribute("aria-selected", String(t === f))), r.children[f] ? (n.setAttribute("aria-activedescendant", r.children[f].id), r.children[f].scrollIntoView({ block: "nearest" })) : n.removeAttribute("aria-activedescendant");
	}, b = (i = "") => {
		if (n.disabled) return;
		if (u = [...t.options].filter((e) => !e.matches(":disabled") && !e.hidden && e.label.toLocaleLowerCase().includes(i.toLocaleLowerCase())), r.replaceChildren(), u.forEach((e, t) => {
			let n = document.createElement("li");
			n.className = "f-combobox__option", n.id = `${r.id}-${t}`, n.setAttribute("role", "option"), n.textContent = e.label, n.addEventListener("pointerdown", (e) => e.preventDefault()), n.addEventListener("click", () => x(t)), r.append(n);
		}), !u.length) {
			let t = document.createElement("li");
			t.className = "f-combobox__empty", t.setAttribute("role", "presentation"), t.textContent = d(e, "no-matches", "No matches"), r.append(t);
		}
		p = !0, r.hidden = !1, n.setAttribute("aria-expanded", "true"), c.show();
		let a = u.findIndex((e) => e.selected);
		y(u.length ? Math.max(0, a) : -1);
	}, x = (e) => {
		u[e] && (h(t, u[e].value), n.value = _(), n.removeAttribute("aria-invalid"), v(), n.focus());
	};
	return {
		init() {
			e = this.$el, t = e.querySelector("select[data-fruit-control]"), !(!t || t.multiple || t.size > 1) && (n = document.createElement("input"), n.type = "text", n.className = "f-input", n.autocomplete = "off", n.setAttribute("role", "combobox"), n.setAttribute("aria-autocomplete", "list"), n.setAttribute("aria-expanded", "false"), r = document.createElement("ul"), r.id = m("fruit-options"), r.className = "f-combobox__options", r.setAttribute("role", "listbox"), r.hidden = !0, i = this.$el.querySelector("[data-fruit-ui]"), a = !i, i || (i = document.createElement("div"), i.setAttribute("data-fruit-ui", ""), this.$el.append(i)), n.setAttribute("aria-controls", r.id), i.append(n, r), t.hidden = !0, c = l(r, n, { stretch: !0 }), n.value = _(), n.addEventListener("input", () => b(n.value)), n.addEventListener("click", () => b()), n.addEventListener("blur", () => {
				v(), n.value = _();
			}), n.addEventListener("keydown", (e) => {
				e.isComposing || (["ArrowDown", "ArrowUp"].includes(e.key) ? (e.preventDefault(), p ? u.length && y((f + (e.key === "ArrowDown" ? 1 : -1) + u.length) % u.length) : (b(), y(e.key === "ArrowUp" ? u.length - 1 : 0))) : e.key === "Enter" && p && f >= 0 ? (e.preventDefault(), x(f)) : e.key === "Escape" && p ? (e.preventDefault(), e.stopPropagation(), v(), n.value = _()) : e.key === "Tab" && v());
			}), s = (e) => {
				this.$el.contains(e.target) || v();
			}, document.addEventListener("pointerdown", s), o = g(this, t, n, (e) => {
				p && ["value", "reset"].includes(e) && v(), ([
					"initial",
					"value",
					"reset"
				].includes(e) || !p) && (n.value = _()), p && e === "options" && b(n.value);
				for (let e of ["aria-label", "aria-labelledby"]) n.hasAttribute(e) ? r.setAttribute(e, n.getAttribute(e)) : r.removeAttribute(e);
				n.disabled && v();
			}));
		},
		destroy() {
			o?.(), c?.destroy(), document.removeEventListener("pointerdown", s), n?.remove(), r?.remove(), a && i?.remove(), t && (t.hidden = !1);
		}
	};
}
function B() {
	let e, t, n, r, i, a, o, s, l, u = [], f = () => t.value.split(/\r?\n/).map((e) => e.trim()).filter(Boolean), p = (e) => {
		i.textContent = e;
	}, m = () => {
		u = f(), n.querySelectorAll(".f-chip").forEach((e) => e.remove());
		for (let [i, a] of u.entries()) {
			let o = document.createElement("span");
			o.className = "f-chip";
			let s = document.createElement("span");
			s.textContent = a;
			let l = document.createElement("button");
			l.type = "button", l.className = "f-chip__remove", l.textContent = "×", l.setAttribute("aria-label", d(e, "remove-label", "Remove {value}", { value: a })), l.disabled = r.disabled || r.readOnly, l.addEventListener("click", () => {
				h(t, u.filter((e, t) => t !== i).join("\n")), p(d(e, "removed-message", "Removed {value}", { value: a })), r.focus();
			}), l.addEventListener("keydown", (e) => {
				let t = [...n.querySelectorAll("button")];
				(e.key === "ArrowLeft" || e.key === "ArrowRight") && (e.preventDefault(), (t[i + (e.key === "ArrowLeft" === c(n) ? 1 : -1)] || r).focus()), e.key === "Escape" && (e.preventDefault(), r.focus());
			}), o.append(s, l), n.insertBefore(o, r);
		}
	}, _ = (n) => {
		if (r.disabled || r.readOnly) return !1;
		let i = [...u];
		for (let a of n.split(/[,\n]/).map((e) => e.trim()).filter(Boolean)) {
			let n = {
				value: a,
				error: d(e, "invalid-message", "Check this value before adding it.")
			};
			if (!t.dispatchEvent(new CustomEvent("fruit-token-add", {
				bubbles: !0,
				cancelable: !0,
				detail: n
			}))) return r.setCustomValidity(n.error), r.setAttribute("aria-invalid", "true"), p(n.error), !1;
			let o = String(n.value).trim();
			o && !i.includes(o) && i.push(o);
		}
		if (t.maxLength >= 0 && i.join("\n").length > t.maxLength) {
			let n = d(e, "length-message", "Use at most {count} characters.", { count: t.maxLength });
			return r.setCustomValidity(n), r.setAttribute("aria-invalid", "true"), p(n), !1;
		}
		return h(t, i.join("\n")), r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid"), p(d(e, "count-message", "{count} items", { count: i.length })), !0;
	};
	return {
		init() {
			e = this.$el, t = e.querySelector("textarea[data-fruit-control]"), t && (n = document.createElement("div"), n.className = "f-token-field__entry", r = document.createElement("input"), r.type = "text", r.autocomplete = "off", r.placeholder = d(e, "placeholder", "Add an item"), i = document.createElement("span"), i.className = "f-sr-only", i.setAttribute("role", "status"), a = this.$el.querySelector("[data-fruit-ui]"), o = !a, a || (a = document.createElement("div"), a.setAttribute("data-fruit-ui", ""), this.$el.append(a)), n.append(r), a.append(n, i), t.hidden = !0, l = () => {
				r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid"), i.textContent = "", m();
			}, t.addEventListener("fruit-token-reset", l), r.addEventListener("keydown", (e) => {
				e.isComposing || (e.key === "Enter" || e.key === "," ? (e.preventDefault(), _(r.value)) : (e.key === "Backspace" || e.key === "ArrowLeft") && !r.value ? n.querySelector(".f-chip:last-of-type button")?.focus() : e.key === "Escape" ? (r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid")) : e.key === "Tab" && r.value.trim() && _(r.value));
			}), r.addEventListener("input", () => {
				r.setCustomValidity(""), r.removeAttribute("aria-invalid");
			}), r.addEventListener("change", () => {
				r.value.trim() && _(r.value);
			}), r.addEventListener("paste", (e) => {
				let t = e.clipboardData?.getData("text");
				t && /[,\n]/.test(t) && (e.preventDefault(), _(t));
			}), s = g(this, t, r, (e) => {
				m(), ["value", "reset"].includes(e) && (r.value = "", i.textContent = "");
			}, {
				presentation: n,
				focusRoot: n
			}));
		},
		destroy() {
			s?.(), t?.removeEventListener("fruit-token-reset", l), n?.remove(), i?.remove(), o && a?.remove(), t && (t.hidden = !1);
		}
	};
}
function V() {
	let e, t, n = () => {
		let t = Math.max(0, Number.parseInt(e.dataset.count ?? "0", 10) || 0), n = (e.dataset.fruitTemplate || ":count selected").split("|"), r = e.closest("[lang]")?.lang || navigator.language || "en", i = n.length > 1 && new Intl.PluralRules(r).select(t) !== "one" ? n[1] : n[0], a = e.querySelector(".f-selection-bar__count");
		a && (a.textContent = i.trim().replace(":count", String(t))), e.hidden = t === 0;
	};
	return {
		init() {
			e = this.$el, t = new MutationObserver(n), t.observe(e, {
				attributes: !0,
				attributeFilter: ["data-count"]
			}), n();
		},
		destroy() {
			t?.disconnect();
		}
	};
}
//#endregion
//#region src/js/navigation.js
var H = "[role=\"menuitem\"], [role=\"menuitemcheckbox\"], [role=\"menuitemradio\"]", U = (e, t) => [...e.querySelectorAll(H)].filter((e) => t(e) && !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true" && e.getClientRects().length), W = (e, t) => {
	e.length && e[(t + e.length) % e.length].focus();
}, G = (e) => [...e.childNodes].filter((e) => !e.classList?.contains("f-menu-item__shortcut")).map((e) => e.textContent).join("").trim().toLocaleLowerCase();
function K(e, t, n) {
	let r = t.indexOf(document.activeElement);
	return [
		"ArrowDown",
		"ArrowUp",
		"Home",
		"End"
	].includes(e.key) ? (e.preventDefault(), W(t, e.key === "Home" ? 0 : e.key === "End" ? t.length - 1 : r + (e.key === "ArrowDown" ? 1 : -1)), !0) : e.key.length === 1 && e.key !== " " && !e.ctrlKey && !e.metaKey && !e.altKey && (e.preventDefault(), clearTimeout(n.timer), n.search += e.key.toLocaleLowerCase(), [...t.slice(r + 1), ...t.slice(0, r + 1)].find((e) => G(e).startsWith(n.search))?.focus(), n.timer = setTimeout(() => {
		n.search = "";
	}, 600), !0);
}
function q() {
	let e, t, n, r, i, a, o, s = {
		search: "",
		timer: null
	}, c = (t) => t?.closest("[data-fruit-menu], [x-data^=\"fruitMenu\"]") === e, l = () => U(n, c), d = (e) => W(l(), e);
	return {
		init() {
			e = this.$el, e.setAttribute("data-fruit-menu", ""), t = e.querySelector("summary"), n = e.querySelector("[role=\"menu\"]"), t && n && (r = u(e, n, {
				above: e.classList.contains("f-menu--above"),
				owns: c,
				onToggle: (e) => {
					t.setAttribute("aria-expanded", String(e)), e && document.activeElement === t && d(0);
				}
			}), n.id ||= m("fruit-menu"), t.setAttribute("aria-haspopup", "menu"), t.setAttribute("aria-controls", n.id), t.setAttribute("aria-expanded", String(e.open)), n.querySelectorAll(H).forEach((e) => {
				c(e) && (e.tabIndex = -1);
			}), i = (n) => {
				if (!c(n.target)) return;
				let i = l();
				n.target === t && ["ArrowDown", "ArrowUp"].includes(n.key) ? (n.preventDefault(), e.open = !0, r.show(), d(n.key === "ArrowDown" ? 0 : i.length - 1)) : e.open && n.key === "Tab" ? o = setTimeout(() => r.close(!1), 0) : e.open && K(n, i, s);
			}, a = (e) => {
				let t = e.target.closest(H);
				c(t) && !t.matches(":disabled") && t.getAttribute("aria-disabled") !== "true" && r.close(!0);
			}, e.addEventListener("keydown", i), n.addEventListener("click", a));
		},
		destroy() {
			clearTimeout(o), clearTimeout(s.timer), r?.destroy(), e?.removeEventListener("keydown", i), n?.removeEventListener("click", a);
		}
	};
}
function J() {
	let e, t, n, r, i, a, o = {
		x: 0,
		y: 0
	}, s = {
		search: "",
		timer: null
	}, u = (t) => t?.closest("[data-fruit-menu]") === e, d = () => !e.hidden, f = (r) => {
		d() && (n.hide(), e.hidden = !0, t.removeAttribute("data-fruit-context-open"), r && i?.isConnected && i.focus());
	}, p = (r, a, s) => {
		i = s, o = {
			x: r,
			y: a
		}, e.hidden = !1, t.setAttribute("data-fruit-context-open", ""), n.show(), W(U(e, u), 0);
	};
	return {
		init() {
			if (e = this.$el, t = e.parentElement, !t) return;
			e.setAttribute("data-fruit-menu", ""), e.id ||= m("fruit-context-menu"), e.hidden = !0, e.querySelectorAll(H).forEach((e) => {
				u(e) && (e.tabIndex = -1);
			}), n = l(e, { getBoundingClientRect: () => new DOMRect(o.x, o.y, 0, 0) }, { point: t }), r = new AbortController();
			let i = (e, t, n) => e.addEventListener(t, n, { signal: r.signal }), h = !1;
			i(t, "contextmenu", (t) => {
				e.contains(t.target) || (t.preventDefault(), !h && p(t.clientX, t.clientY, document.activeElement));
			}), i(t, "keydown", (n) => {
				if (e.contains(n.target) || !(n.key === "F10" && n.shiftKey || n.key === "ContextMenu")) return;
				n.preventDefault(), h = !0, a = setTimeout(() => h = !1, 0);
				let r = n.target.getBoundingClientRect();
				p(c(t) ? r.right : r.left, r.bottom, n.target);
			}), i(e, "keydown", (t) => {
				u(t.target) && (t.key === "Escape" ? (t.preventDefault(), t.stopPropagation(), f(!0)) : t.key === "Tab" ? (t.preventDefault(), f(!0)) : K(t, U(e, u), s));
			}), i(e, "click", (e) => {
				let t = e.target.closest(H);
				u(t) && !t.matches(":disabled") && t.getAttribute("aria-disabled") !== "true" && f(!0);
			}), i(document, "pointerdown", (t) => {
				d() && !e.contains(t.target) && f(!1);
			}), i(window, "blur", () => f(!1));
		},
		destroy() {
			clearTimeout(a), clearTimeout(s.timer), r?.abort(), n?.destroy(), t?.removeAttribute("data-fruit-context-open");
		}
	};
}
function Y() {
	let e, t, n, r, i, a, o;
	return {
		init() {
			e = this.$el;
			let s = e.querySelector("[role=\"tooltip\"]");
			s && (a = l(s, e.firstElementChild));
			let c = () => {
				e.hasAttribute("data-dismissed") || a?.show();
			};
			r = () => {
				clearTimeout(o), o = setTimeout(c, 600);
			}, i = () => {
				clearTimeout(o), c();
			}, t = (t) => {
				t.key === "Escape" && (e.matches(":hover") || e.contains(document.activeElement)) && (e.setAttribute("data-dismissed", ""), a?.hide(), t.stopPropagation());
			}, n = (t) => {
				e.contains(t.relatedTarget) || (clearTimeout(o), e.removeAttribute("data-dismissed"), !e.matches(":hover") && !e.contains(document.activeElement) && a?.hide());
			}, document.addEventListener("keydown", t), e.addEventListener("mouseleave", n), e.addEventListener("focusout", n), e.addEventListener("mouseenter", r), e.addEventListener("focusin", i);
		},
		destroy() {
			clearTimeout(o), a?.destroy(), document.removeEventListener("keydown", t), e.removeEventListener("mouseleave", n), e.removeEventListener("focusout", n), e.removeEventListener("mouseenter", r), e.removeEventListener("focusin", i);
		}
	};
}
function X() {
	let e, t, n, r, i = (t) => t?.closest("[data-fruit-tabs], [x-data^=\"fruitTabs\"]") === e, a = () => [...e.querySelectorAll("[role=\"tab\"]")].filter(i), o = () => a().filter((e) => !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true"), s = (t) => {
		for (let n of a()) {
			let r = n === t;
			n.setAttribute("aria-selected", String(r)), n.tabIndex = r ? 0 : -1;
			let a = [...e.querySelectorAll("[role=\"tabpanel\"]")].find((e) => i(e) && e.id === n.getAttribute("aria-controls"));
			a && (a.hidden = !r);
		}
	}, l = () => s(o().find((e) => e.getAttribute("aria-selected") === "true") || o()[0]);
	return {
		init() {
			e = this.$el, e.setAttribute("data-fruit-tabs", ""), l(), n = (e) => {
				let t = e.target.closest("[role=\"tab\"]");
				o().includes(t) && s(t);
			}, t = (t) => {
				let n = o(), r = n.indexOf(t.target);
				if (r < 0 || ![
					"ArrowLeft",
					"ArrowRight",
					"Home",
					"End"
				].includes(t.key)) return;
				t.preventDefault(), t.stopPropagation();
				let i = c(e), a = t.key === "Home" ? 0 : t.key === "End" ? n.length - 1 : (r + (t.key === "ArrowRight" === i ? -1 : 1) + n.length) % n.length;
				s(n[a]), n[a].focus();
			}, e.addEventListener("click", n), e.addEventListener("keydown", t), r = new MutationObserver(l), r.observe(e, {
				childList: !0,
				subtree: !0,
				attributes: !0,
				attributeFilter: ["disabled", "aria-disabled"]
			});
		},
		destroy() {
			r.disconnect(), e.removeEventListener("click", n), e.removeEventListener("keydown", t);
		}
	};
}
function Z() {
	let e;
	return {
		init() {
			let t = this.$el, n = t.querySelector(".f-floating-disclosure__content");
			t.querySelector("summary") && n && (e = u(t, n, {
				above: t.classList.contains("f-floating-disclosure--above"),
				owns: (e) => e?.closest(".f-floating-disclosure") === t
			}));
		},
		close(t = !1) {
			e?.close(t);
		},
		destroy() {
			e?.destroy();
		}
	};
}
//#endregion
//#region src/js/alpine.js
var Q = !1;
function $(e) {
	e[L] || e.data("fruitEditor", R), e.data("fruitToast", n), e.magic("toast", () => t), e.data("fruitConfirmer", a), e.magic("confirm", () => i), e.data("fruitCombobox", z), e.data("fruitTokenField", B), e.data("fruitSelectionBar", V), e.data("fruitAutocomplete", _), e.data("fruitCommandPalette", v), e.data("fruitDropzone", b), e.data("fruitMenu", q), e.data("fruitDatePicker", A), e.data("fruitColorPicker", I), e.data("fruitContextMenu", J), e.data("fruitTooltip", Y), e.data("fruitTabs", X), e.data("fruitSplitter", f), e.data("fruitFloatingDisclosure", Z), e.data("fruitDialogModel", s), typeof window < "u" && !Q && (o(window), Q = !0);
}
//#endregion
//#region src/js/livewire.js
window.Alpine ? $(window.Alpine) : document.addEventListener("alpine:init", () => $(window.Alpine), { once: !0 });
//#endregion
export { i as confirm, t as toast };
