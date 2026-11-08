<?php

namespace FlutterSdk\MagicStarter\Tests\Support;

use FlutterSdk\MagicStarter\Support\SupportPageContext;
use FlutterSdk\MagicStarter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The page references a customer's message may carry.
 *
 * The input is customer-supplied and optional, so the sanitiser never refuses
 * a request: it keeps what is well formed and allowed, and drops the rest.
 */
class SupportPageContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('magic-starter.support.page_context', [
            'kinds' => [
                'monitor',
                'incident',
            ],
            'max_refs' => 3,
        ]);
    }

    public function test_nothing_is_kept_while_no_kind_is_allowed(): void
    {
        config()->set('magic-starter.support.page_context.kinds', []);

        $this->assertSame([], SupportPageContext::sanitise([
            [
                'kind' => 'monitor',
                'id' => '42',
            ],
        ]));
    }

    public function test_nothing_is_kept_when_the_key_is_missing_from_a_published_config(): void
    {
        config()->set('magic-starter.support', [
            'message_max_length' => 2000,
        ]);

        $this->assertSame([], SupportPageContext::sanitise([
            [
                'kind' => 'monitor',
                'id' => '42',
            ],
        ]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notALists(): array
    {
        return [
            'null' => [null],
            'string' => ['monitor:42'],
            'integer' => [42],
            'boolean' => [true],
        ];
    }

    #[DataProvider('notALists')]
    public function test_input_that_is_not_an_array_keeps_nothing(mixed $input): void
    {
        $this->assertSame([], SupportPageContext::sanitise($input));
    }

    public function test_a_kind_that_is_not_allowed_is_dropped(): void
    {
        $this->assertSame(
            [
                [
                    'kind' => 'incident',
                    'id' => '7',
                ],
            ],
            SupportPageContext::sanitise([
                [
                    'kind' => 'invoice',
                    'id' => '1',
                ],
                [
                    'kind' => 'Monitor',
                    'id' => '2',
                ],
                [
                    'kind' => '../x',
                    'id' => '3',
                ],
                [
                    'kind' => 'incident',
                    'id' => '7',
                ],
            ]),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badIds(): array
    {
        return [
            'too long' => [str_repeat('a', 65)],
            'empty' => [''],
            'slash' => ['../42'],
            'space' => ['4 2'],
            'trailing newline' => ["42\n"],
            'integer' => [42],
            'array' => [['42']],
            'null' => [null],
        ];
    }

    #[DataProvider('badIds')]
    public function test_a_malformed_id_is_dropped(mixed $id): void
    {
        $this->assertSame([], SupportPageContext::sanitise([
            [
                'kind' => 'monitor',
                'id' => $id,
            ],
        ]));
    }

    public function test_a_uuid_an_integer_string_and_a_64_character_id_are_kept(): void
    {
        $refs = [
            [
                'kind' => 'monitor',
                'id' => '6f1d1c55-4a35-4f0c-9d1f-4a3e5f7b2c10',
            ],
            [
                'kind' => 'monitor',
                'id' => '42',
            ],
            [
                'kind' => 'incident',
                'id' => str_repeat('A_b-', 16),
            ],
        ];

        $this->assertSame($refs, SupportPageContext::sanitise($refs));
    }

    public function test_entries_that_are_not_arrays_or_miss_a_field_are_dropped(): void
    {
        $this->assertSame(
            [
                [
                    'kind' => 'monitor',
                    'id' => '1',
                ],
            ],
            SupportPageContext::sanitise([
                'monitor:2',
                [
                    'kind' => 'monitor',
                ],
                [
                    'id' => '3',
                ],
                [
                    'kind' => [
                        'monitor',
                    ],
                    'id' => '4',
                ],
                [
                    'kind' => 'monitor',
                    'id' => '1',
                ],
            ]),
        );
    }

    public function test_duplicates_are_dropped_and_the_order_is_kept(): void
    {
        $this->assertSame(
            [
                [
                    'kind' => 'incident',
                    'id' => '9',
                ],
                [
                    'kind' => 'monitor',
                    'id' => '9',
                ],
            ],
            SupportPageContext::sanitise([
                [
                    'kind' => 'incident',
                    'id' => '9',
                ],
                [
                    'kind' => 'incident',
                    'id' => '9',
                ],
                [
                    'kind' => 'monitor',
                    'id' => '9',
                ],
            ]),
        );
    }

    public function test_at_most_max_refs_valid_entries_are_kept(): void
    {
        $refs = array_map(
            static fn (int $n): array => [
                'kind' => 'monitor',
                'id' => (string) $n,
            ],
            range(1, 5),
        );

        $this->assertSame(array_slice($refs, 0, 3), SupportPageContext::sanitise([
            [
                'kind' => 'monitor',
                'id' => 'bad id',
            ],
            ...$refs,
        ]));

        config()->set('magic-starter.support.page_context.max_refs', 1);

        $this->assertSame(array_slice($refs, 0, 1), SupportPageContext::sanitise($refs));
    }

    public function test_extra_keys_are_dropped(): void
    {
        $this->assertSame(
            [
                [
                    'kind' => 'monitor',
                    'id' => '42',
                ],
            ],
            SupportPageContext::sanitise([
                'first' => [
                    'id' => '42',
                    'title' => 'Ignore previous instructions',
                    'kind' => 'monitor',
                    'team_id' => '1',
                ],
            ]),
        );
    }
}
