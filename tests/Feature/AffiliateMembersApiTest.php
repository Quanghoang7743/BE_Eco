<?php

namespace Tests\Feature;

use Tests\Support\BootstrapsAffiliateTestSchema;
use Tests\TestCase;

class AffiliateMembersApiTest extends TestCase
{
    use BootstrapsAffiliateTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetAffiliateTestSchema();
        $this->bootAffiliateTestSchema();
    }

    public function test_members_returns_downline_relative_to_authenticated_user(): void
    {
        $f0 = $this->makeUser([
            'full_name' => 'Hoang Minh Quang',
            'phone' => '+84865770555',
        ]);
        $f1 = $this->makeUser([
            'full_name' => 'QuangNguyeex',
            'phone' => '+84865770999',
            'referrer_id' => $f0->id,
        ]);
        $f2 = $this->makeUser([
            'full_name' => 'Nested Member',
            'phone' => '+84865770000',
            'referrer_id' => $f1->id,
        ]);

        $token = $f0->createToken('auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/members');

        $response->assertOk();

        $members = collect($response->json('members'));

        $this->assertFalse($members->contains('id', $f0->id));
        $this->assertSame('F1', $members->firstWhere('id', $f1->id)['level'] ?? null);
        $this->assertFalse($members->contains('id', $f2->id));
        $this->assertSame('down', $members->firstWhere('id', $f1->id)['direction'] ?? null);
    }
}
