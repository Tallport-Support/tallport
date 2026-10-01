<?php

namespace App\Http\Controllers\Install;

use App\Http\Requests;
use Illuminate\Routing\Controller;
use App\Install\PermissionsChecker;

class PermissionsController extends Controller
{

    /**
     * The permissions.
     *
     * @var PermissionsChecker
     */
    protected $permissions;

    /**
     * Create a new instance.
     *
     * @param PermissionsChecker $checker
     */
    public function __construct(PermissionsChecker $checker)
    {
        $this->permissions = $checker;
    }

    /**
     * Display the permissions check page.
     *
     * @return \Illuminate\View\View
     */
    public function permissions()
    {
        $permissions = $this->permissions->check(
            config('installer.permissions')
        );

        return view('vendor.installer.permissions', compact('permissions'));
    }
}
