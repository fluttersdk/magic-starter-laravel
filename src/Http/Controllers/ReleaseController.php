<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Support\ReleaseFeed;
use Illuminate\Http\JsonResponse;

/**
 * Serves the published app releases to any client, signed in or not.
 *
 * Intentionally unauthenticated: a client reads the release notes, and the
 * latest build it should compare itself against, before a session exists.
 * Drafts never leave; see {@see ReleaseFeed}.
 */
class ReleaseController
{
    /**
     * Return the cached `{data, meta.latest_build}` feed of published releases.
     */
    public function index(): JsonResponse
    {
        return response()->json(ReleaseFeed::payload());
    }
}
