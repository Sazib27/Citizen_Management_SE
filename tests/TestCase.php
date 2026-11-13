<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureViteManifestExists();
    }

    /**
     * Blade's @vite directive throws when public/build/manifest.json is
     * missing, so any test that renders a layout fails on a checkout
     * where the frontend has not been built. `public/build` is
     * gitignored, so this is the normal state of a fresh clone — the
     * test suite must not depend on `npm run build` having been run.
     *
     * A minimal manifest is written when the real one is absent. The
     * entries point at files that do not exist, but nothing in the suite
     * fetches them; the point is only that the manifest is present and
     * parseable.
     */
    private function ensureViteManifestExists(): void
    {
        $manifestPath = public_path('build/manifest.json');

        if (file_exists($manifestPath)) {
            return;
        }

        $directory = dirname($manifestPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($manifestPath, json_encode([
            'resources/js/app.js' => [
                'file'    => 'assets/app.js',
                'isEntry' => true,
                'src'     => 'resources/js/app.js',
            ],
            'resources/css/app.css' => [
                'file'    => 'assets/app.css',
                'isEntry' => true,
                'src'     => 'resources/css/app.css',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
