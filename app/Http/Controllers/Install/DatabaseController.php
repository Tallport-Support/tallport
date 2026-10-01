<?php

namespace App\Http\Controllers\Install;

use Illuminate\Routing\Controller;
use App\Install\DatabaseManager;

class DatabaseController extends Controller
{
    /**
     * The database manager.
     *
     * @var DatabaseManager
     */
    private $databaseManager;

    /**
     * Create a new instance.
     *
     * @param DatabaseManager $databaseManager
     */
    public function __construct(DatabaseManager $databaseManager)
    {
        $this->databaseManager = $databaseManager;
    }

    /**
     * Migrate and seed the database.
     *
     * @return \Illuminate\View\View
     */
    public function database()
    {
        $response = $this->databaseManager->migrateAndSeed();

        return redirect()->route('LaravelInstaller::final')
                         ->with(['message' => $response]);
    }
}
