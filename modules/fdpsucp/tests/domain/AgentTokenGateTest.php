<?php

declare(strict_types=1);

use FD\PrismUcp\Support\AgentTokenGate;
use PHPUnit\Framework\TestCase;

final class AgentTokenGateTest extends TestCase
{
    public function test_empty_configured_token_rejects_every_request(): void
    {
        $this->assertFalse(AgentTokenGate::allows('', []));
        $this->assertFalse(AgentTokenGate::allows('', ['authorization' => 'Bearer ']));
        $this->assertFalse(AgentTokenGate::allows('', ['ucp-agent-token' => '']));
        $this->assertFalse(AgentTokenGate::allows('', ['x-api-key' => '']));
        $this->assertFalse(AgentTokenGate::allows('', ['x-api-key' => 'secret']));
    }

    public function test_matching_bearer_token_is_allowed(): void
    {
        $this->assertTrue(AgentTokenGate::allows('secret', ['authorization' => 'Bearer secret']));
    }

    public function test_matching_agent_token_header_is_allowed(): void
    {
        $this->assertTrue(AgentTokenGate::allows('secret', ['ucp-agent-token' => 'secret']));
    }

    public function test_missing_or_wrong_token_is_rejected(): void
    {
        $this->assertFalse(AgentTokenGate::allows('secret', []));
        $this->assertFalse(AgentTokenGate::allows('secret', ['authorization' => 'Bearer other']));
        $this->assertFalse(AgentTokenGate::allows('secret', ['ucp-agent-token' => 'other']));
    }

    public function test_matching_x_api_key_header_is_allowed(): void
    {
        $this->assertTrue(AgentTokenGate::allows('secret', ['x-api-key' => 'secret']));
    }

    public function test_x_api_key_header_is_trimmed(): void
    {
        $this->assertTrue(AgentTokenGate::allows('secret', ['x-api-key' => '  secret ']));
    }

    public function test_wrong_or_empty_x_api_key_is_rejected(): void
    {
        $this->assertFalse(AgentTokenGate::allows('secret', ['x-api-key' => 'other']));
        $this->assertFalse(AgentTokenGate::allows('secret', ['x-api-key' => '']));
    }
}
