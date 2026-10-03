# Public components and patterns

The [component gallery](../components.html) is the visual catalog of the CSS shipped by `fruitui/css` and the opt-in `fruitui/mail.css`. Every entry has a live specimen and HTML/Blade usage. [component-catalog.json](component-catalog.json) maps public CSS families to gallery anchors, Blade adapters, and actual consumers. The [component policy](component-policy.md#blade-contracts) defines the native contracts for every Blade adapter. Gallery specimens are rendered from the Blade sources in `gallery/specimens/` (`composer gallery`), so the live specimen and the Blade usage shown beside it are the same code.

## CSS compositions and utilities

These use native markup and existing controls. A CSS composition does not require a separate Blade wrapper to be reusable.

| Family | Purpose and native markup | State and keyboard | Customization and composition |
| --- | --- | --- | --- |
| Field | Arrange an input, its label, and supporting text with `f-field`, `f-label`, `f-help`, and `f-error`. | The input owns validation; use `aria-invalid` and `aria-describedby`. Native editing and focus. | Compose Input, Select, or Textarea with labels and messages. |
| Search | Arrange a decorative SVG and `input type=search` in `f-search`. | Native text value/editing; application owns results. | Optional `f-icon`, accessible label, existing Input attributes. |
| Segmented choices | Present related native radios in `f-segmented` labels. | Native checked value; Tab enters, arrows select, Space checks. | Same radio name; each input is followed by its label span. Use fieldset/legend. No tablist or behavior modes. |
| Avatar | Present initials in a native span with `f-avatar`; Blade `x-fruit::avatar`. | No interaction or keyboard behavior. | Decorative when a name is adjacent; otherwise supply an accessible identity (`label` in Blade). Scoped `--f-avatar-size`, `--f-avatar-radius`, `--f-avatar-border`, `--f-avatar-background`, `--f-avatar-color`, `--f-avatar-font-size`, and `--f-avatar-font-weight` customize presentation. |
| Sidebar | Arrange navigation links/buttons and optional native disclosure groups with `f-sidebar`; Blade `x-fruit::sidebar`, `sidebar-group` and `sidebar-item` (links). | `details` owns open; links/buttons own actions and `aria-current=page`. Native Tab, Enter/Space. | Header (workspace) and footer (account) identity lockups, heading, item, nested item (automatic inside a group), identity text, count badge, decorative chevron. Tokens: `--f-sidebar-identity-gap`, `--f-sidebar-header-padding`, `--f-sidebar-footer-padding`, `--f-sidebar-item-indent`. No navigation data model. |
| Toolbar | Arrange actions in a div/header with `f-toolbar`. | No added state or keyboard behavior. | Group independent controls with `f-toolbar__group`; flexible space with `f-toolbar__spacer`. |
| Badge | Present a count or short label in `span.f-badge`; Blade `x-fruit::badge`. | No value, interaction, or added keyboard behavior. | Text content; scoped semantic tokens for appearance. |
| Toast | Announce a short result on one `div.f-toast` outlet: `x-fruit::toaster` in Blade, or `x-data="fruitToast"` with `x-show`/`x-text="notice"` in HTML. | The outlet owns one message and its timing; hover and focus pause it. Announced through `role=status`; no focus transfer. | Send messages with `Fruit::toast()` on the server, `$toast()` in Alpine, or `toast()` from `fruitui/alpine`; see [server feedback](#server-feedback-dialogs-and-toasts). |
| Row / Stack | Arrange independent children with `f-row` or `f-stack`. | No owned state or keyboard behavior. | Content and controls; shared spacing tokens. |
| Muted text | Apply secondary text color with `f-muted`. | Native content semantics; no state or keyboard behavior. | Shared secondary token, automatically light/dark. |
| Screen-reader text | Preserve accessible content while visually hiding it with `f-sr-only`. | Native label/text semantics. | Use on labels and descriptions; not on focusable controls. |
| Icon | Size and stroke caller-owned SVG artwork with `f-icon`. | No added state or keyboard behavior. | Decorative artwork uses `aria-hidden=true`; the enclosing control supplies its label. No Apple artwork is distributed. |
| Mail shell | Opt-in: import `fruitui/mail.css` (or `mail.compat.css`) after the main stylesheet. Arrange toolbars, navigation, list, and reader using `f-mail-container` and `f-mail f-workspace`, with shared `f-pane` classes on its pane elements. | Application owns `data-view=list/message/mailboxes` and navigation. | Named container queries choose desktop, medium, and phone layouts; `--f-sidebar-width`, `--f-list-width`, and `--f-mail-mobile-height` adjust geometry. |

## Native forms and indicators

Shared `forms.css` covers checkbox/radio marks (including indeterminate checkboxes), fieldset/legend, readonly and autofilled inputs, search cancellation, and the additional native controls below. Admin table selection uses the same `f-check` styling as labeled choices. Admin and Support use the same `f-fieldset` reset and legend presentation. Scope `--f-check-size` to adjust a checkbox/radio's dimensions without changing its meaning.

| Purpose | HTML classes and element | Blade adapter |
| --- | --- | --- |
| Group fields | `fieldset.f-fieldset` with a native legend | `x-fruit::fieldset` |
| Choose files | `input.f-input.f-file type=file` | `x-fruit::file` |
| Numeric quantity | `input.f-input type=number` | `x-fruit::number` |
| Calendar value | `input.f-input type=date/datetime-local/month/week`; date and date-time in `div.f-date-picker` | `x-fruit::date` with the corresponding type |
| Time of day | `input.f-input type=time` | `x-fruit::time` |
| Color value | `input.f-input.f-color type=color` in `div.f-color-picker` | `x-fruit::color` |
| Bounded quantity | `input.f-range type=range` | `x-fruit::range` |
| Task completion/activity | `progress.f-progress` | `x-fruit::progress` |
| Bounded measurement | `meter.f-meter` | `x-fruit::meter` |

These adapters emit the native element directly. Put names, labels, validation attributes, `x-model`, and `wire:model` on the real input. Date's small subtype enum covers calendar values; it cannot become a time, file, or text control. Number, time, color, range, and file have fixed types. Keep Input for text-like entry. File uses its native `files` list: read `$event.target.files` in an Alpine `@change` action or use Livewire `wire:model` uploads; do not bind a filename with `x-model` or set a file input value. Application code owns uploads, data persistence, and announcements.

```blade
<x-fruit::fieldset>
    <legend>Import settings</legend>
    <label class="f-field">
        <span class="f-label">Attachment</span>
        <x-fruit::file name="attachment" accept=".csv" wire:model="attachment" />
    </label>
    <label class="f-field">
        <span class="f-label">Seats</span>
        <x-fruit::number name="seats" min="1" max="50" step="1" wire:model="seats" />
    </label>
</x-fruit::fieldset>
```

A readonly field has a secondary surface and text but remains focusable, selectable, and submitted. Disabled fields retain native exclusion from submission. Autofill follows the same palette through an inset surface and text styling where supported; browsers can reserve autofill properties for their own rendering. The native search clear affordance is styled in Blink/WebKit and keeps its input event. Other engines keep their own search rendering.

Browsers draw their own date, time and color popups, which CSS cannot style, so FruitUI replaces them while the native input keeps the value, typing, validation and `wire:model`:

- **Date and date-time** open a FruitUI calendar on a click or Alt+Down, following the [WAI date picker pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/examples/datepicker-dialog/): arrows move by day and week, PageUp/PageDown by month (Shift for a year), Enter chooses, Escape closes. Typing in the field's segments still works and moves the open calendar. Days outside `min`/`max` cannot be chosen; choosing a day keeps a date-time's time. Month and week keep the browser's control.
- **Time** stays typed and stepped with the arrow keys, as on macOS; Chromium's clock button is hidden. Firefox's cannot be hidden by CSS and still opens Firefox's picker.
- **Color** opens a swatch palette from the input's `list` datalist, or Apple's system colors when Blade renders it without one. **Other…** expands a custom editor in the same popover: a saturation/brightness area (drag it, or use the arrow keys), a hue slider and a hex field. Values update the native input as they change and commit on release.

Without JavaScript the browser's pickers remain. For raw HTML, wrap the input in `div.f-date-picker[x-data="fruitDatePicker"]` or `div.f-color-picker[x-data="fruitColorPicker"]` with an empty `<div data-fruit-ui wire:ignore></div>`; Blade emits that markup. File dialogs stay the operating system's. MDN describes these [native styling limits](https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Forms/Advanced_form_styling). Numeric steppers stay available. Range keeps native stepping; Firefox exposes a filled track segment, while Blink/WebKit use the shared neutral track. No script is needed for any of these controls.

Progress represents a task: supply `value` and `max` for completion, omit `value` for indeterminate activity. Reduced motion holds the indeterminate cue still. Meter represents a measurement: native `min`, `max`, `low`, `high`, and `optimum` decide whether the fill uses success, warning, or danger. Include a visible numeric description so meaning does not depend on color. Both require a label or accessible name; progress fallback text alone is [not an accessible label](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/progress#labelling). `--f-indicator-height` adjusts indicator thickness; `--f-color-width` adjusts the color field. Forced colors preserve visible controls and indicator boundaries.

## Native rich text

Within `.fruit-ui`, `blockquote`, inline `code`, `kbd`, `hr`, and `pre` use shared typography and semantic appearance tokens. Code blocks scroll within their available width, nested code avoids a second backplate, and quoted content has a subtle leading rule. These are native HTML elements and work identically in Blade without extra wrappers. For code blocks that can scroll, use `tabindex="0"`, `role="region"`, and an accessible name on the `pre` so keyboard users can scroll it; focus receives the shared accent outline. Applications choose content hierarchy and keyboard shortcut wording.

## Select

Select always uses the real native control. Shared styles in `src/css/select.css` cover its options, groups, selected/hover/focus states, and disabled options using the existing appearance tokens. In desktop browsers supporting `appearance: base-select` and `::picker(select)`, the picker has rounded rows and selection checkmarks in the browser's top layer, so scrolling panes cannot clip it. Native keyboard navigation, validation, form submission/reset, and value/action bindings remain browser-owned.

Touch devices keep their operating system's picker. Unsupported browsers retain a native select with theme-aware option colors where the platform permits them. This is progressive CSS enhancement; native popup borders, spacing, and highlights are platform-controlled in the fallback. Multiple/size listboxes keep native rendering, keyboard range selection, and row sizing; shared option styling applies where supported. They deliberately retain `appearance: auto`: accepting `base-select` does not guarantee complete listbox keyboard support. See MDN's [customizable selects](https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Forms/Customizable_select).

```html
<label class="f-field">
  <span class="f-label">Mailbox</span>
  <select class="f-input" name="mailbox" required>
    <option value="all">All Inboxes</option>
    <optgroup label="Accounts">
      <option value="work">Work</option>
      <option value="personal">Personal</option>
      <option value="offline" disabled>Offline account</option>
    </optgroup>
  </select>
</label>
```

```blade
<label class="f-field">
    <span class="f-label">Included folders</span>
    <x-fruit::select name="folders[]" multiple size="3" wire:model="folders">
        <option value="inbox">Inbox</option>
        <option value="sent">Sent</option>
        <option value="archive">Archive</option>
    </x-fruit::select>
</label>
```

Use ordinary `option`/`optgroup` children and native attributes. Option content stays plain text for fallback compatibility; do not add a parallel hidden input, a synthetic listbox, or another Alpine instance. Light/dark, scoped theme overrides, and forced-colors selection states work through CSS.

## Item lists and rows

Mail messages and Support tickets use the same native list and item-opening button. The list owns arrangement, the button owns activation, and the application owns which item is open. The `quiet` and `filled` variants change appearance while keeping the same button contract. The list is not a listbox and adds no required arrow-key behavior. The examples supply their own arrow-key navigation and responsive screen transitions.

```html
<ul class="f-item-list" role="list" aria-label="Conversations">
  <li>
    <button class="f-item-row f-item-row--filled"
            type="button" aria-current="true">
      <span class="f-avatar f-item-row__leading" aria-hidden="true">SC</span>
      <span class="f-item-row__top">
        <span class="f-item-row__title">Sophie Chen</span>
        <span class="f-item-row__time">10:42</span>
      </span>
      <span class="f-item-row__subtitle">A fresh start</span>
      <span class="f-item-row__preview">A few thoughts on our next release.</span>
      <span class="f-item-row__meta">Work mailbox</span>
    </button>
  </li>
</ul>
```

```blade
<x-fruit::item-list aria-label="Conversations">
    @foreach ($conversations as $conversation)
        <li>
            <x-fruit::item-row variant="filled"
                :aria-current="$openId === $conversation->id ? 'true' : null"
                wire:click="open({{ $conversation->id }})">
                {{ $conversation->sender }}
                <x-slot:leading><span class="f-avatar" aria-hidden="true">{{ $conversation->initials }}</span></x-slot:leading>
                <x-slot:trailing>{{ $conversation->time }}</x-slot:trailing>
                <x-slot:subtitle>{{ $conversation->subject }}</x-slot:subtitle>
                <x-slot:preview>{{ $conversation->preview }}</x-slot:preview>
                <x-slot:meta>{{ $conversation->mailbox_name }}</x-slot:meta>
            </x-fruit::item-row>
        </li>
    @endforeach
</x-fruit::item-list>
```

The default slot supplies the title; an explicit `title` slot can replace it. `leading` is an optional decorative identity cue; `trailing` is time or similar short text; `subtitle`, `preview`, and `meta` are optional supporting content. Slot attributes reach their corresponding spans. All content inside the button must be noninteractive. For an unread cue, compose `span.f-item-row__unread` with `aria-hidden=true` and separate `f-sr-only` text explaining the unread state.

Bulk selection is a separate control beside the opening button:

```blade
<li class="f-row">
    <x-fruit::checkbox name="selected[]" value="42" wire:model="selected">
        Select conversation
    </x-fruit::checkbox>
    <x-fruit::item-row wire:click="open(42)">Sophie Chen</x-fruit::item-row>
</li>
```

Apply layout overrides for the available row width when composing side-by-side controls. Set presentation properties on the list/row through CSS; they do not change the control's native purpose:

| Property | Default / purpose |
| --- | --- |
| `--f-item-list-padding` | `0 8px 8px`; scroll-area padding. |
| `--f-item-row-padding` | `14px 12px`; row padding. |
| `--f-item-row-leading-inset` | `62px` when a leading cue exists, otherwise `12px`; content and separator start inset. |
| `--f-item-row-radius` | `9px`; row corner radius. |
| `--f-item-separator-end` | `12px`; trailing separator inset. |
| `--f-item-current-background`, `--f-item-current-hover` | Current-row surface and hover appearance. |
| `--f-item-current-color`, `--f-item-current-secondary`, `--f-item-current-unread`, `--f-item-current-border` | Current-row text, supporting text, unread cue, and separator appearance. |

Current-state appearance defaults are defined on the row; override them on the row rather than an ancestor. Use semantic `--f-` color tokens so overrides retain both appearances. Responsive Mail/Support aliases now specify only spacing, typography, identity placement, and presentation overrides; shared hover, current state, separators, truncation, and preview clamping live in `src/css/patterns.css`.

## Attachments and empty states

Mail and Chat use a native `a.f-attachment`. The caller supplies the file URL and optional `download` attribute. Blade requires `href`, `:href`, or `x-bind:href` on the native link. File text remains escaped; decorated slots are normal Blade content.

```blade
<x-fruit::attachment :href="$attachment->url" download>
    {{ $attachment->name }}
    <x-slot:detail>{{ $attachment->description }}</x-slot:detail>
    <x-slot:leading><!-- Decorative file SVG --></x-slot:leading>
    <x-slot:trailing><!-- Decorative download SVG --></x-slot:trailing>
</x-fruit::attachment>
```

HTML uses `f-attachment__body`, `f-attachment__detail`, and optional `f-attachment__leading` / `f-attachment__trailing` children. `--f-attachment-gap` and `--f-attachment-padding` adjust spacing. File actions remain native link actions.

All four examples use `f-empty-state` for empty results. It owns no filter state and does not automatically announce itself or move focus. Add appropriate native heading structure and independent recovery actions:

```blade
<x-fruit::empty-state>
    <x-slot:title><h2>No conversations</h2></x-slot:title>
    Try another search.
    <x-slot:actions>
        <x-fruit::button wire:click="clearFilters">Clear filters</x-fruit::button>
    </x-slot:actions>
</x-fruit::empty-state>
```

An optional `icon` slot contains decorative artwork. HTML uses `f-empty-state__icon`, `f-empty-state__title`, `f-empty-state__description`, and `f-empty-state__actions`. `--f-empty-gap`, `--f-empty-padding`, `--f-empty-min-height`, `--f-empty-title-size`, and `--f-empty-description-size` adjust presentation.

## Example boundaries

Charts, customer tabs, full Support inspectors, and Chat reactions remain example compositions; messages, dividers, breadcrumbs and timelines are shared. Their frames, panes, composers, facts, floating disclosures, and table presentation now use the public components below. They are identified as such in the gallery. Application records, searches, queues, assignment, billing, and routing belong to the host application. New examples must compare existing arrangements and extract common presentation once a second actual use demonstrates the need.

## Workspace frames, panes, and resizing

Mail, Support, Chat, and Admin share `.f-workspace` and `.f-pane`. A workspace supplies the grid frame; a pane supplies its surface/minimum sizing. `f-pane--column` arranges content vertically, `f-pane--scroll` scrolls the pane, and `f-pane--border-start/end` adds a divider. Inside a column pane, `f-pane__scroll` fills remaining space and scrolls independently. Name focusable scroll regions and use `tabindex="0"` for keyboard scrolling.

```html
<section class="f-workspace" aria-label="Inbox"
  style="--f-workspace-columns: var(--f-navigation-width, 220px) minmax(0, 1fr); --f-workspace-height: 600px">
  <nav id="navigation" class="f-pane f-pane--column f-pane--scroll f-pane--border-end f-sidebar" aria-label="Mailboxes">…</nav>
  <section id="content" class="f-pane f-pane--column" aria-label="Conversation">
    <header class="f-toolbar">Conversation actions</header>
    <div class="f-pane__scroll" tabindex="0" role="region" aria-label="Conversation history">…</div>
  </section>
  <div class="f-splitter" style="--f-splitter-column: 1" role="separator" tabindex="0"
    aria-orientation="vertical" aria-label="Mailboxes" aria-controls="navigation"
    x-data="fruitSplitter({pane: 'navigation', flexible: 'content', variable: '--f-navigation-width', min: 160, max: 300, reserve: 280})"></div>
</section>
```

```blade
<x-fruit::workspace aria-label="Inbox" style="--f-workspace-columns: var(--f-navigation-width, 220px) minmax(0, 1fr); --f-workspace-height: 600px">
    <nav id="navigation" class="f-pane f-pane--scroll f-sidebar" aria-label="Mailboxes">…</nav>
    <x-fruit::pane id="content" class="f-pane--column">…</x-fruit::pane>
    <x-fruit::splitter pane="navigation" flexible="content" variable="--f-navigation-width"
        :min="160" :max="300" :reserve="280" aria-label="Mailboxes" style="--f-splitter-column: 1" />
</x-fruit::workspace>
```

Workspace tokens: `--f-workspace-columns`, `--f-workspace-rows`, `--f-workspace-height`, `--f-workspace-min-height`, `--f-workspace-radius`, and `--f-workspace-background`. Pane tokens: `--f-pane-background` on the pane and `--f-pane-scroll-padding` on the scroll area. Surface overrides use semantic tokens. Native navigation/article/section elements may use the CSS classes directly; the Blade Workspace wrapper emits a section, and Pane emits a div.

Resize is optional. Register `fruitUI` on the existing Alpine/Livewire Alpine instance; each Splitter enhances only its own divider. It updates the declared `--f-` width variable on the nearest workspace. IDs must be unique and refer to distinct panes inside that frame; the flexible pane reserves `reserve` pixels while other visible tracks retain their widths. `min`/`max` are positive pixel limits. Bounds and accessible values update when the frame or other pane widths change. `--f-splitter-column` selects the pane’s grid track (default 1). Each splitter overlays its existing grid edge; it adds no extra track. `edge="start"` places it at a trailing inspector's leading edge and reverses its width adjustment.

Left/Right move the divider by 8px; Shift uses 32px. Home/End select the bounds. Escape or pointer cancellation restores the width from before a drag; double-click restores the initial width. RTL reverses physical movement correctly. Pane collapse stays with application navigation; resizing does not close a pane. No widths are persisted automatically.

Without Alpine, handles stay hidden and the CSS layout remains usable. **Applications must hide handles in compact container queries when panes stack, disappear, or are replaced by another screen.** Keep enough width for the declared minimums before enabling resizing. The four examples demonstrate this with their existing navigation attributes and breakpoints; narrowing the workspace removes inspectors first, then switches to one visible phone pane. Follow [Apple layout](https://developer.apple.com/design/human-interface-guidelines/layout) and [column views](https://developer.apple.com/design/human-interface-guidelines/column-views) when choosing that hierarchy.

## Message composers

Support replies/notes and Chat conversations/threads use the same `.f-composer` native form arrangement. Compose labels and `.f-input.f-composer__input` textareas, `.f-help`/`.f-error`, optional `f-composer__header`, and `f-composer__footer`. The Blade adapter has a default content slot and forwards native form and submit attributes to the form. Labels, `x-model`, `wire:model`, validation and keyboard shortcuts remain on their real controls. It never owns drafts, reply/note modes or sending behavior.

```blade
<x-fruit::composer wire:submit="send">
    <label class="f-label" for="reply">Reply</label>
    <x-fruit::textarea id="reply" name="reply" class="f-composer__input" wire:model="draft" required aria-describedby="reply-error" />
    @error('draft') <p class="f-error" id="reply-error">{{ $message }}</p> @enderror
    <footer class="f-composer__footer"><x-fruit::button type="submit" variant="primary">Send</x-fruit::button></footer>
</x-fruit::composer>
```

Use `--f-composer-padding`, `--f-composer-background`, `--f-composer-action-gap`, `--f-composer-footer-margin`, `--f-composer-input-min-height`, `--f-composer-input-max-height`, and `--f-composer-input-size`. Native Enter inserts a line break; Chat's send shortcut is example behavior, including Shift+Enter and composition input handling.

## Description lists

Support customer facts and Admin customer/subscription details share `.f-description-list` on a native `dl`. Supply native div groups containing `dt`/`dd`; the Blade adapter emits the same dl and accepts those groups in its default slot.

```blade
<x-fruit::description-list>
    <div><dt>Email</dt><dd>{{ $customer->email }}</dd></div>
    <div><dt>Plan</dt><dd>{{ $customer->plan }}</dd></div>
</x-fruit::description-list>
```

Tokens: `--f-description-columns` (grid tracks), `--f-description-gap`, `--f-description-margin`, `--f-description-term-size`, `--f-description-value-size`, `--f-description-value-margin`, and `--f-description-value-line-height`. Use a container query to reduce columns when facts no longer fit. Data and independent controls remain caller-owned.

## Floating disclosures

Mail's phone options and Chat's reaction picker share native `.f-floating-disclosure` details/summary, with `.f-floating-disclosure__content` holding independent actions. Default placement is below; `f-floating-disclosure--above` changes only positioning. Tokens: `--f-floating-gap`, `--f-floating-width`, `--f-floating-padding`, `--f-floating-radius`, `--f-floating-background`, `--f-floating-shadow`, and `--f-floating-backdrop`. Reduced transparency uses an opaque surface and removes blur.

```blade
<x-fruit::floating-disclosure placement="above" x-data="fruitFloatingDisclosure">
    <x-slot:trigger class="f-button">Options</x-slot:trigger>
    <x-slot:content class="f-stack">
        <x-fruit::button wire:click="markAllRead" x-on:click="close(true)">Mark all read</x-fruit::button>
    </x-slot:content>
</x-fruit::floating-disclosure>
```

`title` is a fallback for the trigger slot. Trigger attributes go to the summary; content attributes go to the panel; default content can replace the content slot. Keep the trigger noninteractive content inside its native summary. Native Enter/Space and open state work without JavaScript. Optional `fruitFloatingDisclosure` closes on an outside pointer without changing focus, or Escape with focus return. An action can call `close(true)` after it completes. There is no menu role, focus trap or arrow-key menu model. Keep enough room for the chosen panel placement within scroll/clipping boundaries.

## Native tables

Admin customers and subscriptions share `.f-table` presentation. The primitive owns native table structure only; callers supply caption, colgroup, thead, tbody, tfoot and scoped row/column headers. A surrounding `.f-table__scroll` region can provide horizontal scrolling without affecting table semantics. Sorting uses independent native buttons and caller-owned `aria-sort` on headers. Selection uses independent checkboxes and optional `data-selected="true"` row presentation.

```blade
<div class="f-table__scroll" tabindex="0" role="region" aria-label="Customers">
    <x-fruit::table>
        <caption>Customers</caption>
        <thead><tr><th scope="col">Name</th><th scope="col">Plan</th></tr></thead>
        <tbody>@foreach ($customers as $customer)
            <tr><th scope="row">{{ $customer->name }}</th><td>{{ $customer->plan }}</td></tr>
        @endforeach</tbody>
    </x-fruit::table>
</div>
```

Tokens: `--f-table-min-width`, `--f-table-font-size`, `--f-table-heading-size`, `--f-table-cell-padding`, and `--f-table-heading-padding`. Text wraps by default; examples may scope nowrap on suitable columns. There is no record fetching, sorting, pagination or CRUD dispatcher in the primitive. Native reading semantics are unchanged in HTML, Blade and Livewire.

## Menus, searchable choices, tokens and rich text

Menus, Combobox, Token Field, Editor, Alert, Tabs, Section Nav, Pagination, Upload rows and related compositions. Every family has a gallery specimen with HTML and Blade usage; the [policy](component-policy.md#blade-contracts) lists the semantic contracts.

### Choosing a control

| Need | Use | Value and responsibility |
| --- | --- | --- |
| A persistent message | `f-alert` / `x-fruit::alert` | Application chooses message, tone, actions, and any live announcement role. |
| Action commands | `f-menu` + `f-menu-item` | `fruitMenu` owns command focus and dismissal; application owns commands. |
| Commands for one item, on right-click | `f-context-menu` / `x-fruit::context-menu` | `fruitContextMenu` owns opening, focus and dismissal; the same commands must also be reachable elsewhere. |
| Independent popup controls | Floating Disclosure | Ordinary controls retain their own keyboard contracts. |
| One searchable existing option | `f-combobox` / `x-fruit::combobox` | The native single select owns its scalar value. |
| Recipients or tags | `f-token-field` / `x-fruit::token-field` | The native textarea owns a newline-delimited string. |
| Rich formatted text | `f-editor` / `x-fruit::editor` | The native textarea owns HTML; an optional Tiptap module supplies editing. |
| Related panels in one page | `f-tabs`, `f-tab` | Linked tabs/panels; optional `fruitTabs`, or existing route-aware callbacks. |
| Related pages | `f-section-nav` | Ordinary links and `aria-current="page"`. |
| Page controls | `f-pagination` | Application pagination; independent links or native buttons. |

Menus follow the [WAI menu button keyboard pattern](https://www.w3.org/WAI/ARIA/apg/patterns/menu-button/); searchable selection follows the [combobox pattern](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/). Popups use the [browser top layer](https://developer.mozilla.org/en-US/docs/Web/API/Popover_API/Using) where available so scrollable panes do not clip them; unsupported browsers retain positioned panels. Register the existing plugin before Alpine starts, including Livewire's Alpine instance. Helpers do not start another instance.

```js
import fruitUI from 'fruitui/alpine';
fruitUI(Alpine);
```

### Pull-down chevrons

Like macOS pull-down buttons, a Menu whose trigger is text shows a chevron after it: Blade adds it to the default `title` trigger. A custom `trigger` slot chooses: add `<span class="f-menu__chevron" aria-hidden="true"></span>` after its text, use the chevron alone in an `f-button--icon` trigger with an `aria-label` (the menu half of a split button), or leave it out for an icon-only "more" button.

```blade
<x-fruit::menu title="Sort">…</x-fruit::menu>

<div class="f-button-group">
    <x-fruit::button type="submit">Send reply</x-fruit::button>
    <x-fruit::menu title="Send options">
        <x-slot:trigger class="f-button--icon" aria-label="Send options"><span class="f-menu__chevron" aria-hidden="true"></span></x-slot:trigger>
        …
    </x-fruit::menu>
</div>
```

### Context menus

A context menu holds commands for the element it sits in: put it inside a list item, message or card, after the content. A secondary click opens it at the pointer; Shift+F10 or the context-menu key opens it below the focused control. It uses the same Menu Items, Checkboxes, Radios, Links, Separators and Groups as Menu, with the same arrows, Home/End and typeahead. Escape or Tab closes it and returns focus; activating a command closes it too. While it is open, its target carries `data-fruit-context-open` and an accent outline, as in macOS.

Context menus are hidden by nature, so offer every command somewhere visible as well: a toolbar, a Menu, or the selection bar. Without JavaScript the browser's own context menu remains.

```blade
<li wire:key="ticket-{{ $ticket->id }}">
    <x-fruit::item-row wire:click="open({{ $ticket->id }})">{{ $ticket->subject }}</x-fruit::item-row>
    <x-fruit::context-menu title="Conversation actions">
        <x-fruit::menu-item wire:click="open({{ $ticket->id }})">Open conversation</x-fruit::menu-item>
        <x-fruit::menu-item wire:click="close({{ $ticket->id }})">Close conversation</x-fruit::menu-item>
    </x-fruit::context-menu>
</li>
```

For raw HTML, put `x-data="fruitCombobox"` on a `div.f-combobox` containing `select.f-input[data-fruit-control]`. Token Field uses `div.f-token-field[x-data="fruitTokenField"]` with `textarea.f-input[data-fruit-control]`. Blade emits that markup. Give the native control a label, name, initial value, and validation/model attributes. The helper creates an unnamed query, copies accessible labels/descriptions, and publishes native events when values change. Editor publishes input while typing and committed change on widget blur. See [adoption contracts](adoption.md) for wrapper attributes, localization and Field associations. Without JavaScript, the select or textarea remains editable and submits the same value format.

Blade includes stable `wire:ignore` containers for generated UI. The named native control remains outside them, so Livewire can update its model, options, labels, validation, disabled, and readonly attributes. For handwritten Livewire markup, include an empty `<div data-fruit-ui wire:ignore></div>` beside the native select/textarea. Rich Editor's toolbar and surface each use `wire:ignore`; its textarea remains outside those boundaries. Keep a widget's DOM identity stable, or key an outer container when replacing it.

```blade
<label for="assignee">Assigned to</label>
<x-fruit::combobox id="assignee" name="assignee" wire:model.live="assignee">
    <option value="">Unassigned</option>
    <option value="alex">Alex Morgan</option>
</x-fruit::combobox>

<label for="cc">Cc</label>
<x-fruit::token-field id="cc" name="cc" wire:model="cc"
    placeholder="Add a recipient">{{ $cc }}</x-fruit::token-field>
```

Token entry adds on Enter, comma, or a multiline/comma paste. Values are trimmed and exact duplicates are ignored. Empty Backspace/Left focuses the last remove button; arrows navigate remove buttons, Escape returns to entry. Adding is atomic for a paste: if application validation rejects any value, the native value is unchanged. Applications can cancel the bubbling `fruit-token-add` event and set `event.detail.error`, or normalize `event.detail.value`. Existing server-provided values are not validated by this event; validate the entire submitted string on the server.

On the server, `FruitUI\Fruit::tokens()` splits the value the way the field shows it: trimmed, without blank lines or exact duplicates. The `FruitUI\Rules\Tokens` rule applies ordinary Laravel rules to every token and reports a failure under the field's own key, so `x-fruit::field` shows it. Bind an existing array back with `implode("\n", $tags)`.

```php
use FruitUI\Fruit;
use FruitUI\Rules\Tokens;

public string $cc = '';

public function send(): void
{
    $this->validate(['cc' => ['nullable', new Tokens('email')]]);

    $recipients = Fruit::tokens($this->cc); // ['ann@example.com', 'bob@example.com']
}
```

Dispatch `fruit-token-reset` on the native textarea to discard pending entry and validation feedback without changing committed tokens. Mail uses it when starting another draft, including when the serialized model was already empty. Ordinary form resets also clear pending entry.

```html
<textarea data-fruit-control name="cc"
  @fruit-token-add="if (!isRecipient($event.detail.value)) {
    $event.detail.error = 'Enter an email address.';
    $event.preventDefault();
  }"></textarea>
```

Choice queries keep focus while `aria-activedescendant` identifies the highlighted option. Up/Down navigate, Enter commits, Escape restores the selected label, and Tab leaves without changing the selection. Disabled options/optgroups are skipped. The enhanced controls preserve form resets, native required/disabled/readonly behavior, label focus, external Alpine model updates, and helper cleanup. Combobox does not support multiple/size modes; use Select for native multiple selection or Token Field for free text tokens.

### Text completions

`x-fruit::autocomplete` suggests completions while typing in one Input or Textarea: mentions after `@`, emoji after `:`, saved replies after `/`, or, without a `trigger`, the current word (for recipient fields). Options are `<option>` elements in its `options` slot; the value is inserted and the label shown. The native control keeps its value, name, models and Field association; inserting a suggestion sends `input` and `change`, so `wire:model` and `x-model` update. For server search, re-render the options from Livewire as the text changes.

```blade
<x-fruit::autocomplete trigger="@">
    <x-fruit::textarea name="reply" wire:model="reply" />
    <x-slot:options>
        @foreach ($agents as $agent)
            <option value="{{ '@'.$agent->handle }}">{{ $agent->name }}</option>
        @endforeach
    </x-slot:options>
</x-fruit::autocomplete>
```

Up/Down choose, Enter or Tab inserts, Escape closes. The control gets `aria-autocomplete="list"` and `aria-activedescendant`; a polite status announces the number of suggestions.

### Command palette

`x-fruit::command-palette` is a modal search over destinations and actions, opened by Cmd/Ctrl with its `shortcut`, or by name like a named Dialog (`Fruit::openDialog('commands')`, `$dispatch('fruit-dialog-open', { name: 'commands' })`). `x-fruit::command-link` items navigate (including `wire:navigate`); `x-fruit::command` items run an action (`wire:click`, `@click`); `x-fruit::command-group` labels related items. Typing filters by label, Up/Down move the highlight, Enter activates it and closes the palette, Escape closes it.

```blade
<x-fruit::command-palette name="commands" shortcut="k" label="Go to">
    <x-fruit::command-group label="Mailboxes">
        <x-fruit::command-link href="{{ route('mailbox', 'inbox') }}" wire:navigate>Inbox</x-fruit::command-link>
    </x-fruit::command-group>
    <x-fruit::command wire:click="compose" shortcut="⌘N">New message</x-fruit::command>
</x-fruit::command-palette>
```

### Optional rich editing

The core Alpine module does not import Tiptap. Import the separate integration on pages that need editing. Its optional peers (installed only by source-editor consumers) are `@tiptap/core`, `@tiptap/pm`, and `@tiptap/starter-kit`; [Tiptap's vanilla installation](https://tiptap.dev/docs/editor/getting-started/install/vanilla-javascript) describes the underlying editor.

```js
import fruitEditor from 'fruitui/editor';
fruitEditor(Alpine); // before this same Alpine instance starts
```

```blade
<label for="signature">Reply signature</label>
<x-fruit::editor id="signature" name="signature" wire:model="signature">
    {{ $signature }}
</x-fruit::editor>
```

The toolbar offers bold, italic, lists, quotes, undo, and redo. Tiptap handles document editing and shortcuts. The adapter escapes the initial HTML into a textarea; with JavaScript disabled it is an editable HTML source field. The editor mirrors an HTML string back to the same named textarea. Empty documents serialize as an empty string for native required validation. Readonly and disabled controls block editing; form resets and external model changes update the document. Sanitize and validate submitted HTML in the application before storing or rendering it. The editor schema is not a server sanitizer.

Use `f-prose` around sanitized message content to scope paragraph, list, quote, image, and table presentation. Wrap wide tables in `f-prose__scroll` with `role="region"`, `tabindex="0"`, and an accessible name; this keeps keyboard scrolling within the message. Do not add `f-prose` to the entire application.

### Composing the remaining pieces

`f-button-group` joins independent Buttons and Menus for split actions. `f-input-group` joins native inputs, `f-input-group__addon` units, and action buttons; use `aria-describedby` for a meaningful unit. `f-chip` holds a value and an independent `f-chip__remove` button. None owns the surrounding form's value.

`f-upload` rows combine File, Progress, text/links, and independent cancel/retry/remove buttons. The application owns FileList handling and transport. The Mail and Support examples simulate upload progress locally and provide downloadable browser blobs; they send no files to a server. `f-spinner` is decorative activity: keep a readable action name, set `aria-busy`, and use native disabled when repeated activation must be blocked. Reduced motion stops spinning.

`f-avatar` also accepts an `img` with meaningful alt text, or empty alt when an adjacent name supplies identity. `<x-fruit::avatar>` is decorative by default; `label` gives it an accessible identity and `src` renders a photo. `f-avatar-group` overlaps independent avatars; `f-presence` must have adjacent readable status or equivalent accessible text. `f-notifications` arranges grouped native lists of destination links, badges, and independent actions; unread counts and read state stay in the application.

Tooltips use `f-tooltip` with `x-data="fruitTooltip"`, a focusable control referencing a noninteractive `f-tooltip__text[role="tooltip"]` through `aria-describedby`. CSS reveals help on hover/focus; the helper dismisses on Escape, including hover-only disclosure. Keep essential labels and instructions visible. In Blade, `text-id` names the tooltip text and the trigger references it, so the association is rendered by the server and survives Livewire morphs:

```blade
<x-fruit::tooltip text="Move this conversation to the archive." text-id="archive-help">
    <x-fruit::button aria-describedby="archive-help" wire:click="archive">Archive</x-fruit::button>
</x-fruit::tooltip>
``` Alerts do not automatically acquire a live role based on tone; add `role="status"` or `role="alert"` when new feedback should be announced.

All new CSS uses existing semantic appearance tokens, follows system light/dark changes, and supports explicit theme overrides. The examples keep routing, recipient rules, upload state, and record data outside the framework.

## Conversations, selection, loading and files

These cover the core screens of helpdesks, mail and chat. Contracts are in the [policy](component-policy.md#blade-contracts); every one has a gallery specimen.

- **Message** (`x-fruit::message`): one message's avatar, author, meta, time, body, attachments and footer. `layout="inline"` suits chat (Chat's channel); `layout="stacked"` suits email and helpdesk threads (Support, Chat threads); `variant="note"` marks internal notes. Reactions and reply counts are independent controls in the footer. The `actions` slot holds quick actions (react, reply in thread, quote) in a labelled group that appears at the message's top corner on hover or whenever focus is inside it, and stays visible on touch screens; stacked messages give it a column beside the header so the time stays readable. Offer the same commands elsewhere when they matter, since hover is not discoverable.
- **Long histories**: render a window of recent messages and load older ones as the reader scrolls up, rather than the whole history: a `wire:intersect="loadOlder"` sentinel before the first message, with a Skeleton while it loads (see [infinite scroll](#app-shells-icons-and-infinite-scroll)). Chat apps usually add a "Jump to latest" button once the reader scrolls back; the Chat example shows one.
- **Divider** (`x-fruit::divider`): days, "New messages" (`tone="accent"`) or reply counts (`align="start"`).
- **Timeline** (`x-fruit::timeline`, `timeline-item`): conversation history and audit logs, with a native `time`.
- **Bulk selection**: put a Checkbox beside each Item Row in its list item; the list gets a checkbox column. `x-fruit::selection-bar :count="count($selected)"` shows the count and independent actions, and renders nothing at zero.
- **Loading**: `x-fruit::skeleton :lines="3"` inside a container with `aria-busy="true"`, for example a Livewire lazy component's `placeholder()`. Busy buttons (Livewire `data-loading`, or `aria-busy`) show a progress cursor; compose `x-fruit::spinner` beside a readable label.
- **Files**: `x-fruit::dropzone` wraps a native file input. Dropped files are filtered by `accept` and `multiple`, then assigned to the input with `input` and `change`, so `wire:model` uploads and change handlers work unchanged. Use Livewire's `temporaryUrl()` with Avatar or Attachment for previews.
- **Uploads**: `x-fruit::upload-list` with `upload-row` (`name`, `state`: uploading, complete, error, cancelled, and `progress`). With Livewire uploads, read progress from the input's `livewire-upload-progress` event, as below.
- **Notifications**: `x-fruit::notification-group heading="Today"` with `x-fruit::notification` links (`avatar`, `meta` and `badge` slots).
- **Typing**: `x-fruit::typing` renders a persistent status region; give it text such as "Mia is typing…" while someone types, and leave it empty otherwise.
- **Back**: `x-fruit::back-link` returns to the parent screen in compact layouts, with its title truncated. Buttons that switch views in place use the `f-back` class.
- **Badges** take `tone` (accent, success, warning, danger), `variant="outline"` and a `dot` slot for status labels.
- **Menus** add Menu Checkbox, Menu Radio, Menu Link, Menu Separator, Menu Group and `shortcut` hints; checked state is the caller's (`checked` or `x-bind:aria-checked`).
- **Breadcrumbs** (`x-fruit::breadcrumbs`, `crumb`): ancestors link, the current page uses `current`.

```blade
<x-fruit::item-list aria-label="Conversations">
    @foreach ($conversations as $conversation)
        <li wire:key="conversation-{{ $conversation->id }}">
            <x-fruit::checkbox wire:model.live="selected" value="{{ $conversation->id }}">
                <span class="f-sr-only">Select {{ $conversation->customer }}</span>
            </x-fruit::checkbox>
            <x-fruit::item-row wire:click="open({{ $conversation->id }})">{{ $conversation->customer }}</x-fruit::item-row>
        </li>
    @endforeach
</x-fruit::item-list>
<x-fruit::selection-bar :count="count($selected)" aria-label="Selected conversations">
    <x-fruit::button size="small" wire:click="closeSelected">Close selected</x-fruit::button>
</x-fruit::selection-bar>
```

```blade
<div x-data="{ progress: null }">
    <x-fruit::dropzone wire:model="attachments" multiple
        x-on:livewire-upload-start="progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-finish="progress = null" />
    <x-fruit::upload-list x-show="progress !== null">
        <li class="f-upload__row" data-state="uploading"><div class="f-upload__body">
            <strong>Uploading</strong><progress class="f-progress" max="100" :value="progress" aria-label="Upload progress"></progress>
        </div></li>
    </x-fruit::upload-list>
    <x-fruit::upload-list aria-label="Attached files">
        @foreach ($attachments as $index => $file)
            <x-fruit::upload-row :name="$file->getClientOriginalName()">
                <x-slot:actions><x-fruit::button size="small" wire:click="removeAttachment({{ $index }})">Remove</x-fruit::button></x-slot:actions>
            </x-fruit::upload-row>
        @endforeach
    </x-fruit::upload-list>
</div>
```

## App shells, icons and infinite scroll

**Persistent shells.** Put the sidebar in the layout inside `@persist('sidebar')` and give its links `wire:navigate`. The sidebar keeps its element (and scroll position) across navigations; Livewire marks the link for the current URL with `data-current`, which Sidebar Items and Section Nav style like `aria-current="page"`.

```blade
@persist('sidebar')
    <x-fruit::sidebar class="f-pane f-pane--column" aria-label="App">
        <x-fruit::sidebar-item :href="route('inbox')" :current="request()->routeIs('inbox')" wire:navigate>Inbox</x-fruit::sidebar-item>
    </x-fruit::sidebar>
@endpersist
```

**Icons.** FruitUI ships no icon set. Any SVG set works; `.f-icon` sizes it (20px, or `--f-icon-size`), draws strokes in `currentColor` and leaves fills off, which suits outline sets such as Heroicons through blade-icons: `<x-heroicon-o-archive-box class="f-icon" aria-hidden="true" />`. Put icons in the `icon`, `leading` or `avatar` slots, or inside buttons beside a text or `aria-label` name.

**Infinite scroll.** End the list with a sentinel that loads more when it scrolls into view, and show placeholders while it loads:

```blade
@if ($conversations->hasMorePages())
    <div wire:intersect="loadMore" aria-busy="true"><x-fruit::skeleton :lines="2" /></div>
@endif
```

**Islands and lazy placeholders.** A Livewire island renders part of a component on its own, so slow or secondary content (customer history, stats, related records) neither delays the first paint nor re-renders on every update. Give a lazy island a placeholder in the same shape: a busy container with a Skeleton. Livewire loads the island when its placeholder scrolls into view. Ordinary updates skip it; an action marked `wire:island="history"`, or `$this->renderIsland('history')` in PHP, re-renders it. The Support desk example renders the open customer's earlier conversations this way and re-renders the island from `open()`.

```blade
@island(name: 'history', lazy: true)
    @placeholder
        <section aria-label="Earlier conversations" aria-busy="true"><x-fruit::skeleton :lines="2" /></section>
    @endplaceholder
    <section aria-labelledby="history-title">
        <h2 id="history-title">Earlier conversations</h2>
        <x-fruit::timeline aria-labelledby="history-title">…</x-fruit::timeline>
    </section>
@endisland
```

Keep islands outside elements whose `wire:key` changes, such as a pane keyed per record: replacing the keyed element detaches the island and its next render is lost. Key an inner wrapper instead. A lazy component works the same way: return the busy Skeleton container from its `placeholder()` method.

## Field associations and validation errors

`x-fruit::field` composes one native control with its `label`, an optional `description` and its error. Child form adapters, including Checkbox, Radio and Switch, inherit the id and merged ARIA descriptions. The id is `control-id` when given, otherwise the child's own `id`, otherwise one derived from its `wire:model` or `name` (`form.email` becomes `field-form-email`). Plain HTML children need `control-id` and their own associations. Field associates exactly one control and owns no value, rule or model; use Fieldset for choice groups.

The error comes from Laravel's shared `$errors` bag, the same one `@error` reads: Field shows the first message for its control's `wire:model` key, or its `name` (`items[0][title]` becomes `items.0.title`; a trailing `[]` is dropped). That covers controller validation after a redirect and Livewire `validate()` alike. `bag` reads a named bag, as with `validateWithBag()`. An explicit `error` string takes precedence, and `error=""` shows no error.

```blade
<x-fruit::field label="Email" description="Use your work address">
    <x-fruit::input type="email" wire:model.live.blur="form.email" />
</x-fruit::field>

<x-fruit::field label="Terms">
    <x-fruit::checkbox wire:model="terms">I accept the terms</x-fruit::checkbox>
</x-fruit::field>
```

Input's type is fixed at render time, with one exception: a password input may bind `x-bind:type` (or `::type`) so a toggle can reveal what was typed. Keep the toggle a separate, labelled button that reports its state with `aria-pressed`; the input keeps its name, `autocomplete` and `wire:model`.

```blade
<x-fruit::field label="Password" x-data="{ shown: false }">
    <div class="f-input-group">
        <x-fruit::input type="password" wire:model="password" autocomplete="current-password"
            x-bind:type="shown ? 'text' : 'password'" />
        <x-fruit::button type="button" x-on:click="shown = !shown" x-bind:aria-pressed="shown">Show</x-fruit::button>
    </div>
</x-fruit::field>
```

## Server feedback: dialogs and toasts

Put one `<x-fruit::toaster />` in the layout (in HTML, one element with `x-data="fruitToast"`). It announces `fruit-toast` window events, sent by `Fruit::toast()`, the `$toast()` Alpine magic or `toast()` from `fruitui/alpine`, and, on page load, a `fruit-toast` value flashed to the session. `duration` (default 4000 ms, 0 keeps it) controls dismissal; hover and focus pause it. It uses the top layer where supported, so an open modal dialog does not cover it; a modal makes the rest of the page inert, though, so report results of a dialog's task after it closes, or inside the dialog. It shows one message at a time and does not queue or route notifications.

`FruitUI\Fruit` sends feedback from Livewire components, form objects, actions and controllers:

```php
use FruitUI\Fruit;

Fruit::toast('Conversation closed.');   // now; outside a Livewire request, on the next page
Fruit::flashToast('Closed.');           // on the next page, e.g. before $this->redirect(...)
Fruit::openDialog('close-ticket');      // Livewire requests only
Fruit::closeDialog('close-ticket');
```

A dialog opens in one of two ways. Bind its open state when the server owns it; Escape and `<form method="dialog">` buttons close it and update the property:

```blade
<x-fruit::dialog wire:model="closing" aria-labelledby="close-title">
    …
    <form method="dialog"><x-fruit::button type="submit">Cancel</x-fruit::button></form>
</x-fruit::dialog>
```

Or give it a `name` and open or close it with events, from the server (`Fruit::openDialog`) or the browser (`$dispatch('fruit-dialog-open', { name: 'close-ticket' })`). Either way, Livewire morphs leave the dialog's own attributes alone, so an open dialog stays open while its content re-renders.

In tests, `Livewire::test()` gains matching assertions. `assertToasted()` accepts a toast dispatched in the request or flashed for the next page, optionally with its exact message:

```php
Livewire::test(Inbox::class)
    ->call('archive')
    ->assertToasted('Conversation archived.')
    ->assertDialogClosed('confirm-archive');

Livewire::test(Inbox::class)->call('confirm')->assertDialogOpened('confirm-archive')->assertNotToasted();
```

## Pagination

`fruit::pagination.default` renders a Laravel paginator with `x-fruit::pagination`: a range summary, Previous/Next and page links. Use it with `$items->links('fruit::pagination.default')`, or make it the default with `Paginator::defaultView()` in a service provider.

In Livewire components, set `'pagination_theme' => 'fruit'` in `config/livewire.php`. Paginators then call Livewire's page actions instead of links, without a per-component `paginationView()`. Labels use Laravel translations: `Previous`, `Next`, `Page :page` and `:first–:last of :total`.

## Livewire request states

Livewire 4 marks the element that started a request with `data-loading`. Busy buttons show a progress cursor. During `wire:submit`, Livewire disables or locks every control in the form; FruitUI keeps their appearance instead of dimming the whole form. (A control that was already disabled looks enabled until the request ends.) Livewire also marks `wire:navigate` links for the current page with `data-current`; Sidebar items and Section Nav links style it like `aria-current="page"`, so a persisted sidebar stays correct after navigation.

## Text size

Text uses a web reading scale of `--f-text-*` tokens in rem: at the default browser size, body text (`--f-text-md`) is 16px, controls (`--f-text-base`) 15px, secondary text (`--f-text-sm`, `--f-text-xs`) 14px and 13px, and the smallest labels (`--f-text-2xs`) 12px. Headings use `--f-text-lg` to `--f-text-5xl` (17px to 40px). Sizes follow the reader's browser text setting. Buttons and fields are 36px tall (44px on touch screens). An html scope sizes its body rather than html, which would redefine rem. A host that fixes a pixel font size on html (Bootstrap 3 uses 10px) pins the scale with `--f-text-root: 16px` on its `.fruit-ui` scope. Spacing and control heights remain in pixels.
