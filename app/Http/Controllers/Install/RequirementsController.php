<?php

namespace App\Http\Controllers\Install;

use Illuminate\Routing\Controller;
use App\Install\RequirementsChecker;

class RequirementsController extends Controller
{
    /**
     * The requirements.
     *
     * @var RequirementsChecker
     */
    protected $requirements;

    /**
     * Create a new instance.
     *
     * @param RequirementsChecker $checker
     */
    public function __construct(RequirementsChecker $checker)
    {
        $this->requirements = $checker;
    }

    /**
     * Display the requirements page.
     *
     * @return \Illuminate\View\View
     */
    public function requirements()
    {
        $phpSupportInfo = $this->requirements->checkPHPversion(
            config('installer.core.minPhpVersion')
        );
        $requirements = $this->requirements->check(
            config('installer.requirements')
        );

        $optional = [];
        foreach (config('installer.optional', []) as $extension => $purpose) {
            $optional[$extension] = ['enabled' => \Helper::extensionEnabled($extension), 'purpose' => $purpose];
        }
        $optional['PCRE JIT'] = ['enabled' => \Helper::pcreJitAvailable(), 'purpose' => 'Faster text processing'];

        return view('vendor.installer.requirements', compact('requirements', 'phpSupportInfo', 'optional'));
    }
}