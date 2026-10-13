<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Http\Requests\StorePublicSupportConversationRequest;
use FlutterSdk\MagicStarter\Support\SupportRules;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Validator;

/**
 * The visitor's address and name are read from one place, so the form request
 * and the action cannot drift apart on what a visitor may send.
 */
class SupportRulesTest extends TestCase
{
    public function test_the_address_is_a_required_email_within_the_column(): void
    {
        $rules = SupportRules::email();

        $this->assertTrue(Validator::make(['email' => 'ada@example.com'], ['email' => $rules])->passes());
        $this->assertTrue(Validator::make(['email' => ''], ['email' => $rules])->fails());
        $this->assertTrue(Validator::make(['email' => 'not-an-address'], ['email' => $rules])->fails());
        $this->assertTrue(
            Validator::make(['email' => str_repeat('a', 250) . '@example.com'], ['email' => $rules])->fails(),
        );
    }

    public function test_the_name_is_optional_and_within_the_column(): void
    {
        $rules = SupportRules::name();

        $this->assertTrue(Validator::make(['name' => null], ['name' => $rules])->passes());
        $this->assertTrue(Validator::make(['name' => 'Ada Lovelace'], ['name' => $rules])->passes());
        $this->assertTrue(Validator::make(['name' => str_repeat('a', 256)], ['name' => $rules])->fails());
    }

    public function test_the_public_form_reads_both_from_the_one_source(): void
    {
        $rules = (new StorePublicSupportConversationRequest)->rules();

        $this->assertSame(SupportRules::email(), $rules['email']);
        $this->assertSame(SupportRules::name(), $rules['name']);
    }
}
