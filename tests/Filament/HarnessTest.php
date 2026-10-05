<?php

namespace FlutterSdk\MagicStarter\Tests\Filament;

class HarnessTest extends FilamentTestCase
{
    public function test_panel_login_page_answers_200(): void
    {
        $this->get('/admin/login')->assertOk();
    }
}
