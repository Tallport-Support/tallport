<?php

namespace App\Http\Controllers;

use App\Conversation;
use App\Option;
use App\Subscription;
use App\User;
use Illuminate\Http\Request;
use Validator;

class SettingsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * General settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function view($section = 'general')
    {
        $settings = $this->getSectionSettings($section);

        if (!$settings) {
            abort(404);
        }

        $sections = $this->getSections();

        $template_vars = [
            'settings'     => $settings,
            'section'      => $section,
            'sections'     => $this->getSections(),
            'section_name' => $sections[$section]['title'],
        ];
        $template_vars = $this->getTemplateVars($section, $template_vars);

        return view('settings/view', $template_vars);
    }

    public function getValidator($section)
    {
        $rules = $this->getSectionParams($section, 'validator_rules');

        if (!empty($rules)) {
            return Validator::make(request()->all(), $rules);
        }
    }

    public function getTemplateVars($section, $template_vars)
    {
        $section_vars = $this->getSectionParams($section, 'template_vars');

        if ($section_vars && is_array($section_vars)) {
            return array_merge($template_vars, $section_vars);
        } else {
            return $template_vars;
        }
    }

    /**
     * Parameters of the sections settings.
     *
     * If in settings parameter `env` is set, option will be saved into .env file
     * instead of DB.
     *
     * @param [type] $section [description]
     * @param string $param   [description]
     *
     * @return [type] [description]
     */
    public function getSectionParams($section, $param = '')
    {
        $params = [];

        switch ($section) {
            case 'emails':
                $params = [
                    'template_vars' => [
                        'sendmail_path' => ini_get('sendmail_path'),
                        'mail_drivers'  => [
                            'mail'     => __("PHP's mail() function"),
                            'sendmail' => __('Sendmail'),
                            'smtp'     => 'SMTP',
                        ],
                    ],
                    'validator_rules' => [
                        'settings.mail_host' => 'safehost',
                        'settings.mail_from' => 'required|email',
                    ],
                    'settings' => [
                        'fetch_schedule' => [
                            'env' => 'APP_FETCH_SCHEDULE',
                        ],
                        'mail_password' => [
                            'safe_password' => true,
                            'encrypt' => true,
                        ],
                        // 'use_mail_date_on_fetching' => [
                        //     'env' => 'APP_USE_MAIL_DATE_ON_FETCHING',
                        // ],
                    ],
                ];
                break;
            case 'general':
                $params = [
                    'settings' => [
                        'custom_number' => [
                            'env' => 'APP_CUSTOM_NUMBER',
                        ],
                        'max_message_size' => [
                            'env' => 'APP_MAX_MESSAGE_SIZE',
                        ],
                        'email_conv_history' => [
                            'env' => 'APP_EMAIL_CONV_HISTORY',
                        ],
                        'email_user_history' => [
                            'env' => 'APP_EMAIL_USER_HISTORY',
                        ],
                        'locale' => [
                            'env' => 'APP_LOCALE',
                        ],
                        'timezone' => [
                            'env' => 'APP_TIMEZONE',
                        ],
                        'user_permissions' => [
                            'env' => 'APP_USER_PERMISSIONS',
                            'env_encode' => true,
                        ],
                    ],
                ];
                break;
            case 'alerts':
                $subscriptions_defaults = Subscription::getDefaultSubscriptions();
                $subscriptions = array();
                foreach ($subscriptions_defaults as $medium => $subscriptions_for_medium) {
                    foreach ($subscriptions_defaults[$medium] as $subscription) {
                        $subscriptions[] = (object) array("medium" => $medium, "event" => $subscription);
                    }
                }
                $params = [
                    'template_vars' => [
                        'logs' => \App\ActivityLog::getAvailableLogs(),
                        'person' => null,
                        'subscriptions' => $subscriptions,
                        'mobile_available' => \Eventy::filter('notifications.mobile_available', false),
                    ],
                    'settings' => [
                        'alert_logs' => [
                            'env' => 'APP_ALERT_LOGS',
                        ],
                        'alert_logs_period' => [
                            'env' => 'APP_ALERT_LOGS_PERIOD',
                        ],
                    ],
                ];

                // todo: monitor App Logs
                foreach ($params['template_vars']['logs'] as $i => $log) {
                    if ($log == \App\ActivityLog::NAME_APP_LOGS || $log == \App\ActivityLog::NAME_OUT_EMAILS) {
                        unset($params['template_vars']['logs'][$i]);
                    }
                }

                break;
            case 'ai':
                $params = [
                    'template_vars' => [
                        'ai_languages' => \App\Ai\Settings::displayNames(),
                        'ai_mailboxes' => \App\Mailbox::orderBy('name')->get(),
                    ],
                    'validator_rules' => [
                        'settings.aiassistant\.base_url'                => 'nullable|url:http,https',
                        'settings.aiassistant\.documentation\.embedding_base_url' => 'nullable|url:http,https',
                        'settings.aiassistant\.drafts_per_day'          => 'nullable|integer|min:0|max:10000',
                        'settings.aiassistant\.daily_tokens'            => 'nullable|integer|min:0|max:1000000000',
                        'settings.aiassistant\.translations_per_customer_hour' => 'nullable|integer|min:0|max:10000',
                        'settings.aiassistant\.customer_context_url.*'  => 'nullable|url:http,https|max:2048',
                        'settings.aiassistant\.customer_context_guidance.*' => 'nullable|string|max:6000',
                    ],
                    'settings' => [
                        'aiassistant.api_key' => [
                            'safe_password' => true,
                            'encrypt'       => true,
                        ],
                        'aiassistant.documentation.embedding_api_key' => [
                            'safe_password' => true,
                            'encrypt'       => true,
                        ],
                    ],
                ];
                break;
            case 'api':
                $params = [
                    'template_vars' => [
                        'api_key'        => \App\Api\ApiKey::globalKey(),
                        'api_keys'       => \App\Api\ApiKey::with('user')->orderBy('user_id')->orderBy('id')->get(),
                        'webhooks'       => \App\Api\Webhook::orderBy('id')->get(),
                        'webhook_secret' => \App\Api\Webhook::secret(),
                        'webhook_events' => \App\Api\Webhook::allEvents(),
                        'mailboxes'      => \App\Mailbox::orderBy('name')->get(),
                    ],
                    'validator_rules' => [
                        'settings.api\.cors_hosts' => 'nullable|string|max:1000',
                    ],
                    'settings' => [
                        'api.cors_hosts' => [
                            'env' => 'APIWEBHOOKS_CORS_HOSTS',
                        ],
                    ],
                ];
                break;
            default:
                $params = \Eventy::filter('settings.section_params', $params, $section);
                break;
        }

        $params = \Eventy::filter('settings.alter_section_params', $params, $section);

        if ($param) {
            if (isset($params[$param])) {
                return $params[$param];
            } else {
                return;
            }
        } else {
            return $params;
        }
    }

    public function getSectionSettings($section)
    {
        $settings = [];

        switch ($section) {
            case 'general':
                $settings = [
                    'company_name'         => Option::get('company_name', \Config::get('app.name')),
                    'next_ticket'          => (Option::get('next_ticket') >= Conversation::max('number') + 1) ? Option::get('next_ticket') : Conversation::max('number') + 1,
                    'custom_number'        => (int)config('app.custom_number'),
                    'user_permissions'     => User::getGlobalUserPermissions(),
                    'email_branding'       => Option::get('email_branding'),
                    'open_tracking'        => Option::get('open_tracking'),
                    'email_conv_history'   => config('app.email_conv_history'),
                    'max_message_size'     => config('app.max_message_size'),
                    'email_user_history'   => config('app.email_user_history'),
                    \App\Misc\Gravatar::OPTION => Option::get(\App\Misc\Gravatar::OPTION, false),
                    \App\Misc\Gravatar::DEFAULT_OPTION => Option::get(\App\Misc\Gravatar::DEFAULT_OPTION, ''),
                    'time_format'          => Option::get('time_format', User::TIME_FORMAT_24),
                    'locale'               => \Helper::getRealAppLocale(),
                    'timezone'             => config('app.timezone'),
                    'attachment_reminder_phrases' => Option::get(\App\Http\Controllers\AttachmentsController::REMINDER_OPTION, \App\Http\Controllers\AttachmentsController::REMINDER_DEFAULT),
                ];
                break;
            case 'emails':
                $settings = [
                    'mail_from'       => \App\Misc\Mail::getSystemMailFrom(),
                    'mail_driver'     => Option::get('mail_driver', \Config::get('mail.driver')),
                    'mail_host'       => Option::get('mail_host', \Config::get('mail.host')),
                    'mail_port'       => Option::get('mail_port', \Config::get('mail.port')),
                    'mail_username'   => Option::get('mail_username', \Config::get('mail.username')),
                    'mail_password'   => \Helper::decrypt(Option::get('mail_password', \Config::get('mail.password'))),
                    'mail_encryption' => Option::get('mail_encryption', \Config::get('mail.encryption')),
                    'noreply_emails'  => implode("\n", \App\Misc\Noreply::customPatterns()),
                    'fetch_schedule'  => config('app.fetch_schedule'),
                    //'use_mail_date_on_fetching'             => config('app.use_mail_date_on_fetching'),
                ];
                break;
            case 'alerts':
                $settings = Option::getOptions([
                    'alert_recipients',
                    'alert_fetch',
                    'alert_fetch_period',
                    'alert_logs',
                    'alert_logs_names',
                    'alert_logs_period',
                    'alert_logs_fetch_min_occurrences',
                    'subscription_defaults',
                ], [
                    'alert_logs_names'                 => [],
                    'alert_logs'                       => config('app.alert_logs'),
                    'alert_logs_period'                => config('app.alert_logs_period'),
                    'alert_logs_fetch_min_occurrences' => \App\Console\Commands\LogsMonitor::FETCH_ERRORS_MIN_OCCURRENCES_DEFAULT,
                ]);
                break;
            case 'ai':
                $settings = [
                    'aiassistant.provider'                        => \App\Ai\Settings::provider(),
                    'aiassistant.api_key'                         => \App\Ai\Settings::apiKey(),
                    'aiassistant.base_url'                        => \App\Ai\Settings::baseUrl(),
                    'aiassistant.model'                           => Option::get('aiassistant.model', ''),
                    'aiassistant.translation_model'               => Option::get('aiassistant.translation_model', ''),
                    'aiassistant.daily_tokens'                    => \App\Ai\Settings::dailyTokens(),
                    'aiassistant.translations_per_customer_hour'  => \App\Ai\Settings::translationsPerCustomerHour(),
                    'aiassistant.documentation.embedding_provider' => \App\Ai\Settings::embeddingProviderIsSame() ? 'same' : \App\Ai\Settings::embeddingProvider(),
                    'aiassistant.documentation.embedding_api_key' => \Helper::decrypt(Option::get('aiassistant.documentation.embedding_api_key', '')),
                    'aiassistant.documentation.embedding_base_url' => Option::get('aiassistant.documentation.embedding_base_url', ''),
                    'aiassistant.documentation.embedding_model'   => Option::get('aiassistant.documentation.embedding_model', ''),
                    'aiassistant.documentation.chunk_size'        => \App\Ai\Settings::chunkSize(),
                    'aiassistant.documentation.chunk_overlap'     => \App\Ai\Settings::chunkOverlap(),
                    'aiassistant.documentation.retrieval_limit'   => \App\Ai\Settings::retrievalLimit(),
                    'aiassistant.summary_conversation_threshold'  => \App\Ai\Settings::summaryThreshold(),
                    'aiassistant.translation_language'            => \App\Ai\Settings::defaultLanguage(),
                    'aiassistant.drafts_per_day'                  => \App\Ai\Settings::draftsPerDay(null),
                    'aiassistant.mailbox_language'                => (array) Option::get('aiassistant.mailbox_language', []),
                    'aiassistant.mailbox_features_off'            => (array) Option::get('aiassistant.mailbox_features_off', []),
                    'aiassistant.mailbox_chat_translation'        => (array) Option::get('aiassistant.mailbox_chat_translation', []),
                    'aiassistant.customer_context_url'            => (array) Option::get('aiassistant.customer_context_url', []),
                    'aiassistant.customer_context_secret_key'     => (array) Option::get('aiassistant.customer_context_secret_key', []),
                    'aiassistant.customer_context_signature_header' => (array) Option::get('aiassistant.customer_context_signature_header', []),
                    'aiassistant.customer_context_guidance'       => (array) Option::get('aiassistant.customer_context_guidance', []),
                ];
                break;
            case 'api':
                $settings = [
                    'api.cors_hosts' => config('api.cors_hosts'),
                ];
                break;
            case 'retention':
                $settings = [];
                foreach (array_keys(\App\Retention\Retention::OPTIONS) as $name) {
                    $settings[$name] = \App\Retention\Retention::get($name);
                }
                break;
            case 'branding':
                $settings = [];
                foreach (\App\Misc\Branding::SETTINGS as $name) {
                    $settings[$name] = \App\Misc\Branding::get($name, $name == 'branding.widget_powered_by' ? true : '');
                }
                break;
            default:
                $settings = \Eventy::filter('settings.section_settings', $settings, $section);
                break;
        }

        $settings = \Eventy::filter('settings.alter_section_settings', $settings, $section);

        return $settings;
    }

    public function getSections()
    {
        $sections = [
            // todo: order
            'general' => ['title' => __('General'), 'icon' => 'cog', 'order' => 100],
            'emails'  => ['title' => __('Mail Settings'), 'icon' => 'transfer', 'order' => 200],
            'alerts'  => ['title' => __('Alerts'), 'icon' => 'bell', 'order' => 300],
            'ai'      => ['title' => __('AI Assistant'), 'icon' => 'ai', 'order' => 400],
            'api'     => ['title' => __('API & Webhooks'), 'icon' => 'transfer', 'order' => 600],
            'retention' => ['title' => __('Retention'), 'icon' => 'archive', 'order' => 620],
            'branding' => ['title' => __('Appearance'), 'icon' => 'adjust', 'order' => 650],
        ];
        $sections = \Eventy::filter('settings.sections', $sections);

        return $sections;
    }

    /**
     * Save general settings.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function save($section = 'general')
    {
        $settings = $this->getSectionSettings($section);

        if (!$settings) {
            abort(404);
        }

        if ($section == 'ai') {
            $this->normalizeAiSettings(request());
        }
        if ($section == 'branding') {
            $error = $this->prepareBrandingSettings(request());
            if ($error) {
                return redirect()->route('settings', ['section' => $section])->withErrors($error)->withInput();
            }
        }

        return $this->processSave($section, array_keys($settings));
    }

    /**
     * Branding settings as stored: images saved (or removed), HTML and CSS
     * made safe. Returns errors by field, or null.
     */
    protected function prepareBrandingSettings(Request $request)
    {
        $values = (array) $request->settings;
        foreach (array_keys(\App\Misc\Branding::IMAGES) as $name) {
            $field = str_replace('.', '_', $name);
            try {
                $values[$name] = \App\Misc\Branding::saveImage($name, $request->file($field), $request->input($field.'_remove'));
            } catch (\Throwable $e) {
                return [$field => $e->getMessage()];
            }
        }
        foreach (['branding.footer', 'branding.email_header', 'branding.email_footer'] as $name) {
            $values[$name] = \Helper::stripDangerousTags((string) ($values[$name] ?? ''));
        }
        foreach (['branding.css', 'branding.email_css'] as $name) {
            $values[$name] = \App\Misc\Branding::sanitizeCss($values[$name] ?? '');
        }
        $values['branding.accent'] = in_array($values['branding.accent'] ?? '', \FruitUI\Fruit::ACCENTS, true) ? $values['branding.accent'] : 'blue';
        $values['branding.widget_powered_by'] = !empty($values['branding.widget_powered_by']);
        $request->merge(['settings' => $values]);

        return null;
    }

    /**
     * AI Assistant settings as stored: trimmed, within bounds, and the
     * per-mailbox feature checkboxes as the features turned off.
     */
    protected function normalizeAiSettings(Request $request)
    {
        $input = (array) $request->settings;
        foreach (['aiassistant.base_url', 'aiassistant.documentation.embedding_base_url'] as $name) {
            if (isset($input[$name])) {
                $input[$name] = rtrim(trim($input[$name]), '/');
            }
        }
        foreach (['aiassistant.model', 'aiassistant.translation_model', 'aiassistant.documentation.embedding_model'] as $name) {
            if (isset($input[$name])) {
                $input[$name] = trim($input[$name]);
            }
        }
        if (isset($input['aiassistant.provider'])) {
            $input['aiassistant.provider'] = \App\Ai\Providers::normalize($input['aiassistant.provider']);
        }
        if (isset($input['aiassistant.documentation.embedding_provider']) && $input['aiassistant.documentation.embedding_provider'] != 'same') {
            $input['aiassistant.documentation.embedding_provider'] = \App\Ai\Providers::normalize($input['aiassistant.documentation.embedding_provider']);
        }
        foreach ([
            'aiassistant.documentation.chunk_size'       => [500, 20000],
            'aiassistant.documentation.chunk_overlap'    => [0, 5000],
            'aiassistant.documentation.retrieval_limit'  => [1, 20],
            'aiassistant.summary_conversation_threshold' => [0, 10],
        ] as $name => $bounds) {
            if (isset($input[$name])) {
                $input[$name] = max($bounds[0], min($bounds[1], (int) $input[$name]));
            }
        }
        // The mailbox settings (sent when there are mailboxes).
        if (!isset($input['aiassistant.mailbox_language'])) {
            $request->merge(['settings' => $input]);

            return;
        }
        $input['aiassistant.mailbox_language'] = array_filter((array) $input['aiassistant.mailbox_language'], [\App\Ai\Settings::class, 'isLanguage']);

        $on = (array) ($input['aiassistant.mailbox_features_on'] ?? []);
        $off = [];
        foreach (\App\Mailbox::pluck('id') as $mailbox_id) {
            $mailbox_off = array_values(array_diff(\App\Ai\Settings::FEATURES, array_keys((array) ($on[$mailbox_id] ?? []))));
            if ($mailbox_off) {
                $off[$mailbox_id] = $mailbox_off;
            }
        }
        unset($input['aiassistant.mailbox_features_on']);
        $input['aiassistant.mailbox_features_off'] = $off;
        $input['aiassistant.mailbox_chat_translation'] = array_map('intval', array_filter((array) ($input['aiassistant.mailbox_chat_translation'] ?? [])));

        // Customer context secrets are stored encrypted; a masked one is kept.
        $secrets = (array) Option::get('aiassistant.customer_context_secret_key', []);
        foreach ((array) ($input['aiassistant.customer_context_secret_key'] ?? []) as $mailbox_id => $secret) {
            if (!preg_match('/^\*+$/', (string) $secret)) {
                $secrets[$mailbox_id] = (string) $secret === '' ? '' : encrypt((string) $secret);
            }
        }
        $input['aiassistant.customer_context_secret_key'] = $secrets;
        foreach (['url', 'signature_header', 'guidance'] as $name) {
            $input['aiassistant.customer_context_'.$name] = array_map(function ($value) {
                return trim((string) $value);
            }, (array) ($input['aiassistant.customer_context_'.$name] ?? []));
        }

        $request->merge(['settings' => $input]);
    }

    public function processSave($section, $settings)
    {
        // Validate
        $validator = $this->getValidator($section);

        if ($validator && $validator->fails()) {
            return redirect()->route('settings', ['section' => $section])
                        ->withErrors($validator)
                        ->withInput();
        }

        $request = request();

        $request = \Eventy::filter('settings.before_save', $request, $section, $settings);

        $cc_required = false;
        $settings_params = $this->getSectionParams($section, 'settings');
        foreach ($settings as $i => $option_name) {
            // Do not save dummy passwords, and keep the password when the
            // field wasn't sent at all.
            if (!empty($settings_params[$option_name])
                && !empty($settings_params[$option_name]['safe_password'])
                && (!isset($request->settings[$option_name])
                    || preg_match("/^\*+$/", $request->settings[$option_name]))
            ) {
                continue;
            }

            // Option has to be saved to .env file.
            if (!empty($settings_params[$option_name]) && !empty($settings_params[$option_name]['env'])) {
                $env_value = $request->settings[$option_name] ?? '';

                if (is_array($env_value)) {
                    $env_value = json_encode($env_value);
                }

                if ($env_value !== '' && !empty($settings_params[$option_name]['encrypt'])) {
                    $env_value = encrypt($env_value);
                }

                if (!empty($settings_params[$option_name]['env_encode'])) {
                    $env_value = base64_encode($env_value);
                }

                \Helper::setEnvFileVar($settings_params[$option_name]['env'], $env_value);

                config($option_name, $env_value);
                $cc_required = true;
                continue;
            }

            // By some reason isset() does not work for empty elements.
            if (isset($request->settings) && array_key_exists($option_name, $request->settings)) {
                $option_value = $request->settings[$option_name];

                if (!empty($settings_params[$option_name]['encrypt'])) {
                    $option_value = encrypt($option_value);
                }

                Option::set($option_name, $option_value);
            } else {
                // If option does not exist, default will be used,
                // so we can not just remove bool settings.
                if (isset($settings_params[$option_name]['default'])) {
                    $default = $settings_params[$option_name]['default'];
                } else {
                    $default = \Option::getDefault($option_name, null);
                }
                if ($default === true) {
                    Option::set($option_name, false);
                } elseif (is_array(\Option::getDefault($option_name, -1))) {
                    Option::set($option_name, []);
                } else {
                    Option::remove($option_name);
                }
            }
        }

        // Clear cache if some options have been saved to .env file.
        // Clearing the cache also restarts queue:work as it also
        // needs to get new .env parameters.
        if ($cc_required) {
            \Helper::clearCache(['--doNotGenerateVars' => true]);
        }

        // \Helper::clearCache prevents \Session::flash() from displaying.
        $request->session()->flash('flash_success_floating', __('Settings updated'));

        $response = redirect()->route('settings', ['section' => $section]);

        $response = \Eventy::filter('settings.after_save', $response, $request, $section, $settings);

        return $response;
    }

    /**
     * Users ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $user = auth()->user();

        switch ($request->action) {

            // Test sending emails from mailbox
            case 'send_test':

                if (empty($request->to)) {
                    $response['msg'] = __('Please specify recipient of the test email');
                }

                if (!$response['msg']) {
                    $test_result = [
                        'status' => 'error',
                    ];

                    try {
                        $test_result = \MailHelper::sendTestMail($request->to);
                    } catch (\Exception $e) {
                        $test_result['msg'] = $e->getMessage();
                    }

                    if ($test_result['status'] == 'error') {
                        $response['msg'] = $test_result['msg']
                            ?: __('Error occurred sending email. Please check your mail server logs for more details.');
                    }

                    $response['log'] = $test_result['log'] ?? '';
                }

                if (!$response['msg']) {
                    $response['status'] = 'success';
                }

                // Remember email address
                if (!empty($request->to)) {
                    \App\Option::set('send_test_to', $request->to);
                }
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occurred';
        }

        return \Response::json($response);
    }
}
