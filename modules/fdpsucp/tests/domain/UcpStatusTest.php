<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\UcpStatus;
use PHPUnit\Framework\TestCase;

/**
 * Ported from woocommerce-ucp StatusTest — checkout status resolution and
 * missing-requirement derivation (UcpStatus::resolve / missingRequirements /
 * missingMessages).
 */
final class UcpStatusTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makeSession(array $overrides = []): array
    {
        return array_merge([
            'status' => 'incomplete',
            'line_items' => json_encode([['id' => 'li_1']]),
            'buyer' => json_encode(['email' => 'test@example.com']),
            'fulfillment' => json_encode([
                'methods' => [[
                    'type' => 'shipping',
                    'destinations' => [[
                        'address_country' => 'US',
                        'postal_code' => '94105',
                    ]],
                    'groups' => [[
                        'options' => [['id' => 'flat_rate1']],
                        'selected_option_id' => 'flat_rate1',
                    ]],
                ]],
            ]),
        ], $overrides);
    }

    // ── resolve() ────────────────────────────────────────────

    public function test_canceled_session_returns_canceled(): void
    {
        $this->assertSame('canceled', UcpStatus::resolve($this->makeSession(['status' => 'canceled'])));
    }

    public function test_completed_session_returns_completed(): void
    {
        $this->assertSame('completed', UcpStatus::resolve($this->makeSession(['status' => 'completed'])));
    }

    public function test_full_session_returns_ready_for_complete(): void
    {
        $this->assertSame('ready_for_complete', UcpStatus::resolve($this->makeSession()));
    }

    public function test_missing_email_returns_incomplete(): void
    {
        $this->assertSame('incomplete', UcpStatus::resolve($this->makeSession(['buyer' => json_encode([])])));
    }

    public function test_missing_address_returns_incomplete(): void
    {
        $this->assertSame('incomplete', UcpStatus::resolve($this->makeSession(['fulfillment' => json_encode([])])));
    }

    public function test_missing_line_items_returns_incomplete(): void
    {
        $this->assertSame('incomplete', UcpStatus::resolve($this->makeSession(['line_items' => json_encode([])])));
    }

    // ── missingRequirements() ────────────────────────────────

    public function test_complete_session_has_no_missing(): void
    {
        $this->assertEmpty(UcpStatus::missingRequirements($this->makeSession()));
    }

    public function test_empty_session_missing_everything(): void
    {
        $session = [
            'status' => 'incomplete',
            'line_items' => '[]',
            'buyer' => '{}',
            'fulfillment' => '{}',
        ];
        $missing = UcpStatus::missingRequirements($session);

        $this->assertContains('items', $missing);
        $this->assertContains('email', $missing);
        $this->assertContains('shipping_address', $missing);
    }

    public function test_unselected_shipping_option_is_flagged(): void
    {
        $session = $this->makeSession(['fulfillment' => json_encode([
            'methods' => [[
                'type' => 'shipping',
                'destinations' => [['address_country' => 'US']],
                'groups' => [[
                    'options' => [['id' => 'flat_rate1']],
                    'selected_option_id' => '',
                ]],
            ]],
        ])]);

        $this->assertContains('selected_fulfillment_option', UcpStatus::missingRequirements($session));
    }

    // ── missingMessages() ────────────────────────────────────

    public function test_missing_messages_returns_error_per_field(): void
    {
        $messages = UcpStatus::missingMessages(['email', 'shipping_address']);

        $this->assertCount(2, $messages);
        $this->assertSame('missing_email', $messages[0]['code']);
        $this->assertSame('error', $messages[0]['type']);
        $this->assertSame('missing_shipping_address', $messages[1]['code']);
    }

    public function test_empty_missing_returns_no_messages(): void
    {
        $this->assertEmpty(UcpStatus::missingMessages([]));
    }
}
