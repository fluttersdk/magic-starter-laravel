<?php

namespace FlutterSdk\MagicStarter\Contracts;

interface GeneratesNewRecoveryCodes
{
    /**
     * Generate new recovery codes for the user.
     *
     * @return array<int, string>
     */
    public function generate(mixed $user): array;
}
