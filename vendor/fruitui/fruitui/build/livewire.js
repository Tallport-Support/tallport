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
//#region src/js/messages.js
function o(e, t, n, r = {}) {
	return (e.getAttribute(`data-fruit-${t}`) ?? n).replace(/\{(\w+)\}/g, (e, t) => String(r[t] ?? e));
}
//#endregion
//#region src/js/copy.js
async function s(e) {
	if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(e);
	let t = document.createElement("textarea");
	t.value = e, t.setAttribute("readonly", ""), t.style.cssText = "position: fixed; inset-block-start: 0; opacity: 0", document.body.append(t), t.select();
	let n = document.execCommand("copy");
	if (t.remove(), !n) throw Error("FruitUI could not copy to the clipboard.");
}
function c() {
	let e, t = new AbortController();
	return {
		init() {
			let n = this.$el, r = n.querySelector("button[data-fruit-copy]"), i = n.querySelector("[role=\"status\"]");
			if (!r || !i) return;
			let a = (e) => {
				i.textContent = "", requestAnimationFrame(() => i.textContent = e);
			};
			r.addEventListener("click", async () => {
				clearTimeout(e);
				let t = r.dataset.fruitCopy ?? "";
				try {
					await s(t), n.dataset.copied = "", a(o(n, "copied-message", "Copied")), r.dispatchEvent(new CustomEvent("fruit-copied", {
						bubbles: !0,
						detail: { value: t }
					}));
				} catch {
					delete n.dataset.copied, a(o(n, "failed-message", "Could not copy"));
				}
				e = setTimeout(() => {
					delete n.dataset.copied, i.textContent = "";
				}, 2e3);
			}, { signal: t.signal });
		},
		destroy() {
			clearTimeout(e), t.abort();
		}
	};
}
//#endregion
//#region src/js/list-selection.js
function l() {
	let e = new AbortController(), t, n, r = null, i = !1, a = (e) => e.querySelector(":scope > .f-check input[type=\"checkbox\"], :scope > input[type=\"checkbox\"]"), o = (e) => e.querySelector(":scope > .f-item-row"), s = () => [...t.children].filter((e) => a(e) && o(e)), c = (e) => a(e).checked, l = () => s().filter(c), u = () => s().find((e) => o(e).matches("[aria-current=\"true\"], [aria-current=\"page\"]")), d = (e, t) => {
		let n = a(e);
		n && !n.disabled && n.checked !== t && n.click();
	}, f = (e, t) => {
		let n = s(), [r, i] = [n.indexOf(e), n.indexOf(t)].sort((e, t) => e - t);
		return n.slice(r, i + 1);
	}, p = (e, { inRow: n = !0 } = {}) => {
		let r = e.closest?.("li");
		return !r || r.parentElement !== t || !a(r) || !o(r) ? null : !n || o(r).contains(e) ? r : null;
	}, m = () => t.id ? [...document.querySelectorAll(`[data-fruit-select-toggle][aria-controls="${CSS.escape(t.id)}"]`)] : [], h = () => {
		t.hasAttribute("data-selecting") !== i && t.toggleAttribute("data-selecting", i);
		for (let e of m()) e.getAttribute("aria-pressed") !== String(i) && e.setAttribute("aria-pressed", String(i));
	};
	return {
		init() {
			t = this.$el;
			let a = (t, n, r, i = !1) => t.addEventListener(n, r, {
				capture: i,
				signal: e.signal
			});
			a(t, "mousedown", (e) => {
				e.shiftKey && p(e.target) && e.preventDefault();
			}, !0), a(t, "click", (e) => {
				let t = p(e.target);
				if (t) {
					if (!(e.metaKey || e.ctrlKey || i && !e.shiftKey) && !e.shiftKey) {
						for (let e of l()) d(e, !1);
						r = t;
						return;
					}
					if (e.preventDefault(), e.stopImmediatePropagation(), e.shiftKey) {
						let e = r?.isConnected && s().includes(r) ? r : u() ?? t;
						for (let n of f(e, t)) d(n, !0);
					} else {
						let e = u();
						!l().length && e && e !== t && !i && d(e, !0), d(t, !c(t)), r = t;
					}
					o(t).focus();
				}
			}, !0), a(t, "keydown", (e) => {
				let t = p(e.target);
				if (t && e.target === o(t)) {
					if (e.shiftKey && (e.key === "ArrowDown" || e.key === "ArrowUp")) {
						e.preventDefault(), e.stopImmediatePropagation();
						let n = s(), i = n[n.indexOf(t) + (e.key === "ArrowDown" ? 1 : -1)];
						if (!i) return;
						c(t) ? c(i) ? d(t, !1) : d(i, !0) : (d(t, !0), d(i, !0)), r ??= t, o(i).focus();
					} else if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "a") {
						e.preventDefault();
						for (let e of s()) d(e, !0);
					} else if (e.key === "Escape" && l().length) {
						e.preventDefault(), e.stopImmediatePropagation();
						for (let e of l()) d(e, !1);
					}
				}
			}, !0), a(document, "click", (e) => {
				let n = e.target.closest?.("[data-fruit-select-toggle]");
				n && t.id && n.getAttribute("aria-controls") === t.id && (i = !i, h());
			}), n = new MutationObserver(h), n.observe(document.body, {
				subtree: !0,
				attributes: !0,
				attributeFilter: ["data-selecting", "aria-pressed"]
			}), h();
		},
		destroy() {
			e.abort(), n?.disconnect(), t?.removeAttribute("data-selecting");
		}
	};
}
//#endregion
//#region src/js/control-bridge.js
var u = 0, d = (e) => `${e}-${++u}`;
function f(e, t) {
	return e.id || (e.id = d(t), e._x_bindings = {
		...e._x_bindings,
		id: e.id
	}), e.id;
}
function p(e, t, { commit: n = !0 } = {}) {
	e.value !== t && (e.value = t, e.dispatchEvent(new Event("input", { bubbles: !0 })), n && e.dispatchEvent(new Event("change", { bubbles: !0 })));
}
function m(e, t, n, r, { presentation: i = n, focusRoot: a = n } = {}) {
	let o = [], s = (e, t, n) => {
		e?.addEventListener(t, n), o.push(() => e?.removeEventListener(t, n));
	}, c = () => [...t.labels || []], l = i.className, u = i.getAttribute("style"), d = n.placeholder || "", p = t.ownerDocument.activeElement === t, m = t.value;
	s(t.ownerDocument, "click", (e) => {
		c().some((t) => t.contains(e.target)) && (e.target === t || !e.target.closest("button, a, input, select, textarea")) && (e.preventDefault(), n.focus());
	});
	let h = (e = "attributes") => {
		t.hidden ||= !0;
		let a = c();
		for (let e of a) f(e, "fruit-label");
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
		o === null ? i.removeAttribute("style") : i.setAttribute("style", o), "placeholder" in n && (n.placeholder = t.getAttribute("placeholder") ?? d), r(e), m = t.value;
	}, g = () => {
		t.value !== m && (n.setCustomValidity?.(""), h("value"));
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
		h(t.value === m ? n ? "options" : "attributes" : "value");
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
		t._x_model && o.push(e.$watch(() => t._x_model.get(), () => e.$nextTick(g))), h("initial"), (t.autofocus || p) && [t.ownerDocument.body, t].includes(t.ownerDocument.activeElement) && n.focus();
	}), h("initial"), () => {
		v.disconnect(), clearTimeout(_), o.forEach((e) => e?.());
	};
}
//#endregion
//#region src/js/remote-dialog.js
var h = ["medium", "large"], g = (e, t) => document.querySelector("template[data-fruit-remote-dialog]")?.getAttribute(`data-fruit-${e}`) ?? t;
function _(e, t) {
	let n = d("fruit-dialog"), r = document.createElement("dialog");
	return r.className = `f-dialog f-dialog--scroll${t === "large" ? " f-dialog--large" : ""}`, document.body.closest(".fruit-ui") || r.classList.add("fruit-ui"), r.setAttribute("aria-labelledby", `${n}-title`), r.innerHTML = `<header class="f-dialog__header"><h2 id="${n}-title"></h2><button class="f-button f-button--ghost f-button--icon" type="button" data-fruit-dialog-close><svg class="f-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button></header><div class="f-dialog__body"></div>`, r.querySelector("h2").textContent = e, r.querySelector("[data-fruit-dialog-close]").setAttribute("aria-label", g("close-label", "Close")), r;
}
function v() {
	let e = document.createElement("p");
	e.className = "f-sr-only", e.setAttribute("role", "status"), e.textContent = g("loading-label", "Loading…");
	let t = document.createElement("div");
	return t.className = "f-skeleton", t.setAttribute("aria-hidden", "true"), t.innerHTML = "<div class=\"f-skeleton__line\"></div>".repeat(4), [e, t];
}
function y({ title: e, html: t, url: n, size: r = "medium", trigger: i = null } = {}) {
	if (typeof e != "string" || !e.trim()) throw Error("FruitUI dialog needs a title.");
	if (t === void 0 == (n === void 0)) throw Error("FruitUI dialog needs either html or url.");
	if (!h.includes(r)) throw Error(`FruitUI dialog size must be one of: ${h.join(", ")}.`);
	let a = _(e, r), o = a.querySelector(".f-dialog__body"), s = document.activeElement, c = new AbortController(), l, u, d, f = new Promise((e, t) => (l = e, u = t)), p = new Promise((e) => d = e);
	f.catch(() => {});
	let m = (e) => {
		typeof e == "string" ? o.innerHTML = e : o.replaceChildren(e);
		let t = o.lastElementChild;
		t?.matches(".f-dialog__footer") && a.append(t), o.removeAttribute("aria-busy"), l(o), a.dispatchEvent(new CustomEvent("fruit-dialog-loaded", {
			bubbles: !0,
			detail: {
				dialog: a,
				body: o,
				url: n,
				trigger: i
			}
		}));
	}, y = async () => {
		o.setAttribute("aria-busy", "true"), o.replaceChildren(...v());
		try {
			let e = await fetch(n, {
				headers: {
					Accept: "text/html",
					"X-Requested-With": "XMLHttpRequest"
				},
				credentials: "same-origin",
				signal: c.signal
			});
			if (!e.ok) throw Error(`FruitUI dialog could not load ${n}: ${e.status}.`);
			let t = await e.text();
			a.isConnected && m(t);
		} catch (e) {
			if (c.signal.aborted) return;
			o.removeAttribute("aria-busy");
			let t = document.createElement("p");
			t.className = "f-error", t.setAttribute("role", "alert"), t.textContent = g("error-message", "Could not load this content.");
			let r = document.createElement("button");
			r.type = "button", r.className = "f-button", r.textContent = g("retry-label", "Try Again"), r.addEventListener("click", y);
			let i = document.createElement("div");
			i.append(r), o.replaceChildren(t, i), a.dispatchEvent(new CustomEvent("fruit-dialog-error", {
				bubbles: !0,
				detail: {
					dialog: a,
					url: n,
					error: e
				}
			}));
		}
	};
	return a.querySelector("[data-fruit-dialog-close]").addEventListener("click", () => a.close("")), a.addEventListener("close", () => {
		c.abort(), u(/* @__PURE__ */ Error("FruitUI dialog closed before its content loaded.")), a.remove(), d(a.returnValue), s?.isConnected && typeof s.focus == "function" && s.focus();
	}), document.body.append(a), a.showModal(), n === void 0 ? m(t) : y(), {
		element: a,
		body: o,
		loaded: f,
		closed: p,
		close: (e) => a.close(e ?? "")
	};
}
function b(e = document) {
	let t = (e) => {
		let t = e.target.closest?.("[data-fruit-dialog-url]");
		if (!t || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
		let n = t.dataset.fruitDialogUrl, r = (n && n !== "data-fruit-dialog-url" ? n : "") || t.getAttribute("href");
		r && (e.preventDefault(), y({
			url: r,
			title: t.dataset.fruitDialogTitle || t.textContent.trim(),
			size: t.dataset.fruitDialogSize || "medium",
			trigger: t
		}));
	};
	return e.addEventListener("click", t), () => e.removeEventListener("click", t);
}
//#endregion
//#region src/js/dialog.js
function x(e = window) {
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
function S() {
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
var C = (e) => getComputedStyle(e).direction === "rtl";
function w(e, t, { stretch: n = !1, above: r = !1, point: i = null, start: a = !1 } = {}) {
	let o = typeof e.showPopover == "function", s = e.getAttribute("style"), c = !1;
	o && (e.popover = "manual");
	let l = () => {
		if (!c || !o) return;
		let s = t.getBoundingClientRect(), l = window.visualViewport, u = l?.offsetLeft || 0, d = l?.offsetTop || 0, f = l?.width || window.innerWidth, p = l?.height || window.innerHeight;
		e.style.position = "fixed", e.style.inset = "auto", e.style.margin = "0", e.style.transform = "none", e.style.maxWidth = `${Math.max(0, f - 16)}px`, e.style.maxHeight = `${Math.max(40, p - 16)}px`, e.style.overflowY = "auto", n && (e.style.width = `${Math.min(s.width, f - 16)}px`);
		let m = e.getBoundingClientRect(), h = i ? C(i) ? s.left - m.width : s.left : n || C(t) !== a ? s.left : s.right - m.width, g = d + p - s.bottom - 8, _ = s.top - d - 8, v = r && _ >= m.height || g < m.height && _ > g, y = v ? _ : g;
		e.style.maxHeight = `${Math.max(40, y)}px`;
		let b = e.getBoundingClientRect().height;
		e.style.left = `${Math.max(u + 8, Math.min(h, u + f - m.width - 8))}px`, e.style.top = `${Math.max(d + 8, Math.min(v ? s.top - b - 4 : s.bottom + 4, d + p - b - 8))}px`;
	}, u = () => {
		c = !0, o && (e.popover = "manual"), o && !e.matches(":popover-open") && e.showPopover(), l();
	}, d = () => {
		c = !1, o && e.matches(":popover-open") && e.hidePopover();
	};
	return window.addEventListener("resize", l), document.addEventListener("scroll", l, !0), window.visualViewport?.addEventListener("resize", l), window.visualViewport?.addEventListener("scroll", l), {
		show: u,
		hide: d,
		destroy() {
			d(), window.removeEventListener("resize", l), document.removeEventListener("scroll", l, !0), window.visualViewport?.removeEventListener("resize", l), window.visualViewport?.removeEventListener("scroll", l), o && e.removeAttribute("popover"), s === null ? e.removeAttribute("style") : e.setAttribute("style", s);
		}
	};
}
function T(e, t, { above: n = !1, owns: r = (t) => t?.closest("details") === e, onToggle: i } = {}) {
	let a = e.querySelector("summary"), o = w(t, a, { above: n }), s = new AbortController(), c = { signal: s.signal }, l = (t) => {
		e.open = !1, o.hide(), t && a.focus();
	};
	return e.addEventListener("toggle", (t) => {
		t.target === e && (e.open ? o.show() : o.hide(), i?.(e.open));
	}, c), document.addEventListener("pointerdown", (t) => {
		e.open && !e.contains(t.target) && l(!1);
	}, c), e.addEventListener("keydown", (t) => {
		t.key === "Escape" && e.open && r(t.target) && (t.preventDefault(), t.stopPropagation(), l(!0));
	}, c), e.open && o.show(), {
		trigger: a,
		show: () => o.show(),
		close: l,
		destroy() {
			s.abort(), o.destroy();
		}
	};
}
//#endregion
//#region src/js/splitter.js
function E({ pane: e, variable: t, min: n = 160, max: r = 420, reserve: i = 280, flexible: a, edge: s = "end" }) {
	if (!e || !a || e === a || !/^--f-[\w-]+$/.test(t) || !["start", "end"].includes(s) || ![
		n,
		r,
		i
	].every(Number.isFinite) || n <= 0 || r < n || i <= 0) throw Error("FruitUI splitter requires pane/flexible IDs, a --f- variable, positive bounds and start/end edge.");
	let c, l, u, d, f, p, m, h, g, _, v = (e) => !!e?.getClientRects().length && getComputedStyle(e).display !== "none";
	return {
		init() {
			if (c = this.$el, l = c.closest(".f-workspace"), u = l?.querySelector(`[id="${CSS.escape(e)}"]`), d = l?.querySelector(`[id="${CSS.escape(a)}"]`), !u || !d) throw Error("FruitUI splitter panes must belong to its workspace.");
			c.setAttribute("data-ready", ""), c.setAttribute("data-edge", s), c.setAttribute("aria-controls", e), g = Object.fromEntries(Object.entries({
				pointerdown: this.start,
				pointermove: this.move,
				pointerup: this.end,
				pointercancel: this.cancel,
				lostpointercapture: this.end,
				keydown: this.key,
				dblclick: this.reset
			}).map(([e, t]) => [e, t.bind(this)]));
			for (let [e, t] of Object.entries(g)) c.addEventListener(e, t);
			f = new ResizeObserver(() => {
				this.describe(), this.schedule();
			}), f.observe(l), f.observe(u), f.observe(d), p = new MutationObserver(() => this.schedule()), p.observe(l, { attributes: !0 }), this.describe(), this.schedule();
		},
		bounds() {
			let e = u.getBoundingClientRect().width, t = e + d.getBoundingClientRect().width - i;
			return {
				width: e,
				upper: Math.max(n, Math.floor(Math.min(r, t)))
			};
		},
		schedule() {
			cancelAnimationFrame(m), m = requestAnimationFrame(() => this.update());
		},
		update() {
			if (!v(c) || !v(u) || !v(d)) {
				h && this.cancel();
				return;
			}
			let { width: e, upper: t } = this.bounds();
			(e > t + 1 || e < n - 1) && this.set(e), this.describe();
		},
		describe() {
			if (!v(u) || !v(d)) return;
			let { width: e, upper: t } = this.bounds();
			c.setAttribute("aria-valuemin", Math.round(n)), c.setAttribute("aria-valuemax", Math.floor(t)), c.setAttribute("aria-valuenow", Math.round(e)), c.setAttribute("aria-valuetext", o(c, "value-text", "{count} pixels", { count: Math.round(e) }));
		},
		set(e) {
			let { upper: r } = this.bounds(), i = `${Math.round(Math.max(n, Math.min(r, e)))}px`;
			l.style.getPropertyValue(t) !== i && (l.style.setProperty(t, i), this.schedule());
		},
		start(e) {
			e.button === 0 && v(u) && v(d) && (e.preventDefault(), c.focus({ preventScroll: !0 }), h = {
				id: e.pointerId,
				x: e.clientX,
				width: u.getBoundingClientRect().width,
				previous: l.style.getPropertyValue(t)
			}, c.setPointerCapture(e.pointerId), c.setAttribute("data-resizing", ""), l.setAttribute("data-resizing", ""));
		},
		move(e) {
			if (!h || e.pointerId !== h.id) return;
			let t = (C(l) ? -1 : 1) * (s === "start" ? -1 : 1);
			this.set(h.width + (e.clientX - h.x) * t);
		},
		end(e = !0) {
			if (!h) return;
			let { id: t, width: n } = h;
			h = void 0, c.removeAttribute("data-resizing"), l.removeAttribute("data-resizing"), c.hasPointerCapture(t) && c.releasePointerCapture(t), e && Math.abs(u.getBoundingClientRect().width - n) >= 1 && this.commit();
		},
		cancel() {
			h && (h.previous ? l.style.setProperty(t, h.previous) : l.style.removeProperty(t), this.end(!1), this.schedule());
		},
		commit(n = 0) {
			clearTimeout(_), _ = setTimeout(() => {
				v(u) && c.dispatchEvent(new CustomEvent("fruit-resize", {
					bubbles: !0,
					detail: {
						pane: e,
						variable: t,
						value: Math.round(u.getBoundingClientRect().width)
					}
				}));
			}, n);
		},
		key(e) {
			if (e.key === "Escape" && h) {
				e.preventDefault(), e.stopPropagation(), this.cancel();
				return;
			}
			let { width: t, upper: r } = this.bounds(), i = (C(l) ? -1 : 1) * (s === "start" ? -1 : 1), a = e.shiftKey ? 32 : 8, o = {
				ArrowLeft: t - a * i,
				ArrowRight: t + a * i,
				Home: n,
				End: r
			};
			e.key in o && (e.preventDefault(), e.stopPropagation(), this.set(o[e.key]), this.commit(400));
		},
		reset() {
			l.style.removeProperty(t), this.schedule(), requestAnimationFrame(() => this.commit());
		},
		destroy() {
			clearTimeout(_), this.end(!1), f?.disconnect(), p?.disconnect(), cancelAnimationFrame(m);
			for (let [e, t] of Object.entries(g)) c.removeEventListener(e, t);
		}
	};
}
//#endregion
//#region src/js/suggestions.js
function D(e, t, { query: n, pick: r, filter: i = !0, exclude: a = () => !1, anchor: s = t, messages: c = {
	label: "label",
	count: "count-message"
} }) {
	let l = new AbortController(), u = (e, t, n, r = {}) => e.addEventListener(t, n, {
		...r,
		signal: l.signal
	}), f = document.createElement("ul");
	f.id = d("fruit-suggestions"), f.className = "f-autocomplete__options", f.setAttribute("role", "listbox"), f.setAttribute("aria-label", o(e, c.label, "Suggestions")), f.hidden = !0;
	let p = document.createElement("span");
	p.className = "f-sr-only", p.setAttribute("role", "status"), (e.querySelector("[data-fruit-ui]") ?? e).append(f, p);
	let m = w(f, s, { stretch: s.tagName !== "TEXTAREA" }), h = -1, g = [], _ = () => [...e.querySelector("datalist")?.options ?? []].filter((e) => !e.disabled && !a(e)), v = (e) => e.label || e.value, y = () => {
		h = -1, g = [], f.hidden = !0, m.hide(), t.removeAttribute("aria-activedescendant");
	}, b = (e) => {
		h = e, [...f.children].forEach((e, t) => e.setAttribute("aria-selected", String(t === h)));
		let n = f.children[h];
		n ? (t.setAttribute("aria-activedescendant", n.id), n.scrollIntoView({ block: "nearest" })) : t.removeAttribute("aria-activedescendant");
	}, x = (e) => {
		let t = g[e];
		y(), t && r(t);
	}, S = () => {
		let r = n();
		if (r === null || t.disabled || t.readOnly) return y();
		let a = r.toLocaleLowerCase(), s = (e) => `${v(e)} ${e.value}`.toLocaleLowerCase().split(/[\s@:/#._-]+/);
		if (g = i ? _().filter((e) => e.value.toLocaleLowerCase().startsWith(a) || s(e).some((e) => e.startsWith(a))).sort((e, t) => Number(!v(e).toLocaleLowerCase().startsWith(a)) - Number(!v(t).toLocaleLowerCase().startsWith(a))).slice(0, 8) : _(), !g.length) return p.textContent = o(e, "no-suggestions", "No suggestions"), y();
		f.replaceChildren(...g.map((e, t) => {
			let n = document.createElement("li");
			if (n.id = `${f.id}-${t}`, n.className = "f-autocomplete__option", n.setAttribute("role", "option"), n.textContent = v(e), e.label && e.label !== e.value) {
				let t = document.createElement("span");
				t.className = "f-autocomplete__detail", t.textContent = e.value, n.append(t);
			}
			return n.addEventListener("pointerdown", (e) => e.preventDefault()), n.addEventListener("click", () => x(t)), n;
		})), f.hidden = !1, m.show(), p.textContent = o(e, c.count, "{count} suggestions", { count: g.length }), b(0);
	}, C = () => {
		t.setAttribute("aria-autocomplete", "list"), t.setAttribute("aria-haspopup", "listbox"), t.setAttribute("aria-controls", f.id);
	};
	C();
	let T = new MutationObserver((e) => {
		(t.getAttribute("aria-controls") !== f.id || !t.hasAttribute("aria-haspopup")) && C(), e.some((e) => (e.target.nodeType === Node.ELEMENT_NODE ? e.target : e.target.parentElement)?.closest("datalist") || [...e.addedNodes, ...e.removedNodes].some((e) => e.nodeName === "DATALIST")) && document.activeElement === t && S();
	});
	return T.observe(t, {
		attributes: !0,
		attributeFilter: [
			"aria-autocomplete",
			"aria-haspopup",
			"aria-controls"
		]
	}), T.observe(e, {
		childList: !0,
		subtree: !0,
		characterData: !0,
		attributes: !0
	}), u(t, "input", (e) => {
		e.isComposing || S();
	}), u(t, "keydown", (e) => {
		e.isComposing || f.hidden || (e.key === "ArrowDown" || e.key === "ArrowUp" ? (e.preventDefault(), b((h + (e.key === "ArrowDown" ? 1 : -1) + g.length) % g.length)) : e.key === "Enter" || e.key === "Tab" ? (e.preventDefault(), e.stopImmediatePropagation(), x(h)) : e.key === "Escape" && (e.preventDefault(), e.stopImmediatePropagation(), y()));
	}, { capture: !0 }), u(t, "blur", y), u(t, "click", () => {
		f.hidden || S();
	}), {
		hide: y,
		destroy() {
			T.disconnect(), l.abort(), m.destroy(), f.remove(), p.remove();
			for (let e of [
				"aria-autocomplete",
				"aria-haspopup",
				"aria-controls",
				"aria-activedescendant"
			]) t.removeAttribute(e);
		}
	};
}
//#endregion
//#region src/js/autocomplete.js
function ee() {
	let e, t, n, r = () => e.dataset.fruitTrigger || "", i = () => {
		let e = t.selectionStart ?? t.value.length, n = t.value.slice(0, e), i = n.search(/[^\s,]*$/), a = n.slice(i), o = r();
		return o ? a.startsWith(o) ? {
			start: i,
			end: e,
			query: a.slice(o.length)
		} : null : a ? {
			start: i,
			end: e,
			query: a
		} : null;
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input:not([type=\"hidden\"]), textarea"), !t) return;
			let a = null;
			n = D(e, t, {
				query() {
					return a = i(), a && a.query;
				},
				pick(e) {
					let { start: i, end: o } = a, s = t.value, c = `${e.value}${r() ? " " : ""}`;
					p(t, s.slice(0, i) + c + s.slice(o));
					let l = i + c.length;
					t.setSelectionRange?.(l, l), t.focus(), n.hide();
				}
			});
		},
		destroy() {
			n?.destroy();
		}
	};
}
//#endregion
//#region src/js/command-palette.js
function te() {
	let e, t, n, r, i, a, o = -1, s = () => [...n.querySelectorAll("[role=\"option\"]")].filter((e) => !e.hidden && !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true"), c = (e) => {
		let r = s();
		o = r.length ? (e + r.length) % r.length : -1;
		for (let e of n.querySelectorAll("[role=\"option\"]")) e.setAttribute("aria-selected", "false");
		let i = r[o];
		if (!i) return t.removeAttribute("aria-activedescendant");
		f(i, "fruit-command"), i.setAttribute("aria-selected", "true"), t.setAttribute("aria-activedescendant", i.id), i.scrollIntoView({ block: "nearest" });
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
var O = (e, t) => {
	let n = (e.accept || "").split(",").map((e) => e.trim().toLowerCase()).filter(Boolean);
	if (!n.length) return !0;
	let r = t.name.toLowerCase(), i = (t.type || "").toLowerCase();
	return n.some((e) => e.startsWith(".") ? r.endsWith(e) : e.endsWith("/*") ? i.startsWith(e.slice(0, -1)) : i === e);
};
function ne() {
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
				let r = [...e.dataTransfer.files].filter((e) => O(n, e));
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
var k = (e) => String(e).padStart(2, "0"), A = (e) => `${String(e.getFullYear()).padStart(4, "0")}-${k(e.getMonth() + 1)}-${k(e.getDate())}`, j = (e) => {
	let t = /^(\d{4,})-(\d{2})-(\d{2})/.exec(e || "");
	return t ? new Date(Number(t[1]), Number(t[2]) - 1, Number(t[3])) : null;
}, M = (e, t) => new Date(e.getFullYear(), e.getMonth(), e.getDate() + t), N = (e, t) => {
	let n = new Date(e.getFullYear(), e.getMonth() + t, 1), r = new Date(n.getFullYear(), n.getMonth() + 1, 0).getDate();
	return n.setDate(Math.min(e.getDate(), r)), n;
}, P = (e, t) => !!(e && t) && A(e) === A(t), F = (e) => e.closest("[lang]")?.lang || navigator.language || "en";
function I(e) {
	try {
		let t = new Intl.Locale(e), n = t.getWeekInfo?.() ?? t.weekInfo;
		if (n?.firstDay) return n.firstDay % 7;
	} catch {}
	return +!/^en(-US|-CA)?$/i.test(e);
}
function L(e, t, n, { onOpen: r, onFocus: i }) {
	let a = w(n, t, { start: !0 }), o = new AbortController(), s = (e, t, n) => e.addEventListener(t, n, { signal: o.signal }), c = () => !n.hidden, l = () => {
		t.setAttribute("aria-haspopup", "dialog"), t.setAttribute("aria-controls", n.id);
	}, u = (e) => {
		c() && (a.hide(), n.hidden = !0, l(), e && t.focus());
	}, d = (e) => {
		t.disabled || t.readOnly || (n.hidden = !1, r(), a.show(), l(), e && i());
	};
	l();
	let f = new MutationObserver(() => {
		(t.getAttribute("aria-controls") !== n.id || !t.hasAttribute("aria-haspopup")) && l();
	});
	return f.observe(t, {
		attributes: !0,
		attributeFilter: ["aria-haspopup", "aria-controls"]
	}), s(document, "pointerdown", (t) => {
		c() && !e.contains(t.target) && !n.contains(t.target) && u(!1);
	}), s(e, "focusout", (t) => {
		c() && t.relatedTarget && !e.contains(t.relatedTarget) && !n.contains(t.relatedTarget) && u(!1);
	}), s(n, "keydown", (e) => {
		e.key === "Escape" && (e.preventDefault(), e.stopPropagation(), u(!0));
	}), {
		listen: s,
		open: d,
		close: u,
		isOpen: c,
		destroy() {
			f.disconnect(), o.abort(), a.destroy();
		}
	};
}
function re() {
	let e, t, n, r, i, a, s = /* @__PURE__ */ new Date(), c = /* @__PURE__ */ new Date(), l = () => j(t.value), u = () => [j(t.min), j(t.max)], f = (e) => {
		let [t, n] = u();
		return !!(t && e < t || n && e > n);
	}, m = (e) => {
		let [t, n] = u();
		return t && e < t ? t : n && e > n ? n : e;
	}, h = () => {
		let e = F(t);
		r.textContent = new Intl.DateTimeFormat(e, {
			month: "long",
			year: "numeric"
		}).format(s);
		let n = M(s, -((s.getDay() - I(e) + 7) % 7)), a = new Intl.DateTimeFormat(e, { weekday: "narrow" }), o = new Intl.DateTimeFormat(e, { weekday: "long" }), u = new Intl.DateTimeFormat(e, { dateStyle: "full" }), d = document.createElement("tr");
		for (let e = 0; e < 7; e++) {
			let t = M(n, e), r = document.createElement("th");
			r.scope = "col", r.abbr = o.format(t), r.textContent = a.format(t), d.append(r);
		}
		let p = [];
		for (let e = 0; e < 6; e++) {
			let t = document.createElement("tr");
			for (let r = 0; r < 7; r++) {
				let i = M(n, e * 7 + r), a = document.createElement("td");
				a.setAttribute("aria-selected", String(P(i, l())));
				let o = document.createElement("button");
				o.type = "button", o.className = "f-calendar__day", o.tabIndex = P(i, c) ? 0 : -1, o.textContent = String(i.getDate()), o.dataset.date = A(i), o.setAttribute("aria-label", u.format(i)), i.getMonth() !== s.getMonth() && (o.dataset.outside = ""), P(i, /* @__PURE__ */ new Date()) && o.setAttribute("aria-current", "date"), f(i) && o.setAttribute("aria-disabled", "true"), a.append(o), t.append(a);
			}
			p.push(t);
		}
		i.tHead.replaceChildren(d), i.tBodies[0].replaceChildren(...p);
	}, g = (e, t = !0) => {
		c = e, s = new Date(e.getFullYear(), e.getMonth(), 1), h(), t && i.querySelector(`[data-date="${A(e)}"]`)?.focus();
	}, _ = (e) => {
		if (f(e)) return;
		let n = A(e);
		if (t.type === "datetime-local") {
			let e = /* @__PURE__ */ new Date();
			n += `T${t.value.split("T")[1] || `${k(e.getHours())}:${k(e.getMinutes())}`}`;
		}
		p(t, n), a.close(!0);
	}, v = (e, n) => {
		let r = C(t) ? -1 : 1;
		return {
			ArrowLeft: () => M(n, -r),
			ArrowRight: () => M(n, r),
			ArrowUp: () => M(n, -7),
			ArrowDown: () => M(n, 7),
			Home: () => M(n, -((n.getDay() - I(F(t)) + 7) % 7)),
			End: () => M(n, 6 - (n.getDay() - I(F(t)) + 7) % 7),
			PageUp: () => N(n, e.shiftKey ? -12 : -1),
			PageDown: () => N(n, e.shiftKey ? 12 : 1)
		}[e.key]?.();
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input[type=\"date\"], input[type=\"datetime-local\"]"), !t) return;
			n = document.createElement("div"), n.id = d("fruit-calendar"), n.className = "f-calendar", n.setAttribute("role", "dialog"), n.setAttribute("aria-label", o(e, "label", "Choose Date")), n.hidden = !0;
			let u = document.createElement("div");
			u.className = "f-calendar__header", r = document.createElement("div"), r.className = "f-calendar__title", r.id = `${n.id}-title`, r.setAttribute("aria-live", "polite");
			let f = (t, n, r) => {
				let i = document.createElement("button");
				return i.type = "button", i.className = "f-calendar__nav", i.dataset.direction = t, i.setAttribute("aria-label", o(e, n, r)), i.addEventListener("click", () => {
					c = m(N(c, t === "next" ? 1 : -1)), s = new Date(c.getFullYear(), c.getMonth(), 1), h();
				}), i;
			};
			u.append(r, f("previous", "previous-label", "Previous Month"), f("next", "next-label", "Next Month")), i = document.createElement("table"), i.className = "f-calendar__grid", i.setAttribute("role", "grid"), i.setAttribute("aria-labelledby", r.id), i.append(document.createElement("thead"), document.createElement("tbody")), n.append(u, i), (e.querySelector("[data-fruit-ui]") ?? e).append(n), a = L(e, t, n, {
				onOpen: () => g(m(l() ?? /* @__PURE__ */ new Date()), !1),
				onFocus: () => i.querySelector(".f-calendar__day[tabindex=\"0\"]")?.focus()
			}), e.setAttribute("data-ready", ""), a.listen(t, "click", (e) => {
				e.preventDefault(), a.isOpen() || a.open(!1);
			}), a.listen(t, "keydown", (e) => {
				e.altKey && ["ArrowDown", "ArrowUp"].includes(e.key) ? (e.preventDefault(), e.key === "ArrowUp" ? a.close(!0) : a.isOpen() ? i.querySelector(".f-calendar__day[tabindex=\"0\"]")?.focus() : a.open(!0)) : e.key === "Escape" && a.isOpen() && (e.preventDefault(), e.stopPropagation(), a.close(!1));
			}), a.listen(t, "input", () => {
				let e = l();
				a.isOpen() && e && g(e, !1);
			}), a.listen(i, "click", (e) => {
				let t = e.target.closest(".f-calendar__day");
				t && _(j(t.dataset.date));
			}), a.listen(i, "keydown", (e) => {
				let t = e.target.closest(".f-calendar__day");
				if (!t) return;
				let n = v(e, j(t.dataset.date));
				n && (e.preventDefault(), g(n));
			});
		},
		destroy() {
			a?.destroy(), n?.remove(), e?.removeAttribute("data-ready");
		}
	};
}
var ie = [
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
], R = 7, ae = (e) => [
	1,
	3,
	5
].map((t) => parseInt(e.slice(t, t + 2), 16) / 255);
function z(e) {
	let [t, n, r] = ae(e), i = Math.max(t, n, r), a = i - Math.min(t, n, r), o = 0;
	return a && (o = i === t ? (n - r) / a % 6 : i === n ? (r - t) / a + 2 : (t - n) / a + 4), [
		(o * 60 + 360) % 360,
		i ? a / i : 0,
		i
	];
}
function B(e, t, n) {
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
function V() {
	let e, t, n, r, i, a, s, c, l, u, f, m = [
		0,
		0,
		0
	], h = () => {
		let e = [...t.list?.options ?? []].filter((e) => /^#[0-9a-f]{6}$/i.test(e.value)).map((e) => [e.label || e.value, e.value.toLowerCase()]);
		return e.length ? e : ie;
	}, g = () => [...r.querySelectorAll("[role=\"option\"]")], _ = () => {
		let e = t.value.toLowerCase();
		r.replaceChildren(...h().map(([t, n]) => {
			let r = document.createElement("div");
			r.className = "f-swatch", r.setAttribute("role", "option"), r.tabIndex = -1, r.title = t, r.dataset.value = n, r.style.setProperty("--f-swatch-color", n), r.setAttribute("aria-selected", String(n === e));
			let i = document.createElement("span");
			return i.className = "f-sr-only", i.textContent = t, r.append(i), r;
		}));
	}, v = () => {
		let [n, r, i] = m;
		a.style.setProperty("--f-picker-hue", B(n, 1, 1)), c.style.left = `${r * 100}%`, c.style.top = `${(1 - i) * 100}%`;
		let d = (e) => Math.round(e * 100);
		s.setAttribute("aria-valuenow", String(d(r))), s.setAttribute("aria-valuetext", o(e, "area-text", "Saturation {saturation}%, brightness {brightness}%", {
			saturation: d(r),
			brightness: d(i)
		})), l.value = String(Math.round(n)), document.activeElement !== u && (u.value = t.value);
	}, y = () => {
		let [e, n, r] = z(t.value);
		m = [
			n && r ? e : m[0],
			n,
			r
		], v();
	}, b = (e, n) => {
		m = e, p(t, B(...m), { commit: n }), v();
	}, x = (e) => {
		p(t, e.dataset.value), f.close(!0);
	}, S = (e, t, n = {}) => {
		let r = document.createElement(e);
		r.className = t;
		for (let [e, t] of Object.entries(n)) r.setAttribute(e, t);
		return r;
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input[type=\"color\"]"), !t) return;
			n = S("div", "f-color-palette", {
				id: d("fruit-colors"),
				role: "dialog",
				"aria-label": o(e, "label", "Choose Color")
			}), n.hidden = !0, r = S("div", "f-color-palette__swatches", {
				role: "listbox",
				"aria-label": o(e, "colors-label", "Colors")
			}), r.style.setProperty("--f-swatch-columns", String(R)), i = S("button", "f-button f-button--ghost f-button--small f-color-palette__other", {
				type: "button",
				"aria-expanded": "false"
			}), i.textContent = o(e, "other-label", "Other…"), a = S("div", "f-color-editor"), a.hidden = !0, a.style.setProperty("--f-picker-black", "#000"), a.style.setProperty("--f-picker-white", "#fff"), a.style.setProperty("--f-picker-spectrum", "linear-gradient(90deg, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00)"), s = S("div", "f-color-editor__area", {
				role: "slider",
				tabindex: "0",
				"aria-label": o(e, "area-label", "Saturation and brightness"),
				"aria-valuemin": "0",
				"aria-valuemax": "100"
			}), c = S("span", "f-color-editor__thumb", { "aria-hidden": "true" }), s.append(c), l = S("input", "f-range f-color-editor__hue", {
				type: "range",
				min: "0",
				max: "359",
				"aria-label": o(e, "hue-label", "Hue")
			});
			let h = S("label", "f-color-editor__hex"), v = S("span", "f-label");
			v.textContent = o(e, "hex-label", "Hex"), u = S("input", "f-input", {
				type: "text",
				maxlength: "7",
				spellcheck: "false",
				autocomplete: "off"
			}), h.append(v, u), a.append(s, l, h), i.setAttribute("aria-controls", a.id = `${n.id}-editor`), n.append(r, i, a), _(), (e.querySelector("[data-fruit-ui]") ?? e).append(n), f = L(e, t, n, {
				onOpen: () => {
					_(), a.hidden = !0, i.hidden = !1, i.setAttribute("aria-expanded", "false");
				},
				onFocus: () => {
					let e = g();
					(e.find((e) => e.getAttribute("aria-selected") === "true") ?? e[0])?.focus();
				}
			}), e.setAttribute("data-ready", ""), f.listen(t, "click", (e) => {
				e.preventDefault(), f.isOpen() ? f.close(!1) : f.open(!0);
			}), f.listen(t, "input", () => {
				a.hidden || y();
			}), f.listen(r, "click", (e) => {
				let t = e.target.closest("[role=\"option\"]");
				t && x(t);
			}), f.listen(r, "keydown", (e) => {
				let n = g(), r = n.indexOf(document.activeElement);
				if (r < 0) return;
				let i = C(t) ? -1 : 1, a = {
					ArrowRight: r + i,
					ArrowLeft: r - i,
					ArrowDown: r + R,
					ArrowUp: r - R,
					Home: 0,
					End: n.length - 1
				};
				e.key in a ? (e.preventDefault(), n[Math.max(0, Math.min(n.length - 1, a[e.key]))].focus()) : (e.key === "Enter" || e.key === " ") && (e.preventDefault(), x(n[r]));
			}), f.listen(i, "click", () => {
				a.hidden = !1, i.hidden = !0, i.setAttribute("aria-expanded", "true"), y(), s.focus();
			});
			let w = (e, t) => {
				let n = s.getBoundingClientRect(), r = (e) => Math.max(0, Math.min(1, e));
				b([
					m[0],
					r((e.clientX - n.left) / n.width),
					1 - r((e.clientY - n.top) / n.height)
				], t);
			}, T = !1;
			f.listen(s, "pointerdown", (e) => {
				e.button === 0 && (e.preventDefault(), s.focus(), s.setPointerCapture(e.pointerId), T = !0, w(e, !1));
			}), f.listen(s, "pointermove", (e) => {
				T && w(e, !1);
			}), f.listen(s, "pointerup", (e) => {
				T && (T = !1, w(e, !0));
			}), f.listen(s, "keydown", (e) => {
				let t = e.shiftKey ? .1 : .01, n = {
					ArrowLeft: [-t, 0],
					ArrowRight: [t, 0],
					ArrowDown: [0, -t],
					ArrowUp: [0, t]
				}[e.key];
				if (!n) return;
				e.preventDefault();
				let r = (e) => Math.max(0, Math.min(1, e));
				b([
					m[0],
					r(m[1] + n[0]),
					r(m[2] + n[1])
				], !0);
			}), f.listen(l, "input", () => b([
				Number(l.value),
				m[1],
				m[2]
			], !1)), f.listen(l, "change", () => b([
				Number(l.value),
				m[1],
				m[2]
			], !0)), f.listen(u, "input", () => {
				let e = u.value.trim().toLowerCase(), n = /^#?[0-9a-f]{6}$/.test(e) ? `#${e.replace("#", "")}` : null;
				n && (p(t, n), y());
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
var H = Symbol.for("fruitui.editor");
function U(e, t, { insert: n, set: r, commit: i }) {
	let a = new AbortController(), o = (e) => {
		let { html: a = "", target: o } = e.detail ?? {};
		(e.currentTarget !== window || o === t.id || o === t.name) && (t.matches(":disabled") || t.readOnly || ((e.type === "fruit-editor-set" ? r : n)(String(a)), i()));
	};
	for (let t of ["fruit-editor-insert", "fruit-editor-set"]) e.addEventListener(t, o, { signal: a.signal }), window.addEventListener(t, o, { signal: a.signal });
	return () => a.abort();
}
function W() {
	let e;
	return {
		init() {
			let t = this.$el.querySelector("textarea[data-fruit-control]");
			t && (e = U(this.$el, t, {
				insert: (e) => {
					let n = t.selectionStart ?? t.value.length, r = t.selectionEnd ?? n;
					p(t, t.value.slice(0, n) + e + t.value.slice(r), { commit: !1 }), t.setSelectionRange?.(n + e.length, n + e.length);
				},
				set: (e) => p(t, e, { commit: !1 }),
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
function G() {
	let e, t, n, r, i, a, s, c, l, u = [], f = -1, h = !1, g = () => t.selectedOptions[0]?.label || "", _ = () => {
		h = !1, l.hide(), r.hidden = !0, n.setAttribute("aria-expanded", "false"), n.removeAttribute("aria-activedescendant");
	}, v = (e) => {
		f = e, [...r.children].forEach((e, t) => e.setAttribute("aria-selected", String(t === f))), r.children[f] ? (n.setAttribute("aria-activedescendant", r.children[f].id), r.children[f].scrollIntoView({ block: "nearest" })) : n.removeAttribute("aria-activedescendant");
	}, y = (i = "") => {
		if (n.disabled) return;
		let a = e.dataset.fruitSearch === "server";
		if (u = [...t.options].filter((e) => !e.matches(":disabled") && !e.hidden && (a || e.label.toLocaleLowerCase().includes(i.toLocaleLowerCase()))), r.replaceChildren(), u.forEach((e, t) => {
			let n = document.createElement("li");
			n.className = "f-combobox__option", n.id = `${r.id}-${t}`, n.setAttribute("role", "option"), n.textContent = e.label, n.addEventListener("pointerdown", (e) => e.preventDefault()), n.addEventListener("click", () => b(t)), r.append(n);
		}), !u.length) {
			let t = document.createElement("li");
			t.className = "f-combobox__empty", t.setAttribute("role", "presentation"), t.textContent = o(e, "no-matches", "No matches"), r.append(t);
		}
		h = !0, r.hidden = !1, n.setAttribute("aria-expanded", "true"), l.show();
		let s = u.findIndex((e) => e.selected);
		v(u.length ? Math.max(0, s) : -1);
	}, b = (e) => {
		u[e] && (p(t, u[e].value), n.value = g(), n.removeAttribute("aria-invalid"), _(), n.focus());
	};
	return {
		init() {
			e = this.$el, t = e.querySelector("select[data-fruit-control]"), !(!t || t.multiple || t.size > 1) && (n = document.createElement("input"), n.type = "text", n.className = "f-input", n.autocomplete = "off", n.setAttribute("role", "combobox"), n.setAttribute("aria-autocomplete", "list"), n.setAttribute("aria-expanded", "false"), r = document.createElement("ul"), r.id = d("fruit-options"), r.className = "f-combobox__options", r.setAttribute("role", "listbox"), r.hidden = !0, i = this.$el.querySelector("[data-fruit-ui]"), a = !i, i || (i = document.createElement("div"), i.setAttribute("data-fruit-ui", ""), this.$el.append(i)), n.setAttribute("aria-controls", r.id), i.append(n, r), t.hidden = !0, l = w(r, n, { stretch: !0 }), n.value = g(), n.addEventListener("input", (e) => {
				e.isComposing || t.dispatchEvent(new CustomEvent("fruit-suggest", {
					bubbles: !0,
					detail: { query: n.value.trim() }
				})), y(n.value);
			}), n.addEventListener("click", () => y()), n.addEventListener("blur", () => {
				_(), n.value = g();
			}), n.addEventListener("keydown", (e) => {
				e.isComposing || (["ArrowDown", "ArrowUp"].includes(e.key) ? (e.preventDefault(), h ? u.length && v((f + (e.key === "ArrowDown" ? 1 : -1) + u.length) % u.length) : (y(), v(e.key === "ArrowUp" ? u.length - 1 : 0))) : e.key === "Enter" && h && f >= 0 ? (e.preventDefault(), b(f)) : e.key === "Escape" && h ? (e.preventDefault(), e.stopPropagation(), _(), n.value = g()) : e.key === "Tab" && _());
			}), c = (e) => {
				this.$el.contains(e.target) || _();
			}, document.addEventListener("pointerdown", c), s = m(this, t, n, (e) => {
				h && ["value", "reset"].includes(e) && _(), ([
					"initial",
					"value",
					"reset"
				].includes(e) || !h) && (n.value = g()), h && e === "options" && y(n.value);
				for (let e of ["aria-label", "aria-labelledby"]) n.hasAttribute(e) ? r.setAttribute(e, n.getAttribute(e)) : r.removeAttribute(e);
				n.disabled && _();
			}));
		},
		destroy() {
			s?.(), l?.destroy(), document.removeEventListener("pointerdown", c), n?.remove(), r?.remove(), a && i?.remove(), t && (t.hidden = !1);
		}
	};
}
function K() {
	let e, t, n, r, i, a, s, c, l, u, d, f, h = [], g = () => {
		f && (t.hasAttribute("name") && (d = t.name.replace(/\[\]$/, ""), t.removeAttribute("name")), f.replaceChildren(...h.map((e) => {
			let n = document.createElement("input");
			return n.type = "hidden", n.name = `${d}[]`, n.value = e, n.disabled = t.matches(":disabled"), t.hasAttribute("form") && n.setAttribute("form", t.getAttribute("form")), n;
		})));
	}, _ = () => t.value.split(/\r?\n/).map((e) => e.trim()).filter(Boolean), v = (e) => {
		i.textContent = e;
	}, y = () => {
		h = _(), n.querySelectorAll(".f-chip").forEach((e) => e.remove());
		for (let [i, a] of h.entries()) {
			let s = document.createElement("span");
			s.className = "f-chip";
			let c = document.createElement("span");
			c.textContent = a;
			let l = document.createElement("button");
			l.type = "button", l.className = "f-chip__remove", l.textContent = "×", l.setAttribute("aria-label", o(e, "remove-label", "Remove {value}", { value: a })), l.disabled = r.disabled || r.readOnly, l.addEventListener("click", () => {
				p(t, h.filter((e, t) => t !== i).join("\n")), v(o(e, "removed-message", "Removed {value}", { value: a })), r.focus();
			}), l.addEventListener("keydown", (e) => {
				let t = [...n.querySelectorAll("button")];
				(e.key === "ArrowLeft" || e.key === "ArrowRight") && (e.preventDefault(), (t[i + (e.key === "ArrowLeft" === C(n) ? 1 : -1)] || r).focus()), e.key === "Escape" && (e.preventDefault(), r.focus());
			}), s.append(c, l), n.insertBefore(s, r);
		}
		g();
	}, b = (n) => {
		if (r.disabled || r.readOnly) return !1;
		let i = [...h];
		for (let a of n.split(/[,\n]/).map((e) => e.trim()).filter(Boolean)) {
			let n = {
				value: a,
				error: o(e, "invalid-message", "Check this value before adding it.")
			};
			if (!t.dispatchEvent(new CustomEvent("fruit-token-add", {
				bubbles: !0,
				cancelable: !0,
				detail: n
			}))) return r.setCustomValidity(n.error), r.setAttribute("aria-invalid", "true"), v(n.error), !1;
			let s = String(n.value).trim();
			s && !i.includes(s) && i.push(s);
		}
		if (t.maxLength >= 0 && i.join("\n").length > t.maxLength) {
			let n = o(e, "length-message", "Use at most {count} characters.", { count: t.maxLength });
			return r.setCustomValidity(n), r.setAttribute("aria-invalid", "true"), v(n), !1;
		}
		return p(t, i.join("\n")), r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid"), v(o(e, "count-message", "{count} items", { count: i.length })), !0;
	};
	return {
		init() {
			e = this.$el, t = e.querySelector("textarea[data-fruit-control]"), t && (n = document.createElement("div"), n.className = "f-token-field__entry", r = document.createElement("input"), r.type = "text", r.autocomplete = "off", r.placeholder = o(e, "placeholder", "Add an item"), i = document.createElement("span"), i.className = "f-sr-only", i.setAttribute("role", "status"), a = this.$el.querySelector("[data-fruit-ui]"), s = !a, a || (a = document.createElement("div"), a.setAttribute("data-fruit-ui", ""), this.$el.append(a)), n.append(r), a.append(n, i), t.hidden = !0, e.dataset.fruitSubmit === "list" && t.name && (d = t.name.replace(/\[\]$/, ""), t.removeAttribute("name"), f = document.createElement("div"), f.hidden = !0, a.append(f)), e.querySelector("datalist") && (u = D(e, r, {
				query: () => r.value.trim() || null,
				pick: (e) => {
					b(e.value), r.focus();
				},
				filter: e.dataset.fruitSearch !== "server",
				exclude: (e) => h.includes(e.value),
				anchor: n,
				messages: {
					label: "suggestions-label",
					count: "suggestions-count-message"
				}
			})), l = () => {
				r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid"), i.textContent = "", y();
			}, t.addEventListener("fruit-token-reset", l), r.addEventListener("keydown", (e) => {
				e.isComposing || (e.key === "Enter" || e.key === "," ? (e.preventDefault(), b(r.value)) : (e.key === "Backspace" || e.key === "ArrowLeft") && !r.value ? n.querySelector(".f-chip:last-of-type button")?.focus() : e.key === "Escape" ? (r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid")) : e.key === "Tab" && r.value.trim() && b(r.value));
			}), r.addEventListener("input", (e) => {
				r.setCustomValidity(""), r.removeAttribute("aria-invalid"), e.isComposing || t.dispatchEvent(new CustomEvent("fruit-suggest", {
					bubbles: !0,
					detail: { query: r.value.trim() }
				}));
			}), r.addEventListener("change", () => {
				r.value.trim() && b(r.value);
			}), r.addEventListener("paste", (e) => {
				let t = e.clipboardData?.getData("text");
				t && /[,\n]/.test(t) && (e.preventDefault(), b(t));
			}), c = m(this, t, r, (e) => {
				y(), ["value", "reset"].includes(e) && (r.value = "", i.textContent = "");
			}, {
				presentation: n,
				focusRoot: n
			}));
		},
		destroy() {
			c?.(), u?.destroy(), f && (f.remove(), t.name = d), t?.removeEventListener("fruit-token-reset", l), n?.remove(), i?.remove(), s && a?.remove(), t && (t.hidden = !1);
		}
	};
}
function q() {
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
var J = "[role=\"menuitem\"], [role=\"menuitemcheckbox\"], [role=\"menuitemradio\"]", Y = (e, t) => [...e.querySelectorAll(J)].filter((e) => t(e) && !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true" && e.getClientRects().length), X = (e, t) => {
	e.length && e[(t + e.length) % e.length].focus();
}, oe = (e) => [...e.childNodes].filter((e) => !e.classList?.contains("f-menu-item__shortcut")).map((e) => e.textContent).join("").trim().toLocaleLowerCase();
function Z(e, t, n) {
	let r = t.indexOf(document.activeElement);
	return [
		"ArrowDown",
		"ArrowUp",
		"Home",
		"End"
	].includes(e.key) ? (e.preventDefault(), X(t, e.key === "Home" ? 0 : e.key === "End" ? t.length - 1 : r + (e.key === "ArrowDown" ? 1 : -1)), !0) : e.key.length === 1 && e.key !== " " && !e.ctrlKey && !e.metaKey && !e.altKey && (e.preventDefault(), clearTimeout(n.timer), n.search += e.key.toLocaleLowerCase(), [...t.slice(r + 1), ...t.slice(0, r + 1)].find((e) => oe(e).startsWith(n.search))?.focus(), n.timer = setTimeout(() => {
		n.search = "";
	}, 600), !0);
}
function se() {
	let e, t, n, r, i, a, o, s = {
		search: "",
		timer: null
	}, c = (t) => t?.closest("[data-fruit-menu], [x-data^=\"fruitMenu\"]") === e, l = () => Y(n, c), u = (e) => X(l(), e);
	return {
		init() {
			e = this.$el, e.setAttribute("data-fruit-menu", ""), t = e.querySelector("summary"), n = e.querySelector("[role=\"menu\"]"), t && n && (r = T(e, n, {
				above: e.classList.contains("f-menu--above"),
				owns: c,
				onToggle: (e) => {
					t.setAttribute("aria-expanded", String(e)), e && document.activeElement === t && u(0);
				}
			}), f(n, "fruit-menu"), t.setAttribute("aria-haspopup", "menu"), t.setAttribute("aria-controls", n.id), t.setAttribute("aria-expanded", String(e.open)), n.querySelectorAll(J).forEach((e) => {
				c(e) && (e.tabIndex = -1);
			}), i = (n) => {
				if (!c(n.target)) return;
				let i = l();
				n.target === t && ["ArrowDown", "ArrowUp"].includes(n.key) ? (n.preventDefault(), e.open = !0, r.show(), u(n.key === "ArrowDown" ? 0 : i.length - 1)) : e.open && n.key === "Tab" ? o = setTimeout(() => r.close(!1), 0) : e.open && Z(n, i, s);
			}, a = (e) => {
				let t = e.target.closest(J);
				c(t) && !t.matches(":disabled") && t.getAttribute("aria-disabled") !== "true" && r.close(!0);
			}, e.addEventListener("keydown", i), n.addEventListener("click", a));
		},
		destroy() {
			clearTimeout(o), clearTimeout(s.timer), r?.destroy(), e?.removeEventListener("keydown", i), n?.removeEventListener("click", a);
		}
	};
}
function ce() {
	let e, t, n, r, i, a, o = {
		x: 0,
		y: 0
	}, s = {
		search: "",
		timer: null
	}, c = (t) => t?.closest("[data-fruit-menu]") === e, l = () => !e.hidden, u = (r) => {
		l() && (n.hide(), e.hidden = !0, t.removeAttribute("data-fruit-context-open"), r && i?.isConnected && i.focus());
	}, d = (r, a, s) => {
		i = s, o = {
			x: r,
			y: a
		}, e.hidden = !1, t.setAttribute("data-fruit-context-open", ""), n.show(), X(Y(e, c), 0);
	};
	return {
		init() {
			if (e = this.$el, t = e.parentElement, !t) return;
			e.setAttribute("data-fruit-menu", ""), f(e, "fruit-context-menu"), e.hidden = !0, e.querySelectorAll(J).forEach((e) => {
				c(e) && (e.tabIndex = -1);
			}), n = w(e, { getBoundingClientRect: () => new DOMRect(o.x, o.y, 0, 0) }, { point: t }), r = new AbortController();
			let i = (e, t, n) => e.addEventListener(t, n, { signal: r.signal }), p = !1;
			i(t, "contextmenu", (t) => {
				e.contains(t.target) || (t.preventDefault(), !p && d(t.clientX, t.clientY, document.activeElement));
			}), i(t, "keydown", (n) => {
				if (e.contains(n.target) || !(n.key === "F10" && n.shiftKey || n.key === "ContextMenu")) return;
				n.preventDefault(), p = !0, a = setTimeout(() => p = !1, 0);
				let r = n.target.getBoundingClientRect();
				d(C(t) ? r.right : r.left, r.bottom, n.target);
			}), i(e, "keydown", (t) => {
				c(t.target) && (t.key === "Escape" ? (t.preventDefault(), t.stopPropagation(), u(!0)) : t.key === "Tab" ? (t.preventDefault(), u(!0)) : Z(t, Y(e, c), s));
			}), i(e, "click", (e) => {
				let t = e.target.closest(J);
				c(t) && !t.matches(":disabled") && t.getAttribute("aria-disabled") !== "true" && u(!0);
			}), i(document, "pointerdown", (t) => {
				l() && !e.contains(t.target) && u(!1);
			}), i(window, "blur", () => u(!1));
		},
		destroy() {
			clearTimeout(a), clearTimeout(s.timer), r?.abort(), n?.destroy(), t?.removeAttribute("data-fruit-context-open");
		}
	};
}
function le() {
	let e, t, n, r, i, a, o;
	return {
		init() {
			e = this.$el;
			let s = e.querySelector("[role=\"tooltip\"]");
			s && (a = w(s, e.firstElementChild));
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
function ue() {
	let e, t, n, r, i = (t) => t?.closest("[data-fruit-tabs], [x-data^=\"fruitTabs\"]") === e, a = () => [...e.querySelectorAll("[role=\"tab\"]")].filter(i), o = () => a().filter((e) => !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true"), s = (t) => {
		for (let n of a()) {
			let r = n === t;
			n.setAttribute("aria-selected", String(r)), n.tabIndex = r ? 0 : -1;
			let a = [...e.querySelectorAll("[role=\"tabpanel\"]")].find((e) => i(e) && e.id === n.getAttribute("aria-controls"));
			a && (a.hidden = !r);
		}
	}, c = () => s(o().find((e) => e.getAttribute("aria-selected") === "true") || o()[0]);
	return {
		init() {
			e = this.$el, e.setAttribute("data-fruit-tabs", ""), c(), n = (e) => {
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
				let i = C(e), a = t.key === "Home" ? 0 : t.key === "End" ? n.length - 1 : (r + (t.key === "ArrowRight" === i ? -1 : 1) + n.length) % n.length;
				s(n[a]), n[a].focus();
			}, e.addEventListener("click", n), e.addEventListener("keydown", t), r = new MutationObserver(c), r.observe(e, {
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
function de() {
	let e;
	return {
		init() {
			let t = this.$el, n = t.querySelector(".f-floating-disclosure__content");
			t.querySelector("summary") && n && (e = T(t, n, {
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
	e[H] || e.data("fruitEditor", W), e.data("fruitToast", n), e.magic("toast", () => t), e.data("fruitConfirmer", a), e.magic("confirm", () => i), e.data("fruitCopy", c), e.data("fruitListSelection", l), e.magic("dialog", () => y), e.data("fruitCombobox", G), e.data("fruitTokenField", K), e.data("fruitSelectionBar", q), e.data("fruitAutocomplete", ee), e.data("fruitCommandPalette", te), e.data("fruitDropzone", ne), e.data("fruitMenu", se), e.data("fruitDatePicker", re), e.data("fruitColorPicker", V), e.data("fruitContextMenu", ce), e.data("fruitTooltip", le), e.data("fruitTabs", ue), e.data("fruitSplitter", E), e.data("fruitFloatingDisclosure", de), e.data("fruitDialogModel", S), typeof window < "u" && !Q && (x(window), b(document), Q = !0);
}
//#endregion
//#region src/js/livewire.js
window.Alpine ? $(window.Alpine) : document.addEventListener("alpine:init", () => $(window.Alpine), { once: !0 });
//#endregion
export { i as confirm, y as dialog, t as toast };
