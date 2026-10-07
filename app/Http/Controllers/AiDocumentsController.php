<?php

namespace App\Http\Controllers;

use App\Ai\Document;
use App\Ai\DocumentApiKey;
use App\Ai\Documents;
use App\Jobs\AiIndexDocument;
use App\Mailbox;
use Illuminate\Http\Request;

/**
 * AI Assistant documentation (Manage » Settings » AI Assistant »
 * Documentation), and the API websites push documents to.
 */
class AiDocumentsController extends Controller
{
    const MAX_API_CONTENT = 1000000;

    public function index()
    {
        return view('settings/ai_documents', [
            'documents' => Document::with('mailbox')->withCount('chunks')->orderBy('mailbox_id')->orderBy('title')->get(),
            'mailboxes' => Mailbox::orderBy('name')->get(),
            'api_keys'  => DocumentApiKey::all()->keyBy('mailbox_id'),
        ]);
    }

    public function action(Request $request)
    {
        switch ($request->action) {
            case 'add':
                $mailbox = Mailbox::findOrFail((int) $request->mailbox_id);
                $urls = array_unique(array_filter(array_map([Document::class, 'normalizeSourceUrl'], preg_split('/\R+/', (string) $request->urls))));
                $invalid = array_filter($urls, function ($url) {
                    return !Document::isHttpUrl($url) || mb_strlen($url) > 2048;
                });
                if (!$urls || $invalid) {
                    return back()->withInput()->withErrors(['urls' => $invalid
                        ? __('Not an http or https URL').': '.implode(', ', $invalid)
                        : __('Enter at least one URL.')]);
                }
                foreach ($urls as $url) {
                    $document = Document::firstOrNew([
                        'mailbox_id'        => $mailbox->id,
                        'source_identifier' => hash('sha256', $url),
                    ]);
                    if (!$document->exists) {
                        $document->mailbox_id = $mailbox->id;
                        $document->source_identifier = hash('sha256', $url);
                        $document->source_type = Document::SOURCE_TYPE_URL;
                        $document->source_url = $url;
                        $document->title = mb_substr(basename((string) parse_url($url, PHP_URL_PATH)) ?: $url, 0, 191);
                        $document->localized_urls = Document::localizedUrlsFor($url);
                        $document->status = Document::STATUS_PENDING;
                    }
                    $document->enabled = true;
                    $document->save();
                    AiIndexDocument::dispatch($document->id, true);
                }
                \Session::flash('flash_success_floating', __('Documentation added. It is being fetched and indexed.'));
                break;

            case 'toggle':
                $document = Document::findOrFail((int) $request->document_id);
                $document->enabled = !$document->enabled;
                $document->save();
                if ($document->enabled) {
                    AiIndexDocument::dispatch($document->id, true);
                }
                break;

            case 'delete':
                $document = Document::findOrFail((int) $request->document_id);
                $document->chunks()->delete();
                $document->delete();
                \Session::flash('flash_success_floating', __('Documentation deleted.'));
                break;

            case 'index':
                $documents = Document::where('enabled', true);
                if ($request->document_id) {
                    $documents->where('id', (int) $request->document_id);
                }
                foreach ($documents->pluck('id') as $document_id) {
                    AiIndexDocument::dispatch($document_id, true, (bool) $request->force);
                }
                \Session::flash('flash_success_floating', __('Documentation is being fetched and indexed.'));
                break;

            case 'issue_key':
                $mailbox = Mailbox::findOrFail((int) $request->mailbox_id);
                \Session::flash('ai_new_key', ['mailbox' => $mailbox->name, 'key' => DocumentApiKey::issue($mailbox->id)]);
                break;

            case 'revoke_key':
                DocumentApiKey::where('mailbox_id', (int) $request->mailbox_id)->delete();
                \Session::flash('flash_success_floating', __('API key revoked.'));
                break;

            default:
                abort(404);
        }

        return redirect()->route('ai.documents');
    }

