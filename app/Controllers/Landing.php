<?php

namespace App\Controllers;

use CodeIgniter\Controller;

/**
 * Single-shop install: the landing page and the sign-in page are one screen.
 * "/" renders exactly what "/login" renders (installation redirect, migration
 * gate, and signed-in redirect to the dashboard all live in Auth::showLogin).
 */
class Landing extends Controller
{
    public function index()
    {
        return (new Auth())->showLogin();
    }
}
