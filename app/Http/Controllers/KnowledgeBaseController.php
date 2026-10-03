<?php

namespace App\Http\Controllers;

use App\KbArticle;
use App\Mailbox;
use Illuminate\Http\Request;

/**
 * The knowledge base: articles for agents (Knowledge Base in the menu),
 * written by admins and users allowed to manage it, inserted in replies
 * from the editor.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $query = KbArticle::visibleTo($user);
        $search = trim((string) $request->q);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($query) use ($like) {
                $query->where('title', 'like', $like)->orWhere('body', 'like', $like);
            });
        }
        if ($request->filled('category')) {
            $request->category == '-' ? $query->whereNull('category') : $query->where('category', $request->category);
        }

        return view('kb/index', [
            'articles'   => $query->orderBy('category')->orderBy('title')->get(),
            'categories' => KbArticle::visibleTo($user)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'search'     => $search,
            'category'   => (string) $request->category,
            'can_manage' => KbArticle::canManage($user),
        ]);
    }

    public function show($id)
    {
        $article = KbArticle::visibleTo(auth()->user())->findOrFail($id);

        return view('kb/article', [
            'article'    => $article,
            'categories' => KbArticle::visibleTo(auth()->user())->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function edit($id = null)
    {
        $user = auth()->user();
        $article = $id ? KbArticle::visibleTo($user)->findOrFail($id) : new KbArticle();
        if (!KbArticle::canManage($user) || ($article->exists && !$article->canBeEditedBy($user))) {
            abort(403);
        }

        return view('kb/edit', [
            'article'    => $article,
            'mailboxes'  => $user->mailboxesCanView(),
            'categories' => KbArticle::visibleTo($user)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function save(Request $request)
    {
        $user = auth()->user();
        $article = $request->article_id ? KbArticle::visibleTo($user)->findOrFail($request->article_id) : new KbArticle();
        if (!KbArticle::canManage($user) || ($article->exists && !$article->canBeEditedBy($user))) {
            abort(403);
        }
        $request->validate([
            'title'    => 'required|string|max:'.KbArticle::TITLE_MAX_LENGTH,
            'category' => 'nullable|string|max:'.KbArticle::CATEGORY_MAX_LENGTH,
            'body'     => 'nullable|string',
        ]);
        $mailbox_id = (int) $request->mailbox_id ?: null;
        if (($mailbox_id && !in_array($mailbox_id, $user->mailboxesIdsCanView())) || (!$mailbox_id && !$user->isAdmin())) {
            return back()->withInput()->withErrors(['mailbox_id' => __('Choose a mailbox you have access to.')]);
        }

        $article->title = trim(strip_tags($request->title));
        $article->category = trim(strip_tags((string) $request->category)) ?: null;
        $article->body = \Helper::stripDangerousTags((string) $request->body);
        $article->mailbox_id = $mailbox_id;
        if (!$article->exists) {
            $article->created_by_user_id = $user->id;
        }
        $article->updated_by_user_id = $user->id;
        $article->save();

        \Session::flash('flash_success_floating', __('Article saved'));

        return redirect()->route('kb.article', ['id' => $article->id]);
    }

    public function delete($id)
    {
        $article = KbArticle::visibleTo(auth()->user())->findOrFail($id);
        if (!$article->canBeEditedBy(auth()->user())) {
            abort(403);
        }
        $article->delete();
        \Session::flash('flash_success_floating', __('Article deleted'));

        return redirect()->route('kb');
    }

    /**
     * The reply editor: an article's text to insert.
     */
    public function ajax(Request $request)
    {
        $response = ['status' => 'error', 'msg' => ''];
        $user = auth()->user();

        switch ($request->action) {
            case 'get':
                $article = KbArticle::visibleTo($user)->find($request->article_id);
                if (!$article) {
                    $response['msg'] = __('Article not found');
                    break;
                }
                $response['body'] = (string) $article->body;
                $response['status'] = 'success';
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        return \Response::json($response);
    }

    /**
     * Articles for a mailbox's reply editor: [{id, title, category}].
     */
    public static function forEditor($mailbox_id)
    {
        return KbArticle::forMailbox($mailbox_id)->orderBy('category')->orderBy('title')
            ->get(['id', 'title', 'category'])->toArray();
    }
}
