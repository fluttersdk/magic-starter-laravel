<?php

namespace FlutterSdk\MagicStarter\Tests\Rules;

use FlutterSdk\MagicStarter\Rules\E164Phone;
use FlutterSdk\MagicStarter\Tests\TestCase;
use Illuminate\Support\Facades\Validator;

final class E164PhoneTest extends TestCase
{
    /**
     * Test valid E.164 phone numbers pass validation.
     */
    public function test_valid_e164_numbers_pass(): void
    {
        $validNumbers = [
            '+14155552671',
            '+905551234567',
            '+19', // Minimum valid: country + subscriber
        ];

        foreach ($validNumbers as $number) {
            $validator = Validator::make(
                ['phone' => $number],
                ['phone' => ['required', new E164Phone]],
            );

            $this->assertTrue(
                $validator->passes(),
                "Failed to validate valid E.164 number: {$number}",
            );
        }
    }

    /**
     * Test that numbers without a plus prefix are rejected.
     */
    public function test_rejects_number_without_plus_prefix(): void
    {
        $validator = Validator::make(
            ['phone' => '14155552671'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * Test that numbers starting with zero after the plus are rejected.
     */
    public function test_rejects_number_starting_with_zero_after_plus(): void
    {
        $validator = Validator::make(
            ['phone' => '+0123456789'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * Test that numbers with spaces are rejected.
     */
    public function test_rejects_number_with_spaces(): void
    {
        $validator = Validator::make(
            ['phone' => '+1 415 555 2671'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * Test that numbers with dashes are rejected.
     */
    public function test_rejects_number_with_dashes(): void
    {
        $validator = Validator::make(
            ['phone' => '+1-415-555-2671'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * Test that numbers with parentheses are rejected.
     */
    public function test_rejects_number_with_parentheses(): void
    {
        $validator = Validator::make(
            ['phone' => '+(1)4155552671'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * Test that numbers exceeding 15 digits (excluding plus) are rejected.
     */
    public function test_rejects_too_long_number(): void
    {
        $validator = Validator::make(
            ['phone' => '+1234567890123456'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }

    /**
     * Test that alphabetic input is rejected.
     */
    public function test_rejects_alphabetic_input(): void
    {
        $invalidInputs = [
            '+1abc',
            'not-a-phone',
        ];

        foreach ($invalidInputs as $input) {
            $validator = Validator::make(
                ['phone' => $input],
                ['phone' => ['required', new E164Phone]],
            );

            $this->assertFalse($validator->passes());
        }
    }

    /**
     * Test that the minimum valid E.164 length (+1 digit) is accepted.
     */
    public function test_accepts_minimum_length_number(): void
    {
        $validator = Validator::make(
            ['phone' => '+1'],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * Test that an empty string fails validation.
     */
    public function test_empty_string_fails(): void
    {
        $validator = Validator::make(
            ['phone' => ''],
            ['phone' => ['required', new E164Phone]],
        );

        $this->assertFalse($validator->passes());
    }
}
