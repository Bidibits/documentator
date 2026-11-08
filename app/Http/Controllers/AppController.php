<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class AppController extends Controller
{
    /**
     * Scripts and stylesheets come only from this site (built by scripts/build-app.mjs); no inline script runs.
     * Inline style attributes stay allowed. Fonts come from Google Fonts, as in PMS.
     * The file viewer shows a downloaded file from a blob: address (an <iframe> for a PDF, which the browser's own PDF
     * viewer draws, an <img> for a picture), so frames and plugins may use blob: and nothing else.
     */
    private const CSP = "default-src 'self'; script-src 'self'; "
        ."style-src 'self' https://fonts.googleapis.com; style-src-attr 'unsafe-inline'; "
        ."font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; "
        ."frame-src blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src blob:";

    public function __invoke(): Response
    {
        $page = config('documentator.app_page');
        if (! is_file($page)) {
            return response('The Documentanator app is not built yet. Run: npm run build', 503)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return response(file_get_contents($page), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Content-Security-Policy' => self::CSP,
            // the same safety for browsers that ignore parts of the policy, and no page address handed to other sites
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
