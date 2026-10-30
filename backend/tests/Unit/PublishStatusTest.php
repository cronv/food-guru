<?php

namespace Tests\Unit;

use App\Enums\PublishStatus;
use PHPUnit\Framework\TestCase;

class PublishStatusTest extends TestCase
{
    public function test_из_черновика_можно_на_проверку_в_публикацию_и_в_архив(): void
    {
        $draft = PublishStatus::Draft;

        $this->assertTrue($draft->canMoveTo(PublishStatus::Moderation));
        $this->assertTrue($draft->canMoveTo(PublishStatus::Published));
        $this->assertTrue($draft->canMoveTo(PublishStatus::Archived));
    }

    public function test_переход_в_самого_себя_запрещён(): void
    {
        foreach (PublishStatus::cases() as $status) {
            $this->assertFalse(
                $status->canMoveTo($status),
                "Статус «{$status->label()}» не должен переходить сам в себя",
            );
        }
    }

    public function test_из_архива_нельзя_сразу_на_проверку(): void
    {
        $this->assertFalse(PublishStatus::Archived->canMoveTo(PublishStatus::Moderation));
        $this->assertTrue(PublishStatus::Archived->canMoveTo(PublishStatus::Draft));
        $this->assertTrue(PublishStatus::Archived->canMoveTo(PublishStatus::Published));
    }

    public function test_у_каждого_статуса_есть_русская_подпись(): void
    {
        foreach (PublishStatus::cases() as $status) {
            $this->assertNotSame('', trim($status->label()));
        }
    }
}
