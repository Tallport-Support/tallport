<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiAccess;
use App\Api\Writer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * REST API responses: lists as {_embedded: {<things>: [...]}, page: {...}},
 * 201 with a Resource-ID header for what is created, 204 for changes,
 * errors as {message, _embedded: {errors: [{path, message, source}]}}.
 */
abstract class ApiController extends Controller
{
    const PAGE_SIZE = 50;

    protected function access()
    {
        return ApiAccess::current();
    }

    /**
     * A request parameter by camelCase or snake_case name.
     */
    protected function param(Request $request, $name, $default = null)
    {
        return Writer::get($request->all(), $name, $default);
    }

    protected function hasParam(Request $request, $name)
    {
        return Writer::get($request->all(), $name, '__missing__') !== '__missing__';
    }

    protected function error($message, $path = '', $status = 400)
    {
        return response()->json([
            'message'   => $status == 400 ? 'Error occurred' : $message,
            '_embedded' => ['errors' => [['path' => $path, 'message' => $message, 'source' => 'JSON']]],
        ], $status);
    }

    /**
     * An error from App\Api\Writer: [message, path, status].
     */
    protected function writerError($error)
    {
        return $error[2] == 403 ? $this->forbidden($error[0]) : $this->error($error[0], $error[1], $error[2]);
    }

    protected function required($name)
    {
        return $this->error('`'.$name.'` parameter is required', $name);
    }

    protected function forbidden($message)
    {
        return response()->json(['message' => $message, '_embedded' => ['errors' => []]], 403);
    }

    protected function forbiddenMailbox()
    {
        return $this->forbidden('Forbidden: Provided API key is not permitted to access this mailbox');
    }

    protected function forbiddenConversation()
    {
        return $this->forbidden('Forbidden: API key owner is not permitted to access this conversation');
    }

    protected function forbiddenAdmin()
    {
        return $this->forbidden('Forbidden: This action requires an API Key owned by an administrator');
    }

    protected function notFound()
    {
        return response()->json(['message' => 'Not Found', '_embedded' => ['errors' => []]], 404);
    }

    protected function created($data, $id)
    {
        return response()->json($data, 201)->header('Resource-ID', $id);
    }

    protected function noContent()
    {
        return response()->json(null, 204);
    }

    /**
     * A page of a query, as $plural, each formatted by $format.
     */
    protected function paginated(Request $request, $query, $plural, callable $format)
    {
        $size = (int) $this->param($request, 'pageSize', self::PAGE_SIZE) ?: self::PAGE_SIZE;
        $size = max(1, min($size, (int) config('api.max_page_size', 1000)));
        $page = $query->paginate($size, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return response()->json([
            '_embedded' => [$plural => collect($page->items())->map($format)->values()->all()],
            'page'      => [
                'size'          => $size,
                'totalElements' => $page->total(),
                'totalPages'    => $page->lastPage(),
                'number'        => $page->currentPage(),
            ],
        ]);
    }

    /**
     * Sort a list by sortField and sortOrder (desc unless asc).
     */
    protected function sort(Request $request, $query, $fields, $default)
    {
        $field = $fields[$this->param($request, 'sortField')] ?? $default;
        $order = strtolower((string) $this->param($request, 'sortOrder')) == 'asc' ? 'asc' : 'desc';
        $query->orderBy($field, $order);
    }

    /**
     * Endpoints for modules Tallport doesn't have.
     */
    public function moduleMissing(Request $request)
    {
        $modules = [
            'tags'            => 'Tags',
            'custom_fields'   => 'Custom Fields',
            'customer_fields' => 'Customer Fields',
            'timelogs'        => 'Time Tracking',
            'reports'         => 'Reports',
        ];
        $module = 'Tags';
        foreach ($modules as $segment => $name) {
            if (in_array($segment, $request->segments())) {
                $module = $name;
            }
        }

        return $this->error($module.' module is not installed or not activated');
    }
}
