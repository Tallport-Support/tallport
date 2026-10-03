//#region src/js/toast.js
function e(e) {
	window.dispatchEvent(new CustomEvent("fruit-toast", { detail: { message: e } }));
}
function t({ duration: e = 4e3, message: t = null } = {}) {
	if (!Number.isFinite(e) || e < 0) throw Error("FruitUI Toast duration must be a nonnegative number of milliseconds.");
	let n, r, i = e, a = new AbortController(), o = () => {
		clearTimeout(n), n = void 0;
	};
	return {
		notice: "",
		init() {
			let e = { signal: a.signal };
			this.$el.classList.contains("f-toast") && typeof this.$el.showPopover == "function" && (this.$el.popover = "manual"), window.addEventListener("fruit-toast", (e) => this.notify(e.detail?.message ?? ""), e);
			for (let t of ["mouseenter", "focusin"]) this.$el.addEventListener(t, () => this.pauseNotice(), e);
			for (let t of ["mouseleave", "focusout"]) this.$el.addEventListener(t, () => this.resumeNotice(), e);
			t && this.notify(t);
		},
		notify(t) {
			o(), this.notice = String(t), i = e, this.$el.popover && (this.$el.matches(":popover-open") && this.$el.hidePopover(), this.$el.showPopover()), this.resumeNotice();
		},
		dismissNotice() {
			o(), this.notice = "", i = 0, this.$el.popover && this.$el.matches(":popover-open") && this.$el.hidePopover();
		},
		pauseNotice() {
			n !== void 0 && (i = Math.max(0, i - (performance.now() - r)), o());
		},
		resumeNotice() {
			this.notice && e && n === void 0 && (r = performance.now(), n = setTimeout(() => this.dismissNotice(), i));
		},
		destroy() {
			o(), a.abort();
		}
	};
}
//#endregion
//#region src/js/dialog.js
function n(e = window) {
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
function r() {
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
var i = (e) => getComputedStyle(e).direction === "rtl";
function a(e, t, { stretch: n = !1, above: r = !1, point: a = null, start: o = !1 } = {}) {
	let s = typeof e.showPopover == "function", c = e.getAttribute("style"), l = !1;
	s && (e.popover = "manual");
	let u = () => {
		if (!l || !s) return;
		let c = t.getBoundingClientRect(), u = window.visualViewport, d = u?.offsetLeft || 0, f = u?.offsetTop || 0, p = u?.width || window.innerWidth, m = u?.height || window.innerHeight;
		e.style.position = "fixed", e.style.inset = "auto", e.style.margin = "0", e.style.transform = "none", e.style.maxWidth = `${Math.max(0, p - 16)}px`, e.style.maxHeight = `${Math.max(40, m - 16)}px`, e.style.overflowY = "auto", n && (e.style.width = `${Math.min(c.width, p - 16)}px`);
		let h = e.getBoundingClientRect(), g = a ? i(a) ? c.left - h.width : c.left : n || i(t) !== o ? c.left : c.right - h.width, _ = f + m - c.bottom - 8, v = c.top - f - 8, y = r && v >= h.height || _ < h.height && v > _, b = y ? v : _;
		e.style.maxHeight = `${Math.max(40, b)}px`;
		let x = e.getBoundingClientRect().height;
		e.style.left = `${Math.max(d + 8, Math.min(g, d + p - h.width - 8))}px`, e.style.top = `${Math.max(f + 8, Math.min(y ? c.top - x - 4 : c.bottom + 4, f + m - x - 8))}px`;
	}, d = () => {
		l = !0, s && (e.popover = "manual"), s && !e.matches(":popover-open") && e.showPopover(), u();
	}, f = () => {
		l = !1, s && e.matches(":popover-open") && e.hidePopover();
	};
	return window.addEventListener("resize", u), document.addEventListener("scroll", u, !0), window.visualViewport?.addEventListener("resize", u), window.visualViewport?.addEventListener("scroll", u), {
		show: d,
		hide: f,
		destroy() {
			f(), window.removeEventListener("resize", u), document.removeEventListener("scroll", u, !0), window.visualViewport?.removeEventListener("resize", u), window.visualViewport?.removeEventListener("scroll", u), s && e.removeAttribute("popover"), c === null ? e.removeAttribute("style") : e.setAttribute("style", c);
		}
	};
}
function o(e, t, { above: n = !1, owns: r = (t) => t?.closest("details") === e, onToggle: i } = {}) {
	let o = e.querySelector("summary"), s = a(t, o, { above: n }), c = new AbortController(), l = { signal: c.signal }, u = (t) => {
		e.open = !1, s.hide(), t && o.focus();
	};
	return e.addEventListener("toggle", (t) => {
		t.target === e && (e.open ? s.show() : s.hide(), i?.(e.open));
	}, l), document.addEventListener("pointerdown", (t) => {
		e.open && !e.contains(t.target) && u(!1);
	}, l), e.addEventListener("keydown", (t) => {
		t.key === "Escape" && e.open && r(t.target) && (t.preventDefault(), t.stopPropagation(), u(!0));
	}, l), e.open && s.show(), {
		trigger: o,
		show: () => s.show(),
		close: u,
		destroy() {
			c.abort(), s.destroy();
		}
	};
}
//#endregion
//#region src/js/messages.js
function s(e, t, n, r = {}) {
	return (e.getAttribute(`data-fruit-${t}`) ?? n).replace(/\{(\w+)\}/g, (e, t) => String(r[t] ?? e));
}
//#endregion
//#region src/js/splitter.js
function c({ pane: e, variable: t, min: n = 160, max: r = 420, reserve: a = 280, flexible: o, edge: c = "end" }) {
	if (!e || !o || e === o || !/^--f-[\w-]+$/.test(t) || !["start", "end"].includes(c) || ![
		n,
		r,
		a
	].every(Number.isFinite) || n <= 0 || r < n || a <= 0) throw Error("FruitUI splitter requires pane/flexible IDs, a --f- variable, positive bounds and start/end edge.");
	let l, u, d, f, p, m, h, g, _, v, y = (e) => !!e?.getClientRects().length && getComputedStyle(e).display !== "none";
	return {
		init() {
			if (l = this.$el, u = l.closest(".f-workspace"), d = u?.querySelector(`[id="${CSS.escape(e)}"]`), f = u?.querySelector(`[id="${CSS.escape(o)}"]`), !d || !f) throw Error("FruitUI splitter panes must belong to its workspace.");
			_ = y(d) ? d.getBoundingClientRect().width : void 0, l.setAttribute("data-ready", ""), l.setAttribute("data-edge", c), l.setAttribute("aria-controls", e), v = Object.fromEntries(Object.entries({
				pointerdown: this.start,
				pointermove: this.move,
				pointerup: this.end,
				pointercancel: this.cancel,
				lostpointercapture: this.end,
				keydown: this.key,
				dblclick: this.reset
			}).map(([e, t]) => [e, t.bind(this)]));
			for (let [e, t] of Object.entries(v)) l.addEventListener(e, t);
			p = new ResizeObserver(() => {
				this.describe(), this.schedule();
			}), p.observe(u), p.observe(d), p.observe(f), m = new MutationObserver(() => this.schedule()), m.observe(u, { attributes: !0 }), this.describe(), this.schedule();
		},
		bounds() {
			let e = d.getBoundingClientRect().width, t = e + f.getBoundingClientRect().width - a;
			return {
				width: e,
				upper: Math.max(n, Math.floor(Math.min(r, t)))
			};
		},
		schedule() {
			cancelAnimationFrame(h), h = requestAnimationFrame(() => this.update());
		},
		update() {
			if (!y(l) || !y(d) || !y(f)) {
				g && this.cancel();
				return;
			}
			let { width: e, upper: t } = this.bounds();
			_ ??= e, (e > t + 1 || e < n - 1) && this.set(e), this.describe();
		},
		describe() {
			if (!y(d) || !y(f)) return;
			let { width: e, upper: t } = this.bounds();
			l.setAttribute("aria-valuemin", Math.round(n)), l.setAttribute("aria-valuemax", Math.floor(t)), l.setAttribute("aria-valuenow", Math.round(e)), l.setAttribute("aria-valuetext", s(l, "value-text", "{count} pixels", { count: Math.round(e) }));
		},
		set(e) {
			let { upper: r } = this.bounds(), i = `${Math.round(Math.max(n, Math.min(r, e)))}px`;
			u.style.getPropertyValue(t) !== i && (u.style.setProperty(t, i), this.schedule());
		},
		start(e) {
			e.button === 0 && y(d) && y(f) && (e.preventDefault(), l.focus({ preventScroll: !0 }), g = {
				id: e.pointerId,
				x: e.clientX,
				width: d.getBoundingClientRect().width,
				previous: u.style.getPropertyValue(t)
			}, l.setPointerCapture(e.pointerId), l.setAttribute("data-resizing", ""), u.setAttribute("data-resizing", ""));
		},
		move(e) {
			if (!g || e.pointerId !== g.id) return;
			let t = (i(u) ? -1 : 1) * (c === "start" ? -1 : 1);
			this.set(g.width + (e.clientX - g.x) * t);
		},
		end() {
			if (!g) return;
			let e = g.id;
			g = void 0, l.removeAttribute("data-resizing"), u.removeAttribute("data-resizing"), l.hasPointerCapture(e) && l.releasePointerCapture(e);
		},
		cancel() {
			g && (g.previous ? u.style.setProperty(t, g.previous) : u.style.removeProperty(t), this.end(), this.schedule());
		},
		key(e) {
			if (e.key === "Escape" && g) {
				e.preventDefault(), e.stopPropagation(), this.cancel();
				return;
			}
			let { width: t, upper: r } = this.bounds(), a = (i(u) ? -1 : 1) * (c === "start" ? -1 : 1), o = e.shiftKey ? 32 : 8, s = {
				ArrowLeft: t - o * a,
				ArrowRight: t + o * a,
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
			for (let [e, t] of Object.entries(v)) l.removeEventListener(e, t);
		}
	};
}
//#endregion
//#region src/js/control-bridge.js
var l = 0, u = (e) => `${e}-${++l}`;
function d(e, t, { commit: n = !0 } = {}) {
	e.value !== t && (e.value = t, e.dispatchEvent(new Event("input", { bubbles: !0 })), n && e.dispatchEvent(new Event("change", { bubbles: !0 })));
}
function f(e, t, n, r, { presentation: i = n, focusRoot: a = n } = {}) {
	let o = [], s = (e, t, n) => {
		e?.addEventListener(t, n), o.push(() => e?.removeEventListener(t, n));
	}, c = () => [...t.labels || []], l = i.className, d = i.getAttribute("style"), f = n.placeholder || "", p = t.ownerDocument.activeElement === t, m = t.value;
	s(t.ownerDocument, "click", (e) => {
		c().some((t) => t.contains(e.target)) && (e.target === t || !e.target.closest("button, a, input, select, textarea")) && (e.preventDefault(), n.focus());
	});
	let h = (e = "attributes") => {
		t.hidden ||= !0;
		let a = c();
		for (let e of a) e.id ||= u("fruit-label");
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
		let o = t.getAttribute("style") || d;
		o === null ? i.removeAttribute("style") : i.setAttribute("style", o), "placeholder" in n && (n.placeholder = t.getAttribute("placeholder") ?? f), r(e), m = t.value;
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
//#region src/js/autocomplete.js
function p() {
	let e, t, n, r, i, o, c, l = -1, f = [], p = null, m = () => e.dataset.fruitTrigger || "", h = () => [...e.querySelector("datalist")?.options ?? []].filter((e) => !e.disabled), g = () => {
		let e = t.selectionStart ?? t.value.length, n = t.value.slice(0, e), r = n.search(/[^\s,]*$/), i = n.slice(r), a = m();
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
		l = -1, f = [], n.hidden = !0, i.hide(), t.removeAttribute("aria-activedescendant");
	}, v = (e) => {
		l = e, [...n.children].forEach((e, t) => e.setAttribute("aria-selected", String(t === l)));
		let r = n.children[l];
		r ? (t.setAttribute("aria-activedescendant", r.id), r.scrollIntoView({ block: "nearest" })) : t.removeAttribute("aria-activedescendant");
	}, y = () => {
		if (p = g(), !p || t.disabled || t.readOnly) return _();
		let a = p.query.toLocaleLowerCase(), o = (e) => e.label || e.value, c = (e) => `${o(e)} ${e.value}`.toLocaleLowerCase().split(/[\s@:/#._-]+/);
		if (f = h().filter((e) => e.value.toLocaleLowerCase().startsWith(m() + a) || c(e).some((e) => e.startsWith(a))).sort((e, t) => Number(!o(e).toLocaleLowerCase().startsWith(a)) - Number(!o(t).toLocaleLowerCase().startsWith(a))).slice(0, 8), !f.length) return r.textContent = s(e, "no-suggestions", "No suggestions"), _();
		n.replaceChildren(...f.map((e, t) => {
			let r = document.createElement("li");
			if (r.id = `${n.id}-${t}`, r.className = "f-autocomplete__option", r.setAttribute("role", "option"), r.textContent = o(e), e.label && e.label !== e.value) {
				let t = document.createElement("span");
				t.className = "f-autocomplete__detail", t.textContent = e.value, r.append(t);
			}
			return r.addEventListener("pointerdown", (e) => e.preventDefault()), r.addEventListener("click", () => b(t)), r;
		})), n.hidden = !1, i.show(), r.textContent = s(e, "count-message", "{count} suggestions", { count: f.length }), v(0);
	}, b = (e) => {
		let n = f[e];
		if (!n || !p) return;
		let { start: r, end: i } = p, a = t.value, o = `${n.value}${m() ? " " : ""}`;
		d(t, a.slice(0, r) + o + a.slice(i));
		let s = r + o.length;
		t.setSelectionRange?.(s, s), t.focus(), _();
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input:not([type=\"hidden\"]), textarea"), !t) return;
			o = new AbortController();
			let d = (e, t, n) => e.addEventListener(t, n, { signal: o.signal });
			n = document.createElement("ul"), n.id = u("fruit-suggestions"), n.className = "f-autocomplete__options", n.setAttribute("role", "listbox"), n.setAttribute("aria-label", s(e, "label", "Suggestions")), n.hidden = !0, r = document.createElement("span"), r.className = "f-sr-only", r.setAttribute("role", "status"), (e.querySelector("[data-fruit-ui]") ?? e).append(n, r), i = a(n, t, { stretch: t.tagName === "INPUT" });
			let p = () => {
				t.setAttribute("aria-autocomplete", "list"), t.setAttribute("aria-haspopup", "listbox"), t.setAttribute("aria-controls", n.id);
			};
			p(), c = new MutationObserver(() => {
				(t.getAttribute("aria-controls") !== n.id || !t.hasAttribute("aria-haspopup")) && p();
			}), c.observe(t, {
				attributes: !0,
				attributeFilter: [
					"aria-autocomplete",
					"aria-haspopup",
					"aria-controls"
				]
			}), d(t, "input", (e) => {
				e.isComposing || y();
			}), d(t, "keydown", (e) => {
				e.isComposing || n.hidden || (e.key === "ArrowDown" || e.key === "ArrowUp" ? (e.preventDefault(), v((l + (e.key === "ArrowDown" ? 1 : -1) + f.length) % f.length)) : e.key === "Enter" || e.key === "Tab" ? (e.preventDefault(), e.stopPropagation(), b(l)) : e.key === "Escape" && (e.preventDefault(), e.stopPropagation(), _()));
			}), d(t, "blur", _), d(t, "click", () => {
				n.hidden || y();
			});
		},
		destroy() {
			c?.disconnect(), o?.abort(), i?.destroy(), n?.remove(), r?.remove();
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
function m() {
	let e, t, n, r, i, a, o = -1, s = () => [...n.querySelectorAll("[role=\"option\"]")].filter((e) => !e.hidden && !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true"), c = (e) => {
		let r = s();
		o = r.length ? (e + r.length) % r.length : -1;
		for (let e of n.querySelectorAll("[role=\"option\"]")) e.setAttribute("aria-selected", "false");
		let i = r[o];
		if (!i) return t.removeAttribute("aria-activedescendant");
		i.id ||= u("fruit-command"), i.setAttribute("aria-selected", "true"), t.setAttribute("aria-activedescendant", i.id), i.scrollIntoView({ block: "nearest" });
	}, l = () => {
		let e = t.value.trim().toLocaleLowerCase();
		for (let t of n.querySelectorAll("[role=\"option\"]")) t.hidden = !!e && !t.textContent.toLocaleLowerCase().includes(e);
		for (let e of n.querySelectorAll("[role=\"group\"]")) e.hidden = !e.querySelector("[role=\"option\"]:not([hidden])");
		r.hidden = s().length > 0, c(0);
	}, d = (t) => {
		t && (e.close(), t.click());
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("[role=\"combobox\"]"), n = e.querySelector("[role=\"listbox\"]"), r = e.querySelector(".f-command-palette__empty"), !t || !n || !r) return;
			i = new AbortController();
			let u = (e, t, n) => e.addEventListener(t, n, { signal: i.signal });
			u(t, "input", l), u(t, "keydown", (e) => {
				e.isComposing || (e.key === "ArrowDown" || e.key === "ArrowUp" ? (e.preventDefault(), c(o + (e.key === "ArrowDown" ? 1 : -1))) : e.key === "Enter" && (e.preventDefault(), d(s()[o])));
			}), u(n, "pointermove", (e) => {
				let t = e.target.closest("[role=\"option\"]");
				t && !t.hidden && c(s().indexOf(t));
			}), u(e, "mousedown", (e) => {
				e.target !== t && e.preventDefault();
			}), u(n, "click", (t) => {
				t.target.closest("[role=\"option\"]") && e.open && e.close();
			}), a = new MutationObserver(() => {
				e.open && (t.value = "", l(), t.focus());
			}), a.observe(e, {
				attributes: !0,
				attributeFilter: ["open"]
			});
			let f = e.dataset.fruitShortcut?.toLocaleLowerCase();
			f && u(document, "keydown", (t) => {
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
var h = (e, t) => {
	let n = (e.accept || "").split(",").map((e) => e.trim().toLowerCase()).filter(Boolean);
	if (!n.length) return !0;
	let r = t.name.toLowerCase(), i = (t.type || "").toLowerCase();
	return n.some((e) => e.startsWith(".") ? r.endsWith(e) : e.endsWith("/*") ? i.startsWith(e.slice(0, -1)) : i === e);
};
function g() {
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
				let r = [...e.dataTransfer.files].filter((e) => h(n, e));
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
var _ = (e) => String(e).padStart(2, "0"), v = (e) => `${String(e.getFullYear()).padStart(4, "0")}-${_(e.getMonth() + 1)}-${_(e.getDate())}`, y = (e) => {
	let t = /^(\d{4,})-(\d{2})-(\d{2})/.exec(e || "");
	return t ? new Date(Number(t[1]), Number(t[2]) - 1, Number(t[3])) : null;
}, b = (e, t) => new Date(e.getFullYear(), e.getMonth(), e.getDate() + t), x = (e, t) => {
	let n = new Date(e.getFullYear(), e.getMonth() + t, 1), r = new Date(n.getFullYear(), n.getMonth() + 1, 0).getDate();
	return n.setDate(Math.min(e.getDate(), r)), n;
}, S = (e, t) => !!(e && t) && v(e) === v(t), C = (e) => e.closest("[lang]")?.lang || navigator.language || "en";
function w(e) {
	try {
		let t = new Intl.Locale(e), n = t.getWeekInfo?.() ?? t.weekInfo;
		if (n?.firstDay) return n.firstDay % 7;
	} catch {}
	return +!/^en(-US|-CA)?$/i.test(e);
}
function T(e, t, n, { onOpen: r, onFocus: i }) {
	let o = a(n, t, { start: !0 }), s = new AbortController(), c = (e, t, n) => e.addEventListener(t, n, { signal: s.signal }), l = () => !n.hidden, u = () => {
		t.setAttribute("aria-haspopup", "dialog"), t.setAttribute("aria-controls", n.id);
	}, d = (e) => {
		l() && (o.hide(), n.hidden = !0, u(), e && t.focus());
	}, f = (e) => {
		t.disabled || t.readOnly || (n.hidden = !1, r(), o.show(), u(), e && i());
	};
	u();
	let p = new MutationObserver(() => {
		(t.getAttribute("aria-controls") !== n.id || !t.hasAttribute("aria-haspopup")) && u();
	});
	return p.observe(t, {
		attributes: !0,
		attributeFilter: ["aria-haspopup", "aria-controls"]
	}), c(document, "pointerdown", (t) => {
		l() && !e.contains(t.target) && !n.contains(t.target) && d(!1);
	}), c(e, "focusout", (t) => {
		l() && t.relatedTarget && !e.contains(t.relatedTarget) && !n.contains(t.relatedTarget) && d(!1);
	}), c(n, "keydown", (e) => {
		e.key === "Escape" && (e.preventDefault(), e.stopPropagation(), d(!0));
	}), {
		listen: c,
		open: f,
		close: d,
		isOpen: l,
		destroy() {
			p.disconnect(), s.abort(), o.destroy();
		}
	};
}
function E() {
	let e, t, n, r, a, o, c = /* @__PURE__ */ new Date(), l = /* @__PURE__ */ new Date(), f = () => y(t.value), p = () => [y(t.min), y(t.max)], m = (e) => {
		let [t, n] = p();
		return !!(t && e < t || n && e > n);
	}, h = (e) => {
		let [t, n] = p();
		return t && e < t ? t : n && e > n ? n : e;
	}, g = () => {
		let e = C(t);
		r.textContent = new Intl.DateTimeFormat(e, {
			month: "long",
			year: "numeric"
		}).format(c);
		let n = b(c, -((c.getDay() - w(e) + 7) % 7)), i = new Intl.DateTimeFormat(e, { weekday: "narrow" }), o = new Intl.DateTimeFormat(e, { weekday: "long" }), s = new Intl.DateTimeFormat(e, { dateStyle: "full" }), u = document.createElement("tr");
		for (let e = 0; e < 7; e++) {
			let t = b(n, e), r = document.createElement("th");
			r.scope = "col", r.abbr = o.format(t), r.textContent = i.format(t), u.append(r);
		}
		let d = [];
		for (let e = 0; e < 6; e++) {
			let t = document.createElement("tr");
			for (let r = 0; r < 7; r++) {
				let i = b(n, e * 7 + r), a = document.createElement("td");
				a.setAttribute("aria-selected", String(S(i, f())));
				let o = document.createElement("button");
				o.type = "button", o.className = "f-calendar__day", o.tabIndex = S(i, l) ? 0 : -1, o.textContent = String(i.getDate()), o.dataset.date = v(i), o.setAttribute("aria-label", s.format(i)), i.getMonth() !== c.getMonth() && (o.dataset.outside = ""), S(i, /* @__PURE__ */ new Date()) && o.setAttribute("aria-current", "date"), m(i) && o.setAttribute("aria-disabled", "true"), a.append(o), t.append(a);
			}
			d.push(t);
		}
		a.tHead.replaceChildren(u), a.tBodies[0].replaceChildren(...d);
	}, E = (e, t = !0) => {
		l = e, c = new Date(e.getFullYear(), e.getMonth(), 1), g(), t && a.querySelector(`[data-date="${v(e)}"]`)?.focus();
	}, D = (e) => {
		if (m(e)) return;
		let n = v(e);
		if (t.type === "datetime-local") {
			let e = /* @__PURE__ */ new Date();
			n += `T${t.value.split("T")[1] || `${_(e.getHours())}:${_(e.getMinutes())}`}`;
		}
		d(t, n), o.close(!0);
	}, O = (e, n) => {
		let r = i(t) ? -1 : 1;
		return {
			ArrowLeft: () => b(n, -r),
			ArrowRight: () => b(n, r),
			ArrowUp: () => b(n, -7),
			ArrowDown: () => b(n, 7),
			Home: () => b(n, -((n.getDay() - w(C(t)) + 7) % 7)),
			End: () => b(n, 6 - (n.getDay() - w(C(t)) + 7) % 7),
			PageUp: () => x(n, e.shiftKey ? -12 : -1),
			PageDown: () => x(n, e.shiftKey ? 12 : 1)
		}[e.key]?.();
	};
	return {
		init() {
			if (e = this.$el, t = e.querySelector("input[type=\"date\"], input[type=\"datetime-local\"]"), !t) return;
			n = document.createElement("div"), n.id = u("fruit-calendar"), n.className = "f-calendar", n.setAttribute("role", "dialog"), n.setAttribute("aria-label", s(e, "label", "Choose date")), n.hidden = !0;
			let i = document.createElement("div");
			i.className = "f-calendar__header", r = document.createElement("div"), r.className = "f-calendar__title", r.id = `${n.id}-title`, r.setAttribute("aria-live", "polite");
			let d = (t, n, r) => {
				let i = document.createElement("button");
				return i.type = "button", i.className = "f-calendar__nav", i.dataset.direction = t, i.setAttribute("aria-label", s(e, n, r)), i.addEventListener("click", () => {
					l = h(x(l, t === "next" ? 1 : -1)), c = new Date(l.getFullYear(), l.getMonth(), 1), g();
				}), i;
			};
			i.append(r, d("previous", "previous-label", "Previous month"), d("next", "next-label", "Next month")), a = document.createElement("table"), a.className = "f-calendar__grid", a.setAttribute("role", "grid"), a.setAttribute("aria-labelledby", r.id), a.append(document.createElement("thead"), document.createElement("tbody")), n.append(i, a), (e.querySelector("[data-fruit-ui]") ?? e).append(n), o = T(e, t, n, {
				onOpen: () => E(h(f() ?? /* @__PURE__ */ new Date()), !1),
				onFocus: () => a.querySelector(".f-calendar__day[tabindex=\"0\"]")?.focus()
			}), e.setAttribute("data-ready", ""), o.listen(t, "click", (e) => {
				e.preventDefault(), o.isOpen() || o.open(!1);
			}), o.listen(t, "keydown", (e) => {
				e.altKey && ["ArrowDown", "ArrowUp"].includes(e.key) ? (e.preventDefault(), e.key === "ArrowUp" ? o.close(!0) : o.isOpen() ? a.querySelector(".f-calendar__day[tabindex=\"0\"]")?.focus() : o.open(!0)) : e.key === "Escape" && o.isOpen() && (e.preventDefault(), e.stopPropagation(), o.close(!1));
			}), o.listen(t, "input", () => {
				let e = f();
				o.isOpen() && e && E(e, !1);
			}), o.listen(a, "click", (e) => {
				let t = e.target.closest(".f-calendar__day");
				t && D(y(t.dataset.date));
			}), o.listen(a, "keydown", (e) => {
				let t = e.target.closest(".f-calendar__day");
				if (!t) return;
				let n = O(e, y(t.dataset.date));
				n && (e.preventDefault(), E(n));
			});
		},
		destroy() {
			o?.destroy(), n?.remove(), e?.removeAttribute("data-ready");
		}
	};
}
var D = [
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
], O = 7, k = (e) => [
	1,
	3,
	5
].map((t) => parseInt(e.slice(t, t + 2), 16) / 255);
function A(e) {
	let [t, n, r] = k(e), i = Math.max(t, n, r), a = i - Math.min(t, n, r), o = 0;
	return a && (o = i === t ? (n - r) / a % 6 : i === n ? (r - t) / a + 2 : (t - n) / a + 4), [
		(o * 60 + 360) % 360,
		i ? a / i : 0,
		i
	];
}
function j(e, t, n) {
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
function M() {
	let e, t, n, r, a, o, c, l, f, p, m, h = [
		0,
		0,
		0
	], g = () => {
		let e = [...t.list?.options ?? []].filter((e) => /^#[0-9a-f]{6}$/i.test(e.value)).map((e) => [e.label || e.value, e.value.toLowerCase()]);
		return e.length ? e : D;
	}, _ = () => [...r.querySelectorAll("[role=\"option\"]")], v = () => {
		let e = t.value.toLowerCase();
		r.replaceChildren(...g().map(([t, n]) => {
			let r = document.createElement("div");
			r.className = "f-swatch", r.setAttribute("role", "option"), r.tabIndex = -1, r.title = t, r.dataset.value = n, r.style.setProperty("--f-swatch-color", n), r.setAttribute("aria-selected", String(n === e));
			let i = document.createElement("span");
			return i.className = "f-sr-only", i.textContent = t, r.append(i), r;
		}));
	}, y = () => {
		let [n, r, i] = h;
		o.style.setProperty("--f-picker-hue", j(n, 1, 1)), l.style.left = `${r * 100}%`, l.style.top = `${(1 - i) * 100}%`;
		let a = (e) => Math.round(e * 100);
		c.setAttribute("aria-valuenow", String(a(r))), c.setAttribute("aria-valuetext", s(e, "area-text", "Saturation {saturation}%, brightness {brightness}%", {
			saturation: a(r),
			brightness: a(i)
		})), f.value = String(Math.round(n)), document.activeElement !== p && (p.value = t.value);
	}, b = () => {
		let [e, n, r] = A(t.value);
		h = [
			n && r ? e : h[0],
			n,
			r
		], y();
	}, x = (e, n) => {
		h = e, d(t, j(...h), { commit: n }), y();
	}, S = (e) => {
		d(t, e.dataset.value), m.close(!0);
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
				id: u("fruit-colors"),
				role: "dialog",
				"aria-label": s(e, "label", "Choose color")
			}), n.hidden = !0, r = C("div", "f-color-palette__swatches", {
				role: "listbox",
				"aria-label": s(e, "colors-label", "Colors")
			}), r.style.setProperty("--f-swatch-columns", String(O)), a = C("button", "f-button f-button--ghost f-button--small f-color-palette__other", {
				type: "button",
				"aria-expanded": "false"
			}), a.textContent = s(e, "other-label", "Other…"), o = C("div", "f-color-editor"), o.hidden = !0, o.style.setProperty("--f-picker-black", "#000"), o.style.setProperty("--f-picker-white", "#fff"), o.style.setProperty("--f-picker-spectrum", "linear-gradient(90deg, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00)"), c = C("div", "f-color-editor__area", {
				role: "slider",
				tabindex: "0",
				"aria-label": s(e, "area-label", "Saturation and brightness"),
				"aria-valuemin": "0",
				"aria-valuemax": "100"
			}), l = C("span", "f-color-editor__thumb", { "aria-hidden": "true" }), c.append(l), f = C("input", "f-range f-color-editor__hue", {
				type: "range",
				min: "0",
				max: "359",
				"aria-label": s(e, "hue-label", "Hue")
			});
			let g = C("label", "f-color-editor__hex"), y = C("span", "f-label");
			y.textContent = s(e, "hex-label", "Hex"), p = C("input", "f-input", {
				type: "text",
				maxlength: "7",
				spellcheck: "false",
				autocomplete: "off"
			}), g.append(y, p), o.append(c, f, g), a.setAttribute("aria-controls", o.id = `${n.id}-editor`), n.append(r, a, o), v(), (e.querySelector("[data-fruit-ui]") ?? e).append(n), m = T(e, t, n, {
				onOpen: () => {
					v(), o.hidden = !0, a.hidden = !1, a.setAttribute("aria-expanded", "false");
				},
				onFocus: () => {
					let e = _();
					(e.find((e) => e.getAttribute("aria-selected") === "true") ?? e[0])?.focus();
				}
			}), e.setAttribute("data-ready", ""), m.listen(t, "click", (e) => {
				e.preventDefault(), m.isOpen() ? m.close(!1) : m.open(!0);
			}), m.listen(t, "input", () => {
				o.hidden || b();
			}), m.listen(r, "click", (e) => {
				let t = e.target.closest("[role=\"option\"]");
				t && S(t);
			}), m.listen(r, "keydown", (e) => {
				let n = _(), r = n.indexOf(document.activeElement);
				if (r < 0) return;
				let a = i(t) ? -1 : 1, o = {
					ArrowRight: r + a,
					ArrowLeft: r - a,
					ArrowDown: r + O,
					ArrowUp: r - O,
					Home: 0,
					End: n.length - 1
				};
				e.key in o ? (e.preventDefault(), n[Math.max(0, Math.min(n.length - 1, o[e.key]))].focus()) : (e.key === "Enter" || e.key === " ") && (e.preventDefault(), S(n[r]));
			}), m.listen(a, "click", () => {
				o.hidden = !1, a.hidden = !0, a.setAttribute("aria-expanded", "true"), b(), c.focus();
			});
			let w = (e, t) => {
				let n = c.getBoundingClientRect(), r = (e) => Math.max(0, Math.min(1, e));
				x([
					h[0],
					r((e.clientX - n.left) / n.width),
					1 - r((e.clientY - n.top) / n.height)
				], t);
			}, E = !1;
			m.listen(c, "pointerdown", (e) => {
				e.button === 0 && (e.preventDefault(), c.focus(), c.setPointerCapture(e.pointerId), E = !0, w(e, !1));
			}), m.listen(c, "pointermove", (e) => {
				E && w(e, !1);
			}), m.listen(c, "pointerup", (e) => {
				E && (E = !1, w(e, !0));
			}), m.listen(c, "keydown", (e) => {
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
					h[0],
					r(h[1] + n[0]),
					r(h[2] + n[1])
				], !0);
			}), m.listen(f, "input", () => x([
				Number(f.value),
				h[1],
				h[2]
			], !1)), m.listen(f, "change", () => x([
				Number(f.value),
				h[1],
				h[2]
			], !0)), m.listen(p, "input", () => {
				let e = p.value.trim().toLowerCase(), n = /^#?[0-9a-f]{6}$/.test(e) ? `#${e.replace("#", "")}` : null;
				n && (d(t, n), b());
			}), m.listen(p, "blur", () => p.value = t.value), m.listen(p, "keydown", (e) => {
				e.key === "Enter" && (e.preventDefault(), m.close(!0));
			});
		},
		destroy() {
			m?.destroy(), n?.remove(), e?.removeAttribute("data-ready");
		}
	};
}
//#endregion
//#region src/js/selection.js
function N() {
	let e, t, n, r, i, o, c, l, p, m = [], h = -1, g = !1, _ = () => t.selectedOptions[0]?.label || "", v = () => {
		g = !1, p.hide(), r.hidden = !0, n.setAttribute("aria-expanded", "false"), n.removeAttribute("aria-activedescendant");
	}, y = (e) => {
		h = e, [...r.children].forEach((e, t) => e.setAttribute("aria-selected", String(t === h))), r.children[h] ? (n.setAttribute("aria-activedescendant", r.children[h].id), r.children[h].scrollIntoView({ block: "nearest" })) : n.removeAttribute("aria-activedescendant");
	}, b = (i = "") => {
		if (n.disabled) return;
		if (m = [...t.options].filter((e) => !e.matches(":disabled") && !e.hidden && e.label.toLocaleLowerCase().includes(i.toLocaleLowerCase())), r.replaceChildren(), m.forEach((e, t) => {
			let n = document.createElement("li");
			n.className = "f-combobox__option", n.id = `${r.id}-${t}`, n.setAttribute("role", "option"), n.textContent = e.label, n.addEventListener("pointerdown", (e) => e.preventDefault()), n.addEventListener("click", () => x(t)), r.append(n);
		}), !m.length) {
			let t = document.createElement("li");
			t.className = "f-combobox__empty", t.setAttribute("role", "presentation"), t.textContent = s(e, "no-matches", "No matches"), r.append(t);
		}
		g = !0, r.hidden = !1, n.setAttribute("aria-expanded", "true"), p.show();
		let a = m.findIndex((e) => e.selected);
		y(m.length ? Math.max(0, a) : -1);
	}, x = (e) => {
		m[e] && (d(t, m[e].value), n.value = _(), n.removeAttribute("aria-invalid"), v(), n.focus());
	};
	return {
		init() {
			e = this.$el, t = e.querySelector("select[data-fruit-control]"), !(!t || t.multiple || t.size > 1) && (n = document.createElement("input"), n.type = "text", n.className = "f-input", n.autocomplete = "off", n.setAttribute("role", "combobox"), n.setAttribute("aria-autocomplete", "list"), n.setAttribute("aria-expanded", "false"), r = document.createElement("ul"), r.id = u("fruit-options"), r.className = "f-combobox__options", r.setAttribute("role", "listbox"), r.hidden = !0, i = this.$el.querySelector("[data-fruit-ui]"), o = !i, i || (i = document.createElement("div"), i.setAttribute("data-fruit-ui", ""), this.$el.append(i)), n.setAttribute("aria-controls", r.id), i.append(n, r), t.hidden = !0, p = a(r, n, { stretch: !0 }), n.value = _(), n.addEventListener("input", () => b(n.value)), n.addEventListener("click", () => b()), n.addEventListener("blur", () => {
				v(), n.value = _();
			}), n.addEventListener("keydown", (e) => {
				e.isComposing || (["ArrowDown", "ArrowUp"].includes(e.key) ? (e.preventDefault(), g ? m.length && y((h + (e.key === "ArrowDown" ? 1 : -1) + m.length) % m.length) : (b(), y(e.key === "ArrowUp" ? m.length - 1 : 0))) : e.key === "Enter" && g && h >= 0 ? (e.preventDefault(), x(h)) : e.key === "Escape" && g ? (e.preventDefault(), e.stopPropagation(), v(), n.value = _()) : e.key === "Tab" && v());
			}), l = (e) => {
				this.$el.contains(e.target) || v();
			}, document.addEventListener("pointerdown", l), c = f(this, t, n, (e) => {
				g && ["value", "reset"].includes(e) && v(), ([
					"initial",
					"value",
					"reset"
				].includes(e) || !g) && (n.value = _()), g && e === "options" && b(n.value);
				for (let e of ["aria-label", "aria-labelledby"]) n.hasAttribute(e) ? r.setAttribute(e, n.getAttribute(e)) : r.removeAttribute(e);
				n.disabled && v();
			}));
		},
		destroy() {
			c?.(), p?.destroy(), document.removeEventListener("pointerdown", l), n?.remove(), r?.remove(), o && i?.remove(), t && (t.hidden = !1);
		}
	};
}
function P() {
	let e, t, n, r, a, o, c, l, u, p = [], m = () => t.value.split(/\r?\n/).map((e) => e.trim()).filter(Boolean), h = (e) => {
		a.textContent = e;
	}, g = () => {
		p = m(), n.querySelectorAll(".f-chip").forEach((e) => e.remove());
		for (let [a, o] of p.entries()) {
			let c = document.createElement("span");
			c.className = "f-chip";
			let l = document.createElement("span");
			l.textContent = o;
			let u = document.createElement("button");
			u.type = "button", u.className = "f-chip__remove", u.textContent = "×", u.setAttribute("aria-label", s(e, "remove-label", "Remove {value}", { value: o })), u.disabled = r.disabled || r.readOnly, u.addEventListener("click", () => {
				d(t, p.filter((e, t) => t !== a).join("\n")), h(s(e, "removed-message", "Removed {value}", { value: o })), r.focus();
			}), u.addEventListener("keydown", (e) => {
				let t = [...n.querySelectorAll("button")];
				(e.key === "ArrowLeft" || e.key === "ArrowRight") && (e.preventDefault(), (t[a + (e.key === "ArrowLeft" === i(n) ? 1 : -1)] || r).focus()), e.key === "Escape" && (e.preventDefault(), r.focus());
			}), c.append(l, u), n.insertBefore(c, r);
		}
	}, _ = (n) => {
		if (r.disabled || r.readOnly) return !1;
		let i = [...p];
		for (let a of n.split(/[,\n]/).map((e) => e.trim()).filter(Boolean)) {
			let n = {
				value: a,
				error: s(e, "invalid-message", "Check this value before adding it.")
			};
			if (!t.dispatchEvent(new CustomEvent("fruit-token-add", {
				bubbles: !0,
				cancelable: !0,
				detail: n
			}))) return r.setCustomValidity(n.error), r.setAttribute("aria-invalid", "true"), h(n.error), !1;
			let o = String(n.value).trim();
			o && !i.includes(o) && i.push(o);
		}
		if (t.maxLength >= 0 && i.join("\n").length > t.maxLength) {
			let n = s(e, "length-message", "Use at most {count} characters.", { count: t.maxLength });
			return r.setCustomValidity(n), r.setAttribute("aria-invalid", "true"), h(n), !1;
		}
		return d(t, i.join("\n")), r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid"), h(s(e, "count-message", "{count} items", { count: i.length })), !0;
	};
	return {
		init() {
			e = this.$el, t = e.querySelector("textarea[data-fruit-control]"), t && (n = document.createElement("div"), n.className = "f-token-field__entry", r = document.createElement("input"), r.type = "text", r.autocomplete = "off", r.placeholder = s(e, "placeholder", "Add an item"), a = document.createElement("span"), a.className = "f-sr-only", a.setAttribute("role", "status"), o = this.$el.querySelector("[data-fruit-ui]"), c = !o, o || (o = document.createElement("div"), o.setAttribute("data-fruit-ui", ""), this.$el.append(o)), n.append(r), o.append(n, a), t.hidden = !0, u = () => {
				r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid"), a.textContent = "", g();
			}, t.addEventListener("fruit-token-reset", u), r.addEventListener("keydown", (e) => {
				e.isComposing || (e.key === "Enter" || e.key === "," ? (e.preventDefault(), _(r.value)) : (e.key === "Backspace" || e.key === "ArrowLeft") && !r.value ? n.querySelector(".f-chip:last-of-type button")?.focus() : e.key === "Escape" ? (r.value = "", r.setCustomValidity(""), r.removeAttribute("aria-invalid")) : e.key === "Tab" && r.value.trim() && _(r.value));
			}), r.addEventListener("input", () => {
				r.setCustomValidity(""), r.removeAttribute("aria-invalid");
			}), r.addEventListener("change", () => {
				r.value.trim() && _(r.value);
			}), r.addEventListener("paste", (e) => {
				let t = e.clipboardData?.getData("text");
				t && /[,\n]/.test(t) && (e.preventDefault(), _(t));
			}), l = f(this, t, r, (e) => {
				g(), ["value", "reset"].includes(e) && (r.value = "", a.textContent = "");
			}, {
				presentation: n,
				focusRoot: n
			}));
		},
		destroy() {
			l?.(), t?.removeEventListener("fruit-token-reset", u), n?.remove(), a?.remove(), c && o?.remove(), t && (t.hidden = !1);
		}
	};
}
//#endregion
//#region src/js/navigation.js
var F = "[role=\"menuitem\"], [role=\"menuitemcheckbox\"], [role=\"menuitemradio\"]", I = (e, t) => [...e.querySelectorAll(F)].filter((e) => t(e) && !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true" && e.getClientRects().length), L = (e, t) => {
	e.length && e[(t + e.length) % e.length].focus();
}, R = (e) => [...e.childNodes].filter((e) => !e.classList?.contains("f-menu-item__shortcut")).map((e) => e.textContent).join("").trim().toLocaleLowerCase();
function z(e, t, n) {
	let r = t.indexOf(document.activeElement);
	return [
		"ArrowDown",
		"ArrowUp",
		"Home",
		"End"
	].includes(e.key) ? (e.preventDefault(), L(t, e.key === "Home" ? 0 : e.key === "End" ? t.length - 1 : r + (e.key === "ArrowDown" ? 1 : -1)), !0) : e.key.length === 1 && e.key !== " " && !e.ctrlKey && !e.metaKey && !e.altKey && (e.preventDefault(), clearTimeout(n.timer), n.search += e.key.toLocaleLowerCase(), [...t.slice(r + 1), ...t.slice(0, r + 1)].find((e) => R(e).startsWith(n.search))?.focus(), n.timer = setTimeout(() => {
		n.search = "";
	}, 600), !0);
}
function B() {
	let e, t, n, r, i, a, s, c = {
		search: "",
		timer: null
	}, l = (t) => t?.closest("[data-fruit-menu], [x-data^=\"fruitMenu\"]") === e, d = () => I(n, l), f = (e) => L(d(), e);
	return {
		init() {
			e = this.$el, e.setAttribute("data-fruit-menu", ""), t = e.querySelector("summary"), n = e.querySelector("[role=\"menu\"]"), t && n && (r = o(e, n, {
				above: e.classList.contains("f-menu--above"),
				owns: l,
				onToggle: (e) => {
					t.setAttribute("aria-expanded", String(e)), e && document.activeElement === t && f(0);
				}
			}), n.id ||= u("fruit-menu"), t.setAttribute("aria-haspopup", "menu"), t.setAttribute("aria-controls", n.id), t.setAttribute("aria-expanded", String(e.open)), n.querySelectorAll(F).forEach((e) => {
				l(e) && (e.tabIndex = -1);
			}), i = (n) => {
				if (!l(n.target)) return;
				let i = d();
				n.target === t && ["ArrowDown", "ArrowUp"].includes(n.key) ? (n.preventDefault(), e.open = !0, r.show(), f(n.key === "ArrowDown" ? 0 : i.length - 1)) : e.open && n.key === "Tab" ? s = setTimeout(() => r.close(!1), 0) : e.open && z(n, i, c);
			}, a = (e) => {
				let t = e.target.closest(F);
				l(t) && !t.matches(":disabled") && t.getAttribute("aria-disabled") !== "true" && r.close(!0);
			}, e.addEventListener("keydown", i), n.addEventListener("click", a));
		},
		destroy() {
			clearTimeout(s), clearTimeout(c.timer), r?.destroy(), e?.removeEventListener("keydown", i), n?.removeEventListener("click", a);
		}
	};
}
function V() {
	let e, t, n, r, o, s, c = {
		x: 0,
		y: 0
	}, l = {
		search: "",
		timer: null
	}, d = (t) => t?.closest("[data-fruit-menu]") === e, f = () => !e.hidden, p = (r) => {
		f() && (n.hide(), e.hidden = !0, t.removeAttribute("data-fruit-context-open"), r && o?.isConnected && o.focus());
	}, m = (r, i, a) => {
		o = a, c = {
			x: r,
			y: i
		}, e.hidden = !1, t.setAttribute("data-fruit-context-open", ""), n.show(), L(I(e, d), 0);
	};
	return {
		init() {
			if (e = this.$el, t = e.parentElement, !t) return;
			e.setAttribute("data-fruit-menu", ""), e.id ||= u("fruit-context-menu"), e.hidden = !0, e.querySelectorAll(F).forEach((e) => {
				d(e) && (e.tabIndex = -1);
			}), n = a(e, { getBoundingClientRect: () => new DOMRect(c.x, c.y, 0, 0) }, { point: t }), r = new AbortController();
			let o = (e, t, n) => e.addEventListener(t, n, { signal: r.signal }), h = !1;
			o(t, "contextmenu", (t) => {
				e.contains(t.target) || (t.preventDefault(), !h && m(t.clientX, t.clientY, document.activeElement));
			}), o(t, "keydown", (n) => {
				if (e.contains(n.target) || !(n.key === "F10" && n.shiftKey || n.key === "ContextMenu")) return;
				n.preventDefault(), h = !0, s = setTimeout(() => h = !1, 0);
				let r = n.target.getBoundingClientRect();
				m(i(t) ? r.right : r.left, r.bottom, n.target);
			}), o(e, "keydown", (t) => {
				d(t.target) && (t.key === "Escape" ? (t.preventDefault(), t.stopPropagation(), p(!0)) : t.key === "Tab" ? (t.preventDefault(), p(!0)) : z(t, I(e, d), l));
			}), o(e, "click", (e) => {
				let t = e.target.closest(F);
				d(t) && !t.matches(":disabled") && t.getAttribute("aria-disabled") !== "true" && p(!0);
			}), o(document, "pointerdown", (t) => {
				f() && !e.contains(t.target) && p(!1);
			}), o(window, "blur", () => p(!1));
		},
		destroy() {
			clearTimeout(s), clearTimeout(l.timer), r?.abort(), n?.destroy(), t?.removeAttribute("data-fruit-context-open");
		}
	};
}
function H() {
	let e, t, n, r, i, o, s;
	return {
		init() {
			e = this.$el;
			let c = e.querySelector("[role=\"tooltip\"]");
			c && (o = a(c, e.firstElementChild));
			let l = () => {
				e.hasAttribute("data-dismissed") || o?.show();
			};
			r = () => {
				clearTimeout(s), s = setTimeout(l, 600);
			}, i = () => {
				clearTimeout(s), l();
			}, t = (t) => {
				t.key === "Escape" && (e.matches(":hover") || e.contains(document.activeElement)) && (e.setAttribute("data-dismissed", ""), o?.hide(), t.stopPropagation());
			}, n = (t) => {
				e.contains(t.relatedTarget) || (clearTimeout(s), e.removeAttribute("data-dismissed"), !e.matches(":hover") && !e.contains(document.activeElement) && o?.hide());
			}, document.addEventListener("keydown", t), e.addEventListener("mouseleave", n), e.addEventListener("focusout", n), e.addEventListener("mouseenter", r), e.addEventListener("focusin", i);
		},
		destroy() {
			clearTimeout(s), o?.destroy(), document.removeEventListener("keydown", t), e.removeEventListener("mouseleave", n), e.removeEventListener("focusout", n), e.removeEventListener("mouseenter", r), e.removeEventListener("focusin", i);
		}
	};
}
function U() {
	let e, t, n, r, a = (t) => t?.closest("[data-fruit-tabs], [x-data^=\"fruitTabs\"]") === e, o = () => [...e.querySelectorAll("[role=\"tab\"]")].filter(a), s = () => o().filter((e) => !e.matches(":disabled") && e.getAttribute("aria-disabled") !== "true"), c = (t) => {
		for (let n of o()) {
			let r = n === t;
			n.setAttribute("aria-selected", String(r)), n.tabIndex = r ? 0 : -1;
			let i = [...e.querySelectorAll("[role=\"tabpanel\"]")].find((e) => a(e) && e.id === n.getAttribute("aria-controls"));
			i && (i.hidden = !r);
		}
	}, l = () => c(s().find((e) => e.getAttribute("aria-selected") === "true") || s()[0]);
	return {
		init() {
			e = this.$el, e.setAttribute("data-fruit-tabs", ""), l(), n = (e) => {
				let t = e.target.closest("[role=\"tab\"]");
				s().includes(t) && c(t);
			}, t = (t) => {
				let n = s(), r = n.indexOf(t.target);
				if (r < 0 || ![
					"ArrowLeft",
					"ArrowRight",
					"Home",
					"End"
				].includes(t.key)) return;
				t.preventDefault(), t.stopPropagation();
				let a = i(e), o = t.key === "Home" ? 0 : t.key === "End" ? n.length - 1 : (r + (t.key === "ArrowRight" === a ? -1 : 1) + n.length) % n.length;
				c(n[o]), n[o].focus();
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
function W() {
	let e;
	return {
		init() {
			let t = this.$el, n = t.querySelector(".f-floating-disclosure__content");
			t.querySelector("summary") && n && (e = o(t, n, {
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
var G = !1;
function K(i) {
	i.data("fruitEditor", () => ({})), i.data("fruitToast", t), i.magic("toast", () => e), i.data("fruitCombobox", N), i.data("fruitTokenField", P), i.data("fruitAutocomplete", p), i.data("fruitCommandPalette", m), i.data("fruitDropzone", g), i.data("fruitMenu", B), i.data("fruitDatePicker", E), i.data("fruitColorPicker", M), i.data("fruitContextMenu", V), i.data("fruitTooltip", H), i.data("fruitTabs", U), i.data("fruitSplitter", c), i.data("fruitFloatingDisclosure", W), i.data("fruitDialogModel", r), typeof window < "u" && !G && (n(window), G = !0);
}
//#endregion
export { K as default, t as fruitToast, e as toast };