    /**
     * Push a document (Markdown) for a mailbox: Authorization: Bearer <key>.
     * The document is indexed right away.
     */
    public function api(Request $request)
    {
        $token = trim((string) $request->header('Authorization'));
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        $api_key = DocumentApiKey::findByToken($token);
        if (!$api_key) {
            return response()->json(['status' => 'error', 'message' => $token === '' ? 'Missing Authorization bearer token.' : 'Invalid Authorization bearer token.'], 401);
        }

        $data = $request->json()->all() ?: $request->all();
        $errors = $this->apiErrors($data);
        if ($errors) {
            return response()->json(['status' => 'error', 'message' => 'Documentation payload is invalid.', 'errors' => $errors], 422);
        }

        $identifier = trim($data['identifier']);
        $content = trim($data['content']);
        $public_url = !empty($data['public_url']) ? Document::normalizeSourceUrl($data['public_url']) : '';

        $document = Document::firstOrNew([
            'mailbox_id'        => $api_key->mailbox_id,
            'source_identifier' => Document::apiSourceIdentifier($identifier),
        ]);
        $created = !$document->exists;
        $document->mailbox_id = $api_key->mailbox_id;
        $document->source_identifier = Document::apiSourceIdentifier($identifier);
        $document->source_type = Document::SOURCE_TYPE_API;
        $document->title = mb_substr(trim($data['title'] ?? '') ?: Documents::title($content) ?: $identifier, 0, 191);
        $document->source_url = $public_url ?: 'api://'.mb_substr(trim(preg_replace('/[^A-Za-z0-9._:-]+/', '-', $identifier), '-') ?: hash('sha256', $identifier), 0, 190);
        $document->canonical_locale = trim($data['canonical_locale'] ?? '') ?: Document::CANONICAL_LOCALE;
        $document->localized_urls = $this->localizedUrls($data, $public_url);
        $document->enabled = !array_key_exists('enabled', $data) || filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
        $document->metadata = ['api_identifier' => $identifier, 'submitted_at' => now()->toDateTimeString(), 'has_public_url' => (bool) $public_url];
        $content_changed = $document->content_hash !== hash('sha256', $content);
        $document->content = $content;
        $document->content_hash = hash('sha256', $content);
        if ($created || $content_changed) {
            $document->status = Document::STATUS_PENDING;
        }
        $document->save();

        $api_key->last_used_at = now();
        $api_key->save();

        $indexing = ['status' => 'skipped', 'message' => 'Document is disabled'];
        if (!Documents::available()) {
            $indexing = ['status' => 'skipped', 'message' => 'The embedding provider does not support embeddings'];
        } elseif ($document->enabled) {
            try {
                $indexing = Documents::needsIndexing($document)
                    ? ['status' => 'indexed', 'message' => Documents::index($document).' chunks']
                    : ['status' => 'skipped', 'message' => 'Document is unchanged'];
            } catch (\Throwable $e) {
                \Helper::logException($e, '[AI] Documentation API, document #'.$document->id.':');

                return response()->json([
                    'status'   => 'error',
                    'message'  => 'Documentation was saved, but indexing failed.',
                    'document' => $this->apiDocument($document->fresh()),
                    'error'    => ['type' => 'indexing_failed', 'detail' => $e->getMessage()],
                ], 500);
            }
        }

        return response()->json([
            'status'   => 'success',
            'message'  => $created ? 'Documentation created.' : 'Documentation updated.',
            'document' => $this->apiDocument($document->fresh()),
            'indexing' => $indexing,
        ], $created ? ($indexing['status'] == 'skipped' ? 202 : 201) : 200);
    }

    protected function apiErrors(array $data)
    {
        $errors = [];
        if (empty($data['identifier']) || !is_string($data['identifier']) || mb_strlen(trim($data['identifier'])) > 191) {
            $errors['identifier'] = ['Identifier is required and must be 191 characters or fewer.'];
        }
        if (empty($data['content']) || !is_string($data['content']) || trim($data['content']) === '') {
            $errors['content'] = ['Content is required.'];
        } elseif (strlen($data['content']) > self::MAX_API_CONTENT) {
            $errors['content'] = ['Content must be '.self::MAX_API_CONTENT.' bytes or fewer.'];
        }
        foreach (['title' => 191, 'public_url' => 2048, 'canonical_locale' => 10] as $field => $max) {
            if (isset($data[$field]) && (!is_string($data[$field]) || mb_strlen(trim($data[$field])) > $max)) {
                $errors[$field] = [ucfirst(str_replace('_', ' ', $field)).' must be a string of '.$max.' characters or fewer.'];
            }
        }
        if (!empty($data['public_url']) && !isset($errors['public_url']) && !Document::isHttpUrl(trim($data['public_url']))) {
            $errors['public_url'] = ['Public URL must be a valid http or https URL.'];
        }
        if (!empty($data['canonical_locale']) && !in_array(trim($data['canonical_locale']), Document::SUPPORTED_LOCALES)) {
            $errors['canonical_locale'] = ['Canonical locale must be one of: '.implode(', ', Document::SUPPORTED_LOCALES).'.'];
        }
        if (isset($data['enabled']) && filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
            $errors['enabled'] = ['Enabled must be true or false.'];
        }
        if (isset($data['localized_urls'])) {
            if (!is_array($data['localized_urls'])) {
                $errors['localized_urls'] = ['Localized URLs must be an object keyed by locale.'];
            } else {
                foreach ($data['localized_urls'] as $locale => $url) {
                    if (!in_array($locale, Document::SUPPORTED_LOCALES)) {
                        $errors['localized_urls.'.$locale] = ['Locale must be one of: '.implode(', ', Document::SUPPORTED_LOCALES).'.'];
                    } elseif ($url !== null && $url !== '' && (!is_string($url) || mb_strlen($url) > 2048 || !Document::isHttpUrl(trim($url)))) {
                        $errors['localized_urls.'.$locale] = ['Localized URL must be a valid http or https URL of 2048 characters or fewer.'];
                    }
                }
            }
        }

        return $errors;
    }

    protected function localizedUrls(array $data, $public_url)
    {
        if (!empty($data['localized_urls']) && is_array($data['localized_urls'])) {
            $urls = [];
            foreach ($data['localized_urls'] as $locale => $url) {
                if (is_string($url) && trim($url) !== '') {
                    $urls[$locale] = Document::normalizeSourceUrl($url);
                }
            }

            return $urls;
        }

        return $public_url ? Document::localizedUrlsFor($public_url) : [];
    }

    protected function apiDocument(Document $document)
    {
        return [
            'id'              => $document->id,
            'mailbox_id'      => $document->mailbox_id,
            'title'           => $document->title,
            'identifier'      => $document->metadata['api_identifier'] ?? null,
            'source_type'     => $document->source_type,
            'source_url'      => $document->isPrivate() ? null : $document->source_url,
            'status'          => $document->status,
            'chunks_count'    => $document->chunks()->count(),
            'content_hash'    => $document->content_hash,
            'last_indexed_at' => $document->last_indexed_at ? $document->last_indexed_at->toDateTimeString() : null,
            'last_error'      => $document->last_error,
        ];
    }
}
