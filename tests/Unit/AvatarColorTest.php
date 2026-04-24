<?php

namespace Tests\Unit;

use App\Support\AvatarColor;
use PHPUnit\Framework\TestCase;

class AvatarColorTest extends TestCase
{
    public function test_same_id_yields_same_colour(): void
    {
        $this->assertSame(AvatarColor::forUserId(42), AvatarColor::forUserId(42));
    }

    public function test_different_ids_usually_differ(): void
    {
        $colours = array_unique([
            AvatarColor::forUserId(1), AvatarColor::forUserId(2), AvatarColor::forUserId(3),
            AvatarColor::forUserId(4), AvatarColor::forUserId(5), AvatarColor::forUserId(6),
        ]);
        $this->assertGreaterThanOrEqual(3, count($colours));
    }

    public function test_initials_return_two_letters(): void
    {
        $this->assertSame('SD', AvatarColor::initials('Sumit Dabas'));
        $this->assertSame('N',  AvatarColor::initials('Nikhil'));
        $this->assertSame('',   AvatarColor::initials(''));
    }
}
