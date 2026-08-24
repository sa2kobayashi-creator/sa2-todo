<?php

namespace App\Http\Controllers;

use App\Support\Registration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect('/dashboard');
        }

        return view('home', [
            'appName' => config('app.name'),
            'registrationOpen' => Registration::isOpen(),
        ]);
    }
}
