<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    // Render an Inertia page.
    // Component is the Vue file path relative to resources/js/Pages/
    // e.g. 'Entities/Show' → resources/js/Pages/Entities/Show.vue
    protected function page(string $component, array $props = []): InertiaResponse
    {
        return Inertia::render($component, $props);
    }

    // Redirect back with a flash message.
    // Message surfaces in the Vue layout via usePage().props.flash
    protected function back(string $message = ''): \Illuminate\Http\RedirectResponse
    {
        if ($message) {
            session()->flash('success', $message);
        }

        return redirect()->back();
    }

    // Redirect to a named route with a flash message.
    protected function to(string $route, array $params = [], string $message = ''): \Illuminate\Http\RedirectResponse
    {
        if ($message) {
            session()->flash('success', $message);
        }

        return redirect()->route($route, $params);
    }
}