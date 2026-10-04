{{-- The groups of conditions or actions of the workflow form (tallportWorkflowEditor in public/js/workflows.js). --}}
<div class="workflow-editor" data-mode="{{ $mode }}">
    <template x-for="(group, group_index) in editors.{{ $mode }}" :key="group.id">
        <div>
            <div class="wf-and f-muted" x-show="group_index > 0">{{ __('and') }}</div>
            <div class="f-card wf-group">
                <div class="wf-group__body">
                    <template x-for="(row, row_index) in group.rows" :key="row.id">
                        <div>
                            <div class="wf-or f-muted" x-show="row_index > 0">{{ __('or') }}</div>
                            <div class="wf-row">
                                <select class="f-input wf-type" x-bind:value="row.type" x-on:change="setType('{{ $mode }}', row, $event.target.value)" aria-label="{{ $mode == 'conditions' ? __('Select a condition') : __('Select an action') }}">
                                    <option value="">-- {{ $mode == 'conditions' ? __('Select a condition') : __('Select an action') }} --</option>
                                    @foreach ($config[$mode] as $config_group)
                                        @if (!empty($config_group['title']))
                                            <optgroup label="{{ $config_group['title'] }}">
                                        @endif
                                        @foreach ($config_group['items'] ?? [] as $item_key => $item)
                                            <option value="{{ $item_key }}">{{ $item['title'] }}</option>
                                        @endforeach
                                        @if (!empty($config_group['title']))
                                            </optgroup>
                                        @endif
                                    @endforeach
                                </select>

                                {{-- Made again when the type changes. --}}
                                <template x-for="row_type in (itemOf('{{ $mode }}', row).title ? [row.type] : [])" :key="row_type">
                                    <span class="wf-row__fields" x-data="{ item: itemOf('{{ $mode }}', row) }">
                                        <span class="wf-operator">
                                            <template x-if="item.operators">
                                                <select class="f-input wf-operator-select" x-on:change="row.operator = $event.target.value">
                                                    <template x-for="(title, key) in item.operators" :key="key">
                                                        <option x-bind:value="key" x-text="title" x-bind:selected="key == row.operator"></option>
                                                    </template>
                                                </select>
                                            </template>
                                        </span>
                                        <span class="wf-value">
                                            <template x-if="item.values_type == 'date'">
                                                <span>
                                                    <input type="number" min="1" class="f-input wf-number" x-model="row.number">
                                                    <select class="f-input wf-metric" x-model="row.metric">
                                                        <option value="i">{{ __('Minutes') }}</option>
                                                        <option value="h">{{ __('Hours') }}</option>
                                                        <option value="d">{{ __('Days') }}</option>
                                                    </select>
                                                </span>
                                            </template>
                                            <template x-if="item.values_type != 'date' && item.values_type != 'email' && item.values && item.values.length && item.multiple">
                                                <select class="f-input wf-value-select" multiple x-on:change="row.value = Array.from($el.selectedOptions, option => option.value)">
                                                    <template x-for="pair in item.values" :key="pair[0]">
                                                        <option x-bind:value="pair[0]" x-text="pair[1]" x-bind:selected="row.value.includes(pair[0])"></option>
                                                    </template>
                                                </select>
                                            </template>
                                            <template x-if="item.values_type != 'date' && item.values_type != 'email' && item.values && item.values.length && !item.multiple">
                                                <select class="f-input wf-value-select" x-on:change="row.value = $event.target.value">
                                                    <template x-for="pair in item.values" :key="pair[0]">
                                                        <option x-bind:value="pair[0]" x-text="pair[1]" x-bind:selected="pair[0] === row.value"></option>
                                                    </template>
                                                </select>
                                            </template>
                                            <template x-if="item.values_type != 'date' && item.values_type != 'email' && !item.values">
                                                <input type="text" class="f-input wf-text" x-bind:placeholder="item.placeholder || ''" x-model="row.value">
                                            </template>
                                        </span>
                                    </span>
                                </template>

                                <button type="button" class="f-button f-button--ghost f-button--icon wf-remove" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}" x-on:click="removeRow('{{ $mode }}', group, row)">&times;</button>

                                {{-- Reply, email to the customer, forward, note: the email's fields. --}}
                                <template x-for="email_type in (itemOf('{{ $mode }}', row).values_type == 'email' ? [row.type] : [])" :key="email_type">
                                    <div class="wf-email">
                                        <template x-if="row.type == 'forward'">
                                            <div class="f-field wf-email-field">
                                                <label class="f-label" x-bind:for="'wf-' + row.id + '-to'">{{ __('To') }}</label>
                                                <input type="text" class="f-input" x-bind:id="'wf-' + row.id + '-to'" x-model="row.email.to">
                                            </div>
                                        </template>
                                        <template x-if="row.type != 'note'">
                                            <div class="f-field wf-email-field">
                                                <label class="f-label" x-bind:for="'wf-' + row.id + '-cc'">{{ __('Cc') }}</label>
                                                <input type="text" class="f-input" x-bind:id="'wf-' + row.id + '-cc'" x-model="row.email.cc">
                                            </div>
                                        </template>
                                        <template x-if="row.type != 'note'">
                                            <div class="f-field wf-email-field">
                                                <label class="f-label" x-bind:for="'wf-' + row.id + '-bcc'">{{ __('Bcc') }}</label>
                                                <input type="text" class="f-input" x-bind:id="'wf-' + row.id + '-bcc'" x-model="row.email.bcc">
                                            </div>
                                        </template>
                                        <template x-if="row.type == 'email_customer'">
                                            <div class="f-field wf-email-field">
                                                <label class="f-label" x-bind:for="'wf-' + row.id + '-subject'">{{ __('Subject') }}</label>
                                                <input type="text" class="f-input" x-bind:id="'wf-' + row.id + '-subject'" x-model="row.email.subject">
                                            </div>
                                        </template>
                                        <div x-init="mountEditor($el, row)"></div>
                                        <template x-if="row.type == 'reply' || row.type == 'email_customer'">
                                            <label class="f-check"><input type="checkbox" x-model="row.email.no_signature"> {{ __('Without signature') }}</label>
                                        </template>
                                        <template x-if="row.type != 'note'">
                                            <div class="f-field wf-email-field">
                                                <label class="f-label" x-bind:for="'wf-' + row.id + '-history'">{{ __('Conversation History') }}</label>
                                                <select class="f-input" x-bind:id="'wf-' + row.id + '-history'" x-on:change="row.email.conv_history = $event.target.value">
                                                    <template x-if="row.type != 'forward'">
                                                        <option value="" x-bind:selected="row.email.conv_history == ''">{{ __('Use global setting') }}</option>
                                                    </template>
                                                    <template x-if="row.type != 'forward'">
                                                        <option value="none" x-bind:selected="row.email.conv_history == 'none'">{{ __('None') }}</option>
                                                    </template>
                                                    <option value="last" x-bind:selected="row.email.conv_history == 'last'">{{ __('Last message') }}</option>
                                                    <option value="full" x-bind:selected="row.email.conv_history == 'full'">{{ __('Full history') }}</option>
                                                </select>
                                            </div>
                                        </template>
                                        <template x-if="row.type != 'note'">
                                            <div class="f-field wf-email-field">
                                                <label class="f-label" x-bind:for="'wf-' + row.id + '-sender'">{{ __('Sender Name') }}</label>
                                                <select class="f-input" x-bind:id="'wf-' + row.id + '-sender'" x-on:change="row.email.sender_name = $event.target.value">
                                                    <template x-if="row.type == 'reply'">
                                                        <option value="{{ App\Workflows\Actions::SENDER_ASSIGNEE_OR_MAILBOX }}" x-bind:selected="row.email.sender_name == '{{ App\Workflows\Actions::SENDER_ASSIGNEE_OR_MAILBOX }}'">{{ __('Assignee or Mailbox name (if unassigned)') }}</option>
                                                    </template>
                                                    <option value="{{ App\Workflows\Actions::SENDER_MAILBOX }}" x-bind:selected="row.email.sender_name == '{{ App\Workflows\Actions::SENDER_MAILBOX }}'">{{ __('Mailbox name') }}</option>
                                                    <option value="{{ App\Workflows\Actions::SENDER_WORKFLOW }}" x-bind:selected="row.email.sender_name == '{{ App\Workflows\Actions::SENDER_WORKFLOW }}'">{{ __('Workflow-user name') }}</option>
                                                </select>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                    @if ($mode == 'conditions')
                        <button type="button" class="f-button f-button--ghost f-button--small wf-add-or" x-on:click="addRow('{{ $mode }}', group)">{{ __('+ OR') }}</button>
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
<button type="button" class="f-button f-button--small wf-add-group" x-on:click="addGroup('{{ $mode }}')">{{ $mode == 'conditions' ? __('+ AND') : __('+ Action') }}</button>
