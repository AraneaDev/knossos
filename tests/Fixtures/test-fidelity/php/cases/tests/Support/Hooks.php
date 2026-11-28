<?php
namespace Tests\Support;
use PHPUnit\Framework\Attributes\PreCondition;
use PHPUnit\Framework\Attributes\Before;
trait PreHooks { #[PreCondition] protected function prep(): void { $this->x = 2; } }
trait LateBefore { #[Before(priority: -1)] protected function late(): void { $this->x = 2; } }
trait PreTemplate { protected function assertPreConditions(): void { $this->x = 2; } }
trait DocBefore { /** @before */ protected function doc(): void { $this->x = 2; } }
trait AssertHelper { protected function assertReady(): void { $this->x = 2; } }
