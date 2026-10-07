<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

/**
 * Hooks modules use to take part in logging in (e.g. LDAP, two-factor
 * authentication).
 */
class AuthHooksTest extends FeatureTestCase
{
    protected $hooks = [];

    protected function tearDown(): void
    {
        foreach ($this->hooks as [$type, $hook, $callback]) {
            $type == 'filter' ? \Eventy::removeFilter($hook, $callback) : \Eventy::removeAction($hook, $callback);
        }
        parent::tearDown();
    }

    protected function addHook($type, $hook, $callback, $arguments)
    {
        $this->hooks[] = [$type, $hook, $callback];
        $type == 'filter' ? \Eventy::addFilter($hook, $callback, 20, $arguments) : \Eventy::addAction($hook, $callback, 20, $arguments);
    }

    protected function postLogin($email, $password)
    {
        \Session::start();

        return $this->post('/login', ['_token' => csrf_token(), 'email' => $email, 'password' => $password]);
    }

    public function testModuleCanAcceptPassword()
    {
        $user = $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('local-password')]);
        $this->addHook('filter', 'session_guard.validate_credentials', function ($valid, $user, $credentials) {
            return $valid || $credentials['password'] === 'directory-password';
        }, 3);

        $this->postLogin('agent@example.org', 'directory-password')->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
    }

    public function testModuleCanRejectPassword()
    {
        $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('local-password')]);
        $this->addHook('filter', 'session_guard.validate_credentials', function ($valid) {
            return false;
        }, 1);

        $this->postLogin('agent@example.org', 'local-password')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function testAuthMiddlewareHookRunsBeforeAuthentication()
    {
        $calls = [];
        $this->addHook('action', 'auth_middleware.handle', function ($request, $guards) use (&$calls) {
            $calls[] = $request->path();
        }, 2);

        $this->get('/users')->assertRedirect(route('login'));

        $this->assertSame(['users'], $calls);
    }

    /**
     * A module can refuse a login with its own errors (login.custom_check).
     */
    public function testModuleCanRefuseALogin()
    {
        $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('local-password')]);
        $this->addHook('filter', 'login.custom_check', function ($errors, $request) {
            return $request->input('email') == 'agent@example.org' ? ['email' => 'Logins are closed for maintenance.'] : $errors;
        }, 2);

        $this->postLogin('agent@example.org', 'local-password')->assertSessionHasErrors(['email' => 'Logins are closed for maintenance.']);

        $this->assertGuest();
    }
}
