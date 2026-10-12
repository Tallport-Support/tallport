<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Documentation in GitHub repositories, read through GitHub's API: a file's
 * Markdown, and the Markdown files in a folder. A mailbox's access tokens
 * (aiassistant.github_token) read private repositories: each is for an owner
 * (the account or organization in the link; a fine-grained token covers one),
 * one without an owner for the others. They are sent to api.github.com only.
 */
class GitHub
{
    const API_URL = 'https://api.github.com';

    const MAX_FOLDER_FILES = 500;

    const MARKDOWN_EXTENSIONS = ['md', 'mdx', 'markdown'];

    /**
     * A GitHub link: [owner, repo, type (file|folder), ref (null: the default branch), path], else null.
     * The branch is the part after blob/ or tree/ up to the next slash.
     */
    public static function parse($url)
    {
        $url = trim((string) $url);
        if (preg_match('#^https://github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#i', $url, $m)) {
            return ['owner' => $m[1], 'repo' => $m[2], 'type' => 'folder', 'ref' => null, 'path' => ''];
        }
        if (preg_match('#^https://github\.com/([\w.-]+)/([\w.-]+)/(blob|tree)/([^/?\#]+)(?:/([^?\#]*))?#i', $url, $m)) {
            $path = trim(rawurldecode($m[5] ?? ''), '/');
            if ($m[3] == 'blob' && $path === '') {
                return null;
            }

            return ['owner' => $m[1], 'repo' => $m[2], 'type' => $m[3] == 'blob' ? 'file' : 'folder', 'ref' => rawurldecode($m[4]), 'path' => $path];
        }
        if (preg_match('#^https://raw\.githubusercontent\.com/([\w.-]+)/([\w.-]+)/([^/?\#]+)/([^?\#]+)#i', $url, $m)) {
            return ['owner' => $m[1], 'repo' => $m[2], 'type' => 'file', 'ref' => rawurldecode($m[3]), 'path' => trim(rawurldecode($m[4]), '/')];
        }

        return null;
    }

    /**
     * A file's link on GitHub.
     */
    public static function fileUrl($owner, $repo, $ref, $path)
    {
        return 'https://github.com/'.$owner.'/'.$repo.'/blob/'.rawurlencode($ref).'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * The mailbox's access tokens by owner (lowercase; '' for the other owners).
     */
    public static function tokens($mailbox_id)
    {
        $stored = ((array) \Option::get('aiassistant.github_token', []))[$mailbox_id] ?? [];
        // One token for every owner, before tokens had owners.
        if (!is_array($stored)) {
            $stored = ['' => $stored];
        }
        $tokens = [];
        foreach ($stored as $owner => $encrypted) {
            $token = (string) \Helper::decrypt($encrypted);
            if ($token !== '') {
                $tokens[(string) $owner] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Stores the mailbox's access tokens by owner, encrypted.
     */
    public static function setTokens($mailbox_id, array $tokens)
    {
        $all = (array) \Option::get('aiassistant.github_token', []);
        $stored = [];
        foreach ($tokens as $owner => $token) {
            if (trim((string) $token) !== '') {
                $stored[strtolower(trim((string) $owner))] = encrypt(trim((string) $token));
            }
        }
        if ($stored) {
            $all[$mailbox_id] = $stored;
        } else {
            unset($all[$mailbox_id]);
        }
        \Option::set('aiassistant.github_token', $all);
    }

    /**
     * The token for a repository's owner: its own, else the one for other owners, else none.
     */
    public static function tokenFor($owner, $mailbox_id)
    {
        $tokens = self::tokens($mailbox_id);

        return $tokens[strtolower($owner)] ?? $tokens[''] ?? '';
    }

    /**
     * A file's Markdown: [content, private].
     */
    public static function fetchFile(array $link, $mailbox_id, ?float $deadline = null)
    {
        $repository = self::repository($link, $mailbox_id, $deadline);
        $path = 'contents/'.implode('/', array_map('rawurlencode', explode('/', $link['path'])))
            .'?ref='.rawurlencode($link['ref'] ?? $repository['default_branch']);
        $limited = OutboundHttp::limitedResponseOptions(Documents::MAX_DOCUMENT_BYTES, $too_large);
        try {
            $response = self::get($link, $path, $repository['token'], $deadline, 'application/vnd.github.raw', $limited);
        } catch (\Throwable $e) {
            if ($too_large) {
                throw new \Exception('Unable to fetch Markdown: larger than '.Documents::MAX_DOCUMENT_BYTES.' bytes', 0, $e);
            }
            throw $e;
        }

        return [$response->body(), $repository['private']];
    }

    /**
     * The Markdown files in a folder (and its subfolders): [file links, private].
     */
    public static function folderFiles(array $link, $mailbox_id, ?float $deadline = null)
    {
        $repository = self::repository($link, $mailbox_id, $deadline);
        $ref = $link['ref'] ?? $repository['default_branch'];
        $response = self::get($link, 'git/trees/'.rawurlencode($ref).'?recursive=1', $repository['token'], $deadline);
        if (!is_array($response->json('tree'))) {
            throw new \RuntimeException(__('GitHub sent an error (:status).', ['status' => $response->status()]));
        }
        if ($response->json('truncated')) {
            throw new \RuntimeException(__('GitHub sent an incomplete file list.'));
        }

        $prefix = $link['path'] === '' ? '' : $link['path'].'/';
        $urls = [];
        foreach ($response->json('tree') as $entry) {
            $path = (string) ($entry['path'] ?? '');
            if (($entry['type'] ?? '') == 'blob' && str_starts_with($path, $prefix)
                && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MARKDOWN_EXTENSIONS)
            ) {
                $urls[] = self::fileUrl($link['owner'], $link['repo'], $ref, $path);
            }
        }
        if (count($urls) > self::MAX_FOLDER_FILES) {
            throw new \RuntimeException('More than '.self::MAX_FOLDER_FILES.' Markdown files in the folder');
        }

        return [$urls, $repository['private']];
    }

    /**
     * The repository's default branch, whether it is private, and the token that reads it.
     */
    protected static function repository(array $link, $mailbox_id, ?float $deadline)
    {
        $token = self::tokenFor($link['owner'], $mailbox_id);
        $response = self::get($link, '', $token, $deadline);

        return [
            'default_branch' => (string) ($response->json('default_branch') ?: 'main'),
            'private'        => (bool) $response->json('private'),
            'token'          => $token,
        ];
    }

    protected static function get(array $link, $path, $token, ?float $deadline, $accept = 'application/vnd.github+json', array $options = [])
    {
        $remaining = $deadline === null ? 30 : (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new \RuntimeException('AI document deadline exceeded');
        }
        $headers = ['Accept' => $accept, 'X-GitHub-Api-Version' => '2022-11-28', 'Accept-Encoding' => 'identity'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $url = self::API_URL.'/repos/'.rawurlencode($link['owner']).'/'.rawurlencode($link['repo']).($path === '' ? '' : '/'.$path);
        try {
            $response = Http::withOptions(\Helper::setGuzzleDefaultOptions(array_merge([
                'timeout'         => min(30, $remaining),
                'connect_timeout' => min(10, $remaining),
                'allow_redirects' => false,
            ], $options)))->withUserAgent('Tallport-AI-Assistant')->withHeaders($headers)->get($url);
        } catch (\Exception $e) {
            throw new \RuntimeException(__('GitHub couldn\'t be reached: :error', ['error' => $e->getMessage()]), 0, $e);
        }

        if ($response->status() == 429 || ($response->status() == 403 && $response->header('X-RateLimit-Remaining') === '0')) {
            throw new \RuntimeException(__('GitHub\'s limit on requests from this server was reached. Try again later.'));
        }
        if (!$response->successful()) {
            throw new \RuntimeException(__('GitHub sent an error (:status).', ['status' => $response->status()]));
        }

        return $response;
    }
}
